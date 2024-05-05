<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2016-2024 Zorin Projects
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Protocols;

use localzet\Server;
use localzet\Server\Connection\{AsyncTcpConnection, ConnectionInterface};
use localzet\Server\Protocols\Http\Response;
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
     * @param AsyncTcpConnection $connection
     * @return int|false
     * @throws Throwable
     */
    public static function input(string $buffer, AsyncTcpConnection $connection): bool|int
    {
        // Если шаг рукопожатия не установлен, выводим сообщение об ошибке и возвращаем false.
        if (empty($connection->context->handshakeStep)) {
            Server::safeEcho("Получение данных перед рукопожатием. Буфер:" . bin2hex($buffer) . "\n");
            return false;
        }

        // Если шаг рукопожатия равен 1, обрабатываем рукопожатие.
        if ($connection->context->handshakeStep === 1) {
            return self::dealHandshake($buffer, $connection);
        }

        // Получаем длину полученных данных.
        $recvLen = strlen($buffer);
        // Если длина данных меньше 2, возвращаем 0.
        if ($recvLen < 2) {
            return 0;
        }

        // Буферизовать данные кадра веб-сокета.
        if ($connection->context->websocketCurrentFrameLength) {
            // Нам нужно больше данных кадра.
            if ($connection->context->websocketCurrentFrameLength > $recvLen) {
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
                $connection->close();
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
                    if (isset($connection->onWebSocketClose)) {
                        try {
                            ($connection->onWebSocketClose)($connection);
                        } catch (Throwable $e) {
                            Server::stopAll(250, $e);
                        }
                    } // Закрытие соединения
                    else {
                        $connection->close();
                    }
                    return 0;
                // Неверный опкод
                default:
                    Server::safeEcho("Ошибка опкода $opcode и закрытие WebSocket соединения. Буфер:" . $buffer . "\n");
                    $connection->close();
                    return 0;
            }

            // Рассчитать длину пакета
            if ($dataLen === 126) {
                if (strlen($buffer) < 4) {
                    return 0;
                }
                $pack = unpack('nn/ntotal_len', $buffer);
                $currentFrameLength = $pack['total_len'] + 4;
            } else if ($dataLen === 127) {
                if (strlen($buffer) < 10) {
                    return 0;
                }
                $arr = unpack('n/N2c', $buffer);
                $currentFrameLength = $arr['c1'] * 4294967296 + $arr['c2'] + 10;
            } else {
                $currentFrameLength = $dataLen + 2;
            }

            // Вычисляем общий размер пакета.
            $totalPackageSize = strlen($connection->context->websocketDataBuffer) + $currentFrameLength;

            // Если общий размер пакета превышает максимально допустимый размер пакета, выводим сообщение об ошибке и закрываем соединение.
            if ($totalPackageSize > $connection->maxPackageSize) {
                Server::safeEcho("Ошибка пакета. package_length=$totalPackageSize\n");
                $connection->close();
                return 0;
            }

            if ($isFinFrame) {
                // Если код операции равен 0x9 (пинг-пакет).
                if ($opcode === 0x9) {
                    if ($recvLen >= $currentFrameLength) {
                        // Декодируем данные пинг-пакета.
                        $pingData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        // Удаляем данные пинг-пакета из буфера.
                        $connection->consumeRecvBuffer($currentFrameLength);
                        // Сохраняем текущий тип websocket.
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        // Устанавливаем тип websocket в "\x8a".
                        $connection->websocketType = "\x8a";
                        // Попытка вызвать onWebSocketPing
                        if (isset($connection->onWebSocketPing)) {
                            try {
                                ($connection->onWebSocketPing)($connection, $pingData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        } else {
                            $connection->send($pingData);
                        }
                        $connection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;
                }

                // Если код операции равен 0xa (понг-пакет).
                if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        // Декодируем данные понг-пакета.
                        $pongData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        // Удаляем данные понг-пакета из буфера.
                        $connection->consumeRecvBuffer($currentFrameLength);
                        // Сохраняем текущий тип websocket.
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        // Устанавливаем тип websocket в "\x8a".
                        $connection->websocketType = "\x8a";
                        // Попытка вызвать onWebSocketPong
                        if (isset($connection->onWebSocketPong)) {
                            try {
                                ($connection->onWebSocketPong)($connection, $pongData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        }
                        $connection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;
                }

                return $currentFrameLength;
            }

            // Устанавливаем текущую длину кадра websocket.
            $connection->context->websocketCurrentFrameLength = $currentFrameLength;
        }

        // Если получены только данные о длине кадра.
        if ($connection->context->websocketCurrentFrameLength === $recvLen) {
            // Декодируем данные.
            static::decode($buffer, $connection);
            // Удаляем декодированные данные из буфера.
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            // Устанавливаем текущую длину кадра websocket в 0.
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        } // Если длина полученных данных больше длины кадра.
        elseif ($connection->context->websocketCurrentFrameLength < $recvLen) {
            // Декодируем данные текущего кадра.
            static::decode(substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            // Удаляем декодированные данные из буфера.
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            // Сохраняем текущую длину кадра.
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            // Устанавливаем текущую длину кадра websocket в 0.
            $connection->context->websocketCurrentFrameLength = 0;
            // Продолжаем чтение следующего кадра.
            return self::input(substr($buffer, $currentFrameLength), $connection);
        } // Если длина полученных данных меньше длины кадра, возвращаем 0.
        else {
            return 0;
        }
    }

    /**
     * Рукопожатие WebSocket.
     *
     * @param string $buffer
     * @param AsyncTcpConnection $connection
     * @return bool|int
     * @throws Throwable
     */
    public static function dealHandshake(string $buffer, AsyncTcpConnection $connection): bool|int
    {
        // Позиция конца заголовков в буфере.
        $pos = strpos($buffer, "\r\n\r\n");
        if ($pos) {
            // Проверка Sec-WebSocket-Accept.
            if (preg_match("/Sec-WebSocket-Accept: *(.*?)\r\n/i", $buffer, $match)) {
                if ($match[1] !== base64_encode(sha1($connection->context->websocketSecKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true))) {
                    Server::safeEcho("Sec-WebSocket-Accept не совпадает. Заголовок:\n" . substr($buffer, 0, $pos) . "\n");
                    // Закрытие соединения.
                    $connection->close();
                    return 0;
                }
            } else {
                Server::safeEcho("Sec-WebSocket-Accept не найден. Заголовок:\n" . substr($buffer, 0, $pos) . "\n");
                // Закрытие соединения.
                $connection->close();
                return 0;
            }

            // Рукопожатие завершено.
            $connection->context->handshakeStep = 2;
            // Длина ответа на рукопожатие.
            $handshakeResponseLength = $pos + 4;
            // Буфер обрезается до длины ответа на рукопожатие.
            $buffer = substr($buffer, 0, $handshakeResponseLength);
            // Разбор ответа.
            $response = static::parseResponse($buffer);

            // Попытка вызвать обратный вызов onWebSocketConnect.
            if (isset($connection->onWebSocketConnect)) {
                try {
                    ($connection->onWebSocketConnect)($connection, $response);
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            // Серцебиение.
            if (!empty($connection->websocketPingInterval)) {
                $connection->context->websocketPingTimer = Timer::add($connection->websocketPingInterval, function () use ($connection) {
                    if (false === $connection->send(pack('H*', '898000000000'), true)) {
                        Timer::del($connection->context->websocketPingTimer);
                        $connection->context->websocketPingTimer = null;
                    }
                });
            }

            // Удаление данных рукопожатия из буфера.
            $connection->consumeRecvBuffer($handshakeResponseLength);

            // Есть данные, ожидающие отправки.
            if (!empty($connection->context->tmpWebsocketData)) {
                // Отправка временных данных websocket.
                $connection->send($connection->context->tmpWebsocketData, true);
                // Очистка временных данных websocket.
                $connection->context->tmpWebsocketData = '';
            }

            if (strlen($buffer) > $handshakeResponseLength) {
                return self::input(substr($buffer, $handshakeResponseLength), $connection);
            }
        }
        return 0;
    }

    /**
     * Разбор ответа.
     *
     * @param string $buffer
     * @return Response
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
            if (empty($content))
                continue; // Пропуск пустых строк.
            [$key, $value] = explode(':', $content, 2); // Разделение строки на ключ и значение.
            $headers[$key] = trim($value); // Удаление пробелов в начале и конце значения.
        }

        // Возвращаем объект Response с установленными значениями статуса, заголовков и версии протокола.
        return (new Response())->withStatus((int)$status, $phrase)->withHeaders($headers)->withProtocolVersion($protocolVersion);
    }

    /**
     * Декодирование WebSocket.
     *
     * @param string $bytes
     * @param AsyncTcpConnection $connection
     * @return string
     */
    public static function decode(string $bytes, AsyncTcpConnection $connection): string
    {
        // Получаем длину данных.
        $dataLength = ord($bytes[1]);

        // Если длина данных равна 126, данные начинаются с 4-го байта.
        if ($dataLength === 126) {
            $decodedData = substr($bytes, 4);
        } else if ($dataLength === 127) {
            // Если длина данных равна 127, данные начинаются с 10-го байта.
            $decodedData = substr($bytes, 10);
        } else {
            // В противном случае данные начинаются со 2-го байта.
            $decodedData = substr($bytes, 2);
        }

        // Если текущая длина кадра websocket не равна нулю,
        // добавляем декодированные данные в буфер данных websocket и возвращаем его.
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decodedData;
            return $connection->context->websocketDataBuffer;
        }

        // Если в буфере данных websocket есть данные,
        // добавляем к ним декодированные данные и очищаем буфер.
        if ($connection->context->websocketDataBuffer !== '') {
            $decodedData = $connection->context->websocketDataBuffer . $decodedData;
            $connection->context->websocketDataBuffer = '';
        }

        // Возвращаем декодированные данные.
        return $decodedData;
    }

    /**
     * Кодирование WebSocket.
     *
     * @param string $payload
     * @param AsyncTcpConnection $connection
     * @return string
     * @throws Throwable
     */
    public static function encode(string $payload, AsyncTcpConnection $connection): string
    {
        // Получаем длину буфера.
        if (empty($connection->websocketType)) {
            $connection->websocketType = self::BINARY_TYPE_BLOB;
        }
        if (empty($connection->context->handshakeStep)) {
            static::sendHandshake($connection);
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
        $frame = $connection->websocketType . $head . $maskKey;

        // Добавляем полезную нагрузку в кадр:
        $maskKey = str_repeat($maskKey, (int)floor($length / 4)) . substr($maskKey, 0, $length % 4);
        $frame .= $payload ^ $maskKey;

        if ($connection->context->handshakeStep === 1) {
            // Если буфер уже заполнен, отбрасываем текущий пакет.
            if (strlen($connection->context->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        ($connection->onError)($connection, ConnectionInterface::SEND_FAIL, 'send buffer full and drop package');
                    } catch (Throwable $e) {
                        Server::stopAll(250, $e);
                    }
                }
                return '';
            }

            // Добавляем закодированный кадр во временные данные websocket.
            $connection->context->tmpWebsocketData .= $frame;

            // Проверяем, заполнен ли буфер.
            if ($connection->onBufferFull && $connection->maxSendBufferSize <= strlen($connection->context->tmpWebsocketData)) {
                try {
                    ($connection->onBufferFull)($connection);
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
     * @param AsyncTcpConnection $connection
     * @return void
     * @throws Throwable
     */
    public static function sendHandshake(AsyncTcpConnection $connection): void
    {
        // Если шаг рукопожатия уже установлен, возвращаемся.
        if (!empty($connection->context->handshakeStep)) {
            return;
        }
        // Получение хоста.
        $port = $connection->getRemotePort();
        $host = $port === 80 || $port === 443 ? $connection->getRemoteHost() : $connection->getRemoteHost() . ':' . $port;
        // Заголовок рукопожатия.
        $connection->context->websocketSecKey = base64_encode(random_bytes(16));
        $userHeader = $connection->headers ?? null;
        $userHeaderStr = '';
        if (!empty($userHeader)) {
            foreach ($userHeader as $k => $v) {
                $userHeaderStr .= "$k: $v\r\n";
            }
            $userHeaderStr = "\r\n" . trim($userHeaderStr);
        }
        // Формирование заголовка запроса.
        $header = 'GET ' . $connection->getRemoteURI() . " HTTP/1.1\r\n" .
            (!preg_match("/\nHost:/i", $userHeaderStr) ? "Host: $host\r\n" : '') .
            "Connection: Upgrade\r\n" .
            "Upgrade: websocket\r\n" .
            (isset($connection->websocketOrigin) ? "Origin: " . $connection->websocketOrigin . "\r\n" : '') .
            (isset($connection->websocketClientProtocol) ? "Sec-WebSocket-Protocol: " . $connection->websocketClientProtocol . "\r\n" : '') .
            "Sec-WebSocket-Version: 13\r\n" .
            "Sec-WebSocket-Key: " . $connection->context->websocketSecKey . $userHeaderStr . "\r\n\r\n";
        // Отправка заголовка запроса.
        $connection->send($header, true);
        // Установка шага рукопожатия в 1.
        $connection->context->handshakeStep = 1;
        // Установка текущей длины кадра websocket в 0.
        $connection->context->websocketCurrentFrameLength = 0;
        // Очистка буфера данных websocket.
        $connection->context->websocketDataBuffer = '';
        // Очистка временных данных websocket.
        $connection->context->tmpWebsocketData = '';
    }

    /**
     * Отправка данных рукопожатия WebSocket.
     *
     * @param AsyncTcpConnection $connection
     * @return void
     * @throws Throwable
     */
    public static function onConnect(AsyncTcpConnection $connection): void
    {
        static::sendHandshake($connection);
    }

    /**
     * Очистка соединения при его закрытии.
     *
     * @param AsyncTcpConnection $connection
     */
    public static function onClose(AsyncTcpConnection $connection): void
    {
        // Сброс шага рукопожатия.
        $connection->context->handshakeStep = null;
        // Сброс текущей длины кадра websocket.
        $connection->context->websocketCurrentFrameLength = 0;
        // Очистка временных данных websocket.
        $connection->context->tmpWebsocketData = '';
        // Очистка буфера данных websocket.
        $connection->context->websocketDataBuffer = '';

        if (!empty($connection->context->websocketPingTimer)) {
            Timer::del($connection->context->websocketPingTimer);
            // Сброс таймера пинга websocket.
            $connection->context->websocketPingTimer = null;
        }
    }
}