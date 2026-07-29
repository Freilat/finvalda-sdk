<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Finvalda;
use Finvalda\FinvaldaConfig;
use Finvalda\HttpClient;
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
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;

class FinvaldaTest extends TestCase
{
    private Finvalda $finvalda;

    protected function setUp(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        );

        $this->finvalda = new Finvalda($config);
    }

    public function test_stock_returns_stock_resource(): void
    {
        $this->assertInstanceOf(Stock::class, $this->finvalda->stock());
    }

    public function test_clients_returns_clients_resource(): void
    {
        $this->assertInstanceOf(Clients::class, $this->finvalda->clients());
    }

    public function test_products_returns_products_resource(): void
    {
        $this->assertInstanceOf(Products::class, $this->finvalda->products());
    }

    public function test_services_returns_services_resource(): void
    {
        $this->assertInstanceOf(Services::class, $this->finvalda->services());
    }

    public function test_objects_returns_objects_resource(): void
    {
        $this->assertInstanceOf(Objects::class, $this->finvalda->objects());
    }

    public function test_references_returns_references_resource(): void
    {
        $this->assertInstanceOf(References::class, $this->finvalda->references());
    }

    public function test_pricing_returns_pricing_resource(): void
    {
        $this->assertInstanceOf(Pricing::class, $this->finvalda->pricing());
    }

    public function test_operations_returns_operations_resource(): void
    {
        $this->assertInstanceOf(Operations::class, $this->finvalda->operations());
    }

    public function test_order_management_returns_order_management_resource(): void
    {
        $this->assertInstanceOf(OrderManagement::class, $this->finvalda->orderManagement());
    }

    public function test_documents_returns_documents_resource(): void
    {
        $this->assertInstanceOf(Documents::class, $this->finvalda->documents());
    }

    public function test_reports_returns_reports_resource(): void
    {
        $this->assertInstanceOf(Reports::class, $this->finvalda->reports());
    }

    public function test_descriptions_returns_descriptions_resource(): void
    {
        $this->assertInstanceOf(Descriptions::class, $this->finvalda->descriptions());
    }

    public function test_permissions_returns_permissions_resource(): void
    {
        $this->assertInstanceOf(Permissions::class, $this->finvalda->permissions());
    }

    public function test_transactions_returns_transactions_resource(): void
    {
        $this->assertInstanceOf(Transactions::class, $this->finvalda->transactions());
    }

    public function test_resources_are_lazily_loaded_and_cached(): void
    {
        $stock1 = $this->finvalda->stock();
        $stock2 = $this->finvalda->stock();

        $this->assertSame($stock1, $stock2);
    }

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
}
