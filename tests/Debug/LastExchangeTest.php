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
