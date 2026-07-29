# Request/Response Recording — Design

Date: 2026-07-29

## Goal

Let SDK users capture what the SDK actually sent to Finvalda and what came back, in a
form that is readable at a glance and reproducible as a `curl` command.

The SDK already has two related surfaces:

- **PSR-3 logging** (`FinvaldaConfig::$logger`) — debug records per request and response,
  bodies truncated at 100 KB.
- **Debug mode** (`Finvalda::setDebug(true)` / `getLastDebugInfo()`) — the last request and
  response as plain arrays. Captures nothing when the request fails.

Recording is a third surface, not a replacement: it keeps a short history of exchanges as
value objects that can render themselves.

## Decisions

| Decision | Choice |
|---|---|
| Consumption | History of the last N exchanges (ring buffer) |
| Credentials | Redacted by default, opt in to real values |
| Existing debug mode | Untouched; recording lives alongside |
| Default rendering | HTTP text, pretty JSON, embedded `xmlstring` payload expanded |
| Enablement | Runtime API plus `config/finvalda.php` / env |
| Capture point | Inside `HttpClient::sendRequest()`, from the SDK's own view of the request |

### Why capture inside `HttpClient`, not Guzzle middleware

Guzzle middleware would capture byte-exact PSR-7 requests, but `HttpClient` accepts an
injected `ClientInterface` and every test injects one. Middleware can only attach to a
client the SDK constructs itself, so recording would be inert in tests and silently
inert for any consumer injecting a client.

`HttpClient` already knows the method, endpoint, headers (`buildHeaders()`), query params,
and the normalized JSON body, so it can hand a faithful picture to the recorder while
working identically behind a `MockHandler`.

Accepted trade-off: if a consumer injects their own Guzzle client carrying extra default
headers or middleware, those additions do not appear in recordings. Recordings are the
SDK's view of the request, not literal wire bytes. Documented in the README.

## Components

New namespace `Finvalda\Recording`.

### `Exchange`

Immutable value object for **one request attempt** and its outcome.

Request side:

- `method: string`
- `url: string` — absolute, query string included
- `headers: array<string, string>` — redacted unless credentials capture is on
- `body: ?string` — the exact string handed to Guzzle; `null` for GET

Response side:

- `statusCode: ?int` — `null` when the request threw before any response
- `headers: array<string, list<string>>`
- `body: ?string`
- `durationMs: float`
- `error: ?string` — transport error message when there was no response

Plus `attempt: int` — 1-based retry attempt number.

Rendering methods: `toString()` (aliased by `__toString()`), `toCurl()`, and `toArray()`
returning `['request' => ['method', 'url', 'headers', 'body'], 'response' => ['status_code',
'headers', 'body', 'duration_ms', 'error'], 'attempt' => int]`.

### `Recorder`

Bounded ring buffer.

- `__construct(int $limit = 20, bool $credentials = false)`
- `record(Exchange $exchange): void` — appends, trimming to `limit`
- `all(): list<Exchange>` — oldest to newest
- `last(): ?Exchange`

Redaction is applied **at capture time**, before the `Exchange` is constructed. With
default settings the real password never enters the buffer.

### `Finvalda\Support\Redactor`

`HttpClient::REDACTED_KEYS` and `HttpClient::redact()` move here so log redaction and
recording redaction cannot drift. `HttpClient` delegates; existing log behaviour is
unchanged (top-level keys only: `Password`, `ConnString`, `sPassword`).

`sPassword` matters for query params too — `References::updateUserPassword()` sends it as
a GET parameter.

## Public API

On `Finvalda`, mirrored on `HttpClient`:

```php
$finvalda->record();                                // limit 20, redacted
$finvalda->record(limit: 5, credentials: true);     // real values, runnable curl
$finvalda->recordings();                            // Exchange[] oldest → newest
$finvalda->lastRecording();                         // ?Exchange
$finvalda->stopRecording();                         // stop and drop the buffer
```

On `Finvalda`, `record()` and `stopRecording()` return `$this` for chaining, matching
`setLogger()` and `setDebug()`. The `HttpClient` equivalents return `void`, matching
`setLogger()` / `setDebug()` there.

## Config

`FinvaldaConfig` gains:

- `bool $record = false`
- `int $recordLimit = 20`
- `bool $recordCredentials = false`

`fromArray()` maps `record`, `record_limit`, `record_credentials`.

`config/finvalda.php`:

```php
'record' => (bool) env('FINVALDA_RECORD', false),
'record_limit' => (int) env('FINVALDA_RECORD_LIMIT', 20),
'record_credentials' => (bool) env('FINVALDA_RECORD_CREDENTIALS', false),
```

When `record` is true, `HttpClient` builds the `Recorder` in its constructor, so a Laravel
app enables recording without touching code. `record_credentials` is documented as
never-in-production.

## Rendering

### Formatted (default, `__toString`)

```
POST https://host/FvsServicePure.svc/InsertNewOperation
UserName: demo
Password: ***
Accept: application/json

{
    "ItemClassName": "PardDok",
    "xmlstring": {
        "PardDok": {
            "sZurnalas": "PARD",
            "PardDokPrekeDetEil": [ ... ]
        }
    }
}

--- 200 OK (128 ms) ---
{
    "AccessResult": "Success",
    "nResult": 0
}
```

Rules:

- Bodies pretty-print only when they parse as JSON. Finvalda sometimes answers XML (e.g.
  `GetFvsUser` on some server builds); such bodies pass through verbatim.
- When `xmlstring` holds a JSON string, it is decoded and nested into the pretty output.
  If it does not parse as JSON, it is left as the raw string.
- Pretty printing uses `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
- With no response, the separator reads `--- ERROR: <message> (<n> ms) ---`.

### `toCurl()`

Byte-exact body, no pretty printing, so it reproduces the call:

```
curl -X POST 'https://host/FvsServicePure.svc/InsertNewOperation' \
  -H 'UserName: demo' \
  -H 'Password: ***' \
  -H 'Accept: application/json' \
  -H 'Language: 0' \
  -H 'Content-Type: application/json' \
  -d '{"ItemClassName":"PardDok","xmlstring":"{\"PardDok\":{...}}"}'
```

- Single-quoted values with `'\''` escaping.
- No `-d` for requests without a body.
- `Content-Type: application/json` is **inferred** — Guzzle adds it for `json` bodies; the
  SDK does not set it in `buildHeaders()`. Noted in the README.
- Under default redaction the curl needs the real password substituted before it runs.

## Capture semantics

- Recording happens inside the per-attempt closure in `sendRequest()`, so a retried call
  produces one `Exchange` per attempt, each with its own duration and `attempt` number.
- **Failures are recorded.** The `$this->client->request()` call is wrapped: on a
  `RequestException` carrying a response, the status, headers, and error body are captured;
  on a connection error, `error` holds the message. The exception then rethrows unchanged,
  so error handling is unaffected.
- Duration is measured around the Guzzle call.
- Bodies are stored whole — no 100 KB truncation as in the PSR-3 path. The buffer is short
  and explicitly opted into.
- Recording is off unless enabled; the hot path costs one null check.

## Testing

- `tests/Recording/RecorderTest.php` — buffer trims to `limit`, redaction at capture,
  `credentials: true` preserves values, `last()` on an empty buffer.
- `tests/Recording/ExchangeTest.php` — formatted output including nested `xmlstring`
  expansion and XML passthrough; curl escaping; GET renders without `-d`; error separator.
- `tests/HttpClientRecordingTest.php` — with the existing `MockHandler` setup: success
  recorded; a 500 recorded and then rethrown; a retried call yields two exchanges;
  recording off by default; config-driven enablement via `FinvaldaConfig(record: true)`.
- `tests/FinvaldaConfigTest.php` — `fromArray()` maps the three new keys.
- Existing debug-mode and redaction tests must keep passing unchanged.

## Documentation

- README: a "Recording Requests" subsection after "Debug Mode", covering the runtime API,
  the config/env switches, both renderings, and the two honesty notes (inferred
  `Content-Type`, redacted password in curl).
- README config table: the three new keys.
- `CLAUDE.md`: add `Recording/` to the file structure listing.

## Out of scope

- Callback/stream sinks for live dumping.
- Persisting recordings to disk.
- Env-var placeholder substitution in curl output.
- Replacing or deprecating `setDebug()` / `getLastDebugInfo()`.
