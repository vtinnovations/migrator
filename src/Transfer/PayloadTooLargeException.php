<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Transfer;

/**
 * Thrown when the destination answers HTTP 413 to a chunk POST — the body exceeded the remote
 * web server's limit (nginx client_max_body_size / PHP post_max_size). The push step catches
 * this specifically to shrink the chunk size and retry, rather than failing the whole transfer.
 */
final class PayloadTooLargeException extends \RuntimeException
{
}
