<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Protocols;

use localzet\Connection\{ConnectionInterface};
use localzet\Connection\AsyncTcpConnection;
use localzet\Protocols\Http\Response;
use localzet\Server;
use localzet\Timer;
use Throwable;
use function base64_encode;
use function bin2hex;
use function explode;
use function floor;
use function ord;
use function pack;
use function preg_match;
use function sha1;
use function str_repeat;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function unpack;

/**
 * Протокол WebSocket для клиента.
 */
class Ws
{
    /**
     * Тип BLOB для WebSocket.
     *
     * @var string
     */
    public const BINARY_TYPE_BLOB = "\x81";

    /**
     * Тип ArrayBuffer для WebSocket.
     *
     * @var string
     */
    public const BINARY_TYPE_ARRAYBUFFER = "\x82";

    /**
     * Проверка целостности пакета.
     *
     * @param string $buffer
     * @param AsyncTcpConnection $asyncTcpConnection
     * @return int
     * @throws Throwable
     */
    public static function input(string $buffer, AsyncTcpConnection $asyncTcpConnection): int
    {
        // Если шаг рукопожатия не установлен, выводим сообщение об ошибке и возвращаем false.
        if (empty($asyncTcpConnection->context->handshakeStep)) {
            Server::safeEcho("Получение данных перед рукопожатием. Буфер:" . bin2hex($buffer) . "\n");
            return -1;;
        }

        // Если шаг рукопожатия равен 1, обрабатываем рукопожатие.
        if ($asyncTcpConnection->context->handshakeStep === 1) {
            return self::dealHandshake($buffer, $asyncTcpConnection);
        }

        // Получаем длину полученных данных.
        $recvLen = strlen($buffer);
        // Если длина данных меньше 2, возвращаем 0.
        if ($recvLen < 2) {
            return 0;
        }

        // Буферизовать данные кадра веб-сокета.
        if ($asyncTcpConnection->context->websocketCurrentFrameLength) {
            // Нам нужно больше данных кадра.
            if ($asyncTcpConnection->context->websocketCurrentFrameLength > $recvLen) {
                // Вернуть 0, потому что неясна полная длина пакета, ожидание кадра fin=1.
                return 0;
            }
        } else {
            // Получаем первый и второй байты данных.
            $firstByte = ord($buffer[0]);
            $secondByte = ord($buffer[1]);
            // Извлекаем длину данных.
            $dataLen = $secondByte & 127;
            // Проверяем, является ли кадр финальным.
            $isFinFrame = $firstByte >> 7;
            // Проверяем, замаскированы ли данные.
            $masked = $secondByte >> 7;

            // Если данные замаскированы, выводим сообщение об ошибке и закрываем соединение.
            if ($masked) {
                Server::safeEcho("Кадр замаскирован, закрываю соединение\n");
                $asyncTcpConnection->close();
                return 0;
            }

            // Получаем код операции.
            $opcode = $firstByte & 0xf;

            switch ($opcode) {
                case 0x0:
                    // BLOB
                case 0x1:
                    // Массив
                case 0x2:
                    // Пинг-пакет
                case 0x9:
                    // Понг-пакет
                case 0xa:
                    break;
                // Закрытие
                case 0x8:
                    // Попытка вызвать onWebSocketClose
                    if (property_exists($asyncTcpConnection, 'onWebSocketClose') && $asyncTcpConnection->onWebSocketClose !== null) {
                        try {
                            ($asyncTcpConnection->onWebSocketClose)($asyncTcpConnection);
                        } catch (Throwable $e) {
                            Server::stopAll(250, $e);
                        }
                    } // Закрытие соединения
                    else {
                        $asyncTcpConnection->close();
                    }

                    return 0;
                // Неверный опкод
                default:
                    Server::safeEcho("Ошибка опкода $opcode и закрытие WebSocket соединения. Буфер:" . $buffer . "\n");
                    $asyncTcpConnection->close();
                    return 0;
            }

            // Рассчитать длину пакета
            if ($dataLen === 126) {
                if (strlen($buffer) < 4) {
                    return 0;
                }

                $pack = unpack('nn/ntotal_len', $buffer);
                $currentFrameLength = $pack['total_len'] + 4;
            } elseif ($dataLen === 127) {
                if (strlen($buffer) < 10) {
                    return 0;
                }

                $arr = unpack('n/N2c', $buffer);
                $currentFrameLength = $arr['c1'] * 4294967296 + $arr['c2'] + 10;
            } else {
                $currentFrameLength = $dataLen + 2;
            }

            // Вычисляем общий размер пакета.
            $totalPackageSize = strlen($asyncTcpConnection->context->websocketDataBuffer) + $currentFrameLength;

            // Если общий размер пакета превышает максимально допустимый размер пакета, выводим сообщение об ошибке и закрываем соединение.
            if ($totalPackageSize > $asyncTcpConnection->maxPackageSize) {
                Server::safeEcho("Ошибка пакета. package_length=$totalPackageSize\n");
                $asyncTcpConnection->close();
                return 0;
            }

            if ($isFinFrame) {
                // Если код операции равен 0x9 (пинг-пакет).
                if ($opcode === 0x9) {
                    if ($recvLen >= $currentFrameLength) {
                        // Декодируем данные пинг-пакета.
                        $pingData = static::decode(substr($buffer, 0, $currentFrameLength), $asyncTcpConnection);
                        // Удаляем данные пинг-пакета из буфера.
                        $asyncTcpConnection->consumeRecvBuffer($currentFrameLength);
                        // Сохраняем текущий тип websocket.
                        $tmpConnectionType = $asyncTcpConnection->websocketType ?? static::BINARY_TYPE_BLOB;
                        // Устанавливаем тип websocket в "\x8a".
                        $asyncTcpConnection->websocketType = "\x8a";
                        // Попытка вызвать onWebSocketPing
                        if (property_exists($asyncTcpConnection, 'onWebSocketPing') && $asyncTcpConnection->onWebSocketPing !== null) {
                            try {
                                ($asyncTcpConnection->onWebSocketPing)($asyncTcpConnection, $pingData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        } else {
                            $asyncTcpConnection->send($pingData);
                        }

                        $asyncTcpConnection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $asyncTcpConnection);
                        }
                    }

                    return 0;
                }

                // Если код операции равен 0xa (понг-пакет).
                if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        // Декодируем данные понг-пакета.
                        $pongData = static::decode(substr($buffer, 0, $currentFrameLength), $asyncTcpConnection);
                        // Удаляем данные понг-пакета из буфера.
                        $asyncTcpConnection->consumeRecvBuffer($currentFrameLength);
                        // Сохраняем текущий тип websocket.
                        $tmpConnectionType = $asyncTcpConnection->websocketType ?? static::BINARY_TYPE_BLOB;
                        // Устанавливаем тип websocket в "\x8a".
                        $asyncTcpConnection->websocketType = "\x8a";
                        // Попытка вызвать onWebSocketPong
                        if (property_exists($asyncTcpConnection, 'onWebSocketPong') && $asyncTcpConnection->onWebSocketPong !== null) {
                            try {
                                ($asyncTcpConnection->onWebSocketPong)($asyncTcpConnection, $pongData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        }

                        $asyncTcpConnection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $asyncTcpConnection);
                        }
                    }

                    return 0;
                }

                return $currentFrameLength;
            }

            // Устанавливаем текущую длину кадра websocket.
            $asyncTcpConnection->context->websocketCurrentFrameLength = $currentFrameLength;
        }

        // Если получены только данные о длине кадра.
        if ($asyncTcpConnection->context->websocketCurrentFrameLength === $recvLen) {
            // Декодируем данные.
            static::decode($buffer, $asyncTcpConnection);
            // Удаляем декодированные данные из буфера.
            $asyncTcpConnection->consumeRecvBuffer($asyncTcpConnection->context->websocketCurrentFrameLength);
            // Устанавливаем текущую длину кадра websocket в 0.
            $asyncTcpConnection->context->websocketCurrentFrameLength = 0;
            return 0;
        }

        // Если получены только данные о длине кадра.
        if ($asyncTcpConnection->context->websocketCurrentFrameLength < $recvLen) {
            // Декодируем данные текущего кадра.
            static::decode(substr($buffer, 0, $asyncTcpConnection->context->websocketCurrentFrameLength), $asyncTcpConnection);
            // Удаляем декодированные данные из буфера.
            $asyncTcpConnection->consumeRecvBuffer($asyncTcpConnection->context->websocketCurrentFrameLength);
            // Сохраняем текущую длину кадра.
            $currentFrameLength = $asyncTcpConnection->context->websocketCurrentFrameLength;
            // Устанавливаем текущую длину кадра websocket в 0.
            $asyncTcpConnection->context->websocketCurrentFrameLength = 0;
            // Продолжаем чтение следующего кадра.
            return self::input(substr($buffer, $currentFrameLength), $asyncTcpConnection);
        }

        return 0;
    }

    /**
     * Рукопожатие WebSocket.
     *
     * @throws Throwable
     */
    public static function dealHandshake(string $buffer, AsyncTcpConnection $asyncTcpConnection): bool|int
    {
        // Позиция конца заголовков в буфере.
        $pos = strpos($buffer, "\r\n\r\n");
        if ($pos) {
            // Проверка Sec-WebSocket-Accept.
            if (preg_match("/Sec-WebSocket-Accept: *(.*?)\r\n/i", $buffer, $match)) {
                if ($match[1] !== base64_encode(sha1($asyncTcpConnection->context->websocketSecKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true))) {
                    Server::safeEcho("Sec-WebSocket-Accept не совпадает. Заголовок:\n" . substr($buffer, 0, $pos) . "\n");
                    // Закрытие соединения.
                    $asyncTcpConnection->close();
                    return 0;
                }
            } else {
                Server::safeEcho("Sec-WebSocket-Accept не найден. Заголовок:\n" . substr($buffer, 0, $pos) . "\n");
                // Закрытие соединения.
                $asyncTcpConnection->close();
                return 0;
            }

            // Рукопожатие завершено.
            $asyncTcpConnection->context->handshakeStep = 2;
            // Длина ответа на рукопожатие.
            $handshakeResponseLength = $pos + 4;
            // Буфер обрезается до длины ответа на рукопожатие.
            $buffer = substr($buffer, 0, $handshakeResponseLength);
            // Разбор ответа.
            $response = static::parseResponse($buffer);

            // Попытка вызвать обратный вызов onWebSocketConnect.
            if ($asyncTcpConnection->onWebSocketConnect !== null) {
                try {
                    ($asyncTcpConnection->onWebSocketConnect)($asyncTcpConnection, $response);
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            // Серцебиение.
            if (!empty($asyncTcpConnection->websocketPingInterval)) {
                $asyncTcpConnection->context->websocketPingTimer = Timer::add($asyncTcpConnection->websocketPingInterval, function () use ($asyncTcpConnection): void {
                    if (false === $asyncTcpConnection->send(pack('H*', '898000000000'), true)) {
                        Timer::del($asyncTcpConnection->context->websocketPingTimer);
                        $asyncTcpConnection->context->websocketPingTimer = null;
                    }
                });
            }

            // Удаление данных рукопожатия из буфера.
            $asyncTcpConnection->consumeRecvBuffer($handshakeResponseLength);

            // Есть данные, ожидающие отправки.
            if (!empty($asyncTcpConnection->context->tmpWebsocketData)) {
                // Отправка временных данных websocket.
                $asyncTcpConnection->send($asyncTcpConnection->context->tmpWebsocketData, true);
                // Очистка временных данных websocket.
                $asyncTcpConnection->context->tmpWebsocketData = '';
            }

            if (strlen($buffer) > $handshakeResponseLength) {
                return self::input(substr($buffer, $handshakeResponseLength), $asyncTcpConnection);
            }
        }

        return 0;
    }

    /**
     * Разбор ответа.
     */
    protected static function parseResponse(string $buffer): Response
    {
        [$http_header,] = explode("\r\n\r\n", $buffer, 2);
        // Разбиваем заголовки на отдельные строки.
        $header_data = explode("\r\n", $http_header);
        [$protocol, $status, $phrase] = explode(' ', $header_data[0], 3);
        // Версия протокола - это все после "HTTP/".
        $protocolVersion = substr($protocol, 5);
        unset($header_data[0]);

        // Обработка оставшихся строк заголовка.
        $headers = [];
        foreach ($header_data as $content) {
            if (empty($content)) {
                continue;
            }

            // Пропуск пустых строк.
            [$key, $value] = explode(':', $content, 2); // Разделение строки на ключ и значение.
            $headers[$key] = trim($value); // Удаление пробелов в начале и конце значения.
        }

        // Возвращаем объект Response с установленными значениями статуса, заголовков и версии протокола.
        return (new Response())->withStatus((int)$status, $phrase)->withHeaders($headers)->withProtocolVersion($protocolVersion);
    }

    /**
     * Декодирование WebSocket.
     */
    public static function decode(string $bytes, AsyncTcpConnection $asyncTcpConnection): string
    {
        // Получаем длину данных.
        $dataLength = ord($bytes[1]);

        // Если длина данных равна 126, данные начинаются с 4-го байта.
        if ($dataLength === 126) {
            $decodedData = substr($bytes, 4);
        } elseif ($dataLength === 127) {
            // Если длина данных равна 127, данные начинаются с 10-го байта.
            $decodedData = substr($bytes, 10);
        } else {
            // В противном случае данные начинаются со 2-го байта.
            $decodedData = substr($bytes, 2);
        }

        // Если текущая длина кадра websocket не равна нулю,
        // добавляем декодированные данные в буфер данных websocket и возвращаем его.
        if ($asyncTcpConnection->context->websocketCurrentFrameLength) {
            $asyncTcpConnection->context->websocketDataBuffer .= $decodedData;
            return $asyncTcpConnection->context->websocketDataBuffer;
        }

        // Если в буфере данных websocket есть данные,
        // добавляем к ним декодированные данные и очищаем буфер.
        if ($asyncTcpConnection->context->websocketDataBuffer !== '') {
            $decodedData = $asyncTcpConnection->context->websocketDataBuffer . $decodedData;
            $asyncTcpConnection->context->websocketDataBuffer = '';
        }

        // Возвращаем декодированные данные.
        return $decodedData;
    }

    /**
     * Кодирование WebSocket.
     *
     * @throws Throwable
     */
    public static function encode(string $payload, AsyncTcpConnection $asyncTcpConnection): string
    {
        // Получаем длину буфера.
        if (empty($asyncTcpConnection->websocketType)) {
            $asyncTcpConnection->websocketType = self::BINARY_TYPE_BLOB;
        }

        if (empty($asyncTcpConnection->context->handshakeStep)) {
            static::sendHandshake($asyncTcpConnection);
        }

        // Ключ маскирования.
        $maskKey = "\x00\x00\x00\x00";
        $length = strlen($payload);

        // Кодируем данные в зависимости от их длины.
        if (strlen($payload) < 126) {
            $head = chr(0x80 | $length);
        } elseif ($length < 0xFFFF) {
            $head = chr(0x80 | 126) . pack("n", $length);
        } else {
            $head = chr(0x80 | 127) . pack("N", 0) . pack("N", $length);
        }

        // Формируем кадр данных.
        $frame = $asyncTcpConnection->websocketType . $head . $maskKey;

        // Добавляем полезную нагрузку в кадр:
        $maskKey = str_repeat($maskKey, (int)floor($length / 4)) . substr($maskKey, 0, $length % 4);
        $frame .= $payload ^ $maskKey;

        if ($asyncTcpConnection->context->handshakeStep === 1) {
            // Если буфер уже заполнен, отбрасываем текущий пакет.
            if (strlen($asyncTcpConnection->context->tmpWebsocketData) > $asyncTcpConnection->maxSendBufferSize) {
                if ($asyncTcpConnection->onError) {
                    try {
                        ($asyncTcpConnection->onError)($asyncTcpConnection, ConnectionInterface::SEND_FAIL, 'send buffer full and drop package');
                    } catch (Throwable $e) {
                        Server::stopAll(250, $e);
                    }
                }

                return '';
            }

            // Добавляем закодированный кадр во временные данные websocket.
            $asyncTcpConnection->context->tmpWebsocketData .= $frame;

            // Проверяем, заполнен ли буфер.
            if ($asyncTcpConnection->onBufferFull && $asyncTcpConnection->maxSendBufferSize <= strlen($asyncTcpConnection->context->tmpWebsocketData)) {
                try {
                    ($asyncTcpConnection->onBufferFull)($asyncTcpConnection);
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            return '';
        }

        // Возвращаем закодированный кадр.
        return $frame;
    }

    /**
     * Отправка рукопожатия WebSocket.
     *
     * @throws Throwable
     */
    public static function sendHandshake(AsyncTcpConnection $asyncTcpConnection): void
    {
        // Если шаг рукопожатия уже установлен, возвращаемся.
        if (!empty($asyncTcpConnection->context->handshakeStep)) {
            return;
        }

        // Получение хоста.
        $port = $asyncTcpConnection->getRemotePort();
        $host = $port === 80 || $port === 443 ? $asyncTcpConnection->getRemoteHost() : $asyncTcpConnection->getRemoteHost() . ':' . $port;
        // Заголовок рукопожатия.
        $asyncTcpConnection->context->websocketSecKey = base64_encode(random_bytes(16));
        $userHeader = $asyncTcpConnection->headers ?? null;
        $userHeaderStr = '';
        if (!empty($userHeader)) {
            foreach ($userHeader as $k => $v) {
                $userHeaderStr .= "$k: $v\r\n";
            }

            $userHeaderStr = "\r\n" . trim($userHeaderStr);
        }

        // Формирование заголовка запроса.
        $header = 'GET ' . $asyncTcpConnection->getRemoteURI() . " HTTP/1.1\r\n" .
            (preg_match("/\nHost:/i", $userHeaderStr) ? '' : "Host: $host\r\n") .
            "Connection: Upgrade\r\n" .
            "Upgrade: websocket\r\n" .
            (property_exists($asyncTcpConnection, 'websocketOrigin') && $asyncTcpConnection->websocketOrigin !== null ? "Origin: " . $asyncTcpConnection->websocketOrigin . "\r\n" : '') .
            (property_exists($asyncTcpConnection, 'websocketClientProtocol') && $asyncTcpConnection->websocketClientProtocol !== null ? "Sec-WebSocket-Protocol: " . $asyncTcpConnection->websocketClientProtocol . "\r\n" : '') .
            "Sec-WebSocket-Version: 13\r\n" .
            "Sec-WebSocket-Key: " . $asyncTcpConnection->context->websocketSecKey . $userHeaderStr . "\r\n\r\n";
        // Отправка заголовка запроса.
        $asyncTcpConnection->send($header, true);
        // Установка шага рукопожатия в 1.
        $asyncTcpConnection->context->handshakeStep = 1;
        // Установка текущей длины кадра websocket в 0.
        $asyncTcpConnection->context->websocketCurrentFrameLength = 0;
        // Очистка буфера данных websocket.
        $asyncTcpConnection->context->websocketDataBuffer = '';
        // Очистка временных данных websocket.
        $asyncTcpConnection->context->tmpWebsocketData = '';
    }

    /**
     * Отправка данных рукопожатия WebSocket.
     *
     * @throws Throwable
     */
    public static function onConnect(AsyncTcpConnection $asyncTcpConnection): void
    {
        static::sendHandshake($asyncTcpConnection);
    }

    /**
     * Очистка соединения при его закрытии.
     */
    public static function onClose(AsyncTcpConnection $asyncTcpConnection): void
    {
        // Сброс шага рукопожатия.
        $asyncTcpConnection->context->handshakeStep = null;
        // Сброс текущей длины кадра websocket.
        $asyncTcpConnection->context->websocketCurrentFrameLength = 0;
        // Очистка временных данных websocket.
        $asyncTcpConnection->context->tmpWebsocketData = '';
        // Очистка буфера данных websocket.
        $asyncTcpConnection->context->websocketDataBuffer = '';

        if (!empty($asyncTcpConnection->context->websocketPingTimer)) {
            Timer::del($asyncTcpConnection->context->websocketPingTimer);
            // Сброс таймера пинга websocket.
            $asyncTcpConnection->context->websocketPingTimer = null;
        }
    }
}