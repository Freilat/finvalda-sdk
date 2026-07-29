<?php

declare(strict_types=1);

namespace Finvalda\Tests\Enums;

use Finvalda\Enums\CredentialMode;
use PHPUnit\Framework\TestCase;

class CredentialModeTest extends TestCase
{
    public function test_cases_have_stable_string_values(): void
    {
        $this->assertSame('masked', CredentialMode::Masked->value);
        $this->assertSame('env', CredentialMode::Env->value);
        $this->assertSame('real', CredentialMode::Real->value);
    }

    public function test_try_from_returns_null_for_unknown_values(): void
    {
        $this->assertNull(CredentialMode::tryFrom('nonsense'));
    }
}
