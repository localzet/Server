<?php

/**
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Core\Protocols;

use localzet\Core\Server;
use localzet\Core\Connection\TcpConnection;
use localzet\Core\Protocols\FastCGI\Request;
use localzet\Core\Protocols\FastCGI\Response;

class Fcgi
{
    /**
     * Версия протокола FCGI
     *
     * @var int
     */
    const FCGI_VERSION_1 = 1;

    /**
     * Фиксированная длина FCGI_Header: sizeof(FCGI_Header) === 8
     *
     * typedef struct {
     *     unsigned char version;
     *     unsigned char type;
     *     unsigned char requestIdB1;
     *     unsigned char requestIdB0;
     *     unsigned char contentLengthB1;
     *     unsigned char contentLengthB0;
     *     unsigned char paddingLength;
     *     unsigned char reserved;
     * } FCGI_Header;
     *
     * @var int
     */
    const FCGI_HEADER_LEN = 8;

    /**
     * Максимальная длина полезной нагрузки 
     *
     * @var int
     */
    const FCGI_MAX_PAYLOAD_LEN = 65535;

    /**
     * Зарезервированный бит FCGI_Header
     *
     * @var string
     */
    const FCGI_RESERVED = '';

    /**
     * Прокладка FCGI_Header
     *
     * @var string
     */
    const FCGI_PADDING = '';

    /**
     * Тип записи FCGI_BEGIN_REQUEST
     *
     * @var int
     */
    const FCGI_BEGIN_REQUEST = 1;

    /**
     * Тип записи FCGI_ABORT_REQUEST
     *
     * @var int
     */
    const FCGI_ABORT_REQUEST = 2;

    /**
     * Тип записи FCGI_END_REQUEST
     *
     * @var int
     */
    const FCGI_END_REQUEST = 3;

    /**
     * Тип записи FCGI_PARAMS
     *
     * @var int
     */
    const FCGI_PARAMS = 4;

    /**
     * Псевдо-тип записи FCGI_PARAMS
     *
     * @var int
     */
    const FCGI_PARAMS_END = 4 << 3;

    /**
     * Тип записи FCGI_STDIN
     *
     * @var int
     */
    const FCGI_STDIN = 5;

    /**
     * Псевдо-тип записи FCGI_STDIN
     *
     * @var int
     */
    const FCGI_STDIN_END = 5 << 4;

    /**
     * Тип записи FCGI_STDOUT
     *
     * @var int
     */
    const FCGI_STDOUT = 6;

    /**
     * Тип записи FCGI_STDERR
     *
     * @var int
     */
    const FCGI_STDERR = 7;

    /**
     * Тип записи FCGI_DATA
     *
     * @var int
     */
    const FCGI_DATA = 8;

    /**
     * Тип записи FCGI_GET_VALUES
     *
     * @var int
     */
    const FCGI_GET_VALUES = 9;

    /**
     * Тип записи FCGI_GET_VALUES_RESULT
     *
     * @var int
     */
    const FCGI_GET_VALUES_RESULT = 10;

    /**
     * Тип записи FCGI_UNKNOWN_TYPE
     *
     * @var int
     */
    const FCGI_UNKNOWN_TYPE = 11;

    /**
     * Тип роли FCGI_RESPONDER
     *
     * @var int
     */
    const FCGI_RESPONDER = 1;

    /**
     * Тип роли FCGI_AUTHORIZER
     *
     * @var int
     */
    const FCGI_AUTHORIZER = 2;

    /**
     * Тип роли FCGI_FILTER
     *
     * @var int
     */
    const FCGI_FILTER = 3;

    /**
     * Статус протокола FCGI_REQUEST_COMPLETE
     *
     * @var int
     */
    const FCGI_REQUEST_COMPLETE = 0;

    /**
     * Статус протокола FCGI_CANT_MPX_CONN
     *
     * @var int
     */
    const FCGI_CANT_MPX_CONN = 1;

    /**
     * Статус протокола FCGI_OVERLOADED
     *
     * @var int
     */
    const FCGI_OVERLOADED = 2;

    /**
     * Статус протокола FCGI_UNKNOWN_ROLE
     *
     * @var int
     */
    const FCGI_UNKNOWN_ROLE = 3;

    /**
     * Объект запроса
     *
     * @var object
     */
    static private $_request = NULL;

    /**
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($buffer, TcpConnection $connection)
    {
        $recv_len = \strlen($buffer);

        if ($recv_len < static::FCGI_HEADER_LEN) return 0;

        if (!isset($connection->packetLength)) $connection->packetLength = 0;

        $data = \unpack("Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved", $buffer);
        if (false === $data) return 0;

        $chunk_len = static::FCGI_HEADER_LEN + $data['contentLength'] + $data['paddingLength'];
        if ($recv_len < $chunk_len) return 0;

        if (static::FCGI_END_REQUEST != $data['type']) {
            $connection->packetLength += $chunk_len;
            $next_chunk_len = static::input(\substr($buffer, $chunk_len), $connection);

            if (0 == $next_chunk_len) {
                // Важно!! Не забываем сбросить на нулевой байт!!
                $connection->packetLength = 0;
                return 0;
            }
        } else {
            $connection->packetLength += $chunk_len;
        }

        // Проверка длины пакета превышает длину пакета MAX или нет
        if ($connection->packetLength > $connection->maxPackageSize) {
            $data  = "Исключение: ошибка пакета. package_length = {$connection->packetLength} ";
            $data .= "превышает лимит {$connection->maxPackageSize}" . PHP_EOL;
            Server::safeEcho($data);
            $connection->close();
            return 0;
        }

        return $connection->packetLength;
    }

    /**
     * @param string $buffer
     * @param TcpConnection $connection
     * @return array
     */
    public static function decode($buffer, TcpConnection $connection)
    {
        $offset = 0;
        $stdout = $stderr = '';

        do {
            $header_buffer = \substr($buffer, $offset, static::FCGI_HEADER_LEN);
            $data = \unpack("Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved", $header_buffer);

            if (false === $data) {
                $stderr = "Не удалось распаковывать данные заголовка из двоичного буфера";
                Server::safeEcho($stderr);
                $connection->close();
                break;
            }

            $chunk_len = static::FCGI_HEADER_LEN + $data['contentLength'] + $data['paddingLength'];
            $body_buffer = \substr($buffer, $offset + static::FCGI_HEADER_LEN, $chunk_len - static::FCGI_HEADER_LEN);


            switch ($data['type']) {
                case static::FCGI_STDOUT:
                    $payload = \unpack("a{$data['contentLength']}contentData/a{$data['paddingLength']}paddingData", $body_buffer);
                    $stdout .= $payload['contentData'];
                    break;
                case static::FCGI_STDERR:
                    $payload = \unpack("a{$data['contentLength']}contentData/a{$data['paddingLength']}paddingData", $body_buffer);
                    $stderr .= $payload['contentData'];
                    break;
                case static::FCGI_END_REQUEST:
                    $payload = \unpack("NappStatus/CprotocolStatus/a3reserved", $body_buffer);
                    $result = static::checkProtocolStatus($payload['protocolStatus']);

                    if (0 <> $result['status']) {
                        $stderr = $result['data'];
                        Server::safeEcho($stderr);
                        $connection->close();
                    }
                    break;
                default:
                    //Пока не поддерживается
                    $payload = '';
                    break;
            }

            $offset += $chunk_len;
        } while ($offset < $connection->packetLength);

        // Важно!! Не забываем сбросить на нулевой байт!!
        $connection->packetLength = 0;

        // Сбор ответа
        $response = new Response();
        $output = $response->setRequestId($data['requestId'] ?? -1)
            ->setStdout($stdout)
            ->setStderr($stderr)
            ->formatOutput();

        // onResponse
        if (!empty($connection->onResponse) && is_callable($connection->onResponse)) {
            try {
                \call_user_func($connection->onResponse, $connection, $response);
            } catch (\Exception $e) {
                $data = "Исключение: onResponse: " . $e->getMessage();
                Server::safeEcho($data);
                $connection->close();
            } catch (\Error $e) {
                $data = "Исключение: onResponse: " . $e->getMessage();
                Server::safeEcho($data);
                $connection->close();
            }
        }

        return $output;
    }

    /**
     * @brief   encode package 
     *
     * @param   Request                 $request
     * @param   TcpConnection           $connection
     *
     * @return  string
     */
    public static function encode(Request $request, TcpConnection $connection)
    {
        if (!$request instanceof Request) return '';

        static::$_request = $request;

        $packet = '';
        $packet .= static::createPacket(static::FCGI_BEGIN_REQUEST);
        $packet .= static::createPacket(static::FCGI_PARAMS);
        $packet .= static::createPacket(static::FCGI_PARAMS_END);
        $packet .= static::createPacket(static::FCGI_STDIN);
        $packet .= static::createPacket(static::FCGI_STDIN_END);

        $connection->maxSendBufferSize = TcpConnection::$defaultMaxSendBufferSize * 10;
        $packet_len = \strlen($packet);

        if ($packet_len > $connection->maxSendBufferSize) {
            $data  = "Исключение: ошибка отправки пакета. package_length = {$packet_len} ";
            $data .= "превышает лимит {$connection->maxSendBufferSize}" . PHP_EOL;
            Server::safeEcho($data);
            $connection->close();
            return '';
        }

        return $packet;
    }

    /**
     * @param string $type
     * @return string
     */
    static private function packPayload($type = '')
    {
        $payload = '';

        switch ($type) {
            case static::FCGI_BEGIN_REQUEST:
                $payload = \pack(
                    "nCa5",
                    static::$_request->getRole(),
                    static::$_request->getKeepAlive(),
                    static::FCGI_RESERVED
                );
                break;
            case static::FCGI_PARAMS:
            case static::FCGI_PARAMS_END:
                $payload = '';
                $params = (static::FCGI_PARAMS == $type) ? static::$_request->getParams() : [];
                foreach ($params as $name => $value) {
                    $name_len  = \strlen($name);
                    $value_len = \strlen($value);
                    $format = [
                        $name_len  > 127 ? 'N' : 'C',
                        $value_len > 127 ? 'N' : 'C',
                        "a{$name_len}",
                        "a{$value_len}",
                    ];
                    $format = implode('', $format);
                    $payload .= \pack(
                        $format,
                        $name_len  > 127 ? ($name_len  | 0x80000000) : $name_len,
                        $value_len > 127 ? ($value_len | 0x80000000) : $value_len,
                        $name,
                        $value
                    );
                }
                break;
            case static::FCGI_STDIN:
            case static::FCGI_ABORT_REQUEST:
            case static::FCGI_DATA:
                $payload = \pack("a" . static::$_request->getContentLength(), static::$_request->getContent());
                break;
            case static::FCGI_STDIN_END:
                $payload = '';
                break;
            case static::FCGI_UNKNOWN_TYPE:
                $payload = \pack("Ca7", static::FCGI_UNKNOWN_TYPE, static::FCGI_RESERVED);
                break;
            default:
                $payload = '';
                break;
        }

        return $payload;
    }

    /**
     * @param string $type
     * @return string
     */
    static public function createPacket($type = '')
    {
        $packet = '';
        $offset = 0;
        $payload = static::packPayload($type);
        $total_len = \strlen($payload);

        // Не забываем сбросить псевдо-тип записи на нормальный
        $type == static::FCGI_PARAMS_END && $type = static::FCGI_PARAMS;
        $type == static::FCGI_STDIN_END  && $type = static::FCGI_STDIN;

        // Может быть, нужно разделить полезную нагрузку на множество частей 
        do {
            $chunk = \substr($payload, $offset, static::FCGI_MAX_PAYLOAD_LEN);
            $chunk_len = \strlen($chunk);
            $remainder = \abs($chunk_len % 8);
            $padding_len = $remainder > 0 ? 8 - $remainder : 0;

            $header = \pack(
                "CCnnCC",
                static::FCGI_VERSION_1,
                $type,
                static::$_request->getRequestId(),
                $chunk_len,
                $padding_len,
                static::FCGI_RESERVED
            );

            $padding = \pack("a{$padding_len}", static::FCGI_PADDING);
            $packet .= $header . $chunk . $padding;
            $offset += $chunk_len;
        } while ($offset < $total_len);

        return $packet;
    }

    /**
     * @param int $status
     * @return array
     */
    static public function checkProtocolStatus($status = 0)
    {
        switch ($status) {
            case static::FCGI_REQUEST_COMPLETE:
                $data = 'Принято: Запрос заполнен';
                break;
            case static::FCGI_CANT_MPX_CONN:
                $data = 'Отклонено: Сервер FastCGI не поддерживает одновременную обработку';
                break;
            case static::FCGI_OVERLOADED:
                $data = 'Отклонено: Серверу FastCGI не хватает ресурсов';
                break;
            case static::FCGI_UNKNOWN_ROLE:
                $data = 'Отклонено: Сервер FastCGI не поддерживает указанную роль';
                break;
            default:
                $data = 'Отклонено: Сервер FastCGI не знает, что случилось';
                break;
        }

        return [
            'status' => $status,
            'data'  => $data,
        ];
    }
}
