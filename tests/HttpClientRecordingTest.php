<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Enums\CredentialMode;
use Finvalda\Exceptions\FinvaldaException;
use Finvalda\FinvaldaConfig;
use Finvalda\HttpClient;
use Finvalda\Retry\RetryPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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
}
