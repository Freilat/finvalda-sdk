<?php

declare(strict_types=1);

namespace Finvalda\Tests\Logging;

use Finvalda\FinvaldaConfig;
use Finvalda\HttpClient;
use Finvalda\Logging\JsonLinesLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class JsonLinesLoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/finvalda-json-lines-logger-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->dir);
    }

    public function test_it_appends_one_json_object_per_record(): void
    {
        $path = $this->dir . '/finvalda.log';
        $logger = new JsonLinesLogger($path);

        $logger->debug('Finvalda API request', ['method' => 'GET', 'endpoint' => 'GetPrekes']);
        $logger->debug('Finvalda API response', ['status_code' => 200]);

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Finvalda API request', $first['message']);
        $this->assertSame('GET', $first['method']);
        $this->assertSame('GetPrekes', $first['endpoint']);
        $this->assertSame(200, $second['status_code']);
    }

    public function test_each_entry_carries_an_ordered_timestamp_a_pid_and_a_level(): void
    {
        $path = $this->dir . '/finvalda.log';

        (new JsonLinesLogger($path))->debug('Finvalda API request');

        $entry = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('debug', $entry['level']);
        $this->assertSame(getmypid(), $entry['pid']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/',
            $entry['ts'],
            'ts needs millisecond precision and an offset to order and correlate entries',
        );
    }

    public function test_it_truncates_long_context_strings_at_any_depth(): void
    {
        $path = $this->dir . '/finvalda.log';

        (new JsonLinesLogger($path, maxBodyBytes: 10))->debug('Finvalda API request', [
            'body' => str_repeat('a', 15),
            'params' => ['xmlstring' => str_repeat('b', 12), 'nKiekis' => 5],
        ]);

        $entry = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('aaaaaaaaaa... [truncated 5 bytes]', $entry['body']);
        $this->assertSame('bbbbbbbbbb... [truncated 2 bytes]', $entry['params']['xmlstring']);
        $this->assertSame(5, $entry['params']['nKiekis']);
    }

    public function test_it_stringifies_a_stringable_level(): void
    {
        $path = $this->dir . '/finvalda.log';
        $level = new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'notice';
            }
        };

        (new JsonLinesLogger($path))->log($level, 'Finvalda API request');

        $entry = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('notice', $entry['level']);
    }

    public function test_it_creates_the_log_directory(): void
    {
        $path = $this->dir . '/nested/deeper/finvalda.log';

        (new JsonLinesLogger($path))->debug('Finvalda API request');

        $this->assertFileExists($path);
    }

    public function test_it_keeps_context_values_that_collide_with_reserved_keys(): void
    {
        $path = $this->dir . '/finvalda.log';

        (new JsonLinesLogger($path))->debug('Finvalda API request', [
            'message' => 'from the context',
            'level' => 'from the context too',
            'endpoint' => 'GetPrekes',
        ]);

        $entry = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Finvalda API request', $entry['message']);
        $this->assertSame('debug', $entry['level']);
        $this->assertSame('from the context', $entry['context_message']);
        $this->assertSame('from the context too', $entry['context_level']);
        $this->assertSame('GetPrekes', $entry['endpoint']);
    }

    public function test_it_reports_an_unwritable_path_to_the_php_error_log_once(): void
    {
        mkdir($this->dir, 0o775, true);
        $blocker = $this->dir . '/blocker';
        touch($blocker);

        // A file where a directory is expected: mkdir and the write both fail.
        $logger = new JsonLinesLogger($blocker . '/finvalda.log');

        $errors = $this->captureErrorLog(function () use ($logger): void {
            $logger->debug('Finvalda API request');
            $logger->debug('Finvalda API response');
        });

        $this->assertSame(1, substr_count($errors, 'Finvalda JsonLinesLogger:'));
        $this->assertStringContainsString($blocker, $errors);
        $this->assertSame('', file_get_contents($blocker));
    }

    public function test_it_does_not_throw_when_the_message_cannot_be_stringified(): void
    {
        $path = $this->dir . '/finvalda.log';
        $message = new class implements \Stringable
        {
            public function __toString(): string
            {
                throw new \RuntimeException('boom');
            }
        };

        $errors = $this->captureErrorLog(function () use ($path, $message): void {
            (new JsonLinesLogger($path))->debug($message);
        });

        $this->assertStringContainsString('boom', $errors);
        $this->assertFileDoesNotExist($path);
    }

    public function test_it_reports_a_context_value_that_cannot_be_encoded(): void
    {
        $path = $this->dir . '/finvalda.log';
        $handle = fopen('php://memory', 'r');

        $errors = $this->captureErrorLog(function () use ($path, $handle): void {
            (new JsonLinesLogger($path))->debug('Finvalda API request', ['handle' => $handle]);
        });

        fclose($handle);

        $this->assertStringContainsString('Finvalda JsonLinesLogger:', $errors);
        $this->assertFileDoesNotExist($path);
    }

    public function test_it_records_a_real_request_and_response_cycle_as_two_lines(): void
    {
        $path = $this->dir . '/finvalda.log';
        $guzzle = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode(['AccessResult' => 'Success', 'Data' => []])),
            ])),
        ]);

        $httpClient = new HttpClient(new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'demo',
            password: 'secret',
            logger: new JsonLinesLogger($path),
        ), $guzzle);

        $httpClient->get('GetPrekes', ['sKodas' => 'ABC']);

        $lines = array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES),
        );

        $this->assertCount(2, $lines);

        $this->assertSame('Finvalda API request', $lines[0]['message']);
        $this->assertSame('GET', $lines[0]['method']);
        $this->assertSame('GetPrekes', $lines[0]['endpoint']);
        $this->assertSame('ABC', $lines[0]['params']['sKodas']);

        $this->assertSame('Finvalda API response', $lines[1]['message']);
        $this->assertSame(200, $lines[1]['status_code']);
        $this->assertStringContainsString('AccessResult', $lines[1]['body']);
        $this->assertSame($lines[0]['pid'], $lines[1]['pid']);
    }

    /**
     * Run $work with error_log() redirected to a file, and return what it wrote.
     */
    private function captureErrorLog(callable $work): string
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0o775, true);
        }

        $errorLog = $this->dir . '/php-error.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $work();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        return is_file($errorLog) ? (string) file_get_contents($errorLog) : '';
    }
}
