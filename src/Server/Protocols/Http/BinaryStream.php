<?php

declare(strict_types=1);

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

namespace localzet\Server\Protocols\Http;

use Exception;

/**
 *
 */
class BinaryStream
{
    /**
     *
     */
    const uint8 = 1;
    /**
     *
     */
    const  int8 = 2;
    /**
     *
     */
    const uint16 = 3;
    /**
     *
     */
    const  int16 = 4;
    /**
     *
     */
    const uint32 = 5;
    /**
     *
     */
    const  int32 = 6;
    /**
     *
     */
    const shortFrac = 7;
    /**
     *
     */
    const Fixed = 8;
    /**
     *
     */
    const  FWord = 9;
    /**
     *
     */
    const uFWord = 10;
    /**
     *
     */
    const F2Dot14 = 11;
    /**
     *
     */
    const longDateTime = 12;
    /**
     *
     */
    const char = 13;
    /**
     *
     */
    const modeRead = "rb";
    /**
     *
     */
    const modeWrite = "wb";
    /**
     *
     */
    const modeReadWrite = "rb+";
    /**
     * @var resource The file pointer
     */
    protected $f;
    /**
     * @var
     */
    protected $content;
    /**
     * @var int
     */
    protected int $offset = 0;

    /**
     * @return void
     */
    static function backtrace(): void
    {
        var_dump(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
    }

    /**
     * Create a temporary file in write mode
     *
     * @param bool $allow_memory Allow in-memory files
     *
     * @return resource the temporary file pointer resource
     */
    public static function getTempFile(bool $allow_memory = true)
    {
        $f = null;

        if ($allow_memory) {
            $f = fopen("php://temp", "rb+");
        } else {
            $f = fopen(tempnam(sys_get_temp_dir(), "fnt"), "rb+");
        }

        return $f;
    }

    /**
     * Open a font file in read mode
     *
     * @param string $filename The file name of the font to open
     *
     * @return bool
     * @throws Exception
     */
    public function load(string $filename): bool
    {
        return $this->open($filename, self::modeRead);
    }

    /**
     * Open a font file in a chosen mode
     *
     * @param string $filename The file name of the font to open
     * @param string $mode The opening mode
     *
     * @return bool
     * @throws Exception
     */
    public function open(string $filename, string $mode = self::modeRead): bool
    {
        if (!in_array($mode, array(self::modeRead, self::modeWrite, self::modeReadWrite))) {
            throw new Exception("Unknown file open mode");
        }

        $this->f = fopen($filename, $mode);

        return $this->f != false;
    }

    /**
     * Close the internal file pointer
     */
    public function close(): bool
    {
        return fclose($this->f) != false;
    }

    /**
     * Change the internal file pointer
     *
     * @param resource $fp
     *
     * @throws Exception
     */
    public function setFile($fp): void
    {
        if (!is_resource($fp)) {
            throw new Exception('$fp is not a valid resource');
        }

        $this->f = $fp;
    }

    /**
     * Move the internal file pinter to $offset bytes
     *
     * @param int $offset
     *
     * @return bool True if the $offset position exists in the file
     */
    public function seek(int $offset): bool
    {
        return fseek($this->f, $offset, SEEK_SET) == 0;
    }

    /**
     * Gives the current position in the file
     *
     * @return int The current position
     */
    public function pos(): int
    {
        return ftell($this->f);
    }

    /**
     * @param $n
     * @return void
     */
    public function skip($n): void
    {
        fseek($this->f, $n, SEEK_CUR);
    }

    /**
     * @return mixed
     */
    public function readUFWord(): mixed
    {
        return $this->readUInt16();
    }

    /**
     * @return mixed
     */
    public function readUInt16(): mixed
    {
        $a = unpack("nn", $this->read(2));

        return $a["n"];
    }

    /**
     * @param int $n The number of bytes to read
     *
     * @return string
     */
    public function read(int $n): string
    {
        if ($n < 1) {
            return "";
        }

        //        return (string) fread($this->f, $n);
        $offset = $this->offset;
        $this->offset += $n;
        return substr($this->content, $offset, $n);
    }

    /**
     * @param $data
     * @return false|int
     */
    public function writeUFWord($data): false|int
    {
        return $this->writeUInt16($data);
    }

    /**
     * @param $data
     * @return false|int
     */
    public function writeUInt16($data): false|int
    {
        return $this->write(pack("n", $data), 2);
    }

    /**
     * @param $data
     * @param $length
     * @return false|int
     */
    public function write($data, $length = null): false|int
    {
        if ($data === null || $data === "" || $data === false) {
            return 0;
        }

        return fwrite($this->f, $data, $length);
    }

    /**
     * @return int|mixed
     */
    public function readFWord(): mixed
    {
        return $this->readInt16();
    }

    /**
     * @return int|mixed
     */
    public function readInt16(): mixed
    {
        $a = unpack("nn", $this->read(2));
        $v = $a["n"];

        if ($v >= 0x8000) {
            $v -= 0x10000;
        }

        return $v;
    }

    /**
     * @param $data
     * @return false|int
     */
    public function writeFWord($data): false|int
    {
        return $this->writeInt16($data);
    }

    /**
     * @param $data
     * @return false|int
     */
    public function writeInt16($data): false|int
    {
        if ($data < 0) {
            $data += 0x10000;
        }

        return $this->writeUInt16($data);
    }

    /**
     * @param $def
     * @return array
     */
    public function unpack($def): array
    {
        $d = array();
        foreach ($def as $name => $type) {
            $d[$name] = $this->r($type);
        }

        return $d;
    }

    /**
     * Read a data of type $type in the file from the current position
     *
     * @param mixed $type The data type to read
     *
     * @return mixed The data that was read
     */
    public function r(mixed $type): mixed
    {
        switch ($type) {
            case self::uint8:
                return $this->readUInt8();
            case self::int8:
                return $this->readInt8();
            case self::uFWord:
            case self::uint16:
                return $this->readUInt16();
            case self::FWord:
            case self::F2Dot14:
            case self::int16:
                return $this->readInt16();
            case self::int32:
            case self::uint32:
                return $this->readUInt32();
            case self::Fixed:
            case self::shortFrac:
                return $this->readFixed();
            case self::longDateTime:
                return $this->readLongDateTime();
            case self::char:
                return $this->read(1);
            default:
                if (is_array($type)) {
                    if ($type[0] == self::char) {
                        return $this->read($type[1]);
                    }
                    if ($type[0] == self::uint16) {
                        return $this->readUInt16Many($type[1]);
                    }
                    if ($type[0] == self::int16) {
                        return $this->readInt16Many($type[1]);
                    }
                    if ($type[0] == self::uint8) {
                        return $this->readUInt8Many($type[1]);
                    }
                    if ($type[0] == self::int8) {
                        return $this->readInt8Many($type[1]);
                    }

                    $ret = array();
                    for ($i = 0; $i < $type[1]; $i++) {
                        $ret[] = $this->r($type[0]);
                    }

                    return $ret;
                }

                return null;
        }
    }

    /**
     * @return int
     */
    public function readUInt8(): int
    {
        return ord($this->read(1));
    }

    /**
     * @return int
     */
    public function readInt8(): int
    {
        $v = $this->readUInt8();

        if ($v >= 0x80) {
            $v -= 0x100;
        }

        return $v;
    }

    /**
     * @return mixed
     */
    public function readUInt32(): mixed
    {
        $a = unpack("NN", $this->read(4));

        return $a["N"];
    }

    /**
     * @return float
     */
    public function readFixed(): float
    {
        $d = $this->readInt16();
        $d2 = $this->readUInt16();

        return round($d + $d2 / 0x10000, 4);
    }

    /**
     * @return string
     */
    public function readLongDateTime(): string
    {
        $this->readUInt32(); // ignored
        $date = $this->readUInt32() - 2082844800;

        # PHP_INT_MIN isn't defined in PHP < 7.0
        $php_int_min = defined("PHP_INT_MIN") ? PHP_INT_MIN : ~PHP_INT_MAX;

        if (is_string($date) || $date > PHP_INT_MAX || $date < $php_int_min) {
            $date = 0;
        }

        return date("Y-m-d H:i:s", $date);
    }

    /**
     * @param $count
     * @return array|false
     */
    public function readUInt16Many($count): false|array
    {
        return array_values(unpack("n*", $this->read($count * 2)));
    }

    /**
     * @param $count
     * @return array|false
     */
    public function readInt16Many($count): false|array
    {
        $vals = array_values(unpack("n*", $this->read($count * 2)));
        foreach ($vals as &$v) {
            if ($v >= 0x8000) {
                $v -= 0x10000;
            }
        }

        return $vals;
    }

    /**
     * @param $count
     * @return array|false
     */
    public function readUInt8Many($count): false|array
    {
        return array_values(unpack("C*", $this->read($count)));
    }

    /**
     * @param $count
     * @return array|false
     */
    public function readInt8Many($count): false|array
    {
        return array_values(unpack("c*", $this->read($count)));
    }

    /**
     * @param $def
     * @param $data
     * @return int|null
     */
    public function pack($def, $data): ?int
    {
        $bytes = 0;
        foreach ($def as $name => $type) {
            $bytes += $this->w($type, $data[$name]);
        }

        return $bytes;
    }

    /**
     * Write $data of type $type in the file from the current position
     *
     * @param mixed $type The data type to write
     * @param mixed $data The data to write
     *
     * @return false|int|null The number of bytes read
     */
    public function w(mixed $type, mixed $data): false|int|null
    {
        switch ($type) {
            case self::uint8:
                return $this->writeUInt8($data);
            case self::int8:
                return $this->writeInt8($data);
            case self::uFWord:
            case self::uint16:
                return $this->writeUInt16($data);
            case self::F2Dot14:
            case self::FWord:
            case self::int16:
                return $this->writeInt16($data);
            case self::int32:
            case self::uint32:
                return $this->writeUInt32($data);
            case self::Fixed:
            case self::shortFrac:
                return $this->writeFixed($data);
            case self::longDateTime:
                return $this->writeLongDateTime($data);
            case self::char:
                return $this->write($data, 1);
            default:
                if (is_array($type)) {
                    if ($type[0] == self::char) {
                        return $this->write($data, $type[1]);
                    }

                    $ret = 0;
                    for ($i = 0; $i < $type[1]; $i++) {
                        if (isset($data[$i])) {
                            $ret += $this->w($type[0], $data[$i]);
                        }
                    }

                    return $ret;
                }

                return null;
        }
    }

    /**
     * @param $data
     * @return false|int
     */
    public function writeUInt8($data): false|int
    {
        return $this->write(chr($data), 1);
    }

    /**
     * @param $data
     * @return false|int
     */
    public function writeInt8($data): false|int
    {
        if ($data < 0) {
            $data += 0x100;
        }

        return $this->writeUInt8($data);
    }

    /**
     * @param $data
     * @return false|int
     */
    public function writeUInt32($data): false|int
    {
        return $this->write(pack("N", $data), 4);
    }

    /**
     * @param $data
     * @return int
     */
    public function writeFixed($data): int
    {
        $left = floor($data);
        $right = ($data - $left) * 0x10000;

        return $this->writeInt16($left) + $this->writeUInt16($right);
    }

    /**
     * @param $data
     * @return int
     */
    public function writeLongDateTime($data): int
    {
        $date = strtotime($data);
        $date += 2082844800;

        return $this->writeUInt32(0) + $this->writeUInt32($date);
    }

    /**
     * Converts a Uint32 value to string
     *
     * @param int $uint32
     *
     * @return string The string
     */
    public function convertUInt32ToStr(int $uint32): string
    {
        return chr(($uint32 >> 24) & 0xFF) . chr(($uint32 >> 16) & 0xFF) . chr(($uint32 >> 8) & 0xFF) . chr($uint32 & 0xFF);
    }

    /**
     * @param mixed $content
     * @return BinaryStream
     */
    public function setContent(mixed $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @return int
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * @param mixed $offset
     * @return BinaryStream
     */
    public function setOffset(mixed $offset): static
    {
        $this->offset = $offset;

        return $this;
    }
}
