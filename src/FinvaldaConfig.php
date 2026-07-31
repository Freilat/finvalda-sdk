<?php

declare(strict_types=1);

namespace Finvalda;

use Finvalda\Enums\CredentialMode;
use Finvalda\Enums\Language;
use Finvalda\Logging\JsonLinesLogger;
use Finvalda\Retry\RetryPolicy;
use Finvalda\Support\BodyTruncator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class FinvaldaConfig
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $username,
        public readonly string $password,
        public readonly ?string $connString = null,
        public readonly ?string $companyId = null,
        public readonly Language $language = Language::Lithuanian,
        public readonly bool $removeEmptyStringTags = false,
        public readonly bool $removeZeroNumberTags = false,
        public readonly bool $removeNewLines = false,
        public readonly int $timeout = 30,
        public readonly ?LoggerInterface $logger = null,
        public readonly ?RetryPolicy $retry = null,
        public readonly bool $normalizeFloats = true,
        public readonly int $floatPrecision = 10,
        public readonly bool $record = false,
        public readonly int $recordLimit = 20,
        public readonly CredentialMode $recordCredentials = CredentialMode::Masked,
        public readonly bool $logFileContents = false,
        public readonly int $logBodyBytes = BodyTruncator::MAX_BYTES,
    ) {
        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('Finvalda base URL is required');
        }

        if ($this->username === '') {
            throw new InvalidArgumentException('Finvalda username is required');
        }

        if ($this->password === '') {
            throw new InvalidArgumentException('Finvalda password is required');
        }

        if ($this->floatPrecision < 0 || $this->floatPrecision > 14) {
            throw new InvalidArgumentException('Finvalda float precision must be between 0 and 14 decimal places');
        }
    }

    /**
     * A copy of this config bound to another company, or — with null — to
     * Finvalda's default company, which omits the CompanyID header.
     *
     * Report templates are registered per company, so a template that exists
     * only on the default company cannot be rendered through a company-scoped
     * connection even when the document itself was created there.
     */
    public function withCompanyId(?string $companyId): self
    {
        return new self(...[...get_object_vars($this), 'companyId' => $companyId]);
    }

    /**
     * Build a config from a snake_case array (the shape of config/finvalda.php).
     *
     * The optional `retry` sub-array maps to a RetryPolicy when its `enabled`
     * key is truthy: ['enabled' => true, 'max_attempts' => 3, 'delay_ms' => 100,
     * 'multiplier' => 2.0, 'max_delay_ms' => 10000].
     *
     * The optional `record_credentials` key accepts 'masked' (default), 'env', or
     * 'real'; anything else falls back to 'masked'.
     *
     * The optional `log_path` key builds a JsonLinesLogger when no $logger is
     * passed in; an explicit $logger always wins.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, ?LoggerInterface $logger = null): self
    {
        $retry = null;
        $retryConfig = $config['retry'] ?? [];

        if (is_array($retryConfig) && ! empty($retryConfig['enabled'])) {
            $retry = new RetryPolicy(
                maxAttempts: (int) ($retryConfig['max_attempts'] ?? 3),
                delayMs: (int) ($retryConfig['delay_ms'] ?? 100),
                multiplier: (float) ($retryConfig['multiplier'] ?? 2.0),
                maxDelayMs: (int) ($retryConfig['max_delay_ms'] ?? 10000),
            );
        }

        // An explicitly passed logger wins: the Laravel provider passes one when
        // `log_channel` is configured.
        if ($logger === null && ! empty($config['log_path'])) {
            $logger = new JsonLinesLogger((string) $config['log_path']);
        }

        return new self(
            baseUrl: (string) ($config['base_url'] ?? ''),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            connString: $config['conn_string'] ?? null,
            companyId: $config['company_id'] ?? null,
            language: Language::from((int) ($config['language'] ?? 0)),
            removeEmptyStringTags: (bool) ($config['remove_empty_string_tags'] ?? false),
            removeZeroNumberTags: (bool) ($config['remove_zero_number_tags'] ?? false),
            removeNewLines: (bool) ($config['remove_new_lines'] ?? false),
            timeout: (int) ($config['timeout'] ?? 30),
            logger: $logger,
            retry: $retry,
            normalizeFloats: (bool) ($config['normalize_floats'] ?? true),
            floatPrecision: (int) ($config['float_precision'] ?? 10),
            record: (bool) ($config['record'] ?? false),
            recordLimit: (int) ($config['record_limit'] ?? 20),
            recordCredentials: CredentialMode::tryFrom((string) ($config['record_credentials'] ?? ''))
                ?? CredentialMode::Masked,
            logFileContents: (bool) ($config['log_file_contents'] ?? false),
            logBodyBytes: (int) ($config['log_body_bytes'] ?? BodyTruncator::MAX_BYTES),
        );
    }
}
