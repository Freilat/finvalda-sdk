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
 * with jq. Every failure is swallowed so a logging problem can never break an
 * API call, but the first failure on each instance is reported through the PHP
 * error log so a bad path does not fail silently.
 */
final class JsonLinesLogger extends AbstractLogger
{
    /**
     * Entry keys the logger owns. A context key of the same name is written
     * prefixed with `context_` rather than silently dropped.
     */
    private const RESERVED_KEYS = ['ts', 'pid', 'level', 'message'];

    private bool $reportedFailure = false;

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
                'level' => match (true) {
                    is_string($level) => $level,
                    is_scalar($level), $level instanceof Stringable => (string) $level,
                    default => get_debug_type($level),
                },
                'message' => (string) $message,
            ];

            foreach ($this->truncate($context) as $key => $value) {
                $entry[in_array($key, self::RESERVED_KEYS, true) ? "context_{$key}" : $key] = $value;
            }

            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($line === false) {
                $this->reportFailure('could not encode a record as JSON: ' . json_last_error_msg());

                return;
            }

            $directory = dirname($this->path);

            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            // Entries carry whole request and response bodies — client names,
            // debts, invoice contents — so the file this class creates must not
            // be world-readable. Only on creation: an operator who tightened an
            // existing file keeps their permissions.
            $created = ! is_file($this->path);

            if (@file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
                $this->reportFailure('could not write to the log file');
            } elseif ($created) {
                @chmod($this->path, 0640);
            }
        } catch (Throwable $e) {
            $this->reportFailure($e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Surface the first failure on this instance through the PHP error log:
     * a sink that dies silently is undiagnosable. Later failures stay quiet so
     * a broken path cannot flood the error log from a fleet of cron jobs.
     */
    private function reportFailure(string $reason): void
    {
        if ($this->reportedFailure) {
            return;
        }

        $this->reportedFailure = true;

        error_log("Finvalda JsonLinesLogger: {$reason} (path: {$this->path}). Further failures from this instance are silent.");
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
