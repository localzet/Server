<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2016-2024 Zorin Projects
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Protocols;

use Exception;
use localzet\Server;
use localzet\Server\Connection\{ConnectionInterface, TcpConnection};
use localzet\Server\Protocols\Http\Request;
use Throwable;
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
 * Протокол WebSocket.
 */
class Websocket
{
    /**
     * Тип BLOB для WebSocket.
     *
     * @var string
     */
    public const BINARY_TYPE_BLOB = "\x81";

    /**
     * Тип ArrayBuffer для WebSocket.
     *
     * @var string
     */
    public const BINARY_TYPE_ARRAYBUFFER = "\x82";

    /**
     * Проверка целостности пакета.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     * @throws Throwable
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        // Получаем длину полученных данных.
        $recvLen = strlen($buffer);
        // Если длина данных меньше 6, возвращаем 0.
        if ($recvLen < 6) {
            return 0;
        }

        // Если рукопожатие еще не завершено, обрабатываем его.
        if (empty($connection->context->websocketHandshake)) {
            return static::dealHandshake($buffer, $connection);
        }

        // Буферизовать данные кадра веб-сокета.
        if ($connection->context->websocketCurrentFrameLength) {
            // Нам нужно больше данных кадра.
            if ($connection->context->websocketCurrentFrameLength > $recvLen) {
                // Вернуть 0, потому что неясна полная длина пакета, ожидание кадра fin=1.
                return 0;
            }
        } else {
            // Получаем первый и второй байты данных.
            $firstByte = ord($buffer[0]);
            $secondByte = ord($buffer[1]);
            // Извлекаем длину данных.
            $dataLen = $secondByte & 127;
            // Проверяем, является ли кадр финальным.
            $isFinFrame = $firstByte >> 7;
            // Проверяем, замаскированы ли данные.
            $masked = $secondByte >> 7;

            // Если данные не замаскированы, выводим сообщение об ошибке и закрываем соединение.
            if (!$masked) {
                Server::safeEcho("Кадр не замаскирован, закрываю соединение\n");
                $connection->close();
                return 0;
            }

            // Получаем код операции.
            $opcode = $firstByte & 0xf;

            switch ($opcode) {
                case 0x0:
                    // BLOB
                case 0x1:
                    // Массив
                case 0x2:
                    // Пинг-пакет
                case 0x9:
                    // Понг-пакет
                case 0xa:
                    break;
                // Закрытие
                case 0x8:
                    // Попытка вызвать onWebSocketClose
                    $closeCb = $connection->onWebSocketClose ?? $connection->server->onWebSocketClose ?? false;
                    if ($closeCb) {
                        try {
                            $closeCb($connection);
                        } catch (Throwable $e) {
                            Server::stopAll(250, $e);
                        }
                    } // Закрытие соединения
                    else {
                        $connection->close("\x88\x02\x03\xe8", true);
                    }
                    return 0;
                // Неверный опкод
                default:
                    Server::safeEcho("Ошибка опкода $opcode и закрытие WebSocket соединения. Буфер:" . $buffer . "\n");
                    $connection->close();
                    return 0;
            }

            // Рассчитать длину пакета
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

            // Вычисляем текущую длину кадра.
            $currentFrameLength = $headLen + $dataLen;

            // Вычисляем общий размер пакета.
            $totalPackageSize = strlen($connection->context->websocketDataBuffer) + $currentFrameLength;

            // Если общий размер пакета превышает максимально допустимый размер пакета, выводим сообщение об ошибке и закрываем соединение.
            if ($totalPackageSize > $connection->maxPackageSize) {
                Server::safeEcho("Ошибка пакета. package_length=$totalPackageSize\n");
                $connection->close();
                return 0;
            }

            if ($isFinFrame) {
                // Если код операции равен 0x9 (пинг-пакет).
                if ($opcode === 0x9) {
                    if ($recvLen >= $currentFrameLength) {
                        // Декодируем данные пинг-пакета.
                        $pingData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        // Удаляем данные пинг-пакета из буфера.
                        $connection->consumeRecvBuffer($currentFrameLength);
                        // Сохраняем текущий тип websocket.
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        // Устанавливаем тип websocket в "\x8a".
                        $connection->websocketType = "\x8a";
                        // Попытка вызвать onWebSocketPing
                        $pingCb = $connection->onWebSocketPing ?? $connection->server->onWebSocketPing ?? false;
                        if ($pingCb) {
                            try {
                                $pingCb($connection, $pingData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        } else {
                            // Отправляем данные пинг-пакета обратно клиенту.
                            $connection->send($pingData);
                        }
                        // Восстанавливаем тип websocket.
                        $connection->websocketType = $tmpConnectionType;

                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;
                }

                // Если код операции равен 0xa (понг-пакет).
                if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        // Декодируем данные понг-пакета.
                        $pongData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        // Удаляем данные понг-пакета из буфера.
                        $connection->consumeRecvBuffer($currentFrameLength);
                        // Сохраняем текущий тип websocket.
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        // Устанавливаем тип websocket в "\x8a".
                        $connection->websocketType = "\x8a";
                        // Попытка вызвать onWebSocketPong
                        $pongCb = $connection->onWebSocketPong ?? $connection->server->onWebSocketPong ?? false;
                        if ($pongCb) {
                            try {
                                $pongCb($connection, $pongData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        }

                        // Восстанавливаем тип websocket.
                        $connection->websocketType = $tmpConnectionType;

                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;
                }

                return $currentFrameLength;
            }

            // Устанавливаем текущую длину кадра websocket.
            $connection->context->websocketCurrentFrameLength = $currentFrameLength;
        }

        // Если получены только данные о длине кадра.
        if ($connection->context->websocketCurrentFrameLength === $recvLen) {
            // Декодируем данные.
            static::decode($buffer, $connection);
            // Удаляем декодированные данные из буфера.
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            // Устанавливаем текущую длину кадра websocket в 0.
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        }

        // Если длина полученных данных больше длины кадра.
        if ($connection->context->websocketCurrentFrameLength < $recvLen) {
            // Декодируем данные текущего кадра.
            static::decode(substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            // Удаляем декодированные данные из буфера.
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            // Сохраняем текущую длину кадра.
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            // Устанавливаем текущую длину кадра websocket в 0.
            $connection->context->websocketCurrentFrameLength = 0;
            // Продолжаем чтение следующего кадра.
            return static::input(substr($buffer, $currentFrameLength), $connection);
        }

        // Если длина полученных данных меньше длины кадра, возвращаем 0.
        return 0;
    }

    /**
     * Рукопожатие WebSocket.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     * @throws Throwable
     */
    public static function dealHandshake(string $buffer, TcpConnection $connection): int
    {
        // Протокол HTTP.
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
                $connection->close(format_websocket_response(400, null), true);
                return 0;
            }
            // Расчет ключа websocket.
            $newKey = base64_encode(sha1($SecWebSocketKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Данные ответа на рукопожатие.
            $handshakeMessage = format_websocket_response(101, null, null, [
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Version' => 13,
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $newKey,
            ]);

            // Буфер данных websocket.
            $connection->context->websocketDataBuffer = '';

            // Текущая длина кадра websocket.
            $connection->context->websocketCurrentFrameLength = 0;

            // Текущие данные кадра websocket.
            $connection->context->websocketCurrentFrameBuffer = '';

            // Разбор данных рукопожатия.
            $connection->consumeRecvBuffer($headerLength);

            // Попытка вызвать обратный вызов onWebSocketConnect.
            $onWebsocketConnect = $connection->onWebSocketConnect ?? $connection->server->onWebSocketConnect ?? false;
            if ($onWebsocketConnect) {
                try {
                    $onWebsocketConnect($connection, new Request($buffer));
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            // blob или arraybuffer
            if (empty($connection->websocketType)) {
                $connection->websocketType = static::BINARY_TYPE_BLOB;
            }

            if ($connection->headers) {
                foreach ($connection->headers as $header) {
                    if (stripos($header, 'Server:') === 0) {
                        continue;
                    }
                    $handshakeMessage .= "$header\r\n";
                }
            }

            $handshakeMessage .= "\r\n";
            // Отправить ответ на рукопожатие.
            $connection->send($handshakeMessage, true);
            // Пометить рукопожатие как завершенное.
            $connection->context->websocketHandshake = true;

            // Есть данные, ожидающие отправки.
            if (!empty($connection->context->tmpWebsocketData)) {
                // Отправка временных данных websocket.
                $connection->send($connection->context->tmpWebsocketData, true);
                // Очистка временных данных websocket.
                $connection->context->tmpWebsocketData = '';
            }

            if (strlen($buffer) > $headerLength) {
                return static::input(substr($buffer, $headerLength), $connection);
            }
            return 0;
        }
        // Неверный запрос рукопожатия через веб-сокет.
        $connection->close(format_websocket_response(400, null), true);
        return 0;
    }

    /**
     * Декодирование WebSocket.
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
        $masks = str_repeat($masks, (int)floor($dataLength / 4)) . substr($masks, 0, $dataLength % 4);
        $decoded = $data ^ $masks;
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decoded;
            return $connection->context->websocketDataBuffer;
        }

        if ($connection->context->websocketDataBuffer !== '') {
            $decoded = $connection->context->websocketDataBuffer . $decoded;
            $connection->context->websocketDataBuffer = '';
        }
        return $decoded;
    }

    /**
     * Кодирование WebSocket.
     *
     * @param mixed $buffer
     * @param TcpConnection $connection
     * @return string
     * @throws Throwable
     */
    public static function encode(mixed $buffer, TcpConnection $connection): string
    {
        // Если буфер не является скалярным значением, выбрасываем исключение.
        if (!is_scalar($buffer)) {
            throw new Exception("Вы не можете отправить (" . gettype($buffer) . ") клиенту, конвертируйте это в строку.");
        }

        // Получаем длину буфера.
        $len = strlen($buffer);

        // Если тип websocket не установлен, устанавливаем его в BINARY_TYPE_BLOB.
        if (empty($connection->websocketType)) {
            $connection->websocketType = static::BINARY_TYPE_BLOB;
        }

        // Устанавливаем первый байт в тип websocket.
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

        // Если рукопожатие еще не завершено, данные websocket временного буфера ожидают отправки.
        if (empty($connection->context->websocketHandshake)) {
            if (empty($connection->context->tmpWebsocketData)) {
                $connection->context->tmpWebsocketData = '';
            }

            // Если буфер уже заполнен, отбрасываем текущий пакет.
            if (strlen($connection->context->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        ($connection->onError)($connection, ConnectionInterface::SEND_FAIL, 'отправить полный буфер и удалить пакет');
                    } catch (Throwable $e) {
                        Server::stopAll(250, $e);
                    }
                }
                return '';
            }
            $connection->context->tmpWebsocketData .= $encodeBuffer;

            // Проверяем, заполнен ли буфер.
            if ($connection->onBufferFull && $connection->maxSendBufferSize <= strlen($connection->context->tmpWebsocketData)) {
                try {
                    ($connection->onBufferFull)($connection);
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            return '';
        }

        return $encodeBuffer;
    }
}