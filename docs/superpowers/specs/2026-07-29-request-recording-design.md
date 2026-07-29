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
| Credentials | Three modes: masked (default), shell env placeholders, real values |
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

Immutable value object for **one request attempt** and its outcome. Exposes
`withCredentials(CredentialMode $mode): self`, returning a copy whose headers, URL query,
and JSON body carry masked values, env placeholders, or the originals.

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

- `__construct(int $limit = 20, CredentialMode $credentials = CredentialMode::Masked)`
- `record(Exchange $exchange): void` — appends, trimming to `limit`
- `all(): list<Exchange>` — oldest to newest
- `last(): ?Exchange`

Credential substitution is applied **at capture time**, so under the default mode the real
password never enters the buffer.

### `Finvalda\Enums\CredentialMode`

String-backed enum controlling how credential values appear in recordings:

| Case | Value | Effect |
|---|---|---|
| `Masked` | `masked` | Values become `***`. Default. |
| `Env` | `env` | Values become shell placeholders (`$FVS_PASSWORD`), so curl output runs after exporting them and the secret is never printed. |
| `Real` | `real` | Values kept verbatim. Runnable curl; never for production logs. |

Lives in `Finvalda\Enums` alongside the SDK's other enums.

### `Finvalda\Support\Redactor`

`HttpClient::REDACTED_KEYS` and `HttpClient::redact()` move here so log redaction and
recording substitution cannot drift. `HttpClient` delegates; existing log behaviour is
unchanged (top-level keys only: `Password`, `ConnString`, `sPassword`), always masked —
env placeholders apply to recordings only, never to PSR-3 logs.

`Redactor` exposes `apply()` (mask) and `applyPlaceholders()` (env placeholders), with a
per-key placeholder map: `Password` → `$FVS_PASSWORD`, `ConnString` → `$FVS_CONN_STRING`,
`sPassword` → `$FVS_SPASSWORD`. `sPassword` gets its own placeholder because it is a
different secret — the looked-up user's password in `References::user()` (`GetFvsUser`),
the SDK's only `sPassword` sender — which travels as a GET query parameter, so URL queries
are substituted too.

Because a substituted query value must stay readable (`***`) and shell-expandable
(`$FVS_SPASSWORD`), the query is rebuilt pair by pair rather than through a bare
`http_build_query()`: substituted values are emitted literally and every other parameter is
re-encoded with `PHP_QUERY_RFC3986`, matching `HttpClient::recordedUrl()` and Guzzle. A
non-empty userinfo password (`https://user:pass@host/...`, which Guzzle honours as Basic
auth) is substituted in place as well.

Fields that are not key/value structured — the `error` message, the response body, and
response header values — are scrubbed **by value**: the real credential values are collected
from the still-unsubstituted exchange (headers, URL query, JSON body) and replaced with the
mode's text. Guzzle embeds the request URI in `RequestException`/`ConnectException` messages,
so without this the real `sPassword` would survive in `Exchange::$error`; the same pass also
covers a server echoing a credential back. Each value is registered together with the encoded
forms it can arrive in — `rawurlencode()`, `urlencode()` (they differ for spaces), and the
JSON-escaped form with and without PHP's default slash/unicode escaping — because Guzzle
embeds the *encoded* query string, so scrubbing only the decoded value leaves any password
with a character outside `A-Za-z0-9._~-` recoverable with a single `urldecode()`. Longest
values are replaced first so a value that is a prefix of another cannot leave a fragment
behind, and empty values are skipped.

Value scrubbing is literal and context-blind: it cannot cover a credential the SDK never saw,
and a very short credential value masks unrelated text (a one-character value under `Env` mode
can even corrupt the placeholders already inserted). Accepted — the alternative is failing to
redact a real secret.

## Public API

On `Finvalda`, mirrored on `HttpClient`:

```php
use Finvalda\Enums\CredentialMode;

$finvalda->record();                                              // limit 20, masked
$finvalda->record(limit: 5);
$finvalda->record(credentials: CredentialMode::Env);              // $FVS_PASSWORD placeholders
$finvalda->record(credentials: CredentialMode::Real);            // real values
$finvalda->recordings();                                          // Exchange[] oldest → newest
$finvalda->lastRecording();                                       // ?Exchange
$finvalda->stopRecording();                                       // stop and drop the buffer
```

On `Finvalda`, `record()` and `stopRecording()` return `$this` for chaining, matching
`setLogger()` and `setDebug()`. The `HttpClient` equivalents return `void`, matching
`setLogger()` / `setDebug()` there.

## Config

`FinvaldaConfig` gains:

- `bool $record = false`
- `int $recordLimit = 20`
- `CredentialMode $recordCredentials = CredentialMode::Masked`

`fromArray()` maps `record`, `record_limit`, and `record_credentials` (via
`CredentialMode::tryFrom()`, falling back to `Masked` for any unrecognised value).

`config/finvalda.php`:

```php
'record' => (bool) env('FINVALDA_RECORD', false),
'record_limit' => (int) env('FINVALDA_RECORD_LIMIT', 20),
'record_credentials' => env('FINVALDA_RECORD_CREDENTIALS', 'masked'), // masked|env|real
```

When `record` is true, `HttpClient` builds the `Recorder` in its constructor, so a Laravel
app enables recording without touching code. `record_credentials=real` is documented as
never-in-production; `env` is the safe choice when you want runnable curl.

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
- Under the default masked mode the curl needs the real password substituted before it runs.
- "Byte-exact" means byte-exact except for substituted credentials: substitution rewrites
  the URL query and re-encodes a JSON body only when it actually contains a credential key
  (`sPassword`). Bodies without one are stored untouched.

Under `CredentialMode::Env` the quoting is placeholder-aware, so the command is runnable
after exporting the variables and the secret never appears:

```
curl -X POST 'https://host/FvsServicePure.svc/InsertNewOperation' \
  -H 'UserName: demo' \
  -H 'Password: '"$FVS_PASSWORD" \
  -H 'Accept: application/json'
```

Quoting is driven by the placeholder text, not by a mode flag on the `Exchange`: values are
split on `$FVS_[A-Z_]+` tokens, literal chunks are single-quoted and placeholder tokens are
double-quoted, then concatenated. This also keeps a JSON body correct
(`-d '{"sPassword":"'"$FVS_SPASSWORD"'"}'`).

## Capture semantics

- Recording happens inside the per-attempt closure in `sendRequest()`, so a retried call
  produces one `Exchange` per attempt, each with its own duration and `attempt` number.
- **Failures are recorded.** The `$this->client->request()` call is wrapped: on a
  `RequestException` carrying a response, the status, headers, and error body are captured;
  on a connection error, `error` holds the message. The exception then rethrows unchanged,
  so error handling is unaffected.
- Duration is measured around the Guzzle call.
- Request and response bodies are capped at the same 100 KB budget the PSR-3 path uses,
  through the shared `Finvalda\Support\BodyTruncator`. Bounding only the exchange count is
  not enough: the Laravel binding is a singleton, so a queue worker with `FINVALDA_RECORD=true`
  would retain `record_limit` whole bodies for its lifetime, and `Reports` endpoints answer
  with PDFs. Headers and the URL are never truncated. A truncated body makes `toCurl()`
  non-reproducible for that exchange.
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
- Reading the placeholder values from the environment inside the SDK — placeholders are
  emitted for the shell to resolve, nothing is looked up in `getenv()`.
- Configurable placeholder names.
- Replacing or deprecating `setDebug()` / `getLastDebugInfo()`.
