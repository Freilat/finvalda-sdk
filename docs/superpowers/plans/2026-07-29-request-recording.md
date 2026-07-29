# Request/Response Recording Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let SDK users keep a short in-memory history of request/response exchanges, rendered as readable HTTP text by default or as a reproducible `curl` command.

**Architecture:** A new `Finvalda\Recording` namespace holds an immutable `Exchange` value object (one request attempt plus its outcome, able to render itself) and a `Recorder` ring buffer that substitutes credentials at capture time in one of three modes (masked, shell env placeholders, real). `HttpClient` builds an `Exchange` inside its per-attempt closure — on success and on failure — so retries yield one exchange each and failed calls are captured (the existing debug mode captures nothing on failure). Credential masking moves out of `HttpClient` into a shared `Finvalda\Support\Redactor` so log and recording substitution cannot drift.

**Tech Stack:** PHP 8.3, Guzzle 7, PHPUnit 11, PHPStan 2 (level 5). No new dependencies.

**Spec:** `docs/superpowers/specs/2026-07-29-request-recording-design.md`

## Global Constraints

- PHP `^8.3`. Every new file starts with `<?php`, a blank line, `declare(strict_types=1);`, a blank line, then the namespace.
- All new classes are `final`. Value objects use promoted `public readonly` properties.
- No new Composer dependencies.
- Existing public API must not change: `setDebug()`, `getLastDebugInfo()`, and PSR-3 logging behaviour stay exactly as they are. All currently passing tests must keep passing.
- Source namespace `Finvalda\` maps to `src/`; test namespace `Finvalda\Tests\` maps to `tests/`. A test in `tests/Recording/` uses namespace `Finvalda\Tests\Recording`.
- Test methods use snake_case with a `test_` prefix (e.g. `test_records_a_successful_exchange`), matching the existing suite.
- Verification commands: `vendor/bin/phpunit` and `vendor/bin/phpstan analyse`. PHPStan analyses `src` only (`src/Laravel` excluded).
- Credential keys are exactly `Password`, `ConnString`, `sPassword`. The mask is exactly `***`. The env placeholders are exactly `$FVS_PASSWORD`, `$FVS_CONN_STRING`, `$FVS_SPASSWORD`.
- Default recording limit is `20`; the default credential mode is `CredentialMode::Masked`.
- PSR-3 logging always masks (`***`) regardless of the recording credential mode.
- Commit after each task. Do not amend or squash earlier task commits.

---

### Task 1: Credential substitution primitives — `Redactor` and `CredentialMode`

`HttpClient` currently owns the `REDACTED_KEYS` constant and a private `redact()` method. The recorder needs the same substitution, in three modes, so it moves to a shared class first. Existing masking behaviour is unchanged — the placeholder variant is new and used only by recordings.

**Files:**
- Create: `src/Support/Redactor.php`
- Create: `src/Enums/CredentialMode.php`
- Modify: `src/HttpClient.php` (remove `REDACTED_KEYS` at lines 31-35 and `redact()` at lines 285-297; update the two call sites at lines 238 and 279)
- Test: `tests/Support/RedactorTest.php`, `tests/Enums/CredentialModeTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `Finvalda\Support\Redactor::KEYS` (`list<string>`), `Redactor::MASK` (`string`), `Redactor::PLACEHOLDERS` (`array<string, string>`), `Redactor::apply(array $values): array` (masks), `Redactor::applyPlaceholders(array $values): array` (shell placeholders). Both touch top-level keys only and return a new array.
  - `Finvalda\Enums\CredentialMode` — string-backed enum with cases `Masked` (`'masked'`), `Env` (`'env'`), `Real` (`'real'`).

- [ ] **Step 1: Write the failing test**

Create `tests/Support/RedactorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Tests\Support;

use Finvalda\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_masks_credential_keys(): void
    {
        $result = Redactor::apply([
            'UserName' => 'demo',
            'Password' => 'secret',
            'ConnString' => 'Server=db;Password=db-secret',
            'sPassword' => 'other-secret',
        ]);

        $this->assertSame('demo', $result['UserName']);
        $this->assertSame('***', $result['Password']);
        $this->assertSame('***', $result['ConnString']);
        $this->assertSame('***', $result['sPassword']);
    }

    public function test_leaves_arrays_without_credentials_untouched(): void
    {
        $values = ['sKodas' => 'ABC', 'nKiekis' => 3];

        $this->assertSame($values, Redactor::apply($values));
    }

    public function test_does_not_add_missing_keys(): void
    {
        $this->assertArrayNotHasKey('Password', Redactor::apply(['UserName' => 'demo']));
    }

    public function test_masks_only_top_level_keys(): void
    {
        $result = Redactor::apply([
            'input' => ['Password' => 'secret'],
        ]);

        $this->assertSame('secret', $result['input']['Password']);
    }

    public function test_replaces_credentials_with_shell_placeholders(): void
    {
        $result = Redactor::applyPlaceholders([
            'UserName' => 'demo',
            'Password' => 'secret',
            'ConnString' => 'Server=db',
            'sPassword' => 'other-secret',
        ]);

        $this->assertSame('demo', $result['UserName']);
        $this->assertSame('$FVS_PASSWORD', $result['Password']);
        $this->assertSame('$FVS_CONN_STRING', $result['ConnString']);
        $this->assertSame('$FVS_SPASSWORD', $result['sPassword']);
    }

    public function test_placeholders_leave_arrays_without_credentials_untouched(): void
    {
        $values = ['sKodas' => 'ABC'];

        $this->assertSame($values, Redactor::applyPlaceholders($values));
    }

    public function test_every_credential_key_has_a_placeholder(): void
    {
        foreach (Redactor::KEYS as $key) {
            $this->assertArrayHasKey($key, Redactor::PLACEHOLDERS);
        }
    }
}
```

Create `tests/Enums/CredentialModeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Tests\Enums;

use Finvalda\Enums\CredentialMode;
use PHPUnit\Framework\TestCase;

class CredentialModeTest extends TestCase
{
    public function test_cases_have_stable_string_values(): void
    {
        $this->assertSame('masked', CredentialMode::Masked->value);
        $this->assertSame('env', CredentialMode::Env->value);
        $this->assertSame('real', CredentialMode::Real->value);
    }

    public function test_try_from_returns_null_for_unknown_values(): void
    {
        $this->assertNull(CredentialMode::tryFrom('nonsense'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Support/RedactorTest.php tests/Enums/CredentialModeTest.php`
Expected: FAIL — `Class "Finvalda\Support\Redactor" not found` / `Class "Finvalda\Enums\CredentialMode" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Support/Redactor.php`:

```php
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
     * it is the target user's new password in References::updateUserPassword().
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
```

Create `src/Enums/CredentialMode.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Enums;

/**
 * How credential values appear in recorded exchanges.
 */
enum CredentialMode: string
{
    /** Values become '***'. */
    case Masked = 'masked';

    /** Values become shell placeholders ($FVS_PASSWORD), keeping curl runnable. */
    case Env = 'env';

    /** Values kept verbatim. Never for production logs. */
    case Real = 'real';
}
```

Note: the SDK's other enums (`AccessResult`, `Language`) are declared without `final`, which PHP does not allow on enums — follow that shape exactly.

- [ ] **Step 4: Point `HttpClient` at the new class**

In `src/HttpClient.php`:

1. Add the import next to the existing `use Finvalda\Support\OutboundNumericNormalizer;`:

```php
use Finvalda\Support\Redactor;
```

2. Delete this constant and its docblock (lines 31-35):

```php
    /**
     * Header and parameter names whose values are replaced with '***' in
     * debug captures and PSR-3 log context. The wire request is unaffected.
     */
    private const REDACTED_KEYS = ['Password', 'ConnString', 'sPassword'];
```

3. Delete the private `redact()` method and its docblock (lines 285-297):

```php
    /**
     * Replace sensitive values with '***' for logging/debug output.
     */
    private function redact(array $values): array
    {
        foreach (self::REDACTED_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '***';
            }
        }

        return $values;
    }
```

4. Replace the two call sites. In the debug capture inside `sendRequest()`:

```php
                    'headers' => Redactor::apply(array_merge($this->buildHeaders(), $options['headers'] ?? [])),
```

In `logRequest()`:

```php
            'params' => Redactor::apply($options['query'] ?? $options['json'] ?? []),
```

- [ ] **Step 5: Run the full suite and static analysis**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse`
Expected: PASS. `tests/HttpClientRedactionTest.php` in particular must still pass — it asserts the same masking through the debug and log paths.

- [ ] **Step 6: Commit**

```bash
git add src/Support/Redactor.php src/Enums/CredentialMode.php src/HttpClient.php tests/Support/RedactorTest.php tests/Enums/CredentialModeTest.php
git commit -m "refactor: extract credential substitution into Redactor and CredentialMode"
```

---

### Task 2: `Exchange` value object with formatted rendering

The value object holding one recorded attempt, plus its default human-readable rendering and `toArray()`. `toCurl()` and `withCredentials()` come in Tasks 3 and 4.

**Files:**
- Create: `src/Recording/Exchange.php`
- Test: `tests/Recording/ExchangeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Finvalda\Recording\Exchange` with constructor
  `__construct(string $method, string $url, array $headers, ?string $body, ?int $statusCode, ?string $reasonPhrase, array $responseHeaders, ?string $responseBody, float $durationMs, ?string $error = null, int $attempt = 1)`
  and methods `toString(): string`, `__toString(): string`, `toArray(): array`.
  All constructor arguments are also `public readonly` properties with the same names.
  Later tasks construct it with named arguments.

Note on `reasonPhrase`: the spec's rendering shows `--- 200 OK (128 ms) ---`. PHP has no built-in status-code-to-phrase map and Guzzle's is private, so the phrase is captured from the PSR-7 response and carried on the exchange. `null` renders as the bare code.

- [ ] **Step 1: Write the failing test**

Create `tests/Recording/ExchangeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Tests\Recording;

use Finvalda\Recording\Exchange;
use PHPUnit\Framework\TestCase;

class ExchangeTest extends TestCase
{
    private function operationExchange(): Exchange
    {
        return new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewOperation',
            headers: ['UserName' => 'demo', 'Password' => '***', 'Accept' => 'application/json'],
            body: '{"ItemClassName":"PardDok","xmlstring":"{\"PardDok\":{\"sZurnalas\":\"PARD\"}}"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: ['Content-Type' => ['application/json']],
            responseBody: '{"AccessResult":"Success","nResult":0}',
            durationMs: 128.4,
        );
    }

    public function test_formats_request_line_and_headers(): void
    {
        $output = $this->operationExchange()->toString();

        $this->assertStringContainsString(
            'POST https://example.com/FvsServicePure.svc/InsertNewOperation',
            $output,
        );
        $this->assertStringContainsString('UserName: demo', $output);
        $this->assertStringContainsString('Password: ***', $output);
    }

    public function test_expands_embedded_xmlstring_payload(): void
    {
        $output = $this->operationExchange()->toString();

        // The nested payload is decoded and indented, not left as an escaped string
        $this->assertStringContainsString('"xmlstring": {', $output);
        $this->assertStringContainsString('"sZurnalas": "PARD"', $output);
        $this->assertStringNotContainsString('\"sZurnalas\"', $output);
    }

    public function test_formats_status_line_with_reason_phrase_and_duration(): void
    {
        $this->assertStringContainsString('--- 200 OK (128.4 ms) ---', $this->operationExchange()->toString());
    }

    public function test_pretty_prints_json_response_body(): void
    {
        $this->assertStringContainsString('"AccessResult": "Success"', $this->operationExchange()->toString());
    }

    public function test_leaves_non_json_bodies_verbatim(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetFvsUser',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: '<FvsUser><sKodas>ADMIN</sKodas></FvsUser>',
            durationMs: 4.0,
        );

        $this->assertStringContainsString('<FvsUser><sKodas>ADMIN</sKodas></FvsUser>', $exchange->toString());
    }

    public function test_leaves_xmlstring_verbatim_when_it_is_not_json(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewItem',
            headers: [],
            body: '{"ItemClassName":"Fvs.Preke","xmlstring":"<Preke><sKodas>A</sKodas></Preke>"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 2.0,
        );

        $this->assertStringContainsString('"xmlstring": "<Preke><sKodas>A</sKodas></Preke>"', $exchange->toString());
    }

    public function test_formats_transport_error_when_there_is_no_response(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: [],
            body: null,
            statusCode: null,
            reasonPhrase: null,
            responseHeaders: [],
            responseBody: null,
            durationMs: 30_000.0,
            error: 'cURL error 28: Operation timed out',
        );

        $output = $exchange->toString();

        $this->assertStringContainsString('--- ERROR: cURL error 28: Operation timed out (30000 ms) ---', $output);
    }

    public function test_string_cast_returns_formatted_output(): void
    {
        $exchange = $this->operationExchange();

        $this->assertSame($exchange->toString(), (string) $exchange);
    }

    public function test_to_array_groups_request_and_response(): void
    {
        $array = $this->operationExchange()->toArray();

        $this->assertSame('POST', $array['request']['method']);
        $this->assertSame('https://example.com/FvsServicePure.svc/InsertNewOperation', $array['request']['url']);
        $this->assertSame('***', $array['request']['headers']['Password']);
        $this->assertSame(200, $array['response']['status_code']);
        $this->assertSame(128.4, $array['response']['duration_ms']);
        $this->assertNull($array['response']['error']);
        $this->assertSame(1, $array['attempt']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Recording/ExchangeTest.php`
Expected: FAIL — `Class "Finvalda\Recording\Exchange" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Recording/Exchange.php`:

```php
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
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Recording/ExchangeTest.php && vendor/bin/phpstan analyse`
Expected: PASS.

If `test_formats_transport_error_when_there_is_no_response` fails on the duration, note that `round(30000.0, 1)` interpolates as `30000` (PHP drops the trailing `.0`), which is what the test expects.

- [ ] **Step 5: Commit**

```bash
git add src/Recording/Exchange.php tests/Recording/ExchangeTest.php
git commit -m "feat: add Exchange value object with formatted rendering"
```

---

### Task 3: `Exchange::toCurl()`

**Files:**
- Modify: `src/Recording/Exchange.php`
- Test: `tests/Recording/ExchangeTest.php`

**Interfaces:**
- Consumes: `Exchange` from Task 2.
- Produces: `Exchange::toCurl(): string`.

The curl body is the stored body verbatim (no pretty-printing) so the command reproduces the call. `Content-Type: application/json` is added when a body is present and no such header was captured — Guzzle adds it for `json` bodies and the SDK's `buildHeaders()` does not.

- [ ] **Step 1: Write the failing test**

Append these methods to `tests/Recording/ExchangeTest.php` (`operationExchange()` is already defined in Task 2):

```php
    public function test_curl_renders_method_url_headers_and_body(): void
    {
        $curl = $this->operationExchange()->toCurl();

        $this->assertStringContainsString(
            "curl -X POST 'https://example.com/FvsServicePure.svc/InsertNewOperation'",
            $curl,
        );
        $this->assertStringContainsString("-H 'UserName: demo'", $curl);
        $this->assertStringContainsString("-H 'Password: ***'", $curl);
        $this->assertStringContainsString("-H 'Content-Type: application/json'", $curl);
        // Body is byte-exact, not pretty-printed
        $this->assertStringContainsString(
            '-d \'{"ItemClassName":"PardDok","xmlstring":"{\"PardDok\":{\"sZurnalas\":\"PARD\"}}"}\'',
            $curl,
        );
    }

    public function test_curl_uses_line_continuations(): void
    {
        $this->assertStringContainsString(" \\\n", $this->operationExchange()->toCurl());
    }

    public function test_curl_omits_data_flag_for_bodyless_requests(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes?sKodas=ABC',
            headers: ['UserName' => 'demo'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: '{"AccessResult":"Success"}',
            durationMs: 12.0,
        );

        $curl = $exchange->toCurl();

        $this->assertStringNotContainsString('-d ', $curl);
        $this->assertStringNotContainsString('Content-Type', $curl);
        $this->assertStringContainsString("'https://example.com/FvsServicePure.svc/GetPrekes?sKodas=ABC'", $curl);
    }

    public function test_curl_escapes_single_quotes_in_body(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewItem',
            headers: [],
            body: '{"sPavadinimas":"O\'Brien"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $this->assertStringContainsString('O\'\\\'\'Brien', $exchange->toCurl());
    }

    public function test_curl_keeps_a_captured_content_type_header(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewItem',
            headers: ['Content-Type' => 'text/xml'],
            body: '<Preke />',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $curl = $exchange->toCurl();

        $this->assertStringContainsString("-H 'Content-Type: text/xml'", $curl);
        $this->assertStringNotContainsString('application/json', $curl);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Recording/ExchangeTest.php`
Expected: FAIL — `Call to undefined method Finvalda\Recording\Exchange::toCurl()`.

- [ ] **Step 3: Write the implementation**

Add to `src/Recording/Exchange.php`, after `toArray()`:

```php
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
     * Wrap a value in single quotes for a POSIX shell.
     */
    private function quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Recording/ExchangeTest.php && vendor/bin/phpstan analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Recording/Exchange.php tests/Recording/ExchangeTest.php
git commit -m "feat: render an Exchange as a curl command"
```

---

### Task 4: `Exchange::withCredentials()` and the `Recorder` ring buffer

**Files:**
- Modify: `src/Recording/Exchange.php`
- Create: `src/Recording/Recorder.php`
- Test: `tests/Recording/ExchangeTest.php`, `tests/Recording/RecorderTest.php`

**Interfaces:**
- Consumes: `Exchange` (Tasks 2-3), `Redactor` and `CredentialMode` (Task 1).
- Produces:
  - `Exchange::withCredentials(CredentialMode $mode): self` — a copy whose headers, URL query, and JSON body carry masked values, env placeholders, or (for `Real`) the originals.
  - `Finvalda\Recording\Recorder` with `__construct(int $limit = 20, CredentialMode $credentials = CredentialMode::Masked)`, `record(Exchange $exchange): void`, `all(): list<Exchange>`, `last(): ?Exchange`.
  - An upgraded `Exchange::quote()` (private) that makes curl output placeholder-aware.

Substitution happens when an exchange is recorded, so under the default mode the real password never enters the buffer. `sPassword` travels as a GET query parameter (`References::updateUserPassword()`), which is why the URL query is substituted too.

- [ ] **Step 1: Write the failing test for `withCredentials()`**

Append to `tests/Recording/ExchangeTest.php` (add `use Finvalda\Enums\CredentialMode;` to the imports):

```php
    public function test_masked_mode_masks_credential_headers(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: ['UserName' => 'demo', 'Password' => 'secret', 'ConnString' => 'Server=db'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $masked = $exchange->withCredentials(CredentialMode::Masked);

        $this->assertSame('***', $masked->headers['Password']);
        $this->assertSame('***', $masked->headers['ConnString']);
        $this->assertSame('demo', $masked->headers['UserName']);
        // Original is untouched
        $this->assertSame('secret', $exchange->headers['Password']);
    }

    public function test_env_mode_replaces_credentials_with_placeholders(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: ['UserName' => 'demo', 'Password' => 'secret'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $env = $exchange->withCredentials(CredentialMode::Env);

        $this->assertSame('$FVS_PASSWORD', $env->headers['Password']);
        $this->assertSame('demo', $env->headers['UserName']);
        $this->assertStringNotContainsString('secret', (string) $env);
    }

    public function test_real_mode_returns_the_exchange_unchanged(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: ['Password' => 'secret'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $this->assertSame('secret', $exchange->withCredentials(CredentialMode::Real)->headers['Password']);
    }

    public function test_env_mode_curl_interpolates_the_placeholder(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/GetPrekes',
            headers: ['UserName' => 'demo', 'Password' => 'secret'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $curl = $exchange->withCredentials(CredentialMode::Env)->toCurl();

        // Literal chunk single-quoted, placeholder double-quoted so the shell expands it
        $this->assertStringContainsString('-H \'Password: \'"$FVS_PASSWORD"', $curl);
        $this->assertStringContainsString("-H 'UserName: demo'", $curl);
    }

    public function test_env_mode_curl_interpolates_a_placeholder_inside_a_json_body(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/SetFvsUser',
            headers: [],
            body: '{"sKodas":"ADMIN","sPassword":"secret"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $curl = $exchange->withCredentials(CredentialMode::Env)->toCurl();

        $this->assertStringNotContainsString('secret', $curl);
        $this->assertStringContainsString('\'{"sKodas":"ADMIN","sPassword":"\'"$FVS_SPASSWORD"\'"}\'', $curl);
    }

    public function test_masked_mode_masks_credentials_in_the_url_query(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/SetFvsUserPassword?sKodas=ADMIN&sPassword=secret',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $url = $exchange->withCredentials(CredentialMode::Masked)->url;

        $this->assertStringNotContainsString('secret', $url);
        $this->assertStringContainsString('sPassword=%2A%2A%2A', $url);
        $this->assertStringContainsString('sKodas=ADMIN', $url);
    }

    public function test_env_mode_substitutes_credentials_in_the_url_query(): void
    {
        $exchange = new Exchange(
            method: 'GET',
            url: 'https://example.com/FvsServicePure.svc/SetFvsUserPassword?sKodas=ADMIN&sPassword=secret',
            headers: [],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $url = $exchange->withCredentials(CredentialMode::Env)->url;

        $this->assertStringNotContainsString('secret', $url);
        // http_build_query percent-encodes the placeholder; decode before asserting
        $this->assertStringContainsString('sPassword=' . urlencode('$FVS_SPASSWORD'), $url);
    }

    public function test_masked_mode_masks_credentials_in_a_json_body(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/SetFvsUser',
            headers: [],
            body: '{"sKodas":"ADMIN","sPassword":"secret"}',
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: null,
            durationMs: 1.0,
        );

        $body = $exchange->withCredentials(CredentialMode::Masked)->body;

        $this->assertStringNotContainsString('secret', (string) $body);
        $this->assertStringContainsString('"sPassword":"***"', (string) $body);
    }

    public function test_substitution_leaves_a_credential_free_body_byte_identical(): void
    {
        $exchange = $this->operationExchange();
        $masked = $exchange->withCredentials(CredentialMode::Masked);

        $this->assertSame($exchange->body, $masked->body);
        $this->assertSame($exchange->url, $masked->url);
    }

    public function test_substitution_preserves_response_and_attempt_data(): void
    {
        $exchange = new Exchange(
            method: 'POST',
            url: 'https://example.com/FvsServicePure.svc/InsertNewOperation',
            headers: ['Password' => 'secret'],
            body: null,
            statusCode: 500,
            reasonPhrase: 'Internal Server Error',
            responseHeaders: ['X-Trace' => ['abc']],
            responseBody: 'boom',
            durationMs: 7.5,
            error: 'Server error: 500',
            attempt: 3,
        );

        $substituted = $exchange->withCredentials(CredentialMode::Masked);

        $this->assertSame(500, $substituted->statusCode);
        $this->assertSame('Internal Server Error', $substituted->reasonPhrase);
        $this->assertSame(['X-Trace' => ['abc']], $substituted->responseHeaders);
        $this->assertSame('boom', $substituted->responseBody);
        $this->assertSame(7.5, $substituted->durationMs);
        $this->assertSame('Server error: 500', $substituted->error);
        $this->assertSame(3, $substituted->attempt);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Recording/ExchangeTest.php`
Expected: FAIL — `Call to undefined method Finvalda\Recording\Exchange::withCredentials()`.

- [ ] **Step 3: Implement `withCredentials()` and placeholder-aware quoting**

Add the imports to `src/Recording/Exchange.php`:

```php
use Finvalda\Enums\CredentialMode;
use Finvalda\Support\Redactor;
```

Add these methods after `toCurl()`:

```php
    /**
     * A copy whose headers, URL query, and JSON body carry credential values
     * substituted per the given mode. The URL and body are returned unchanged
     * when they carry no credentials.
     */
    public function withCredentials(CredentialMode $mode): self
    {
        if ($mode === CredentialMode::Real) {
            return $this;
        }

        /** @var array<string, string> $headers */
        $headers = $this->substitute($this->headers, $mode);

        return new self(
            method: $this->method,
            url: $this->substituteUrl($this->url, $mode),
            headers: $headers,
            body: $this->substituteBody($this->body, $mode),
            statusCode: $this->statusCode,
            reasonPhrase: $this->reasonPhrase,
            responseHeaders: $this->responseHeaders,
            responseBody: $this->responseBody,
            durationMs: $this->durationMs,
            error: $this->error,
            attempt: $this->attempt,
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
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $url;
        }

        parse_str($query, $params);
        $substituted = $this->substitute($params, $mode);

        if ($substituted === $params) {
            return $url;
        }

        return str_replace($query, http_build_query($substituted), $url);
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
```

Then replace the `quote()` method written in Task 3 with a placeholder-aware version, so env-mode output actually interpolates in a shell:

```php
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
```

PHPStan notes: `Redactor::apply()`/`applyPlaceholders()` take and return `array<string, mixed>`; the `/** @var array<string, string> $headers */` annotation above narrows the result back to the `headers` property type, since both substitutions only ever write strings. If PHPStan complains about `parse_str()`'s by-reference `$params`, initialise it with `$params = [];` before the call.

- [ ] **Step 4: Run the exchange tests**

Run: `vendor/bin/phpunit tests/Recording/ExchangeTest.php`
Expected: PASS — including the Task 3 curl tests, which must be unaffected by the new `quote()` (values with no `$FVS_` placeholder still come out single-quoted).

- [ ] **Step 5: Write the failing `Recorder` test**

Create `tests/Recording/RecorderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Tests\Recording;

use Finvalda\Enums\CredentialMode;
use Finvalda\Recording\Exchange;
use Finvalda\Recording\Recorder;
use PHPUnit\Framework\TestCase;

class RecorderTest extends TestCase
{
    private function exchange(string $marker = 'A'): Exchange
    {
        return new Exchange(
            method: 'GET',
            url: "https://example.com/FvsServicePure.svc/GetPrekes?sKodas={$marker}",
            headers: ['UserName' => 'demo', 'Password' => 'secret'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: '{"AccessResult":"Success"}',
            durationMs: 5.0,
        );
    }

    public function test_records_exchanges_oldest_first(): void
    {
        $recorder = new Recorder();
        $recorder->record($this->exchange('A'));
        $recorder->record($this->exchange('B'));

        $all = $recorder->all();

        $this->assertCount(2, $all);
        $this->assertStringContainsString('sKodas=A', $all[0]->url);
        $this->assertStringContainsString('sKodas=B', $all[1]->url);
    }

    public function test_trims_to_the_limit_keeping_the_newest(): void
    {
        $recorder = new Recorder(limit: 2);
        $recorder->record($this->exchange('A'));
        $recorder->record($this->exchange('B'));
        $recorder->record($this->exchange('C'));

        $all = $recorder->all();

        $this->assertCount(2, $all);
        $this->assertStringContainsString('sKodas=B', $all[0]->url);
        $this->assertStringContainsString('sKodas=C', $all[1]->url);
    }

    public function test_last_returns_the_newest_exchange(): void
    {
        $recorder = new Recorder();
        $recorder->record($this->exchange('A'));
        $recorder->record($this->exchange('B'));

        $this->assertStringContainsString('sKodas=B', $recorder->last()?->url ?? '');
    }

    public function test_last_is_null_on_an_empty_recorder(): void
    {
        $this->assertNull((new Recorder())->last());
    }

    public function test_masks_credentials_at_capture_time_by_default(): void
    {
        $recorder = new Recorder();
        $recorder->record($this->exchange());

        $this->assertSame('***', $recorder->last()?->headers['Password']);
    }

    public function test_uses_env_placeholders_in_env_mode(): void
    {
        $recorder = new Recorder(credentials: CredentialMode::Env);
        $recorder->record($this->exchange());

        $this->assertSame('$FVS_PASSWORD', $recorder->last()?->headers['Password']);
    }

    public function test_keeps_credentials_in_real_mode(): void
    {
        $recorder = new Recorder(credentials: CredentialMode::Real);
        $recorder->record($this->exchange());

        $this->assertSame('secret', $recorder->last()?->headers['Password']);
    }

    public function test_a_limit_below_one_still_keeps_the_latest_exchange(): void
    {
        $recorder = new Recorder(limit: 0);
        $recorder->record($this->exchange('A'));
        $recorder->record($this->exchange('B'));

        $this->assertCount(1, $recorder->all());
        $this->assertStringContainsString('sKodas=B', $recorder->last()?->url ?? '');
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Recording/RecorderTest.php`
Expected: FAIL — `Class "Finvalda\Recording\Recorder" not found`.

- [ ] **Step 7: Implement `Recorder`**

Create `src/Recording/Recorder.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Recording;

use Finvalda\Enums\CredentialMode;

/**
 * Bounded in-memory history of request/response exchanges. Credentials are
 * substituted as exchanges are recorded, so the buffer never holds real secrets
 * unless CredentialMode::Real was explicitly requested.
 */
final class Recorder
{
    private readonly int $limit;

    /** @var list<Exchange> */
    private array $exchanges = [];

    /**
     * @param  int  $limit  Maximum exchanges kept; the oldest are dropped first
     * @param  CredentialMode  $credentials  How credential values appear in recordings
     */
    public function __construct(
        int $limit = 20,
        private readonly CredentialMode $credentials = CredentialMode::Masked,
    ) {
        $this->limit = max(1, $limit);
    }

    public function record(Exchange $exchange): void
    {
        $this->exchanges[] = $exchange->withCredentials($this->credentials);

        if (count($this->exchanges) > $this->limit) {
            $this->exchanges = array_slice($this->exchanges, -$this->limit);
        }
    }

    /**
     * Recorded exchanges, oldest first.
     *
     * @return list<Exchange>
     */
    public function all(): array
    {
        return $this->exchanges;
    }

    public function last(): ?Exchange
    {
        if ($this->exchanges === []) {
            return null;
        }

        return $this->exchanges[count($this->exchanges) - 1];
    }
}
```

- [ ] **Step 8: Run the tests**

Run: `vendor/bin/phpunit tests/Recording && vendor/bin/phpstan analyse`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Recording tests/Recording
git commit -m "feat: add Recorder ring buffer with capture-time credential substitution"
```

---

### Task 5: Capture exchanges in `HttpClient` and expose them on `Finvalda`

**Files:**
- Modify: `src/HttpClient.php` (`sendRequest()` at lines 223-265; new property, new public methods, new private helpers)
- Modify: `src/Finvalda.php` (add methods after `getLastDebugInfo()`, around line 108)
- Test: `tests/HttpClientRecordingTest.php`

**Interfaces:**
- Consumes: `Exchange`, `Recorder` (Tasks 2-4).
- Produces:
  - `HttpClient::record(int $limit = 20, CredentialMode $credentials = CredentialMode::Masked): void`, `HttpClient::stopRecording(): void`, `HttpClient::recordings(): list<Exchange>`, `HttpClient::lastRecording(): ?Exchange`.
  - `Finvalda::record(int $limit = 20, CredentialMode $credentials = CredentialMode::Masked): self`, `Finvalda::stopRecording(): self`, `Finvalda::recordings(): list<Exchange>`, `Finvalda::lastRecording(): ?Exchange`.

Two things to get right: recording sits **inside** the per-attempt closure so a retried call produces one exchange per attempt, and the Guzzle call is wrapped so failures are recorded before the exception is rethrown unchanged.

The Laravel facade (`src/Laravel/Facades/Finvalda.php`) documents only resource accessors in its `@method` list — `setDebug()` and `setLogger()` are absent — so it needs no change.

- [ ] **Step 1: Write the failing test**

Create `tests/HttpClientRecordingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Enums\CredentialMode;
use Finvalda\Exceptions\FinvaldaException;
use Finvalda\FinvaldaConfig;
use Finvalda\HttpClient;
use Finvalda\Retry\RetryPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HttpClientRecordingTest extends TestCase
{
    /**
     * @param  list<Response|\Throwable>  $responses
     */
    private function createHttpClient(array $responses, ?FinvaldaConfig $config = null): HttpClient
    {
        $mock = new MockHandler($responses);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);

        $config ??= new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
        );

        return new HttpClient($config, $guzzle);
    }

    public function test_recording_is_off_by_default(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->get('GetPrekes');

        $this->assertSame([], $httpClient->recordings());
        $this->assertNull($httpClient->lastRecording());
    }

    public function test_records_a_successful_get(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record();
        $httpClient->get('GetPrekes', ['sKodas' => 'ABC']);

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame('GET', $exchange->method);
        $this->assertSame('https://example.com/FvsServicePure.svc/GetPrekes?sKodas=ABC', $exchange->url);
        $this->assertSame(200, $exchange->statusCode);
        $this->assertSame('OK', $exchange->reasonPhrase);
        $this->assertNull($exchange->body);
        $this->assertStringContainsString('AccessResult', (string) $exchange->responseBody);
        $this->assertGreaterThan(0.0, $exchange->durationMs);
        $this->assertSame(1, $exchange->attempt);
    }

    public function test_records_the_json_body_of_a_write_operation(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success', 'nResult' => 0])),
        ]);

        $httpClient->record();
        $httpClient->postOperation('InsertNewOperation', ['ItemClassName' => 'PardDok'], '{"PardDok":{}}');

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame('POST', $exchange->method);
        $this->assertStringContainsString('"ItemClassName":"PardDok"', (string) $exchange->body);
        $this->assertStringContainsString('xmlstring', (string) $exchange->body);
    }

    public function test_masks_credentials_by_default(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record();
        $httpClient->get('GetPrekes');

        $exchange = $httpClient->lastRecording();

        $this->assertSame('***', $exchange?->headers['Password']);
        $this->assertSame('demo', $exchange?->headers['UserName']);
        $this->assertStringNotContainsString('secret-password', (string) $exchange);
    }

    public function test_env_mode_emits_a_runnable_curl_without_the_secret(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record(credentials: CredentialMode::Env);
        $httpClient->get('GetPrekes');

        $curl = $httpClient->lastRecording()?->toCurl() ?? '';

        $this->assertStringNotContainsString('secret-password', $curl);
        $this->assertStringContainsString('-H \'Password: \'"$FVS_PASSWORD"', $curl);
    }

    public function test_captures_real_credentials_when_requested(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record(credentials: CredentialMode::Real);
        $httpClient->get('GetPrekes');

        $this->assertSame('secret-password', $httpClient->lastRecording()?->headers['Password']);
    }

    public function test_records_a_failed_request_and_rethrows(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(500, [], 'Internal failure'),
        ]);

        $httpClient->record();

        try {
            $httpClient->get('GetPrekes');
            $this->fail('Expected a FinvaldaException');
        } catch (FinvaldaException) {
            // expected
        }

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame(500, $exchange->statusCode);
        $this->assertStringContainsString('Internal failure', (string) $exchange->responseBody);
        $this->assertNotNull($exchange->error);
    }

    public function test_records_one_exchange_per_retry_attempt(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
            retry: new RetryPolicy(maxAttempts: 3, delayMs: 1),
        );

        $httpClient = $this->createHttpClient([
            new Response(500, [], 'boom'),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $httpClient->record();
        $httpClient->get('GetPrekes');

        $recordings = $httpClient->recordings();

        $this->assertCount(2, $recordings);
        $this->assertSame(500, $recordings[0]->statusCode);
        $this->assertSame(1, $recordings[0]->attempt);
        $this->assertSame(200, $recordings[1]->statusCode);
        $this->assertSame(2, $recordings[1]->attempt);
    }

    public function test_stop_recording_drops_the_buffer(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record();
        $httpClient->get('GetPrekes');
        $httpClient->stopRecording();
        $httpClient->get('GetPrekes');

        $this->assertSame([], $httpClient->recordings());
    }

    public function test_recording_limit_is_honoured(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record(limit: 2);
        $httpClient->get('GetPrekes', ['sKodas' => 'A']);
        $httpClient->get('GetPrekes', ['sKodas' => 'B']);
        $httpClient->get('GetPrekes', ['sKodas' => 'C']);

        $recordings = $httpClient->recordings();

        $this->assertCount(2, $recordings);
        $this->assertStringContainsString('sKodas=B', $recordings[0]->url);
        $this->assertStringContainsString('sKodas=C', $recordings[1]->url);
    }

    public function test_curl_output_reproduces_the_recorded_call(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success', 'nResult' => 0])),
        ]);

        $httpClient->record();
        $httpClient->postOperation('InsertNewOperation', ['ItemClassName' => 'PardDok'], '{"PardDok":{}}');

        $curl = $httpClient->lastRecording()?->toCurl() ?? '';

        $this->assertStringContainsString(
            "curl -X POST 'https://example.com/FvsServicePure.svc/InsertNewOperation'",
            $curl,
        );
        $this->assertStringContainsString("-H 'Content-Type: application/json'", $curl);
        $this->assertStringContainsString('-d ', $curl);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/HttpClientRecordingTest.php`
Expected: FAIL — `Call to undefined method Finvalda\HttpClient::record()`.

- [ ] **Step 3: Add the recorder to `HttpClient`**

In `src/HttpClient.php`:

1. Add imports next to the existing ones:

```php
use Finvalda\Enums\CredentialMode;
use Finvalda\Recording\Exchange;
use Finvalda\Recording\Recorder;
```

2. Add the property below `private array $lastResponse = [];`:

```php
    private ?Recorder $recorder = null;
```

3. Add the public API after `getLastDebugInfo()`:

```php
    /**
     * Start recording request/response exchanges in memory. Replaces any
     * exchanges recorded so far.
     *
     * @param  int  $limit  Maximum exchanges kept; the oldest are dropped first
     * @param  CredentialMode  $credentials  How credential values appear in recordings
     */
    public function record(int $limit = 20, CredentialMode $credentials = CredentialMode::Masked): void
    {
        $this->recorder = new Recorder($limit, $credentials);
    }

    /**
     * Stop recording and drop the recorded exchanges.
     */
    public function stopRecording(): void
    {
        $this->recorder = null;
    }

    /**
     * Recorded exchanges, oldest first. Empty when recording is off.
     *
     * @return list<Exchange>
     */
    public function recordings(): array
    {
        return $this->recorder?->all() ?? [];
    }

    public function lastRecording(): ?Exchange
    {
        return $this->recorder?->last();
    }
```

- [ ] **Step 4: Capture inside the per-attempt closure**

Replace the body of `sendRequest()` (lines 223-265) with:

```php
    private function sendRequest(string $method, string $endpoint, array $options): string
    {
        if (isset($options['json'])) {
            $options['json'] = $this->normalizer->normalize($options['json']);
        }

        $attempt = 0;

        $doRequest = function () use ($method, $endpoint, $options, &$attempt): string {
            $attempt++;
            $startTime = microtime(true);

            $this->logRequest($method, $endpoint, $options);

            if ($this->debug) {
                $this->lastRequest = [
                    'method' => $method,
                    'url' => rtrim($this->config->baseUrl, '/') . '/' . $endpoint,
                    'headers' => Redactor::apply(array_merge($this->buildHeaders(), $options['headers'] ?? [])),
                    'body' => $options['body'] ?? $options['form_params'] ?? $options['json'] ?? null,
                ];
            }

            try {
                $response = $this->client->request($method, $endpoint, $options);
            } catch (GuzzleException $e) {
                $this->recordFailure($method, $endpoint, $options, $e, microtime(true) - $startTime, $attempt);

                throw $e;
            }

            $body = (string) $response->getBody();

            $duration = microtime(true) - $startTime;
            $this->logResponse($method, $endpoint, $response->getStatusCode(), $duration, $body);

            if ($this->debug) {
                $this->lastResponse = [
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $body,
                ];
            }

            $this->recorder?->record(new Exchange(
                method: $method,
                url: $this->recordedUrl($endpoint, $options),
                headers: $this->recordedHeaders($options),
                body: $this->recordedBody($options),
                statusCode: $response->getStatusCode(),
                reasonPhrase: $response->getReasonPhrase(),
                responseHeaders: $response->getHeaders(),
                responseBody: $body,
                durationMs: $duration * 1000,
                attempt: $attempt,
            ));

            return $body;
        };

        if ($this->retryHandler !== null) {
            return $this->withShortestFloatEncoding(fn (): string => $this->retryHandler->execute($doRequest));
        }

        return $this->withShortestFloatEncoding($doRequest);
    }
```

Then add these private helpers directly after `sendRequest()`:

```php
    /**
     * Record a failed attempt. Captures the response when the failure carried
     * one (4xx/5xx), otherwise just the transport error.
     *
     * @param  array<string, mixed>  $options
     */
    private function recordFailure(
        string $method,
        string $endpoint,
        array $options,
        GuzzleException $e,
        float $duration,
        int $attempt,
    ): void {
        if ($this->recorder === null) {
            return;
        }

        $response = $e instanceof RequestException && $e->hasResponse() ? $e->getResponse() : null;

        $this->recorder->record(new Exchange(
            method: $method,
            url: $this->recordedUrl($endpoint, $options),
            headers: $this->recordedHeaders($options),
            body: $this->recordedBody($options),
            statusCode: $response?->getStatusCode(),
            reasonPhrase: $response?->getReasonPhrase(),
            responseHeaders: $response?->getHeaders() ?? [],
            responseBody: $response !== null ? (string) $response->getBody() : null,
            durationMs: $duration * 1000,
            error: $e->getMessage(),
            attempt: $attempt,
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function recordedUrl(string $endpoint, array $options): string
    {
        $url = rtrim($this->config->baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $query = $options['query'] ?? [];

        if (is_array($query) && $query !== []) {
            return $url . '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function recordedHeaders(array $options): array
    {
        /** @var array<string, string> $headers */
        $headers = array_merge($this->buildHeaders(), $options['headers'] ?? []);

        return $headers;
    }

    /**
     * The request body as handed to Guzzle. JSON is encoded with default flags
     * to match Guzzle's own encoding of the `json` option.
     *
     * @param  array<string, mixed>  $options
     */
    private function recordedBody(array $options): ?string
    {
        if (isset($options['body']) && is_string($options['body'])) {
            return $options['body'];
        }

        if (isset($options['json'])) {
            return json_encode($options['json']) ?: null;
        }

        return null;
    }
```

Note: `recordedBody()` runs inside `withShortestFloatEncoding()`, so recorded floats match what Guzzle serializes.

- [ ] **Step 5: Run the recording tests**

Run: `vendor/bin/phpunit tests/HttpClientRecordingTest.php`
Expected: PASS.

If `test_records_a_failed_request_and_rethrows` reports no recording, check that the `try`/`catch` wraps only `$this->client->request(...)` — Guzzle throws there for 5xx because `http_errors` is on by default.

- [ ] **Step 6: Expose the API on `Finvalda`**

In `src/Finvalda.php`, add the imports next to the other `use` statements:

```php
use Finvalda\Enums\CredentialMode;
use Finvalda\Recording\Exchange;
```

Add these methods after `getLastDebugInfo()`:

```php
    /**
     * Start recording request/response exchanges in memory. Replaces any
     * exchanges recorded so far.
     *
     * @param  int  $limit  Maximum exchanges kept; the oldest are dropped first
     * @param  CredentialMode  $credentials  How credential values appear in recordings
     * @return $this
     */
    public function record(int $limit = 20, CredentialMode $credentials = CredentialMode::Masked): self
    {
        $this->http->record($limit, $credentials);

        return $this;
    }

    /**
     * Stop recording and drop the recorded exchanges.
     *
     * @return $this
     */
    public function stopRecording(): self
    {
        $this->http->stopRecording();

        return $this;
    }

    /**
     * Recorded exchanges, oldest first. Empty when recording is off.
     *
     * @return list<Exchange>
     */
    public function recordings(): array
    {
        return $this->http->recordings();
    }

    public function lastRecording(): ?Exchange
    {
        return $this->http->lastRecording();
    }
```

- [ ] **Step 7: Test the `Finvalda` surface**

`tests/FinvaldaTest.php` currently builds `Finvalda` from a config alone, with no HTTP helper, so this test wires the mock itself. Add these imports to the file:

```php
use Finvalda\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
```

Then add the test (`products()->all()` issues exactly one GET to `GetPrekes`):

```php
    public function test_record_and_recordings_delegate_to_the_http_client(): void
    {
        $mock = new MockHandler([
            new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success'], JSON_THROW_ON_ERROR)),
        ]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret',
        );

        $finvalda = new Finvalda($config, new HttpClient($config, $guzzle));

        $this->assertSame($finvalda, $finvalda->record(limit: 5));

        $finvalda->products()->all();

        $this->assertCount(1, $finvalda->recordings());
        $this->assertNotNull($finvalda->lastRecording());
        $this->assertSame($finvalda, $finvalda->stopRecording());
        $this->assertSame([], $finvalda->recordings());
    }
```

- [ ] **Step 8: Run the full suite and static analysis**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse`
Expected: PASS, including the untouched `tests/HttpClientTest.php` debug tests and `tests/RetryHandlerTest.php`.

- [ ] **Step 9: Commit**

```bash
git add src/HttpClient.php src/Finvalda.php tests/HttpClientRecordingTest.php tests/FinvaldaTest.php
git commit -m "feat: record request/response exchanges in HttpClient"
```

---

### Task 6: Config and env plumbing

**Files:**
- Modify: `src/FinvaldaConfig.php` (constructor after `floatPrecision`, line 28; `fromArray()` at lines 56-86)
- Modify: `src/HttpClient.php` (constructor, lines 55-72)
- Modify: `config/finvalda.php` (append a Recording block before the closing `];`)
- Test: `tests/FinvaldaConfigTest.php`, `tests/HttpClientRecordingTest.php`

**Interfaces:**
- Consumes: `Recorder` (Task 4), `HttpClient::recordings()` (Task 5).
- Produces: `FinvaldaConfig::$record` (`bool`), `FinvaldaConfig::$recordLimit` (`int`), `FinvaldaConfig::$recordCredentials` (`CredentialMode`), mapped in `fromArray()` from `record`, `record_limit`, `record_credentials` (the last via `CredentialMode::tryFrom()`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/FinvaldaConfigTest.php` (add `use Finvalda\Enums\CredentialMode;` to the imports):

```php
    public function test_recording_is_off_by_default(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'demo',
            password: 'secret',
        );

        $this->assertFalse($config->record);
        $this->assertSame(20, $config->recordLimit);
        $this->assertSame(CredentialMode::Masked, $config->recordCredentials);
    }

    public function test_from_array_maps_recording_keys(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'demo',
            'password' => 'secret',
            'record' => true,
            'record_limit' => 5,
            'record_credentials' => 'env',
        ]);

        $this->assertTrue($config->record);
        $this->assertSame(5, $config->recordLimit);
        $this->assertSame(CredentialMode::Env, $config->recordCredentials);
    }

    public function test_from_array_falls_back_to_masked_for_an_unknown_credential_mode(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'demo',
            'password' => 'secret',
            'record_credentials' => 'nonsense',
        ]);

        $this->assertSame(CredentialMode::Masked, $config->recordCredentials);
    }
```

Add to `tests/HttpClientRecordingTest.php`:

```php
    public function test_recording_can_be_enabled_from_config(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
            record: true,
            recordLimit: 2,
        );

        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $httpClient->get('GetPrekes');

        $this->assertCount(1, $httpClient->recordings());
        $this->assertSame('***', $httpClient->lastRecording()?->headers['Password']);
    }

    public function test_config_can_request_env_placeholders(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
            record: true,
            recordCredentials: CredentialMode::Env,
        );

        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $httpClient->get('GetPrekes');

        $this->assertSame('$FVS_PASSWORD', $httpClient->lastRecording()?->headers['Password']);
    }

    public function test_config_can_request_credential_capture(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
            record: true,
            recordCredentials: CredentialMode::Real,
        );

        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $httpClient->get('GetPrekes');

        $this->assertSame('secret-password', $httpClient->lastRecording()?->headers['Password']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/FinvaldaConfigTest.php tests/HttpClientRecordingTest.php`
Expected: FAIL — `Unknown named parameter $record` / undefined property `record`.

- [ ] **Step 3: Add the config properties**

In `src/FinvaldaConfig.php`, add the import:

```php
use Finvalda\Enums\CredentialMode;
```

Add three parameters after `public readonly int $floatPrecision = 10,`:

```php
        public readonly bool $record = false,
        public readonly int $recordLimit = 20,
        public readonly CredentialMode $recordCredentials = CredentialMode::Masked,
```

Extend the `new self(...)` call in `fromArray()`, after `floatPrecision:`:

```php
            record: (bool) ($config['record'] ?? false),
            recordLimit: (int) ($config['record_limit'] ?? 20),
            recordCredentials: CredentialMode::tryFrom((string) ($config['record_credentials'] ?? ''))
                ?? CredentialMode::Masked,
```

Add a line to the `fromArray()` docblock, after the `retry` paragraph:

```php
     * The optional `record_credentials` key accepts 'masked' (default), 'env', or
     * 'real'; anything else falls back to 'masked'.
```

- [ ] **Step 4: Wire the constructor**

In `src/HttpClient.php::__construct()`, after the `$this->normalizer = ...` assignment:

```php
        if ($this->config->record) {
            $this->recorder = new Recorder(
                $this->config->recordLimit,
                $this->config->recordCredentials,
            );
        }
```

- [ ] **Step 5: Add the Laravel config block**

In `config/finvalda.php`, insert before the final `];` (after the `retry` array):

```php
    /*
    |--------------------------------------------------------------------------
    | Request Recording
    |--------------------------------------------------------------------------
    |
    | Keep the last N request/response exchanges in memory for inspection via
    | $finvalda->recordings(). Off by default.
    |
    | record_credentials controls how credential values appear in recordings:
    | 'masked' (default) prints ***, 'env' prints shell placeholders such as
    | $FVS_PASSWORD so curl output stays runnable without exposing the secret,
    | and 'real' prints the values verbatim — never use 'real' in production.
    |
    */
    'record' => (bool) env('FINVALDA_RECORD', false),
    'record_limit' => (int) env('FINVALDA_RECORD_LIMIT', 20),
    'record_credentials' => env('FINVALDA_RECORD_CREDENTIALS', 'masked'),
```

- [ ] **Step 6: Run the full suite and static analysis**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/FinvaldaConfig.php src/HttpClient.php config/finvalda.php tests/FinvaldaConfigTest.php tests/HttpClientRecordingTest.php
git commit -m "feat: enable request recording from config and env"
```

---

### Task 7: Documentation

**Files:**
- Modify: `README.md` (TOC around line 15; config example around line 137; Laravel `.env` block around line 159; new section after "Debug Mode" which ends at line 223)
- Modify: `CLAUDE.md` (the `File Structure` block)

**Interfaces:**
- Consumes: the complete public API from Tasks 5-6.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Add the TOC entry**

In `README.md`, after the `- [Logging](#logging)` line:

```markdown
  - [Recording Requests](#recording-requests)
```

- [ ] **Step 2: Extend the config example**

In the `new FinvaldaConfig(...)` example, after the `retry: null,` line:

```php
    record: false,                              // Keep the last N exchanges in memory
    recordLimit: 20,
    recordCredentials: CredentialMode::Masked,  // or Env (placeholders) / Real
```

- [ ] **Step 3: Extend the Laravel `.env` block**

After the retry env lines:

```env
# Optional: keep the last N request/response exchanges in memory
FINVALDA_RECORD=true
FINVALDA_RECORD_LIMIT=20
FINVALDA_RECORD_CREDENTIALS=masked  # masked | env | real
```

- [ ] **Step 4: Add the "Recording Requests" section**

Insert after the Debug Mode section (before `### Retry Policy`):

````markdown
### Recording Requests

Debug mode holds only the last exchange, as arrays, and captures nothing when a request
fails. Recording keeps a short history of exchanges as objects that render themselves —
including failed attempts and each retry.

```php
use Finvalda\Enums\CredentialMode;

$finvalda->record();                                    // last 20 exchanges, credentials masked
$finvalda->record(limit: 5);
$finvalda->record(credentials: CredentialMode::Env);    // $FVS_PASSWORD placeholders
$finvalda->record(credentials: CredentialMode::Real);   // real credentials

$finvalda->sale()->journal('PARD')->client('C001')->create();

echo $finvalda->lastRecording();                        // formatted HTTP text
echo $finvalda->lastRecording()->toCurl();              // curl command

foreach ($finvalda->recordings() as $exchange) {
    echo $exchange->toCurl(), PHP_EOL;
}

$finvalda->stopRecording();                             // stops and drops the buffer
```

Credential modes:

| Mode | Output | Use it when |
|---|---|---|
| `CredentialMode::Masked` (default) | `Password: ***` | Reading recordings, pasting them into an issue |
| `CredentialMode::Env` | `Password: $FVS_PASSWORD` | You want a runnable curl without printing the secret — export the variables first |
| `CredentialMode::Real` | `Password: s3cret` | Local debugging only, never in production |

In Laravel, enable it per environment without touching code:

```env
FINVALDA_RECORD=true
FINVALDA_RECORD_LIMIT=20
FINVALDA_RECORD_CREDENTIALS=masked  # masked | env | real
```

The formatted rendering pretty-prints JSON and expands the payload the API carries in
`xmlstring`, so a write operation is readable at a glance:

```
POST https://your-server.com/FvsServicePure.svc/InsertNewOperation
UserName: demo
Password: ***
Accept: application/json
Language: 0

{
    "ItemClassName": "PardDok",
    "xmlstring": {
        "PardDok": {
            "sZurnalas": "PARD",
            "sKlientas": "C001"
        }
    }
}

--- 200 OK (128.4 ms) ---
{
    "AccessResult": "Success",
    "nResult": 0
}
```

`toCurl()` keeps the body exactly as sent, so the command reproduces the call:

```bash
curl -X POST 'https://your-server.com/FvsServicePure.svc/InsertNewOperation' \
  -H 'UserName: demo' \
  -H 'Password: ***' \
  -H 'Accept: application/json' \
  -H 'Language: 0' \
  -H 'Content-Type: application/json' \
  -d '{"ItemClassName":"PardDok","xmlstring":"{\"PardDok\":{\"sZurnalas\":\"PARD\"}}"}'
```

Under `CredentialMode::Env` the quoting is placeholder-aware, so the command runs as-is once
the variables are exported and the secret never appears in the output:

```bash
export FVS_PASSWORD='your-password'

curl -X POST 'https://your-server.com/FvsServicePure.svc/InsertNewOperation' \
  -H 'UserName: demo' \
  -H 'Password: '"$FVS_PASSWORD" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"ItemClassName":"PardDok","xmlstring":"{\"PardDok\":{\"sZurnalas\":\"PARD\"}}"}'
```

The placeholders are `$FVS_PASSWORD` (the `Password` header), `$FVS_CONN_STRING` (the
`ConnString` header), and `$FVS_SPASSWORD` (the `sPassword` parameter used when changing
another user's password). The SDK only emits them — it never reads them from the environment.

Each `Exchange` exposes `method`, `url`, `headers`, `body`, `statusCode`, `reasonPhrase`,
`responseHeaders`, `responseBody`, `durationMs`, `error`, and `attempt`, plus `toString()`,
`toCurl()`, `toArray()`, and `withCredentials()`.

Worth knowing:

- **Credentials are masked** (`Password`, `ConnString`, `sPassword`) unless you choose
  another mode — substitution happens as the exchange is recorded, so the buffer never holds
  the real password. A masked curl needs the real value substituted before it runs; an `Env`
  curl just needs the variables exported.
- **PSR-3 logging always masks**, whatever the recording mode is set to.
- **`Content-Type: application/json` in curl output is inferred.** Guzzle adds it for JSON
  bodies; the SDK does not set it itself.
- **Recordings are the SDK's view of the request.** If you inject your own Guzzle client
  with extra default headers or middleware, those additions are not reflected.
- **Failures are recorded, then rethrown.** A 4xx/5xx exchange carries the status and error
  body; a connection failure carries `error` with no status.
- **Retries record one exchange per attempt**, each with its own `attempt` number and duration.
- Bodies are stored whole — unlike PSR-3 logging, there is no 100 KB truncation. Keep
  `limit` modest in long-running processes.
````

- [ ] **Step 5: Update `CLAUDE.md`**

In the `File Structure` block, add after the `Pagination/` line (keeping alphabetical order):

```
  Recording/                # Exchange value object + Recorder ring buffer
```

Also extend the `Support/` line of that block if present, and add a line to the `Enums/`
description mentioning `CredentialMode`.

- [ ] **Step 6: Verify the documented examples**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse`
Expected: PASS. Then confirm by eye that every method named in the README section exists: `record()`, `stopRecording()`, `recordings()`, `lastRecording()`, `Exchange::toString()`, `toCurl()`, `toArray()`.

- [ ] **Step 7: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: document request/response recording"
```

---

## Verification

After Task 7:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Both must pass clean. Spot-check that `git log --oneline` shows one commit per task and that
`git diff main --stat` touches only: `src/Support/Redactor.php`, `src/Enums/CredentialMode.php`,
`src/Recording/*`, `src/HttpClient.php`, `src/Finvalda.php`, `src/FinvaldaConfig.php`,
`config/finvalda.php`, `README.md`, `CLAUDE.md`, and the test files.

Finally, grep for leftovers of the old naming: `grep -rn "redacted()" src tests` should return
nothing (the method is `withCredentials()`), and `grep -rn "REDACTED_KEYS" src` should return
nothing (the keys live on `Redactor`).
