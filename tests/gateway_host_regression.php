<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2026 Localzet Group
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

require dirname(__DIR__) . '/vendor/autoload.php';

use localzet\Server\Connection\TcpConnection;
use localzet\Server\Events\Select;
use localzet\Server\Gateway\HttpProxySession;
use localzet\Server\Gateway\HttpReverseProxy;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $loop = new Select();
    foreach ([
        'example.test' => 502,
        'example.test:443' => 502,
        '[::1]' => 502,
        '[::1]:8080' => 502,
        'example.test:0' => 400,
        'example.test:65536' => 400,
        'user@example.test' => 400,
        'example.test/path' => 400,
        'example.test\\path' => 400,
        'example test' => 400,
        '[::1' => 400,
    ] as $host => $status) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Cannot create test socket pair.');
        }
        stream_set_blocking($pair[1], false);
        $connection = new TcpConnection($loop, $pair[0], '127.0.0.1:9000');
        $session = new HttpProxySession(new HttpReverseProxy(), $connection, 1.0, 65536);
        $session->onClientData("GET /missing HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
        $response = fread($pair[1], 65536);
        $session->close();
        $connection->close();
        fclose($pair[1]);
        if (!str_starts_with((string) $response, "HTTP/1.1 $status ")) {
            throw new RuntimeException("Unexpected response for Host $host: $response");
        }
        echo "OK: Host $host returns $status without runtime warnings\n";
    }
} finally {
    restore_error_handler();
}
