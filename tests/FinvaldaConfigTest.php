<?php

namespace Finvalda\Tests;

use Finvalda\Enums\Language;
use Finvalda\FinvaldaConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FinvaldaConfigTest extends TestCase
{
    public function test_it_can_be_instantiated(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        );

        $this->assertSame('https://example.com', $config->baseUrl);
        $this->assertSame('user', $config->username);
        $this->assertSame('pass', $config->password);
    }

    public function test_from_array_maps_minimal_config(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'user',
            'password' => 'pass',
        ]);

        $this->assertSame('https://example.com', $config->baseUrl);
        $this->assertSame(Language::Lithuanian, $config->language);
        $this->assertSame(30, $config->timeout);
        $this->assertNull($config->retry);
        $this->assertNull($config->logger);
    }

    public function test_from_array_maps_full_config_including_retry(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'user',
            'password' => 'pass',
            'conn_string' => 'Server=db',
            'company_id' => 'company1',
            'language' => 1,
            'remove_empty_string_tags' => true,
            'remove_zero_number_tags' => true,
            'remove_new_lines' => true,
            'timeout' => 60,
            'retry' => [
                'enabled' => true,
                'max_attempts' => 5,
                'delay_ms' => 200,
                'multiplier' => 1.5,
                'max_delay_ms' => 5000,
            ],
        ]);

        $this->assertSame('Server=db', $config->connString);
        $this->assertSame('company1', $config->companyId);
        $this->assertSame(Language::English, $config->language);
        $this->assertTrue($config->removeEmptyStringTags);
        $this->assertTrue($config->removeZeroNumberTags);
        $this->assertTrue($config->removeNewLines);
        $this->assertSame(60, $config->timeout);

        $this->assertNotNull($config->retry);
        $this->assertSame(5, $config->retry->maxAttempts);
        $this->assertSame(200, $config->retry->delayMs);
        $this->assertSame(1.5, $config->retry->multiplier);
        $this->assertSame(5000, $config->retry->maxDelayMs);
    }

    public function test_from_array_retry_disabled_yields_no_policy(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'user',
            'password' => 'pass',
            'retry' => ['enabled' => false, 'max_attempts' => 5],
        ]);

        $this->assertNull($config->retry);
    }

    public function test_from_array_accepts_logger(): void
    {
        $logger = new NullLogger();

        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'user',
            'password' => 'pass',
        ], $logger);

        $this->assertSame($logger, $config->logger);
    }

    public function test_float_normalization_defaults_are_enabled_with_ten_decimal_places(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        );

        $this->assertTrue($config->normalizeFloats);
        $this->assertSame(10, $config->floatPrecision);
    }

    public function test_from_array_maps_float_normalization_config(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'user',
            'password' => 'pass',
            'normalize_floats' => false,
            'float_precision' => 2,
        ]);

        $this->assertFalse($config->normalizeFloats);
        $this->assertSame(2, $config->floatPrecision);
    }

    public function test_it_rejects_negative_float_precision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Finvalda float precision must be between 0 and 14 decimal places');

        new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
            floatPrecision: -1,
        );
    }

    public function test_it_rejects_excessive_float_precision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Finvalda float precision must be between 0 and 14 decimal places');

        new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
            floatPrecision: 15,
        );
    }
}
