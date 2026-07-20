<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Util;

/**
 * Conservative detection of PHP-serialized strings. Used to flag DB values that must go
 * through the serialize-safe URL replacer instead of a plain string replace.
 */
final class SerializedData
{
    /**
     * True if $value looks like the output of serialize(). Deliberately strict: a false
     * negative just routes a value through the plain replacer (still correct for scalars),
     * while a false positive on a real serialized blob is the dangerous case we avoid.
     */
    public static function isSerialized(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        // Fast structural gate: serialized data starts with a type token.
        if (!preg_match('/^(N;|[bidsaO]:)/', $value)) {
            return false;
        }

        if ('N;' === $value) {
            return true;
        }

        // unserialize() with a guard against side effects (no class instantiation).
        set_error_handler(static fn (): bool => true);

        try {
            $result = unserialize($value, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        // unserialize returns false on failure; the only legit serialized false is "b:0;".
        return false !== $result || 'b:0;' === $value;
    }
}
