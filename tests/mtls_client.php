<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
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

$mode = $argv[1] ?? '';
$port = (int)($argv[2] ?? 0);
$caFile = $argv[3] ?? '';
$certFile = $argv[4] ?? '';
$keyFile = $argv[5] ?? '';
$expectedSuccess = in_array($mode, ['trusted', 'health', 'alpn'], true);

if ($mode === 'stall') {
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 3);
    if (!is_resource($socket)) {
        fwrite(STDERR, "Could not connect to TLS listener: {$errorMessage}\n");
        exit(1);
    }

    sleep(3);
    fclose($socket);
    echo "STALLED_TLS_HANDSHAKE=ok\n";
    exit(0);
}

$ssl = [
    'cafile' => $caFile,
    'peer_name' => 'localhost',
    'verify_peer' => true,
    'verify_peer_name' => true,
];

if ($mode !== 'missing') {
    $ssl['local_cert'] = $certFile;
    $ssl['local_pk'] = $keyFile;
}
if ($mode === 'alpn') {
    $ssl['alpn_protocols'] = 'http/1.1';
}

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
try {
    $socket = stream_socket_client(
        'tls://127.0.0.1:' . $port,
        $errorCode,
        $errorMessage,
        3,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => $ssl]),
    );
} finally {
    restore_error_handler();
}

if (!is_resource($socket) && $expectedSuccess) {
    $diagnostic = $errorMessage . "\n" . implode("\n", $warnings);
    fwrite(STDERR, "Trusted client certificate was rejected: {$diagnostic}\n");
    exit(1);
}

if (!is_resource($socket)) {
    echo strtoupper($mode) . "_CLIENT_REJECTED=ok\n";
    exit(0);
}

if ($mode === 'alpn') {
    $protocol = stream_get_meta_data($socket)['crypto']['alpn_protocol'] ?? null;
    if ($protocol !== 'http/1.1') {
        fwrite(STDERR, 'TLS ALPN did not select http/1.1.');
        exit(1);
    }
}

stream_set_timeout($socket, 2);
if ($mode === 'health') {
    fclose($socket);
    echo "TRUSTED_TLS_HANDSHAKE=ok\n";
    exit(0);
}

$request = "GET /mtls HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
$ioWarnings = [];
set_error_handler(static function (int $severity, string $message) use (&$ioWarnings): bool {
    $ioWarnings[] = $message;
    return true;
});
try {
    fwrite($socket, $request);
    $response = stream_get_contents($socket);
} finally {
    restore_error_handler();
}
fclose($socket);

if (!$expectedSuccess) {
    if (str_starts_with((string)$response, 'HTTP/')) {
        fwrite(STDERR, "{$mode} client received an HTTP response without a trusted certificate.\n");
        exit(1);
    }

    echo strtoupper($mode) . "_CLIENT_REJECTED=ok\n";
    exit(0);
}

if (!str_starts_with((string)$response, 'HTTP/1.1 200')) {
    fwrite(
        STDERR,
        "Trusted mTLS request did not receive HTTP 200.\n" . implode("\n", $ioWarnings) . "\n",
    );
    exit(1);
}

[, $body] = explode("\r\n\r\n", $response, 2);
$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
if (($payload['path'] ?? null) !== '/mtls') {
    fwrite(STDERR, "Trusted mTLS response body did not match.\n");
    exit(1);
}

echo "TRUSTED_CLIENT_MTLS=ok\n";
