# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.7.0] - 2026-07-31

### Added — logging controls

- **`Support\FilePayloadElider`** replaces a whole-file payload in a raw body with a
  size marker. It matches `data`, `fileContents`, `FileContents`, `file_contents` and
  `sFileContent`, and only ever **string** values above 512 bytes — so a structured
  `data` array is untouched, which is what makes keying on a name that generic safe.
- **`logFileContents`** (`log_file_contents` / `FINVALDA_LOG_FILE_CONTENTS`, default
  `false`) restores verbatim payload logging. **`logBodyBytes`**
  (`log_body_bytes` / `FINVALDA_LOG_BODY_BYTES`, default 100000) makes the log byte
  budget configurable; it replaces a private constant.

### Changed

- **File payloads are elided from PSR-3 logs by default.** MakeInvoice/MakeReport/
  GetAutoReport answer with the document base64'd into the response — ~58 KB each, and
  29% of one production log — while `documents()->uploadFile()` sends one the other way
  as hex, at twice the file's size. Both sat under the byte budget and were logged in
  full. Set `log_file_contents` to `true` to restore the old output.
  **Recording (`$finvalda->record()`) is unaffected** and still captures bodies
  verbatim: it is bounded, in memory, and explicitly opted into, which is exactly when
  you want the bytes. `BodyTruncator`'s docblock records that asymmetry as deliberate.

## [3.6.0] - 2026-07-30

### Added — company-scoped clients

Report templates are registered per company, so a client built with
`companyId: 'htrailer'` cannot render a template that exists only on the default
company, even for a document `htrailer` created. Omitting the `CompanyID` header
resolves against the default company and works — but reaching that state meant
building a second client from a second config.

- **`$finvalda->withCompany('HTNT')`** and **`$finvalda->withoutCompany()`** return a
  client bound to another company, or (without) to Finvalda's default company. The
  client shares this one's transport, logger, debug capture and recorder, so a
  company-scoped call still shows up in `getLastDebugInfo()` and `recordings()`, and a
  custom injected `HttpClient` keeps being used. Repeated calls for the same company
  return the same client, memoized for the lifetime of the parent.
- **`FinvaldaConfig::withCompanyId()`** is the same operation at the config level.
- **`HttpClient::getConfig()`** exposes the configuration a transport was built from.

### Added — `Logging\JsonLinesLogger`

The SDK logs at debug level around every request and redacts credentials first, but
shipped nowhere to put those records, so every consumer wrote a file sink.

- **`new JsonLinesLogger($path)`** is a PSR-3 logger appending one JSON object per line
  with `ts`, `pid`, `level` and `message`, plus context keys (including `company`) merged
  in flat — greppable with `jq`. A context key colliding with one of those four is
  written prefixed, e.g. `context_message`, rather than dropped. Missing directories are
  created; strings in the context are capped at `maxBodyBytes` (default 200 KB, above the
  SDK's own 100 KB body cap so records the SDK already truncated are not marked twice).
- Configure it via `log_path` (`FINVALDA_LOG_PATH` in Laravel) instead of constructing
  it by hand; `log_channel`/`FINVALDA_LOG_CHANNEL` takes precedence when both are set.
- Entries hold whole request and response bodies, so a log file this class creates is
  chmod'ed `0640`; an existing file keeps whatever permissions it already has.
- No rotation, no buffering, no level filter — rotate with logrotate, filter with `jq`.
  A failing sink cannot break an API call: the first failure on each logger instance is
  reported through the PHP error log and the rest are silent. Redaction stays in
  `HttpClient`; the sink does not mask a second time.

### Fixed

- **Auth headers are sent with every request** instead of living only in the Guzzle
  client's constructor defaults. A caller-supplied `ClientInterface` previously sent no
  `UserName`, `Password` or `CompanyID` at all, and `getLastDebugInfo()` and recordings
  displayed headers that had never gone out. Header presence is now assertable in tests.
- **`Finvalda\Debug\Diagnostics`** is the single holder for a client's observability
  state — PSR-3 logger, debug flag, the `LastExchange` debug snapshot and the
  `Recorder` — replacing four separate `HttpClient` fields that a company-scoped copy
  could otherwise forget to carry over one at a time. `withCompanyId()` shares this one
  instance by reference, so a company-scoped client and its parent read and write the
  same logger, debug snapshot and recording history.

## [3.5.0] - 2026-07-29

### Added — request/response recording, readable or as a curl command

Debug mode kept only the last exchange, as plain arrays, and captured nothing at
all when a request failed — precisely when you want it. Recording is a third
surface alongside PSR-3 logging and debug mode: a bounded in-memory history of
exchanges as value objects that render themselves.

- **`$finvalda->record()`** starts a ring buffer (default 20 exchanges), read back
  with `recordings()` (oldest first) and `lastRecording()`; `stopRecording()` stops
  and drops it. Also enabled per environment without touching code via
  `FinvaldaConfig` (`record`, `recordLimit`, `recordCredentials`) and the Laravel
  keys `record` / `record_limit` / `record_credentials`
  (`FINVALDA_RECORD`, `FINVALDA_RECORD_LIMIT`, `FINVALDA_RECORD_CREDENTIALS`).
- **`Recording\Exchange`** carries one request *attempt* and its outcome — `method`,
  `url`, `headers`, `body`, `statusCode`, `reasonPhrase`, `responseHeaders`,
  `responseBody`, `durationMs`, `error`, `attempt` — and renders itself three ways:
  `__toString()`/`toString()` as readable HTTP text with pretty JSON and the
  operation payload carried in `xmlstring` decoded and nested rather than shown as an
  escaped one-liner; `toCurl()` as a reproducible command; `toArray()` for structured
  consumption.
- **Failures and retries are recorded.** Capture sits inside the per-attempt closure
  in `sendRequest()`, so a retried call yields one `Exchange` per attempt with its own
  duration and 1-based `attempt`. A 4xx/5xx exchange carries the status, headers and
  error body; a transport failure carries `error` with no status. The original
  exception is rethrown unchanged.
- **`Enums\CredentialMode`** controls how `Password`, `ConnString` and `sPassword`
  appear: `Masked` (default, `***`), `Env` (shell placeholders `$FVS_PASSWORD`,
  `$FVS_CONN_STRING`, `$FVS_SPASSWORD` — the curl runs after exporting them and the
  secret is never printed), `Real` (verbatim; never in production). Substitution
  happens as the exchange is recorded, so the buffer does not hold real credentials
  under the first two modes. `toCurl()`'s quoting is placeholder-aware — literal
  chunks single-quoted, placeholders double-quoted — so a placeholder expands even
  inside a JSON body: `-d '{"sPassword":"'"$FVS_SPASSWORD"'"}'`.
- `HttpClient::REDACTED_KEYS` and its private `redact()` moved to
  **`Support\Redactor`** so PSR-3 log redaction and recording substitution cannot
  drift. PSR-3 logging always masks, whatever the recording mode.

**What the recording is, precisely.** The recorded URL reproduces Guzzle's own
resolution and RFC 3986 query encoding, and the body is encoded exactly as Guzzle
encodes the `json` option — but it is the SDK's view of the request, not literal wire
bytes: anything an externally injected Guzzle client adds (default headers,
middleware) does not appear. `Content-Type: application/json` in curl output is
inferred, since Guzzle sets it and `buildHeaders()` does not.

**Two honest limits.** Credential *keys* are substituted exactly in headers, URL
query, JSON body and userinfo; the `error` string, response headers and response body
are unstructured, so known credential *values* (plus their percent-encoded and
JSON-escaped forms) are scrubbed from them by value. Guzzle embeds the request URI in
its exception messages, so without that pass a real `sPassword` would survive in
`Exchange::$error` even under `Masked`. Value scrubbing is literal: a credential value
that legitimately appears as unrelated data is replaced too, and a one- or
two-character credential will mangle surrounding text. Treat a recording as a redacted
debugging aid, not a sanitised artefact safe to publish unread. Bodies are capped at
100 KB per exchange (a Laravel singleton with `FINVALDA_RECORD=true` in a long-lived
worker would otherwise retain full bodies for the worker's lifetime); a truncated body
is marked and makes that exchange's curl non-reproducible.

`setDebug()` / `getLastDebugInfo()` and PSR-3 logging are unchanged.

## [3.4.0] - 2026-07-28

### Added — additional purchase costs, purchase corrections, stock-op lookup

Four gaps that forced callers to hand-roll plain Finvalda features. The server
shipped the first three together (changelog #58, 2019-04-30) and they form one
workflow: allocate costs on insert, or re-allocate by correcting — with a lookup
that tells you whether correcting is safe.

- **`PurchaseBuilder::additionalCostCodes()` / `PurchaseOrderBuilder::additionalCostCodes()`**
  set the four additional-cost bucket codes (`sPapIslaiduKodas1..4`), previously
  reachable only via `setHeader()` with raw Lithuanian field names. Accepts an
  ordered list (`['KITOS', 'TRANSP']`) or a slot-keyed map (`[2 => 'TRANSP']`).
  Validates arity (max 4), slot range (1-4) and code length (10). Rejected on
  `->short()` at `build()` time — `TrumpasPirkDok`/`TrumpasPirkUzsDok` have no such
  fields. Not added to `purchaseReturn()`: the spec marks the fields *Tik PirkDok ir
  PirkUzsDok*. Shared via the new `Builders\Concerns\HasAdditionalCostCodes` trait.
- **`ProductLine::additionalCost($slot, $currency, $local)`** and
  **`additionalCosts([$slot => $amount])`** allocate a bucket amount to a line,
  writing `dPapIsldSumaV{slot}` and `dPapIsldSumaL{slot}` as a pair — `set()` made it
  easy to populate half a pair and book the line wrong. Not added to `ServiceLine`:
  the spec marks all eight fields *Tik PirkDokPrekeDetEil*.
- **`PurchaseUpdateBuilder`** (`$finvalda->purchaseUpdate()`) builds the
  `KoregPirkDok` envelope for `UpdateOperation`. The correction envelope is not the
  insert envelope — `sZurnalas`/`nNumeris` wrapper, a `PirkDokHeadEil` sub-node, and
  `DelPrekeDetEil`/`DelPaslaugaDetEil` delete nodes with no insert-path equivalent —
  so hand-assembled payloads drifted from the spec silently. `header()` rejects what
  the node does not accept for a purchase: `sKlientas` (a purchase cannot change
  supplier), any operation-date field (the node defines none), `sObjektas5`/`6`, and
  the waybill fields. Reuses `ProductLine`/`ServiceLine` verbatim, enforcing the
  fields the spec marks mandatory on a corrected line.

  **⚠️ A correction is destructive, not an edit.** It deletes the named detail lines
  and re-adds the ones you supply, which rebuilds the product's FIFO stock layer. The
  internal delete fails with error **`4027`** (*Operacijos detalios eilutės yra
  panaudotos kitose operacijose!*) once the goods have been consumed by another
  operation. `4027` is documented under operation *deletion* errors, not the
  `5000`–`5005` correction family, so an update call can return a deletion-class
  code. It is not idempotent, and it fails late — unsold stock corrects fine, and the
  same code path starts failing the day someone sells the goods. `UpdPrekeDetEil` is
  not an escape hatch: it carries only `nPozymis` and `sPapInfo`, so it cannot
  restate amounts. Guard with `assertNotSold()`, which checks every touched product
  code and **fails closed** on an unresolvable history; it is deliberately not run by
  `save()`.
- **`Stock::purchaseOpFor($productCode)`** answers "which purchase operation
  currently holds this product as stock, and has it been sold since" from
  `GetPrekesIstorija`, instead of leaving every caller to re-derive it from
  `Products::history()` rows. The latest purchase wins (a re-acquired item has several
  purchase rows); a sale counts as sold only when dated at or after that purchase.
  Returns a plain array rather than a `Response` and never throws — it is a pre-flight
  check, and `null` means no purchase history or a failed call. Operation kinds are
  matched on the literal `op_rusis_pav` values `Pirkimai`/`Pardavimai`, which are
  observed against a live Finvalda and **not specified** anywhere in the API document;
  an unrecognised kind is ignored rather than guessed at.

Only purchases are covered on the correction side. The other six
`UpdateOperationClass` cases share the envelope shape but each carries its own
`Tik ...` annotations; use `operations()->update()` with a hand-built payload.

## [3.3.1] - 2026-07-09

### Fixed — float normalization defeated by a high host `serialize_precision`
- **Outbound floats now serialize as their shortest round-trippable form
  regardless of the host's `serialize_precision` php.ini.** v3.3.0 rounded
  outbound floats but never controlled how they were encoded. On a server with
  `serialize_precision` set to a positive value, `json_encode` expands every
  non-terminating binary fraction into its full decimal — e.g. a clean `21.49`
  became `21.489999999999998436805981327779591083526611328125` in the request
  body (and rounding was a no-op, since `round(21.49, 10) === 21.49`).
  `HttpClient` now forces `serialize_precision = -1` around both SDK-owned
  encode boundaries — `encodeJson()` (the operation `xmlstring`) and the request
  send (JSON body plus the debug/PSR-3 log encode) — so `21.49` stays `21.49`
  and `0.1 + 0.2` stays `0.3`. Rounding is retained: it collapses arithmetic
  noise into the intended double, which `serialize_precision = -1` then renders
  cleanly.

## [3.3.0] - 2026-07-09

### Added — outbound float artifact normalization
- **Outbound request payloads now have PHP binary-float artifacts stripped
  before JSON encoding.** Float arithmetic such as `0.1 + 0.2` serializes as
  `0.30000000000000004` and reached the Finvalda API verbatim. The SDK now
  rounds every outbound float to a configurable precision (default 10) — high
  enough to preserve any genuine accounting value while discarding ~1e-16
  representation noise. Normalization is applied at the two SDK-owned encode
  boundaries: the JSON request body in `HttpClient::sendRequest()` and the
  serialized `xmlstring` produced by `Resource::jsonEncode()`. Query parameters
  are unaffected — PHP's precision-based string cast already renders
  `0.1 + 0.2` as `0.3`; only `json_encode` exposes the artifact.
- Configurable via `FinvaldaConfig` (`normalizeFloats`, `floatPrecision`) and
  the Laravel config keys `normalize_floats` / `float_precision`
  (env `FINVALDA_NORMALIZE_FLOATS` / `FINVALDA_FLOAT_PRECISION`). Enabled by
  default; pass `normalizeFloats: false` to send raw float values.

## [3.2.0] - 2026-07-05

### Fixed — full-variant return builders emitted non-spec detail-row elements
- **`salesReturn()` and `purchaseReturn()` full (non-`short()`) variants now emit
  the spec's shared detail-element keys.** The FVS spec defines ONE set of detail
  elements per operation family — `PardDokPrekeDetEil` / `PardDokPaslaugaDetEil`
  for all sales classes (PardDok, PardRezDok, PardGrazDok, UVMPardRezDok) and
  `PirkDokPrekeDetEil` / `PirkDokPaslaugaDetEil` for all purchase classes
  (PirkDok, PirkUzsDok, PirkGrazDok, UVMPirkUzsDok). The builders previously
  emitted `PardGrazDok*DetEil` / `PirkGrazDok*DetEil` for the full variants —
  elements that do not exist in the spec. The server silently ignores unknown
  elements and then rejects the operation with **1037 "Operation do not has
  detail rows!"** (verified against a live server). The `short()` variants
  already used the correct keys and are unchanged.

### Known issues (documented in README + docblocks)
- In live testing the full `PardGrazDok` variant still failed with **2012 "Xml
  string is incomplete"** even with the corrected detail keys and all
  spec-mandatory header fields (`sKlientas`, `sDokumentas`, `sValiuta`, `tData`),
  while the identical payload via `->short()` succeeded. Until the missing
  ingredient is identified, `->short()` is the proven shape for returns.
- `originalDocument()` (`sGrazDokumentas`/`sGrazZurnalas`/`nGrazNumeris`) and
  `reason()` (`sGrazPriezastis`) fields appear in neither the official spec nor
  the Postman collection; Finvalda silently ignores unknown fields, so verify
  the linkage lands on your server before relying on it.

### Added
- `BuilderRequestBodyTest` — regression tests pinning the outgoing
  `InsertNewOperation` request body (exact `ItemClassName` and detail-element
  keys inside the serialized `xmlstring`) for the short and full variants of all
  six sales/purchase family builders.

## [3.1.1] - 2026-06-25

### Fixed — clear error when DeleteItem is unsupported by the server build
- **`deleteProductType` / `deleteProductTag` / `deleteClientType` / `deleteClientTag`
  now fail clearly when the server lacks `DeleteItem`.** Live validation found that
  the `DeleteItem` endpoint exists only on newer FvsServicePure builds — older builds
  answer **404** (a WCF "endpoint not found" page). Previously this surfaced as an
  opaque transport error (`HTTP request failed: … 404 Not Found`). The shared
  `References::deleteItem` helper now catches the 404 and throws a typed
  **`Finvalda\Exceptions\OperationNotSupportedException`** (extends `FinvaldaException`)
  whose message names the endpoint and whose `->endpoint` property is `'DeleteItem'`.
  The request shape is unchanged and correct — this is a server-build capability gap,
  not a wire-format bug.
- `create` (`InsertNewItem`) and `update` (`EditItem`) are unaffected and broadly
  available across builds.

### Changed
- `HttpClient` now preserves the HTTP status code on 4xx errors (the wrapped
  `FinvaldaException` carries the status as its code instead of `0`), enabling
  callers to distinguish a 404 from other failures.

## [3.1.0] - 2026-06-25

### Added — type/tag dictionary write coverage
Completes the create/update/delete lifecycle for product and client types and tags
on `References` (read/query lives on Products/Clients via `typesAndTags()` /
`allTypesAndTags()`):
- **`createClientTag(int $n, array)`** — `n = 1..3` → `Fvs.Kliento{I|II|III}Poz`
  (mirrors the existing `createProductTag()`; the item classes already existed but
  had no method).
- **`updateProductType()` / `updateProductTag(int $n, …)` / `updateClientType()` /
  `updateClientTag(int $n, …)`** — `EditItem`, record identified by `sKodas`.
- **`deleteProductType()` / `deleteProductTag(int $n, …)` / `deleteClientType()` /
  `deleteClientTag(int $n, …)`** — `DeleteItem` by code.
- **`ItemClass::clientTag(int)`** helper (1..3 → Roman-suffixed client tag classes).

### Notes
- **Services are read-only for types/tags.** The Finvalda write API exposes no
  `Fvs.PaslaugosRusis` (or service-požymis) class, so there are no service
  create/update/delete counterparts — only the read-side `typesAndTags()`.
- The new `EditItem`/`DeleteItem` calls reuse the same transport already used for
  Preke/Klientas writes; behaviour against the dictionary item classes should be
  confirmed against the live server.

## [3.0.0] - 2026-06-25

### Fixed (behavior change — types and tags)
- **`typesAndTags()` now actually filters.** On Products/Clients/Services the
  method previously sent the type/tag id as an `nID` GET parameter — but the
  server **ignores `nID`** and always returns the full dictionary (verified against
  the live API: two different `nID` values return byte-identical results). The real
  discriminator is a `tipas` column on each row, whose values are exactly the
  existing `ProductTypeId`/`ClientTypeId`/`ServiceTypeId` enum values. The SDK now
  fetches the dictionary once (without `nID`) and filters rows by `tipas`, so the
  method returns only the requested type/tag group — the behavior its signature
  always implied.

### Added
- **`allTypesAndTags()`** on Products/Clients/Services — returns the whole type+tag
  dictionary in a **single** HTTP call. Use `->groupByType()` for an
  `array<int, TypeTagCollection>` keyed by `tipas`. The request is cached per
  resource instance, so this and `typesAndTags()` never fan out into multiple calls.
- **`Finvalda\Data\TypeTag`** DTO (`tipas`, `code`, `name`, `info1`, `info2`) and
  **`Finvalda\Collections\TypeTagCollection`** (`whereType()`, `groupByType()`,
  `types()`, `findByCode()`), matching the existing DTO/Collection patterns.

### Changed (BREAKING)
- **`Products|Clients|Services::typesAndTags()` now returns `TypeTagCollection`
  instead of `Response`.** Iterate `TypeTag` objects (`->code`, `->name`, `->tipas`,
  `->info1`, `->info2`) instead of reading `$response->data` rows. The `ProductTypeId|int`
  (resp. Client/Service) parameter is unchanged and still accepts a raw int.

### Notes
- **Raw `tipas` values are tolerated.** Servers may expose `tipas` values with no
  enum case — e.g. products' `tipas = 100` ("Apmokestinamieji gaminiai"). Pass them
  as a raw int; they are returned normally, not treated as errors.
- **Empty tag groups are normal.** A tag group the server has not configured returns
  an empty `TypeTagCollection` (server-config dependent), not an error.

## [2.12.0] - 2026-06-23

### Fixed (behavior change — product quantities)
- **`ProductLine` now sends `nPirmasMat=1` by default.** Empirical testing against the live Finvalda API disproved the previous understanding (documented in 2.11.1) that an omitted `nPirmasMat` made product `nKiekis` a verbatim no-op. In reality, with `nPirmasMat` absent Finvalda reads the quantity in the unit's **second** dimension and rescales by the unit's first/second ratio (`pirm_antr_sant`): `250` on an "M" product (ratio 100) was stored as 250 cm = **2.5 m**. `KG` (ratio 1000) was off by 1000×; `VNT` (ratio 1) was unaffected, which masked the bug for piece quantities. `ProductLine::make()` now defaults to the primary unit (`nPirmasMat=1`), matching every official API example, so quantities are sent verbatim in the unit you expect.
- Added **`ProductLine::secondMeasurement()`** to opt back into the legacy behavior (drops `nPirmasMat` so Finvalda rescales by the unit ratio). `firstMeasurement(false)` is now equivalent to `secondMeasurement()`.
- **`OperationBuilder::addProduct()` now also defaults `nPirmasMat=1`**, matching `ProductLine::make()`. Opt out with `additionalData: ['nPirmasMat' => 0]`. The fully-raw `addProductLine()` is unchanged (no defaults).
- **Compatibility:** code already calling `->firstMeasurement()` is unaffected (it was already sending `nPirmasMat=1`). `addProductLine()` keeps its raw-passthrough behavior (no `nPirmasMat`) for full control.

### Documentation
- Rewrote the `nKiekis` quantity-convention section: corrected the product second-measurement description (the ratio rescale is **not** a no-op), documented the new `ProductLine` default and `secondMeasurement()` opt-out, and fixed the misleading doc-comments on `ProductLine` and `OperationBuilder::addProduct()`.

## [2.11.3] - 2026-06-15

### Documentation
- Corrected the `sParametras` documentation: `FvsNETParamKonfig` has no `.xlsx` export. The `parameters.local.yaml` catalog is maintained by hand from the tool's profile settings (a hand-made Excel only seeds it). Fixed the wording in the parameters guide (`docs/parameters/README.md` §6), the design spec, and the implementation plan.

## [2.11.2] - 2026-06-15

### Documentation
- **Added a `sParametras` knowledge base under `docs/parameters/`.** A generic guide explains what a parameter profile contains (journal, operation type, series, document type, GL accounts, VAT codes, division, employee, currency, Intrastat data, behavioral flags), how profiles are managed in the `FvsNETParamKonfig` desktop tool, the seven configuration tabs with an English-key → Lithuanian-label mapping, and how an AI assistant can use the catalog to match a transaction to a profile, explain a profile, or draft setup instructions for a new one.
- **`parameters.example.yaml`**: an exhaustive, fake-value reference profile listing every available field across all seven tabs, plus a minimal realistic example. Inflow/disbursement operation types are modeled as a per-currency list (one operation type per currency). Deployment-specific values belong in a git-ignored `parameters.local.yaml`, never committed.
- Linked the README `Server-Configured Parameters` section to the new knowledge base, and genericized a `sFvsImportoParametras` example value.

## [2.11.1] - 2026-06-12

### Documentation
- **Explained the `nKiekis` quantity convention in full.** Per the API spec, the encoding differs by line type and `nPirmasMat`: the **first** measurement is always the quantity as-is (double); for the **second** measurement, **service** rows are multiplied by 100 (fixed-point, `1 → 100`, `0.5 → 50`) while **product** rows are sent as a plain integer with **no ×100**. This matches the existing SDK behavior — `ServiceLine` auto-scales, `ProductLine` is verbatim — which is faithful to the spec, not an inconsistency. Added a README table, the spec quotes, product/service examples, a note that the SDK never performs unit conversion (e.g. kg↔ton ratios are the caller's responsibility), and matching code comments on `ProductLine` and `OperationBuilder::addProduct()`.

## [2.11.0] - 2026-06-12

### Added
- **`DocumentType` enum** for the `sDokRusis` header field, with all ten documented codes (`S`, `SF`, `D`, `DS`, `K`, `KS`, `KT`, `VS`, `VD`, `VK`) and Lithuanian labels. `documentType()` on the sale/purchase builders now accepts a `DocumentType` case or a raw 2-character string (fully backward-compatible).

### Documentation
- Documented `series()` / `documentType()` on the purchase example and added a `sDokRusis` code glossary.
- Clarified that document type (`sDokRusis`), series (`sSerija`), and the `save()` parameter (`sParametras`) are three independent inputs, and that `sParametras` selects the journal server-side and cannot be bypassed on create.

## [2.10.0] - 2026-06-12

### Fixed (breaking wire-format corrections)
- **`InternalTransferBuilder` warehouse fields were wrong.** `fromWarehouse()`/`toWarehouse()` emitted `sSandIs`/`sSandI`; the `VidPerkDok` spec requires the header fields **`sIsSandelio`** (source) and **`sISandeli`** (destination), both mandatory. Internal transfers built with this builder were rejected by the server for missing warehouses. The detail rows (`VidPerkDokDetEil`) have no per-line warehouse fields, so `addTransfer()`'s optional `fromWarehouse`/`toWarehouse` arguments now set the header values (last call wins) instead of emitting unrecognized per-line keys.

### Fixed
- **`Data\Client` country code/name collision.** When the API returned only `sValstybe` (country *name*) with no `sValstybeKodas`, that name was copied into both `country` and `countryName`, and `toArray()` then wrote it back into `sValstybeKodas`. `country` no longer falls back to the name key.
- **`ServiceLine::firstMeasurement()` is now respected.** The ×100 second-measurement scaling was applied unconditionally in the constructor, so `firstMeasurement()` still emitted `nKiekis` ×100 instead of the raw quantity (the docs require no scaling for the first measurement). Scaling is now computed at `toArray()` time and skipped when `firstMeasurement()` is set; an explicit `set('nKiekis', …)` always wins. Default behavior (qty 1 → `nKiekis` 100) is unchanged, and fractional quantities now round rather than truncate.

### Documentation
- Documented the service quantity ×100 convention and the difference between `ServiceLine`, `ProductLine`, and the legacy `addService()` helper.
- Documented previously-undocumented methods: the full `Pricing` client/type matrix, `Descriptions` grouping helpers (`clientGroups`, `warehouseGroups`, `logbookGroups`, `opTypeGroups`, `documentSeries`, `calendarEvents`, `vehicles`, `invoiceList`, `reportList`, `typesAndTags`), `References::materiallyResponsiblePersons`/`updateWarehouse`/`updatePaymentTerm`/`addToGroup`, `Transactions::ommPurchasesDetail`/`ommSalesXmlCondition`/`advancedPaymentsDetailExtended`/`depreciationOfFixedAssetsObjects`, `Operations::activityByObjects`, and `Clients::settlementsFromDateParam`.
- Added a note that the legacy `docs/FVS_Webservice.txt` method signatures describe the V0 SOAP interface and don't always match the V2 endpoint (e.g. `GetPrekesSandelyje` uses `sSandKod`, not the documented `sSanKod` — verified against a live server). `Products::inWarehouse()`/`inWarehouseOrdered()` were confirmed correct as-is.

## [2.9.0] - 2026-06-04

### Security
- **Credentials are now redacted from debug capture and PSR-3 logs.** `getLastDebugInfo()['request']['headers']` previously contained the plaintext `Password` (and `ConnString`, which may embed database credentials); both are now replaced with `***`. The `sPassword` query parameter of `GetFvsUser` (`References::user()`) is likewise redacted from the `params` context of the `Finvalda API request` log record. The wire requests are unaffected. Note: `GetFvsUser` inherently places the password in the request URL — documented on `References::user()`.

### Added
- **PSR-3 logging now includes bodies.** The `Finvalda API request` debug record gains a `body` key carrying the full request body as a string (`null` for GET requests; the existing `params` and `has_body` keys are unchanged). The `Finvalda API response` debug record gains a `body` key carrying the full response body. Bodies larger than 100 KB are truncated and suffixed with `... [truncated N bytes]`. Consumers parsing the SDK's log records should expect the new `body` key in both records; no existing keys changed.
- **Laravel: logger and retry are now configurable.** New `config/finvalda.php` keys: `log_channel` (`FINVALDA_LOG_CHANNEL`) routes SDK debug records to a Laravel log channel; `retry.*` (`FINVALDA_RETRY_*`) builds a `RetryPolicy` with exponential backoff. Previously the service provider never wired either, so Laravel apps had no config path to retries or PSR-3 logging.
- **`FinvaldaConfig::fromArray()`**: builds a config (including the retry policy) from the snake_case `config/finvalda.php` array shape; the service provider now delegates to it.

### Fixed
- **`Products::find()` / `Clients::find()` / `Services::find()`** now handle the Pure service's single-entity envelope (`{"Fvs.Preke": {...}}` found / `{"Fvs.Preke": null}` not found). Previously both shapes produced an empty DTO stub (`code = ''`, all fields null): the null-valued wrapper key defeated the `empty()` not-found check, and found entities were never unwrapped before `fromArray()`. `find()` now throws `NotFoundException` when the entity is missing and returns a populated DTO when it exists.
- **`ping()` always returned `false` on servers where `GetFvsUser` responds with XML.** Some server versions ignore the JSON `Accept` header for this endpoint (observed live); `parseResponse()`/`parseOperationResult()` now fall back to XML parsing when the body is not JSON. As part of this, `Response::$error` is normalized to `null` when the server sends an empty error element/string.
- **`AccessDeniedException` now carries the server's explanation** (e.g. `"This function is not licensed in this machine!"`) for operation calls instead of a bare `"Access denied"`.
- **Collections skip malformed rows.** `ProductCollection`/`ClientCollection`/`ServiceCollection::fromArray()` ignore non-array rows instead of throwing a raw `TypeError`.
- **`Pagination\Cursor` silently dropped items.** Deduplication used `spl_object_hash((object) $item)`; for array items the temporary object is freed after each statement, so PHP reuses the address and *different* items collide on the same hash — iteration typically yielded a single item and stopped. Items are now identified via a new optional `idExtractor` constructor callable (e.g. `fn($item) => $item['sKodas']`), defaulting to a content hash (`md5(serialize($item))`) when omitted.

### Fixed (breaking wire-format corrections)
- **ClearingBuilder, ProductionBuilder, NonAnalyticalBuilder, UvmCancellationBuilder** now nest their detail rows **inside** the operation class wrapper (`{"UzskaitaDok": {...header..., "UzskaitaDebitDetEil": [...]}}`), matching the docs XML examples and the official Postman `InsertNewOperation (UzskaitaDok)` body. These builders were added before the v2.4.0 nesting fix and still emitted the pre-2.4.0 sibling shape that the server rejects with `nResult=1037` "Operation do not has detail rows!". `InventoryCountBuilder` was already correct (flat root + `mode`). Note: the `GamybaDok` (production) nesting is inferred from structural consistency — the docs have no XML example for it; verify against a live server if rejected.

### Changed
- Builders with dedicated line arrays (Clearing, Production, NonAnalytical, UvmCancellation, InventoryCount) now throw `BadMethodCallException` from `build()` if generic `addProduct()`/`addService()` lines were added. Previously such lines were silently discarded.
- **`Entity` array writes now throw.** `$product['field'] = ...` and `unset($product['field'])` previously did nothing silently; they now throw `LogicException` (entities are immutable).

## [2.8.0] - 2026-04-24

### Added
- **Reports**: `makeInvoicePdf()`, `makeReportPdf()`, `autoReportPdf()` return decoded PDF bytes. `makeInvoice()` / `makeReport()` now accept an array and JSON-encode it for the `sParam` query string.
- **Products::imageJpeg()**: Returns decoded JPG bytes (mirrors the Reports pattern).
- **Concerns\DecodesBinaryResponse** trait shared by Reports and Products.
- **DescriptionType::filterKey()**: Authoritative mapping from type to its wire-format filter key. Most types use the same name, but `CurrentStock`/`BarCodes`/`ProductionItem` → `Products`, `Address` → `Clients`, `DocumentSeries` → `Series`, `CountSales`/`CountClients` → `CountFilter`, `PricesBy*` → `Prices`.

### Fixed (breaking wire-format corrections)
- **Operations::get()** now wraps parameters in a single JSON-encoded `opReadParams` query string per API docs (was sending flat query params, which the server ignored).
- **Descriptions**: convenience methods and `get()` now nest filters under the type's filter key (was flattening into `readParams` top level). Matches docs example `{ readParams: { type: "StockOnDate", StockOnDate: { Date: "..." } } }`.
- **Pricing::recommendedPrice()** now wraps params in `{ inParams: { ... } }` per docs.
- **Clients::email($code)** renamed to **`findByEmail($email)`** and sends `sEMail` query param (was sending `sKliKod` with the client code — server endpoint actually expects email).
- **Operations::changeJournal()** and **copy()** now throw `JsonException` on invalid JSON string input (was silently coercing to null).
- **RetryHandler**: `RetryExhaustedException` is now thrown when all retryable attempts fail (previously unreachable — the original exception was thrown instead, wrapping was dead code). Non-retryable exceptions still propagate unchanged.

### Changed (breaking)
- `DescriptionType::TagsAndTypes` → **`DescriptionType::TypesAndTags`** with wire value `"TypesAndTags"` (API docs example uses `TypesAndTags`; the section heading saying `TagsAndTypes` appears to be a doc error).
- `Descriptions::tagsAndTypes()` → **`typesAndTags()`** to match.

## [2.4.1] - 2026-04-13

### Fixed
- **UvmSalesReservationBuilder** now emits detail rows under `PardDokPrekeDetEil` / `PardDokPaslaugaDetEil` (matching Finvalda's server-side schema for `UVMPardRezDok`). Previously emitted the UVM-prefixed names `UVMPardRezDokPrekeDetEil` / `UVMPardRezDokPaslaugaDetEil`, which Finvalda rejects with `nResult=1037` "Operation do not has detail rows!". `UvmPurchaseOrderBuilder` was already using the non-prefixed `PirkDok...` keys; `UvmCancellationBuilder` uses `UVMAnulDokDetEil` (cancellations have their own schema — verify against the live API if rejected).

## [2.4.0] - 2026-04-13

### Fixed
- **OperationBuilder::build()** now produces the shape Finvalda actually accepts: the operation is wrapped under its class key, with detail-row arrays nested **inside** the wrapper alongside the header fields. Previous versions produced rejected payloads:
  - 2.3.0 (flat, no wrapper) → `{"error":"Xml string is incomplete, missing important information.","nResult":2012}`
  - ≤ 2.2.0 (wrapper + sibling detail arrays) → `{"error":"Operation do not has detail rows!","nResult":1037}`

  Verified against a working non-SDK `PardDok` call. New shape:

  ```json
  {
    "PardDok": {
      "sKlientas": "...",
      "tData": "...",
      "PardDokPrekeDetEil":    [ ... ],
      "PardDokPaslaugaDetEil": [ ... ]
    }
  }
  ```

### Reverted
- The `@deprecated` notice on `OperationBuilder::getHeaderKey()` (added in 2.3.0) — the method is actively used by `build()` again.

### Known limitations (carried over from 2.3.0)
- Subclass overrides of `build()` (`ClearingBuilder`, `ProductionBuilder`, `NonAnalyticalBuilder`, `UvmCancellationBuilder`, `InflowBuilder`, `DisbursementBuilder`) still emit the wrapper-with-sibling-detail-arrays shape (the same pattern that triggers `nResult:1037`). They likely need the same nest-detail-rows-inside-wrapper fix. `InventoryCountBuilder` is unaffected (different shape).

## [2.3.0] - 2026-04-13

### Fixed
- **OperationBuilder::build()** now returns a flat array — header fields at the root with detail-row arrays as siblings — instead of wrapping the header under `getHeaderKey()`. The Pure endpoint identifies the operation class via the `ItemClassName` query parameter, so the JSON body must not contain an outer class wrapper. Previously, `InsertNewOperation` rejected fluent-built UVM sales reservations (and other parent-`build()` operations) with `{"error":"Operation do not has detail rows!","nResult":1037}` because detail-row arrays sat as top-level siblings to the header wrapper instead of alongside the header fields the server expected.

### Changed (Behavior of `build()` output)
- Consumers that read `$builder->build()` directly will see a different shape: instead of `['PardDok' => [...header], 'PardDokPrekeDetEil' => [...]]`, the output is now `['sKlientas' => ..., 'tData' => ..., 'PardDokPrekeDetEil' => [...]]`. Code that calls `->save()` is unaffected.

### Deprecated
- `OperationBuilder::getHeaderKey()` is no longer used by `build()`. It remains in place to preserve subclass contracts.

### Known limitations
- Subclass overrides of `build()` (`ClearingBuilder`, `ProductionBuilder`, `NonAnalyticalBuilder`, `UvmCancellationBuilder`, `InflowBuilder`, `DisbursementBuilder`) still wrap the header under `getHeaderKey()`. If those operations also fail with `nResult:1037`, they need the same flat-shape fix applied to their own `build()` methods. `InventoryCountBuilder` already emits its own non-wrapper shape (`{mode, Inventorizacija}`) and is unaffected.

## [2.2.0] - 2026-04-09

### Added
- **Finvalda::ping()**: Test connection and credentials with a single call — returns `true` if the server is reachable and credentials are valid
- **Finvalda::getHttpClient()**: Access the underlying `HttpClient` for advanced usage
- **Finvalda::setDebug()** / **getLastDebugInfo()**: Proxy methods for debug mode without reaching into HttpClient

## [2.1.0] - 2026-04-09

### Added
- **ProductLine DTO**: Fluent value object for building product detail lines with full IDE discoverability — `ProductLine::make('CODE', qty)->warehouse()->amount()->vat()->discount()->object()->objects()->intrastat()->weight()->firstMeasurement()->info()->marked()->set()`
- **ServiceLine DTO**: Fluent value object for service detail lines — `ServiceLine::make('CODE', qty)->amount()->vat()->discount()->object()->objects()->vatCode()->info()->marked()->set()`
- **OperationBuilder::product()**: Accepts `ProductLine` DTOs, adds to product lines array
- **OperationBuilder::service()**: Accepts `ServiceLine` DTOs, adds to service lines array
- Sparse object support on line DTOs: `->object(1, 'DEPT01')->object(4, '1234567')` — levels 2,3,5,6 remain unset
- `set()` escape hatch on both DTOs for raw API fields not covered by named methods

### Changed
- **ProductionBuilder::product()** renamed to **finishedProduct()** to avoid conflict with new `OperationBuilder::product(ProductLine)` method

## [2.0.0] - 2026-04-09

### Changed (Breaking)
- **HTTP transport**: All POST write operations now use JSON body instead of form-encoded params, matching the Postman collection's "Json Body" variant
- **GetDescriptions**: Now wraps parameters in `{"readParams":{...}}` envelope and uses lowercase `type` key, matching the documented API format
- **GetOperations (POST)**: Now wraps parameters in `{"opReadParams":{...}}` envelope
- **DeleteItem**: Uses `{"input":{"ItemClassName":"...","Code":"..."}}` JSON body format per Postman spec
- **ChangeJournal**: Now sends flat JSON body (`sJournal`, `nOpNumber`, `sJournalNew`) instead of xmlstring wrapper
- **CopyOperation**: Now sends `{"input":{...}}` JSON body instead of xmlstring wrapper
- **DeleteOperation**: Journal/number now correctly sent inside xmlstring JSON body
- **LockOperation/UnLockOperation**: Switched from form params to JSON body per REST docs
- **IsOperationLocked**: Switched from query params to JSON body per REST docs
- **Products::editProperties()**: Signature changed from `(string $productCode, array $properties)` to `(array $data)` — data must include `Kodas` array and properties to set, wrapped in `Fvs.EditItemProps > Fvs.Prekes`
- **Descriptions::tagsAndTypes()**: Now uses `readParams` wrapper via `get()` method

### Added
- **HttpClient::postOperationJson()**: New method for endpoints that use flat JSON body and return OperationResult (Lock, Unlock, ChangeJournal, CopyOperation, DeleteItem)
- **Debug mode**: `HttpClient::setDebug()` and `getLastDebugInfo()` to capture full request/response cycle for troubleshooting
- **ItemClass::ProductTag1-20**: 20 new enum cases for product tag item classes (Fvs.PrekesPoz1 through Fvs.PrekesPoz20)
- **ItemClass::productTag(int)**: Static helper to get ProductTag case by number
- **References::createProductTag()**: Create product tag values (Fvs.PrekesPoz1-20) via InsertNewItem
- **DescriptionType::OperationStatuses**: New description type for operation status queries
- **DescriptionType::Accounts**: New description type for account queries
- **SaleBuilder**: Added `roundingAmount()`, `exportToIvaz()`, `locked()`, `employee()` methods
- **PurchaseBuilder**: Added `roundingAmount()`, `exportToIvaz()`, `locked()`, `employee()` methods
- **InternalTransferBuilder**: Added `exportToIvaz()`, `marked()`, `employee()` methods
- **InflowBuilder**: Added `locked()`, `employee()` methods
- **DisbursementBuilder**: Added `locked()`, `employee()` methods
- **Operations::unlock()**: Added optional `$newJournal` parameter (maps to `sZurnalasNaujas`)
- Synced Postman collection to latest version (April 2026)

### Fixed
- **GetDescriptions**: Was sending flat params without `readParams` wrapper — API requires the envelope
- **GetOperations POST**: Was sending flat params without `opReadParams` wrapper
- **Descriptions::tagsAndTypes()**: Was bypassing `get()` method, sending wrong format without `readParams` wrapper
- **EditItemProps**: Was sending extra `ItemClassName`/`sKodas` params and missing `Fvs.EditItemProps` wrapper
- **DeleteOperation**: Was sending journal/number as bare params instead of inside xmlstring
- **Clients::invoicesRelatedToCustomer()**: Restored to `post()` with query params matching Postman spec
- Removed `Content-Type: application/json` from default Guzzle headers — Guzzle's `json` option sets it automatically

## [1.1.1] - 2026-04-08

### Fixed
- Fix `parseOperationResult` ignoring `AccessResult::Fail` when `nResult` is 0

## [1.1.0] - 2026-04-03

### Added
- **Sales Reservation Builder**: `salesReservation()` for PardRezDok operations
- **Purchase Order Builder**: `purchaseOrder()` for PirkUzsDok operations
- **Write-Off Builder**: `writeOff()` for NurasymasDok inventory disposal operations
- **Capitalization Builder**: `capitalization()` for PajamavimasDok inventory receiving operations
- **Clearing Builder**: `clearing()` for UzskaitaDok set-off operations with debit/credit lines
- **Production Builder**: `production()` for GamybaDok operations with finished goods, raw materials, and services
- **Non-Analytical Builder**: `nonAnalytical()` for KtNeanalitDok general ledger entries
- **Inventory Count Builder**: `inventoryCount()` for Inventorizacija operations with mode support
- **UVM Sales Reservation Builder**: `uvmSalesReservation()` for UVMPardRezDok workshop/service orders
- **UVM Purchase Order Builder**: `uvmPurchaseOrder()` for UVMPirkUzsDok operations
- **UVM Cancellation Builder**: `uvmCancellation()` for UVMAnulDok operations
- **Short operation support**: `short()` method on sale, purchase, and return builders to use simplified Trumpas* variants
- Added `series()`, `documentType()`, `fulfillmentDate()` methods to sale, purchase, and return builders
- Comprehensive builder test suite (46 tests covering all builders, build output, short toggles)

## [1.0.0] - Unreleased

### Added
- Initial release
- Full coverage of Finvalda V2 (FvsServicePure) REST API
- 15 resource classes: Stock, Clients, Products, Services, Objects, Transactions, Operations, Pricing, OrderManagement, Documents, Reports, Descriptions, References, Permissions
- 130+ API endpoint implementations
- Laravel service provider with auto-discovery, Facade, and publishable config
- TransactionFilter and PaymentFilter DTOs for query construction
- Injectable HttpClient for testing
- PHPStan level 6 analysis
- Comprehensive PHPDoc on all public methods

### Developer Experience Improvements
- **Typed DTOs**: `Client`, `Product`, `Service`, `StockBalance`, `AnalyticalObject` data transfer objects with full IDE autocomplete
- **Collections**: `ClientCollection`, `ProductCollection`, `ServiceCollection` with filtering methods (`findByCode()`, `whereType()`, `withDebt()`)
- **Fluent Operation Builders**: `sale()`, `purchase()`, `internalTransfer()`, `salesReturn()`, `purchaseReturn()`, `inflow()`, `disbursement()` for readable operation creation
- **Query Builders**: `TransactionQuery` and `OperationQuery` for fluent API filtering
- **Retry Logic**: Configurable `RetryPolicy` with exponential backoff for transient failures
- **PSR-3 Logging**: `setLogger()` method for request/response debugging
- **Cursor Pagination**: `Cursor` and `LazyCollection` for memory-efficient iteration over large datasets
- **Validation**: `Validator` with rules (`Required`, `StringLength`, `NumericRange`, `DateFormat`)
- **Exception Hierarchy**: `NetworkException`, `NotFoundException`, `ConflictException`, `ServerException`, `OperationFailedException`, `RetryExhaustedException`
- **Enum Constants**: `ClientTypeId`, `ProductTypeId`, `ServiceTypeId`, `DebtType`, `OffsetStatus` for magic number elimination
