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

class Ja3
{
    static array $GREASE_TABELE = [
        2570, //(0x0A0A)
        6682, //(0x1A1A)
        10794, //(0x2A2A)
        14906, //(0x3A3A)
        19018, //(0x4A4A)
        23130, //(0x5A5A)
        27242, //(0x6A6A)
        31354, //(0x7A7A)
        35466, //(0x8A8A)
        39578, //(0x9A9A)
        43690, //(0xAAAA)
        47802, //(0xBABA)
        51914, //(0xCACA)
        56026, //(0xDADA)
        60138, //(0xEAEA)
        64250, //(0xFAFA)
    ];

    public static function get($tcp_data): false|array
    {

        $BinaryStream = new BinaryStream();
        $BinaryStream->setContent($tcp_data);
        $ret = [];
        $ja3_arr = [];
        $ret += $BinaryStream->unpack([
            'tls.record.type' => BinaryStream::uint8,
        ]);
        //    echo $ret['tls.record.type'].PHP_EOL;
        if ($ret['tls.record.type'] == 22) {

            $ret += $BinaryStream->unpack([
                'tls.record.version' => BinaryStream::uint16,
                'tls.record.length' => BinaryStream::uint16,
                'tls.handshake.type' => BinaryStream::uint8,
                'tls.handshake.length' => [BinaryStream::uint8, 3],
                'tls.handshake.version' => BinaryStream::uint16,
                'tls.handshake.random' => [BinaryStream::uint8, 32],
                'tls.handshake.session_id_length' => BinaryStream::uint8,
            ]);
            if ($ret['tls.handshake.session_id_length'] > 0) {
                $ret += $BinaryStream->unpack([
                    'tls.handshake.session_id' => [BinaryStream::uint8, $ret['tls.handshake.session_id_length']],
                ]);
            }
            //client hello
            if ($ret['tls.handshake.type'] == 1) {
                $ret += $BinaryStream->unpack([
                    'tls.handshake.cipher_suites_length' => BinaryStream::uint16,
                ]);
                $ciphersuites_count = $ret['tls.handshake.cipher_suites_length'] / 2;
                $ret += $BinaryStream->unpack([
                    'tls.handshake.ciphersuites' => [BinaryStream::uint16, $ciphersuites_count],
                    'tls.handshake.compression_methods_length' => BinaryStream::uint8,
                    'tls.handshake.compression_methods' => BinaryStream::uint8,
                    'tls.handshake.extensions_length' => BinaryStream::uint16,
                ]);
            } else {
                if ($ret['tls.handshake.type'] == 2) {
                    // server hello
                    $ret += $BinaryStream->unpack([
                        'tls.handshake.ciphersuites' => [BinaryStream::uint16, 1],
                        'tls.handshake.compression_methods' => BinaryStream::uint8,
                        'tls.handshake.extensions_length' => BinaryStream::uint16,
                    ]);
                } else {
                    return false;
                }
            }


            $extensions_length = $ret['tls.handshake.extensions_length'];
            $extensions = [];
            $j = 0;

            for ($i = 0; $i < $extensions_length; $j++) {
                $extensions[$j] = $BinaryStream->unpack([
                    'type' => BinaryStream::uint16,
                    'length' => BinaryStream::uint16,
                ]);

                $offset = $BinaryStream->getOffset();
                $extensions[$j]['offset'] = $offset;
                $length = $extensions[$j]['length'];
                $i += 4 + $length;
                $BinaryStream->setOffset($offset + $length);
            }


            $extensions_type_arr = array_column($extensions, 'type');
            $extensions_arr_by_type = array_column($extensions, null, 'type');
            $EllipticCurve = [];

            //Type: supported_groups (10)
            if (!empty($extensions_arr_by_type['10'])) {
                $length = $extensions_arr_by_type['10']['length'];
                $offset = $extensions_arr_by_type['10']['offset'];
                if ($length >= 4) {
                    $cnt = $length / 2 - 1;
                    $BinaryStream->setOffset($offset + 2);
                    $EllipticCurve_ret = $BinaryStream->unpack([
                        'supported_groups_data' => [BinaryStream::uint16, $cnt],
                    ]);

                    $EllipticCurve = $EllipticCurve_ret['supported_groups_data'];
                }
            }
            $EllipticCurvePointFormat = [];
            //Type: ec_point_formats (11)
            if (!empty($extensions_arr_by_type['11'])) {
                $length = $extensions_arr_by_type['11']['length'];
                $offset = $extensions_arr_by_type['11']['offset'];
                if ($length >= 2) {
                    $cnt = $length - 1;
                    $BinaryStream->setOffset($offset + 1);
                    $EllipticCurvePointFormat_data = $BinaryStream->unpack([
                        'ec_point_formats_data' => [BinaryStream::uint8, $cnt],
                    ]);

                    $EllipticCurvePointFormat = $EllipticCurvePointFormat_data['ec_point_formats_data'];
                }
            }
            ////      get $server_name
            //        $server_name = '';
            //        if(!empty($extensions_arr_by_type['0'])){
            //            $length = $extensions_arr_by_type['0']['length'];
            //            $offset = $extensions_arr_by_type['0']['offset'];
            //            if($length>=2){
            //                $cnt = $length -1;
            //                $BinaryStream->setOffset($offset);
            //                $server_name_data = $BinaryStream->unpack([
            //                    'server_name_list'=>BinaryStream::uint16,
            //                    'server_name_type'=>BinaryStream::uint8,
            //                    'server_name_length'=>BinaryStream::uint16,
            //                ]);
            //                $server_name_data += $BinaryStream->unpack([
            //                    'server_name'=>[BinaryStream::char,$server_name_data['server_name_length']],
            //                ]);
            //
            //                $server_name = $server_name_data['server_name'];
            ////                var_dump(__FILE__.' line:'.__LINE__,$server_name_data['server_name_length'],$server_name);
            //            }
            //        }

            ////         get $session_id
            //        $session_id = [];
            //        if(!empty($ret['tls.handshake.session_id'])){
            //            array_walk($ret['tls.handshake.session_id'],function(&$v){
            //                $v = sprintf('%02x',$v);
            //
            //            });
            //            $session_id = $ret['tls.handshake.session_id'];
            //        }

            //$ja3_arr The field order is as follows
            //SSLVersion,Cipher,SSLExtension,EllipticCurve,EllipticCurvePointFormat
            $ja3_arr[] = $ret['tls.handshake.version'];
            $ja3_arr[] = empty($ret['tls.handshake.ciphersuites']) ? '' : implode(
                '-',
                $ret['tls.handshake.ciphersuites'] = array_diff(
                    $ret['tls.handshake.ciphersuites'],
                    self::$GREASE_TABELE
                )
            );
            $ja3_arr[] = empty($extensions_type_arr) ? '' : implode(
                '-',
                array_diff($extensions_type_arr, self::$GREASE_TABELE)
            );
            if ($ret['tls.handshake.type'] == 1) {

                $ja3_arr[] = empty($EllipticCurve) ? '' : implode(
                    '-',
                    array_diff($EllipticCurve, self::$GREASE_TABELE)
                );
                $ja3_arr[] = empty($EllipticCurvePointFormat) ? '' : implode(
                    '-',
                    array_diff($EllipticCurvePointFormat, self::$GREASE_TABELE)
                );
                $ja3_str = implode(',', $ja3_arr);

                return [
                    'tls.record.type' => $ret['tls.record.type'],
                    'tls.handshake.type' => $ret['tls.handshake.type'],
                    'ja3_str' => $ja3_str,
                    'ja3' => md5($ja3_str),
                    'session_ticket' => !empty($extensions_arr_by_type['35']) && $extensions_arr_by_type['35']['length'] > 0,
                    // 'session_id'=>trim(implode('',$session_id)),
                    // 'server_name'=>trim($server_name),
                ];
            } else {
                $ja3s_str = implode(',', $ja3_arr);

                return [
                    'tls.record.type' => $ret['tls.record.type'],
                    'tls.handshake.type' => $ret['tls.handshake.type'],
                    'ja3s_str' => $ja3s_str,
                    'ja3s' => md5($ja3s_str),
                    // 'session_id'=>trim(implode('',$session_id)),
                ];
            }
        } else {
            return false;
        }

        return false;
    }
}
