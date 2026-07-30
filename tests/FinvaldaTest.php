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
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

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

    public function test_with_company_returns_a_client_bound_to_another_company(): void
    {
        $finvalda = new Finvalda(new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
            companyId: 'htrailer',
        ));

        $other = $finvalda->withCompany('HTNT');

        $this->assertSame('HTNT', $other->getHttpClient()->getConfig()->companyId);
        $this->assertNotSame($finvalda, $other);
        $this->assertNotSame($finvalda->getHttpClient(), $other->getHttpClient());
        $this->assertSame('htrailer', $finvalda->getHttpClient()->getConfig()->companyId);
    }

    public function test_without_company_returns_a_client_bound_to_the_default_company(): void
    {
        $finvalda = new Finvalda(new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
            companyId: 'htrailer',
        ));

        $this->assertNull($finvalda->withoutCompany()->getHttpClient()->getConfig()->companyId);
    }

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

    public function test_recording_switched_on_after_a_company_client_exists_still_captures_its_calls(): void
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

        $child = $finvalda->withoutCompany();
        $finvalda->record();
        $child->products()->all();

        $this->assertCount(1, $finvalda->recordings());
    }

    public function test_debug_switched_on_after_a_company_client_exists_still_captures_its_calls(): void
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

        $child = $finvalda->withoutCompany();
        $finvalda->setDebug(true);
        $child->products()->all();

        $debug = $finvalda->getLastDebugInfo();
        $this->assertSame(200, $debug['response']['status_code']);
    }

    public function test_disabling_debug_on_the_parent_stops_the_company_client_capturing(): void
    {
        $history = [];
        $finvalda = $this->createFinvaldaWithHistory(
            new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'demo',
                password: 'secret',
                companyId: 'htrailer',
            ),
            [
                new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success'])),
                new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success'])),
            ],
            $history,
        );

        $finvalda->setDebug(true);
        $finvalda->withoutCompany()->products()->all();
        $finvalda->setDebug(false);
        $finvalda->withoutCompany()->products()->all();

        $this->assertSame(['request' => [], 'response' => []], $finvalda->getLastDebugInfo());
    }

    public function test_a_logger_set_after_a_company_client_exists_receives_the_company_calls(): void
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

        $child = $finvalda->withoutCompany();

        $spy = new class extends AbstractLogger {
            /** @var array<int, array{0: mixed, 1: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };

        $finvalda->setLogger($spy);
        $child->products()->all();

        $messages = array_column($spy->records, 1);
        $this->assertContains('Finvalda API request', $messages);
        $this->assertContains('Finvalda API response', $messages);
    }

    public function test_a_parent_and_a_company_client_share_one_request_history(): void
    {
        $history = [];
        $finvalda = $this->createFinvaldaWithHistory(
            new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'demo',
                password: 'secret',
                companyId: 'htrailer',
            ),
            [
                new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success'])),
                new GuzzleResponse(200, [], json_encode(['AccessResult' => 'Success'])),
            ],
            $history,
        );

        $finvalda->products()->all();
        $finvalda->withoutCompany()->products()->all();

        $this->assertCount(2, $history);
        $this->assertSame('htrailer', $history[0]['request']->getHeaderLine('CompanyID'));
        $this->assertFalse($history[1]['request']->hasHeader('CompanyID'));
    }
}
