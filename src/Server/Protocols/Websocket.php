<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl AGPL-3.0 license
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Server\Protocols;

use Exception;
use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server;
use Throwable;
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
 * WebSocket protocol.
 */
class Websocket
{
    /**
     * Websocket blob type.
     *
     * @var string
     */
    public const BINARY_TYPE_BLOB = "\x81";

    /**
     * Websocket arraybuffer type.
     *
     * @var string
     */
    public const BINARY_TYPE_ARRAYBUFFER = "\x82";

    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     * @throws Throwable
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        $recvLen = strlen($buffer);
        if ($recvLen < 6) {
            return 0;
        }

        // Еще не завершено рукопожатие.
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
            $firstbyte = ord($buffer[0]);
            $secondbyte = ord($buffer[1]);
            $dataLen = $secondbyte & 127;
            $isFinFrame = $firstbyte >> 7;
            $masked = $secondbyte >> 7;

            if (!$masked) {
                Server::safeEcho("Кадр замаскирован, закрываю соединение\n");
                $connection->close();
                return 0;
            }

            $opcode = $firstbyte & 0xf;
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
            $currentFrameLength = $headLen + $dataLen;

            $totalPackageSize = strlen($connection->context->websocketDataBuffer) + $currentFrameLength;
            if ($totalPackageSize > $connection->maxPackageSize) {
                Server::safeEcho("Ошибка пакета. package_length=$totalPackageSize\n");
                $connection->close();
                return 0;
            }

            if ($isFinFrame) {
                if ($opcode === 0x9) {
                    if ($recvLen >= $currentFrameLength) {
                        $pingData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        $connection->consumeRecvBuffer($currentFrameLength);
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        $pingCb = $connection->onWebSocketPing ?? $connection->server->onWebSocketPing ?? false;
                        if ($pingCb) {
                            try {
                                $pingCb($connection, $pingData);
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

                if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        $pongData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        $connection->consumeRecvBuffer($currentFrameLength);
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
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
                        $connection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;
                }
                return $currentFrameLength;
            }

            $connection->context->websocketCurrentFrameLength = $currentFrameLength;
        }

        // Получены только данные о длине кадра.
        if ($connection->context->websocketCurrentFrameLength === $recvLen) {
            static::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        }

        // Длина полученных данных больше длины кадра.
        if ($connection->context->websocketCurrentFrameLength < $recvLen) {
            static::decode(substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            $connection->context->websocketCurrentFrameLength = 0;
            // Продолжаем читать следующий кадр.
            return static::input(substr($buffer, $currentFrameLength), $connection);
        }

        // Длина полученных данных меньше длины кадра.
        return 0;
    }

    /**
     * Websocket encode.
     *
     * @param mixed $buffer
     * @param TcpConnection $connection
     * @return string
     * @throws Throwable
     */
    public static function encode(mixed $buffer, TcpConnection $connection): string
    {
        if (!is_scalar($buffer)) {
            throw new Exception("Вы не можете отправить (" . gettype($buffer) . ") клиенту, конвертируйте это в строку.");
        }

        $len = strlen($buffer);
        if (empty($connection->websocketType)) {
            $connection->websocketType = static::BINARY_TYPE_BLOB;
        }

        $firstByte = $connection->websocketType;

        if ($len <= 125) {
            $encodeBuffer = $firstByte . chr($len) . $buffer;
        } else {
            if ($len <= 65535) {
                $encodeBuffer = $firstByte . chr(126) . pack("n", $len) . $buffer;
            } else {
                $encodeBuffer = $firstByte . chr(127) . pack("xxxxN", $len) . $buffer;
            }
        }

        // Рукопожатие не завершено, поэтому данные веб-сокета временного буфера ожидают отправки.
        if (empty($connection->context->websocketHandshake)) {
            if (empty($connection->context->tmpWebsocketData)) {
                $connection->context->tmpWebsocketData = '';
            }
            // Если буфер уже заполнен, отбросить текущий пакет.
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
            $connection->context->tmpWebsocketData .= $encodeBuffer;
            // Проверка наполненности буфера
            if ($connection->onBufferFull && $connection->maxSendBufferSize <= strlen($connection->context->tmpWebsocketData)) {
                try {
                    ($connection->onBufferFull)($connection);
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }
            return '';
        }

        return $encodeBuffer;
    }

    /**
     * Websocket decode.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return string
     */
    public static function decode(string $buffer, TcpConnection $connection): string
    {
        $firstByte = ord($buffer[1]);
        $len = $firstByte & 127;

        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else {
            if ($len === 127) {
                $masks = substr($buffer, 10, 4);
                $data = substr($buffer, 14);
            } else {
                $masks = substr($buffer, 2, 4);
                $data = substr($buffer, 6);
            }
        }
        $dataLength = strlen($data);
        $masks = str_repeat($masks, (int)floor($dataLength / 4)) . substr($masks, 0, $dataLength % 4);
        $decoded = $data ^ $masks;
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decoded;
            return $connection->context->websocketDataBuffer;
        }

        if ($connection->context->websocketDataBuffer !== '') {
            $decoded = $connection->context->websocketDataBuffer . $decoded;
            $connection->context->websocketDataBuffer = '';
        }
        return $decoded;
    }

    /**
     * Websocket handshake.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     * @throws Throwable
     */
    public static function dealHandshake(string $buffer, TcpConnection $connection): int
    {
        // HTTP protocol.
        if (str_starts_with($buffer, 'GET')) {
            // Find \r\n\r\n.
            $headerEndPos = strpos($buffer, "\r\n\r\n");
            if (!$headerEndPos) {
                return 0;
            }
            $headerLength = $headerEndPos + 4;

            // Get Sec-WebSocket-Key.
            if (preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match)) {
                $SecWebSocketKey = $match[1];
            } else {
                $connection->close(
                    "HTTP/1.1 200 OK\r\nServer: Localzet Server " . Server::getVersion() . "\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>Localzet Server " . Server::getVersion() . "</div>",
                    true
                );
                return 0;
            }
            // Calculation websocket key.
            $newKey = base64_encode(sha1($SecWebSocketKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Handshake response data.
            $handshakeMessage = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Server: Localzet Server " . Server::getVersion() . "\r\n"
                . "Upgrade: websocket\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: " . $newKey . "\r\n"
                . "Sec-WebSocket-Accept: " . $newKey . "\r\n";

            // Websocket data buffer.
            $connection->context->websocketDataBuffer = '';
            // Current websocket frame length.
            $connection->context->websocketCurrentFrameLength = 0;
            // Current websocket frame data.
            $connection->context->websocketCurrentFrameBuffer = '';
            // Consume handshake data.
            $connection->consumeRecvBuffer($headerLength);

            // Try to emit onWebSocketConnect callback.
            $onWebsocketConnect = $connection->onWebSocketConnect ?? $connection->server->onWebSocketConnect ?? false;
            if ($onWebsocketConnect) {
                try {
                    $onWebsocketConnect($connection, new Request($buffer));
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            // blob or arraybuffer
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
                $connection->send($connection->context->tmpWebsocketData, true);
                $connection->context->tmpWebsocketData = '';
            }
            if (strlen($buffer) > $headerLength) {
                return static::input(substr($buffer, $headerLength), $connection);
            }
            return 0;
        }
        // Неверный запрос рукопожатия через веб-сокет.
        $connection->close(
            "HTTP/1.1 200 OK\r\nServer: Localzet Server " . Server::getVersion() . "\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>Localzet Server " . Server::getVersion() . "</div>",
            true
        );
        return 0;
    }
}
