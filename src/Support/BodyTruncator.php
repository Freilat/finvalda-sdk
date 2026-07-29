<?php

declare(strict_types=1);

namespace Finvalda\Support;

/**
 * Caps a request/response body to a byte budget, appending a marker naming the
 * number of omitted bytes. Shared by PSR-3 logging and recording so the two
 * cannot drift.
 */
final class BodyTruncator
{
    /**
     * Default byte budget for a captured body.
     */
    public const MAX_BYTES = 100_000;

    public static function truncate(?string $body, int $maxBytes = self::MAX_BYTES): ?string
    {
        if ($body === null || strlen($body) <= $maxBytes) {
            return $body;
        }

        $omitted = strlen($body) - $maxBytes;

        return substr($body, 0, $maxBytes) . "... [truncated {$omitted} bytes]";
    }
}
