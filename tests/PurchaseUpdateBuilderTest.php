<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Builders\ProductLine;
use Finvalda\Builders\PurchaseUpdateBuilder;
use Finvalda\Builders\ServiceLine;
use Finvalda\Exceptions\ConflictException;
use Finvalda\Exceptions\ValidationException;
use Finvalda\Finvalda;
use Finvalda\FinvaldaConfig;
use Finvalda\Tests\Concerns\CreatesMockHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Purchase corrections (KoregPirkDok via UpdateOperation).
 *
 * The correction envelope is NOT the insert envelope: it has its own wrapper
 * (sZurnalas + nNumeris), its own header node (PirkDokHeadEil, a documented
 * subset that excludes sKlientas and every date field), and two delete nodes
 * with no insert-path equivalent.
 */
class PurchaseUpdateBuilderTest extends TestCase
{
    use CreatesMockHttpClient;

    private function finvalda(array $responses = [], array &$history = []): Finvalda
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        );

        return new Finvalda($config, $this->createHttpClient($responses, $history));
    }

    // --- Envelope shape ---

    public function test_build_wraps_the_target_operation_under_koreg_pirk_dok(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeProduct('WSM000001TB061527')
            ->build();

        $this->assertSame(['KoregPirkDok'], array_keys($payload));
        $this->assertSame('PIRKNAU', $payload['KoregPirkDok']['sZurnalas']);
        $this->assertSame(1421, $payload['KoregPirkDok']['nNumeris']);
    }

    public function test_header_fields_land_in_the_pirk_dok_head_eil_node(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->header(['sDokumentas' => 'INV-42'])
            ->header(['sPastaba' => 'corrected'])
            ->build()['KoregPirkDok'];

        $this->assertSame(
            ['sDokumentas' => 'INV-42', 'sPastaba' => 'corrected'],
            $payload['PirkDokHeadEil'],
        );
    }

    public function test_header_node_is_omitted_when_no_header_fields_are_set(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeService('TRANSP')
            ->build()['KoregPirkDok'];

        $this->assertArrayNotHasKey('PirkDokHeadEil', $payload);
    }

    public function test_additional_cost_codes_land_in_the_header_node(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->additionalCostCodes([2 => 'TRANSP'])
            ->build()['KoregPirkDok'];

        $this->assertSame('TRANSP', $payload['PirkDokHeadEil']['sPapIslaiduKodas2']);
    }

    public function test_remove_product_emits_a_del_preke_det_eil_row(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeProduct('WSM000001TB061527', 'WH01')
            ->build()['KoregPirkDok'];

        $this->assertSame(
            [['sKodas' => 'WSM000001TB061527', 'sSandelis' => 'WH01']],
            $payload['DelPrekeDetEil'],
        );
    }

    public function test_remove_product_omits_the_warehouse_when_not_given(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeProduct('WSM000001TB061527')
            ->build()['KoregPirkDok'];

        $this->assertSame([['sKodas' => 'WSM000001TB061527']], $payload['DelPrekeDetEil']);
    }

    public function test_remove_service_emits_a_del_paslauga_det_eil_row(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeService('TRANSP')
            ->build()['KoregPirkDok'];

        $this->assertSame([['sKodas' => 'TRANSP']], $payload['DelPaslaugaDetEil']);
    }

    public function test_product_lines_use_the_purchase_detail_key(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->product(
                ProductLine::make('WSM000001TB061527', 1)
                    ->warehouse('WH01')
                    ->amount(38_500.00)
                    ->additionalCost(2, 1_270.00)
            )
            ->build()['KoregPirkDok'];

        $line = $payload['PirkDokPrekeDetEil'][0];
        $this->assertSame('WSM000001TB061527', $line['sKodas']);
        $this->assertSame('WH01', $line['sSandelis']);
        $this->assertSame(38_500.00, $line['dSumaV']);
        $this->assertSame(1_270.00, $line['dPapIsldSumaV2']);
    }

    public function test_service_lines_use_the_purchase_service_detail_key(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->service(ServiceLine::make('TRANSP', 1)->amount(950.00))
            ->build()['KoregPirkDok'];

        $line = $payload['PirkDokPaslaugaDetEil'][0];
        $this->assertSame('TRANSP', $line['sKodas']);
        $this->assertSame(100, $line['nKiekis']);
        $this->assertSame(950.00, $line['dSumaV']);
    }

    public function test_nodes_appear_in_documented_order(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->product(ProductLine::make('P1', 1)->warehouse('WH01')->amount(10.0))
            ->service(ServiceLine::make('S1', 1)->amount(5.0))
            ->removeProduct('P1', 'WH01')
            ->removeService('S1')
            ->header(['sPastaba' => 'x'])
            ->build()['KoregPirkDok'];

        $this->assertSame([
            'sZurnalas',
            'nNumeris',
            'PirkDokHeadEil',
            'DelPrekeDetEil',
            'DelPaslaugaDetEil',
            'PirkDokPrekeDetEil',
            'PirkDokPaslaugaDetEil',
        ], array_keys($payload));
    }

    // --- Envelope validation ---

    public function test_build_requires_a_journal(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('journal() is required for a purchase correction');

        (new PurchaseUpdateBuilder())->number(1421)->removeService('X')->build();
    }

    public function test_build_requires_an_operation_number(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('number() is required for a purchase correction');

        (new PurchaseUpdateBuilder())->journal('PIRKNAU')->removeService('X')->build();
    }

    public function test_build_rejects_an_empty_correction(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has nothing to change');

        (new PurchaseUpdateBuilder())->journal('PIRKNAU')->number(1421)->build();
    }

    // --- Header validation ---

    public function test_header_rejects_the_client_code(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("'sKlientas' cannot be changed by a purchase correction");

        (new PurchaseUpdateBuilder())->header(['sKlientas' => 'SUP002']);
    }

    public function test_header_rejects_an_operation_date(): void
    {
        // PirkDokHeadEil defines no operation-date field, so tData would be silently dropped.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("'tData' is not a PirkDokHeadEil field");

        (new PurchaseUpdateBuilder())->header(['tData' => '2026-07-28']);
    }

    public function test_header_rejects_object_levels_above_four(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("'sObjektas5' is not a PirkDokHeadEil field");

        (new PurchaseUpdateBuilder())->header(['sObjektas5' => 'X']);
    }

    public function test_header_rejects_waybill_fields_on_a_purchase(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("'sVairuotojas' is a waybill field");

        (new PurchaseUpdateBuilder())->header(['sVairuotojas' => 'Jonas']);
    }

    public function test_header_accepts_documented_fields(): void
    {
        $payload = (new PurchaseUpdateBuilder())
            ->journal('PIRKNAU')
            ->number(1421)
            ->header([
                'sSerija' => 'A',
                'sDokumentas' => 'INV-42',
                'sDokRusis' => 'SF',
                'sObjektas4' => 'OBJ',
                'tMokejimoData' => '2026-08-01',
                'nPozymis' => 1,
            ])
            ->build()['KoregPirkDok'];

        $this->assertCount(6, $payload['PirkDokHeadEil']);
    }

    // --- Detail-row validation ---

    public function test_remove_product_rejects_an_over_long_code(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds 20 characters');

        (new PurchaseUpdateBuilder())->removeProduct(str_repeat('X', 21));
    }

    public function test_remove_product_rejects_an_over_long_warehouse(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds 10 characters');

        (new PurchaseUpdateBuilder())->removeProduct('P1', str_repeat('W', 11));
    }

    public function test_remove_service_rejects_an_over_long_code(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds 20 characters');

        (new PurchaseUpdateBuilder())->removeService(str_repeat('X', 21));
    }

    public function test_product_line_without_a_warehouse_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sSandelis');

        (new PurchaseUpdateBuilder())->product(ProductLine::make('P1', 1)->amount(10.0));
    }

    public function test_product_line_without_amounts_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('dSumaV');

        (new PurchaseUpdateBuilder())->product(ProductLine::make('P1', 1)->warehouse('WH01'));
    }

    public function test_service_line_without_amounts_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('dSumaV');

        (new PurchaseUpdateBuilder())->service(ServiceLine::make('S1', 1));
    }

    // --- Posting ---

    public function test_save_posts_update_operation_with_the_purchase_correction_class(): void
    {
        $history = [];
        $finvalda = $this->finvalda([
            $this->jsonResponse(['AccessResult' => 'Success', 'nResult' => 0]),
        ], $history);

        $result = $finvalda->purchaseUpdate()
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeProduct('P1', 'WH01')
            ->product(ProductLine::make('P1', 1)->warehouse('WH01')->amount(10.0))
            ->save('PIRKNAU');

        $this->assertTrue($result->success);

        $request = $history[0]['request'];
        $this->assertStringContainsString('UpdateOperation', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('KoregPirkDok', $body['ItemClassName']);
        $this->assertSame('PIRKNAU', $body['sParametras']);

        $envelope = json_decode($body['xmlstring'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['KoregPirkDok'], array_keys($envelope));
        $this->assertSame(1421, $envelope['KoregPirkDok']['nNumeris']);
    }

    public function test_purchase_update_accessor_returns_a_wired_builder(): void
    {
        $builder = $this->finvalda()->purchaseUpdate();

        $this->assertInstanceOf(PurchaseUpdateBuilder::class, $builder);
    }

    public function test_save_without_a_finvalda_instance_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Finvalda instance not set');

        (new PurchaseUpdateBuilder())->journal('J')->number(1)->removeService('S')->save('P');
    }

    // --- Sold-stock guard ---

    public function test_assert_not_sold_throws_when_a_touched_product_has_been_sold(): void
    {
        $finvalda = $this->finvalda([
            $this->jsonResponse([
                'AccessResult' => 'Success',
                'items' => [
                    ['op_rusis_pav' => 'Pirkimai', 'zurnalas' => 'PIRKNAU', 'op_numeris' => 1421, 'op_data' => '2026-07-01', 'sandelis' => 'WH01'],
                    ['op_rusis_pav' => 'Pardavimai', 'zurnalas' => 'PARD1', 'op_numeris' => 77, 'op_data' => '2026-07-20', 'sandelis' => 'WH01'],
                ],
            ]),
        ]);

        $builder = $finvalda->purchaseUpdate()
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeProduct('WSM000001TB061527', 'WH01')
            ->product(ProductLine::make('WSM000001TB061527', 1)->warehouse('WH01')->amount(10.0));

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage("Product 'WSM000001TB061527' has been sold");

        $builder->assertNotSold();
    }

    public function test_assert_not_sold_passes_for_unsold_stock(): void
    {
        $finvalda = $this->finvalda([
            $this->jsonResponse([
                'AccessResult' => 'Success',
                'items' => [
                    ['op_rusis_pav' => 'Pirkimai', 'zurnalas' => 'PIRKNAU', 'op_numeris' => 1421, 'op_data' => '2026-07-01', 'sandelis' => 'WH01'],
                ],
            ]),
        ]);

        $builder = $finvalda->purchaseUpdate()
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeProduct('WSM000001TB061527', 'WH01');

        $this->assertSame($builder, $builder->assertNotSold());
    }

    public function test_assert_not_sold_checks_each_product_code_once(): void
    {
        $history = [];
        $finvalda = $this->finvalda([
            $this->jsonResponse([
                'AccessResult' => 'Success',
                'items' => [
                    ['op_rusis_pav' => 'Pirkimai', 'zurnalas' => 'PIRKNAU', 'op_numeris' => 1421, 'op_data' => '2026-07-01', 'sandelis' => 'WH01'],
                ],
            ]),
        ], $history);

        $finvalda->purchaseUpdate()
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeProduct('P1', 'WH01')
            ->product(ProductLine::make('P1', 1)->warehouse('WH01')->amount(10.0))
            ->assertNotSold();

        $this->assertCount(1, $history);
    }

    public function test_assert_not_sold_fails_closed_when_the_history_cannot_be_resolved(): void
    {
        // A guard on a destructive call must not pass just because the lookup came
        // back empty or failed.
        $finvalda = $this->finvalda([
            $this->jsonResponse(['AccessResult' => 'Fail', 'error' => 'boom']),
        ]);

        $builder = $finvalda->purchaseUpdate()
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeProduct('WSM000001TB061527', 'WH01');

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage("no purchase operation could be resolved for 'WSM000001TB061527'");

        $builder->assertNotSold();
    }

    public function test_assert_not_sold_ignores_service_only_corrections(): void
    {
        $history = [];
        $finvalda = $this->finvalda([], $history);

        $finvalda->purchaseUpdate()
            ->journal('PIRKNAU')
            ->number(1421)
            ->removeService('TRANSP')
            ->assertNotSold();

        $this->assertCount(0, $history);
    }
}
