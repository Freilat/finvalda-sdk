<?php

declare(strict_types=1);

namespace Finvalda\Tests\Logging;

use Finvalda\Logging\JsonLinesLogger;
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

    public function test_each_entry_carries_a_timestamp_and_level(): void
    {
        $path = $this->dir . '/finvalda.log';

        (new JsonLinesLogger($path))->debug('Finvalda API request');

        $entry = json_decode(trim(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('debug', $entry['level']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['ts']);
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

    public function test_it_creates_the_log_directory(): void
    {
        $path = $this->dir . '/nested/deeper/finvalda.log';

        (new JsonLinesLogger($path))->debug('Finvalda API request');

        $this->assertFileExists($path);
    }

    public function test_it_does_not_throw_when_the_path_cannot_be_written(): void
    {
        mkdir($this->dir, 0o775, true);
        $blocker = $this->dir . '/blocker';
        touch($blocker);

        // A file where a directory is expected: mkdir and the write both fail.
        (new JsonLinesLogger($blocker . '/finvalda.log'))->debug('Finvalda API request');

        $this->assertSame('', file_get_contents($blocker));
    }
}
