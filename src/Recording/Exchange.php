<?php

declare(strict_types=1);

namespace Finvalda\Recording;

use Stringable;

/**
 * One recorded request attempt and its outcome.
 *
 * Renders itself either as readable HTTP text (default, pretty-printed JSON
 * with the embedded xmlstring payload expanded) or as a curl command.
 */
final class Exchange implements Stringable
{
    private const PRETTY_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param  array<string, string>  $headers  Request headers, credentials already masked unless captured deliberately
     * @param  array<string, list<string>>  $responseHeaders
     * @param  string|null  $error  Transport error message, or the exception message for an HTTP error status
     * @param  int  $attempt  1-based retry attempt number
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly ?int $statusCode,
        public readonly ?string $reasonPhrase,
        public readonly array $responseHeaders,
        public readonly ?string $responseBody,
        public readonly float $durationMs,
        public readonly ?string $error = null,
        public readonly int $attempt = 1,
    ) {}

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Readable HTTP text: request line, headers, pretty body, status, pretty
     * response body.
     */
    public function toString(): string
    {
        $lines = ["{$this->method} {$this->url}"];

        foreach ($this->headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        if ($this->body !== null && $this->body !== '') {
            $lines[] = '';
            $lines[] = $this->prettyBody($this->body);
        }

        $lines[] = '';
        $lines[] = $this->statusLine();

        if ($this->responseBody !== null && $this->responseBody !== '') {
            $lines[] = $this->prettyBody($this->responseBody);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{
     *     request: array{method: string, url: string, headers: array<string, string>, body: string|null},
     *     response: array{status_code: int|null, headers: array<string, list<string>>, body: string|null, duration_ms: float, error: string|null},
     *     attempt: int
     * }
     */
    public function toArray(): array
    {
        return [
            'request' => [
                'method' => $this->method,
                'url' => $this->url,
                'headers' => $this->headers,
                'body' => $this->body,
            ],
            'response' => [
                'status_code' => $this->statusCode,
                'headers' => $this->responseHeaders,
                'body' => $this->responseBody,
                'duration_ms' => round($this->durationMs, 2),
                'error' => $this->error,
            ],
            'attempt' => $this->attempt,
        ];
    }

    private function statusLine(): string
    {
        $duration = round($this->durationMs, 1);

        if ($this->statusCode === null) {
            return "--- ERROR: {$this->error} ({$duration} ms) ---";
        }

        $status = trim("{$this->statusCode} {$this->reasonPhrase}");

        return "--- {$status} ({$duration} ms) ---";
    }

    /**
     * Pretty-print a JSON body, expanding an embedded JSON payload carried in
     * the API's xmlstring field. Non-JSON bodies (the server answers XML on
     * some endpoints) pass through verbatim.
     */
    private function prettyBody(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        if (isset($decoded['xmlstring']) && is_string($decoded['xmlstring'])) {
            $payload = json_decode($decoded['xmlstring'], true);

            if (is_array($payload)) {
                $decoded['xmlstring'] = $payload;
            }
        }

        return json_encode($decoded, self::PRETTY_FLAGS) ?: $body;
    }
}
