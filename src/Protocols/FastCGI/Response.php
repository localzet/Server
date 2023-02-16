<?php

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 * 
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl AGPL-3.0 license
 * 
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *              
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *              
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Server\Protocols\FastCGI;

class Response
{
    /**
     * success status
     *
     * @var int
     */
    const STATUS_OK = 200;

    /**
     * invalid status
     *
     * @var int
     */
    const STATUS_INVALID = -1;

    /**
     * the request id from response
     *
     * @var int
     */
    protected $_requestId;

    /**
     * the stdout from response
     *
     * @var string
     */
    protected $_stdout = '';

    /**
     * the stderr from response 
     *
     * @var string
     */
    protected $_stderr = '';

    /**
     * the origin header from response 
     *
     * @var string
     */
    protected $_header = '';

    /**
     * the origin body from response 
     *
     * @var string
     */
    protected $_body = '';

    /**
     * @brief    __construct    
     *
     * @param    int    $request_id
     *
     * @return   void
     */
    public function __construct($request_id = 0)
    {
        $this->setRequestId($request_id);
    }

    /**
     * @brief    set request id
     *
     * @return   int
     */
    public function setRequestId($id = 0)
    {
        $this->_requestId = (\is_int($id) && $id > 0) ? $id : -1;

        return $this;
    }

    /**
     * @brief    set stdout  
     *
     * @param    string  $stdout
     *
     * @return   object
     */
    public function setStdout($stdout = '')
    {
        if (\is_string($stdout)) {
            $this->_stdout = $stdout;
        }

        return $this;
    }

    /**
     * @brief    get stdout  
     *
     * @return   string
     */
    public function getStdout()
    {
        return $this->_stdout;
    }

    /**
     * @brief    set stderr  
     *
     * @param    string  $stderr
     *
     * @return   object
     */
    public function setStderr($stderr = '')
    {
        if (\is_string($stderr)) {
            $this->_stderr = $stderr;
        }

        return $this;
    }

    /**
     * @brief    get stderr
     *
     * @return   void
     */
    public function getStderr()
    {
        return $this->_stderr;
    }

    /**
     * @brief    get header   
     *
     * @return   string
     */
    public function getHeader()
    {
        return $this->_header;
    }

    /**
     * @brief    get body
     *
     * @return   string
     */
    public function getBody()
    {
        return $this->_body;
    }

    /**
     * @brief    get request id
     *
     * @return   int
     */
    public function getRequestId()
    {
        return $this->_requestId;
    }

    /**
     * @brief    format response output
     *
     * @return   array
     */
    public function formatOutput()
    {
        $status = static::STATUS_INVALID;
        $header = [];
        $body = '';
        $crlf_pos = \strpos($this->getStdout(), "\r\n\r\n");

        if (false !== $crlf_pos) {
            $status = static::STATUS_OK;
            $head = \substr($this->getStdout(), 0, $crlf_pos);
            $body = \substr($this->getStdout(), $crlf_pos + 4);
            $this->_header = \substr($this->getStdout(), 0, $crlf_pos + 4);
            $this->_body = $body;
            $header_lines = \explode(PHP_EOL, $head);

            foreach ($header_lines as $line) {
                if (preg_match('/([\w-]+):\s*(.*)$/', $line, $matches)) {
                    $name  = \trim($matches[1]);
                    $value = \trim($matches[2]);

                    if ('status' === strtolower($name)) {
                        $pos = strpos($value, ' ');
                        $status = false !== $pos ? \substr($value, 0, $pos) : static::STATUS_OK;
                        continue;
                    }

                    if (!array_key_exists($name, $header)) {
                        $header[$name] = $value;
                        continue;
                    }

                    !\is_array($header[$name]) && $header[$name] = [$header[$name]];
                    $header[$name][] = $value;
                }
            }
        }

        $output = [
            'requestId' => $this->getRequestId(),
            'status'    => $status,
            'stderr'    => $this->getStderr(),
            'header'    => $header,
            'body'      => $body,
        ];

        return $output;
    }
}
