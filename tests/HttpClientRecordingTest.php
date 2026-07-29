<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Enums\CredentialMode;
use Finvalda\Exceptions\FinvaldaException;
use Finvalda\Finvalda;
use Finvalda\FinvaldaConfig;
use Finvalda\HttpClient;
use Finvalda\Retry\RetryPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HttpClientRecordingTest extends TestCase
{
    /**
     * @param  list<Response|\Throwable>  $responses
     */
    private function createHttpClient(array $responses, ?FinvaldaConfig $config = null): HttpClient
    {
        $mock = new MockHandler($responses);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);

        $config ??= new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
        );

        return new HttpClient($config, $guzzle);
    }

    /**
     * References::user() is the SDK's only sPassword sender — it travels as a
     * GET query parameter, so it is the case the recording redaction must cover.
     */
    private function lookUpUser(HttpClient $httpClient, string $userName, string $password): void
    {
        (new Finvalda(
            new FinvaldaConfig(baseUrl: 'https://example.com/FvsServicePure.svc', username: 'demo', password: 'x'),
            $httpClient,
        ))->references()->user($userName, $password);
    }

    public function test_recording_is_off_by_default(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->get('GetPrekes');

        $this->assertSame([], $httpClient->recordings());
        $this->assertNull($httpClient->lastRecording());
    }

    public function test_records_a_successful_get(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record();
        $httpClient->get('GetPrekes', ['sKodas' => 'ABC']);

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame('GET', $exchange->method);
        $this->assertSame('https://example.com/FvsServicePure.svc/GetPrekes?sKodas=ABC', $exchange->url);
        $this->assertSame(200, $exchange->statusCode);
        $this->assertSame('OK', $exchange->reasonPhrase);
        $this->assertNull($exchange->body);
        $this->assertStringContainsString('AccessResult', (string) $exchange->responseBody);
        $this->assertGreaterThan(0.0, $exchange->durationMs);
        $this->assertSame(1, $exchange->attempt);
    }

    public function test_records_the_json_body_of_a_write_operation(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success', 'nResult' => 0])),
        ]);

        $httpClient->record();
        $httpClient->postOperation('InsertNewOperation', ['ItemClassName' => 'PardDok'], '{"PardDok":{}}');

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame('POST', $exchange->method);
        $this->assertStringContainsString('"ItemClassName":"PardDok"', (string) $exchange->body);
        $this->assertStringContainsString('xmlstring', (string) $exchange->body);
    }

    public function test_masks_credentials_by_default(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record();
        $httpClient->get('GetPrekes');

        $exchange = $httpClient->lastRecording();

        $this->assertSame('***', $exchange?->headers['Password']);
        $this->assertSame('demo', $exchange?->headers['UserName']);
        $this->assertStringNotContainsString('secret-password', (string) $exchange);
    }

    public function test_env_mode_emits_a_runnable_curl_without_the_secret(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record(credentials: CredentialMode::Env);
        $httpClient->get('GetPrekes');

        $curl = $httpClient->lastRecording()?->toCurl() ?? '';

        $this->assertStringNotContainsString('secret-password', $curl);
        $this->assertStringContainsString('-H \'Password: \'"$FVS_PASSWORD"', $curl);
    }

    public function test_captures_real_credentials_when_requested(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record(credentials: CredentialMode::Real);
        $httpClient->get('GetPrekes');

        $this->assertSame('secret-password', $httpClient->lastRecording()?->headers['Password']);
    }

    public function test_records_a_failed_request_and_rethrows(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(500, [], 'Internal failure'),
        ]);

        $httpClient->record();

        try {
            $httpClient->get('GetPrekes');
            $this->fail('Expected a FinvaldaException');
        } catch (FinvaldaException) {
            // expected
        }

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame(500, $exchange->statusCode);
        $this->assertStringContainsString('Internal failure', (string) $exchange->responseBody);
        $this->assertNotNull($exchange->error);
    }

    public function test_records_one_exchange_per_retry_attempt(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
            retry: new RetryPolicy(maxAttempts: 3, delayMs: 1),
        );

        $httpClient = $this->createHttpClient([
            new Response(500, [], 'boom'),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $httpClient->record();
        $httpClient->get('GetPrekes');

        $recordings = $httpClient->recordings();

        $this->assertCount(2, $recordings);
        $this->assertSame(500, $recordings[0]->statusCode);
        $this->assertSame(1, $recordings[0]->attempt);
        $this->assertSame(200, $recordings[1]->statusCode);
        $this->assertSame(2, $recordings[1]->attempt);
    }

    public function test_stop_recording_drops_the_buffer(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record();
        $httpClient->get('GetPrekes');
        $httpClient->stopRecording();
        $httpClient->get('GetPrekes');

        $this->assertSame([], $httpClient->recordings());
    }

    public function test_recording_limit_is_honoured(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record(limit: 2);
        $httpClient->get('GetPrekes', ['sKodas' => 'A']);
        $httpClient->get('GetPrekes', ['sKodas' => 'B']);
        $httpClient->get('GetPrekes', ['sKodas' => 'C']);

        $recordings = $httpClient->recordings();

        $this->assertCount(2, $recordings);
        $this->assertStringContainsString('sKodas=B', $recordings[0]->url);
        $this->assertStringContainsString('sKodas=C', $recordings[1]->url);
    }

    public function test_curl_output_reproduces_the_recorded_call(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success', 'nResult' => 0])),
        ]);

        $httpClient->record();
        $httpClient->postOperation('InsertNewOperation', ['ItemClassName' => 'PardDok'], '{"PardDok":{}}');

        $curl = $httpClient->lastRecording()?->toCurl() ?? '';

        $this->assertStringContainsString(
            "curl -X POST 'https://example.com/FvsServicePure.svc/InsertNewOperation'",
            $curl,
        );
        $this->assertStringContainsString("-H 'Content-Type: application/json'", $curl);
        $this->assertStringContainsString('-d ', $curl);
    }

    public function test_recorded_url_matches_the_query_string_guzzle_actually_sends(): void
    {
        $history = [];

        $baseUrl = 'https://example.com/FvsServicePure.svc';
        $mock = new MockHandler([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $guzzle = new Client([
            'handler' => $stack,
            'base_uri' => rtrim($baseUrl, '/') . '/',
        ]);

        $config = new FinvaldaConfig(
            baseUrl: $baseUrl,
            username: 'demo',
            password: 'secret-password',
        );

        $httpClient = new HttpClient($config, $guzzle);

        $httpClient->record();
        $httpClient->get('GetPrekes', ['sPavadinimas' => 'A B']);

        $exchange = $httpClient->lastRecording();
        $this->assertNotNull($exchange);

        $sentUri = (string) $history[0]['request']->getUri();

        $this->assertSame($sentUri, $exchange->url);
        $this->assertStringContainsString('A%20B', $exchange->url);
        $this->assertStringNotContainsString('A+B', $exchange->url);
    }

    public function test_recorded_url_matches_a_leading_slash_endpoint_that_guzzle_actually_requests(): void
    {
        $history = [];

        $baseUrl = 'https://example.com/FvsServicePure.svc';
        $mock = new MockHandler([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $guzzle = new Client([
            'handler' => $stack,
            'base_uri' => rtrim($baseUrl, '/') . '/',
        ]);

        $config = new FinvaldaConfig(
            baseUrl: $baseUrl,
            username: 'demo',
            password: 'secret-password',
        );

        $httpClient = new HttpClient($config, $guzzle);

        $httpClient->record();
        $httpClient->get('/GetPrekes');

        $exchange = $httpClient->lastRecording();
        $this->assertNotNull($exchange);

        $sentUri = (string) $history[0]['request']->getUri();

        $this->assertSame($sentUri, $exchange->url);
        $this->assertSame('https://example.com/GetPrekes', $exchange->url);
    }

    public function test_recording_can_be_enabled_from_config(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
            record: true,
            recordLimit: 2,
        );

        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $httpClient->get('GetPrekes');

        $this->assertCount(1, $httpClient->recordings());
        $this->assertSame('***', $httpClient->lastRecording()?->headers['Password']);
    }

    public function test_config_can_request_env_placeholders(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
            record: true,
            recordCredentials: CredentialMode::Env,
        );

        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $httpClient->get('GetPrekes');

        $this->assertSame('$FVS_PASSWORD', $httpClient->lastRecording()?->headers['Password']);
    }

    /**
     * Guzzle's cURL handler builds the ConnectException message as
     * "cURL error N: ... for {uri}", so a real sPassword query value lands in
     * Exchange::$error verbatim unless it is scrubbed.
     */
    public function test_a_transport_failure_does_not_leak_the_credential_in_masked_mode(): void
    {
        $uri = 'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=bob&sPassword=topsecret123';

        $httpClient = $this->createHttpClient([
            new ConnectException(
                "cURL error 6: Could not resolve host: example.com (see https://curl.se/libcurl/c/libcurl-errors.html) for {$uri}",
                new Request('GET', $uri),
            ),
        ]);

        $httpClient->record();

        try {
            $this->lookUpUser($httpClient, 'bob', 'topsecret123');
            $this->fail('Expected a FinvaldaException');
        } catch (FinvaldaException) {
            // expected
        }

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertStringNotContainsString('topsecret123', (string) $exchange);
        $this->assertStringNotContainsString('topsecret123', $exchange->toCurl());
        $this->assertStringNotContainsString('topsecret123', (string) json_encode($exchange->toArray()));
        $this->assertStringNotContainsString('secret-password', (string) json_encode($exchange->toArray()));
        // The message is still useful
        $this->assertStringContainsString('Could not resolve host', (string) $exchange->error);
        $this->assertStringContainsString('sPassword=***', (string) $exchange->error);
    }

    public function test_a_transport_failure_does_not_leak_the_credential_in_env_mode(): void
    {
        $uri = 'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=bob&sPassword=topsecret123';

        $httpClient = $this->createHttpClient([
            new ConnectException("cURL error 7: Failed to connect for {$uri}", new Request('GET', $uri)),
        ]);

        $httpClient->record(credentials: CredentialMode::Env);

        try {
            $this->lookUpUser($httpClient, 'bob', 'topsecret123');
            $this->fail('Expected a FinvaldaException');
        } catch (FinvaldaException) {
            // expected
        }

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertStringNotContainsString('topsecret123', (string) json_encode($exchange->toArray()));
        $this->assertStringNotContainsString('secret-password', (string) json_encode($exchange->toArray()));
        $this->assertStringContainsString('sPassword=$FVS_SPASSWORD', (string) $exchange->error);
    }

    public function test_a_response_echoing_the_credential_does_not_leak_it(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(
                200,
                ['X-Echo' => 'pw=topsecret123'],
                json_encode(['AccessResult' => 'Success', 'sPassword' => 'topsecret123']),
            ),
        ]);

        $httpClient->record();
        $this->lookUpUser($httpClient, 'bob', 'topsecret123');

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame(['X-Echo' => ['pw=***']], $exchange->responseHeaders);
        $this->assertStringNotContainsString('topsecret123', (string) $exchange);
        $this->assertStringNotContainsString('topsecret123', $exchange->toCurl());
        $this->assertStringNotContainsString('topsecret123', (string) json_encode($exchange->toArray()));
    }

    public function test_a_recorded_url_query_credential_stays_literal_and_keeps_rfc3986_encoding(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);

        $httpClient->record();
        $httpClient->get('GetFvsUser', ['sUserName' => 'John Doe', 'sPassword' => 'topsecret123']);

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame(
            'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=John%20Doe&sPassword=***',
            $exchange->url,
        );

        $httpClient->record(credentials: CredentialMode::Env);
        $httpClient->get('GetFvsUser', ['sUserName' => 'John Doe', 'sPassword' => 'topsecret123']);

        $curl = $httpClient->lastRecording()?->toCurl() ?? '';

        $this->assertStringNotContainsString('%24FVS_SPASSWORD', $curl);
        $this->assertStringContainsString('sPassword=\'"$FVS_SPASSWORD"', $curl);
    }

    public function test_recorded_bodies_are_capped_by_size(): void
    {
        $big = json_encode(['AccessResult' => 'Success', 'blob' => str_repeat('x', 150_000)]);
        $small = json_encode(['AccessResult' => 'Success', 'blob' => str_repeat('y', 1_000)]);

        $httpClient = $this->createHttpClient([
            new Response(200, [], $big),
            new Response(200, [], $small),
        ]);

        $httpClient->record();
        $httpClient->get('GetBig');
        $httpClient->get('GetSmall');

        $recordings = $httpClient->recordings();

        $this->assertLessThan(strlen((string) $big), strlen((string) $recordings[0]->responseBody));
        $this->assertStringContainsString('... [truncated ', (string) $recordings[0]->responseBody);
        $this->assertSame($small, $recordings[1]->responseBody);
    }

    public function test_a_recorded_request_body_is_capped_by_size(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success', 'nResult' => 0])),
        ]);

        $httpClient->record();
        $httpClient->postOperation('InsertNewOperation', ['ItemClassName' => 'PardDok'], str_repeat('z', 150_000));

        $body = (string) $httpClient->lastRecording()?->body;

        $this->assertLessThan(150_000, strlen($body));
        $this->assertStringContainsString('... [truncated ', $body);
    }

    public function test_env_placeholders_reach_headers_and_the_url_query_from_a_config_array(): void
    {
        $config = FinvaldaConfig::fromArray([
            'base_url' => 'https://example.com/FvsServicePure.svc',
            'username' => 'demo',
            'password' => 'secret-password',
            'record' => true,
            'record_limit' => 5,
            'record_credentials' => 'env',
        ]);

        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $this->lookUpUser($httpClient, 'bob', 'topsecret123');

        $exchange = $httpClient->lastRecording();

        $this->assertNotNull($exchange);
        $this->assertSame('$FVS_PASSWORD', $exchange->headers['Password']);
        $this->assertSame(
            'https://example.com/FvsServicePure.svc/GetFvsUser?sUserName=bob&sPassword=$FVS_SPASSWORD',
            $exchange->url,
        );
        $this->assertStringNotContainsString('secret-password', $exchange->toCurl());
        $this->assertStringNotContainsString('topsecret123', $exchange->toCurl());
        $this->assertStringContainsString('sPassword=\'"$FVS_SPASSWORD"', $exchange->toCurl());
    }

    public function test_config_can_request_credential_capture(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com/FvsServicePure.svc',
            username: 'demo',
            password: 'secret-password',
            record: true,
            recordCredentials: CredentialMode::Real,
        );

        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ], $config);

        $httpClient->get('GetPrekes');

        $this->assertSame('secret-password', $httpClient->lastRecording()?->headers['Password']);
    }
}
