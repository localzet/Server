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

use RuntimeException;

/**
 * Incremental tracker HTTP message body.
 *
 * Класс не декодирует payload и не меняет wire-format. Его задача только
 * определить границу сообщения при Content-Length/chunked framing, чтобы
 * reverse proxy мог стримить байты дальше без полной буферизации body.
 */
final class HttpBodyTracker
{
    private const NONE = 'none';
    private const LENGTH = 'length';
    private const CHUNKED = 'chunked';
    private const UNTIL_CLOSE = 'close';

    private string $mode = self::NONE;
    private int $remaining = 0;
    private string $chunkBuffer = '';
    private ?int $chunkRemaining = null;
    private bool $readingTrailers = false;
    private bool $complete = false;

    public static function forRequest(array $headers): self
    {
        return self::fromHeaders($headers, false);
    }

    public static function forResponse(array $headers, int $status, string $requestMethod): self
    {
        if ($requestMethod === 'HEAD' || ($status >= 100 && $status < 200) || in_array($status, [204, 304], true)) {
            return new self();
        }
        return self::fromHeaders($headers, true);
    }

    private static function fromHeaders(array $headers, bool $untilCloseFallback): self
    {
        $tracker = new self();

        // На proxy boundary framing должен быть однозначным. Иначе разные
        // hop-ы могут по-разному трактовать сообщение и открыть request-smuggling.
        $contentLengths = [];
        foreach ($headers['content-length'] ?? [] as $value) {
            foreach (explode(',', $value) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '' || !preg_match('/^\d+$/', $candidate)) {
                    throw new RuntimeException('Invalid Content-Length in proxied message.');
                }
                $contentLengths[] = $candidate;
            }
        }
        if ($contentLengths !== [] && count(array_unique($contentLengths)) !== 1) {
            throw new RuntimeException('Conflicting Content-Length headers in proxied message.');
        }

        $transferTokens = [];
        foreach ($headers['transfer-encoding'] ?? [] as $value) {
            foreach (explode(',', strtolower($value)) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $transferTokens[] = $token;
                }
            }
        }
        if ($transferTokens !== [] && $contentLengths !== []) {
            throw new RuntimeException('Transfer-Encoding with Content-Length is not allowed.');
        }
        if ($transferTokens !== []) {
            // 7.0 принимает только wire framing, которое умеет однозначно
            // отслеживать. Неизвестные transfer codings лучше отклонить, чем
            // проксировать с потенциально отличающейся семантикой upstream-а.
            if ($transferTokens !== ['chunked']) {
                throw new RuntimeException('Unsupported Transfer-Encoding in proxied message.');
            }
            $tracker->mode = self::CHUNKED;
            return $tracker;
        }

        if ($contentLengths !== []) {
            $tracker->mode = self::LENGTH;
            $tracker->remaining = (int)$contentLengths[0];
            $tracker->complete = $tracker->remaining === 0;
            return $tracker;
        }

        if ($untilCloseFallback) {
            $tracker->mode = self::UNTIL_CLOSE;
            return $tracker;
        }

        $tracker->complete = true;
        return $tracker;
    }

    /**
     * Учитывает очередную порцию wire bytes и возвращает число байт,
     * принадлежащих текущему body. Остаток chunk-а может уже быть началом
     * следующего HTTP message и должен остаться в session buffer.
     */
    public function consume(string $data): int
    {
        if ($data === '' || $this->complete) {
            return 0;
        }

        if ($this->mode === self::UNTIL_CLOSE) {
            return strlen($data);
        }

        if ($this->mode === self::LENGTH) {
            $consumed = min(strlen($data), $this->remaining);
            $this->remaining -= $consumed;
            $this->complete = $this->remaining === 0;
            return $consumed;
        }

        return $this->consumeChunked($data);
    }

    public function markConnectionClosed(): void
    {
        if ($this->mode === self::UNTIL_CLOSE) {
            $this->complete = true;
        }
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function isUntilClose(): bool
    {
        return $this->mode === self::UNTIL_CLOSE;
    }

    private function consumeChunked(string $data): int
    {
        $before = strlen($this->chunkBuffer);
        $this->chunkBuffer .= $data;
        $offset = 0;

        while (!$this->complete) {
            if ($this->readingTrailers) {
                // Пустые trailers заканчиваются одиночным CRLF; непустые — CRLFCRLF.
                if (str_starts_with(substr($this->chunkBuffer, $offset), "\r\n")) {
                    $offset += 2;
                    $this->complete = true;
                    break;
                }
                $end = strpos($this->chunkBuffer, "\r\n\r\n", $offset);
                if ($end === false) {
                    break;
                }
                $offset = $end + 4;
                $this->complete = true;
                break;
            }

            if ($this->chunkRemaining === null) {
                $lineEnd = strpos($this->chunkBuffer, "\r\n", $offset);
                if ($lineEnd === false) {
                    break;
                }
                $line = substr($this->chunkBuffer, $offset, $lineEnd - $offset);
                $sizeToken = trim(explode(';', $line, 2)[0]);
                if ($sizeToken === '' || !ctype_xdigit($sizeToken)) {
                    throw new RuntimeException('Invalid chunk size in proxied HTTP message.');
                }
                $this->chunkRemaining = hexdec($sizeToken);
                $offset = $lineEnd + 2;
                if ($this->chunkRemaining === 0) {
                    $this->readingTrailers = true;
                    $this->chunkRemaining = null;
                }
                continue;
            }

            if (strlen($this->chunkBuffer) - $offset < $this->chunkRemaining + 2) {
                break;
            }
            $offset += $this->chunkRemaining;
            if (substr($this->chunkBuffer, $offset, 2) !== "\r\n") {
                throw new RuntimeException('Invalid chunk terminator in proxied HTTP message.');
            }
            $offset += 2;
            $this->chunkRemaining = null;
        }

        if ($offset > 0) {
            $this->chunkBuffer = (string)substr($this->chunkBuffer, $offset);
        }

        // Пока terminal zero-chunk не найден, вся новая порция однозначно
        // принадлежит текущему body и может немедленно стримиться upstream.
        if (!$this->complete) {
            return strlen($data);
        }

        // После terminal chunk возвращаем только часть новой порции до границы
        // message. Остаток уже может быть началом следующего HTTP сообщения.
        $consumed = max(0, min(strlen($data), $offset - $before));
        $this->chunkBuffer = '';
        return $consumed;
    }
}
