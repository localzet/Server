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

namespace localzet\Server\Runtime;

use RuntimeException;
use Socket;

/**
 * Передача runtime state между старым и новым PHP image при hot-upgrade.
 *
 * `pcntl_exec()` сохраняет PID процесса, но PHP после exec уже не знает о старых
 * stream resources. Поэтому Localzet на короткое время форкает broker-процесс:
 * broker удерживает listening sockets и startup-lock, новый PHP image соединяется
 * с ним через локальный Unix socket, после чего descriptors передаются системным
 * механизмом SCM_RIGHTS.
 *
 * В результате master может перечитать bootstrap-код без rebinding портов и без
 * остановки старых workers. Механизм намеренно Unix-only и требует ext-sockets.
 */
final class HotUpgradeBroker
{
    public const ENV_PATH = 'LOCALZET_HOT_UPGRADE_PATH';
    public const ENV_TOKEN = 'LOCALZET_HOT_UPGRADE_TOKEN';

    /**
     * Проверяет наличие системных примитивов, необходимых для descriptor passing.
     */
    public static function isSupported(): bool
    {
        return DIRECTORY_SEPARATOR === '/'
            && function_exists('pcntl_fork')
            && function_exists('pcntl_exec')
            && extension_loaded('sockets')
            && function_exists('socket_sendmsg')
            && function_exists('socket_recvmsg')
            && function_exists('socket_cmsg_space')
            && defined('SCM_RIGHTS')
            && defined('AF_UNIX');
    }

    /**
     * Запускает временный descriptor broker.
     *
     * @param array<string,mixed> $metadata JSON-serializable supervisor state.
     * @param array<string,mixed> $resources Stream resources/Socket instances,
     *        которые новый master должен получить после exec.
     * @return array{pid:int,path:string,token:string}
     * @throws RuntimeException Если broker невозможно создать.
     */
    public static function fork(array $metadata, array $resources): array
    {
        if (!self::isSupported()) {
            throw new RuntimeException('Zero-downtime hot upgrade requires Unix, pcntl and ext-sockets SCM_RIGHTS support.');
        }

        $path = sys_get_temp_dir()
            . '/localzet-upgrade-'
            . getmypid()
            . '-'
            . bin2hex(random_bytes(6))
            . '.sock';
        $token = bin2hex(random_bytes(24));

        $server = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (!$server instanceof Socket) {
            throw new RuntimeException('Unable to create Localzet hot-upgrade control socket.');
        }

        @unlink($path);
        if (!@socket_bind($server, $path) || !@socket_listen($server, 1)) {
            $message = socket_strerror(socket_last_error($server));
            @socket_close($server);
            @unlink($path);
            throw new RuntimeException("Unable to bind Localzet hot-upgrade broker: {$message}");
        }
        @chmod($path, 0600);

        $pid = pcntl_fork();
        if ($pid < 0) {
            @socket_close($server);
            @unlink($path);
            throw new RuntimeException('Unable to fork Localzet hot-upgrade broker.');
        }

        if ($pid === 0) {
            // Broker не выполняет application code: он обслуживает ровно одно
            // локальное соединение нового master и сразу завершает процесс.
            self::serveOnce($server, $path, $token, $metadata, $resources);
            exit(0);
        }

        // Старый master больше не должен держать listening end control socket.
        // Его копия остаётся у broker child до завершения descriptor transfer.
        @socket_close($server);

        return ['pid' => $pid, 'path' => $path, 'token' => $token];
    }

    /**
     * Получает supervisor metadata и переданные descriptors в новом PHP image.
     *
     * @return array{metadata:array<string,mixed>,resources:array<string,mixed>}
     */
    public static function receiveFromEnvironment(): array
    {
        $path = (string)getenv(self::ENV_PATH);
        $token = (string)getenv(self::ENV_TOKEN);
        if ($path === '' || $token === '') {
            throw new RuntimeException('Hot-upgrade environment is incomplete.');
        }

        $client = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (!$client instanceof Socket || !@socket_connect($client, $path)) {
            $message = $client instanceof Socket
                ? socket_strerror(socket_last_error($client))
                : 'socket_create failed';
            throw new RuntimeException("Unable to connect to Localzet hot-upgrade broker: {$message}");
        }

        self::writeAll($client, $token . "\n");
        $metadataLine = self::readLine($client, 4 * 1024 * 1024);
        $metadata = json_decode($metadataLine, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($metadata)) {
            throw new RuntimeException('Invalid Localzet hot-upgrade metadata.');
        }

        $resourceCount = (int)($metadata['resource_count'] ?? 0);
        $resources = [];

        // SOCK_STREAM does not preserve message boundaries. Without this READY
        // barrier readLine() is allowed to consume the ordinary payload byte of
        // the first SCM_RIGHTS message together with metadata; the associated
        // descriptor is then lost because ancillary data must be read via recvmsg().
        // The barrier makes descriptor transfer a separate protocol phase.
        self::writeAll($client, "READY\n");

        for ($index = 0; $index < $resourceCount; $index++) {
            $message = [
                'buffer_size' => 512,
                'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1),
            ];
            $received = @socket_recvmsg($client, $message, 0);
            if ($received === false || $received <= 0) {
                throw new RuntimeException('Hot-upgrade broker closed before all descriptors were received.');
            }

            $label = trim((string)(($message['iov'][0] ?? '')));
            $resource = $message['control'][0]['data'][0] ?? null;
            if ($label === '' || ($resource === null)) {
                throw new RuntimeException('Invalid descriptor message from hot-upgrade broker.');
            }

            // SCM_RIGHTS may materialize sockets as Socket objects and regular
            // files directly as stream resources. Server core consumes streams.
            if ($resource instanceof Socket) {
                $resource = socket_export_stream($resource);
            }
            if (!is_resource($resource)) {
                throw new RuntimeException("Unable to export inherited descriptor '{$label}' as a stream.");
            }
            $resources[$label] = $resource;

            // One descriptor per acknowledged round-trip prevents two sendmsg()
            // calls from being coalesced into one SOCK_STREAM read and also keeps
            // the broker alive until the new image has materialized the resource.
            self::writeAll($client, "ACK {$index}\n");
        }

        $done = trim(self::readLine($client, 64));
        if ($done !== 'DONE') {
            throw new RuntimeException('Hot-upgrade broker did not finish descriptor transfer cleanly.');
        }

        @socket_close($client);
        @unlink($path);
        putenv(self::ENV_PATH);
        putenv(self::ENV_TOKEN);

        return ['metadata' => $metadata, 'resources' => $resources];
    }

    /**
     * Broker child: аутентифицирует новый master и передаёт descriptors.
     *
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $resources
     */
    private static function serveOnce(Socket $server, string $path, string $token, array $metadata, array $resources): void
    {
        try {
            $client = @socket_accept($server);
            if (!$client instanceof Socket) {
                return;
            }

            $receivedToken = trim(self::readLine($client, 512));
            if (!hash_equals($token, $receivedToken)) {
                @socket_close($client);
                return;
            }

            $metadata['resource_count'] = count($resources);
            self::writeAll(
                $client,
                json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"
            );

            // Do not start SCM_RIGHTS traffic until the new image has consumed
            // the metadata line with ordinary socket_read(). Otherwise SOCK_STREAM
            // may let that read steal the first descriptor's payload byte.
            if (trim(self::readLine($client, 64)) !== 'READY') {
                return;
            }

            $index = 0;
            foreach ($resources as $label => $resource) {
                $sent = @socket_sendmsg($client, [
                    // POSIX requires at least one ordinary byte alongside
                    // SCM_RIGHTS ancillary data. Label doubles as that byte payload.
                    'iov' => [(string)$label . "\n"],
                    'control' => [[
                        'level' => SOL_SOCKET,
                        'type' => SCM_RIGHTS,
                        'data' => [$resource],
                    ]],
                ], 0);
                if ($sent === false) {
                    return;
                }

                if (trim(self::readLine($client, 64)) !== "ACK {$index}") {
                    return;
                }
                ++$index;
            }

            self::writeAll($client, "DONE\n");
            @socket_close($client);
        } finally {
            @socket_close($server);
            @unlink($path);
        }
    }

    /** Читает одну LF-terminated строку с жёстким ограничением размера. */
    private static function readLine(Socket $socket, int $maxBytes): string
    {
        $buffer = '';
        while (!str_contains($buffer, "\n")) {
            $chunk = @socket_read($socket, min(4096, $maxBytes - strlen($buffer)), PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Unexpected EOF on Localzet hot-upgrade control socket.');
            }
            $buffer .= $chunk;
            if (strlen($buffer) >= $maxBytes) {
                throw new RuntimeException('Localzet hot-upgrade control message is too large.');
            }
        }
        return substr($buffer, 0, strpos($buffer, "\n"));
    }

    /** Записывает весь control payload, учитывая частичные socket_write(). */
    private static function writeAll(Socket $socket, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = @socket_write($socket, substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write Localzet hot-upgrade control message.');
            }
            $offset += $written;
        }
    }
}
