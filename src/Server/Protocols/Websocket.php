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
use function sha1;
use function str_repeat;
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
     * Имя класса Request.
     */
    protected static string $requestClass = Request::class;

    /**
     * Получить или установить имя класса Request для рукопожатия.
     *
     * @param string|null $className
     */
    public static function requestClass(string $className = null): string
    {
        if ($className !== null) {
            static::$requestClass = $className;
        }

        return static::$requestClass;
    }

    /**
     * Проверка целостности пакета.
     *
     * @throws Throwable
     */
    public static function input(string $buffer, TcpConnection $tcpConnection): int
    {
        // Получаем длину полученных данных.
        $recvLen = strlen($buffer);
        // Если длина данных меньше 6, возвращаем 0.
        if ($recvLen < 6) {
            return 0;
        }

        // Если рукопожатие еще не завершено, обрабатываем его.
        if (empty($tcpConnection->context->websocketHandshake)) {
            return static::dealHandshake($buffer, $tcpConnection);
        }

        // Буферизовать данные кадра веб-сокета.
        if ($tcpConnection->context->websocketCurrentFrameLength) {
            // Нам нужно больше данных кадра.
            if ($tcpConnection->context->websocketCurrentFrameLength > $recvLen) {
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
                $tcpConnection->close();
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
                    $closeCb = $tcpConnection->onWebSocketClose ?? $tcpConnection->server->onWebSocketClose ?? false;
                    if ($closeCb) {
                        try {
                            $closeCb($tcpConnection);
                        } catch (Throwable $e) {
                            Server::stopAll(250, $e);
                        }
                    } // Закрытие соединения
                    else {
                        $tcpConnection->close("\x88\x02\x03\xe8", true);
                    }

                    return 0;
                // Неверный опкод
                default:
                    Server::safeEcho("Ошибка опкода $opcode и закрытие WebSocket соединения. Буфер:" . $buffer . "\n");
                    $tcpConnection->close();
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
            } elseif ($dataLen === 127) {
                $headLen = 14;
                if ($headLen > $recvLen) {
                    return 0;
                }

                $arr = unpack('n/N2c', $buffer);
                $dataLen = $arr['c1'] * 4294967296 + $arr['c2'];
            }

            // Вычисляем текущую длину кадра.
            $currentFrameLength = $headLen + $dataLen;

            // Вычисляем общий размер пакета.
            $totalPackageSize = strlen($tcpConnection->context->websocketDataBuffer) + $currentFrameLength;

            // Если общий размер пакета превышает максимально допустимый размер пакета, выводим сообщение об ошибке и закрываем соединение.
            if ($totalPackageSize > $tcpConnection->maxPackageSize) {
                Server::safeEcho("Ошибка пакета. package_length=$totalPackageSize\n");
                $tcpConnection->close();
                return 0;
            }

            if ($isFinFrame) {
                // Если код операции равен 0x9 (пинг-пакет).
                if ($opcode === 0x9) {
                    if ($recvLen >= $currentFrameLength) {
                        // Декодируем данные пинг-пакета.
                        $pingData = static::decode(substr($buffer, 0, $currentFrameLength), $tcpConnection);
                        // Удаляем данные пинг-пакета из буфера.
                        $tcpConnection->consumeRecvBuffer($currentFrameLength);
                        // Сохраняем текущий тип websocket.
                        $tmpConnectionType = $tcpConnection->websocketType ?? static::BINARY_TYPE_BLOB;
                        // Устанавливаем тип websocket в "\x8a".
                        $tcpConnection->websocketType = "\x8a";
                        // Попытка вызвать onWebSocketPing
                        $pingCb = $tcpConnection->onWebSocketPing ?? $tcpConnection->server->onWebSocketPing ?? false;
                        if ($pingCb) {
                            try {
                                $pingCb($tcpConnection, $pingData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        } else {
                            // Отправляем данные пинг-пакета обратно клиенту.
                            $tcpConnection->send($pingData);
                        }

                        // Восстанавливаем тип websocket.
                        $tcpConnection->websocketType = $tmpConnectionType;

                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $tcpConnection);
                        }
                    }

                    return 0;
                }

                // Если код операции равен 0xa (понг-пакет).
                if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        // Декодируем данные понг-пакета.
                        $pongData = static::decode(substr($buffer, 0, $currentFrameLength), $tcpConnection);
                        // Удаляем данные понг-пакета из буфера.
                        $tcpConnection->consumeRecvBuffer($currentFrameLength);
                        // Сохраняем текущий тип websocket.
                        $tmpConnectionType = $tcpConnection->websocketType ?? static::BINARY_TYPE_BLOB;
                        // Устанавливаем тип websocket в "\x8a".
                        $tcpConnection->websocketType = "\x8a";
                        // Попытка вызвать onWebSocketPong
                        $pongCb = $tcpConnection->onWebSocketPong ?? $tcpConnection->server->onWebSocketPong ?? false;
                        if ($pongCb) {
                            try {
                                $pongCb($tcpConnection, $pongData);
                            } catch (Throwable $e) {
                                Server::stopAll(250, $e);
                            }
                        }

                        // Восстанавливаем тип websocket.
                        $tcpConnection->websocketType = $tmpConnectionType;

                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $tcpConnection);
                        }
                    }

                    return 0;
                }

                return $currentFrameLength;
            }

            // Устанавливаем текущую длину кадра websocket.
            $tcpConnection->context->websocketCurrentFrameLength = $currentFrameLength;
        }

        // Если получены только данные о длине кадра.
        if ($tcpConnection->context->websocketCurrentFrameLength === $recvLen) {
            // Декодируем данные.
            static::decode($buffer, $tcpConnection);
            // Удаляем декодированные данные из буфера.
            $tcpConnection->consumeRecvBuffer($tcpConnection->context->websocketCurrentFrameLength);
            // Устанавливаем текущую длину кадра websocket в 0.
            $tcpConnection->context->websocketCurrentFrameLength = 0;
            return 0;
        }

        // Если длина полученных данных больше длины кадра.
        if ($tcpConnection->context->websocketCurrentFrameLength < $recvLen) {
            // Декодируем данные текущего кадра.
            static::decode(substr($buffer, 0, $tcpConnection->context->websocketCurrentFrameLength), $tcpConnection);
            // Удаляем декодированные данные из буфера.
            $tcpConnection->consumeRecvBuffer($tcpConnection->context->websocketCurrentFrameLength);
            // Сохраняем текущую длину кадра.
            $currentFrameLength = $tcpConnection->context->websocketCurrentFrameLength;
            // Устанавливаем текущую длину кадра websocket в 0.
            $tcpConnection->context->websocketCurrentFrameLength = 0;
            // Продолжаем чтение следующего кадра.
            return static::input(substr($buffer, $currentFrameLength), $tcpConnection);
        }

        // Если длина полученных данных меньше длины кадра, возвращаем 0.
        return 0;
    }

    /**
     * Рукопожатие WebSocket.
     *
     * @throws Throwable
     */
    public static function dealHandshake(string $buffer, TcpConnection $tcpConnection): int
    {
        /** @var Request $request */
        $request = new static::$requestClass($buffer);
        $request->connection = $tcpConnection;
        $tcpConnection->request = $request;

        // Протокол HTTP.
        if ($request->isMethod('GET')) {
            $headerEndPos = strpos($buffer, "\r\n\r\n");
            if (!$headerEndPos) {
                return 0;
            }

            $headerLength = $headerEndPos + 4;

            // Get Sec-WebSocket-Key.
            $SecWebSocketKey = $request->header('Sec-WebSocket-Key');
            if (!$SecWebSocketKey) {
                $tcpConnection->close(format_http_response(400), true);
                return 0;
            }

            // Данные ответа на рукопожатие.
            $tcpConnection->response = new Server\Protocols\Http\Response(101, [
                'Sec-WebSocket-Accept' => base64_encode(sha1($SecWebSocketKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true)),
                'Connection' => 'Upgrade',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Version' => 13,
            ], null);

            // Буфер данных websocket.
            $tcpConnection->context->websocketDataBuffer = '';

            // Текущая длина кадра websocket.
            $tcpConnection->context->websocketCurrentFrameLength = 0;

            // Текущие данные кадра websocket.
            $tcpConnection->context->websocketCurrentFrameBuffer = '';

            // Разбор данных рукопожатия.
            $tcpConnection->consumeRecvBuffer($headerLength);

            // Попытка вызвать обратный вызов onWebSocketConnect.
            $onWebSocketConnect = $tcpConnection->onWebSocketConnect ?? $tcpConnection->server->onWebSocketConnect ?? false;
            if ($onWebSocketConnect) {
                try {
                    $addResponse = $onWebSocketConnect($tcpConnection, $request) ?? null;

                    if ($addResponse instanceof Server\Protocols\Http\Response) {
                        if ($addResponse->getHeaders()) {
                            $tcpConnection->response->withHeaders($addResponse->getHeaders());
                        }

                        if ($addResponse->getStatusCode() >= 400) {
                            $tcpConnection->response->withStatus($addResponse->getStatusCode());

                            if (!empty($addResponse->rawBody())) {
                                $tcpConnection->response->withBody($addResponse->rawBody());
                            }

                            $tcpConnection->close((string)$tcpConnection->response, true);
                            return 0;
                        }
                    }
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            // blob или arraybuffer
            if (empty($tcpConnection->websocketType)) {
                $tcpConnection->websocketType = static::BINARY_TYPE_BLOB;
            }

            if ($tcpConnection->headers) {
                $tcpConnection->response->withHeaders($tcpConnection->headers);
            }

            // Отправить ответ на рукопожатие.
            $tcpConnection->send((string)$tcpConnection->response, true);
            // Пометить рукопожатие как завершенное.
            $tcpConnection->context->websocketHandshake = true;

            // Есть данные, ожидающие отправки.
            if (!empty($tcpConnection->context->tmpWebsocketData)) {
                // Отправка временных данных websocket.
                $tcpConnection->send($tcpConnection->context->tmpWebsocketData, true);
                // Очистка временных данных websocket.
                $tcpConnection->context->tmpWebsocketData = '';
            }

            if (strlen($buffer) > $headerLength) {
                return static::input(substr($buffer, $headerLength), $tcpConnection);
            }

            return 0;
        }

        // Неверный запрос рукопожатия через веб-сокет.
        $tcpConnection->close(format_http_response(400), true);
        return 0;
    }

    /**
     * Декодирование WebSocket.
     */
    public static function decode(string $buffer, TcpConnection $tcpConnection): string
    {
        $firstByte = ord($buffer[1]);
        $len = $firstByte & 127;

        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } elseif ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }

        $dataLength = strlen($data);
        $masks = str_repeat($masks, (int)floor($dataLength / 4)) . substr($masks, 0, $dataLength % 4);
        $decoded = $data ^ $masks;
        if ($tcpConnection->context->websocketCurrentFrameLength) {
            $tcpConnection->context->websocketDataBuffer .= $decoded;
            return $tcpConnection->context->websocketDataBuffer;
        }

        if ($tcpConnection->context->websocketDataBuffer !== '') {
            $decoded = $tcpConnection->context->websocketDataBuffer . $decoded;
            $tcpConnection->context->websocketDataBuffer = '';
        }

        return $decoded;
    }

    /**
     * Кодирование WebSocket.
     *
     * @throws Throwable
     */
    public static function encode(mixed $buffer, TcpConnection $tcpConnection): string
    {
        // Если буфер не является скалярным значением, выбрасываем исключение.
        if (!is_scalar($buffer)) {
            throw new Exception("Вы не можете отправить (" . gettype($buffer) . ") клиенту, конвертируйте это в строку.");
        }

        // Получаем длину буфера.
        $len = strlen($buffer);

        // Если тип websocket не установлен, устанавливаем его в BINARY_TYPE_BLOB.
        if (empty($tcpConnection->websocketType)) {
            $tcpConnection->websocketType = static::BINARY_TYPE_BLOB;
        }

        // Устанавливаем первый байт в тип websocket.
        $firstByte = $tcpConnection->websocketType;

        if ($len <= 125) {
            $encodeBuffer = $firstByte . chr($len) . $buffer;
        } elseif ($len <= 65535) {
            $encodeBuffer = $firstByte . chr(126) . pack("n", $len) . $buffer;
        } else {
            $encodeBuffer = $firstByte . chr(127) . pack("xxxxN", $len) . $buffer;
        }

        // Если рукопожатие еще не завершено, данные websocket временного буфера ожидают отправки.
        if (empty($tcpConnection->context->websocketHandshake)) {
            if (empty($tcpConnection->context->tmpWebsocketData)) {
                $tcpConnection->context->tmpWebsocketData = '';
            }

            // Если буфер уже заполнен, отбрасываем текущий пакет.
            if (strlen($tcpConnection->context->tmpWebsocketData) > $tcpConnection->maxSendBufferSize) {
                if ($tcpConnection->onError) {
                    try {
                        ($tcpConnection->onError)($tcpConnection, ConnectionInterface::SEND_FAIL, 'отправить полный буфер и удалить пакет');
                    } catch (Throwable $e) {
                        Server::stopAll(250, $e);
                    }
                }

                return '';
            }

            $tcpConnection->context->tmpWebsocketData .= $encodeBuffer;

            // Проверяем, заполнен ли буфер.
            if ($tcpConnection->onBufferFull && $tcpConnection->maxSendBufferSize <= strlen($tcpConnection->context->tmpWebsocketData)) {
                try {
                    ($tcpConnection->onBufferFull)($tcpConnection);
                } catch (Throwable $e) {
                    Server::stopAll(250, $e);
                }
            }

            return '';
        }

        return $encodeBuffer;
    }
}