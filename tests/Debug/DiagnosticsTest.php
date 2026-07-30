<?php

declare(strict_types=1);

namespace Finvalda\Tests\Debug;

use Finvalda\Debug\Diagnostics;
use Finvalda\Enums\CredentialMode;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

class DiagnosticsTest extends TestCase
{
    public function test_it_starts_with_no_logger_debug_off_and_no_recorder(): void
    {
        $diagnostics = new Diagnostics();

        $this->assertNull($diagnostics->logger());
        $this->assertFalse($diagnostics->debugEnabled());
        $this->assertNull($diagnostics->recorder());
        $this->assertSame(['request' => [], 'response' => []], $diagnostics->lastExchange()->toArray());
    }

    public function test_set_logger_and_logger_round_trip(): void
    {
        $diagnostics = new Diagnostics();
        $logger = new NullLogger();

        $diagnostics->setLogger($logger);

        $this->assertSame($logger, $diagnostics->logger());
    }

    public function test_constructor_accepts_an_initial_logger(): void
    {
        $logger = new NullLogger();

        $diagnostics = new Diagnostics($logger);

        $this->assertSame($logger, $diagnostics->logger());
    }

    public function test_disabling_debug_clears_the_composed_last_exchange(): void
    {
        $diagnostics = new Diagnostics();

        $diagnostics->setDebug(true);
        $diagnostics->lastExchange()->setRequest(['method' => 'GET']);
        $diagnostics->lastExchange()->setResponse(['status_code' => 200]);

        $diagnostics->setDebug(false);

        $this->assertFalse($diagnostics->debugEnabled());
        $this->assertSame(['request' => [], 'response' => []], $diagnostics->lastExchange()->toArray());
    }

    public function test_start_recording_then_stop_recording_leaves_no_recorder(): void
    {
        $diagnostics = new Diagnostics();

        $diagnostics->startRecording(20, CredentialMode::Masked);
        $this->assertNotNull($diagnostics->recorder());

        $diagnostics->stopRecording();

        $this->assertNull($diagnostics->recorder());
    }

    public function test_two_holders_of_the_same_instance_see_each_others_mutations(): void
    {
        $diagnostics = new Diagnostics();
        $alias = $diagnostics;

        $diagnostics->setDebug(true);
        $diagnostics->startRecording(5, CredentialMode::Real);
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };
        $diagnostics->setLogger($logger);

        $this->assertTrue($alias->debugEnabled());
        $this->assertNotNull($alias->recorder());
        $this->assertSame($logger, $alias->logger());
    }
}
