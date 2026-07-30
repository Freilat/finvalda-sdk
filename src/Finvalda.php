<?php

declare(strict_types=1);

namespace Finvalda;

use Finvalda\Builders\CapitalizationBuilder;
use Finvalda\Builders\ClearingBuilder;
use Finvalda\Builders\DisbursementBuilder;
use Finvalda\Builders\InflowBuilder;
use Finvalda\Builders\InternalTransferBuilder;
use Finvalda\Builders\InventoryCountBuilder;
use Finvalda\Builders\NonAnalyticalBuilder;
use Finvalda\Builders\ProductionBuilder;
use Finvalda\Builders\PurchaseBuilder;
use Finvalda\Builders\PurchaseOrderBuilder;
use Finvalda\Builders\PurchaseReturnBuilder;
use Finvalda\Builders\PurchaseUpdateBuilder;
use Finvalda\Builders\SaleBuilder;
use Finvalda\Builders\SalesReservationBuilder;
use Finvalda\Builders\SalesReturnBuilder;
use Finvalda\Builders\UvmCancellationBuilder;
use Finvalda\Builders\UvmPurchaseOrderBuilder;
use Finvalda\Builders\UvmSalesReservationBuilder;
use Finvalda\Builders\WriteOffBuilder;
use Finvalda\Enums\CredentialMode;
use Finvalda\Recording\Exchange;
use Finvalda\Resources\Clients;
use Finvalda\Resources\Descriptions;
use Finvalda\Resources\Documents;
use Finvalda\Resources\Objects;
use Finvalda\Resources\Operations;
use Finvalda\Resources\OrderManagement;
use Finvalda\Resources\Permissions;
use Finvalda\Resources\Pricing;
use Finvalda\Resources\Products;
use Finvalda\Resources\References;
use Finvalda\Resources\Reports;
use Finvalda\Resources\Services;
use Finvalda\Resources\Stock;
use Finvalda\Resources\Transactions;
use Psr\Log\LoggerInterface;

/**
 * Finvalda API client - main entry point for all resource endpoints.
 */
final class Finvalda
{
    private HttpClient $http;

    private ?Stock $stock = null;
    private ?Clients $clients = null;
    private ?Products $products = null;
    private ?Services $services = null;
    private ?Objects $objects = null;
    private ?References $references = null;
    private ?Pricing $pricing = null;
    private ?Operations $operations = null;
    private ?OrderManagement $orderManagement = null;
    private ?Documents $documents = null;
    private ?Reports $reports = null;
    private ?Descriptions $descriptions = null;
    private ?Permissions $permissions = null;
    private ?Transactions $transactions = null;

    /** @var array<string, self> */
    private array $companyClients = [];

    /**
     * Create a new Finvalda API client instance.
     *
     * @param  FinvaldaConfig  $config  API connection configuration
     * @param  HttpClient|null  $httpClient  Optional pre-configured HTTP client (for testing or custom middleware)
     */
    public function __construct(
        private readonly FinvaldaConfig $config,
        ?HttpClient $httpClient = null,
    ) {
        $this->http = $httpClient ?? new HttpClient($this->config);
    }

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

    /**
     * A client bound to Finvalda's default company. See withCompany().
     */
    public function withoutCompany(): self
    {
        return $this->withCompany(null);
    }

    /**
     * Set the logger instance for request/response logging.
     *
     * @param  LoggerInterface|null  $logger  PSR-3 logger instance, or null to disable logging
     * @return $this
     */
    public function setLogger(?LoggerInterface $logger): self
    {
        $this->http->setLogger($logger);

        return $this;
    }

    /**
     * Enable or disable debug mode for request/response capture.
     *
     * @return $this
     */
    public function setDebug(bool $debug): self
    {
        $this->http->setDebug($debug);

        return $this;
    }

    /**
     * Get debug information from the last request/response cycle.
     *
     * @return array{request: array, response: array}
     */
    public function getLastDebugInfo(): array
    {
        return $this->http->getLastDebugInfo();
    }

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

    /**
     * Test the connection and credentials by calling a lightweight endpoint.
     */
    public function ping(): bool
    {
        try {
            return $this->references()->user()->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the underlying HTTP client for advanced usage.
     */
    public function getHttpClient(): HttpClient
    {
        return $this->http;
    }

    /**
     * Get the Stock resource for inventory balance operations.
     *
     * @return Stock
     */
    public function stock(): Stock
    {
        return $this->stock ??= new Stock($this->http);
    }

    /**
     * Get the Clients resource for customer/supplier operations.
     *
     * @return Clients
     */
    public function clients(): Clients
    {
        return $this->clients ??= new Clients($this->http);
    }

    /**
     * Get the Products resource for product catalog operations.
     *
     * @return Products
     */
    public function products(): Products
    {
        return $this->products ??= new Products($this->http);
    }

    /**
     * Get the Services resource for service catalog operations.
     *
     * @return Services
     */
    public function services(): Services
    {
        return $this->services ??= new Services($this->http);
    }

    /**
     * Get the Objects resource for analytical object operations.
     *
     * @return Objects
     */
    public function objects(): Objects
    {
        return $this->objects ??= new Objects($this->http);
    }

    /**
     * Get the References resource for reference data operations.
     *
     * @return References
     */
    public function references(): References
    {
        return $this->references ??= new References($this->http);
    }

    /**
     * Get the Pricing resource for discount and price operations.
     *
     * @return Pricing
     */
    public function pricing(): Pricing
    {
        return $this->pricing ??= new Pricing($this->http);
    }

    /**
     * Get the Operations resource for document operation CRUD.
     *
     * @return Operations
     */
    public function operations(): Operations
    {
        return $this->operations ??= new Operations($this->http);
    }

    /**
     * Get the OrderManagement resource for UVM order tracking.
     *
     * @return OrderManagement
     */
    public function orderManagement(): OrderManagement
    {
        return $this->orderManagement ??= new OrderManagement($this->http);
    }

    /**
     * Get the Documents resource for file upload and attachment operations.
     *
     * @return Documents
     */
    public function documents(): Documents
    {
        return $this->documents ??= new Documents($this->http);
    }

    /**
     * Get the Reports resource for PDF generation operations.
     *
     * @return Reports
     */
    public function reports(): Reports
    {
        return $this->reports ??= new Reports($this->http);
    }

    /**
     * Get the Descriptions resource for universal data queries.
     *
     * @return Descriptions
     */
    public function descriptions(): Descriptions
    {
        return $this->descriptions ??= new Descriptions($this->http);
    }

    /**
     * Get the Permissions resource for user access control queries.
     *
     * @return Permissions
     */
    public function permissions(): Permissions
    {
        return $this->permissions ??= new Permissions($this->http);
    }

    /**
     * Get the Transactions resource for accounting transaction operations.
     *
     * @return Transactions
     */
    public function transactions(): Transactions
    {
        return $this->transactions ??= new Transactions($this->http);
    }

    // --- Fluent Operation Builders: Sales ---

    /**
     * Create a new sale operation builder.
     */
    public function sale(): SaleBuilder
    {
        return (new SaleBuilder())->using($this);
    }

    /**
     * Create a new sales reservation operation builder.
     */
    public function salesReservation(): SalesReservationBuilder
    {
        return (new SalesReservationBuilder())->using($this);
    }

    /**
     * Create a new sales return operation builder.
     */
    public function salesReturn(): SalesReturnBuilder
    {
        return (new SalesReturnBuilder())->using($this);
    }

    // --- Fluent Operation Builders: Purchases ---

    /**
     * Create a new purchase operation builder.
     */
    public function purchase(): PurchaseBuilder
    {
        return (new PurchaseBuilder())->using($this);
    }

    /**
     * Create a new purchase order operation builder.
     */
    public function purchaseOrder(): PurchaseOrderBuilder
    {
        return (new PurchaseOrderBuilder())->using($this);
    }

    /**
     * Create a new purchase return operation builder.
     */
    public function purchaseReturn(): PurchaseReturnBuilder
    {
        return (new PurchaseReturnBuilder())->using($this);
    }

    /**
     * Create a new purchase correction builder (KoregPirkDok via UpdateOperation).
     *
     * DESTRUCTIVE: a correction deletes and re-adds detail lines, rebuilding the
     * product's stock layer. See PurchaseUpdateBuilder for the 4027 footgun and
     * PurchaseUpdateBuilder::assertNotSold() for the pre-flight guard.
     */
    public function purchaseUpdate(): PurchaseUpdateBuilder
    {
        return (new PurchaseUpdateBuilder())->using($this);
    }

    // --- Fluent Operation Builders: Transfers & Adjustments ---

    /**
     * Create a new internal transfer operation builder.
     */
    public function internalTransfer(): InternalTransferBuilder
    {
        return (new InternalTransferBuilder())->using($this);
    }

    /**
     * Create a new write-off/disposal operation builder.
     */
    public function writeOff(): WriteOffBuilder
    {
        return (new WriteOffBuilder())->using($this);
    }

    /**
     * Create a new capitalization/receiving operation builder.
     */
    public function capitalization(): CapitalizationBuilder
    {
        return (new CapitalizationBuilder())->using($this);
    }

    /**
     * Create a new inventory count operation builder.
     */
    public function inventoryCount(): InventoryCountBuilder
    {
        return (new InventoryCountBuilder())->using($this);
    }

    // --- Fluent Operation Builders: Payments ---

    /**
     * Create a new inflow (payment received) operation builder.
     */
    public function inflow(): InflowBuilder
    {
        return (new InflowBuilder())->using($this);
    }

    /**
     * Create a new disbursement (payment out) operation builder.
     */
    public function disbursement(): DisbursementBuilder
    {
        return (new DisbursementBuilder())->using($this);
    }

    /**
     * Create a new clearing/set-off operation builder.
     */
    public function clearing(): ClearingBuilder
    {
        return (new ClearingBuilder())->using($this);
    }

    // --- Fluent Operation Builders: Production ---

    /**
     * Create a new production operation builder.
     */
    public function production(): ProductionBuilder
    {
        return (new ProductionBuilder())->using($this);
    }

    // --- Fluent Operation Builders: Other ---

    /**
     * Create a new non-analytical accounting operation builder.
     */
    public function nonAnalytical(): NonAnalyticalBuilder
    {
        return (new NonAnalyticalBuilder())->using($this);
    }

    // --- Fluent Operation Builders: UVM (Order Management) ---

    /**
     * Create a new UVM sales reservation (workshop/service order) builder.
     */
    public function uvmSalesReservation(): UvmSalesReservationBuilder
    {
        return (new UvmSalesReservationBuilder())->using($this);
    }

    /**
     * Create a new UVM cancellation operation builder.
     */
    public function uvmCancellation(): UvmCancellationBuilder
    {
        return (new UvmCancellationBuilder())->using($this);
    }

    /**
     * Create a new UVM purchase order operation builder.
     */
    public function uvmPurchaseOrder(): UvmPurchaseOrderBuilder
    {
        return (new UvmPurchaseOrderBuilder())->using($this);
    }
}
