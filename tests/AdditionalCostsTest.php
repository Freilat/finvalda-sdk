<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Builders\ProductLine;
use Finvalda\Builders\PurchaseBuilder;
use Finvalda\Builders\PurchaseOrderBuilder;
use Finvalda\Builders\PurchaseReturnBuilder;
use Finvalda\Builders\ServiceLine;
use Finvalda\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Additional purchase costs (papildomos išlaidos).
 *
 * Two halves of one feature: the header declares up to four cost buckets
 * (sPapIslaiduKodas1..4, spec "Tik PirkDok ir PirkUzsDok") and each product
 * line allocates amounts to them (dPapIsldSuma{L,V}1..4, spec
 * "Tik PirkDokPrekeDetEil"). The slot NUMBER binds the two together.
 */
class AdditionalCostsTest extends TestCase
{
    // --- Header bucket codes (sPapIslaiduKodas1..4) ---

    public function test_ordered_codes_fill_slots_one_through_four(): void
    {
        $payload = (new PurchaseBuilder())
            ->additionalCostCodes(['KITOS', 'TRANSP', 'ILGALSAV', 'DRAUDIM'])
            ->build()['PirkDok'];

        $this->assertSame('KITOS', $payload['sPapIslaiduKodas1']);
        $this->assertSame('TRANSP', $payload['sPapIslaiduKodas2']);
        $this->assertSame('ILGALSAV', $payload['sPapIslaiduKodas3']);
        $this->assertSame('DRAUDIM', $payload['sPapIslaiduKodas4']);
    }

    public function test_slot_keyed_map_sets_only_the_named_slots(): void
    {
        $payload = (new PurchaseBuilder())
            ->additionalCostCodes([2 => 'TRANSP', 4 => 'DRAUDIM'])
            ->build()['PirkDok'];

        $this->assertSame('TRANSP', $payload['sPapIslaiduKodas2']);
        $this->assertSame('DRAUDIM', $payload['sPapIslaiduKodas4']);
        $this->assertArrayNotHasKey('sPapIslaiduKodas1', $payload);
        $this->assertArrayNotHasKey('sPapIslaiduKodas3', $payload);
    }

    public function test_purchase_order_supports_cost_codes(): void
    {
        $payload = (new PurchaseOrderBuilder())
            ->additionalCostCodes(['TRANSP'])
            ->build()['PirkUzsDok'];

        $this->assertSame('TRANSP', $payload['sPapIslaiduKodas1']);
    }

    public function test_purchase_return_has_no_cost_codes(): void
    {
        // Spec: "Tik PirkDok ir PirkUzsDok" — PirkGrazDok has no such fields.
        $this->assertFalse(method_exists(PurchaseReturnBuilder::class, 'additionalCostCodes'));
    }

    public function test_more_than_four_codes_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('additionalCostCodes() accepts at most 4 codes, 5 given');

        (new PurchaseBuilder())->additionalCostCodes(['A', 'B', 'C', 'D', 'E']);
    }

    public function test_slot_outside_one_to_four_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('additionalCostCodes() slot must be 1-4, 7 given');

        (new PurchaseBuilder())->additionalCostCodes([7 => 'TRANSP']);
    }

    public function test_code_longer_than_ten_characters_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Additional cost code 'ILGALAIKIS_SAV' exceeds 10 characters (slot 3)");

        (new PurchaseBuilder())->additionalCostCodes([3 => 'ILGALAIKIS_SAV']);
    }

    public function test_empty_code_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Additional cost code must be a non-empty string (slot 1)');

        (new PurchaseBuilder())->additionalCostCodes(['']);
    }

    public function test_short_purchase_rejects_cost_codes_at_build_time(): void
    {
        // short() can be called after additionalCostCodes(), so the check is deferred
        // to build(). TrumpasPirkDok has no additional-cost fields at all.
        $builder = (new PurchaseBuilder())->additionalCostCodes(['TRANSP'])->short();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('additionalCostCodes() is not supported on TrumpasPirkDok');

        $builder->build();
    }

    public function test_short_purchase_order_rejects_cost_codes_at_build_time(): void
    {
        $builder = (new PurchaseOrderBuilder())->short()->additionalCostCodes(['TRANSP']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('additionalCostCodes() is not supported on TrumpasPirkUzsDok');

        $builder->build();
    }

    public function test_short_purchase_builds_when_no_cost_codes_are_set(): void
    {
        $payload = (new PurchaseBuilder())->short()->client('SUP001')->build();

        $this->assertArrayHasKey('TrumpasPirkDok', $payload);
    }

    // --- Per-line allocation (dPapIsldSuma{L,V}1..4) ---

    public function test_additional_cost_writes_both_currency_and_local_amounts(): void
    {
        $line = ProductLine::make('WSM000001TB061527', 1)->additionalCost(2, 950.00)->toArray();

        $this->assertSame(950.00, $line['dPapIsldSumaV2']);
        $this->assertSame(950.00, $line['dPapIsldSumaL2']);
    }

    public function test_additional_cost_accepts_an_explicit_local_amount(): void
    {
        $line = ProductLine::make('A', 1)->additionalCost(1, 100.00, 345.28)->toArray();

        $this->assertSame(100.00, $line['dPapIsldSumaV1']);
        $this->assertSame(345.28, $line['dPapIsldSumaL1']);
    }

    public function test_additional_cost_rejects_a_slot_outside_one_to_four(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('additionalCost() slot must be 1-4, 0 given');

        ProductLine::make('A', 1)->additionalCost(0, 10.00);
    }

    public function test_additional_costs_allocates_several_slots_at_once(): void
    {
        $line = ProductLine::make('A', 1)->additionalCosts([3 => 120.00, 4 => 310.00])->toArray();

        $this->assertSame(120.00, $line['dPapIsldSumaV3']);
        $this->assertSame(120.00, $line['dPapIsldSumaL3']);
        $this->assertSame(310.00, $line['dPapIsldSumaV4']);
        $this->assertSame(310.00, $line['dPapIsldSumaL4']);
    }

    public function test_additional_costs_rejects_an_invalid_slot_in_the_map(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('additionalCost() slot must be 1-4, 5 given');

        ProductLine::make('A', 1)->additionalCosts([1 => 10.00, 5 => 20.00]);
    }

    public function test_service_lines_have_no_additional_cost_allocation(): void
    {
        // Spec marks all eight fields "Tik PirkDokPrekeDetEil".
        $this->assertFalse(method_exists(ServiceLine::class, 'additionalCost'));
        $this->assertFalse(method_exists(ServiceLine::class, 'additionalCosts'));
    }

    public function test_header_buckets_and_line_amounts_share_the_slot_number(): void
    {
        $payload = (new PurchaseBuilder())
            ->client('SUP001')
            ->warehouse('WH01')
            ->additionalCostCodes([2 => 'TRANSP'])
            ->product(
                ProductLine::make('WSM000001TB061527', 1)
                    ->warehouse('WH01')
                    ->amount(38_500.00)
                    ->additionalCost(2, 950.00)
            )
            ->build()['PirkDok'];

        $this->assertSame('TRANSP', $payload['sPapIslaiduKodas2']);
        $this->assertSame(950.00, $payload['PirkDokPrekeDetEil'][0]['dPapIsldSumaV2']);
    }
}
