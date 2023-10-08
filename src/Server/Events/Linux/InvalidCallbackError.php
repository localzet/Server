<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Server\Events\Linux;

use Closure;
use Error;
use localzet\Server\Events\Linux\Internal\ClosureHelper;

/**
 *
 */
final class InvalidCallbackError extends Error
{
    /**
     *
     */
    public const E_NONNULL_RETURN = 1;
    /**
     *
     */
    public const E_INVALID_IDENTIFIER = 2;
    /** @var string */
    private readonly string $rawMessage;
    /** @var string */
    private readonly string $callbackId;
    /** @var array<string, string> */
    private array $info = [];

    /**
     * @param string $callbackId The callback identifier.
     * @param string $message The exception message.
     */
    private function __construct(string $callbackId, int $code, string $message)
    {
        parent::__construct($message, $code);

        $this->callbackId = $callbackId;
        $this->rawMessage = $message;
    }

    /**
     * MUST be thrown if any callback returns a non-null value.
     */
    public static function nonNullReturn(string $callbackId, Closure $closure): self
    {
        return new self(
            $callbackId,
            self::E_NONNULL_RETURN,
            'Non-null return value received from callback ' . ClosureHelper::getDescription($closure)
        );
    }

    /**
     * MUST be thrown if any operation (except disable() and cancel()) is attempted with an invalid callback identifier.
     *
     * An invalid callback identifier is any identifier that is not yet emitted by the driver or cancelled by the user.
     */
    public static function invalidIdentifier(string $callbackId): self
    {
        return new self($callbackId, self::E_INVALID_IDENTIFIER, 'Invalid callback identifier ' . $callbackId);
    }

    /**
     * @return string The callback identifier.
     */
    public function getCallbackId(): string
    {
        return $this->callbackId;
    }

    /**
     * @param string $key
     * @param string $message
     * @return void
     */
    public function addInfo(string $key, string $message): void
    {
        $this->info[$key] = $message;

        $info = '';

        foreach ($this->info as $infoKey => $infoMessage) {
            $info .= "\r\n\r\n" . $infoKey . ': ' . $infoMessage;
        }

        $this->message = $this->rawMessage . $info;
    }
}