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

use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;
use RuntimeException;

/**
 * RFC 6455 WebSocket server protocol.
 *
 * Клиентские frames обязаны быть masked. Control frames проверяются отдельно:
 * FIN=1, payload <=125, корректные close codes и автоматический pong по умолчанию.
 */
class Websocket implements ProtocolInterface
{
    // Исторически Localzet хранит здесь первый байт WebSocket frame.
    public const BINARY_TYPE_BLOB = "\x81";
    public const BINARY_TYPE_ARRAYBUFFER = "\x82";

    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        if (!$connection instanceof TcpConnection) {
            throw new \InvalidArgumentException('WebSocket requires TcpConnection.');
        }

        if (!($connection->context->websocketHandshake ?? false)) {
            return static::handshake($buffer, $connection);
        }

        if (strlen($buffer) < 2) {
            return 0;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $fin = ($first & 0x80) !== 0;
        $rsv = $first & 0x70;
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $payloadCode = $second & 0x7f;

        if ($rsv !== 0 || !$masked || !in_array($opcode, [0x0, 0x1, 0x2, 0x8, 0x9, 0xA], true)) {
            static::protocolClose($connection, 1002, 'Protocol error');
            return 0;
        }

        $offset = 2;
        if ($payloadCode === 126) {
            if (strlen($buffer) < 4) return 0;
            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
            if ($payloadLength < 126) {
                static::protocolClose($connection, 1002, 'Non-minimal length');
                return 0;
            }
            $offset = 4;
        } elseif ($payloadCode === 127) {
            if (strlen($buffer) < 10) return 0;
            $parts = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            if (($parts['high'] & 0x80000000) !== 0) {
                static::protocolClose($connection, 1002, 'Invalid 64-bit length');
                return 0;
            }
            $payloadLength = $parts['high'] * 4294967296 + $parts['low'];
            if ($payloadLength < 65536 || $payloadLength > PHP_INT_MAX) {
                static::protocolClose($connection, 1002, 'Invalid payload length');
                return 0;
            }
            $payloadLength = (int)$payloadLength;
            $offset = 10;
        } else {
            $payloadLength = $payloadCode;
        }

        $isControl = $opcode >= 0x8;
        if ($isControl && (!$fin || $payloadLength > 125)) {
            static::protocolClose($connection, 1002, 'Invalid control frame');
            return 0;
        }
        if ($payloadLength > $connection->maxPackageSize) {
            static::protocolClose($connection, 1009, 'Message too big');
            return 0;
        }

        $total = $offset + 4 + $payloadLength;
        return strlen($buffer) >= $total ? $total : 0;
    }

    public static function decode(string $buffer, ConnectionInterface $connection): mixed
    {
        // Handshake header является самостоятельным transport frame и не должен
        // попадать в onMessage как WebSocket payload.
        if (str_starts_with($buffer, 'GET ')) {
            return null;
        }
        if (!$connection instanceof TcpConnection) {
            throw new \InvalidArgumentException('WebSocket requires TcpConnection.');
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $fin = ($first & 0x80) !== 0;
        $opcode = $first & 0x0f;
        $lengthCode = $second & 0x7f;
        $offset = 2;

        if ($lengthCode === 126) {
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($lengthCode === 127) {
            $parts = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            $length = (int)($parts['high'] * 4294967296 + $parts['low']);
            $offset = 10;
        } else {
            $length = $lengthCode;
        }

        $mask = substr($buffer, $offset, 4);
        $payload = substr($buffer, $offset + 4, $length);
        $payload = static::mask($payload, $mask);

        if ($opcode === 0x8) {
            if (strlen($payload) === 1) {
                static::protocolClose($connection, 1002, 'Invalid close payload');
                return null;
            }
            $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1000;
            $reason = strlen($payload) > 2 ? substr($payload, 2) : '';
            if (!static::isValidCloseCode($code) || ($reason !== '' && !static::isValidUtf8($reason))) {
                static::protocolClose($connection, 1002, 'Invalid close frame');
                return null;
            }
            if ($connection->onWebSocketClose !== null) {
                ($connection->onWebSocketClose)($connection, $code, $reason);
            }

            $alreadySent = (bool)($connection->context->wsCloseSent ?? false);
            static::cancelGracefulCloseTimer($connection);
            if (!$alreadySent) {
                $connection->context->wsCloseSent = true;
                $connection->send(static::frame(pack('n', $code) . $reason, 0x8), true);
            }
            // После обмена Close frames завершаем TCP только после flush.
            $connection->end();
            return null;
        }

        if ($opcode === 0x9) {
            if ($connection->onWebSocketPing !== null) {
                ($connection->onWebSocketPing)($connection, $payload);
            } else {
                $connection->send(static::frame($payload, 0xA), true);
            }
            return null;
        }

        if ($opcode === 0xA) {
            if ($connection->onWebSocketPong !== null) {
                ($connection->onWebSocketPong)($connection, $payload);
            }
            return null;
        }

        // Fragmentation: накапливаем payload до FIN и возвращаем приложению только complete message.
        if ($opcode === 0x1 || $opcode === 0x2) {
            if (($connection->context->wsFragmentOpcode ?? null) !== null) {
                static::protocolClose($connection, 1002, 'Unexpected new data frame during fragmentation');
                return null;
            }
            if (!$fin) {
                $connection->context->wsFragmentOpcode = $opcode;
                $connection->context->wsFragmentBuffer = $payload;
                return null;
            }
            if ($opcode === 0x1 && !static::isValidUtf8($payload)) {
                static::protocolClose($connection, 1007, 'Invalid UTF-8');
                return null;
            }
            return $payload;
        }

        if ($opcode === 0x0) {
            $fragmentOpcode = $connection->context->wsFragmentOpcode ?? null;
            if ($fragmentOpcode === null) {
                static::protocolClose($connection, 1002, 'Unexpected continuation frame');
                return null;
            }
            $connection->context->wsFragmentBuffer .= $payload;
            if (strlen($connection->context->wsFragmentBuffer) > $connection->maxPackageSize) {
                static::protocolClose($connection, 1009, 'Message too big');
                return null;
            }
            if (!$fin) {
                return null;
            }
            $message = $connection->context->wsFragmentBuffer;
            unset($connection->context->wsFragmentBuffer, $connection->context->wsFragmentOpcode);
            if ($fragmentOpcode === 0x1 && !static::isValidUtf8($message)) {
                static::protocolClose($connection, 1007, 'Invalid UTF-8');
                return null;
            }
            return $message;
        }

        return null;
    }

    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        if (!$connection instanceof TcpConnection) {
            throw new \InvalidArgumentException('WebSocket requires TcpConnection.');
        }
        if (!is_scalar($data) && !$data instanceof \Stringable) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Unable to JSON-encode WebSocket payload.');
            }
            $data = $encoded;
        }

        $type = $connection->context->websocketType ?? ($connection->websocketType ?? static::BINARY_TYPE_BLOB);
        if (is_string($type) && strlen($type) === 1) {
            $firstByte = ord($type);
            $opcode = $firstByte & 0x0f;
        } else {
            $opcode = in_array($type, ['binary', 'arraybuffer'], true) ? 0x2 : 0x1;
        }
        $frame = static::frame((string)$data, $opcode);

        // Пользователь может вызвать send() из onConnect до завершения HTTP Upgrade.
        // Frame нельзя выпускать в сеть раньше 101, поэтому временно держим его отдельно.
        if (!($connection->context->websocketHandshake ?? false)) {
            $pending = (string)($connection->context->tmpWebsocketData ?? '');
            if (strlen($pending) + strlen($frame) > $connection->maxSendBufferSize) {
                if ($connection->onError !== null) {
                    ($connection->onError)($connection, ConnectionInterface::SEND_FAIL, 'WebSocket pre-handshake buffer is full.');
                }
                return '';
            }
            $connection->context->tmpWebsocketData = $pending . $frame;
            if (strlen($connection->context->tmpWebsocketData) >= $connection->maxSendBufferSize
                && $connection->onBufferFull !== null) {
                ($connection->onBufferFull)($connection);
            }
            return '';
        }
        return $frame;
    }

    protected static function handshake(string $buffer, TcpConnection $connection): int
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) >= Http::maxHeaderLength()) {
                $connection->close(
                    "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
                    true
                );
            }
            return 0;
        }

        if ($end >= Http::maxHeaderLength()) {
            $connection->close(
                "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
                true
            );
            return 0;
        }

        $head = substr($buffer, 0, $end + 4);
        $rawHead = substr($buffer, 0, $end);
        $firstLineEnd = strpos($rawHead, "\r\n");
        if ($firstLineEnd === false) {
            static::rejectHandshake($connection, 400, 'Bad Request');
            return 0;
        }

        // RFC 6455 opening handshake — HTTP/1.1 Upgrade request.
        $requestLine = substr($rawHead, 0, $firstLineEnd);
        if (!preg_match('~^GET ([^\x00-\x20\x7f]+) HTTP/1\.1$~D', $requestLine)) {
            static::rejectHandshake($connection, 400, 'Bad Request');
            return 0;
        }

        $headerLines = substr($rawHead, $firstLineEnd + 2);
        $lines = $headerLines === '' ? [] : explode("\r\n", $headerLines);
        if (count($lines) > Http::maxHeaderCount()) {
            static::rejectHandshake($connection, 431, 'Request Header Fields Too Large');
            return 0;
        }

        /** @var array<string,list<string>> $headers */
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
                static::rejectHandshake($connection, 400, 'Bad Request');
                return 0;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2
                || !preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $parts[0])) {
                static::rejectHandshake($connection, 400, 'Bad Request');
                return 0;
            }

            $value = trim($parts[1], " \t");
            if (str_contains($value, "\0")) {
                static::rejectHandshake($connection, 400, 'Bad Request');
                return 0;
            }
            $headers[strtolower($parts[0])][] = $value;
        }

        $host = $headers['host'] ?? [];
        $upgrade = $headers['upgrade'] ?? [];
        $connectionHeaders = $headers['connection'] ?? [];
        $keys = $headers['sec-websocket-key'] ?? [];
        $versions = $headers['sec-websocket-version'] ?? [];

        // Security-sensitive opening-handshake fields are deliberately singular.
        // Duplicate Host/Key/Version/Upgrade often indicate parser ambiguity.
        if (count($host) !== 1
            || count($upgrade) !== 1
            || count($keys) !== 1
            || count($versions) !== 1
            || isset($headers['transfer-encoding'])
            || isset($headers['content-length'])) {
            static::rejectHandshake($connection, 400, 'Bad Request');
            return 0;
        }

        if (!static::validHost($host[0])) {
            static::rejectHandshake($connection, 400, 'Bad Request');
            return 0;
        }

        $connectionTokens = [];
        foreach ($connectionHeaders as $value) {
            foreach (explode(',', strtolower($value)) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $connectionTokens[] = $token;
                }
            }
        }

        $key = trim($keys[0]);
        $decodedKey = base64_decode($key, true);
        if (strtolower(trim($upgrade[0])) !== 'websocket'
            || !in_array('upgrade', $connectionTokens, true)
            || trim($versions[0]) !== '13'
            || $decodedKey === false
            || strlen($decodedKey) !== 16) {
            static::rejectHandshake(
                $connection,
                400,
                'Bad Request',
                "Sec-WebSocket-Version: 13\r\n"
            );
            return 0;
        }

        $request = new Request($head);
        $request->connection = $connection;
        if ($connection->onWebSocketConnect !== null) {
            $decision = ($connection->onWebSocketConnect)($connection, $request);
            if ($decision instanceof Response) {
                $connection->close((string)$decision, true);
                return 0;
            }
            if ($connection->getStatus() >= TcpConnection::STATUS_CLOSING) {
                return 0;
            }
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $extra = '';
        foreach ($connection->headers as $name => $value) {
            foreach ((array)$value as $item) {
                $extra .= str_replace(["\r", "\n"], '', (string)$name) . ': '
                    . str_replace(["\r", "\n"], '', (string)$item) . "\r\n";
            }
        }
        $connection->headers = [];
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n{$extra}\r\n";
        $connection->send($response, true);
        $connection->context->websocketHandshake = true;

        if (!empty($connection->context->tmpWebsocketData)) {
            $connection->send($connection->context->tmpWebsocketData, true);
            unset($connection->context->tmpWebsocketData);
        }

        if ($connection->onWebSocketConnected !== null) {
            ($connection->onWebSocketConnected)($connection, $request);
        }

        return $end + 4;
    }

    protected static function rejectHandshake(
        TcpConnection $connection,
        int           $status,
        string        $reason,
        string        $extraHeaders = ''
    ): void
    {
        $connection->close(
            "HTTP/1.1 {$status} {$reason}\r\n"
            . "Connection: close\r\n"
            . $extraHeaders
            . "Content-Length: 0\r\n\r\n",
            true
        );
    }

    protected static function validHost(string $host): bool
    {
        if ($host === '' || preg_match('/\s/', $host) || str_contains($host, '/')
            || str_contains($host, '@') || str_contains($host, '\\')) {
            return false;
        }

        // WebSocket handshake использует те же Host semantics, что и HTTP.
        // Не ограничиваемся regexp: IPv6 literal валидируем как IPv6, а port
        // проверяем по реальному диапазону TCP/UDP, иначе :99999 проходил бы.
        if ($host[0] === '[') {
            if (!preg_match('/^\[([^]]+)](?::([0-9]{1,5}))?$/D', $host, $match)) {
                return false;
            }
            if (filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return false;
            }
            return !isset($match[2]) || static::validPort($match[2]);
        }

        if (!preg_match('/^([A-Za-z0-9._-]+)(?::([0-9]{1,5}))?$/D', $host, $match)) {
            return false;
        }
        return !isset($match[2]) || static::validPort($match[2]);
    }

    protected static function validPort(string $port): bool
    {
        return $port !== '' && ctype_digit($port) && (int)$port <= 65535;
    }

    /**
     * RFC 6455 graceful server shutdown.
     *
     * 1001 (Going Away) сообщает клиенту, что endpoint штатно уходит, а не
     * обрывается сеть. После отправки Close даём peer короткое окно ответить.
     */
    public static function gracefulClose(
        TcpConnection $connection,
        int           $code = 1001,
        string        $reason = 'Server shutting down',
        float         $timeout = 1.0,
    ): void
    {
        if (($connection->context->websocketHandshake ?? false) !== true) {
            $connection->end();
            return;
        }
        if (($connection->context->wsCloseSent ?? false) === true) {
            return;
        }
        if (!static::isValidCloseCode($code)) {
            throw new \InvalidArgumentException('Invalid WebSocket close code.');
        }
        if (!static::isValidUtf8($reason) || strlen($reason) > 123) {
            throw new \InvalidArgumentException('WebSocket close reason must be valid UTF-8 and <= 123 bytes.');
        }

        $connection->context->wsCloseSent = true;
        $connection->send(static::frame(pack('n', $code) . $reason, 0x8), true);

        $delay = max(0.0, $timeout);
        if ($delay <= 0 || $connection->eventLoop === null) {
            $connection->end();
            return;
        }

        $connection->context->wsCloseTimer = $connection->eventLoop->delay($delay, static function () use ($connection): void {
            unset($connection->context->wsCloseTimer);
            if ($connection->getStatus() !== TcpConnection::STATUS_CLOSED) {
                $connection->end();
            }
        });
    }

    /** @internal transport cleanup hook called by TcpConnection::destroy(). */
    public static function onClose(TcpConnection $connection): void
    {
        static::cancelGracefulCloseTimer($connection);
        unset(
            $connection->context->wsCloseSent,
            $connection->context->wsFragmentBuffer,
            $connection->context->wsFragmentOpcode
        );
    }

    protected static function cancelGracefulCloseTimer(TcpConnection $connection): void
    {
        $timerId = (int)($connection->context->wsCloseTimer ?? 0);
        if ($timerId > 0 && $connection->eventLoop !== null) {
            $connection->eventLoop->offDelay($timerId);
        }
        unset($connection->context->wsCloseTimer);
    }

    public static function frame(string $payload, int $opcode = 0x1, bool $fin = true): string
    {
        $first = ($fin ? 0x80 : 0x00) | ($opcode & 0x0f);
        $length = strlen($payload);
        if ($length < 126) {
            return chr($first) . chr($length) . $payload;
        }
        if ($length <= 0xffff) {
            return chr($first) . chr(126) . pack('n', $length) . $payload;
        }
        $high = intdiv($length, 4294967296);
        $low = $length % 4294967296;
        return chr($first) . chr(127) . pack('NN', $high, $low) . $payload;
    }

    protected static function mask(string $payload, string $mask): string
    {
        $length = strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            $payload[$i] = $payload[$i] ^ $mask[$i & 3];
        }
        return $payload;
    }

    protected static function protocolClose(TcpConnection $connection, int $code, string $reason = ''): void
    {
        $connection->close(static::frame(pack('n', $code) . $reason, 0x8), true);
    }

    protected static function isValidCloseCode(int $code): bool
    {
        if ($code >= 3000 && $code <= 4999) {
            return true;
        }
        if ($code < 1000 || $code >= 1015) {
            return false;
        }
        return !in_array($code, [1004, 1005, 1006], true);
    }

    protected static function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
