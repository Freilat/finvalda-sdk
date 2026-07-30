<?php

declare(strict_types=1);

namespace Finvalda\Logging;

use DateTimeImmutable;
use Finvalda\Support\BodyTruncator;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * Appends one JSON object per line to a file, so SDK log records stay greppable
 * with `jq` without pulling in a logging framework. Context keys are merged into
 * the entry alongside `ts`, `pid`, `level` and `message`; a colliding context key
 * is written prefixed with `context_` rather than silently dropped.
 *
 * Credentials are NOT redacted here: HttpClient has already applied Redactor to
 * the records it emits. Do not add a second redaction pass — it would double-mask.
 *
 * No rotation, no buffering, no minimum level: rotate with logrotate, and filter
 * with jq. Every failure is swallowed — a logging problem must never break an
 * API call — which also means a bad path fails silently.
 */
final class JsonLinesLogger extends AbstractLogger
{
    /**
     * Entry keys the logger owns. A context key of the same name is written
     * prefixed with `context_` rather than silently dropped.
     */
    private const RESERVED_KEYS = ['ts', 'pid', 'level', 'message'];

    /**
     * @param  string  $path  Log file; missing directories are created
     * @param  int  $maxBodyBytes  Byte cap per context string. Deliberately above
     *                             the SDK's own 100 KB body cap so records the SDK
     *                             already truncated are not marked a second time.
     */
    public function __construct(
        private readonly string $path,
        private readonly int $maxBodyBytes = 200_000,
    ) {}

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        try {
            $entry = [
                'ts' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
                'pid' => getmypid(),
                'level' => is_scalar($level) ? (string) $level : gettype($level),
                'message' => (string) $message,
            ];

            foreach ($this->truncate($context) as $key => $value) {
                $entry[in_array($key, self::RESERVED_KEYS, true) ? "context_{$key}" : $key] = $value;
            }

            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($line === false) {
                return;
            }

            $directory = dirname($this->path);

            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Nothing left to do: reporting a logging failure needs a logger.
        }
    }

    /**
     * Cap every string in the context, at any depth — request bodies arrive one
     * level down under `params`.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function truncate(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = BodyTruncator::truncate($value, $this->maxBodyBytes);
            } elseif (is_array($value)) {
                $context[$key] = $this->truncate($value);
            }
        }

        return $context;
    }
}
