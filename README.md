# Finvalda PHP SDK

PHP SDK for the [Finvalda (FVS)](https://www.finvalda.lt/) accounting software web service API.

Built from the official [Finvalda API documentation](https://documenter.getpostman.com/view/7208231/2s8YmRMLvd).

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
  - [Basic Configuration](#basic-configuration)
  - [Laravel Integration](#laravel-integration)
  - [Logging](#logging)
  - [Recording Requests](#recording-requests)
  - [Retry Policy](#retry-policy)
  - [Custom HTTP Client](#custom-http-client-testing)
- [Typed DTOs & Collections](#typed-dtos--collections)
  - [Finding Entities](#finding-entities)
  - [Working with Collections](#working-with-collections)
- [Fluent Operation Builders](#fluent-operation-builders)
  - [Sales](#creating-a-sale)
  - [Purchases](#creating-a-purchase)
  - [Additional Purchase Costs](#additional-purchase-costs)
  - [Correcting a Purchase](#correcting-a-purchase)
  - [Internal Transfers](#creating-an-internal-transfer)
  - [Returns](#creating-returns)
  - [Payments](#creating-payments)
  - [Write-Offs & Capitalization](#write-offs--capitalization)
  - [Production](#creating-a-production-operation)
  - [Non-Analytical Operations](#non-analytical-operations)
  - [Inventory Count](#inventory-count)
  - [Clearing / Set-Off](#clearing--set-off)
  - [UVM (Order Management)](#uvm-order-management)
  - [Short / Simplified Operations](#short--simplified-operations)
- [Query Builders](#query-builders)
  - [Transaction Query](#transaction-query)
  - [Operation Query](#operation-query)
- [Validation](#validation)
- [Field Reference](#field-reference)
- [Resources](#resources)
  - [Stock / Inventory](#stock--inventory)
  - [Clients](#clients)
  - [Products](#products)
  - [Services](#services)
  - [Objects (6 Levels)](#objects-6-levels)
  - [Transactions](#transactions-financial-detail-data)
  - [Operations](#operations-create-update-delete)
  - [Order Management (UVM)](#order-management-uvm)
  - [Pricing & Discounts](#pricing--discounts)
  - [Documents](#documents)
  - [Reports & Invoices](#reports--invoices)
  - [Descriptions (Universal Query)](#descriptions-universal-query)
  - [Reference Data](#reference-data)
  - [User Permissions](#user-permissions)
- [Pagination](#pagination)
- [Error Handling](#error-handling)
- [Server-Configured Parameters](#server-configured-parameters)
- [API Versions](#api-versions)
- [License](#license)

## Requirements

- PHP >= 8.3
- Guzzle HTTP client

## Installation

```bash
composer require arturas88/finvalda-sdk
```

## Quick Start

```php
use Finvalda\Finvalda;
use Finvalda\FinvaldaConfig;

// Configure the client
$config = new FinvaldaConfig(
    baseUrl: 'https://your-server.com/FvsServicePure.svc',
    username: 'your-username',
    password: 'your-password',
);

$finvalda = new Finvalda($config);

// Test connection
if (! $finvalda->ping()) {
    die('Connection failed — check credentials and server URL');
}

// Fetch all clients as a typed collection
$clients = $finvalda->clients()->collect();

foreach ($clients as $client) {
    echo "{$client->code}: {$client->name}\n";
}

// Create a sale using the fluent builder
$result = $finvalda->sale()
    ->client('CLI001')
    ->date('2024-01-15')
    ->warehouse('MAIN')
    ->addProduct('PRD001', quantity: 10, price: 19.99)
    ->addProduct('PRD002', quantity: 5, amount: 49.95)
    ->save('STANDARD');

if ($result->success) {
    echo "Created: {$result->journal} #{$result->number}";
}
```

## Configuration

### Basic Configuration

```php
use Finvalda\Finvalda;
use Finvalda\FinvaldaConfig;
use Finvalda\Enums\Language;
use Finvalda\Enums\CredentialMode;
use Finvalda\Retry\RetryPolicy;

$config = new FinvaldaConfig(
    baseUrl: 'https://your-server.com/FvsServicePure.svc',
    username: 'your-username',
    password: 'your-password',
    // Optional parameters:
    connString: null,                    // Database connection string
    companyId: null,                     // Company ID for multi-database setups
    language: Language::Lithuanian,      // or Language::English
    removeEmptyStringTags: false,
    removeZeroNumberTags: false,
    removeNewLines: false,
    timeout: 30,
    logger: null,                        // PSR-3 logger instance
    retry: null,                         // RetryPolicy instance
    record: false,                              // Keep the last N exchanges in memory
    recordLimit: 20,
    recordCredentials: CredentialMode::Masked,  // or Env (placeholders) / Real
);

$finvalda = new Finvalda($config);
```

### Laravel Integration

The package auto-registers via Laravel package discovery. Add your credentials to `.env`:

```env
FINVALDA_BASE_URL=https://your-server.com/FvsServicePure.svc
FINVALDA_USERNAME=your-username
FINVALDA_PASSWORD=your-password
FINVALDA_COMPANY_ID=your-company-id

# Optional: route SDK debug logs to a Laravel log channel
FINVALDA_LOG_CHANNEL=stack

# Optional: retry transient failures with exponential backoff
FINVALDA_RETRY_ENABLED=true
FINVALDA_RETRY_MAX_ATTEMPTS=3

# Optional: keep the last N request/response exchanges in memory
FINVALDA_RECORD=true
FINVALDA_RECORD_LIMIT=20
FINVALDA_RECORD_CREDENTIALS=masked  # masked | env | real
```

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=finvalda-config
```

Then inject or use the facade:

```php
// Dependency injection
public function index(Finvalda\Finvalda $finvalda)
{
    $clients = $finvalda->clients()->collect();
}

// Facade
use Finvalda\Laravel\Facades\Finvalda;

$clients = Finvalda::clients()->collect();
```

### Logging

Enable PSR-3 logging for request/response debugging:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create a logger
$logger = new Logger('finvalda');
$logger->pushHandler(new StreamHandler('path/to/finvalda.log', Logger::DEBUG));

// Option 1: Pass in config
$config = new FinvaldaConfig(
    // ...
    logger: $logger,
);

// Option 2: Set after initialization
$finvalda->setLogger($logger);
```

Both records are logged at `debug` level. `Finvalda API request` includes method, endpoint, parameters, and the full request body (`body`, string or null for GET). `Finvalda API response` includes method, endpoint, status code, response time, and the full response body (`body`). Bodies larger than 100 KB are truncated with a `... [truncated N bytes]` marker — route the SDK's debug-level records to a suitable handler if log volume is a concern.

### Debug Mode

Capture full request/response details for troubleshooting:

```php
$finvalda->setDebug(true);

// Make any API call
$result = $finvalda->operations()->create(OperationClass::Sale, $data, 'PARAM');

// Inspect what was sent and received
$debug = $finvalda->getLastDebugInfo();
print_r($debug['request']);   // method, url, headers, body
print_r($debug['response']);  // status_code, headers, body

// Disable debug mode (clears stored info)
$finvalda->setDebug(false);
```

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

$finvalda->sale()->client('C001')->save('PARD');

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
    "sParametras": "PARD",
    "xmlstring": {
        "PardDok": {
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
  -d '{"ItemClassName":"PardDok","sParametras":"PARD","xmlstring":"{\"PardDok\":{\"sKlientas\":\"C001\"}}"}'
```

Under `CredentialMode::Env` the quoting is placeholder-aware, so the command runs as-is once
the variables are exported and the secret never appears in the output:

```bash
export FVS_PASSWORD='your-password'

curl -X POST 'https://your-server.com/FvsServicePure.svc/InsertNewOperation' \
  -H 'UserName: demo' \
  -H 'Password: '"$FVS_PASSWORD" \
  -H 'Accept: application/json' \
  -H 'Language: 0' \
  -H 'Content-Type: application/json' \
  -d '{"ItemClassName":"PardDok","sParametras":"PARD","xmlstring":"{\"PardDok\":{\"sKlientas\":\"C001\"}}"}'
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
  the real password. Request headers, the URL query, the URL's userinfo password and a JSON
  request body are substituted by key; the error message, the response body and response
  header values are scrubbed by value, because Guzzle embeds the request URI (including a
  `sPassword` query parameter) in its exception messages and a server can echo a credential
  back. Value scrubbing is literal, so a credential value that legitimately appears as data
  elsewhere in a response is masked too. A masked curl needs the real value substituted
  before it runs; an `Env` curl just needs the variables exported.
- **PSR-3 logging always masks**, whatever the recording mode is set to.
- **`Content-Type: application/json` in curl output is inferred.** Guzzle adds it for JSON
  bodies; the SDK does not set it itself.
- **Recordings are the SDK's view of the request.** The recorded URL and body reproduce
  Guzzle's own resolution and encoding (RFC 3986 query encoding, the same JSON encoding
  Guzzle applies to the `json` option), but if you inject your own Guzzle client with extra
  default headers or middleware, those additions are not reflected.
- **Failures are recorded, then rethrown.** A 4xx/5xx exchange carries the status and error
  body; a connection failure carries `error` with no status.
- **Retries record one exchange per attempt**, each with its own `attempt` number and duration.
- **Bodies are capped at 100 KB each**, the same budget PSR-3 logging uses, with the excess
  replaced by a `... [truncated N bytes]` marker. Without the cap a long-lived process (the
  Laravel binding is a singleton, so a queue worker keeps one buffer for its lifetime) would
  retain `limit` whole bodies — and `Reports` endpoints answer with PDFs. A truncated body
  makes `toCurl()` non-reproducible for that exchange: the `-d` payload is no longer the
  bytes that were sent. Keep `limit` modest in long-running processes.
- **`FINVALDA_RECORD_LIMIT=0` records one exchange, not none** — the limit is clamped to a
  minimum of 1. Set `FINVALDA_RECORD=false` (or call `stopRecording()`) to disable recording.

### Retry Policy

Configure automatic retries for transient failures:

```php
use Finvalda\Retry\RetryPolicy;

// Default retry policy (3 attempts, 100ms initial delay, exponential backoff)
$config = new FinvaldaConfig(
    // ...
    retry: RetryPolicy::default(),
);

// Custom retry policy
$config = new FinvaldaConfig(
    // ...
    retry: new RetryPolicy(
        maxAttempts: 5,
        delayMs: 200,
        multiplier: 2.0,
        maxDelayMs: 10000,
        retryableStatusCodes: [429, 500, 502, 503, 504],
        retryOnNetworkError: true,
    ),
);

// Conservative policy (longer delays)
$config = new FinvaldaConfig(
    // ...
    retry: RetryPolicy::conservative(),
);

// Disable retries
$config = new FinvaldaConfig(
    // ...
    retry: RetryPolicy::noRetry(),
);
```

### Custom HTTP Client (Testing)

Inject a custom Guzzle client for testing or custom configuration:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$mock = new MockHandler([
    new Response(200, [], json_encode(['AccessResult' => 'Success', 'items' => []])),
]);

$httpClient = new \Finvalda\HttpClient($config, new Client(['handler' => HandlerStack::create($mock)]));
$finvalda = new Finvalda($config, $httpClient);
```

## Typed DTOs & Collections

The SDK provides typed Data Transfer Objects for better IDE support and type safety.

### Finding Entities

Use `find()` to get a single entity as a typed DTO with full IDE autocomplete:

```php
use Finvalda\Data\Client;
use Finvalda\Data\Product;
use Finvalda\Data\Service;
use Finvalda\Exceptions\NotFoundException;

// Find a client - returns typed Client DTO
$client = $finvalda->clients()->find('CLI001');
echo $client->name;           // Full IDE autocomplete
echo $client->email;
echo $client->vatCode;
echo $client->debt;

// Find a product
$product = $finvalda->products()->find('PRD001');
echo $product->name;
echo $product->price1;
echo $product->barcode;
echo $product->supplier1;

// Find a service
$service = $finvalda->services()->find('SVC001');
echo $service->name;
echo $service->price;

// Handle not found
try {
    $client = $finvalda->clients()->find('NONEXISTENT');
} catch (NotFoundException $e) {
    echo "Client not found";
}

// Access raw API data if needed
$rawData = $client->raw;
$specificField = $client['sSpecialField']; // ArrayAccess supported
```

### Working with Collections

Use `collect()` to get typed collections with powerful filtering and transformation methods:

```php
use Finvalda\Collections\ClientCollection;
use Finvalda\Collections\ProductCollection;

// Get all clients as a typed collection
$clients = $finvalda->clients()->collect();

// Filter clients with debt
$debtors = $clients->withDebt();
$totalDebt = $clients->totalDebt();

// Filter by type
$vipClients = $clients->whereType('VIP');

// Find by code within collection
$client = $clients->findByCode('CLI001');

// Get products
$products = $finvalda->products()->collect();

// Filter by type
$electronics = $products->whereType('ELECTRONICS');

// Filter by supplier
$fromSupplier = $products->whereSupplier('SUP001');

// Products with stock
$inStock = $products->withStock();

// Filter by tag
$tagged = $products->whereTag(1, 'FEATURED');

// Find by barcode
$product = $products->findByBarcode('1234567890123');

// Collection methods work on all collections
$clients->count();                    // Count items
$clients->isEmpty();                  // Check if empty
$clients->isNotEmpty();               // Check if not empty
$clients->first();                    // Get first item
$clients->last();                     // Get last item
$clients->get(5);                     // Get by index
$clients->all();                      // Get as array

// Filtering and mapping
$filtered = $clients->filter(fn($c) => $c->debt > 1000);
$names = $clients->map(fn($c) => $c->name);
$codes = $clients->pluck('code');

// Iteration
foreach ($clients as $client) {
    echo "{$client->code}: {$client->name}\n";
}

// Execute callback for each
$clients->each(function($client) {
    sendReminder($client);
});

// Group by field
$byType = $clients->groupBy(fn($c) => $c->type);
foreach ($byType as $type => $typeClients) {
    echo "{$type}: {$typeClients->count()} clients\n";
}

// Convert to array
$array = $clients->toArray();

// Date filtering
$recentClients = $finvalda->clients()->collect(modifiedSince: '2024-01-01');
$newProducts = $finvalda->products()->collect(createdSince: '2024-06-01');
```

## Fluent Operation Builders

Create operations using an intuitive fluent interface instead of complex nested arrays.

### Creating a Sale

```php
// Fluent builder (new way)
$result = $finvalda->sale()
    ->client('CLI001')
    ->date('2024-01-15')
    ->warehouse('MAIN')
    ->currency('EUR')
    ->description('January order')
    ->documentNumber('ORD-2024-001')
    ->paymentDays(30)
    ->priceType(1)
    ->discount(5.0)
    ->object1('DEPT01')
    ->object2('PROJ01')
    ->employee('JONAS')
    ->exportToIvaz()
    ->roundingAmount(0.01)
    ->addProduct('PRD001', quantity: 10, price: 19.99)
    ->addProduct('PRD002', quantity: 5, amount: 49.95)
    ->addService('SVC001', quantity: 2, price: 50.00)
    ->save('STANDARD');

// Equivalent array-based approach (old way - still supported)
$result = $finvalda->operations()->create(OperationClass::Sale, [
    'sKlientas' => 'CLI001',
    'tData' => '2024-01-15',
    'sSandelis' => 'MAIN',
    'sValiuta' => 'EUR',
    'sAprasymas' => 'January order',
    'sDokumentas' => 'ORD-2024-001',
    'nAtsiskDien' => 30,
    'nKainosTipas' => 1,
    'dNuolaida' => 5.0,
    'sObjektas1' => 'DEPT01',
    'sObjektas2' => 'PROJ01',
    'PardDokPrekeDetEil' => [
        ['sKodas' => 'PRD001', 'nKiekis' => 10, 'dKaina' => 19.99],
        ['sKodas' => 'PRD002', 'nKiekis' => 5, 'dSumaV' => 49.95],
    ],
    'PardDokPaslaugaDetEil' => [
        ['sKodas' => 'SVC001', 'nKiekis' => 2, 'dKaina' => 50.00],
    ],
], 'STANDARD');
```

### Using Line DTOs (Recommended for Accounting)

For operations that need VAT, amounts in EUR, objects per line, or other detail fields, use `ProductLine` and `ServiceLine` DTOs for full IDE discoverability:

```php
use Finvalda\Builders\ProductLine;
use Finvalda\Builders\ServiceLine;

$result = $finvalda->sale()
    ->client('CLI001')
    ->date('2024-01-15')
    ->currency('EUR')
    ->series('SF')
    ->employee('JONAS')
    ->product(
        ProductLine::make('MILTAI', 12.25)
            ->warehouse('CENTR.')
            ->amount(161.16, local: 161.16)
            ->vat(percent: 21, amount: 33.84, amountLocal: 33.84)
    )
    ->product(
        ProductLine::make('PIENAS', 5)
            ->warehouse('CENTR.')
            ->price(5.00)
            ->vat(percent: 21)
            ->discount(percent: 5.0)
            ->object(1, 'DEPT01')
            ->object(4, '1234567')        // sparse objects — level 2,3 skipped
    )
    ->service(
        ServiceLine::make('TRANSPORT', 1)
            ->amount(50.00, local: 50.00)
            ->vat(percent: 21, amount: 10.50, amountLocal: 10.50)
            ->object(1, 'DEPT01')
    )
    ->save('STANDARD');
```

The `product()` / `service()` methods accept line DTOs. The existing `addProduct()` / `addService()` / `addProductLine()` / `addServiceLine()` methods still work — you can mix both styles in the same builder.

**Available ProductLine methods:** `price()`, `amount()`, `vat()`, `discount()`, `warehouse()`, `object()`, `objects()`, `vatCode()`, `intrastat()`, `weight()`, `firstMeasurement()`, `secondMeasurement()`, `info()`, `marked()`, `set()`

**Available ServiceLine methods:** `price()`, `amount()`, `vat()`, `discount()`, `object()`, `objects()`, `vatCode()`, `description()`, `firstMeasurement()`, `info()`, `marked()`, `set()`

#### Quantity convention (important)

Every product/service code (`sKodas`) is configured in Finvalda with a measurement
unit that has **two dimensions** — a *first* (primary) unit, a *second* unit, and a
**first/second ratio** (`pirm_antr_sant`, from `references()->measurementUnits()`).
Examples: `M` → first = m, second = cm, ratio 100; `KG` → first = kg, second = g,
ratio 1000; `VNT` → ratio 1. The `nPirmasMat` flag selects which dimension `nKiekis`
is read in:

| `nPirmasMat` | How Finvalda reads `nKiekis` |
|---|---|
| `1` (sent) | In the **first (primary)** unit, **verbatim**. `250` on an "M" product = 250 m. |
| absent | In the **second** unit, then **rescaled by the unit's ratio**. `250` on an "M" product = 250 cm = **2.5 m**. |

The second-measurement default is **not a no-op** — Finvalda divides by the ratio.
`VNT` products (ratio 1) are unaffected, which is why piece quantities never exposed
this. All official Finvalda API examples send product lines with `"nPirmasMat":"1"`.

Services additionally use a **×100 fixed-point** encoding for the second measurement
(`1 → 100`, `0.5 → 50`); products do not. From the spec:

> **Service** `nKiekis` — *paslaugos kiekis antru matavimu (integer) arba pirmu jei nurodyta nPirmasMat=1. **Kiekis padaugintas iš 100.** Jeigu reikalingas kiekis 0.5 tada nKiekis = 50, jeigu reikalingas kiekis 1 tada nKiekis = 100. Jeigu naudojamas pirmas matavimas dauginti nereikia.*
>
> **Product** `nKiekis` — *prekės kiekis antru matavimu (integer) arba pirmu jei nurodyta nPirmasMat=1.* (no ×100)

##### What the SDK does for you

| Builder helper | Default | Opt-out |
|---|---|---|
| `ProductLine::make($code, $qty)` | **First measurement**: emits `nKiekis = $qty` verbatim and `nPirmasMat = 1` | `->secondMeasurement()` (or `->firstMeasurement(false)`) drops `nPirmasMat` so Finvalda rescales by the unit ratio |
| `ServiceLine::make($code, $qty)` | **Second measurement**: emits `nKiekis = round($qty × 100)` | `->firstMeasurement()` emits `nKiekis = $qty` as-is and `nPirmasMat = 1` |
| `addProduct()` | Emits `nKiekis` **verbatim** and `nPirmasMat = 1` | Pass `additionalData: ['nPirmasMat' => 0]`, or use `addProductLine()` for raw control |
| `addService()` | Emits `nKiekis` **verbatim** (no scaling, no `nPirmasMat`) | Pass `nPirmasMat` via `additionalData` |
| `addProductLine()` / `addServiceLine()` | Emit the line array **verbatim** (no defaults) | — |

> **Why product lines default to `nPirmasMat=1`:** real quantities are expressed in the
> primary unit (you book "250 metres", not "25000 cm"). Omitting the flag silently
> divided M/KG quantities by their ratio. Both `ProductLine::make()` and the
> `addProduct()` helper default to the primary unit; `addProductLine()` stays a raw
> passthrough for full control.

```php
// Product (default, first measurement): qty sent verbatim in the primary unit
$finvalda->sale()->client('CLI001')->product(
    ProductLine::make('1141817', 250.0)        // nKiekis = 250, nPirmasMat = 1 → 250 m
)->save('STANDARD');

// Product, second measurement: Finvalda rescales by the unit ratio (rarely what you want)
$finvalda->sale()->client('CLI001')->product(
    ProductLine::make('1141817', 250.0)->secondMeasurement()  // nKiekis = 250 → 2.5 m on an "M" product
)->save('STANDARD');

// Service, second measurement (default): qty 1 → nKiekis 100, 0.5 → 50
$finvalda->sale()->client('CLI001')->service(
    ServiceLine::make('TRANSPORT', 0.5)        // nKiekis = 50
)->save('STANDARD');

// Service, first measurement: qty sent as-is
$finvalda->sale()->client('CLI001')->service(
    ServiceLine::make('TRANSPORT', 1)->firstMeasurement()   // nKiekis = 1, nPirmasMat = 1
)->save('STANDARD');
```

For rare/niche API fields not covered by named methods, use the `set()` escape hatch:

```php
ProductLine::make('SPECIAL', 1)
    ->warehouse('CENTR.')
    ->vat(percent: 21)
    ->set('sAtitSer', 'CERT-001')     // conformity certificate
    ->set('tGalData', '2025-12-31')   // expiry date
```

### Creating a Purchase

```php
use Finvalda\Enums\DocumentType;

$result = $finvalda->purchase()
    ->client('SUP001')
    ->date('2024-01-15')
    ->warehouse('MAIN')
    ->currency('EUR')
    ->series('SF')                          // sSerija — document series
    ->documentType(DocumentType::VatInvoice) // sDokRusis — or pass 'SF'
    ->supplierInvoice('INV-2024-001')
    ->supplierInvoiceDate('2024-01-14')
    ->paymentDays(60)
    ->addProduct('PRD001', quantity: 100, price: 9.99)
    ->addProduct('PRD002', quantity: 50, price: 14.99)
    ->addService('FREIGHT', quantity: 1, amount: 150.00)
    ->save('STANDARD');
```

#### Document type, series & the operation parameter

`series()`, `documentType()`, and the `save()` parameter are **three independent
inputs** to a create call — none of them is derived from the others:

| Builder method | API field | Controls |
|---|---|---|
| `documentType()` | `sDokRusis` | The document type (see codes below) |
| `series()` | `sSerija` | The document series |
| `save('STANDARD')` | `sParametras` | The server-configured import/journal profile |

`documentType()` accepts a `DocumentType` enum case or a raw 2-character code:

| Code | `DocumentType` case | Meaning |
|---|---|---|
| `S`  | `Invoice` | Sąskaita faktūra |
| `SF` | `VatInvoice` | PVM sąskaita faktūra |
| `D`  | `DebitInvoice` | Debetinė sąskaita |
| `DS` | `DebitVatInvoice` | Debetinė PVM sąskaita |
| `K`  | `CreditInvoice` | Kreditinė sąskaita |
| `KS` | `CreditVatInvoice` | Kreditinė PVM sąskaita faktūra |
| `KT` | `Other` | Kita |
| `VS` | `LawyerVatInvoice` | Advokatų PVM sąskaita faktūra |
| `VD` | `LawyerVatInvoiceDebit` | Advokatų PVM sąskaita faktūra debetinė |
| `VK` | `LawyerVatInvoiceCredit` | Advokatų PVM sąskaita faktūra kreditinė |

These methods are available on `sale()`, `salesReservation()`, `salesReturn()`,
`purchase()`, `purchaseOrder()`, and `purchaseReturn()`.

> **`sParametras` is required and cannot be bypassed.** The operation *type*
> (purchase vs. sale, i.e. `ItemClassName`) is what you pick by choosing the
> builder, and you set `sDokRusis`/`sSerija` yourself — but the journal an
> operation lands in is resolved server-side from the `sParametras` profile you
> pass to `save()`. There is no header field to specify the journal directly on
> create; the resulting journal/number come back on the `OperationResult`.

### Additional Purchase Costs

A purchase header can declare up to four **additional-cost buckets** (*papildomos
išlaidos*) — named categories such as freight, registration or insurance — whose
amounts are then allocated per product line. The two halves are one feature: bucket
codes without per-line amounts book nothing, and per-line amounts without bucket
codes have nowhere to land.

```php
$finvalda->purchase()
    ->client('SUP001')
    ->date('2026-07-28')
    ->warehouse('WH01')
    ->supplierInvoice('INV-2026-0042')
    ->additionalCostCodes(['KITOS', 'TRANSP', 'ILGALSAV', 'DRAUDIM'])  // slots 1-4
    ->product(
        ProductLine::make('WSM000001TB061527', 1)
            ->warehouse('WH01')
            ->amount(38_500.00)
            ->additionalCost(2, 950.00)              // TRANSP  → dPapIsldSumaV2/L2
            ->additionalCosts([4 => 310.00])         // DRAUDIM → dPapIsldSumaV4/L4
    )
    ->save('PIRKNAU');
```

| Method | API fields | Notes |
|---|---|---|
| `additionalCostCodes([...])` | `sPapIslaiduKodas1..4` | Ordered list fills slots 1-4 in order; a slot-keyed map (`[2 => 'TRANSP']`) sets individual slots. Max 4 codes, 10 chars each. |
| `ProductLine::additionalCost($slot, $currency, $local)` | `dPapIsldSumaV{slot}`, `dPapIsldSumaL{slot}` | Always writes both the currency and EUR halves; `$local` defaults to `$currency`. |
| `ProductLine::additionalCosts([$slot => $amount])` | same | Plural convenience, keyed by slot. |

> **The slot *number* is the binding.** Slot 2 in the header is what slot 2 on the
> line allocates to. Slots are positional and server-configured — do not reorder
> them between bookings of the same journal, or previously booked allocations stop
> lining up with their buckets.

Availability follows the spec exactly:

- Cost codes exist on **`purchase()` and `purchaseOrder()` only** (*Tik PirkDok ir
  PirkUzsDok*). `purchaseReturn()` has no such method.
- They are **not** available on `->short()` — `TrumpasPirkDok` has no
  additional-cost fields. Since `short()` may be called after
  `additionalCostCodes()`, that conflict is reported by `build()`/`save()`.
- Per-line amounts are **product lines only** (*Tik PirkDokPrekeDetEil*).
  `ServiceLine` has no `additionalCost()`, because service detail rows do not
  define the fields.

The SDK does not round the amounts (the fields are `Numeric(14,2)`) and cannot
check that the header actually declares a code in the slot you allocate to — the
line object cannot see the header.

### Correcting a Purchase

`purchaseUpdate()` builds the `KoregPirkDok` envelope for `UpdateOperation`.

> ### ⚠️ A correction is destructive, not an edit
>
> `KoregPirkDok` corrects an operation by **deleting** the named detail lines and
> **re-adding** the ones you supply. Re-adding a product line **rebuilds that
> product's FIFO stock layer**, and the internal delete fails outright once the
> goods have been consumed by another operation — a sale, write-off, transfer or
> production run. The error is **`4027` — *Operacijos detalios eilutės yra
> panaudotos kitose operacijose!*** Note it is documented under operation
> *deletion* errors, not the `5000`–`5005` correction family, so an **update** call
> can return a **deletion**-class code.
>
> Three things worth knowing before you ship a caller:
>
> 1. **It is not idempotent.** A re-added line is a new acquisition with a new cost
>    layer. Re-sending the same correction is not a no-op.
> 2. **It fails late.** Unsold stock corrects fine; the same code path starts
>    failing the day someone sells the goods.
> 3. **Do not rely on partial success.** If a multi-line correction rejects on one
>    consumed line, re-read the operation before retrying.
>
> Check `Stock::purchaseOpFor($code)['sold']` first, or call `assertNotSold()`.
> `UpdPrekeDetEil` is **not** an escape hatch: the spec gives it only `sKodas`,
> `nKodasN`, `nPozymis` and `sPapInfo`, so it can flip a line's marked flag and free
> text but cannot restate amounts. There is no way to re-allocate costs without the
> delete/re-add cycle.

```php
$stock = $finvalda->stock()->purchaseOpFor('WSM000001TB061527');

if ($stock === null || $stock['sold']) {
    // Sold, or never purchased. A correction here would hit 4027, or rebuild a
    // stock layer underneath a sale. Report it; let an accountant handle it.
    return;
}

$finvalda->purchaseUpdate()
    ->journal($stock['journal'])
    ->number($stock['op_number'])
    ->additionalCostCodes([2 => 'TRANSP'])
    ->removeProduct('WSM000001TB061527', $stock['warehouse'])
    ->product(
        ProductLine::make('WSM000001TB061527', 1)
            ->warehouse($stock['warehouse'])
            ->amount(38_500.00)
            ->vat(percent: 21, amount: 8_085.00)
            ->additionalCost(2, 1_270.00)   // was 950.00
    )
    ->save('PIRKNAU');
```

This emits the documented envelope — note that it is **not** the insert envelope:

```json
{
  "KoregPirkDok": {
    "sZurnalas": "PIRKNAU",
    "nNumeris": 1421,
    "PirkDokHeadEil": { "sPapIslaiduKodas2": "TRANSP" },
    "DelPrekeDetEil": [ { "sKodas": "WSM000001TB061527", "sSandelis": "WH01" } ],
    "PirkDokPrekeDetEil": [ { "sKodas": "WSM000001TB061527", "...": "..." } ]
  }
}
```

| Method | Node | Notes |
|---|---|---|
| `journal()` / `number()` | `sZurnalas`, `nNumeris` | Both required — they identify the operation to correct. |
| `header([...])` | `PirkDokHeadEil` | Raw field names, merged across calls, validated against the documented column set. Omit to leave the header alone. |
| `additionalCostCodes([...])` | `PirkDokHeadEil` | Same method as on insert (*Tik KoregPirkDok ir KoregPirkUzsDok*). |
| `removeProduct($code, $warehouse)` | `DelPrekeDetEil` | Warehouse optional. |
| `removeService($code)` | `DelPaslaugaDetEil` | |
| `product(ProductLine)` | `PirkDokPrekeDetEil` | Requires `sKodas`, `sSandelis`, `dSumaV`, `dSumaL`, `nKiekis`. |
| `service(ServiceLine)` | `PirkDokPaslaugaDetEil` | Requires `sKodas`, `dSumaV`, `dSumaL`, `nKiekis`. |
| `assertNotSold()` | — | One `purchaseOpFor()` round trip per distinct product code; throws `ConflictException` when a touched product is sold. |

What `header()` deliberately **refuses**, because `PirkDokHeadEil` does not accept
it for a purchase:

- **`sKlientas`** — a purchase correction cannot change the supplier (*Tik
  KoregPardDok ir KoregPardRezDok*).
- **Any operation date** — the node defines none, neither `tData` nor
  `tTiekejoSFData`. The operation date is not correctable here.
- **`sObjektas5` / `sObjektas6`** — the node stops at `sObjektas4`.
- **Waybill (*važtaraštis*) fields** — documented for sales, sales reservations and
  purchase returns only.

`assertNotSold()` **fails closed**: a product whose purchase history cannot be
resolved is refused too, since a guard on a destructive call must not pass just
because the lookup failed. It is deliberately *not* run by `save()` — it costs a
round trip per code and hides a decision the caller should be making.

Only purchases are covered. The other six `UpdateOperationClass` cases share the
envelope shape but each has its own `Tik ...` annotations; use
`operations()->update()` with a hand-built payload for those.

### Creating an Internal Transfer

```php
$result = $finvalda->internalTransfer()
    ->date('2024-01-15')
    ->fromWarehouse('MAIN')      // header field sIsSandelio
    ->toWarehouse('BRANCH')      // header field sISandeli
    ->description('Restock branch warehouse')
    ->addTransfer('PRD001', quantity: 50)
    ->addTransfer('PRD002', quantity: 25)
    ->save('TRANSFER');
```

An internal transfer carries a **single** source/destination warehouse pair at the
header level — the detail rows have no per-line warehouse fields. To move stock
between different warehouse pairs, create separate transfer operations.

### Creating Returns

```php
// Sales return
$result = $finvalda->salesReturn()
    ->client('CLI001')
    ->date('2024-01-20')
    ->warehouse('MAIN')
    ->originalDocument('SF-001', 'PARD', 123)
    ->reason('Defective product')
    ->addProduct('PRD001', quantity: 2, price: 19.99)
    ->save('RETURN');

// Purchase return
$result = $finvalda->purchaseReturn()
    ->client('SUP001')
    ->date('2024-01-20')
    ->warehouse('MAIN')
    ->originalDocument('PO-001', 'PIRK', 456)
    ->reason('Wrong items delivered')
    ->addProduct('PRD001', quantity: 10, price: 9.99)
    ->save('RETURN');
```

> **Note:** In live testing the full `PardGrazDok` variant was rejected with error
> 2012 ("Xml string is incomplete") even with a spec-correct payload, while the
> same fields via `->short()` (`TrumpasPardGrazDok`) succeeded. If a full-variant
> return fails with 2012, use `->short()` — it is the shape proven to work.
>
> The `originalDocument()` / `reason()` fields (`sGrazDokumentas`, `sGrazZurnalas`,
> `nGrazNumeris`, `sGrazPriezastis`) do not appear in the official FVS spec.
> Finvalda silently ignores unknown fields, so verify against your server that the
> linkage actually lands before relying on it.

### Creating Payments

```php
// Payment received (inflow)
$result = $finvalda->inflow()
    ->client('CLI001')
    ->date('2024-01-15')
    ->amount(500.00)
    ->currency('EUR')
    ->bankAccount('BANK01')
    ->description('Payment for invoice SF-001')
    ->forDocument('SF-001', 'PARD', 123, amount: 500.00)
    ->save('INFLOW');

// Payment out (disbursement)
$result = $finvalda->disbursement()
    ->client('SUP001')
    ->date('2024-01-15')
    ->amount(1000.00)
    ->currency('EUR')
    ->bankAccount('BANK01')
    ->description('Payment for purchase PO-001')
    ->forDocument('PO-001', 'PIRK', 456, amount: 1000.00)
    ->save('DISBURSEMENT');
```

### Builder Advanced Usage

```php
// Set the parameter once
$sale = $finvalda->sale()->parameter('STANDARD');

// Add custom header fields
$sale->setHeader('sCustomField', 'value');

// Add product lines with additional data
$sale->addProduct('PRD001', quantity: 10, price: 19.99, warehouse: 'WH01', additionalData: [
    'sLot' => 'LOT001',
    'tExpiryDate' => '2025-12-31',
]);

// Add raw product line
$sale->addProductLine([
    'sKodas' => 'PRD002',
    'nKiekis' => 5,
    'dKaina' => 29.99,
    'sSandelis' => 'WH01',
    'sSerialNumber' => 'SN12345',
]);

// Build without saving (for inspection)
$data = $sale->build();
print_r($data);

// Save
$result = $sale->save();
```

### Write-Offs & Capitalization

```php
// Write-off (disposal of inventory)
$result = $finvalda->writeOff()
    ->date('2024-01-15')
    ->name('Monthly write-off')
    ->note('Damaged goods')
    ->employee('Jonas')
    ->addItem('PRD001', quantity: 5, warehouse: 'MAIN', account: '6110')
    ->addItem('PRD002', quantity: 3, warehouse: 'MAIN', account: '6110')
    ->save('WRITEOFF');

// Capitalization (receiving inventory)
$result = $finvalda->capitalization()
    ->date('2024-01-15')
    ->name('Inventory receiving')
    ->addItem('PRD001', quantity: 10, amount: 199.90, warehouse: 'MAIN', account: '2010')
    ->save('CAPITALIZE');
```

### Creating a Production Operation

```php
$result = $finvalda->production()
    ->date('2024-01-15')
    ->finishedProduct('FINISHED001')
    ->documentNumber('PROD-001')
    ->description('Daily production run')
    ->addFinishedGood('FINISHED001', warehouse: 'MAIN', quantity: 100, amount: 500.00)
    ->addRawMaterial('RAW001', warehouse: 'MAIN', quantity: 200)
    ->addRawMaterial('RAW002', warehouse: 'MAIN', quantity: 50)
    ->addProductionService('SVC001', amount: 100.00, quantity: 1)
    ->save('PRODUCTION');
```

### Non-Analytical Operations

```php
$result = $finvalda->nonAnalytical()
    ->date('2024-01-15')
    ->currency('EUR')
    ->documentNumber('DEP-001')
    ->description1('Depreciation entry')
    ->addEntry('6110', 'Equipment depreciation', debitLocal: 500.00, creditLocal: 0)
    ->addEntry('1240', 'Accumulated depreciation', debitLocal: 0, creditLocal: 500.00)
    ->save('JOURNAL');
```

### Inventory Count

```php
$result = $finvalda->inventoryCount()
    ->journal('INVENT')
    ->warehouse('01')
    ->date('2024-03-03')
    ->addItem('B.BENZINAS', quantity: 15.45, account: '1275')
    ->addItem('B.DYZELINAS', quantity: 20.00, account: '1275')
    ->save('INVENTORY');

// Append to an existing inventory count
$result = $finvalda->inventoryCount()
    ->mode(1)
    ->journal('INVENT')
    ->warehouse('01')
    ->date('2024-03-03')
    ->addItem('B.PROPANAS', quantity: 8.00, account: '1275')
    ->save('INVENTORY');
```

### Clearing / Set-Off

```php
$result = $finvalda->clearing()
    ->date('2024-01-15')
    ->name('Monthly clearing')
    ->debtor('CLI001')
    ->creditor('CLI002')
    ->addDebitLine(amount: 270.00, series: 'SF', document: '001', type: 3)
    ->addCreditLine(amount: 270.00, series: 'PF', document: '002', type: 2)
    ->save('CLEARING');

// Using account entries (type 6)
$result = $finvalda->clearing()
    ->date('2024-01-15')
    ->debtor('CLI001')
    ->creditor('CLI002')
    ->addDebitAccount(amount: 270.00, account: '241000')
    ->addCreditAccount(amount: 270.00, account: '241001')
    ->save('CLEARING');
```

### UVM (Order Management)

```php
// UVM sales reservation (workshop/service order)
$result = $finvalda->uvmSalesReservation()
    ->client('HTNT')
    ->date('2024-01-15')
    ->operationType('PARDSERV')
    ->fulfillmentDate('2024-01-20')
    ->currency('EUR')
    ->object1('SERVISAS')
    ->description('Workshop order #30608')
    ->addService('5054', quantity: 1, price: 0, additionalData: [
        'sPavadinimas' => 'Service description',
    ])
    ->save('WORKSHOP');

// UVM purchase order
$result = $finvalda->uvmPurchaseOrder()
    ->client('SUP001')
    ->date('2024-01-15')
    ->currency('EUR')
    ->operationType('PIRK')
    ->addProduct('PRD001', quantity: 24, price: 3.50, warehouse: 'CENTR.')
    ->save('ORDER');

// UVM cancellation
$result = $finvalda->uvmCancellation()
    ->date('2024-01-15')
    ->name('Cancel reservations')
    ->documentNumber('ANUL-001')
    ->addCancellation(journal: 'UVMPARD', number: 123)
    ->addCancellation(journal: 'UVMPARD', number: 124)
    ->save('CANCEL');
```

### Short / Simplified Operations

All sales, purchase, and return builders support a `short()` mode that uses simplified operation variants. Short operations send minimal headers and let the server fill in defaults.

```php
// Short sale — server applies default settings
$result = $finvalda->sale()
    ->short()
    ->client('CLI001')
    ->date('2024-01-15')
    ->series('SF')
    ->currency('EUR')
    ->addProduct('PRD001', quantity: 10, price: 19.99)
    ->save('STANDARD');

// Short purchase return
$result = $finvalda->purchaseReturn()
    ->short()
    ->client('SUP001')
    ->currency('EUR')
    ->series('GR')
    ->addProduct('PRD001', quantity: 10, price: 9.99)
    ->save('RETURN');
```

Builders supporting `short()`: `sale()`, `salesReservation()`, `salesReturn()`, `purchase()`, `purchaseOrder()`, `purchaseReturn()`.

## Query Builders

Build queries fluently for better readability and IDE support.

### Transaction Query

```php
use Finvalda\Query\TransactionQuery;

// Create a fluent query
$query = TransactionQuery::create()
    ->journal('PARD')
    ->series('AA')
    ->dateRange('2024-01-01', '2024-12-31')
    ->modifiedSince('2024-06-01');

// Use with transactions resource
$response = $finvalda->transactions()->sales($query->toFilter());
$response = $finvalda->transactions()->salesDetail($query->toFilter());

// Query methods
$query = TransactionQuery::create()
    ->journal('PARD')              // Filter by journal code
    ->operationNumber(123)         // Filter by operation number
    ->series('AA')                 // Filter by document series
    ->orderNumber('SF-001')        // Filter by order/document number
    ->journalGroup('SALES_GRP')    // Filter by journal group
    ->dateFrom('2024-01-01')       // Operation date from
    ->dateTo('2024-12-31')         // Operation date to
    ->dateRange('2024-01-01', '2024-12-31')  // Both dates at once
    ->modifiedSince('2024-06-01'); // Only modified since
```

### Operation Query

```php
use Finvalda\Query\OperationQuery;

// Factory methods for common operation types
$query = OperationQuery::sales()
    ->journal('PARD')
    ->dateRange('2024-01-01', '2024-12-31')
    ->client('CLI001');

// Use with operations resource
$response = $finvalda->operations()->query($query->opClass(), $query->build());

// All factory methods
$query = OperationQuery::sales();
$query = OperationQuery::salesDetail();
$query = OperationQuery::purchases();
$query = OperationQuery::purchasesDetail();
$query = OperationQuery::inflows();
$query = OperationQuery::inflowsDetail();
$query = OperationQuery::disbursement();
$query = OperationQuery::disbursementDetail();
$query = OperationQuery::internalTransactions();
$query = OperationQuery::internalTransactionsDetail();
$query = OperationQuery::forClass(OpClass::SalesReturns);

// Query methods
$query = OperationQuery::sales()
    ->journal('PARD')
    ->number(123)
    ->series('AA')
    ->client('CLI001')
    ->warehouse('WH01')
    ->product('PRD001')
    ->dateFrom('2024-01-01')
    ->dateTo('2024-12-31')
    ->modifiedSince('2024-06-01')
    ->journalGroup('SALES_GRP')
    ->object1('DEPT01')
    ->object2('PROJ01');
```

## Validation

Validate data before sending to the API to catch errors early:

```php
use Finvalda\Validation\Validator;
use Finvalda\Validation\Rules\Required;
use Finvalda\Validation\Rules\StringLength;
use Finvalda\Validation\Rules\NumericRange;
use Finvalda\Validation\Rules\DateFormat;
use Finvalda\Exceptions\ValidationException;

// Define validation rules
$validator = new Validator([
    'sKodas' => [new Required(), StringLength::max(50)],
    'sPavadinimas' => [new Required(), StringLength::max(200)],
    'dKaina' => [NumericRange::positive()],
    'tData' => [DateFormat::ymd()],
]);

// Validate data
$result = $validator->validate([
    'sKodas' => 'PRD001',
    'sPavadinimas' => 'Product Name',
    'dKaina' => 19.99,
    'tData' => '2024-01-15',
]);

if ($result->fails()) {
    foreach ($result->errors as $field => $errors) {
        echo "{$field}: " . implode(', ', $errors) . "\n";
    }
}

// Or validate and throw exception
try {
    $validator->validateOrFail($data);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    $allMessages = $e->getAllErrors();
}

// Quick validation
$result = Validator::check($data, [
    'sKodas' => [new Required()],
    'sPavadinimas' => [new Required(), StringLength::between(3, 200)],
]);

// Available rules
new Required();                          // Field is required
new Required('Custom message');          // With custom message
StringLength::max(50);                   // Max 50 characters
StringLength::min(3);                    // Min 3 characters
StringLength::between(3, 50);            // Between 3 and 50
StringLength::exact(10);                 // Exactly 10 characters
NumericRange::positive();                // >= 0
NumericRange::positiveNonZero();         // > 0
NumericRange::min(10);                   // >= 10
NumericRange::max(100);                  // <= 100
NumericRange::between(10, 100);          // Between 10 and 100
DateFormat::ymd();                       // Y-m-d format
DateFormat::datetime();                  // Y-m-d H:i:s format
new DateFormat('d/m/Y');                 // Custom format
```

> The lengths above are illustrative. For the **real** per-field maximum lengths,
> see the [Field Reference](#field-reference) — e.g. a client `sKodas` is max 15
> chars, `sPavadinimas` max 100.

## Field Reference

The API enforces per-field **maximum text lengths** and **numeric precision**, and
marks each field as mandatory or auto-filled from the server parameter profile. This is
documented exhaustively in the official spec, which is included in this repo:

**→ [`docs/FVS_Webservice.md`](docs/FVS_Webservice.md)** (the single source of truth)

Look up the write payload you're building:

- **Master data** (`InsertNewItem`) — `Fvs.Preke` (products), `Fvs.Paslauga`
  (services), `Fvs.Klientas` (clients), objects, banks, warehouses, types/tags.
- **Operations** (`InsertNewOperation` / `UpdateOperation`) — `PardDok` (sales),
  `PirkDok` (purchases), `IplDok`/`IsmDok` (payments), `VidPerkDok` (transfers),
  `NurasymasDok`/`PajamavimasDok` (write-off/capitalization), `GamybaDok` (production),
  `UzskaitaDok` (clearing), `KtNeanalitDok` (non-analytical), UVM, and their `*DetEil`
  detail lines.

Each field is a table row with these columns: **type · field name · description · max
length · required · auto-filled-from-parameter · notes**. A `+` in the *required* column
means mandatory; a `+` in the *auto-filled* column means the webservice supplies it from
the parameter profile when omitted. For example, client `sKodas` is `String` max **15**,
required; `sPavadinimas` max **100**; `sEMail` max **30**, optional. Money amounts are
`Numeric (14,2)`.

## Resources

All read methods return a `Response` object:

```php
$response = $finvalda->clients()->list();

$response->successful();  // bool - whether the request succeeded
$response->failed();      // bool - whether the request failed
$response->data;          // array - the response data
$response->error;         // ?string - error message if failed
$response->raw;           // array - full raw response
```

All write methods return an `OperationResult` object:

```php
$result = $finvalda->clients()->create($data);

$result->success;     // bool
$result->series;      // ?string
$result->document;    // ?string
$result->journal;     // ?string
$result->number;      // ?int
$result->error;       // ?string
$result->errorCode;   // ?int
```

### Stock / Inventory

```php
// Current stock balances
$response = $finvalda->stock()->balances();
$response = $finvalda->stock()->balances(productCode: 'PROD001', warehouseCode: 'WH01');

// Extended balances (includes product type, tags)
$response = $finvalda->stock()->balancesExtended();

// Balances with selling prices
$response = $finvalda->stock()->balancesWithPrices(includeZeroQuantity: true);

// Balances by warehouse group
$response = $finvalda->stock()->balancesByGroup(warehouseGroupCode: 'GROUP1');

// Ordered products
$response = $finvalda->stock()->orderedProducts();
```

#### Which purchase operation holds this stock?

For serialised, quantity-1 stock (a VIN, a serial number), `purchaseOpFor()` answers
two questions that otherwise get re-derived by every caller: *which purchase
operation currently holds it*, and *has it been sold since*. Both come from
`GetPrekesIstorija`.

```php
$op = $finvalda->stock()->purchaseOpFor('WSM000001TB061527');

if ($op === null) {
    // never purchased, or the history call failed
} elseif ($op['sold']) {
    // sold on $op['sale_date'] via $op['sale_journal'] #$op['sale_op_number']
} else {
    // current layer: $op['journal'] #$op['op_number'], warehouse $op['warehouse']
}
```

```php
[
    'journal' => 'PIRKNAU', 'op_number' => 1421, 'warehouse' => 'WH01',
    'op_date' => '2026-07-01',
    'sold' => true, 'sale_journal' => 'PARD1', 'sale_op_number' => 77,
    'sale_date' => '2026-07-20',   // sale_* are null when sold is false
]
```

- **The latest purchase wins.** A re-acquired item has several purchase rows; only
  the most recent one holds the current stock layer.
- **A sale counts only when dated at or after that purchase.** An older sale belongs
  to a previous ownership cycle (bought → sold → bought back).
- Unlike the rest of this resource it returns a **plain array, not a `Response`**,
  and **never throws** — it exists to be used as a pre-flight check (see
  [Correcting a Purchase](#correcting-a-purchase)). `null` means no purchase history
  or a failed call. Use `products()->history()` for the raw rows.
- Operation kinds are matched on the literal strings `Pirkimai`/`Pardavimai` in
  `op_rusis_pav`. The spec documents the column but never enumerates its values, so
  these are **observed against a live Finvalda, not specified**; an unrecognised kind
  is ignored rather than guessed at.

### Clients

```php
use Finvalda\Enums\ClientTypeId;

// List / find / collect
$response = $finvalda->clients()->list();
$response = $finvalda->clients()->list(modifiedSince: '2024-01-01');
$response = $finvalda->clients()->get('CLIENT001');
$client = $finvalda->clients()->find('CLIENT001');      // Returns typed Client DTO
$clients = $finvalda->clients()->collect();             // Returns ClientCollection

// All clients (with optional date filters)
$response = $finvalda->clients()->all();
$response = $finvalda->clients()->all(modifiedSince: '2024-01-01');

// Find client by email
$response = $finvalda->clients()->findByEmail('client@example.com');

// Client types and tags — see "Types and tags" below for how this works
$types = $finvalda->clients()->typesAndTags(ClientTypeId::Type);     // TypeTagCollection of client types
$tag1  = $finvalda->clients()->typesAndTags(ClientTypeId::Tag1);     // Tag 1 options
$all   = $finvalda->clients()->allTypesAndTags();                    // whole dictionary in one call

// Clients by type
$response = $finvalda->clients()->byType('VIP');

// Accounts (with full filter support)
$response = $finvalda->clients()->accounts(clientCode: 'CLIENT001');
$response = $finvalda->clients()->accounts(
    clientCode: 'CLIENT001',
    journalGroup: 'PARD',
    debtType: 1,
    documentDateFrom: '2024-01-01',
    documentDateTo: '2024-12-31',
);

// Unpaid documents (sales and purchases)
$response = $finvalda->clients()->unpaidDocuments('CLIENT001');
$response = $finvalda->clients()->unpaidPurchaseDocuments('CLIENT001');

// Client debt condition
$response = $finvalda->clients()->debtCondition('CLIENT001', journalGroup: 'PARD');

// Settlements
$response = $finvalda->clients()->settlements(series: 'SER', document: 'DOC001');
$response = $finvalda->clients()->settlements(journal: 'PARD', number: 123);
$response = $finvalda->clients()->settlementsDetailed(series: 'SER', document: 'DOC001');
$response = $finvalda->clients()->settlementsFromDate(series: 'SER', modifiedSince: '2024-01-01');
$response = $finvalda->clients()->settlementsFromDateParam($xmlParam); // raw XML param variant

// CRUD operations
$result = $finvalda->clients()->create([
    'sKodas' => 'NEW001',
    'sPavadinimas' => 'New Client Ltd',
    'sDebtSask' => '2410',
    'sKredSask' => '5001',
]);

$result = $finvalda->clients()->update([
    'sKodas' => 'NEW001',
    'sPavadinimas' => 'Updated Client Name',
]);

$result = $finvalda->clients()->delete('CLIENT001');

// Invoices related to a customer
$response = $finvalda->clients()->invoicesRelatedToCustomer('CLIENT001', debtType: 0);
```

### Products

```php
use Finvalda\Enums\ProductTypeId;

// List / find / collect
$response = $finvalda->products()->list();
$response = $finvalda->products()->get('PROD001');
$product = $finvalda->products()->find('PROD001');      // Returns typed Product DTO
$products = $finvalda->products()->collect();           // Returns ProductCollection

// Extended list with filters
$response = $finvalda->products()->listExtended(
    type: 'ELECTRONICS',
    supplier1: 'SUPP01',
    modifiedSince: '2024-01-01',
);

// All products
$response = $finvalda->products()->all(modifiedSince: '2024-01-01');

// Product image (envelope with base64 `fileContents`)
$response = $finvalda->products()->image('PROD001');

// Product image as decoded JPG bytes
$jpg = $finvalda->products()->imageJpeg('PROD001');

// Products in warehouse
$response = $finvalda->products()->inWarehouse('WH01', modifiedSince: '2024-01-01');
$response = $finvalda->products()->inWarehouseOrdered('WH01', order: 1);

// Types and tags — see "Types and tags" below for how this works
$types = $finvalda->products()->typesAndTags(ProductTypeId::Type);    // TypeTagCollection of product types
$tag1  = $finvalda->products()->typesAndTags(ProductTypeId::Tag1);    // Tag 1 options
$all   = $finvalda->products()->allTypesAndTags();                    // whole dictionary in one call
$response = $finvalda->products()->typeGroups();
$response = $finvalda->products()->typeGroupComposition('GRP01');
$response = $finvalda->products()->byType('ELECTRONICS');

// Product history
$response = $finvalda->products()->history('PROD001', dateFrom: '2024-01-01');

// Sold products per period
$response = $finvalda->products()->soldPerPeriod(
    productCode: 'PROD001',
    warehouseCode: 'WH01',
    dateFrom: '2024-01-01',
    dateTo: '2024-12-31',
);

// CRUD operations
$result = $finvalda->products()->create([
    'sKodas' => 'NEWPROD',
    'sPavadinimas' => 'New Product',
    'sRysysSuSask' => '2414',
    'sMatavimoVnt' => 'vnt',
]);

$result = $finvalda->products()->update([
    'sKodas' => 'PROD001',
    'sPavadinimas' => 'Updated Product Name',
]);

// Bulk edit product properties (applies to multiple products at once)
$result = $finvalda->products()->editProperties([
    'Kodas' => ['PROD001', 'PROD002', 'PROD003'],
    'pardKaina1' => '19.99',
    'pardVal' => 'EUR',
]);

$result = $finvalda->products()->delete('PROD001');
```

### Services

```php
use Finvalda\Enums\ServiceTypeId;

// List / find / collect
$response = $finvalda->services()->list();
$response = $finvalda->services()->get('SVC001');
$service = $finvalda->services()->find('SVC001');       // Returns typed Service DTO
$services = $finvalda->services()->collect();           // Returns ServiceCollection

// All services
$response = $finvalda->services()->all(modifiedSince: '2024-01-01');

// Types and tags — see "Types and tags" below for how this works
$types = $finvalda->services()->typesAndTags(ServiceTypeId::Type);    // TypeTagCollection of service types
$tag1  = $finvalda->services()->typesAndTags(ServiceTypeId::Tag1);    // Tag 1 options
$all   = $finvalda->services()->allTypesAndTags();                    // whole dictionary in one call
$response = $finvalda->services()->byType('CONSULTING');

// CRUD operations
$result = $finvalda->services()->create([
    'sKodas' => 'NEWSVC',
    'sPavadinimas' => 'New Service',
    'sRysysSuSask' => '5001',
]);

$result = $finvalda->services()->update(['sKodas' => 'SVC001', 'sPavadinimas' => 'Updated']);
$result = $finvalda->services()->delete('SVC001');
```

### Types and tags (rūšys ir požymiai)

Products, clients and services each have a "type" (rūšis) plus a number of "tag"
groups (požymiai). Finvalda exposes them through one endpoint per entity:

| Entity   | Endpoint                  | Accessor                          |
|----------|---------------------------|-----------------------------------|
| Products | `GetPrekiuRusisPozymius`  | `$finvalda->products()`           |
| Clients  | `GetKlientuRusisPozymius` | `$finvalda->clients()`            |
| Services | `GetPaslauguRusisPozymius`| `$finvalda->services()`           |

**One call returns the whole dictionary.** Each endpoint returns *every* type and
*every* tag group in a single response. The rows are discriminated by a `tipas`
column. The legacy `nID` request parameter is **ignored by the server** — passing
different values returns byte-identical results — so the SDK does not send it and
filters by `tipas` client-side instead.

**`tipas` → field mapping** (note the non-sequential numbering for clients/services):

| Entity   | Type | Tag1 | Tag2 | Tag3 | Tag4 | Tag5 | Tag6 | Tag9 | Tag10 | Tag11 |
|----------|------|------|------|------|------|------|------|------|-------|-------|
| Products | 0    | 1    | 2    | 3    | 4    | 5    | 6    | 9    | 10    | 11    |
| Clients  | 22   | 12   | 13   | 14   | —    | —    | —    | —    | —     | —     |
| Services | 18   | 15   | 16   | 17   | —    | —    | —    | —    | —     | —     |

These integers are the `ProductTypeId` / `ClientTypeId` / `ServiceTypeId` enum
values. Servers may define additional `tipas` values that have no enum case — for
example products often expose `tipas = 100` ("Apmokestinamieji gaminiai"). Pass
those as a raw int. A tag group the server has not configured simply yields an
empty collection; that is normal and not an error.

**Returned columns** (mapped onto the `TypeTag` DTO):

| Column        | DTO property | Notes                        |
|---------------|--------------|------------------------------|
| `tipas`       | `->tipas`    | int discriminator (see above)|
| `kodas`       | `->code`     | the code you reference        |
| `pavadinimas` | `->name`     | display name                  |
| `info1`       | `->info1`    | products only                 |
| `info2`       | `->info2`    | products only                 |

```php
use Finvalda\Enums\ProductTypeId;

// A single type/tag group, filtered by tipas → TypeTagCollection of TypeTag
$types = $finvalda->products()->typesAndTags(ProductTypeId::Type);
foreach ($types as $t) {
    echo "{$t->code}: {$t->name}\n";   // ->tipas, ->code, ->name, ->info1, ->info2
}

// Raw int works for server-defined tipas without an enum case
$taxable = $finvalda->products()->typesAndTags(100);

// The WHOLE dictionary in ONE HTTP call (cached on the resource instance)
$all = $finvalda->products()->allTypesAndTags();          // TypeTagCollection (every row)
$byTipas = $all->groupByType();                           // array<int, TypeTagCollection>
$tag1Values = $byTipas[1] ?? new \Finvalda\Collections\TypeTagCollection();
$present = $all->types();                                 // distinct tipas values present
```

`typesAndTags()` and `allTypesAndTags()` share a single cached request, so calling
both (or several filtered reads) on the same resource instance does **not** fan out
into multiple round-trips.

**Creating, updating and deleting types and tags.** The dictionary read above is
read-only; manage entries via `References` (create/update/delete for product and
client types and tags — see the [Reference Data](#reference-data) section):

```php
// Create a product type (Fvs.PrekesRusis) and a Tag-N value (Fvs.PrekesPoz{N}, N = 1..20)
$finvalda->references()->createProductType(['sKodas' => 'ELECTRONICS', 'sPavadinimas' => 'Electronics']);
$finvalda->references()->createProductTag(1, ['sKodas' => 'PROMO', 'sPavadinimas' => 'Promotional']);
$finvalda->references()->updateProductTag(1, ['sKodas' => 'PROMO', 'sPavadinimas' => 'Promo 2026']);
$finvalda->references()->deleteProductTag(1, 'PROMO');
// Clients: createClientType()/createClientTag(1..3) (Fvs.KlientoRusis / Fvs.Kliento{I|II|III}Poz),
// plus update*/delete* counterparts. Service types/tags are read-only (no API write class).
```

The `kodas` returned by `typesAndTags()` is exactly what you pass into
`Products::create()` as `sRusis` (type) and `sPozymis1..N` (tags):

```php
$finvalda->products()->create([
    'sKodas'      => 'NEWPROD',
    'sPavadinimas'=> 'New Product',
    'sRusis'      => 'ELECTRONICS',   // a Type kodas (tipas 0)
    'sPozymis1'   => 'PROMO',         // a Tag1 kodas (tipas 1)
]);
```

### Objects (6 Levels)

```php
// List objects at level 1-6
$response = $finvalda->objects()->list(level: 1);
$response = $finvalda->objects()->list(level: 2, objectCode: 'OBJ001');

// Get single object
$response = $finvalda->objects()->get(level: 1, objectCode: 'OBJ001');

// Create / update
$result = $finvalda->objects()->create(level: 1, data: [
    'sKodas' => 'DEPT01',
    'sPavadinimas' => 'Sales Department',
]);

$result = $finvalda->objects()->update(level: 1, data: [
    'sKodas' => 'DEPT01',
    'sPavadinimas' => 'Updated Department',
]);
```

### Transactions (Financial Detail Data)

```php
use Finvalda\Filters\TransactionFilter;
use Finvalda\Filters\PaymentFilter;
use Finvalda\Query\TransactionQuery;

// Using filter DTO
$filter = new TransactionFilter(
    dateFrom: '2024-01-01',
    dateTo: '2024-12-31',
    journalGroup: 'PARD_GRP',
);

// Or using fluent query builder
$filter = TransactionQuery::create()
    ->dateRange('2024-01-01', '2024-12-31')
    ->journalGroup('PARD_GRP')
    ->toFilter();

// Sales
$response = $finvalda->transactions()->sales($filter);
$response = $finvalda->transactions()->salesDetail($filter);
$response = $finvalda->transactions()->salesDetailWithPrimeCost($filter);

// Sale Reservations
$response = $finvalda->transactions()->saleReservations($filter);
$response = $finvalda->transactions()->saleReservationsDetail($filter);

// Sales Returns
$response = $finvalda->transactions()->salesReturns($filter);
$response = $finvalda->transactions()->salesReturnsDetail($filter);

// Purchases
$response = $finvalda->transactions()->purchases($filter);
$response = $finvalda->transactions()->purchasesDetail($filter);
$response = $finvalda->transactions()->purchasesExtendedDetail($filter);

// Purchase Orders & Returns
$response = $finvalda->transactions()->purchaseOrders($filter);
$response = $finvalda->transactions()->purchaseOrdersDetail($filter);
$response = $finvalda->transactions()->purchaseReturns($filter);
$response = $finvalda->transactions()->purchaseReturnsDetail($filter);

// Inflows with payment reference
$response = $finvalda->transactions()->inflowsDetail(
    filter: $filter,
    paymentFilter: new PaymentFilter(
        payedForDocSeries: 'AA',
        payedForDocOrderNumber: 'SF-001',
    ),
);

// Advance Payments
$response = $finvalda->transactions()->advancedPaymentsDetail(
    filter: $filter,
    client: 'CLIENT001',
    offsetStatus: 0,
);

// Disbursements & Clearing
$response = $finvalda->transactions()->disbursementsDetail($filter);
$response = $finvalda->transactions()->clearingOffsDetail($filter);

// OMM (Order Management Module)
$response = $finvalda->transactions()->ommSales($filter);
$response = $finvalda->transactions()->ommSalesDetail($filter);
$response = $finvalda->transactions()->ommPurchases($filter);
$response = $finvalda->transactions()->ommPurchasesDetail($filter);

// OMM sales filtered by a raw XML condition
$response = $finvalda->transactions()->ommSalesXmlCondition($xmlData);
$response = $finvalda->transactions()->ommSalesXmlConditionWithTitle($xmlData);

// Advance payments (extended)
$response = $finvalda->transactions()->advancedPaymentsDetailExtended(
    filter: $filter,
    client: 'CLIENT001',
    offsetStatus: 0,
);

// Fixed Assets & Currency
$response = $finvalda->transactions()->depreciationOfFixedAssets(year: 2024, month: 6);
$response = $finvalda->transactions()->depreciationOfFixedAssetsObjects(year: 2024, month: 6);
$response = $finvalda->transactions()->currencyDebtRecount($filter);

// Low Value Inventory
$response = $finvalda->transactions()->lowValueInventory();
```

### Operations (Create, Update, Delete)

Operations require a `$parameter` argument which is server-configured. See [Server-Configured Parameters](#server-configured-parameters).

```php
use Finvalda\Enums\OperationClass;
use Finvalda\Enums\DeleteOperationClass;
use Finvalda\Enums\UpdateOperationClass;
use Finvalda\Enums\OpClass;
use Finvalda\Query\OperationQuery;

$parameter = 'STANDARD'; // Server-configured

// Create operations (prefer fluent builders - see above)
$result = $finvalda->operations()->create(OperationClass::Sale, $data, $parameter);
$result = $finvalda->operations()->create(OperationClass::Purchase, $data, $parameter);
$result = $finvalda->operations()->create(OperationClass::InternalTransfer, $data, $parameter);

// Delete an operation
$result = $finvalda->operations()->delete(
    DeleteOperationClass::Sale,
    journal: 'PARD',
    number: 123,
    parameter: $parameter,
);

// Update an operation
$result = $finvalda->operations()->update(UpdateOperationClass::Sale, [
    'sZurnalas' => 'PARD',
    'nNumeris' => 123,
    'PardDokHeadEil' => ['sPastaba' => 'Updated comment'],
], $parameter);

// Read operations with query builder
$query = OperationQuery::sales()
    ->dateRange('2024-01-01', '2024-12-31')
    ->client('CLIENT001');

$response = $finvalda->operations()->query($query->opClass(), $query->build());

// Or with arrays (sent as opReadParams, filter keys must be nested under `filter`)
$response = $finvalda->operations()->get(OpClass::Sales, [
    'filter' => [
        'OpDateFrom' => '2024-01-01',
        'OpDateTill' => '2024-12-31',
    ],
]);

// Lock / unlock operations
$finvalda->operations()->lock('PARD', 123, parameter: 'STANDARD');
$finvalda->operations()->unlock('PARD', 123);
$finvalda->operations()->unlock('PARD', 123, newJournal: 'PARD2'); // move to new journal on unlock
$response = $finvalda->operations()->isLocked('PARD', 123);

// Change journal
$result = $finvalda->operations()->changeJournal([
    'sJournal' => 'PARD',
    'nOpNumber' => 123,
    'sJournalNew' => 'PARD2',
]);

// Copy operation
$result = $finvalda->operations()->copy([
    'sParameter' => 'STANDARD',
    'sJournal' => 'PARD',
    'nOpNumber' => 123,
    'sJournalNew' => 'PARD2',
    'bDeleteSourceOp' => false,
    'bKeepDocument' => false,
]);

// Activity by analytical objects (GetVeiklaPagalObjektus)
$response = $finvalda->operations()->activityByObjects([
    'tDataNuo' => '2024-01-01',
    'tDataIki' => '2024-12-31',
    // ...object/journal filters
]);
```

### Order Management (UVM)

```php
$response = $finvalda->orderManagement()->salesReservationStatus('PARD', 123);

$response = $finvalda->orderManagement()->completedReservations(
    journalGroup: 'PARD_GRP',
    dateFrom: '2024-01-01',
    dateTo: '2024-12-31',
);
$response = $finvalda->orderManagement()->pendingReservations();
$response = $finvalda->orderManagement()->cancelledReservations();
$response = $finvalda->orderManagement()->orderedProducts(dateFrom: '2024-01-01');
```

### Pricing & Discounts

```php
// Combined client + item prices
$response = $finvalda->pricing()->clientItemPrices(clientCode: 'CLI001', itemCode: 'PROD001');
$response = $finvalda->pricing()->clientTypeItemPrices(clientTypeCode: 'VIP', itemCode: 'PROD001');
$response = $finvalda->pricing()->clientItemTypePrices(clientCode: 'CLI001', itemTypeCode: 'ELECTRONICS');

// Product discounts and additional prices
$response = $finvalda->pricing()->clientProductDiscounts('CLI001');
$response = $finvalda->pricing()->clientProductAdditionalPrices('CLI001');
$response = $finvalda->pricing()->clientProductTypeDiscounts('CLI001');

// Service pricing
$response = $finvalda->pricing()->clientServiceDiscounts('CLI001');
$response = $finvalda->pricing()->clientServiceAdditionalPrices('CLI001');

// Client type pricing
$response = $finvalda->pricing()->clientTypeProductDiscounts('VIP');
$response = $finvalda->pricing()->clientTypeServiceDiscounts('VIP');

// The full pricing matrix follows a consistent naming scheme:
//   client[Type]  ×  Product|Service[Type]  ×  Discounts|AdditionalPrices
// All of the following are available (each takes the relevant code plus
// optional modifiedSince / createdSince date filters):
$finvalda->pricing()->clientItemTypePrices(clientCode: 'CLI001', itemTypeCode: 'ELECTRONICS');
$finvalda->pricing()->clientTypeItemPrices(clientTypeCode: 'VIP', itemCode: 'PROD001');
$finvalda->pricing()->clientTypeItemTypePrices(clientTypeCode: 'VIP', itemTypeCode: 'ELECTRONICS');
$finvalda->pricing()->clientProductTypeAdditionalPrices('CLI001');
$finvalda->pricing()->clientServiceTypeDiscounts('CLI001');
$finvalda->pricing()->clientServiceTypeAdditionalPrices('CLI001');
$finvalda->pricing()->clientTypeProductAdditionalPrices('VIP');
$finvalda->pricing()->clientTypeProductTypeDiscounts('VIP');
$finvalda->pricing()->clientTypeProductTypeAdditionalPrices('VIP');
$finvalda->pricing()->clientTypeServiceAdditionalPrices('VIP');
$finvalda->pricing()->clientTypeServiceTypeDiscounts('VIP');
$finvalda->pricing()->clientTypeServiceTypeAdditionalPrices('VIP');

// Recommended price calculation
$response = $finvalda->pricing()->recommendedPrice([
    'invoiceType' => 0,
    'invoiceDate' => ['year' => 2024, 'month' => 6, 'day' => 15],
    'itemType' => 1,
    'itemCode' => 'PROD001',
    'itemAmount' => 10,
    'warehouseCode' => 'WH01',
    'clientCode' => 'CLI001',
]);
```

### Documents

```php
use Finvalda\Enums\DocumentEntityType;

// Upload
$result = $finvalda->documents()->uploadFile('invoice.pdf', '/path/to/invoice.pdf');
$result = $finvalda->documents()->upload('doc.pdf', $hexContent);

// Attach to entity
$result = $finvalda->documents()->attach(
    DocumentEntityType::Sale,
    entityCode: 'CLI001',
    filename: 'invoice.pdf',
    journal: 'PARD',
    number: 123,
);

// Get attached documents
$response = $finvalda->documents()->attached(DocumentEntityType::Client, 'CLI001');

// Delete
$result = $finvalda->documents()->delete('invoice.pdf');
```

### Reports & Invoices

```php
// Recommended: pass params as an array and get decoded PDF bytes back
$pdf = $finvalda->reports()->makeInvoicePdf([
    'FakturosKodas' => 'PARD_01',
    'sSerija' => 'AAA',
    'sDokumentas' => '123',
    'sZurnalas' => '$PARD.',
    'nNumeris' => 45151,
]);

$pdf = $finvalda->reports()->makeReportPdf([
    'code' => 'PARDSAR_01',
    'DateFrom' => '2024-01-01',
    'DateTo' => '2024-01-31',
]);

// Low-level response methods remain available when you need the API envelope
$response = $finvalda->reports()->makeInvoice(['FakturosKodas' => 'PARD_01']);
$response = $finvalda->reports()->makeReport(['code' => 'PARDSAR_01']);
$response = $finvalda->reports()->autoReports();
$response = $finvalda->reports()->autoReport('report_filename.pdf');
$pdf = $finvalda->reports()->autoReportPdf('report_filename.pdf');
```

### Descriptions (Universal Query)

```php
use Finvalda\Enums\DescriptionType;

// The SDK nests filters under the correct key per description type. Pass only
// the inner filter contents; the wrapping (StockOnDate, Products, Series, ...)
// is handled for you.
$response = $finvalda->descriptions()->get(DescriptionType::Products, [
    'Codes' => ['PROD001', 'PROD002'],
], page: 1, limit: 50);

// Convenience methods
$response = $finvalda->descriptions()->stockOnDate('2024-06-15', ['Warehouse' => 'WH01']);
$response = $finvalda->descriptions()->products(['Type' => 'ELECTRONICS']);
$response = $finvalda->descriptions()->clients(['Email' => 'client@example.com']);
$response = $finvalda->descriptions()->services();
$response = $finvalda->descriptions()->currentStock(['Warehouse' => 'WH01']);
$response = $finvalda->descriptions()->fixedAssets();
$response = $finvalda->descriptions()->barCodes(['Codes' => ['PROD001']]);
$response = $finvalda->descriptions()->prices(['Client' => 'CLI001']);
$response = $finvalda->descriptions()->currencyRates('2024-01-01', '2024-12-31', ['USD', 'GBP']);

// Additional description types
$response = $finvalda->descriptions()->get(DescriptionType::OperationStatuses);
$response = $finvalda->descriptions()->get(DescriptionType::Accounts);
$response = $finvalda->descriptions()->get(DescriptionType::Vehicles);
$response = $finvalda->descriptions()->get(DescriptionType::ProductionItem, [
    'Codes' => ['PROD001'],
]);
$response = $finvalda->descriptions()->get(DescriptionType::PartnerProducts, [
    'Codes' => ['PROD001'],
    'Client' => 'CLI001',
]);

// Convenience helpers for grouping/reference description types
$response = $finvalda->descriptions()->typesAndTags('product', number: 1); // 'product'|'service'|'client'
$response = $finvalda->descriptions()->clientGroups();
$response = $finvalda->descriptions()->warehouseGroups();
$response = $finvalda->descriptions()->logbookGroups();      // journal (logbook) groups
$response = $finvalda->descriptions()->opTypeGroups();       // operation-type groups
$response = $finvalda->descriptions()->documentSeries(type: 1);
$response = $finvalda->descriptions()->calendarEvents('USERNAME', ['DateFrom' => '2024-01-01']);
$response = $finvalda->descriptions()->vehicles();
$response = $finvalda->descriptions()->invoiceList(opClass: 'PARD');
$response = $finvalda->descriptions()->reportList(class: 'PARDSAR');
```

### Reference Data

```php
$response = $finvalda->references()->measurementUnits();
$response = $finvalda->references()->warehouses();
$response = $finvalda->references()->taxes();
$response = $finvalda->references()->paymentTerms();
$response = $finvalda->references()->user();
$response = $finvalda->references()->materiallyResponsiblePersons();        // optional code filter

// Update existing reference entities
$result = $finvalda->references()->updateWarehouse(['sKodas' => 'WH03', 'sPavadinimas' => 'Renamed']);
$result = $finvalda->references()->updatePaymentTerm(['sKodas' => 'NET30', 'sPavadinimas' => 'Net 30 days']);

// Append an item to a group (AppendGroup)
$result = $finvalda->references()->addToGroup(
    itemClassName: 'Fvs.Preke',
    groupCode: 'GRP01',
    itemCode: 'PROD001',
);

// Create reference entities
$result = $finvalda->references()->createBank(['sKodas' => 'BNK01', 'sPavadinimas' => 'My Bank']);
$result = $finvalda->references()->createWarehouse(['sKodas' => 'WH03', 'sPavadinimas' => 'Warehouse 3']);
$result = $finvalda->references()->createPaymentTerm(['sKodas' => 'NET30', 'sPavadinimas' => 'Net 30']);
$result = $finvalda->references()->createClientType(['sKodas' => 'VIP', 'sPavadinimas' => 'VIP Clients']);
$result = $finvalda->references()->createProductType(['sKodas' => 'ELEC', 'sPavadinimas' => 'Electronics']);

// Create product tag values (tags 1-20) and client tag values (tags 1-3)
$result = $finvalda->references()->createProductTag(1, ['sKodas' => 'FEAT', 'sPavadinimas' => 'Featured']);
$result = $finvalda->references()->createProductTag(7, ['sKodas' => 'NEW', 'sPavadinimas' => 'New Arrival']);
$result = $finvalda->references()->createClientTag(1, ['sKodas' => 'KEY', 'sPavadinimas' => 'Key Account']);

// Update product/client types and tags (record identified by sKodas)
$result = $finvalda->references()->updateProductType(['sKodas' => 'ELEC', 'sPavadinimas' => 'Electronics & IT']);
$result = $finvalda->references()->updateProductTag(1, ['sKodas' => 'FEAT', 'sPavadinimas' => 'Featured ★']);
$result = $finvalda->references()->updateClientType(['sKodas' => 'VIP', 'sPavadinimas' => 'VIP+']);
$result = $finvalda->references()->updateClientTag(1, ['sKodas' => 'KEY', 'sPavadinimas' => 'Key Account']);

// Delete product/client types and tags by code.
// NOTE: deleting requires a FvsServicePure build that exposes the DeleteItem
// endpoint. Older builds answer 404; in that case these methods throw
// Finvalda\Exceptions\OperationNotSupportedException (a FinvaldaException) naming
// the endpoint, rather than a raw transport error. Create (InsertNewItem) and
// update (EditItem) are broadly available across builds.
try {
    $result = $finvalda->references()->deleteProductType('ELEC');
    $result = $finvalda->references()->deleteProductTag(1, 'FEAT');
    $result = $finvalda->references()->deleteClientType('VIP');
    $result = $finvalda->references()->deleteClientTag(1, 'KEY');
} catch (\Finvalda\Exceptions\OperationNotSupportedException $e) {
    // $e->endpoint === 'DeleteItem' — this server build can't delete dictionary entries
}

// NOTE: service types/tags are read-only via the API — there is no Fvs.PaslaugosRusis
// write class, so no create/update/delete counterpart exists for services.
```

### User Permissions

```php
$response = $finvalda->permissions()->warehouses();
$response = $finvalda->permissions()->clients();
$response = $finvalda->permissions()->operationTypes();
$response = $finvalda->permissions()->operationJournals();
```

## Pagination

For large datasets, use lazy pagination with the `Cursor` class:

```php
use Finvalda\Pagination\Cursor;
use Finvalda\Pagination\LazyCollection;

// Create a cursor for clients
$cursor = new Cursor(
    fetcher: fn($modifiedSince, $createdSince) =>
        $finvalda->clients()->all($modifiedSince, $createdSince)->data,
    dateExtractor: fn($item) => isset($item['tKoregavimoData'])
        ? new \DateTime($item['tKoregavimoData'])
        : null,
    // Recommended: a stable identity per record so duplicates from
    // overlapping date ranges are skipped reliably. Without it, items
    // are compared by full content.
    idExtractor: fn($item) => $item['sKodas'],
);

// Iterate lazily (memory efficient)
foreach ($cursor->modifiedSince('2024-01-01')->getIterator() as $clientData) {
    echo $clientData['sPavadinimas'] . "\n";
}

// Take first N items
$first100 = $cursor->take(100);

// Get all as array
$allClients = $cursor->all();

// LazyCollection for generator-based iteration
$lazy = LazyCollection::make($finvalda->clients()->all()->data);

$filtered = $lazy
    ->filter(fn($c) => ($c['dSkola'] ?? 0) > 0)
    ->map(fn($c) => $c['sPavadinimas'])
    ->take(10)
    ->all();
```

## Error Handling

```php
use Finvalda\Exceptions\FinvaldaException;
use Finvalda\Exceptions\AccessDeniedException;
use Finvalda\Exceptions\ValidationException;
use Finvalda\Exceptions\NotFoundException;
use Finvalda\Exceptions\NetworkException;
use Finvalda\Exceptions\ServerException;
use Finvalda\Exceptions\RetryExhaustedException;

try {
    $client = $finvalda->clients()->find('CLI001');
} catch (NotFoundException $e) {
    echo "Client not found";
} catch (AccessDeniedException $e) {
    echo "Access denied: {$e->getMessage()}";
} catch (NetworkException $e) {
    echo "Network error (connection failed, timeout): {$e->getMessage()}";
} catch (ServerException $e) {
    echo "Server error (5xx): {$e->getMessage()}";
} catch (RetryExhaustedException $e) {
    echo "All {$e->attempts} retry attempts failed: {$e->getMessage()}";
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    $allMessages = $e->getAllErrors();
} catch (FinvaldaException $e) {
    echo "API error: {$e->getMessage()}";
}

// Check response status
$response = $finvalda->clients()->list();

if ($response->failed()) {
    echo "Error: {$response->error}";
}

// Check operation result
$result = $finvalda->clients()->create($data);

if ($result->success) {
    echo "Created: {$result->journal} #{$result->number}";
} else {
    echo "Error #{$result->errorCode}: {$result->error}";
}
```

## Server-Configured Parameters

Finvalda uses server-configured parameters that depend on your installation.

### sParametras (Operations)

Required for operation methods (`create`, `update`, `delete`). Tells the server which journal configuration to use.

```php
$parameter = 'STANDARD'; // Your server-configured value

$result = $finvalda->operations()->create(OperationClass::Sale, $data, $parameter);
$result = $finvalda->sale()->client('CLI001')->addProduct('PRD001', 10, 19.99)->save($parameter);
```

> **Deeper reference — [`docs/parameters/`](docs/parameters/):** explains what a
> `sParametras` profile actually contains (journal, operation type, series, document type,
> accounts, VAT, division, employee, Intrastat data, flags), how it is configured in the
> `FvsNETParamKonfig` tool, and a YAML format
> ([`parameters.example.yaml`](docs/parameters/parameters.example.yaml)) for cataloguing your
> own profiles. With that catalog filled in, an AI assistant can match a transaction to the
> right profile, explain a profile, or draft `FvsNETParamKonfig` setup instructions for a new
> one. Keep deployment-specific values out of version control (the catalog file is
> git-ignored).

### sFvsImportoParametras (Items)

Optional data field for item methods. Include in data array if required:

```php
$result = $finvalda->clients()->create([
    'sKodas' => 'NEW001',
    'sPavadinimas' => 'New Client Ltd',
    'sFvsImportoParametras' => 'STANDARD', // Server-configured
]);
```

### Troubleshooting Parameter Errors

If you receive an error like:

```json
{
  "nResult": 1036,
  "sError": "Parameter 'NET_DELSPINIGIAI_SUPVM' not found in database!"
}
```

This means the parameter is not configured on your server. Contact your Finvalda administrator for valid parameter values.

## API Versions

This SDK targets **V2 (FvsServicePure)** - the recommended REST interface.

| Version | URL Pattern | Description |
|---------|-------------|-------------|
| V2 (recommended) | `.../FvsServicePure.svc` | Clean REST JSON/XML |
| V1 | `.../FvsServiceR.svc/rest` | REST with string-wrapped responses |
| V0 | `.../FvsService.asmx` | SOAP + REST XML |

## Keeping Up to Date

The SDK is built from the [official Postman collection](https://documenter.getpostman.com/view/7208231/2s8YmRMLvd). To check for new endpoints:

```bash
bin/sync-postman-collection
```

> **Note on parameter names.** The legacy method signatures in `docs/FVS_Webservice.txt`
> describe the older V0 (SOAP) interface and do **not** always match the V2
> `FvsServicePure` endpoint this SDK targets. For example, `GetPrekesSandelyje`
> is documented with `sSanKod` but the V2 endpoint actually honors `sSandKod`
> (verified against a live server). When a query filter appears to be silently
> ignored, confirm the exact parameter name against a live server rather than
> trusting the `.txt` signature.

## License

MIT
