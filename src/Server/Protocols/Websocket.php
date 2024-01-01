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

namespace localzet\Server\Protocols;

use Exception;
use Throwable;
use localzet\Server;
use localzet\Server\Connection\{ConnectionInterface, TcpConnection};
use localzet\Server\Protocols\Http\Request;
use function base64_encode;
use function chr;
use function floor;
use function gettype;
use function is_scalar;
use function ord;
use function pack;
use function preg_match;
use function sha1;
use function str_repeat;
use function stripos;
use function strlen;
use function strpos;
use function substr;
use function unpack;

/**
 * Протокол WebSocket.
 */
class Websocket
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
     * @param TcpConnection $connection
     * @return int
     * @throws Throwable
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        // Получаем длину полученных данных.
        $recvLen = strlen($buffer);
        // Если длина данных меньше 6, возвращаем 0.
        if ($recvLen < 6) {
            return 0;
        }

        // Если рукопожатие еще не завершено, обрабатываем его.
        if (empty($connection->context->websocketHandshake)) {
            return static::dealHandshake($buffer, $connection);
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

            // Если данные не замаскированы, выводим сообщение об ошибке и закрываем соединение.
            if (!$masked) {
                Server::safeEcho("Кадр не замаскирован, закрываю соединение\n");
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
                    $closeCb = $connection->onWebSocketClose ?? $connection->server->onWebSocketClose ?? false;
                    if ($closeCb) {
                        try {
                            $closeCb($connection);
                        } catch (Throwable $e) {
                            Server::stopAll(250, $e);
                        }
                    } // Закрытие соединения
                    else {
                        $connection->close("\x88\x02\x03\xe8", true);
                    }
                    return 0;
                // Неверный опкод
                default:
                    Server::safeEcho("Ошибка опкода $opcode и закрытие WebSocket соединения. Буфер:" . $buffer . "\n");
                    $connection->close();
                    return 0;
            }

            // Рассчитать длину пакета
            $headLen = 6;
            if ($dataLen === 126) {
                $headLen = 8;
                if ($headLen > $recvLen) {
                    return 0;
                }
                $pack = unpack('nn/ntotal_len', $buffer);
                $dataLen = $pack['total_len'];
            } else {
                if ($dataLen === 127) {
                    $headLen = 14;
                    if ($headLen > $recvLen) {
                        return 0;
                    }
                    $arr = unpack('n/N2c', $buffer);
                    $dataLen = $arr['c1'] * 4294967296 + $arr['c2'];
                }
            }

            // Вычисляем текущую длину кадра.
            $currentFrameLength = $headLen + $dataLen;

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
                        $pingCb = $connection->onWebSocketPing ?? $connection->server->onWebSocketPing ?? false;
                        if ($pingCb) {
                            try {
                                $pingCb($connection, $pingData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        } else {
                            // Отправляем данные пинг-пакета обратно клиенту.
                            $connection->send($pingData);
                        }
                        // Восстанавливаем тип websocket.
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
                        $pongCb = $connection->onWebSocketPong ?? $connection->server->onWebSocketPong ?? false;
                        if ($pongCb) {
                            try {
                                $pongCb($connection, $pongData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        }

                        // Восстанавливаем тип websocket.
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
        }

        // Если длина полученных данных больше длины кадра.
        if ($connection->context->websocketCurrentFrameLength < $recvLen) {
            // Декодируем данные текущего кадра.
            static::decode(substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            // Удаляем декодированные данные из буфера.
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            // Сохраняем текущую длину кадра.
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            // Устанавливаем текущую длину кадра websocket в 0.
            $connection->context->websocketCurrentFrameLength = 0;
            // Продолжаем чтение следующего кадра.
            return static::input(substr($buffer, $currentFrameLength), $connection);
        }

        // Если длина полученных данных меньше длины кадра, возвращаем 0.
        return 0;
    }

    /**
     * Рукопожатие WebSocket.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     * @throws Throwable
     */
    public static function dealHandshake(string $buffer, TcpConnection $connection): int
    {
        // Протокол HTTP.
        if (str_starts_with($buffer, 'GET')) {
            // Найти "\r\n\r\n".
            $pos = strpos($buffer, "\r\n\r\n");
            if (!$pos) {
                return 0;
            }

            // Длина заголовка.
            $headerLength = $pos + 4;

            // Получить Sec-WebSocket-Key.
            if (preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match)) {
                $SecWebSocketKey = $match[1];
            } else {
                $connection->close(
                    "HTTP/1.0 200 OK\r\nServer: Localzet Server " . Server::getVersion() . "\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>Localzet Server " . Server::getVersion() . "</div>",
                    true
                );
                return 0;
            }
            // Расчет ключа websocket.
            $newKey = base64_encode(sha1($SecWebSocketKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Данные ответа на рукопожатие.
            $handshakeMessage = "HTTP/1.1 101 Switching Protocol\r\n"
                . "Server: Localzet Server " . Server::getVersion() . "\r\n"
                . "Upgrade: websocket\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: " . $newKey . "\r\n";

            // Буфер данных websocket.
            $connection->context->websocketDataBuffer = '';
            
            // Текущая длина кадра websocket.
            $connection->context->websocketCurrentFrameLength = 0;

            // Текущие данные кадра websocket.
            $connection->context->websocketCurrentFrameBuffer = '';

            // Разбор данных рукопожатия.
            $connection->consumeRecvBuffer($headerLength);

            // Попытка вызвать обратный вызов onWebSocketConnect.
            $onWebsocketConnect = $connection->onWebSocketConnect ?? $connection->server->onWebSocketConnect ?? false;
            if ($onWebsocketConnect) {
                try {
                    $onWebsocketConnect($connection, new Request($buffer));
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            // blob или arraybuffer
            if (empty($connection->websocketType)) {
                $connection->websocketType = static::BINARY_TYPE_BLOB;
            }

            if ($connection->headers) {
                foreach ($connection->headers as $header) {
                    if (stripos($header, 'Server:') === 0) {
                        continue;
                    }
                    $handshakeMessage .= "$header\r\n";
                }
            }

            $handshakeMessage .= "\r\n";
            // Отправить ответ на рукопожатие.
            $connection->send($handshakeMessage, true);
            // Пометить рукопожатие как завершенное.
            $connection->context->websocketHandshake = true;

            // Есть данные, ожидающие отправки.
            if (!empty($connection->context->tmpWebsocketData)) {
                // Отправка временных данных websocket.
                $connection->send($connection->context->tmpWebsocketData, true);
                // Очистка временных данных websocket.
                $connection->context->tmpWebsocketData = '';
            }

            if (strlen($buffer) > $headerLength) {
                return static::input(substr($buffer, $headerLength), $connection);
            }
            return 0;
        }
        // Неверный запрос рукопожатия через веб-сокет.
        $connection->close(
            "HTTP/1.0 200 OK\r\nServer: Localzet Server " . Server::getVersion() . "\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>Localzet Server " . Server::getVersion() . "</div>",
            true
        );
        return 0;
    }


    /**
     * Декодирование WebSocket.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return string
     */
    public static function decode(string $buffer, TcpConnection $connection): string
    {
        // Получаем первый байт данных.
        $firstByte = ord($buffer[1]);
        // Извлекаем длину данных.
        $dataLength = $firstByte & 127;

        // Если длина равна 126, то маска начинается с 4-го байта, а данные - с 8-го.
        if ($dataLength === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($dataLength === 127) {
            // Если длина равна 127, то маска начинается с 10-го байта, а данные - с 14-го.
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            // В противном случае маска начинается со 2-го байта, а данные - с 6-го.
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }

        // Вычисляем длину данных.
        $dataLength = strlen($data);
        // Генерируем маску для декодирования данных.
        $masks = str_repeat($masks, (int) floor($dataLength / 4)) . substr($masks, 0, $dataLength % 4);
        // Декодируем данные.
        $decodedData = $data ^ $masks;

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
     * @param mixed $buffer
     * @param TcpConnection $connection
     * @return string
     * @throws Throwable
     */
    public static function encode(mixed $buffer, TcpConnection $connection): string
    {
        // Если буфер не является скалярным значением, выбрасываем исключение.
        if (!is_scalar($buffer)) {
            throw new Exception("Вы не можете отправить (" . gettype($buffer) . ") клиенту, конвертируйте это в строку.");
        }

        // Получаем длину буфера.
        $length = strlen($buffer);

        // Если тип websocket не установлен, устанавливаем его в BINARY_TYPE_BLOB.
        if (empty($connection->websocketType)) {
            $connection->websocketType = static::BINARY_TYPE_BLOB;
        }

        // Устанавливаем первый байт в тип websocket.
        $firstByte = $connection->websocketType;

        // Кодируем данные в зависимости от их длины.
        if ($length <= 125) {
            $encodeBuffer = $firstByte . chr($length) . $buffer;
        } elseif ($length <= 65535) {
            $encodeBuffer = $firstByte . chr(126) . pack("n", $length) . $buffer;
        } else {
            $encodeBuffer = $firstByte . chr(127) . pack("xxxxN", $length) . $buffer;
        }

        // Если рукопожатие еще не завершено, данные websocket временного буфера ожидают отправки.
        if (empty($connection->context->websocketHandshake)) {
            if (empty($connection->context->tmpWebsocketData)) {
                $connection->context->tmpWebsocketData = '';
            }
            
            // Если буфер уже заполнен, отбрасываем текущий пакет.
            if (strlen($connection->context->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        ($connection->onError)($connection, ConnectionInterface::SEND_FAIL, 'отправить полный буфер и удалить пакет');
                    } catch (Throwable $e) {
                        Server::stopAll(250, $e);
                    }
                }
                return '';
            }
            // Добавляем закодированный буфер во временные данные websocket.
            $connection->context->tmpWebsocketData .= $encodeBuffer;

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

        // Возвращаем закодированный буфер.
        return $encodeBuffer;
    }

}