<?php

namespace Finvalda\Tests;

use Finvalda\Enums\CredentialMode;
use Finvalda\Enums\Language;
use Finvalda\FinvaldaConfig;
use Finvalda\Retry\RetryPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

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

    public function test_recording_is_off_by_default(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'demo',
            password: 'secret',
        );

        $this->assertFalse($config->record);
        $this->assertSame(20, $config->recordLimit);
        $this->assertSame(CredentialMode::Masked, $config->recordCredentials);
    }

    public function test_from_array_maps_recording_keys(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'demo',
            'password' => 'secret',
            'record' => true,
            'record_limit' => 5,
            'record_credentials' => 'env',
        ]);

        $this->assertTrue($config->record);
        $this->assertSame(5, $config->recordLimit);
        $this->assertSame(CredentialMode::Env, $config->recordCredentials);
    }

    public function test_from_array_falls_back_to_masked_for_an_unknown_credential_mode(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com',
            'username' => 'demo',
            'password' => 'secret',
            'record_credentials' => 'nonsense',
        ]);

        $this->assertSame(CredentialMode::Masked, $config->recordCredentials);
    }

    public function test_with_company_id_carries_every_other_field_over_unchanged(): void
    {
        $config = $this->fullyPopulatedConfig();

        $copy = $config->withCompanyId('HTNT');

        $this->assertSame('HTNT', $copy->companyId);
        $this->assertNotSame($config, $copy);

        foreach ((new ReflectionClass(FinvaldaConfig::class))->getProperties() as $property) {
            if ($property->getName() === 'companyId') {
                continue;
            }

            $this->assertSame(
                $property->getValue($config),
                $property->getValue($copy),
                "withCompanyId() did not carry over {$property->getName()}",
            );
        }
    }

    public function test_with_company_id_accepts_null_to_target_the_default_company(): void
    {
        $config = $this->fullyPopulatedConfig();

        $this->assertNull($config->withCompanyId(null)->companyId);
    }

    /**
     * Every field set away from its default, so a field withCompanyId() forgets
     * to forward shows up as a difference rather than matching by coincidence.
     */
    private function fullyPopulatedConfig(): FinvaldaConfig
    {
        return new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
            connString: 'Server=db',
            companyId: 'htrailer',
            language: Language::English,
            removeEmptyStringTags: true,
            removeZeroNumberTags: true,
            removeNewLines: true,
            timeout: 60,
            logger: new NullLogger(),
            retry: new RetryPolicy(maxAttempts: 5),
            normalizeFloats: false,
            floatPrecision: 2,
            record: true,
            recordLimit: 5,
            recordCredentials: CredentialMode::Real,
        );
    }
}
