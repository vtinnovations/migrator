<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Transfer;

/**
 * Raised when a signed exchange response (activation / refresh / push) fails structural or
 * cryptographic verification. The {@see self::category()} is a safe, non-sensitive slug for
 * internal logs and administrator diagnostics (e.g. `signing_key_store_empty`, `md5_mismatch`);
 * it must never become a bypass. The message stays generic and never contains packet material.
 */
final class ExchangeException extends \RuntimeException
{
    public function __construct(private readonly string $category, string $message = '')
    {
        parent::__construct('' !== $message ? $message : $category);
    }

    public function category(): string
    {
        return $this->category;
    }
}
