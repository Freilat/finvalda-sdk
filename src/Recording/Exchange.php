<?php

declare(strict_types=1);

namespace Finvalda\Recording;

use Finvalda\Enums\CredentialMode;
use Finvalda\Support\Redactor;
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

    /**
     * A curl command reproducing this request. The body is byte-exact (not
     * pretty-printed); masked credentials must be substituted before running it.
     */
    public function toCurl(): string
    {
        $parts = ["curl -X {$this->method} " . $this->quote($this->url)];

        $headers = $this->headers;

        if ($this->body !== null && $this->body !== '' && ! isset($headers['Content-Type'])) {
            // Guzzle sets this for JSON bodies; buildHeaders() does not.
            $headers['Content-Type'] = 'application/json';
        }

        foreach ($headers as $name => $value) {
            $parts[] = '  -H ' . $this->quote("{$name}: {$value}");
        }

        if ($this->body !== null && $this->body !== '') {
            $parts[] = '  -d ' . $this->quote($this->body);
        }

        return implode(" \\\n", $parts);
    }

    /**
     * A copy whose headers, URL query, and JSON body carry credential values
     * substituted per the given mode. The URL and body are returned unchanged
     * when they carry no credentials.
     *
     * Fields that are not structured — the transport/HTTP error message, the
     * response body, and response header values — are scrubbed by value: the
     * real credential values are collected from this (still unsubstituted)
     * object and replaced with the same text the mode uses. Guzzle embeds the
     * request URI in its exception messages, so `error` would otherwise carry a
     * verbatim `sPassword` query value; a server echoing a credential back
     * would leak it the same way.
     */
    public function withCredentials(CredentialMode $mode): self
    {
        if ($mode === CredentialMode::Real) {
            return $this;
        }

        /** @var array<string, string> $headers */
        $headers = $this->substitute($this->headers, $mode);

        $secrets = $this->credentialValues($mode);

        return new self(
            method: $this->method,
            url: $this->substituteUrl($this->url, $mode),
            headers: $headers,
            body: $this->scrub($this->substituteBody($this->body, $mode), $secrets),
            statusCode: $this->statusCode,
            reasonPhrase: $this->reasonPhrase,
            responseHeaders: $this->scrubHeaders($this->responseHeaders, $secrets),
            responseBody: $this->scrub($this->responseBody, $secrets),
            durationMs: $this->durationMs,
            error: $this->scrub($this->error, $secrets),
            attempt: $this->attempt,
        );
    }

    /**
     * Real credential values carried by this exchange, mapped to their
     * replacement text under the given mode. Collected from the request
     * headers, the URL query, and a JSON request body — the three places the
     * SDK puts a credential.
     *
     * Each value is registered together with the encoded forms it can appear in
     * downstream: Guzzle embeds the percent-encoded query string in its
     * exception messages, and a JSON response body carries the JSON-escaped
     * form. Without those, a password holding any character outside the
     * unreserved set survives in `error` (recoverable with one `urldecode()`).
     *
     * Longest values first, so replacing one that is a prefix of another cannot
     * leave a fragment behind. Empty values are skipped so nothing ever
     * replaces ''.
     *
     * @return array<string, string>
     */
    private function credentialValues(CredentialMode $mode): array
    {
        $sources = [$this->headers];

        $query = parse_url($this->url, PHP_URL_QUERY);

        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            $sources[] = $params;
        }

        if ($this->body !== null) {
            $decoded = json_decode($this->body, true);

            if (is_array($decoded)) {
                $sources[] = $decoded;
            }
        }

        $secrets = [];

        foreach (Redactor::KEYS as $key) {
            foreach ($sources as $source) {
                $value = $source[$key] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $replacement = $this->replacementFor($key, $mode);

                foreach ($this->encodedVariants($value) as $variant) {
                    $secrets[$variant] = $replacement;
                }
            }
        }

        uksort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    /**
     * A credential value plus every encoded form it can reach a recorded field
     * in: percent-encoded (`rawurlencode` and `urlencode` differ for spaces —
     * `%20` vs `+`) and JSON-escaped, both with PHP's default escaping and with
     * slashes and unicode left alone, since the server chooses its own flags.
     * Duplicates and empty results are dropped.
     *
     * @return list<string>
     */
    private function encodedVariants(string $value): array
    {
        $variants = [$value, rawurlencode($value), urlencode($value)];

        foreach ([0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
            $encoded = json_encode($value, $flags);

            // Strip the surrounding quotes json_encode adds; substr, not trim(),
            // so a value whose escaped form ends in \" keeps its backslash.
            if (is_string($encoded) && strlen($encoded) > 2) {
                $variants[] = substr($encoded, 1, -1);
            }
        }

        return array_values(array_unique(array_filter($variants, fn (string $v): bool => $v !== '')));
    }

    /**
     * @param  array<string, string>  $secrets
     */
    private function scrub(?string $value, array $secrets): ?string
    {
        if ($value === null || $secrets === []) {
            return $value;
        }

        return str_replace(array_keys($secrets), array_values($secrets), $value);
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  array<string, string>  $secrets
     * @return array<string, list<string>>
     */
    private function scrubHeaders(array $headers, array $secrets): array
    {
        if ($secrets === []) {
            return $headers;
        }

        return array_map(
            fn (array $values): array => array_map(
                fn (string $value): string => (string) $this->scrub($value, $secrets),
                $values,
            ),
            $headers,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function substitute(array $values, CredentialMode $mode): array
    {
        return $mode === CredentialMode::Env
            ? Redactor::applyPlaceholders($values)
            : Redactor::apply($values);
    }

    private function substituteUrl(string $url, CredentialMode $mode): string
    {
        return $this->substituteQuery($this->substituteUserInfo($url, $mode), $mode);
    }

    /**
     * Substitute a userinfo password (`https://user:pass@host/...`, which Guzzle
     * honours as Basic auth) in place, leaving the rest of the URL untouched.
     */
    private function substituteUserInfo(string $url, CredentialMode $mode): string
    {
        $password = parse_url($url, PHP_URL_PASS);

        if (! is_string($password) || $password === '') {
            return $url;
        }

        $schemeEnd = strpos($url, '://');
        $authorityStart = $schemeEnd === false ? 0 : $schemeEnd + 3;
        $authorityLength = strcspn($url, '/?#', $authorityStart);
        $authority = substr($url, $authorityStart, $authorityLength);

        $at = strrpos($authority, '@');

        if ($at === false) {
            return $url;
        }

        $colon = strpos(substr($authority, 0, $at), ':');

        if ($colon === false) {
            return $url;
        }

        $start = $authorityStart + $colon + 1;

        return substr_replace($url, $this->replacementFor('Password', $mode), $start, $at - $colon - 1);
    }

    /**
     * Substitute credential values in the query string, rebuilt so a
     * substituted value stays literal: `***` remains readable and
     * `$FVS_SPASSWORD` remains shell-expandable, neither becoming
     * `%2A%2A%2A` / `%24FVS_SPASSWORD`. Non-credential parameters are
     * re-encoded exactly as HttpClient::recordedUrl() encodes them
     * (RFC 3986, matching Guzzle), so they stay byte-identical to the wire.
     */
    private function substituteQuery(string $url, CredentialMode $mode): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $url;
        }

        $params = [];
        parse_str($query, $params);
        $substituted = $this->substitute($params, $mode);

        if ($substituted === $params) {
            return $url;
        }

        $offset = (int) strpos($url, '?');

        return substr_replace($url, $this->buildQuery($substituted), $offset + 1, strlen($query));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function buildQuery(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            if (is_string($value) && in_array((string) $key, Redactor::KEYS, true)) {
                // Already substituted: emit the mask/placeholder verbatim.
                $pairs[] = rawurlencode((string) $key) . '=' . $value;

                continue;
            }

            // Handles scalars, arrays, and empty values exactly as Guzzle would.
            $pair = http_build_query([$key => $value], '', '&', PHP_QUERY_RFC3986);

            if ($pair !== '') {
                $pairs[] = $pair;
            }
        }

        return implode('&', $pairs);
    }

    private function replacementFor(string $key, CredentialMode $mode): string
    {
        return $mode === CredentialMode::Env
            ? Redactor::PLACEHOLDERS[$key]
            : Redactor::MASK;
    }

    private function substituteBody(?string $body, CredentialMode $mode): ?string
    {
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        $substituted = $this->substitute($decoded, $mode);

        if ($substituted === $decoded) {
            return $body;
        }

        return json_encode($substituted) ?: $body;
    }

    /**
     * Quote a value for a POSIX shell. Literal chunks are single-quoted and
     * $FVS_* placeholders double-quoted, then concatenated, so a placeholder
     * expands even when it sits inside a JSON body:
     * '{"sPassword":"'"$FVS_SPASSWORD"'"}'
     */
    private function quote(string $value): string
    {
        $parts = preg_split(
            '/(\$FVS_[A-Z_]+)/',
            $value,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        if ($parts === false || $parts === []) {
            return $this->singleQuote($value);
        }

        $quoted = '';

        foreach ($parts as $part) {
            $quoted .= str_starts_with($part, '$FVS_')
                ? '"' . $part . '"'
                : $this->singleQuote($part);
        }

        return $quoted;
    }

    private function singleQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
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
