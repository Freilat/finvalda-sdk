# Finvalda PHP SDK

## Project Overview
PHP SDK/Composer package for the Finvalda (FVS) Lithuanian accounting/ERP software web service API. Targets V2 (FvsServicePure) REST endpoints returning JSON.

## Architecture
- **Entry point**: `Finvalda\Finvalda` — creates an SDK instance with config, provides lazy-loaded resource accessors
- **Resources**: 15 resource classes in `src/Resources/`, each covering a domain (clients, products, operations, etc.)
- **HTTP layer**: `HttpClient` wraps Guzzle, handles auth headers, JSON parsing, error mapping
- **Enums**: Type-safe constants for operation classes, languages, access results, description types
- **Filters**: `TransactionFilter` and `PaymentFilter` DTOs for query construction
- **Laravel**: Service provider with auto-discovery, Facade, publishable config

## Key Patterns
- All read methods return `Finvalda\Responses\Response` with `->data`, `->successful()`, `->error`, `->raw`
- All write methods return `Finvalda\Responses\OperationResult` with `->success`, `->journal`, `->number`, `->error`
- Resources extend `Finvalda\Resources\Resource` base class
- Builders extend `Finvalda\Builders\OperationBuilder` base class
- HttpClient is injectable (constructor accepts `?ClientInterface`)
- Date parameters accept `DateTimeInterface|string|null` (format: Y-m-d)

## HTTP Transport Patterns
The Pure endpoint (FvsServicePure.svc) supports both query params and JSON body. The SDK uses JSON body for all POST write operations:

- **`postOperation()`** — JSON body with `{"ItemClassName":"...","xmlstring":"..."}`. Used for: InsertNewItem, EditItem, InsertNewOperation, UpdateOperation, DeleteOperation, EditItemProps, AppendGroup, InsertDocument, DeleteDocument, AttachDocument
- **`postOperationJson()`** — Flat JSON body or `{"input":{...}}` wrapper. Used for: LockOperation, UnLockOperation, ChangeJournal (`{sJournal, nOpNumber, sJournalNew}`), CopyOperation (`{input:{...}}`), DeleteItem (`{input:{ItemClassName, Code}}`)
- **`postJson()`** — Custom JSON body returning Response. Used for: GetDescriptions (`{readParams:{...}}`), GetOperations POST (`{opReadParams:{...}}`), IsOperationLocked, GetVeiklaPagalObjektus, GetRecommendedPrice
- **`post()`** — POST with query params returning Response. Used for: GetInvoicesRelatedToCustomer
- **`get()`** — GET with query params. Used for all read-only endpoints (130+)
- **`getRaw()`** — GET returning raw string. Used for binary responses

## Builders
All 23 `OperationClass` enum cases have corresponding builders accessible via `Finvalda`:
- **Sales**: `sale()`, `salesReservation()`, `salesReturn()` — each supports `->short()` for Trumpas* variants
- **Purchases**: `purchase()`, `purchaseOrder()`, `purchaseReturn()` — each supports `->short()`; `purchase()`/`purchaseOrder()` also carry `->additionalCostCodes()` (sPapIslaiduKodas1..4, full variants only)
- **Transfers & Adjustments**: `internalTransfer()`, `writeOff()`, `capitalization()`, `inventoryCount()`
- **Payments**: `inflow()`, `disbursement()`, `clearing()`
- **Production**: `production()` — three line types: finished goods, raw materials, services
- **Other**: `nonAnalytical()` — general ledger debit/credit entries
- **UVM**: `uvmSalesReservation()`, `uvmCancellation()`, `uvmPurchaseOrder()`
- **Corrections**: `purchaseUpdate()` — `PurchaseUpdateBuilder` posts `KoregPirkDok` via `Operations::update()`. Does NOT extend `OperationBuilder` (different envelope: `sZurnalas`/`nNumeris` wrapper + `PirkDokHeadEil` sub-node + `Del*DetEil` delete nodes). DESTRUCTIVE — deletes and re-adds lines, rebuilding the FIFO stock layer; fails with error 4027 once the stock is consumed. Guard with `assertNotSold()` or `Stock::purchaseOpFor()`.

Special build structures: ClearingBuilder (debit/credit lines), ProductionBuilder (3 line types), NonAnalyticalBuilder (accounting entries), UvmCancellationBuilder (cancellation refs), InventoryCountBuilder (flat items with mode wrapper).

### Line DTOs
- `ProductLine::make(code, qty)` — fluent DTO for product detail lines with `->warehouse()`, `->amount()`, `->vat()`, `->discount()`, `->object()`, `->objects()`, `->intrastat()`, `->weight()`, `->firstMeasurement()`, `->info()`, `->marked()`, `->additionalCost()`, `->additionalCosts()`, `->set()`
- `->additionalCost(slot, currency, local)` writes `dPapIsldSumaV{slot}`/`dPapIsldSumaL{slot}` — product lines only (spec: `Tik PirkDokPrekeDetEil`), slot binds to the header's `sPapIslaiduKodas{slot}`
- `ServiceLine::make(code, qty)` — fluent DTO for service detail lines (no warehouse/weight/intrastat)
- Used via `OperationBuilder::product(ProductLine)` and `OperationBuilder::service(ServiceLine)`
- Existing `addProduct()`/`addService()`/`addProductLine()`/`addServiceLine()` remain for backward compatibility
- `->set(key, value)` is the escape hatch for raw API field names not covered by named methods

## File Structure
```
src/
  Finvalda.php              # Main client — $finvalda->clients(), ->products(), etc.
  FinvaldaConfig.php        # Config DTO (baseUrl, username, password, language, etc.)
  HttpClient.php            # HTTP transport layer (Guzzle, injectable)
  Builders/                 # 18 fluent operation builders (OperationBuilder base + 17 concrete)
  Enums/                    # AccessResult, Language, ItemClass, OperationClass, OpClass, CredentialMode, etc.
  Exceptions/               # FinvaldaException, AccessDeniedException, ValidationException
  Filters/                  # TransactionFilter, PaymentFilter DTOs
  Recording/                # Exchange value object + Recorder ring buffer
  Resources/                # 15 resource classes (Stock, Clients, Products, etc.)
  Responses/                # Response, OperationResult
  Laravel/                  # ServiceProvider, Facade
config/
  finvalda.php              # Laravel config (publishable)
docs/                       # API documentation (.doc, .txt, Postman collection)
```

## Available Resources
| Accessor | Class | Purpose |
|---|---|---|
| `->stock()` | Stock | Inventory balances (current, extended, with prices, by group); `purchaseOpFor()` derives the current purchase op + sold flag from `GetPrekesIstorija` (returns a plain array, never throws) |
| `->clients()` | Clients | CRUD, accounts, settlements, debt, email |
| `->products()` | Products | CRUD, warehouse queries, history, images, types |
| `->services()` | Services | CRUD, types and tags |
| `->objects()` | Objects | 6 levels of analytical objects CRUD |
| `->transactions()` | Transactions | Read financial details (sales, purchases, inflows, etc.) |
| `->operations()` | Operations | Create/delete/update/query/lock accounting operations |
| `->pricing()` | Pricing | Discounts and prices by client/product/service/type combos |
| `->orderManagement()` | OrderManagement | UVM reservation status, ordered products |
| `->documents()` | Documents | Upload, attach, list, delete documents |
| `->reports()` | Reports | Invoice/report PDF generation |
| `->descriptions()` | Descriptions | Universal query (GetDescriptions) with 27+ types |
| `->references()` | References | Measurement units, warehouses, taxes, payment terms |
| `->permissions()` | Permissions | User permission queries |

## API Field Name Convention
The Finvalda API uses Lithuanian-prefixed field names. Common prefixes:
- `s` = string (sKodas = code, sPavadinimas = name)
- `n` = number (nKiekis = quantity, nNumeris = number)
- `d` = decimal (dSumaV = amount in currency, dKaina1 = price 1)
- `t` = datetime (tData = date, tKoregavimoData = modified date)
- `b` = boolean (bGaliojimas = validity)

## API Documentation Source
- Official Postman docs: https://documenter.getpostman.com/view/7208231/2s8YmRMLvd
- Collection can be fetched via: `https://documenter.getpostman.com/api/collections/7208231/2s8YmRMLvd`
- Local collection: `docs/FvsWebService.postman_collection.json`
- Local .doc converted to text: `docs/FVS_Webservice.txt`

## Running Checks
```bash
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

## Updating the Postman Collection
```bash
bin/sync-postman-collection
```
