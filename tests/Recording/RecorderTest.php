<?php

declare(strict_types=1);

namespace Finvalda\Tests\Recording;

use Finvalda\Enums\CredentialMode;
use Finvalda\Recording\Exchange;
use Finvalda\Recording\Recorder;
use PHPUnit\Framework\TestCase;

class RecorderTest extends TestCase
{
    private function exchange(string $marker = 'A'): Exchange
    {
        return new Exchange(
            method: 'GET',
            url: "https://example.com/FvsServicePure.svc/GetPrekes?sKodas={$marker}",
            headers: ['UserName' => 'demo', 'Password' => 'secret'],
            body: null,
            statusCode: 200,
            reasonPhrase: 'OK',
            responseHeaders: [],
            responseBody: '{"AccessResult":"Success"}',
            durationMs: 5.0,
        );
    }

    public function test_records_exchanges_oldest_first(): void
    {
        $recorder = new Recorder();
        $recorder->record($this->exchange('A'));
        $recorder->record($this->exchange('B'));

        $all = $recorder->all();

        $this->assertCount(2, $all);
        $this->assertStringContainsString('sKodas=A', $all[0]->url);
        $this->assertStringContainsString('sKodas=B', $all[1]->url);
    }

    public function test_trims_to_the_limit_keeping_the_newest(): void
    {
        $recorder = new Recorder(limit: 2);
        $recorder->record($this->exchange('A'));
        $recorder->record($this->exchange('B'));
        $recorder->record($this->exchange('C'));

        $all = $recorder->all();

        $this->assertCount(2, $all);
        $this->assertStringContainsString('sKodas=B', $all[0]->url);
        $this->assertStringContainsString('sKodas=C', $all[1]->url);
    }

    public function test_last_returns_the_newest_exchange(): void
    {
        $recorder = new Recorder();
        $recorder->record($this->exchange('A'));
        $recorder->record($this->exchange('B'));

        $this->assertStringContainsString('sKodas=B', $recorder->last()?->url ?? '');
    }

    public function test_last_is_null_on_an_empty_recorder(): void
    {
        $this->assertNull((new Recorder())->last());
    }

    public function test_masks_credentials_at_capture_time_by_default(): void
    {
        $recorder = new Recorder();
        $recorder->record($this->exchange());

        $this->assertSame('***', $recorder->last()?->headers['Password']);
    }

    public function test_uses_env_placeholders_in_env_mode(): void
    {
        $recorder = new Recorder(credentials: CredentialMode::Env);
        $recorder->record($this->exchange());

        $this->assertSame('$FVS_PASSWORD', $recorder->last()?->headers['Password']);
    }

    public function test_keeps_credentials_in_real_mode(): void
    {
        $recorder = new Recorder(credentials: CredentialMode::Real);
        $recorder->record($this->exchange());

        $this->assertSame('secret', $recorder->last()?->headers['Password']);
    }

    public function test_a_limit_below_one_still_keeps_the_latest_exchange(): void
    {
        $recorder = new Recorder(limit: 0);
        $recorder->record($this->exchange('A'));
        $recorder->record($this->exchange('B'));

        $this->assertCount(1, $recorder->all());
        $this->assertStringContainsString('sKodas=B', $recorder->last()?->url ?? '');
    }
}
