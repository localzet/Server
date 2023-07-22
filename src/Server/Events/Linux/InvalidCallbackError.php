<?php

declare(strict_types=1);

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