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

require dirname(__DIR__) . '/vendor/autoload.php';

use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;

Server::$pidFile = (string)(getenv('LOCALZET_TEST_MTLS_PID_FILE') ?: '');
Server::$statusFile = (string)(getenv('LOCALZET_TEST_MTLS_STATUS_FILE') ?: '');
Server::$logFile = (string)(getenv('LOCALZET_TEST_MTLS_LOG_FILE') ?: '');

$port = (int)(getenv('LOCALZET_TEST_MTLS_PORT') ?: 0);
$hitsFile = (string)(getenv('LOCALZET_TEST_MTLS_HITS_FILE') ?: '');
$errorsFile = (string)(getenv('LOCALZET_TEST_MTLS_ERRORS_FILE') ?: '');

$server = new Server('https://127.0.0.1:' . $port);
$server->name = 'integration-mtls';
$server->count = 1;
$server->tlsHandshakeTimeout = 2.0;
$server->onError = static function (TcpConnection $connection, int $code, string $message) use ($errorsFile): void {
    file_put_contents($errorsFile, $message . "\n", FILE_APPEND | LOCK_EX);
};
$server->onMessage = static function (TcpConnection $connection, Request $request) use ($hitsFile): void {
    file_put_contents($hitsFile, $request->path() . "\n", FILE_APPEND | LOCK_EX);
    $body = json_encode(['path' => $request->path()], JSON_THROW_ON_ERROR);
    $connection->send(new Response(200, ['Content-Type' => 'application/json'], $body));
    $connection->close();
};

Server::runAll();
