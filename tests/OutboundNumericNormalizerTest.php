<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Support\OutboundNumericNormalizer;
use PHPUnit\Framework\TestCase;

class OutboundNumericNormalizerTest extends TestCase
{
    public function test_it_strips_binary_float_artifacts_from_any_field_recursively(): void
    {
        $normalizer = new OutboundNumericNormalizer(enabled: true, precision: 10);

        $result = $normalizer->normalize([
            'dSumaV' => 0.1 + 0.2,
            'nested' => [
                'dKaina' => 1.1 + 2.2,
                'nKiekis' => 0.1 + 0.7,
            ],
        ]);

        $this->assertSame(0.3, $result['dSumaV']);
        $this->assertSame(3.3, $result['nested']['dKaina']);
        $this->assertSame(0.8, $result['nested']['nKiekis']);
    }

    public function test_it_preserves_genuine_decimal_precision(): void
    {
        $normalizer = new OutboundNumericNormalizer(enabled: true, precision: 10);

        $result = $normalizer->normalize([
            'dKaina' => 12.345678,
            'nKiekis' => 1.234567,
            'dPVM_Procentas' => 21.123456,
        ]);

        $this->assertSame(12.345678, $result['dKaina']);
        $this->assertSame(1.234567, $result['nKiekis']);
        $this->assertSame(21.123456, $result['dPVM_Procentas']);
    }

    public function test_it_leaves_non_float_values_unchanged(): void
    {
        $normalizer = new OutboundNumericNormalizer(enabled: true, precision: 10);

        $result = $normalizer->normalize([
            'sKodas' => 'PRD001',
            'nNumeris' => 42,
            'bGaliojimas' => true,
            'tData' => null,
        ]);

        $this->assertSame('PRD001', $result['sKodas']);
        $this->assertSame(42, $result['nNumeris']);
        $this->assertTrue($result['bGaliojimas']);
        $this->assertNull($result['tData']);
    }

    public function test_it_returns_values_unchanged_when_disabled(): void
    {
        $normalizer = new OutboundNumericNormalizer(enabled: false, precision: 10);

        $input = [
            'dSumaV' => 0.1 + 0.2,
            'nested' => [
                'dKaina' => 1.1 + 2.2,
            ],
        ];

        $this->assertSame($input, $normalizer->normalize($input));
    }

    public function test_precision_is_configurable_and_applies_to_every_field(): void
    {
        $normalizer = new OutboundNumericNormalizer(enabled: true, precision: 2);

        $result = $normalizer->normalize([
            'dKaina' => 12.3456,
            'nKiekis' => 12.3456,
        ]);

        $this->assertSame(12.35, $result['dKaina']);
        $this->assertSame(12.35, $result['nKiekis']);
    }
}
