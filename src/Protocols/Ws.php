<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2026 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Server\Protocols;

use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Response;
use localzet\Timer;
use RuntimeException;
use Throwable;

/** RFC 6455 WebSocket client protocol. */
class Ws implements ProtocolInterface
{
    public const BINARY_TYPE_BLOB = "\x81";
    public const BINARY_TYPE_ARRAYBUFFER = "\x82";
    private const MAX_HANDSHAKE_LENGTH = 16384;

    /** Сбрасывает parser state, не затрагивая transport connection. */
    public static function initContext(AsyncTcpConnection $connection): void
    {
        $connection->context->websocketDataBuffer = '';
        $connection->context->websocketFragmentOpcode = null;
        $connection->context->websocketFragmented = false;
        $connection->context->tmpWebsocketData ??= '';
    }

    /** Вызывается AsyncTcpConnection после TCP/TLS establishment. */
    public static function onConnect(AsyncTcpConnection $connection): void
    {
        $connection->websocketOrigin ??= null;
        $connection->websocketClientProtocol ??= null;
        static::sendHandshake($connection);
    }

    public static function onClose(AsyncTcpConnection $connection): void
    {
        if (!empty($connection->context->websocketPingTimer)) {
            Timer::del((int)$connection->context->websocketPingTimer);
        }
        unset(
            $connection->context->handshakeStep,
            $connection->context->websocketPingTimer,
            $connection->context->websocketSecKey,
            $connection->context->wsClientHandshake
        );
        static::initContext($connection);
        $connection->context->tmpWebsocketData = '';
    }

    public static function sendHandshake(AsyncTcpConnection $connection): void
    {
        if (($connection->context->handshakeStep ?? 0) !== 0) {
            return;
        }

        static::initContext($connection);
        $host = $connection->getRemoteHost();
        $port = $connection->getRemotePort();
        if ($port !== 0 && $port !== 80 && $port !== 443) {
            $host = (str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host) . ':' . $port;
        }
        $uri = str_replace(["\r", "\n"], '', $connection->getRemoteURI() ?: '/');
        $key = base64_encode(random_bytes(16));
        $connection->context->websocketSecKey = $key;

        $headers = [];
        foreach ($connection->headers as $name => $value) {
            $safeName = str_replace(["\r", "\n", ':'], '', (string)$name);
            if ($safeName === '') continue;
            foreach ((array)$value as $item) {
                $headers[$safeName][] = str_replace(["\r", "\n"], '', (string)$item);
            }
        }

        $request = "GET {$uri} HTTP/1.1\r\n";
        if (!static::hasHeader($headers, 'Host')) {
            $request .= "Host: {$host}\r\n";
        }
        $request .= "Connection: Upgrade\r\nUpgrade: websocket\r\n";
        if (!empty($connection->websocketOrigin)) {
            $origin = str_replace(["\r", "\n"], '', (string)$connection->websocketOrigin);
            $request .= "Origin: {$origin}\r\n";
        }
        if (!empty($connection->websocketClientProtocol)) {
            $protocol = str_replace(["\r", "\n"], '', (string)$connection->websocketClientProtocol);
            $request .= "Sec-WebSocket-Protocol: {$protocol}\r\n";
        }
        $request .= "Sec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {$key}\r\n";
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                // Не дублируем обязательные handshake headers, кроме явно переопределённого Host.
                if (in_array(strtolower($name), ['connection', 'upgrade', 'sec-websocket-version', 'sec-websocket-key'], true)) {
                    continue;
                }
                $request .= "{$name}: {$value}\r\n";
            }
        }
        $request .= "\r\n";

        $connection->context->handshakeStep = 1;
        $connection->send($request, true);
    }

    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        if (!$connection instanceof AsyncTcpConnection) {
            throw new \InvalidArgumentException('Ws client protocol requires AsyncTcpConnection.');
        }

        $step = (int)($connection->context->handshakeStep ?? 0);
        if ($step === 0) {
            static::sendHandshake($connection);
            return 0;
        }
        if ($step === 1) {
            return static::dealHandshake($buffer, $connection);
        }

        if (strlen($buffer) < 2) return 0;
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $fin = ($first & 0x80) !== 0;
        $rsv = $first & 0x70;
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $lengthCode = $second & 0x7f;

        if ($masked || $rsv !== 0 || !in_array($opcode, [0x0, 0x1, 0x2, 0x8, 0x9, 0xA], true)) {
            $connection->close();
            return 0;
        }

        $offset = 2;
        if ($lengthCode === 126) {
            if (strlen($buffer) < 4) return 0;
            $length = unpack('nlength', substr($buffer, 2, 2))['length'];
            if ($length < 126) {
                $connection->close();
                return 0;
            }
            $offset = 4;
        } elseif ($lengthCode === 127) {
            if (strlen($buffer) < 10) return 0;
            $parts = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            if (($parts['high'] & 0x80000000) !== 0) {
                $connection->close();
                return 0;
            }
            $length = $parts['high'] * 4294967296 + $parts['low'];
            if ($length < 65536 || $length > PHP_INT_MAX) {
                $connection->close();
                return 0;
            }
            $length = (int)$length;
            $offset = 10;
        } else {
            $length = $lengthCode;
        }

        if ($opcode >= 0x8 && (!$fin || $length > 125)) {
            $connection->close();
            return 0;
        }
        $fragmented = (bool)($connection->context->websocketFragmented ?? false);
        if ($opcode < 0x8 && (($opcode === 0x0) !== $fragmented)) {
            $connection->close();
            return 0;
        }
        if ($length > $connection->maxPackageSize) {
            $connection->close();
            return 0;
        }
        return strlen($buffer) >= $offset + $length ? $offset + $length : 0;
    }

    public static function decode(string $buffer, ConnectionInterface $connection): mixed
    {
        if (!$connection instanceof AsyncTcpConnection) {
            throw new \InvalidArgumentException('Ws client protocol requires AsyncTcpConnection.');
        }
        if (str_starts_with($buffer, 'HTTP/')) {
            return null;
        }

        $first = ord($buffer[0]);
        $opcode = $first & 0x0f;
        $fin = ($first & 0x80) !== 0;
        $lengthCode = ord($buffer[1]) & 0x7f;
        $offset = $lengthCode === 126 ? 4 : ($lengthCode === 127 ? 10 : 2);
        $payload = substr($buffer, $offset);

        if ($opcode === 0x8) {
            if (strlen($payload) === 1) {
                $connection->close();
                return null;
            }
            $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1000;
            $reason = strlen($payload) > 2 ? substr($payload, 2) : '';
            if (!static::isValidCloseCode($code) || ($reason !== '' && preg_match('//u', $reason) !== 1)) {
                $connection->close();
                return null;
            }
            if ($connection->onWebSocketClose !== null) {
                ($connection->onWebSocketClose)($connection, $code, $reason);
            }
            // Ответ на server close обязан быть masked как любой client frame.
            $connection->close(static::clientFrame(pack('n', $code) . $reason, 0x8), true);
            return null;
        }
        if ($opcode === 0x9) {
            if ($connection->onWebSocketPing !== null) {
                ($connection->onWebSocketPing)($connection, $payload);
            } else {
                $connection->send(static::clientFrame($payload, 0xA), true);
            }
            return null;
        }
        if ($opcode === 0xA) {
            if ($connection->onWebSocketPong !== null) {
                ($connection->onWebSocketPong)($connection, $payload);
            }
            return null;
        }

        if ($opcode === 0x1 || $opcode === 0x2) {
            if (!$fin) {
                $connection->context->websocketFragmented = true;
                $connection->context->websocketFragmentOpcode = $opcode;
                $connection->context->websocketDataBuffer = $payload;
                return null;
            }
            if ($opcode === 0x1 && preg_match('//u', $payload) !== 1) {
                $connection->close();
                return null;
            }
            return $payload;
        }

        if ($opcode === 0x0) {
            $connection->context->websocketDataBuffer .= $payload;
            if (strlen($connection->context->websocketDataBuffer) > $connection->maxPackageSize) {
                $connection->close();
                return null;
            }
            if (!$fin) return null;
            $message = $connection->context->websocketDataBuffer;
            $originalOpcode = (int)($connection->context->websocketFragmentOpcode ?? 0x2);
            $connection->context->websocketDataBuffer = '';
            $connection->context->websocketFragmentOpcode = null;
            $connection->context->websocketFragmented = false;
            if ($originalOpcode === 0x1 && preg_match('//u', $message) !== 1) {
                $connection->close();
                return null;
            }
            return $message;
        }
        return null;
    }

    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        if (!$connection instanceof AsyncTcpConnection) {
            throw new \InvalidArgumentException('Ws client protocol requires AsyncTcpConnection.');
        }
        if (!is_scalar($data) && !$data instanceof \Stringable) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) throw new RuntimeException('Unable to JSON-encode WebSocket payload.');
            $data = $json;
        }

        if (($connection->context->handshakeStep ?? 0) === 0) {
            static::sendHandshake($connection);
        }
        $type = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
        $opcode = is_string($type) && strlen($type) === 1
            ? ord($type) & 0x0f
            : (in_array($type, ['binary', 'arraybuffer'], true) ? 0x2 : 0x1);
        $frame = static::clientFrame((string)$data, $opcode);

        if (($connection->context->handshakeStep ?? 0) !== 2) {
            $pending = (string)($connection->context->tmpWebsocketData ?? '');
            if (strlen($pending) + strlen($frame) > $connection->maxSendBufferSize) {
                if ($connection->onError !== null) {
                    ($connection->onError)($connection, ConnectionInterface::SEND_FAIL, 'WebSocket pre-handshake buffer is full.');
                }
                return '';
            }
            $connection->context->tmpWebsocketData = $pending . $frame;
            return '';
        }
        return $frame;
    }

    public static function clientFrame(string $payload, int $opcode = 0x1): string
    {
        $mask = random_bytes(4);
        $masked = $payload;
        for ($i = 0, $length = strlen($masked); $i < $length; $i++) {
            $masked[$i] = $masked[$i] ^ $mask[$i & 3];
        }
        $length = strlen($payload);
        $head = chr(0x80 | ($opcode & 0x0f));
        if ($length < 126) {
            $head .= chr(0x80 | $length);
        } elseif ($length <= 0xffff) {
            $head .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $head .= chr(0x80 | 127) . pack('NN', intdiv($length, 4294967296), $length % 4294967296);
        }
        return $head . $mask . $masked;
    }

    public static function dealHandshake(string $buffer, AsyncTcpConnection $connection): int
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) >= self::MAX_HANDSHAKE_LENGTH) $connection->close();
            return 0;
        }
        if ($end >= self::MAX_HANDSHAKE_LENGTH) {
            $connection->close();
            return 0;
        }

        $length = $end + 4;
        $head = substr($buffer, 0, $length);
        if (!preg_match('~^HTTP/1\.[01]\s+101\b~', $head)) {
            $connection->close();
            return 0;
        }
        $headers = static::parseHeaderBlock($head);
        $upgrade = strtolower($headers['upgrade'] ?? '');
        $connectionTokens = array_map('trim', explode(',', strtolower($headers['connection'] ?? '')));
        $accept = trim($headers['sec-websocket-accept'] ?? '');
        $expected = base64_encode(sha1(($connection->context->websocketSecKey ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if ($upgrade !== 'websocket' || !in_array('upgrade', $connectionTokens, true) || !hash_equals($expected, $accept)) {
            $connection->close();
            return 0;
        }

        $connection->context->handshakeStep = 2;
        $connection->context->wsClientHandshake = true;
        $response = static::parseResponse($head);

        if ($connection->onWebSocketConnect !== null) {
            try {
                ($connection->onWebSocketConnect)($connection, $response);
            } catch (Throwable $e) {
                $connection->error($e);
            }
        }
        if ($connection->onWebSocketConnected !== null && $connection->onWebSocketConnected !== $connection->onWebSocketConnect) {
            try {
                ($connection->onWebSocketConnected)($connection, $response);
            } catch (Throwable $e) {
                $connection->error($e);
            }
        }

        if (!empty($connection->websocketPingInterval)) {
            $interval = (float)$connection->websocketPingInterval;
            if ($interval > 0) {
                $connection->context->websocketPingTimer = Timer::repeat($interval, function () use ($connection): void {
                    if ($connection->send(static::clientFrame('', 0x9), true) === false) {
                        if (!empty($connection->context->websocketPingTimer)) {
                            Timer::del((int)$connection->context->websocketPingTimer);
                            $connection->context->websocketPingTimer = null;
                        }
                    }
                });
            }
        }

        if (!empty($connection->context->tmpWebsocketData)) {
            $connection->send($connection->context->tmpWebsocketData, true);
            $connection->context->tmpWebsocketData = '';
        }
        return $length;
    }

    protected static function parseResponse(string $buffer): Response
    {
        [$head] = explode("\r\n\r\n", $buffer, 2);
        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines) ?: 'HTTP/1.1 500 Invalid Response';
        $parts = explode(' ', $statusLine, 3);
        $version = isset($parts[0]) && str_starts_with($parts[0], 'HTTP/') ? substr($parts[0], 5) : '1.1';
        $status = isset($parts[1]) ? (int)$parts[1] : 500;
        $reason = $parts[2] ?? null;
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || !str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }
        return (new Response())->withStatus($status, $reason)->withHeaders($headers)->withProtocolVersion($version);
    }

    /** @return array<string,string> */
    protected static function parseHeaderBlock(string $head): array
    {
        $headers = [];
        $lines = explode("\r\n", trim($head));
        array_shift($lines);
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    protected static function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $key => $_) {
            if (strcasecmp((string)$key, $name) === 0) return true;
        }
        return false;
    }

    protected static function isValidCloseCode(int $code): bool
    {
        if ($code >= 3000 && $code <= 4999) return true;
        return $code >= 1000 && $code < 1015 && !in_array($code, [1004, 1005, 1006], true);
    }
}
