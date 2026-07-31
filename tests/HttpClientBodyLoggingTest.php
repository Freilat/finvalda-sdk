<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\FinvaldaConfig;
use Finvalda\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

class HttpClientBodyLoggingTest extends TestCase
{
    /**
     * A report response as Finvalda actually sends it: the whole document
     * base64'd into one JSON string, comfortably under the 100 KB log budget.
     */
    private function invoiceResponse(): Response
    {
        return new Response(200, [], '{"AccessResult":"Success","error":"","data":"' . str_repeat('A', 58_000) . '"}');
    }

    /**
     * @return AbstractLogger&object{records: list<array{0: string, 1: array}>}
     */
    private function spyLogger(): object
    {
        return new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
    }

    private function httpClient(array $responses, ?FinvaldaConfig $config = null): HttpClient
    {
        return new HttpClient(
            $config ?? new FinvaldaConfig(baseUrl: 'https://example.com', username: 'u', password: 'p'),
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    }

    private function bodyOf(object $logger, string $message): ?string
    {
        foreach ($logger->records as [$logged, $context]) {
            if ($logged === $message) {
                return $context['body'];
            }
        }

        return null;
    }

    public function test_a_report_payload_is_elided_from_the_logged_response(): void
    {
        $logger = $this->spyLogger();
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'u',
            password: 'p',
            logger: $logger,
        );

        $this->httpClient([$this->invoiceResponse()], $config)->get('MakeInvoice');

        $body = $this->bodyOf($logger, 'Finvalda API response');

        $this->assertStringContainsString('"data":"[elided 58000 bytes]"', (string) $body);
        $this->assertLessThan(500, strlen((string) $body));
        $this->assertStringContainsString('"AccessResult":"Success"', (string) $body);
    }

    public function test_an_upload_payload_is_elided_from_the_logged_request(): void
    {
        $logger = $this->spyLogger();
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'u',
            password: 'p',
            logger: $logger,
        );

        $ok = new Response(200, [], json_encode(['AccessResult' => 'Success', 'nResult' => 1]));
        $httpClient = $this->httpClient([$ok], $config);

        // Documents::upload() hex-encodes the file, so the request body is twice
        // the file size — the same noise as a report response, going the other way.
        $httpClient->postOperation('InsertDocument', [
            'sFileName' => 'invoice.pdf',
            'sFileContent' => str_repeat('ab', 40_000),
        ]);

        $body = $this->bodyOf($logger, 'Finvalda API request');

        $this->assertStringContainsString('"sFileContent":"[elided 80000 bytes]"', (string) $body);
        $this->assertStringContainsString('"sFileName":"invoice.pdf"', (string) $body);
    }

    public function test_log_file_contents_keeps_the_payload_verbatim(): void
    {
        $logger = $this->spyLogger();
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'u',
            password: 'p',
            logger: $logger,
            logFileContents: true,
        );

        $this->httpClient([$this->invoiceResponse()], $config)->get('MakeInvoice');

        $body = $this->bodyOf($logger, 'Finvalda API response');

        $this->assertStringNotContainsString('[elided', (string) $body);
        $this->assertStringContainsString(str_repeat('A', 1_000), (string) $body);
    }

    public function test_log_body_bytes_caps_what_survives_elision(): void
    {
        $logger = $this->spyLogger();
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'u',
            password: 'p',
            logger: $logger,
            logBodyBytes: 50,
        );

        // `note` is not a payload key, so nothing is elided and the byte budget
        // is the only thing acting on this body.
        $body = '{"AccessResult":"Success","note":"' . str_repeat('x', 400) . '"}';
        $this->httpClient([new Response(200, [], $body)], $config)->get('GetPrekes');

        $logged = (string) $this->bodyOf($logger, 'Finvalda API response');

        $this->assertStringStartsWith(substr($body, 0, 50), $logged);
        $this->assertStringContainsString('... [truncated ' . (strlen($body) - 50) . ' bytes]', $logged);
    }

    public function test_recording_still_captures_the_payload_verbatim(): void
    {
        // The deliberate divergence: you reach for record() precisely when you
        // need the bytes, and it is bounded and opt-in. Logging is neither.
        $httpClient = $this->httpClient([$this->invoiceResponse()]);
        $httpClient->record();

        $httpClient->get('MakeInvoice');

        $body = (string) $httpClient->lastRecording()?->responseBody;

        $this->assertStringNotContainsString('[elided', $body);
        $this->assertStringContainsString(str_repeat('A', 1_000), $body);
    }
}
