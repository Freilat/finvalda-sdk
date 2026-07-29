<?php

declare(strict_types=1);

namespace Finvalda\Support;

/**
 * Substitutes credential values in data destined for logs, debug captures, or
 * recordings. The wire request is never affected.
 */
final class Redactor
{
    /**
     * Header, query, and body keys whose values are substituted.
     */
    public const KEYS = ['Password', 'ConnString', 'sPassword'];

    public const MASK = '***';

    /**
     * Shell variable placeholders, one per credential key. sPassword gets its
     * own name because it is a different secret from the connection password —
     * it is the looked-up user's password in References::user() (GetFvsUser),
     * which travels as a GET query parameter.
     */
    public const PLACEHOLDERS = [
        'Password' => '$FVS_PASSWORD',
        'ConnString' => '$FVS_CONN_STRING',
        'sPassword' => '$FVS_SPASSWORD',
    ];

    /**
     * Mask credential values. Top-level keys only.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function apply(array $values): array
    {
        return self::substitute($values, fn (string $key): string => self::MASK);
    }

    /**
     * Replace credential values with shell variable placeholders, so rendered
     * output stays runnable without printing the secret. Top-level keys only.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function applyPlaceholders(array $values): array
    {
        return self::substitute($values, fn (string $key): string => self::PLACEHOLDERS[$key]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  callable(string): string  $replacement
     * @return array<string, mixed>
     */
    private static function substitute(array $values, callable $replacement): array
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $replacement($key);
            }
        }

        return $values;
    }
}
