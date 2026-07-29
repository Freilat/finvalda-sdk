<?php

declare(strict_types=1);

namespace Finvalda\Tests\Support;

use Finvalda\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_masks_credential_keys(): void
    {
        $result = Redactor::apply([
            'UserName' => 'demo',
            'Password' => 'secret',
            'ConnString' => 'Server=db;Password=db-secret',
            'sPassword' => 'other-secret',
        ]);

        $this->assertSame('demo', $result['UserName']);
        $this->assertSame('***', $result['Password']);
        $this->assertSame('***', $result['ConnString']);
        $this->assertSame('***', $result['sPassword']);
    }

    public function test_leaves_arrays_without_credentials_untouched(): void
    {
        $values = ['sKodas' => 'ABC', 'nKiekis' => 3];

        $this->assertSame($values, Redactor::apply($values));
    }

    public function test_does_not_add_missing_keys(): void
    {
        $this->assertArrayNotHasKey('Password', Redactor::apply(['UserName' => 'demo']));
    }

    public function test_masks_only_top_level_keys(): void
    {
        $result = Redactor::apply([
            'input' => ['Password' => 'secret'],
        ]);

        $this->assertSame('secret', $result['input']['Password']);
    }

    public function test_replaces_credentials_with_shell_placeholders(): void
    {
        $result = Redactor::applyPlaceholders([
            'UserName' => 'demo',
            'Password' => 'secret',
            'ConnString' => 'Server=db',
            'sPassword' => 'other-secret',
        ]);

        $this->assertSame('demo', $result['UserName']);
        $this->assertSame('$FVS_PASSWORD', $result['Password']);
        $this->assertSame('$FVS_CONN_STRING', $result['ConnString']);
        $this->assertSame('$FVS_SPASSWORD', $result['sPassword']);
    }

    public function test_placeholders_leave_arrays_without_credentials_untouched(): void
    {
        $values = ['sKodas' => 'ABC'];

        $this->assertSame($values, Redactor::applyPlaceholders($values));
    }

    public function test_every_credential_key_has_a_placeholder(): void
    {
        foreach (Redactor::KEYS as $key) {
            $this->assertArrayHasKey($key, Redactor::PLACEHOLDERS);
        }
    }
}
