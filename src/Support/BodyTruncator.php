<?php

declare(strict_types=1);

namespace Finvalda\Support;

/**
 * Caps a request/response body to a byte budget, appending a marker naming the
 * number of omitted bytes. Shared by PSR-3 logging and recording so the two
 * cannot drift.
 *
 * One thing deliberately does NOT apply to both: FilePayloadElider runs on the
 * logging path only. A log is unbounded, append-only and lives on disk; a
 * recording is capped at record_limit in memory and is explicitly opted into —
 * you reach for record() precisely when you want the bytes. Do not "fix" that
 * asymmetry.
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
