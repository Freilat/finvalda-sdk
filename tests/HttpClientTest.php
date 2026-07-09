<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\FinvaldaConfig;
use Finvalda\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HttpClientTest extends TestCase
{
    private function createHttpClient(array $responses): HttpClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $handlerStack]);

        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        );

        return new HttpClient($config, $guzzle);
    }

    private function createHttpClientWithHistory(array $responses, array &$history): HttpClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $handlerStack]);

        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        );

        return new HttpClient($config, $guzzle);
    }

    public function test_operation_result_with_access_result_fail_and_nresult_zero_returns_failure(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode([
                'AccessResult' => 'Fail',
                'error' => 'Service exception: Wrong ItemClassName.',
                'nResult' => 0,
            ])),
        ]);

        $result = $httpClient->postOperation('test-endpoint');

        $this->assertFalse($result->success);
        $this->assertSame('Service exception: Wrong ItemClassName.', $result->error);
        $this->assertSame(0, $result->errorCode);
    }

    public function test_operation_result_with_access_result_fail_captures_error_key(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode([
                'AccessResult' => 'Fail',
                'error' => 'Some API error via error key',
                'nResult' => 0,
            ])),
        ]);

        $result = $httpClient->postOperation('test-endpoint');

        $this->assertFalse($result->success);
        $this->assertSame('Some API error via error key', $result->error);
    }

    public function test_operation_result_with_access_result_fail_prefers_serror_over_error(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode([
                'AccessResult' => 'Fail',
                'sError' => 'Detailed error from sError',
                'error' => 'Generic error from error',
                'nResult' => 0,
            ])),
        ]);

        $result = $httpClient->postOperation('test-endpoint');

        $this->assertFalse($result->success);
        $this->assertSame('Detailed error from sError', $result->error);
    }

    public function test_operation_result_with_access_result_fail_uses_default_message_when_no_error(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode([
                'AccessResult' => 'Fail',
                'nResult' => 0,
            ])),
        ]);

        $result = $httpClient->postOperation('test-endpoint');

        $this->assertFalse($result->success);
        $this->assertSame('Unknown error (AccessResult: Fail)', $result->error);
    }

    public function test_operation_result_with_access_result_ok_and_nresult_zero_returns_success(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode([
                'AccessResult' => 'Success',
                'nResult' => 0,
            ])),
        ]);

        $result = $httpClient->postOperation('test-endpoint');

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertNull($result->errorCode);
    }

    public function test_post_operation_sends_json_body_with_xmlstring(): void
    {
        $history = [];
        $httpClient = $this->createHttpClientWithHistory([
            new Response(200, [], json_encode([
                'AccessResult' => 'Success',
                'nResult' => 0,
            ])),
        ], $history);

        $jsonBody = '{"PardDok":{"sKlientas":"TEST"}}';

        $httpClient->postOperation('InsertNewOperation', [
            'ItemClassName' => 'PardDok',
            'sParametras' => 'PARAM1',
        ], $jsonBody);

        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));

        // Query string should be empty — everything goes in JSON body
        $this->assertEmpty($request->getUri()->getQuery());

        // Parse the JSON body
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('PardDok', $body['ItemClassName']);
        $this->assertSame('PARAM1', $body['sParametras']);
        $this->assertSame($jsonBody, $body['xmlstring']);
    }

    public function test_post_operation_without_body_sends_json_without_xmlstring(): void
    {
        $history = [];
        $httpClient = $this->createHttpClientWithHistory([
            new Response(200, [], json_encode([
                'AccessResult' => 'Success',
                'nResult' => 0,
            ])),
        ], $history);

        $httpClient->postOperation('InsertNewItem', [
            'ItemClassName' => 'Fvs.Klientas',
            'xmlstring' => '{"Fvs.Klientas":{"sKodas":"K001"}}',
        ]);

        $request = $history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('Fvs.Klientas', $body['ItemClassName']);
        $this->assertSame('{"Fvs.Klientas":{"sKodas":"K001"}}', $body['xmlstring']);
    }

    public function test_post_operation_json_sends_json_body(): void
    {
        $history = [];
        $httpClient = $this->createHttpClientWithHistory([
            new Response(200, [], json_encode([
                'AccessResult' => 'Success',
                'nResult' => 0,
            ])),
        ], $history);

        $httpClient->postOperationJson('LockOperation', [
            'sZurnalas' => '$PARD.',
            'nNumeris' => 49193,
        ]);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('$PARD.', $body['sZurnalas']);
        $this->assertSame(49193, $body['nNumeris']);
    }

    public function test_get_request_uses_query_params(): void
    {
        $history = [];
        $httpClient = $this->createHttpClientWithHistory([
            new Response(200, [], json_encode([
                'AccessResult' => 'Success',
            ])),
        ], $history);

        $httpClient->get('GetOperations', [
            'OpClass' => 'Sales',
            'DateFrom' => '2024-01-01',
        ]);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());

        $query = $request->getUri()->getQuery();
        $this->assertStringContainsString('OpClass=Sales', $query);
        $this->assertStringContainsString('DateFrom=2024-01-01', $query);

        // GET body should be empty
        $this->assertEmpty((string) $request->getBody());
    }

    public function test_debug_captures_request_and_response_when_enabled(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, ['X-Custom' => 'test'], json_encode([
                'AccessResult' => 'Success',
                'nResult' => 0,
            ])),
        ]);

        $httpClient->setDebug(true);

        $httpClient->postOperation('InsertNewOperation', [
            'ItemClassName' => 'PardDok',
        ], '{"PardDok":{}}');

        $debug = $httpClient->getLastDebugInfo();

        $this->assertSame('POST', $debug['request']['method']);
        $this->assertStringContainsString('InsertNewOperation', $debug['request']['url']);
        $this->assertIsArray($debug['request']['headers']);
        $this->assertIsArray($debug['request']['body']);
        $this->assertSame('PardDok', $debug['request']['body']['ItemClassName'] ?? null);
        $this->assertSame('{"PardDok":{}}', $debug['request']['body']['xmlstring'] ?? null);

        $this->assertSame(200, $debug['response']['status_code']);
        $this->assertIsArray($debug['response']['headers']);
        $this->assertIsString($debug['response']['body']);
    }

    public function test_debug_info_is_empty_when_disabled(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode([
                'AccessResult' => 'Success',
                'nResult' => 0,
            ])),
        ]);

        $httpClient->postOperation('test-endpoint');

        $debug = $httpClient->getLastDebugInfo();

        $this->assertEmpty($debug['request']);
        $this->assertEmpty($debug['response']);
    }

    public function test_set_debug_false_clears_previous_debug_info(): void
    {
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode([
                'AccessResult' => 'Success',
                'nResult' => 0,
            ])),
        ]);

        $httpClient->setDebug(true);
        $httpClient->postOperation('test-endpoint');

        // Debug info should be populated
        $this->assertNotEmpty($httpClient->getLastDebugInfo()['request']);

        // Disabling debug should clear it
        $httpClient->setDebug(false);
        $debug = $httpClient->getLastDebugInfo();

        $this->assertEmpty($debug['request']);
        $this->assertEmpty($debug['response']);
    }

    /**
     * @return \Psr\Log\AbstractLogger&object{records: list<array{level: mixed, message: string, context: array}>}
     */
    private function createSpyLogger(): object
    {
        return new class extends \Psr\Log\AbstractLogger
        {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    private function loggedContext(array $records, string $message): array
    {
        foreach ($records as $record) {
            if ($record['message'] === $message) {
                return $record['context'];
            }
        }

        $this->fail("No log record with message '{$message}'");
    }

    public function test_request_log_includes_request_body_string(): void
    {
        $logger = $this->createSpyLogger();
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success', 'nResult' => 0])),
        ]);
        $httpClient->setLogger($logger);

        $httpClient->postOperation('InsertNewItem', [
            'ItemClassName' => 'Fvs.Preke',
            'xmlstring' => '{"Fvs.Preke":{"sKodas":"PRE_01"}}',
        ]);

        $context = $this->loggedContext($logger->records, 'Finvalda API request');

        $this->assertIsString($context['body']);
        $this->assertStringContainsString('Fvs.Preke', $context['body']);
        $this->assertStringContainsString('PRE_01', $context['body']);
    }

    public function test_request_log_body_is_null_for_get_requests(): void
    {
        $logger = $this->createSpyLogger();
        $httpClient = $this->createHttpClient([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);
        $httpClient->setLogger($logger);

        $httpClient->get('GetPreke', ['sPrekesKodas' => 'PRE_01']);

        $context = $this->loggedContext($logger->records, 'Finvalda API request');

        $this->assertNull($context['body']);
        $this->assertSame(['sPrekesKodas' => 'PRE_01'], $context['params']);
    }

    public function test_response_log_includes_response_body_string(): void
    {
        $responseBody = json_encode(['AccessResult' => 'Success', 'Fvs.Preke' => null]);

        $logger = $this->createSpyLogger();
        $httpClient = $this->createHttpClient([
            new Response(200, [], $responseBody),
        ]);
        $httpClient->setLogger($logger);

        $httpClient->get('GetPreke', ['sPrekesKodas' => 'PRE_01']);

        $context = $this->loggedContext($logger->records, 'Finvalda API response');

        $this->assertSame($responseBody, $context['body']);
        $this->assertSame(200, $context['status_code']);
    }

    public function test_logged_bodies_are_truncated_at_cap(): void
    {
        $largeBody = '{"AccessResult":"Success","blob":"' . str_repeat('x', 150_000) . '"}';

        $logger = $this->createSpyLogger();
        $httpClient = $this->createHttpClient([
            new Response(200, [], $largeBody),
        ]);
        $httpClient->setLogger($logger);

        $httpClient->get('GetPrekes');

        $context = $this->loggedContext($logger->records, 'Finvalda API response');

        $this->assertLessThan(strlen($largeBody), strlen($context['body']));
        $this->assertLessThanOrEqual(100_000 + 64, strlen($context['body']));
        $this->assertStringContainsString('[truncated', $context['body']);
    }

    public function test_post_json_strips_float_artifacts_from_every_field(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient(new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        ), $guzzle);

        $httpClient->postJson('GetRecommendedPrice', [
            'input' => [
                'dKaina' => 0.1 + 0.2,
                'nKiekis' => 1.1 + 2.2,
                'dPVM_Procentas' => 21.123456,
            ],
        ]);

        $body = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $input = $body['input'];

        $this->assertSame(0.3, $input['dKaina']);
        $this->assertSame(3.3, $input['nKiekis']);
        $this->assertSame(21.123456, $input['dPVM_Procentas']);
    }

    public function test_post_json_can_disable_float_normalization(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode(['AccessResult' => 'Success'])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $handlerStack]);

        $httpClient = new HttpClient(new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
            normalizeFloats: false,
        ), $guzzle);

        $raw = 0.1 + 0.2;

        $httpClient->postJson('GetRecommendedPrice', [
            'input' => [
                'dKaina' => $raw,
            ],
        ]);

        $body = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($raw, $body['input']['dKaina']);
    }

    public function test_encode_json_uses_shortest_float_representation_regardless_of_host_serialize_precision(): void
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '50');

        try {
            $httpClient = new HttpClient(new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'user',
                password: 'pass',
            ));

            $json = $httpClient->encodeJson([
                'dSumaV' => 21.49,
                'dArtifact' => 0.1 + 0.2,
            ]);

            $this->assertSame('{"dSumaV":21.49,"dArtifact":0.3}', $json);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public function test_post_json_body_uses_shortest_float_representation_regardless_of_host_serialize_precision(): void
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '50');

        try {
            $history = [];
            $mock = new MockHandler([
                new Response(200, [], json_encode(['AccessResult' => 'Success'])),
            ]);
            $handlerStack = HandlerStack::create($mock);
            $handlerStack->push(Middleware::history($history));
            $guzzle = new Client(['handler' => $handlerStack]);

            $httpClient = new HttpClient(new FinvaldaConfig(
                baseUrl: 'https://example.com',
                username: 'user',
                password: 'pass',
            ), $guzzle);

            $httpClient->postJson('GetRecommendedPrice', [
                'input' => [
                    'dSumaV' => 21.49,
                    'dArtifact' => 0.1 + 0.2,
                ],
            ]);

            $rawBody = (string) $history[0]['request']->getBody();

            $this->assertStringContainsString('"dSumaV":21.49', $rawBody);
            $this->assertStringContainsString('"dArtifact":0.3', $rawBody);
            $this->assertStringNotContainsString('21.4899999', $rawBody);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }
}
