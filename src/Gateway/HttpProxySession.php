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

namespace localzet\Server\Gateway;

use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Proxy\Upstream;
use localzet\Server\Proxy\UpstreamPool;
use RuntimeException;
use Throwable;

/** @internal Одна client-side HTTP/1.x proxy session. */
final class HttpProxySession
{
    private string $clientBuffer = '';
    private string $upstreamBuffer = '';

    private ?AsyncTcpConnection $backend = null;
    private ?Upstream $upstream = null;
    private ?UpstreamPool $pool = null;
    private bool $backendConnected = false;
    private bool $backendReleased = true;

    private ?HttpBodyTracker $requestBody = null;
    private ?HttpBodyTracker $responseBody = null;
    private string $requestMethod = '';
    private string $requestVersion = 'HTTP/1.1';
    private string $requestHead = '';
    private bool $requestHeadSent = false;
    private bool $requestComplete = false;
    private bool $responseStarted = false;
    private bool $waitingResponse = false;
    private bool $clientWantsClose = false;
    private bool $responseWantsClose = false;
    private bool $tunnel = false;

    /** @var array<int, true> */
    private array $connectAttempts = [];

    public function __construct(
        private readonly HttpReverseProxy $gateway,
        private readonly TcpConnection    $client,
        private readonly float            $connectTimeout,
        private readonly int              $maxHeaderSize,
    )
    {
    }

    public function onClientData(string $data): void
    {
        if ($this->tunnel) {
            $this->backend?->send($data, true);
            $this->gateway->increment('bytesClientToUpstream', strlen($data));
            return;
        }

        $this->clientBuffer .= $data;
        try {
            $this->processClientBuffer();
        } catch (Throwable $e) {
            $this->sendClientError(400, 'Bad Request');
        }
    }

    public function close(): void
    {
        $this->releaseBackend();
        if ($this->backend !== null) {
            $backend = $this->backend;
            $this->backend = null;
            $backend->close();
        }
    }

    private function processClientBuffer(): void
    {
        if ($this->waitingResponse && $this->requestComplete) {
            return;
        }

        if ($this->requestBody === null) {
            $headEnd = strpos($this->clientBuffer, "\r\n\r\n");
            if ($headEnd === false) {
                if (strlen($this->clientBuffer) > $this->maxHeaderSize) {
                    throw new RuntimeException('Request headers are too large.');
                }
                return;
            }

            $headLength = $headEnd + 4;
            if ($headLength > $this->maxHeaderSize) {
                throw new RuntimeException('Request headers are too large.');
            }
            $rawHead = substr($this->clientBuffer, 0, $headLength);
            $this->clientBuffer = (string)substr($this->clientBuffer, $headLength);
            $this->beginRequest($rawHead);
        }

        // До TCP connect client reading поставлен на pause. Но часть body могла
        // прийти тем же read(), что и headers, поэтому оставляем её в buffer.
        if (!$this->backendConnected || $this->requestBody === null) {
            return;
        }

        if (!$this->requestBody->isComplete() && $this->clientBuffer !== '') {
            $consumed = $this->requestBody->consume($this->clientBuffer);
            if ($consumed > 0) {
                $chunk = substr($this->clientBuffer, 0, $consumed);
                $this->clientBuffer = (string)substr($this->clientBuffer, $consumed);
                $this->backend?->send($chunk, true);
                $this->gateway->increment('bytesClientToUpstream', strlen($chunk));
            }
        }

        if ($this->requestBody->isComplete()) {
            $this->requestComplete = true;
            // Следующий pipelined request не отправляем до ответа на текущий:
            // это сохраняет строгую HTTP/1.1 response ordering без request queue.
            $this->client->pauseRecv();
        }
    }

    private function beginRequest(string $rawHead): void
    {
        [$startLine, $headers] = $this->parseHead($rawHead);
        if (!preg_match('/^([^\s]+)\s+([^\s]+)\s+(HTTP\/1\.[01])$/', $startLine, $matches)) {
            throw new RuntimeException('Malformed HTTP request line.');
        }

        if (!preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $matches[1])) {
            throw new RuntimeException('Invalid HTTP method token.');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $matches[2])) {
            throw new RuntimeException('Invalid HTTP request target.');
        }
        $this->requestMethod = strtoupper($matches[1]);
        $target = $matches[2];
        $this->requestVersion = $matches[3];
        if (count($headers['host'] ?? []) > 1) {
            throw new RuntimeException('Multiple Host headers are not allowed.');
        }
        $host = $headers['host'][0] ?? '';
        if ($this->requestVersion === 'HTTP/1.1' && $host === '') {
            throw new RuntimeException('HTTP/1.1 request requires Host.');
        }
        if ($host !== '' && !$this->isValidHost($host)) {
            throw new RuntimeException('Invalid Host header.');
        }
        $this->assertSafeConnectionTokens($headers);

        $path = parse_url($target, PHP_URL_PATH) ?: '/';
        $route = $this->gateway->resolveRoute($host, $path);
        if ($route === null) {
            $this->sendClientError(502, 'Bad Gateway');
            return;
        }

        $target = $route->rewriteTarget($target);
        $this->requestBody = HttpBodyTracker::forRequest($headers);
        $this->requestComplete = $this->requestBody->isComplete();
        $this->responseBody = null;
        $this->responseStarted = false;
        $this->waitingResponse = true;
        $this->responseWantsClose = false;
        $this->clientWantsClose = $this->shouldCloseClient($headers, $this->requestVersion);
        $this->requestHead = $this->buildRequestHead($this->requestMethod, $target, $this->requestVersion, $headers);
        $this->requestHeadSent = false;
        $this->connectAttempts = [];
        $this->gateway->increment('requests');

        // Уже открытый keep-alive upstream можно использовать повторно только
        // для того же pool. Иначе route isolation была бы нарушена.
        if ($this->backendConnected && $this->pool === $route->pool && $this->backend !== null) {
            $this->sendRequestHeadAndResume();
            return;
        }

        $this->disconnectBackend();
        $this->pool = $route->pool;
        $this->connectBackend();
    }

    private function connectBackend(): void
    {
        if ($this->pool === null) {
            $this->sendBadGateway();
            return;
        }

        try {
            $upstream = $this->pool->select($this->connectAttempts);
        } catch (Throwable) {
            $this->sendBadGateway();
            return;
        }

        $this->connectAttempts[spl_object_id($upstream)] = true;
        $this->client->pauseRecv();
        $this->upstream = $upstream;
        $this->backendReleased = false;
        $upstream->activeConnections++;
        $this->gateway->increment('upstreamConnects');

        $backend = new AsyncTcpConnection($upstream->address, [], $this->client->getEventLoop());
        $backend->setConnectTimeout($this->connectTimeout);
        $this->backend = $backend;
        $this->backendConnected = false;

        $backend->onConnect = function (AsyncTcpConnection $connection): void {
            if ($this->upstream !== null && $this->pool !== null) {
                $this->pool->markSuccess($this->upstream);
            }
            $this->backendConnected = true;
            $this->sendRequestHeadAndResume();
        };
        $backend->onMessage = function (AsyncTcpConnection $connection, string $data): void {
            try {
                $this->onUpstreamData($data);
            } catch (Throwable) {
                $this->sendBadGateway();
            }
        };
        $backend->onBufferFull = fn() => $this->client->pauseRecv();
        $backend->onBufferDrain = function (): void {
            if (!$this->requestComplete) {
                $this->client->resumeRecv();
            }
        };
        $backend->onError = function (AsyncTcpConnection $connection): void {
            // Async connect failure вызывает и onError, и последующий onClose.
            // После failover callbacks старой попытки не должны трогать новую.
            if ($connection !== $this->backend) {
                return;
            }
            $this->gateway->increment('upstreamFailures');
            if ($this->upstream !== null && $this->pool !== null) {
                $this->pool->markFailure($this->upstream);
            }

            if (!$this->backendConnected && !$this->requestHeadSent) {
                $this->gateway->increment('retries');
                $this->releaseBackend();
                $this->backend = null;
                $this->connectBackend();
                return;
            }

            $this->sendBadGateway();
        };
        $backend->onClose = function (AsyncTcpConnection $connection): void {
            if ($connection !== $this->backend) {
                return;
            }
            $wasConnected = $this->backendConnected;
            $this->backendConnected = false;
            $this->releaseBackend();

            if ($this->responseBody?->isUntilClose() === true && $this->responseStarted) {
                $this->responseBody->markConnectionClosed();
                $this->finishResponse(true);
                return;
            }

            // Idle keep-alive upstream мог закрыться между запросами — это не
            // ошибка клиентского соединения, следующий request подключится заново.
            if (!$this->waitingResponse && $wasConnected) {
                $this->backend = null;
                return;
            }

            if ($this->waitingResponse && !$this->tunnel) {
                $this->sendBadGateway();
            } elseif ($this->tunnel) {
                $this->client->close();
            }
        };
        $backend->connect();
    }

    private function sendRequestHeadAndResume(): void
    {
        if ($this->backend === null || !$this->backendConnected || $this->requestHeadSent) {
            return;
        }
        $this->backend->send($this->requestHead, true);
        $this->gateway->increment('bytesClientToUpstream', strlen($this->requestHead));
        $this->requestHeadSent = true;
        $this->client->resumeRecv();
        $this->processClientBuffer();
    }

    private function onUpstreamData(string $data): void
    {
        if ($this->tunnel) {
            $this->client->send($data, true);
            $this->gateway->increment('bytesUpstreamToClient', strlen($data));
            return;
        }

        $this->upstreamBuffer .= $data;

        while ($this->upstreamBuffer !== '') {
            if ($this->responseBody === null) {
                $headEnd = strpos($this->upstreamBuffer, "\r\n\r\n");
                if ($headEnd === false) {
                    if (strlen($this->upstreamBuffer) > $this->maxHeaderSize) {
                        $this->sendBadGateway();
                    }
                    return;
                }

                $headLength = $headEnd + 4;
                if ($headLength > $this->maxHeaderSize) {
                    $this->sendBadGateway();
                    return;
                }
                $rawHead = substr($this->upstreamBuffer, 0, $headLength);
                $this->upstreamBuffer = (string)substr($this->upstreamBuffer, $headLength);
                [$statusLine, $headers] = $this->parseHead($rawHead);
                if (!preg_match('/^HTTP\/1\.[01]\s+(\d{3})(?:\s+.*)?$/', $statusLine, $matches)) {
                    $this->sendBadGateway();
                    return;
                }
                $status = (int)$matches[1];

                // Interim response (например 100 Continue) передаём как есть и
                // продолжаем ждать final response head.
                if ($status >= 100 && $status < 200 && $status !== 101) {
                    $head = $this->buildResponseHead($statusLine, $headers, false, false);
                    $this->client->send($head, true);
                    $this->gateway->increment('bytesUpstreamToClient', strlen($head));
                    continue;
                }

                $this->responseStarted = true;
                $this->responseWantsClose = $this->shouldCloseUpstreamResponse($headers);
                $upgrade = $status === 101 && $this->isUpgrade($headers);
                $this->responseBody = HttpBodyTracker::forResponse($headers, $status, $this->requestMethod);
                $untilClose = $this->responseBody->isUntilClose();
                $responseHead = $this->buildResponseHead($statusLine, $headers, $upgrade, $untilClose || $this->clientWantsClose);
                $this->client->send($responseHead, true);
                $this->gateway->increment('bytesUpstreamToClient', strlen($responseHead));

                if ($upgrade) {
                    $this->enterTunnel();
                    return;
                }

                if ($this->responseBody->isComplete()) {
                    $this->finishResponse(false);
                    continue;
                }
            }

            if ($this->responseBody === null || $this->upstreamBuffer === '') {
                return;
            }
            $consumed = $this->responseBody->consume($this->upstreamBuffer);
            if ($consumed <= 0) {
                return;
            }
            $chunk = substr($this->upstreamBuffer, 0, $consumed);
            $this->upstreamBuffer = (string)substr($this->upstreamBuffer, $consumed);
            $this->client->send($chunk, true);
            $this->gateway->increment('bytesUpstreamToClient', strlen($chunk));

            if ($this->responseBody->isComplete()) {
                $this->finishResponse(false);
            }
        }
    }

    private function finishResponse(bool $backendClosed): void
    {
        $closeClient = $this->clientWantsClose || $this->responseWantsClose || $this->responseBody?->isUntilClose() === true;

        $this->requestBody = null;
        $this->responseBody = null;
        $this->requestMethod = '';
        $this->requestHead = '';
        $this->requestHeadSent = false;
        $this->requestComplete = false;
        $this->responseStarted = false;
        $this->waitingResponse = false;
        $this->connectAttempts = [];

        if ($backendClosed || $this->responseWantsClose) {
            $this->disconnectBackend();
        }

        if ($closeClient) {
            $this->client->end();
            return;
        }

        $this->client->resumeRecv();
        if ($this->clientBuffer !== '') {
            $this->processClientBuffer();
        }
    }

    private function enterTunnel(): void
    {
        if ($this->backend === null) {
            $this->sendBadGateway();
            return;
        }

        $this->tunnel = true;
        $this->waitingResponse = false;
        $this->gateway->increment('websocketTunnels');

        if ($this->upstreamBuffer !== '') {
            $this->client->send($this->upstreamBuffer, true);
            $this->gateway->increment('bytesUpstreamToClient', strlen($this->upstreamBuffer));
            $this->upstreamBuffer = '';
        }
        if ($this->clientBuffer !== '') {
            $this->backend->send($this->clientBuffer, true);
            $this->gateway->increment('bytesClientToUpstream', strlen($this->clientBuffer));
            $this->clientBuffer = '';
        }

        $this->client->pipe($this->backend);
        $this->backend->pipe($this->client);
        $this->client->resumeRecv();
    }

    private function sendBadGateway(): void
    {
        $this->gateway->increment('badGateway');
        $this->sendClientError(502, 'Bad Gateway');
    }

    private function sendClientError(int $status, string $reason): void
    {
        if ($this->client->getStatus() === TcpConnection::STATUS_CLOSED) {
            return;
        }
        $body = $status . ' ' . $reason . "\n";
        $response = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        $this->client->end($response, true);
    }

    private function disconnectBackend(): void
    {
        if ($this->backend !== null) {
            $backend = $this->backend;
            $this->backend = null;
            $this->backendConnected = false;
            $this->releaseBackend();
            $backend->close();
        }
        $this->upstream = null;
    }

    private function releaseBackend(): void
    {
        if ($this->backendReleased || $this->upstream === null) {
            return;
        }
        $this->backendReleased = true;
        $this->upstream->activeConnections = max(0, $this->upstream->activeConnections - 1);
    }

    /** @return array{0:string,1:array<string,list<string>>} */
    private function parseHead(string $rawHead): array
    {
        $lines = explode("\r\n", substr($rawHead, 0, -4));
        $startLine = array_shift($lines) ?? '';
        $headers = [];
        if (count($lines) > 100) {
            throw new RuntimeException('Too many HTTP headers.');
        }
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
                throw new RuntimeException('Invalid HTTP header line.');
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new RuntimeException('Invalid HTTP header line.');
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if ($name === '' || preg_match('/[^!#$%&\'*+\-.^_`|~0-9A-Za-z]/', $name)) {
                throw new RuntimeException('Invalid HTTP header name.');
            }
            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)) {
                throw new RuntimeException('Invalid control character in HTTP header value.');
            }
            $headers[$name][] = $value;
        }
        return [$startLine, $headers];
    }

    /** @param array<string,list<string>> $headers */
    private function buildRequestHead(string $method, string $target, string $version, array $headers): string
    {
        $isUpgrade = $this->isUpgrade($headers);
        $headers = $this->stripHopByHop($headers, $isUpgrade);
        $remoteIp = $this->client->getRemoteIp();
        $originalHost = $headers['host'][0] ?? '';

        $headers['x-forwarded-for'][] = $remoteIp;
        $headers['x-forwarded-proto'] = [$this->client->transport === 'ssl' ? 'https' : 'http'];
        if ($originalHost !== '') {
            $headers['x-forwarded-host'] = [$originalHost];
        }
        $forwarded = 'for=' . $this->formatForwardedNode($remoteIp)
            . ';proto=' . ($this->client->transport === 'ssl' ? 'https' : 'http');
        if ($originalHost !== '') {
            $forwarded .= ';host="' . addcslashes($originalHost, "\\\"") . '"';
        }
        $headers['forwarded'][] = $forwarded;
        $headers['connection'] = [$isUpgrade ? 'Upgrade' : 'keep-alive'];

        return $method . ' ' . $target . ' ' . $version . "\r\n" . $this->renderHeaders($headers) . "\r\n";
    }

    /** @param array<string,list<string>> $headers */
    private function buildResponseHead(string $statusLine, array $headers, bool $upgrade, bool $close): string
    {
        $headers = $this->stripHopByHop($headers, $upgrade);
        if ($upgrade) {
            $headers['connection'] = ['Upgrade'];
        } elseif ($close) {
            $headers['connection'] = ['close'];
        } else {
            $headers['connection'] = ['keep-alive'];
        }
        return $statusLine . "\r\n" . $this->renderHeaders($headers) . "\r\n";
    }

    /** @param array<string,list<string>> $headers */
    private function stripHopByHop(array $headers, bool $preserveUpgrade): array
    {
        $connectionTokens = [];
        foreach ($headers['connection'] ?? [] as $value) {
            foreach (explode(',', $value) as $token) {
                $connectionTokens[] = strtolower(trim($token));
            }
        }
        foreach (['connection', 'proxy-connection', 'keep-alive', 'te'] as $name) {
            unset($headers[$name]);
        }
        foreach ($connectionTokens as $name) {
            if ($name !== '' && (!$preserveUpgrade || $name !== 'upgrade')) {
                unset($headers[$name]);
            }
        }
        if (!$preserveUpgrade) {
            unset($headers['upgrade']);
        }
        return $headers;
    }

    /** @param array<string,list<string>> $headers */
    private function renderHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $values) {
            $display = implode('-', array_map(static fn(string $part): string => ucfirst($part), explode('-', $name)));
            foreach ($values as $value) {
                $lines[] = $display . ': ' . $value;
            }
        }
        return implode("\r\n", $lines) . ($lines === [] ? '' : "\r\n");
    }

    /** @param array<string,list<string>> $headers */
    private function isUpgrade(array $headers): bool
    {
        $connection = strtolower(implode(',', $headers['connection'] ?? []));
        return isset($headers['upgrade']) && str_contains($connection, 'upgrade');
    }

    /** @param array<string,list<string>> $headers */
    private function shouldCloseClient(array $headers, string $version): bool
    {
        $connection = strtolower(implode(',', $headers['connection'] ?? []));
        if (str_contains($connection, 'close')) {
            return true;
        }
        return $version === 'HTTP/1.0' && !str_contains($connection, 'keep-alive');
    }

    /** @param array<string,list<string>> $headers */
    private function shouldCloseUpstreamResponse(array $headers): bool
    {
        return str_contains(strtolower(implode(',', $headers['connection'] ?? [])), 'close');
    }

    /** @param array<string,list<string>> $headers */
    private function assertSafeConnectionTokens(array $headers): void
    {
        foreach ($headers['connection'] ?? [] as $value) {
            foreach (explode(',', strtolower($value)) as $token) {
                $token = trim($token);
                if (in_array($token, ['host', 'content-length', 'transfer-encoding'], true)) {
                    throw new RuntimeException('Connection header may not nominate HTTP framing headers.');
                }
            }
        }
    }

    private function isValidHost(string $host): bool
    {
        if (str_contains($host, '@') || str_contains($host, '/') || str_contains($host, '\\') || preg_match('/\s/', $host) === 1) {
            return false;
        }
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            if ($end === false || filter_var(substr($host, 1, $end - 1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return false;
            }
            $tail = substr($host, $end + 1);
            if ($tail === '') {
                return true;
            }
            if (!preg_match('/^:(\d{1,5})$/', $tail, $match)) {
                return false;
            }
            $port = (int)$match[1];
            return $port >= 1 && $port <= 65535;
        }

        $colon = strrpos($host, ':');
        if ($colon !== false) {
            $portPart = substr($host, $colon + 1);
            if ($portPart === '' || !ctype_digit($portPart)) {
                return false;
            }
            $port = (int)$portPart;
            if ($port < 1 || $port > 65535) {
                return false;
            }
            $host = substr($host, 0, $colon);
        }
        return $host !== '' && strlen($host) <= 253 && preg_match('/^[A-Za-z0-9._-]+$/', $host) === 1;
    }

    private function formatForwardedNode(string $ip): string
    {
        return str_contains($ip, ':') ? '"[' . $ip . ']"' : $ip;
    }
}
