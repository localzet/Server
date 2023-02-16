<?php

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

use Throwable;
use Exception;

use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;

use localzet\Server\Protocols\Http\Request;

use localzet\Server\Server;

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
    const BINARY_TYPE_BLOB = "\x81";

    /**
     * Websocket arraybuffer type.
     *
     * @var string
     */
    const BINARY_TYPE_ARRAYBUFFER = "\x82";

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
        // Receive length.
        $recvLen = strlen($buffer);
        // We need more data.
        if ($recvLen < 6) {
            return 0;
        }

        // Has not yet completed the handshake.
        if (empty($connection->context->websocketHandshake)) {
            return static::dealHandshake($buffer, $connection);
        }

        // Buffer websocket frame data.
        if ($connection->context->websocketCurrentFrameLength) {
            // We need more frame data.
            if ($connection->context->websocketCurrentFrameLength > $recvLen) {
                // Return 0, because it is not clear the full packet length, waiting for the frame of fin=1.
                return 0;
            }
        } else {
            $firstbyte = ord($buffer[0]);
            $secondbyte = ord($buffer[1]);
            $dataLen = $secondbyte & 127;
            $isFinFrame = $firstbyte >> 7;
            $masked = $secondbyte >> 7;

            if (!$masked) {
                Server::safeEcho("frame not masked so close the connection\n");
                $connection->close();
                return 0;
            }

            $opcode = $firstbyte & 0xf;
            switch ($opcode) {
                case 0x0:
                    // Blob type.
                case 0x1:
                    // Arraybuffer type.
                case 0x2:
                    // Ping package.
                case 0x9:
                    // Pong package.
                case 0xa:
                    break;
                    // Close package.
                case 0x8:
                    // Try to emit onWebSocketClose callback.
                    $closeCb = $connection->onWebSocketClose ?? $connection->server->onWebSocketClose ?? false;
                    if ($closeCb) {
                        try {
                            $closeCb($connection);
                        } catch (Throwable $e) {
                            Server::stopAll(250, $e);
                        }
                    }
                    // Close connection.
                    else {
                        $connection->close("\x88\x02\x03\xe8", true);
                    }
                    return 0;
                    // Wrong opcode.
                default:
                    Server::safeEcho("error opcode $opcode and close websocket connection. Buffer:" . bin2hex($buffer) . "\n");
                    $connection->close();
                    return 0;
            }

            // Calculate packet length.
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
                Server::safeEcho("error package. package_length=$totalPackageSize\n");
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
                } else if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        $pongData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        $connection->consumeRecvBuffer($currentFrameLength);
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        // Try to emit onWebSocketPong callback.
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
            } else {
                $connection->context->websocketCurrentFrameLength = $currentFrameLength;
            }
        }

        // Received just a frame length data.
        if ($connection->context->websocketCurrentFrameLength === $recvLen) {
            static::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        } // The length of the received data is greater than the length of a frame.
        elseif ($connection->context->websocketCurrentFrameLength < $recvLen) {
            static::decode(substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            $connection->context->websocketCurrentFrameLength = 0;
            // Continue to read next frame.
            return static::input(substr($buffer, $currentFrameLength), $connection);
        } // The length of the received data is less than the length of a frame.
        else {
            return 0;
        }
    }

    /**
     * Websocket encode.
     *
     * @param mixed $buffer
     * @param TcpConnection $connection
     * @return string
     */
    public static function encode(mixed $buffer, TcpConnection $connection): string
    {
        if (!is_scalar($buffer)) {
            throw new Exception("You can't send(" . gettype($buffer) . ") to client, you need to convert it to string. ");
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

        // Handshake not completed so temporary buffer websocket data waiting for send.
        if (empty($connection->context->websocketHandshake)) {
            if (empty($connection->context->tmpWebsocketData)) {
                $connection->context->tmpWebsocketData = '';
            }
            // If buffer has already full then discard the current package.
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
            $connection->context->tmpWebsocketData .= $encodeBuffer;
            // Check buffer is full.
            if ($connection->maxSendBufferSize <= strlen($connection->context->tmpWebsocketData)) {
                if ($connection->onBufferFull) {
                    try {
                        ($connection->onBufferFull)($connection);
                    } catch (Throwable $e) {
                        Server::stopAll(250, $e);
                    }
                }
            }
            // Return empty string.
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
        $masks = str_repeat($masks, floor($dataLength / 4)) . substr($masks, 0, $dataLength % 4);
        $decoded = $data ^ $masks;
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decoded;
            return $connection->context->websocketDataBuffer;
        } else {
            if ($connection->context->websocketDataBuffer !== '') {
                $decoded = $connection->context->websocketDataBuffer . $decoded;
                $connection->context->websocketDataBuffer = '';
            }
            return $decoded;
        }
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
                    "HTTP/1.1 200 WebSocket\r\nServer: Localzet Server/" . Server::VERSION . "\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>Localzet Server/" . Server::VERSION . "</div>",
                    true
                );
                return 0;
            }
            // Calculation websocket key.
            $newKey = base64_encode(sha1($SecWebSocketKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Handshake response data.
            $handshakeMessage = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Connection: Upgrade\r\n"
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

            $hasServerHeader = false;

            if ($connection->headers) {
                foreach ($connection->headers as $header) {
                    if (stripos($header, 'Server:') === 0) {
                        $hasServerHeader = true;
                    }
                    $handshakeMessage .= "$header\r\n";
                }
            }
            if (!$hasServerHeader) {
                $handshakeMessage .= "Server: Localzet Server/" . Server::VERSION . "\r\n";
            }
            $handshakeMessage .= "\r\n";
            // Send handshake response.
            $connection->send($handshakeMessage, true);
            // Mark handshake complete..
            $connection->context->websocketHandshake = true;

            // There are data waiting to be sent.
            if (!empty($connection->context->tmpWebsocketData)) {
                $connection->send($connection->context->tmpWebsocketData, true);
                $connection->context->tmpWebsocketData = '';
            }
            if (strlen($buffer) > $headerLength) {
                return static::input(substr($buffer, $headerLength), $connection);
            }
            return 0;
        }
        // Bad websocket handshake request.
        $connection->close(
            "HTTP/1.1 200 WebSocket\r\nServer: Localzet Server/" . Server::VERSION . "\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>Localzet Server/" . Server::VERSION . "</div>",
            true
        );
        return 0;
    }
}
