<?php

declare(strict_types=1);

namespace Finvalda;

use Finvalda\Enums\AccessResult;
use Finvalda\Enums\CredentialMode;
use Finvalda\Exceptions\AccessDeniedException;
use Finvalda\Exceptions\FinvaldaException;
use Finvalda\Exceptions\NetworkException;
use Finvalda\Exceptions\ServerException;
use Finvalda\Recording\Exchange;
use Finvalda\Recording\Recorder;
use Finvalda\Responses\OperationResult;
use Finvalda\Responses\Response;
use Finvalda\Retry\RetryHandler;
use Finvalda\Support\OutboundNumericNormalizer;
use Finvalda\Support\Redactor;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

final class HttpClient
{
    /**
     * Maximum number of bytes of a request/response body included in
     * PSR-3 log records. Larger bodies are truncated with a marker.
     */
    private const MAX_LOGGED_BODY_BYTES = 100_000;

    private ClientInterface $client;

    private ?LoggerInterface $logger;

    private ?RetryHandler $retryHandler;

    private OutboundNumericNormalizer $normalizer;

    private bool $debug = false;

    private array $lastRequest = [];

    private array $lastResponse = [];

    private ?Recorder $recorder = null;

    /**
     * @param FinvaldaConfig $config SDK configuration
     * @param ClientInterface|null $client Optional Guzzle client instance (for testing or custom configuration)
     */
    public function __construct(
        private readonly FinvaldaConfig $config,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => rtrim($this->config->baseUrl, '/') . '/',
            'timeout' => $this->config->timeout,
            'headers' => $this->buildHeaders(),
        ]);
        $this->logger = $this->config->logger;
        $this->retryHandler = $this->config->retry !== null
            ? new RetryHandler($this->config->retry, $this->logger)
            : null;
        $this->normalizer = new OutboundNumericNormalizer(
            enabled: $this->config->normalizeFloats,
            precision: $this->config->floatPrecision,
        );
    }

    /**
     * Set the logger instance for request/response logging.
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Enable or disable debug mode. When enabled, the last request and response
     * details are captured and available via getLastDebugInfo().
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;

        if (! $debug) {
            $this->lastRequest = [];
            $this->lastResponse = [];
        }
    }

    /**
     * Get debug information from the last request/response cycle.
     * Only populated when debug mode is enabled via setDebug(true).
     *
     * @return array{request: array, response: array}
     */
    public function getLastDebugInfo(): array
    {
        return [
            'request' => $this->lastRequest,
            'response' => $this->lastResponse,
        ];
    }

    /**
     * Start recording request/response exchanges in memory. Replaces any
     * exchanges recorded so far.
     *
     * @param  int  $limit  Maximum exchanges kept; the oldest are dropped first
     * @param  CredentialMode  $credentials  How credential values appear in recordings
     */
    public function record(int $limit = 20, CredentialMode $credentials = CredentialMode::Masked): void
    {
        $this->recorder = new Recorder($limit, $credentials);
    }

    /**
     * Stop recording and drop the recorded exchanges.
     */
    public function stopRecording(): void
    {
        $this->recorder = null;
    }

    /**
     * Recorded exchanges, oldest first. Empty when recording is off.
     *
     * @return list<Exchange>
     */
    public function recordings(): array
    {
        return $this->recorder?->all() ?? [];
    }

    public function lastRecording(): ?Exchange
    {
        return $this->recorder?->last();
    }

    public function get(string $endpoint, array $params = []): Response
    {
        return $this->request('GET', $endpoint, ['query' => $this->cleanParams($params)]);
    }

    public function post(string $endpoint, array $params = [], ?string $body = null): Response
    {
        $options = ['query' => $this->cleanParams($params)];

        if ($body !== null) {
            $options['body'] = $body;
        }

        return $this->request('POST', $endpoint, $options);
    }

    public function postJson(string $endpoint, array $data): Response
    {
        return $this->request('POST', $endpoint, [
            'json' => $data,
        ]);
    }

    public function postOperationJson(string $endpoint, array $data): OperationResult
    {
        try {
            $response = $this->sendRequest('POST', $endpoint, [
                'json' => $data,
            ]);

            return $this->parseOperationResult($response);
        } catch (GuzzleException $e) {
            throw $this->wrapGuzzleException($e);
        }
    }

    public function postOperation(string $endpoint, array $params = [], ?string $body = null): OperationResult
    {
        try {
            $data = $this->cleanParams($params);

            if ($body !== null) {
                $data['xmlstring'] = $body;
            }

            $response = $this->sendRequest('POST', $endpoint, [
                'json' => $data,
            ]);

            return $this->parseOperationResult($response);
        } catch (GuzzleException $e) {
            throw $this->wrapGuzzleException($e);
        }
    }

    /**
     * Encode SDK-owned outbound data using the configured numeric normalization.
     *
     * @throws \JsonException
     */
    public function encodeJson(mixed $data): string
    {
        return $this->withShortestFloatEncoding(
            fn (): string => json_encode($this->normalizer->normalize($data), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Run an encoding step with serialize_precision forced to -1 so floats
     * serialize as their shortest round-trippable form (e.g. 21.49, not
     * 21.489999999999998...) regardless of the host's php.ini. Rounding alone
     * does not suffice: a host that sets serialize_precision high expands every
     * non-terminating binary fraction back into its full decimal.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $encode
     * @return TReturn
     */
    private function withShortestFloatEncoding(callable $encode): mixed
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            return $encode();
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public function getRaw(string $endpoint, array $params = []): string
    {
        try {
            return $this->sendRequest('GET', $endpoint, [
                'query' => $this->cleanParams($params),
            ]);
        } catch (GuzzleException $e) {
            throw $this->wrapGuzzleException($e);
        }
    }

    private function request(string $method, string $endpoint, array $options): Response
    {
        try {
            $body = $this->sendRequest($method, $endpoint, $options);

            return $this->parseResponse($body);
        } catch (GuzzleException $e) {
            throw $this->wrapGuzzleException($e);
        }
    }

    private function sendRequest(string $method, string $endpoint, array $options): string
    {
        if (isset($options['json'])) {
            $options['json'] = $this->normalizer->normalize($options['json']);
        }

        $attempt = 0;

        $doRequest = function () use ($method, $endpoint, $options, &$attempt): string {
            $attempt++;
            $startTime = microtime(true);

            $this->logRequest($method, $endpoint, $options);

            if ($this->debug) {
                $this->lastRequest = [
                    'method' => $method,
                    'url' => rtrim($this->config->baseUrl, '/') . '/' . $endpoint,
                    'headers' => Redactor::apply(array_merge($this->buildHeaders(), $options['headers'] ?? [])),
                    'body' => $options['body'] ?? $options['form_params'] ?? $options['json'] ?? null,
                ];
            }

            try {
                $response = $this->client->request($method, $endpoint, $options);
            } catch (GuzzleException $e) {
                $this->recordFailure($method, $endpoint, $options, $e, microtime(true) - $startTime, $attempt);

                throw $e;
            }

            $body = (string) $response->getBody();

            $duration = microtime(true) - $startTime;
            $this->logResponse($method, $endpoint, $response->getStatusCode(), $duration, $body);

            if ($this->debug) {
                $this->lastResponse = [
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $body,
                ];
            }

            $this->recorder?->record(new Exchange(
                method: $method,
                url: $this->recordedUrl($endpoint, $options),
                headers: $this->recordedHeaders($options),
                body: $this->recordedBody($options),
                statusCode: $response->getStatusCode(),
                reasonPhrase: $response->getReasonPhrase(),
                responseHeaders: $response->getHeaders(),
                responseBody: $body,
                durationMs: $duration * 1000,
                attempt: $attempt,
            ));

            return $body;
        };

        if ($this->retryHandler !== null) {
            return $this->withShortestFloatEncoding(fn (): string => $this->retryHandler->execute($doRequest));
        }

        return $this->withShortestFloatEncoding($doRequest);
    }

    /**
     * Record a failed attempt. Captures the response when the failure carried
     * one (4xx/5xx), otherwise just the transport error.
     *
     * @param  array<string, mixed>  $options
     */
    private function recordFailure(
        string $method,
        string $endpoint,
        array $options,
        GuzzleException $e,
        float $duration,
        int $attempt,
    ): void {
        if ($this->recorder === null) {
            return;
        }

        $response = $e instanceof RequestException && $e->hasResponse() ? $e->getResponse() : null;

        $this->recorder->record(new Exchange(
            method: $method,
            url: $this->recordedUrl($endpoint, $options),
            headers: $this->recordedHeaders($options),
            body: $this->recordedBody($options),
            statusCode: $response?->getStatusCode(),
            reasonPhrase: $response?->getReasonPhrase(),
            responseHeaders: $response?->getHeaders() ?? [],
            responseBody: $response !== null ? (string) $response->getBody() : null,
            durationMs: $duration * 1000,
            error: $e->getMessage(),
            attempt: $attempt,
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function recordedUrl(string $endpoint, array $options): string
    {
        $url = rtrim($this->config->baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $query = $options['query'] ?? [];

        if (is_array($query) && $query !== []) {
            return $url . '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function recordedHeaders(array $options): array
    {
        /** @var array<string, string> $headers */
        $headers = array_merge($this->buildHeaders(), $options['headers'] ?? []);

        return $headers;
    }

    /**
     * The request body as handed to Guzzle. JSON is encoded with default flags
     * to match Guzzle's own encoding of the `json` option.
     *
     * @param  array<string, mixed>  $options
     */
    private function recordedBody(array $options): ?string
    {
        if (isset($options['body']) && is_string($options['body'])) {
            return $options['body'];
        }

        if (isset($options['json'])) {
            return json_encode($options['json']) ?: null;
        }

        return null;
    }

    private function logRequest(string $method, string $endpoint, array $options): void
    {
        if ($this->logger === null) {
            return;
        }

        $body = $options['body']
            ?? (isset($options['json']) ? json_encode($options['json']) : null);

        $this->logger->debug('Finvalda API request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'params' => Redactor::apply($options['query'] ?? $options['json'] ?? []),
            'has_body' => isset($options['body']) || isset($options['json']),
            'body' => $this->truncateForLog(is_string($body) ? $body : null),
        ]);
    }

    private function logResponse(string $method, string $endpoint, int $statusCode, float $duration, string $body): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->debug('Finvalda API response', [
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'duration_ms' => round($duration * 1000, 2),
            'body' => $this->truncateForLog($body),
        ]);
    }

    private function truncateForLog(?string $body): ?string
    {
        if ($body === null || strlen($body) <= self::MAX_LOGGED_BODY_BYTES) {
            return $body;
        }

        $omitted = strlen($body) - self::MAX_LOGGED_BODY_BYTES;

        return substr($body, 0, self::MAX_LOGGED_BODY_BYTES) . "... [truncated {$omitted} bytes]";
    }

    /**
     * Decode a response body into an array. Bodies are normally JSON, but
     * some endpoints (e.g. GetFvsUser on certain server versions) ignore the
     * Accept header and return XML — fall back to XML parsing for those.
     *
     * @throws FinvaldaException
     */
    private function decodeBody(string $body): array
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (str_starts_with(ltrim($body), '<')) {
            $decoded = $this->decodeXmlBody($body);

            if ($decoded !== null) {
                return $decoded;
            }
        }

        throw new FinvaldaException('Invalid JSON response: ' . json_last_error_msg());
    }

    private function decodeXmlBody(string $body): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        $decoded = json_decode(json_encode($xml) ?: 'null', true);

        return is_array($decoded) ? $decoded : null;
    }

    private function parseResponse(string $body): Response
    {
        $decoded = $this->decodeBody($body);

        $accessResult = AccessResult::tryFrom($decoded['AccessResult'] ?? '') ?? AccessResult::Fail;
        $error = $decoded['error'] ?? $decoded['sError'] ?? null;

        // XML-decoded empty elements arrive as empty arrays, not strings
        if (! is_string($error) || $error === '') {
            $error = null;
        }

        if ($accessResult === AccessResult::AccessDenied) {
            throw new AccessDeniedException($error ?? 'Access denied');
        }

        $data = $decoded;
        unset($data['AccessResult'], $data['error'], $data['sError']);

        // Extract items from common response shapes
        $items = $data['items'] ?? $data['Table'] ?? $data;

        // Unwrap single-key responses where value is a sequential list
        // (e.g. {"Paslaugos": [...]}, {"Prekes": [...]}, {"Klientai": [...]})
        if (is_array($items) && count($items) === 1) {
            $first = reset($items);
            if (is_array($first) && array_is_list($first)) {
                $items = $first;
            }
        }

        return new Response(
            accessResult: $accessResult,
            data: is_array($items) ? $items : [],
            error: $error,
            raw: $decoded,
        );
    }

    private function parseOperationResult(string $body): OperationResult
    {
        $decoded = $this->decodeBody($body);

        $accessResult = AccessResult::tryFrom($decoded['AccessResult'] ?? '') ?? AccessResult::Fail;

        if ($accessResult === AccessResult::AccessDenied) {
            $message = $decoded['sError'] ?? $decoded['error'] ?? null;

            throw new AccessDeniedException(is_string($message) && $message !== '' ? $message : 'Access denied');
        }

        if ($accessResult === AccessResult::Fail) {
            $errorMessage = $decoded['sError'] ?? $decoded['error'] ?? 'Unknown error (AccessResult: Fail)';

            return new OperationResult(
                success: false,
                error: $errorMessage,
                errorCode: (int) ($decoded['nResult'] ?? $decoded['result'] ?? -1),
            );
        }

        $resultCode = $decoded['nResult'] ?? $decoded['result'] ?? -1;
        $errorMessage = $decoded['sError'] ?? $decoded['error'] ?? null;

        if ((int) $resultCode !== 0) {
            return new OperationResult(
                success: false,
                error: $errorMessage,
                errorCode: (int) $resultCode,
            );
        }

        // Parse operation details from sError XML on success
        $series = null;
        $document = null;
        $journal = null;
        $number = null;

        if ($errorMessage && str_contains($errorMessage, '<OP_DUOMENYS>')) {
            $xml = @simplexml_load_string($errorMessage);
            if ($xml !== false) {
                $series = (string) ($xml->SERIJA ?? '');
                $document = (string) ($xml->DOKUMENTAS ?? '');
                $journal = (string) ($xml->ZURNALAS ?? '');
                $number = isset($xml->NUMERIS) ? (int) (string) $xml->NUMERIS : null;
            }
        }

        return new OperationResult(
            success: true,
            series: $series,
            document: $document,
            journal: $journal,
            number: $number,
        );
    }

    private function buildHeaders(): array
    {
        $headers = [
            'UserName' => $this->config->username,
            'Password' => $this->config->password,
            'Accept' => 'application/json',
            'Language' => (string) $this->config->language->value,
        ];

        if ($this->config->connString !== null) {
            $headers['ConnString'] = $this->config->connString;
        }

        if ($this->config->companyId !== null) {
            $headers['CompanyID'] = $this->config->companyId;
        }

        if ($this->config->removeEmptyStringTags) {
            $headers['RemoveEmptyStringTags'] = 'true';
        }

        if ($this->config->removeZeroNumberTags) {
            $headers['RemoveZeroNumberTags'] = 'true';
        }

        if ($this->config->removeNewLines) {
            $headers['RemoveNewLines'] = 'true';
        }

        return $headers;
    }

    /**
     * Remove null values from parameters. Empty strings are preserved
     * as the API may distinguish between "no value" and "empty string".
     */
    private function cleanParams(array $params): array
    {
        return array_filter($params, fn ($value) => $value !== null);
    }

    /**
     * Convert Guzzle exceptions to appropriate SDK exception types.
     */
    private function wrapGuzzleException(GuzzleException $e): FinvaldaException
    {
        // Connection/network errors (DNS failure, timeout, connection refused)
        if ($e instanceof ConnectException) {
            return new NetworkException(
                'Network error: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // HTTP errors with response
        if ($e instanceof RequestException && $e->hasResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();

            // Server errors (5xx)
            if ($statusCode >= 500) {
                return new ServerException(
                    "Server error ({$statusCode}): " . $e->getMessage(),
                    $statusCode,
                    $e
                );
            }

            // Other HTTP errors (4xx): preserve the status code so callers can
            // react to it — e.g. a 404 on an action endpoint means this server
            // build does not expose that endpoint.
            return new FinvaldaException(
                'HTTP request failed: ' . $e->getMessage(),
                $statusCode,
                $e
            );
        }

        // Default fallback (no HTTP response, e.g. malformed request)
        return new FinvaldaException(
            'HTTP request failed: ' . $e->getMessage(),
            0,
            $e
        );
    }
}
