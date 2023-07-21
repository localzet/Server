<?php

use Symfony\Component\Process\PhpProcess;

$serverCode = <<<PHP
<?php
use localzet\Connection\TcpConnection;
use localzet\Protocols\Http\Request;
use localzet\Server;
require_once __DIR__ . '/vendor/autoload.php';
if (!defined('STDIN')) define('STDIN', fopen('php://stdin', 'r'));
if (!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'w'));
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));
\$server = new Server("websocket://0.0.0.0:2000");
%s
Server::\$pidFile = __DIR__ . '/WebsocketServer.pid';
Server::\$command = 'start';
Server::runAll();
PHP;

$clientCode = <<<PHP
<?php
use localzet\Connection\AsyncTcpConnection;
use localzet\Server;
require_once __DIR__ . '/vendor/autoload.php';
if (!defined('STDIN')) define('STDIN', fopen('php://stdin', 'r'));
if (!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'w'));
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));
\$server = new Server();
\$server->onServerStart = function(\$server){
    \$con = new AsyncTcpConnection('ws://127.0.0.1:2000');
    %s
    \$con->connect();
};
Server::\$pidFile = __DIR__ . '/WebsocketClient.pid';
Server::\$command = 'start';
Server::runAll();
PHP;

it('tests websocket connection', function () use ($serverCode, $clientCode) {
    $serverProcess = new PhpProcess(sprintf(
        $serverCode,
        <<<PHP
        \$server->onWebSocketConnect = function () {
            echo "connected";
        };
        \$server->onMessage = function () {};
    PHP
    ));
    $serverProcess->start();
    sleep(1);

    $clientProcess = new PhpProcess(sprintf(
        $clientCode,
        <<<PHP
        \$con->onWebSocketConnect = function(AsyncTcpConnection \$con) {
            \$con->send('connect');
        };
    PHP
    ));
    $clientProcess->start();
    sleep(1);

    expect(getNonFrameOutput($serverProcess->getOutput()))->toBe('connected')
        ->and(getNonFrameOutput($clientProcess->getOutput()))->toBe('');

    $serverProcess->stop();
    $clientProcess->stop();
});

it('tests server and client sending and receiving messages', function () use ($serverCode, $clientCode) {
    $serverProcess = new PhpProcess(sprintf(
        $serverCode,
        <<<PHP
        \$server->onMessage = function (TcpConnection \$connection, \$data) {
            echo \$data;
            \$connection->send('Hi');
        };
    PHP
    ));
    $serverProcess->start();
    sleep(1);

    $clientProcess = new PhpProcess(sprintf(
        $clientCode,
        <<<PHP
        \$con->onWebSocketConnect = function(AsyncTcpConnection \$con) {
            \$con->send('Hello Chance');
        };
        \$con->onMessage = function(\$con, \$data) {
            echo \$data;
        };
    PHP
    ));
    $clientProcess->start();
    sleep(1);

    expect(getNonFrameOutput($serverProcess->getOutput()))->toBe('Hello Chance')
        ->and(getNonFrameOutput($clientProcess->getOutput()))->toBe('Hi');

    $serverProcess->stop();
    $clientProcess->stop();
});

it('tests server close connection', function () use ($serverCode, $clientCode) {
    $serverProcess = new PhpProcess(sprintf(
        $serverCode,
        <<<PHP
        \$server->onWebSocketConnect = function (TcpConnection \$connection) {
            echo 'close connection';
            \$connection->close();
        };
        \$server->onMessage = function () {};
    PHP
    ));
    $serverProcess->start();
    sleep(1);

    $clientProcess = new PhpProcess(sprintf(
        $clientCode,
        <<<PHP
        \$con->onWebSocketConnect = function(AsyncTcpConnection \$con) {
            \$con->send('connect');
        };
        \$con->onClose = function () {
            echo 'closed';
        };
    PHP
    ));
    $clientProcess->start();
    sleep(1);

    expect(getNonFrameOutput($serverProcess->getOutput()))->toBe('close connection')
        ->and(getNonFrameOutput($clientProcess->getOutput()))->toBe('closed');

    $serverProcess->stop();
    $clientProcess->stop();
});

it('tests client close connection', function () use ($serverCode, $clientCode) {
    $serverProcess = new PhpProcess(sprintf(
        $serverCode,
        <<<PHP
        \$server->onMessage = function () {};
        \$server->onClose = function () {
            echo 'closed';
        };
    PHP
    ));
    $serverProcess->start();
    sleep(1);

    $clientProcess = new PhpProcess(sprintf(
        $clientCode,
        <<<PHP
        \$con->onWebSocketConnect = function(AsyncTcpConnection \$con) {
            \$con->send('connect');
            echo 'close connection';
            \$con->close();
        };
    PHP
    ));
    $clientProcess->start();
    sleep(1);

    expect(getNonFrameOutput($serverProcess->getOutput()))->toBe('closed')
        ->and(getNonFrameOutput($clientProcess->getOutput()))->toBe('close connection');

    $serverProcess->stop();
    $clientProcess->stop();
});
