# Usable Logging & Company-Scoped Clients Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every observability surface of the SDK actually work and actually testable — auth headers on the wire, debug info, recordings, and PSR-3 logging — including from a company-scoped client.

**Architecture:** The root cause of most defects is that auth headers exist only in the Guzzle client's constructor defaults (`HttpClient.php:71-75`). Task 1 moves them into per-request options, which fixes three bugs at once and unlocks everything after it: a company-scoped client can then reuse the parent's transport (so mock-handler tests can assert `CompanyID` on the wire, and injected clients keep working), and the clone can share the parent's logger, debug capture and recorder. Tasks 4-7 turn `JsonLinesLogger` from a class into a usable feature: correlatable timestamps, no silent data loss, no silent death, and reachable from Laravel config.

**Tech Stack:** PHP 8.3+, Guzzle 7, PSR-3 (`psr/log` 3), PHPUnit 11, PHPStan 2.

## Global Constraints

- PHP floor is `^8.3`. No `clone with` (8.4), no property hooks (8.4).
- No new Composer dependencies. `psr/log` and `guzzlehttp/guzzle` are already required.
- `vendor/bin/phpunit` must be green (388 tests at plan time) and `vendor/bin/phpstan analyse` must report no errors after **every** task.
- Existing public API must keep working: `Finvalda`, `HttpClient`, `FinvaldaConfig`, `Response`, `OperationResult` signatures already shipped in 3.5.0 are not changed, only added to.
- Match surrounding code style: `declare(strict_types=1)`, promoted readonly constructor properties, `snake_case` test method names prefixed `test_`, tests in `Finvalda\Tests\*` namespaces.
- Everything in this plan lands as unreleased `3.6.0` — the `## [3.6.0]` CHANGELOG section already exists in the working tree and is amended, not appended to.
- All work is currently uncommitted in the working tree (`git status`: modified `CHANGELOG.md`, `CLAUDE.md`, `README.md`, `src/Finvalda.php`, `src/FinvaldaConfig.php`, `src/HttpClient.php`, `tests/FinvaldaConfigTest.php`, `tests/FinvaldaTest.php`; untracked `src/Logging/`, `tests/Logging/`). Task 0 commits that baseline first so later tasks have clean diffs.

---

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `src/HttpClient.php` | Transport. Gains per-request header merging, `withCompanyId()`, uses `LastExchange` | 1, 2, 3 |
| `src/Debug/LastExchange.php` | **New.** Mutable holder for debug-mode's last request/response snapshot, shareable between clients | 2 |
| `src/Finvalda.php` | Memoizes company-scoped clones, builds them from the shared transport | 3 |
| `src/Logging/JsonLinesLogger.php` | PSR-3 file sink. Gains correlatable `ts`, `pid`, reserved-key handling, one-shot failure reporting | 4, 5, 6, 9 |
| `src/FinvaldaConfig.php` | Gains `log_path` → `JsonLinesLogger` in `fromArray()` | 7 |
| `config/finvalda.php` | Gains the `log_path` key | 7 |
| `tests/HttpClientTest.php` | Wire-level header assertions | 1 |
| `tests/Debug/LastExchangeTest.php` | **New.** Holder unit tests | 2 |
| `tests/FinvaldaTest.php` | Company-scoped client behaviour: wire header, shared history, memoization | 3 |
| `tests/Logging/JsonLinesLoggerTest.php` | Logger unit + integration tests | 4, 5, 6, 8, 9 |
| `tests/FinvaldaConfigTest.php` | `log_path` mapping | 7 |
| `README.md`, `CHANGELOG.md`, `CLAUDE.md` | Docs | 9 |

**Phase boundaries** (natural review/release points): Phase 1 = Tasks 0-3 (transport + company clients). Phase 2 = Tasks 4-7 (logger). Phase 3 = Tasks 8-9 (integration + docs).

---

### Task 0: Commit the reviewed baseline

**Files:** none created or modified — this task only commits what is already in the working tree.

- [ ] **Step 1: Confirm the tree is green before committing**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: `OK (388 tests, 1298 assertions)` and `[OK] No errors`

- [ ] **Step 2: Commit**

```bash
git add -A
git commit -m "feat: company-scoped clients and a JSON-lines PSR-3 logger

Adds Finvalda::withCompany()/withoutCompany(), FinvaldaConfig::withCompanyId(),
HttpClient::getConfig() and Finvalda\\Logging\\JsonLinesLogger.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 1: Send auth headers with every request

**Why:** Headers live only in the Guzzle client's constructor defaults. Three verified consequences: (a) a caller-supplied `ClientInterface` sends **no** `UserName`/`Password`/`CompanyID` at all, (b) `getLastDebugInfo()` and recordings *display* `buildHeaders()` regardless, so with an injected client both surfaces report headers that were never sent, (c) no test can assert header presence or omission. Verified there is no other header source: `grep -rn "'headers'" src/` matches only `HttpClient.php:74,303,420` and `Recording/Exchange.php` (read-only).

**Files:**
- Modify: `src/HttpClient.php:71-75` (constructor), `:277-283` (`sendRequest` head), `:303` (debug capture), `:417-423` (`recordedHeaders`)
- Test: `tests/HttpClientTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: no signature changes. After this task `$options['headers']` inside `HttpClient::sendRequest()` always contains the full header set, which Task 3 relies on for transport reuse.

- [ ] **Step 1: Write the failing tests**

Add to `tests/HttpClientTest.php`. Note the file already has a `Middleware::history` helper pattern (`createHttpClientWithHistory`, line 33) — these tests build their own stack because they need a non-default config.

```php
    /**
     * @param  array<int, Response>  $responses
     * @param  array<int, array{request: \Psr\Http\Message\RequestInterface}>  $history
     */
    private function createHttpClientWithConfig(
        FinvaldaConfig $config,
        array $responses,
        array &$history,
    ): HttpClient {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));

        return new HttpClient($config, new Client(['handler' => $handlerStack]));
    }

    public function test_it_sends_credential_headers_on_a_caller_supplied_client(): void
    {
        $history = [];
        $httpClient = $this->createHttpClientWithConfig(
            new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'demo',
                password: 'secret',
                connString: 'Server=db',
                companyId: 'htrailer',
            ),
            [new Response(200, [], json_encode(['AccessResult' => 'Success']))],
            $history,
        );

        $httpClient->get('GetPrekes');

        $request = $history[0]['request'];
        $this->assertSame('demo', $request->getHeaderLine('UserName'));
        $this->assertSame('secret', $request->getHeaderLine('Password'));
        $this->assertSame('Server=db', $request->getHeaderLine('ConnString'));
        $this->assertSame('htrailer', $request->getHeaderLine('CompanyID'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('0', $request->getHeaderLine('Language'));
    }

    public function test_it_omits_the_company_header_when_no_company_is_configured(): void
    {
        $history = [];
        $httpClient = $this->createHttpClientWithConfig(
            new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'demo',
                password: 'secret',
            ),
            [new Response(200, [], json_encode(['AccessResult' => 'Success']))],
            $history,
        );

        $httpClient->get('GetPrekes');

        $request = $history[0]['request'];
        $this->assertFalse($request->hasHeader('CompanyID'));
        $this->assertFalse($request->hasHeader('ConnString'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'headers_on_a_caller_supplied_client|omits_the_company_header'`
Expected: FAIL — both tests fail because the injected client carries no default headers, e.g. `Failed asserting that '' is identical to 'demo'`. This failure *is* bug (a).

- [ ] **Step 3: Merge headers into the request options**

In `src/HttpClient.php`, at the head of `sendRequest()` (immediately after the existing `normalizer` block, before `$attempt = 0;`):

```php
        // Auth headers travel with every request rather than sitting in the
        // Guzzle client's defaults: a caller-supplied ClientInterface would
        // otherwise send none, and the debug/recording surfaces below would
        // report headers that never went out.
        $options['headers'] = array_merge($this->buildHeaders(), $options['headers'] ?? []);
```

- [ ] **Step 4: Stop putting headers in the client defaults**

In the constructor, drop the now-redundant `headers` key so there is exactly one source of truth:

```php
        $this->client = $client ?? new Client([
            'base_uri' => rtrim($this->config->baseUrl, '/') . '/',
            'timeout' => $this->config->timeout,
        ]);
```

- [ ] **Step 5: Read headers from the options in the debug and recording surfaces**

Debug capture inside `sendRequest()` (was `array_merge($this->buildHeaders(), $options['headers'] ?? [])`):

```php
                    'headers' => Redactor::apply($options['headers']),
```

`recordedHeaders()`:

```php
    private function recordedHeaders(array $options): array
    {
        /** @var array<string, string> $headers */
        $headers = $options['headers'] ?? [];

        return $headers;
    }
```

- [ ] **Step 6: Run the new tests, then the whole suite**

Run: `vendor/bin/phpunit --filter 'headers_on_a_caller_supplied_client|omits_the_company_header'`
Expected: PASS

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: all green. The canaries for this change are `tests/HttpClientRedactionTest.php:51-73` and `tests/HttpClientRecordingTest.php:116,145,341,360,610,636` — they assert redacted header *content* in debug info and recordings, which must be unchanged because the options now carry exactly what `buildHeaders()` used to put in the client defaults. If any of them fail, the merge in Step 3 is in the wrong place (it must run before the `$doRequest` closure is defined, because the closure captures `$options` by value).

- [ ] **Step 7: Commit**

```bash
git add src/HttpClient.php tests/HttpClientTest.php
git commit -m "fix: send auth headers with every request instead of only via client defaults

A caller-supplied ClientInterface previously sent no credentials at all, and
debug info and recordings displayed headers that were never on the wire.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Extract the debug snapshot into a shareable holder

**Why:** Task 3 lets a company-scoped client share the parent's debug state. `lastRequest`/`lastResponse` are plain arrays (value semantics), so sharing is impossible without a holder object. This also removes two fields and the clearing logic from `HttpClient`.

**Files:**
- Create: `src/Debug/LastExchange.php`
- Modify: `src/HttpClient.php:57-59` (fields), `:105-113` (`setDebug`), `:121-127` (`getLastDebugInfo`), `:291-298` and `:313-319` (capture sites)
- Test: `tests/Debug/LastExchangeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Finvalda\Debug\LastExchange` with `setRequest(array $request): void`, `setResponse(array $response): void`, `toArray(): array{request: array, response: array}`, `clear(): void`. Task 3 shares one instance between transports.

- [ ] **Step 1: Write the failing test**

Create `tests/Debug/LastExchangeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Tests\Debug;

use Finvalda\Debug\LastExchange;
use PHPUnit\Framework\TestCase;

class LastExchangeTest extends TestCase
{
    public function test_it_starts_empty(): void
    {
        $this->assertSame(['request' => [], 'response' => []], (new LastExchange())->toArray());
    }

    public function test_it_keeps_the_most_recent_request_and_response(): void
    {
        $exchange = new LastExchange();

        $exchange->setRequest(['method' => 'GET']);
        $exchange->setResponse(['status_code' => 200]);
        $exchange->setRequest(['method' => 'POST']);

        $this->assertSame(
            ['request' => ['method' => 'POST'], 'response' => ['status_code' => 200]],
            $exchange->toArray(),
        );
    }

    public function test_clear_drops_both_sides(): void
    {
        $exchange = new LastExchange();
        $exchange->setRequest(['method' => 'GET']);
        $exchange->setResponse(['status_code' => 200]);

        $exchange->clear();

        $this->assertSame(['request' => [], 'response' => []], $exchange->toArray());
    }

    public function test_a_shared_instance_is_visible_to_every_holder(): void
    {
        $exchange = new LastExchange();
        $alias = $exchange;

        $exchange->setRequest(['method' => 'GET']);

        $this->assertSame(['method' => 'GET'], $alias->toArray()['request']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Debug/LastExchangeTest.php`
Expected: FAIL — `Error: Class "Finvalda\Debug\LastExchange" not found`

- [ ] **Step 3: Write the holder**

Create `src/Debug/LastExchange.php`:

```php
<?php

declare(strict_types=1);

namespace Finvalda\Debug;

/**
 * The last request/response snapshot captured while debug mode is on. A mutable
 * object rather than two arrays on HttpClient so that a company-scoped client
 * created with HttpClient::withCompanyId() writes into the same snapshot its
 * parent reads from.
 */
final class LastExchange
{
    /** @var array<string, mixed> */
    private array $request = [];

    /** @var array<string, mixed> */
    private array $response = [];

    /**
     * @param  array<string, mixed>  $request
     */
    public function setRequest(array $request): void
    {
        $this->request = $request;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    /**
     * @return array{request: array<string, mixed>, response: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['request' => $this->request, 'response' => $this->response];
    }

    public function clear(): void
    {
        $this->request = [];
        $this->response = [];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Debug/LastExchangeTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Use the holder in HttpClient**

Replace the two array fields:

```php
    private LastExchange $lastExchange;
```

Add `use Finvalda\Debug\LastExchange;` to the imports and initialise it in the constructor next to `$this->normalizer = ...`:

```php
        $this->lastExchange = new LastExchange();
```

`setDebug()`:

```php
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;

        if (! $debug) {
            $this->lastExchange->clear();
        }
    }
```

`getLastDebugInfo()`:

```php
    public function getLastDebugInfo(): array
    {
        return $this->lastExchange->toArray();
    }
```

The two capture sites in `sendRequest()` become `$this->lastExchange->setRequest([...]);` and `$this->lastExchange->setResponse([...]);` with the same array literals as before.

- [ ] **Step 6: Run the whole suite**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: all green. `tests/HttpClientTest.php:254-260` and `tests/HttpClientRedactionTest.php:51` exercise `getLastDebugInfo()` and must still pass unchanged.

- [ ] **Step 7: Commit**

```bash
git add src/Debug tests/Debug src/HttpClient.php
git commit -m "refactor: hold the debug snapshot in a shareable LastExchange object

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Make a company-scoped client share the parent's transport and observability

**Why:** As shipped, `withCompany()` builds a brand-new `HttpClient`, which (a) silently discards a caller-supplied transport — in a test that means real network calls to the configured base URL — and (b) starts with no logger, no debug and no recording, so `record()` followed by `withoutCompany()->reports()->makeInvoicePdf()` records nothing: the 3.5.0 feature defeated by the 3.6.0 one. After Task 1 the transport no longer carries company identity, so the clone can reuse it.

**Files:**
- Modify: `src/HttpClient.php` (add `withCompanyId()`), `src/Finvalda.php` (memoize clones, build from the shared transport, fix docblocks)
- Test: `tests/FinvaldaTest.php`

**Interfaces:**
- Consumes: `FinvaldaConfig::withCompanyId(?string): self` (already shipped), `HttpClient::getConfig(): FinvaldaConfig` (already shipped), `Finvalda\Debug\LastExchange` (Task 2).
- Produces: `HttpClient::withCompanyId(?string $companyId): self`. `Finvalda::withCompany(?string $companyId): self` becomes memoized per company.

- [ ] **Step 1: Write the failing tests**

Add to `tests/FinvaldaTest.php`. These replace nothing; the two company tests already in the file (`test_with_company_returns_a_client_bound_to_another_company`, `test_without_company_returns_a_client_bound_to_the_default_company`) stay, except that the `assertNotSame($finvalda->getHttpClient(), $other->getHttpClient())` assertion in the first one remains true (a new `HttpClient` instance, sharing the same Guzzle client).

```php
    /**
     * @param  array<int, GuzzleResponse>  $responses
     * @param  array<int, array{request: \Psr\Http\Message\RequestInterface}>  $history
     */
    private function createFinvaldaWithHistory(
        FinvaldaConfig $config,
        array $responses,
        array &$history,
    ): Finvalda {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));

        return new Finvalda($config, new HttpClient($config, new Client(['handler' => $handlerStack])));
    }

    public function test_a_company_scoped_client_reuses_the_injected_transport(): void
    {
        $history = [];
        $finvalda = $this->createFinvaldaWithHistory(
            new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'demo',
                password: 'secret',
                companyId: 'htrailer',
            ),
            [new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success']))],
            $history,
        );

        $finvalda->withoutCompany()->products()->all();

        $this->assertCount(1, $history, 'the clone did not use the injected transport');
        $this->assertFalse($history[0]['request']->hasHeader('CompanyID'));
        $this->assertSame('demo', $history[0]['request']->getHeaderLine('UserName'));
    }

    public function test_a_company_scoped_client_sends_the_named_company(): void
    {
        $history = [];
        $finvalda = $this->createFinvaldaWithHistory(
            new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'demo',
                password: 'secret',
                companyId: 'htrailer',
            ),
            [new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success']))],
            $history,
        );

        $finvalda->withCompany('HTNT')->products()->all();

        $this->assertSame('HTNT', $history[0]['request']->getHeaderLine('CompanyID'));
    }

    public function test_a_company_scoped_call_lands_in_the_parents_recordings(): void
    {
        $history = [];
        $finvalda = $this->createFinvaldaWithHistory(
            new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'demo',
                password: 'secret',
                companyId: 'htrailer',
            ),
            [new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success']))],
            $history,
        );

        $finvalda->record();
        $finvalda->withoutCompany()->products()->all();

        $this->assertCount(1, $finvalda->recordings());
    }

    public function test_a_company_scoped_call_lands_in_the_parents_debug_info(): void
    {
        $history = [];
        $finvalda = $this->createFinvaldaWithHistory(
            new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'demo',
                password: 'secret',
                companyId: 'htrailer',
            ),
            [new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success']))],
            $history,
        );

        $finvalda->setDebug(true);
        $finvalda->withoutCompany()->products()->all();

        $debug = $finvalda->getLastDebugInfo();
        $this->assertSame(200, $debug['response']['status_code']);
        $this->assertArrayNotHasKey('CompanyID', $debug['request']['headers']);
    }

    public function test_the_same_company_returns_the_same_client(): void
    {
        $finvalda = new Finvalda(new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'demo',
            password: 'secret',
            companyId: 'htrailer',
        ));

        $this->assertSame($finvalda->withoutCompany(), $finvalda->withoutCompany());
        $this->assertSame($finvalda->withCompany('HTNT'), $finvalda->withCompany('HTNT'));
        $this->assertNotSame($finvalda->withCompany('HTNT'), $finvalda->withoutCompany());
    }
```

`createFinvaldaWithHistory` takes `$history` by reference, so every test calling it must declare `$history = [];` first — including the two that never read it back.

Add to the imports of `tests/FinvaldaTest.php`: `use GuzzleHttp\Middleware;`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'company_scoped|same_company_returns'`
Expected: FAIL. `test_a_company_scoped_client_reuses_the_injected_transport` fails with `assertCount(1, $history)` seeing 0 — that is bug (a): the clone built its own transport and tried to reach the network. The recordings/debug tests fail with 0 recordings and an empty debug array. `test_the_same_company_returns_the_same_client` fails on the first `assertSame`.

- [ ] **Step 3: Add `HttpClient::withCompanyId()`**

```php
    /**
     * A transport bound to another company — or, with null, to Finvalda's
     * default company, which omits the CompanyID header.
     *
     * Shares this transport's Guzzle client, logger, debug capture and recorder,
     * so a company-scoped call still shows up in this client's getLastDebugInfo()
     * and recordings(). Safe with a caller-supplied client: since headers are
     * built per request, company identity does not live in the transport.
     */
    public function withCompanyId(?string $companyId): self
    {
        $copy = new self($this->config->withCompanyId($companyId), $this->client);
        $copy->logger = $this->logger;
        $copy->debug = $this->debug;
        $copy->recorder = $this->recorder;
        $copy->lastExchange = $this->lastExchange;

        return $copy;
    }
```

- [ ] **Step 4: Build and memoize the clone in `Finvalda`**

Replace the shipped `withCompany()` body and update the docblock:

```php
    /** @var array<string, self> */
    private array $companyClients = [];

    /**
     * A client identical to this one but bound to another company — or, with
     * null, to Finvalda's default company, which omits the CompanyID header.
     * Useful for report templates, which are registered per company: a template
     * living only on the default company renders documents created elsewhere.
     *
     * The returned client shares this one's transport, logger, debug capture and
     * recorder, so its calls stay visible in getLastDebugInfo() and recordings().
     * Repeated calls for the same company return the same client.
     */
    public function withCompany(?string $companyId): self
    {
        // "\0default" cannot collide with a real company id.
        $key = $companyId ?? "\0default";

        if (! isset($this->companyClients[$key])) {
            $http = $this->http->withCompanyId($companyId);
            $this->companyClients[$key] = new self($http->getConfig(), $http);
        }

        return $this->companyClients[$key];
    }
```

- [ ] **Step 5: Run the tests, then the whole suite**

Run: `vendor/bin/phpunit --filter 'company_scoped|same_company_returns'`
Expected: PASS

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/HttpClient.php src/Finvalda.php tests/FinvaldaTest.php
git commit -m "fix: company-scoped clients share the transport, recorder and debug capture

withCompany() previously built a fresh HttpClient, discarding a caller-supplied
transport and starting with logging, debug and recording switched off.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Correlatable timestamps and a pid in every log entry

**Why:** `date('Y-m-d H:i:s')` has no sub-second precision, so a request and its response — which almost always land in the same second — cannot be ordered by `ts`, and no offset means a container on UTC cannot be correlated with an app that thinks it is on Europe/Vilnius. `date('v')` does **not** help (verified: `date('Y-m-d\TH:i:s.vP')` yields `.000` because `date()` takes a whole-second timestamp) — it needs `DateTimeImmutable`. And with concurrent writers appending to one file, `LOCK_EX` keeps lines intact but interleaves them, so request/response adjacency is meaningless without a `pid` to group by.

**Files:**
- Modify: `src/Logging/JsonLinesLogger.php`
- Test: `tests/Logging/JsonLinesLoggerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: entry keys `ts` (ISO 8601 with milliseconds and offset), `pid`, `level`, `message`. Task 5 adds the reserved-key rule over the same set.

- [ ] **Step 1: Write the failing test**

Replace the existing `test_each_entry_carries_a_timestamp_and_level` in `tests/Logging/JsonLinesLoggerTest.php` with:

```php
    public function test_each_entry_carries_an_ordered_timestamp_a_pid_and_a_level(): void
    {
        $path = $this->dir . '/finvalda.log';

        (new JsonLinesLogger($path))->debug('Finvalda API request');

        $entry = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('debug', $entry['level']);
        $this->assertSame(getmypid(), $entry['pid']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/',
            $entry['ts'],
            'ts needs millisecond precision and an offset to order and correlate entries',
        );
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter test_each_entry_carries_an_ordered_timestamp`
Expected: FAIL — `Failed asserting that '2026-07-30 10:44:16' matches PCRE pattern` (and `pid` is missing).

- [ ] **Step 3: Change the entry head**

In `log()`, replace the `$entry` head. Add `use DateTimeImmutable;` to the imports.

```php
            $entry = [
                'ts' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
                'pid' => getmypid(),
                'level' => is_scalar($level) ? (string) $level : gettype($level),
                'message' => (string) $message,
            ] + $this->truncate($context);
```

- [ ] **Step 4: Run the test, then the whole suite**

Run: `vendor/bin/phpunit tests/Logging/JsonLinesLoggerTest.php`
Expected: PASS (5 tests)

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src/Logging/JsonLinesLogger.php tests/Logging/JsonLinesLoggerTest.php
git commit -m "fix: log entries get millisecond ISO timestamps and a pid

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Stop silently dropping colliding context keys

**Why:** `$entry + $this->truncate($context)` keeps the left operand, so a consumer who logs `['message' => ...]`, `['level' => ...]`, `['ts' => ...]` or `['pid' => ...]` loses that value with no signal. The sink is a general-purpose PSR-3 logger; this will happen.

**Files:**
- Modify: `src/Logging/JsonLinesLogger.php`
- Test: `tests/Logging/JsonLinesLoggerTest.php`

**Interfaces:**
- Consumes: the entry head from Task 4.
- Produces: colliding context keys are written prefixed with `context_` (e.g. `context_message`).

- [ ] **Step 1: Write the failing test**

```php
    public function test_it_keeps_context_values_that_collide_with_reserved_keys(): void
    {
        $path = $this->dir . '/finvalda.log';

        (new JsonLinesLogger($path))->debug('Finvalda API request', [
            'message' => 'from the context',
            'level' => 'from the context too',
            'endpoint' => 'GetPrekes',
        ]);

        $entry = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Finvalda API request', $entry['message']);
        $this->assertSame('debug', $entry['level']);
        $this->assertSame('from the context', $entry['context_message']);
        $this->assertSame('from the context too', $entry['context_level']);
        $this->assertSame('GetPrekes', $entry['endpoint']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter test_it_keeps_context_values_that_collide`
Expected: FAIL — `Undefined array key "context_message"`.

- [ ] **Step 3: Prefix colliding keys**

Add the constant next to `MAX_BYTES`-style declarations at the top of the class:

```php
    /**
     * Entry keys the logger owns. A context key of the same name is written
     * prefixed with `context_` rather than silently dropped.
     */
    private const RESERVED_KEYS = ['ts', 'pid', 'level', 'message'];
```

Replace the `+ $this->truncate($context)` union with an explicit merge:

```php
            $entry = [
                'ts' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
                'pid' => getmypid(),
                'level' => is_scalar($level) ? (string) $level : gettype($level),
                'message' => (string) $message,
            ];

            foreach ($this->truncate($context) as $key => $value) {
                $entry[in_array($key, self::RESERVED_KEYS, true) ? "context_{$key}" : $key] = $value;
            }
```

- [ ] **Step 4: Run the test, then the whole suite**

Run: `vendor/bin/phpunit tests/Logging/JsonLinesLoggerTest.php`
Expected: PASS (6 tests)

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src/Logging/JsonLinesLogger.php tests/Logging/JsonLinesLoggerTest.php
git commit -m "fix: keep context values that collide with reserved log entry keys

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Report a broken sink once, and actually test the never-throws guarantee

**Why:** Two defects. (1) A typo'd path produces no file, no error and no clue, forever. (2) The shipped test `test_it_does_not_throw_when_the_path_cannot_be_written` does not test that: verified that `@mkdir` and `@file_put_contents` both return `false` without throwing, so deleting the `try/catch` leaves the test green. A `Stringable` message whose `__toString()` throws is a real failure path and does prove the guard.

**Files:**
- Modify: `src/Logging/JsonLinesLogger.php`
- Test: `tests/Logging/JsonLinesLoggerTest.php`

**Interfaces:**
- Consumes: the entry construction from Tasks 4-5.
- Produces: one `error_log()` line per logger instance on first failure, prefixed `Finvalda JsonLinesLogger:`.

- [ ] **Step 1: Write the failing tests**

Delete `test_it_does_not_throw_when_the_path_cannot_be_written` (it asserts a proxy for a guarantee it cannot fail on) and add:

```php
    public function test_it_reports_an_unwritable_path_to_the_php_error_log_once(): void
    {
        mkdir($this->dir, 0o775, true);
        $blocker = $this->dir . '/blocker';
        touch($blocker);

        // A file where a directory is expected: mkdir and the write both fail.
        $logger = new JsonLinesLogger($blocker . '/finvalda.log');

        $errors = $this->captureErrorLog(function () use ($logger): void {
            $logger->debug('Finvalda API request');
            $logger->debug('Finvalda API response');
        });

        $this->assertSame(1, substr_count($errors, 'Finvalda JsonLinesLogger:'));
        $this->assertStringContainsString($blocker, $errors);
        $this->assertSame('', file_get_contents($blocker));
    }

    public function test_it_does_not_throw_when_the_message_cannot_be_stringified(): void
    {
        $path = $this->dir . '/finvalda.log';
        $message = new class implements \Stringable
        {
            public function __toString(): string
            {
                throw new \RuntimeException('boom');
            }
        };

        $errors = $this->captureErrorLog(function () use ($path, $message): void {
            (new JsonLinesLogger($path))->debug($message);
        });

        $this->assertStringContainsString('boom', $errors);
        $this->assertFileDoesNotExist($path);
    }

    public function test_it_reports_a_context_value_that_cannot_be_encoded(): void
    {
        $path = $this->dir . '/finvalda.log';
        $handle = fopen('php://memory', 'r');

        $errors = $this->captureErrorLog(function () use ($path, $handle): void {
            (new JsonLinesLogger($path))->debug('Finvalda API request', ['handle' => $handle]);
        });

        fclose($handle);

        $this->assertStringContainsString('Finvalda JsonLinesLogger:', $errors);
        $this->assertFileDoesNotExist($path);
    }

    /**
     * Run $work with error_log() redirected to a file, and return what it wrote.
     */
    private function captureErrorLog(callable $work): string
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0o775, true);
        }

        $errorLog = $this->dir . '/php-error.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $work();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        return is_file($errorLog) ? (string) file_get_contents($errorLog) : '';
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'reports_an_unwritable_path|cannot_be_stringified|cannot_be_encoded'`
Expected: FAIL — all three assert on error-log content that is never written (`Failed asserting that 0 is identical to 1`, empty `$errors`). Note `test_it_does_not_throw_when_the_message_cannot_be_stringified` fails on the error-log assertion, **not** with an uncaught exception: the shipped `try/catch` already swallows it, which is exactly why the old test proved nothing.

- [ ] **Step 3: Report failures once**

Add the field and the reporter to `JsonLinesLogger`:

```php
    private bool $reportedFailure = false;
```

```php
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
```

Wire it into the three failure points in `log()`:

```php
            if ($line === false) {
                $this->reportFailure('could not encode a record as JSON: ' . json_last_error_msg());

                return;
            }

            $directory = dirname($this->path);

            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            if (@file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
                $this->reportFailure('could not write to the log file');
            }
        } catch (Throwable $e) {
            $this->reportFailure($e::class . ': ' . $e->getMessage());
        }
```

Update the class docblock: the sink now reports its first failure through the PHP error log instead of failing silently.

- [ ] **Step 4: Run the tests, then the whole suite**

Run: `vendor/bin/phpunit tests/Logging/JsonLinesLoggerTest.php`
Expected: PASS (8 tests)

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: all green. If `phpunit` reports risky/warning output, the `error_log` redirection in `captureErrorLog` is not wrapping every failing call — every test that triggers a failure must go through it, or the message lands on stderr.

- [ ] **Step 5: Verify the guard is genuinely covered**

Temporarily delete the `try { ... } catch (Throwable $e) { ... }` wrapper in `log()` and run:

Run: `vendor/bin/phpunit --filter test_it_does_not_throw_when_the_message_cannot_be_stringified`
Expected: FAIL with `RuntimeException: boom` — proving the test now covers the guarantee. Restore the wrapper and re-run to confirm PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Logging/JsonLinesLogger.php tests/Logging/JsonLinesLoggerTest.php
git commit -m "fix: report the first JsonLinesLogger failure instead of dying silently

Also replaces a test that could not fail with one that covers the never-throws
guarantee via a Stringable message that throws.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Reach the logger from configuration (`log_path`)

**Why:** In Laravel — the SDK's main integration — `FinvaldaServiceProvider.php:21-26` builds a logger only from `log_channel`, so `JsonLinesLogger` is unreachable without bypassing the provider and hand-building `FinvaldaConfig`. Putting the resolution in `FinvaldaConfig::fromArray()` rather than the provider means it is unit-testable without `orchestra/testbench` (not a dev dependency), it works for non-Laravel consumers too, and the chosen precedence falls out for free: the provider passes a non-null `$logger` when `log_channel` is set, so the channel wins with no extra branch.

**Files:**
- Modify: `src/FinvaldaConfig.php` (`fromArray()`), `config/finvalda.php:96-105`
- Test: `tests/FinvaldaConfigTest.php`
- Do **not** modify: `src/Laravel/FinvaldaServiceProvider.php` — no change is needed.

**Interfaces:**
- Consumes: `Finvalda\Logging\JsonLinesLogger::__construct(string $path, int $maxBodyBytes = 200_000)`.
- Produces: `fromArray()` honours a `log_path` key when no `$logger` argument is given.

- [ ] **Step 1: Write the failing tests**

Add to `tests/FinvaldaConfigTest.php` (add `use Finvalda\Logging\JsonLinesLogger;` to the imports):

```php
    public function test_from_array_builds_a_json_lines_logger_from_log_path(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'demo',
            'password' => 'secret',
            'log_path' => '/tmp/finvalda-test.log',
        ]);

        $this->assertInstanceOf(JsonLinesLogger::class, $config->logger);
    }

    public function test_from_array_prefers_an_explicit_logger_over_log_path(): void
    {
        $logger = new NullLogger();

        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'demo',
            'password' => 'secret',
            'log_path' => '/tmp/finvalda-test.log',
        ], $logger);

        $this->assertSame($logger, $config->logger);
    }

    public function test_from_array_ignores_an_empty_log_path(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'demo',
            'password' => 'secret',
            'log_path' => '',
        ]);

        $this->assertNull($config->logger);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'log_path'`
Expected: FAIL — the first test fails with `null is not an instance of Finvalda\Logging\JsonLinesLogger`; the other two pass already (they assert current behaviour and guard the new branch).

- [ ] **Step 3: Resolve `log_path` in `fromArray()`**

Add `use Finvalda\Logging\JsonLinesLogger;` to the imports of `src/FinvaldaConfig.php`, and insert before the `return new self(` in `fromArray()`:

```php
        // An explicitly passed logger wins: the Laravel provider passes one when
        // `log_channel` is configured.
        if ($logger === null && ! empty($config['log_path'])) {
            $logger = new JsonLinesLogger((string) $config['log_path']);
        }
```

Extend the `fromArray()` docblock with a line:

```
     * The optional `log_path` key builds a JsonLinesLogger when no $logger is
     * passed in; an explicit $logger always wins.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'log_path'`
Expected: PASS (3 tests)

- [ ] **Step 5: Add the config key**

In `config/finvalda.php`, extend the existing Logging block (lines 96-105) to:

```php
    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Laravel log channel for SDK request/response debug records. Leave null
    | to disable SDK logging.
    |
    | log_path is an alternative sink with no framework involved: one JSON
    | object per line, appended to the given file, greppable with jq. When both
    | are set, log_channel wins.
    |
    */
    'log_channel' => env('FINVALDA_LOG_CHANNEL'),
    'log_path' => env('FINVALDA_LOG_PATH'),
```

- [ ] **Step 6: Run the whole suite**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/FinvaldaConfig.php config/finvalda.php tests/FinvaldaConfigTest.php
git commit -m "feat: build a JsonLinesLogger from a log_path config key

Makes the JSON-lines sink reachable from Laravel config without touching the
service provider; an explicit logger (log_channel) still wins.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Prove the logger works on a real request/response cycle

**Why:** Every logger test so far uses a hand-written context. Nothing asserts that the SDK's actual log records (`HttpClient::logRequest()`/`logResponse()`, `HttpClient.php:436-467`) land as usable JSON lines — which is the whole point of the feature, and the thing the flat-key layout is designed for.

**Files:**
- Test: `tests/Logging/JsonLinesLoggerTest.php`

**Interfaces:**
- Consumes: `FinvaldaConfig`, `HttpClient`, `JsonLinesLogger`, Guzzle `MockHandler`.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Add to `tests/Logging/JsonLinesLoggerTest.php` (imports: `Finvalda\FinvaldaConfig`, `Finvalda\HttpClient`, `GuzzleHttp\Client`, `GuzzleHttp\Handler\MockHandler`, `GuzzleHttp\HandlerStack`, `GuzzleHttp\Psr7\Response`):

```php
    public function test_it_records_a_real_request_and_response_cycle_as_two_lines(): void
    {
        $path = $this->dir . '/finvalda.log';
        $guzzle = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode(['AccessResult' => 'Success', 'Data' => []])),
            ])),
        ]);

        $httpClient = new HttpClient(new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'demo',
            password: 'secret',
            logger: new JsonLinesLogger($path),
        ), $guzzle);

        $httpClient->get('GetPrekes', ['sKodas' => 'ABC']);

        $lines = array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES),
        );

        $this->assertCount(2, $lines);

        $this->assertSame('Finvalda API request', $lines[0]['message']);
        $this->assertSame('GET', $lines[0]['method']);
        $this->assertSame('GetPrekes', $lines[0]['endpoint']);
        $this->assertSame('ABC', $lines[0]['params']['sKodas']);

        $this->assertSame('Finvalda API response', $lines[1]['message']);
        $this->assertSame(200, $lines[1]['status_code']);
        $this->assertStringContainsString('AccessResult', $lines[1]['body']);
        $this->assertSame($lines[0]['pid'], $lines[1]['pid']);
    }
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/phpunit --filter test_it_records_a_real_request_and_response_cycle`
Expected: PASS. This one is written after the code it covers, so it is a regression guard rather than a driver — if it fails, the failure is a real defect in the wiring, not a missing feature. Investigate before changing the test.

- [ ] **Step 3: Verify it guards something**

Temporarily change `logRequest()`'s message in `src/HttpClient.php` from `'Finvalda API request'` to `'request'` and re-run:

Run: `vendor/bin/phpunit --filter test_it_records_a_real_request_and_response_cycle`
Expected: FAIL. Revert the change and confirm PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Logging/JsonLinesLoggerTest.php
git commit -m "test: cover a full request/response cycle through JsonLinesLogger

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Documentation and remaining nits

**Files:**
- Modify: `src/Logging/JsonLinesLogger.php` (level handling), `README.md`, `CHANGELOG.md`, `CLAUDE.md`
- Test: `tests/Logging/JsonLinesLoggerTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Write the failing test for a `Stringable` level**

PSR-3 allows any level value. `is_scalar($level)` misses `Stringable`, and `gettype()` then writes a useless `"object"`.

```php
    public function test_it_stringifies_a_stringable_level(): void
    {
        $path = $this->dir . '/finvalda.log';
        $level = new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'notice';
            }
        };

        (new JsonLinesLogger($path))->log($level, 'Finvalda API request');

        $entry = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('notice', $entry['level']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter test_it_stringifies_a_stringable_level`
Expected: FAIL — `Failed asserting that 'object' is identical to 'notice'`.

- [ ] **Step 3: Handle it**

```php
                'level' => match (true) {
                    is_string($level) => $level,
                    is_scalar($level), $level instanceof Stringable => (string) $level,
                    default => get_debug_type($level),
                },
```

- [ ] **Step 4: Run the test, then the whole suite**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: all green.

- [ ] **Step 5: Update the README**

In the "Company-Scoped Clients" section, replace the paragraph beginning "Both return a **new** client." with:

```markdown
Both return a client that shares this one's transport, logger, debug capture and
recorder — so a company-scoped call still shows up in `getLastDebugInfo()` and
`recordings()`, and a custom `HttpClient` you injected keeps being used. Repeated
calls for the same company return the same client, so calling this in a loop is fine.
```

In the `JsonLinesLogger` subsection, replace the closing paragraph ("Context keys are merged…") with:

```markdown
Each entry carries `ts` (ISO 8601, milliseconds, with offset), `pid`, `level` and
`message`, plus the context keys merged in flat; a context key colliding with one of
those four is written prefixed, e.g. `context_message`. The `pid` matters when several
processes append to one file: `LOCK_EX` keeps lines intact but interleaves them, so
group by `pid` rather than by adjacency. Missing directories are created. There is no
rotation (use logrotate), no buffering and no level filter. A failing sink cannot break
an API call: the first failure on each logger instance is reported through the PHP error
log and the rest are silent. Credentials are already redacted before a record reaches
any logger, so the sink does not redact again.
```

Then add, as plain README text (not inside the block above), a line and an `env` fenced
block: in Laravel, set `FINVALDA_LOG_PATH=/var/log/finvalda/finvalda.log` instead of
constructing the logger by hand — `FINVALDA_LOG_CHANNEL` takes precedence when both are
set. Add the same variable to the existing `.env` example in the "Laravel Integration"
section, under the `FINVALDA_LOG_CHANNEL` line.

- [ ] **Step 6: Update the CHANGELOG**

Amend the existing unreleased `## [3.6.0]` section: keep the two "Added" subsections, correcting the `withCompany()` bullet (it now shares transport/logger/debug/recorder and memoizes per company — drop the "do not carry over" and "cache the copy" caveats) and the logger bullets (`ts`/`pid`/reserved keys/`log_path`/one-shot failure reporting). Add a third subsection:

```markdown
### Fixed

- **Auth headers are sent with every request** instead of living only in the Guzzle
  client's constructor defaults. A caller-supplied `ClientInterface` previously sent no
  `UserName`, `Password` or `CompanyID` at all, and `getLastDebugInfo()` and recordings
  displayed headers that had never gone out. Header presence is now assertable in tests.
- **`Finvalda\Debug\LastExchange`** holds the debug-mode snapshot, so a company-scoped
  client writes into the same snapshot its parent reads.
```

- [ ] **Step 7: Update CLAUDE.md**

In the File Structure block, add next to the existing `Logging/` line:

```
  Debug/                    # LastExchange (shared debug-mode request/response snapshot)
```

In the "HTTP Transport Patterns" section, add one line under the intro:

```markdown
- **Headers** — `buildHeaders()` is merged into `$options['headers']` on every request, not set as Guzzle client defaults, so an injected `ClientInterface` still authenticates and tests can assert headers on the wire.
```

- [ ] **Step 8: Sanity-check the docs against the code**

Run: `grep -n "do not carry over\|Cache the copy\|fails silently" README.md CHANGELOG.md src/Finvalda.php src/Logging/JsonLinesLogger.php`
Expected: no matches — every one of those claims is false after this plan.

- [ ] **Step 9: Commit**

```bash
git add src tests README.md CHANGELOG.md CLAUDE.md
git commit -m "docs: document shared company-scoped clients and the JSON-lines sink

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10 (optional): Collapse the 17-field copy-with to one line

**Why:** `FinvaldaConfig::withCompanyId()` forwards all 17 fields by hand. The reflection
parity test catches a forgotten field, but the drift is still possible to write. A
named-argument spread over `get_object_vars()` makes it structurally impossible.
**Trade-off:** the explicit version is greppable and survives an IDE rename; the spread
is opaque to PHPStan and to "find usages" on a property. Run this task only if you
prefer the one-liner — the parity test guards both versions equally.

**Verified working on PHP 8.3+:** promoted constructor property names match the parameter
names, `get_object_vars($this)` inside the class returns all of them including private
ones, and a later explicit key in an array literal overrides the spread.

**Files:**
- Modify: `src/FinvaldaConfig.php`
- Test: none — `tests/FinvaldaConfigTest.php::test_with_company_id_carries_every_other_field_over_unchanged` already covers it.

- [ ] **Step 1: Confirm the existing test passes before the change**

Run: `vendor/bin/phpunit --filter test_with_company_id_carries_every_other_field_over_unchanged`
Expected: PASS

- [ ] **Step 2: Replace the body**

```php
    public function withCompanyId(?string $companyId): self
    {
        return new self(...[...get_object_vars($this), 'companyId' => $companyId]);
    }
```

- [ ] **Step 3: Confirm the test still passes and PHPStan is still clean**

Run: `vendor/bin/phpunit --filter test_with_company_id && vendor/bin/phpstan analyse --no-progress`
Expected: PASS and `[OK] No errors`. If PHPStan objects to the named-argument spread, revert this task — the explicit version is the fallback, not a compromise.

- [ ] **Step 4: Commit**

```bash
git add src/FinvaldaConfig.php
git commit -m "refactor: derive withCompanyId() from the current property values

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Out of scope (deliberately)

- **`maxBodyBytes` truncation in `JsonLinesLogger`.** It cannot fire on SDK records (`HttpClient` truncates to 100 KB first; the logger's cap is 200 KB), so for SDK use it is a recursive array copy per log call that never changes anything. It stays because the sink is a general-purpose PSR-3 logger and an unbounded line makes a log file unusable. Delete `truncate()`, the constructor parameter and its test only if you decide the sink is SDK-records-only.
- ~~**A shared `setLogger()` after cloning.**~~ **Overruled during execution (2026-07-30).** Task 3's review showed the by-value copy is worse than this note assumed: because `withCompany()` memoizes, a child created before `record()`/`setDebug()`/`setLogger()` is stale *permanently*, `setDebug(false)` on the parent leaves the child's flag on, and — decisively — each client's `record()` builds its **own** `Recorder`, so broadcasting the setters would still split recordings across buffers instead of the single ordered history this plan promises. Ruling: correctness governs. Task 3 gains a shared `Finvalda\Debug\Diagnostics` holder (logger + debug flag + `LastExchange` + `Recorder`) that `HttpClient::withCompanyId()` passes by reference — which also collapses four `HttpClient` fields into one, so a future copy cannot forget a field.
- **Log rotation.** Operators use logrotate. Rotation from inside the process invites partial-line corruption under concurrent `LOCK_EX` appends.
- **Renaming `withoutCompany()`.** It selects Finvalda's *default* company rather than removing the concept, so `forDefaultCompany()` would be more accurate — but it is already released-shaped in this tree and reads better at the call site.
