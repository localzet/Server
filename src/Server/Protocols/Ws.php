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
use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Protocols\Http\Response;
use localzet\Server;
use localzet\Timer;
use Throwable;
use function base64_encode;
use function bin2hex;
use function floor;
use function gettype;
use function is_array;
use function is_scalar;
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
 * Websocket protocol for client.
 */
class Ws
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
     * @param AsyncTcpConnection $connection
     * @return int|false
     * @throws Throwable
     */
    public static function input(string $buffer, AsyncTcpConnection $connection): bool|int
    {
        if (empty($connection->context->handshakeStep)) {
            Server::safeEcho("Получение данных перед рукопожатием. Буфер:" . bin2hex($buffer) . "\n");
            return false;
        }

        // Получение ответа на рукопожатие
        if ($connection->context->handshakeStep === 1) {
            return self::dealHandshake($buffer, $connection);
        }

        $recvLen = strlen($buffer);
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
            $firstbyte = ord($buffer[0]);
            $secondbyte = ord($buffer[1]);
            $dataLen = $secondbyte & 127;
            $isFinFrame = $firstbyte >> 7;
            $masked = $secondbyte >> 7;

            if ($masked) {
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

                if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        $pongData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        $connection->consumeRecvBuffer($currentFrameLength);
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
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

            $connection->context->websocketCurrentFrameLength = $currentFrameLength;
        }
        // Получены только данные о длине кадра.
        if ($connection->context->websocketCurrentFrameLength === $recvLen) {
            self::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        } // Длина полученных данных больше длины кадра.
        elseif ($connection->context->websocketCurrentFrameLength < $recvLen) {
            self::decode(substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            $connection->context->websocketCurrentFrameLength = 0;
            // Продолжаем читать следующий кадр.
            return self::input(substr($buffer, $currentFrameLength), $connection);
        } // Длина полученных данных меньше длины кадра.
        else {
            return 0;
        }
    }

    /**
     * Websocket encode.
     *
     * @param mixed $payload
     * @param AsyncTcpConnection $connection
     * @return string
     * @throws Throwable
     */
    public static function encode(mixed $payload, AsyncTcpConnection $connection): string
    {
        if (!is_scalar($payload)) {
            throw new Exception("Вы не можете отправить (" . gettype($payload) . ") клиенту, конвертируйте это в строку.");
        }

        if (empty($connection->websocketType)) {
            $connection->websocketType = self::BINARY_TYPE_BLOB;
        }
        if (empty($connection->context->handshakeStep)) {
            static::sendHandshake($connection);
        }

        $maskKey = "\x00\x00\x00\x00";
        $length = strlen($payload);

        if (strlen($payload) < 126) {
            $head = chr(0x80 | $length);
        } elseif ($length < 0xFFFF) {
            $head = chr(0x80 | 126) . pack("n", $length);
        } else {
            $head = chr(0x80 | 127) . pack("N", 0) . pack("N", $length);
        }

        $frame = $connection->websocketType . $head . $maskKey;
        // добавить полезную нагрузку в кадр:
        $maskKey = str_repeat($maskKey, (int)floor($length / 4)) . substr($maskKey, 0, $length % 4);
        $frame .= $payload ^ $maskKey;
        if ($connection->context->handshakeStep === 1) {
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
            $connection->context->tmpWebsocketData .= $frame;
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
        return $frame;
    }

    /**
     * Websocket decode.
     *
     * @param string $bytes
     * @param AsyncTcpConnection $connection
     * @return string
     */
    public static function decode(string $bytes, AsyncTcpConnection $connection): string
    {
        $dataLength = ord($bytes[1]);

        if ($dataLength === 126) {
            $decodedData = substr($bytes, 4);
        } else if ($dataLength === 127) {
            $decodedData = substr($bytes, 10);
        } else {
            $decodedData = substr($bytes, 2);
        }
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decodedData;
            return $connection->context->websocketDataBuffer;
        }
        if ($connection->context->websocketDataBuffer !== '') {
            $decodedData = $connection->context->websocketDataBuffer . $decodedData;
            $connection->context->websocketDataBuffer = '';
        }
        return $decodedData;
    }

    /**
     * Send websocket handshake data.
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
     * Clean
     *
     * @param AsyncTcpConnection $connection
     */
    public static function onClose(AsyncTcpConnection $connection): void
    {
        $connection->context->handshakeStep = null;
        $connection->context->websocketCurrentFrameLength = 0;
        $connection->context->tmpWebsocketData = '';
        $connection->context->websocketDataBuffer = '';
        if (!empty($connection->context->websocketPingTimer)) {
            Timer::del($connection->context->websocketPingTimer);
            $connection->context->websocketPingTimer = null;
        }
    }

    /**
     * Send websocket handshake.
     *
     * @param AsyncTcpConnection $connection
     * @return void
     * @throws Throwable
     */
    public static function sendHandshake(AsyncTcpConnection $connection): void
    {
        if (!empty($connection->context->handshakeStep)) {
            return;
        }
        // Получение хоста
        $port = $connection->getRemotePort();
        $host = $port === 80 ? $connection->getRemoteHost() : $connection->getRemoteHost() . ':' . $port;
        // Заголовок рукопожатия
        $connection->context->websocketSecKey = base64_encode(random_bytes(16));
        $userHeader = $connection->headers ?? null;
        $userHeaderStr = '';
        if (!empty($userHeader)) {
            if (is_array($userHeader)) {
                foreach ($userHeader as $k => $v) {
                    $userHeaderStr .= "$k: $v\r\n";
                }
            } else {
                $userHeaderStr .= $userHeader;
            }
            $userHeaderStr = "\r\n" . trim($userHeaderStr);
        }
        $header = 'GET ' . $connection->getRemoteURI() . " HTTP/1.1\r\n" .
            (!preg_match("/\nHost:/i", $userHeaderStr) ? "Host: $host\r\n" : '') .
            "Connection: Upgrade\r\n" .
            "Upgrade: websocket\r\n" .
            (isset($connection->websocketOrigin) ? "Origin: " . $connection->websocketOrigin . "\r\n" : '') .
            (isset($connection->websocketClientProtocol) ? "Sec-WebSocket-Protocol: " . $connection->websocketClientProtocol . "\r\n" : '') .
            "Sec-WebSocket-Version: 13\r\n" .
            "Sec-WebSocket-Key: " . $connection->context->websocketSecKey . $userHeaderStr . "\r\n\r\n";
        $connection->send($header, true);
        $connection->context->handshakeStep = 1;
        $connection->context->websocketCurrentFrameLength = 0;
        $connection->context->websocketDataBuffer = '';
        $connection->context->tmpWebsocketData = '';
    }

    /**
     * Websocket handshake.
     *
     * @param string $buffer
     * @param AsyncTcpConnection $connection
     * @return bool|int
     * @throws Throwable
     */
    public static function dealHandshake(string $buffer, AsyncTcpConnection $connection): bool|int
    {
        $pos = strpos($buffer, "\r\n\r\n");
        if ($pos) {
            // Проверка Sec-WebSocket-Accept
            if (preg_match("/Sec-WebSocket-Accept: *(.*?)\r\n/i", $buffer, $match)) {
                if ($match[1] !== base64_encode(sha1($connection->context->websocketSecKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true))) {
                    Server::safeEcho("Sec-WebSocket-Accept не совпадает. Заголовок:\n" . substr($buffer, 0, $pos) . "\n");
                    $connection->close();
                    return 0;
                }
            } else {
                Server::safeEcho("Sec-WebSocket-Accept не найден. Заголовок:\n" . substr($buffer, 0, $pos) . "\n");
                $connection->close();
                return 0;
            }

            // Рукопожатие завершено
            $connection->context->handshakeStep = 2;
            $handshakeResponseLength = $pos + 4;
            $buffer = substr($buffer, 0, $handshakeResponseLength);
            $response = static::parseResponse($buffer);
            // Попытка вызвать onWebSocketConnect
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

            $connection->consumeRecvBuffer($handshakeResponseLength);
            if (!empty($connection->context->tmpWebsocketData)) {
                $connection->send($connection->context->tmpWebsocketData, true);
                $connection->context->tmpWebsocketData = '';
            }
            if (strlen($buffer) > $handshakeResponseLength) {
                return self::input(substr($buffer, $handshakeResponseLength), $connection);
            }
        }
        return 0;
    }

    /**
     * Parse response.
     *
     * @param string $buffer
     * @return Response
     */
    protected static function parseResponse(string $buffer): Response
    {
        [$http_header,] = \explode("\r\n\r\n", $buffer, 2);
        $header_data = \explode("\r\n", $http_header);
        [$protocol, $status, $phrase] = \explode(' ', $header_data[0], 3);
        $protocolVersion = substr($protocol, 5);
        unset($header_data[0]);
        $headers = [];
        foreach ($header_data as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            list($key, $value) = \explode(':', $content, 2);
            $value = \trim($value);
            $headers[$key] = $value;
        }
        return (new Response())->withStatus((int)$status, $phrase)->withHeaders($headers)->withProtocolVersion($protocolVersion);
    }
}
