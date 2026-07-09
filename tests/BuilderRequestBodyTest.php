<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Finvalda;
use Finvalda\FinvaldaConfig;
use Finvalda\Builders\ProductLine;
use Finvalda\Tests\Concerns\CreatesMockHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the outgoing InsertNewOperation request body per builder: the exact
 * ItemClassName and the detail-element keys inside the serialized xmlstring.
 *
 * The FVS spec defines ONE shared set of detail elements per operation family
 * (PardDok*DetEil for all sales classes, PirkDok*DetEil for all purchase
 * classes). Unknown elements are silently ignored by the server, so a wrong
 * key produces error 1037 "Operation do not has detail rows!" instead of a
 * validation error — these tests exist to catch that regression.
 */
class BuilderRequestBodyTest extends TestCase
{
    use CreatesMockHttpClient;

    /**
     * @return iterable<string, array{string, bool, string, string, string, string}>
     */
    public static function builderWireFormats(): iterable
    {
        // accessor, short?, ItemClassName, header key, product lines key, service lines key
        yield 'sale full' => ['sale', false, 'PardDok', 'PardDok', 'PardDokPrekeDetEil', 'PardDokPaslaugaDetEil'];
        yield 'sale short' => ['sale', true, 'TrumpasPardDok', 'TrumpasPardDok', 'PardDokPrekeDetEil', 'PardDokPaslaugaDetEil'];
        yield 'sales reservation full' => ['salesReservation', false, 'PardRezDok', 'PardRezDok', 'PardDokPrekeDetEil', 'PardDokPaslaugaDetEil'];
        yield 'sales reservation short' => ['salesReservation', true, 'TrumpasPardRezDok', 'TrumpasPardRezDok', 'PardDokPrekeDetEil', 'PardDokPaslaugaDetEil'];
        yield 'sales return full' => ['salesReturn', false, 'PardGrazDok', 'PardGrazDok', 'PardDokPrekeDetEil', 'PardDokPaslaugaDetEil'];
        yield 'sales return short' => ['salesReturn', true, 'TrumpasPardGrazDok', 'TrumpasPardGrazDok', 'PardDokPrekeDetEil', 'PardDokPaslaugaDetEil'];
        yield 'purchase full' => ['purchase', false, 'PirkDok', 'PirkDok', 'PirkDokPrekeDetEil', 'PirkDokPaslaugaDetEil'];
        yield 'purchase short' => ['purchase', true, 'TrumpasPirkDok', 'TrumpasPirkDok', 'PirkDokPrekeDetEil', 'PirkDokPaslaugaDetEil'];
        yield 'purchase order full' => ['purchaseOrder', false, 'PirkUzsDok', 'PirkUzsDok', 'PirkDokPrekeDetEil', 'PirkDokPaslaugaDetEil'];
        yield 'purchase order short' => ['purchaseOrder', true, 'TrumpasPirkUzsDok', 'TrumpasPirkUzsDok', 'PirkDokPrekeDetEil', 'PirkDokPaslaugaDetEil'];
        yield 'purchase return full' => ['purchaseReturn', false, 'PirkGrazDok', 'PirkGrazDok', 'PirkDokPrekeDetEil', 'PirkDokPaslaugaDetEil'];
        yield 'purchase return short' => ['purchaseReturn', true, 'TrumpasPirkGrazDok', 'TrumpasPirkGrazDok', 'PirkDokPrekeDetEil', 'PirkDokPaslaugaDetEil'];
    }

    #[DataProvider('builderWireFormats')]
    public function test_builder_sends_spec_item_class_and_detail_keys(
        string $accessor,
        bool $short,
        string $itemClassName,
        string $headerKey,
        string $productLinesKey,
        string $serviceLinesKey,
    ): void {
        $history = [];
        $http = $this->createHttpClient([
            $this->jsonResponse(['AccessResult' => 'Success', 'nResult' => 0]),
        ], $history);

        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        );
        $finvalda = new Finvalda($config, $http);

        $builder = $finvalda->{$accessor}();
        if ($short) {
            $builder->short();
        }

        $builder
            ->client('CLI001')
            ->date('2024-01-20')
            ->currency('EUR')
            ->documentNumber('DOC-001')
            ->addProduct('PRD001', quantity: 2, price: 19.99)
            ->addService('SRV001', quantity: 1, price: 5.00)
            ->save('PARAM');

        $body = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($itemClassName, $body['ItemClassName']);
        $this->assertSame('PARAM', $body['sParametras']);

        $operation = json_decode($body['xmlstring'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$headerKey], array_keys($operation));

        $payload = $operation[$headerKey];
        $this->assertArrayHasKey($productLinesKey, $payload);
        $this->assertArrayHasKey($serviceLinesKey, $payload);
        $this->assertSame('PRD001', $payload[$productLinesKey][0]['sKodas']);
        $this->assertSame('SRV001', $payload[$serviceLinesKey][0]['sKodas']);

        // No non-spec GrazDok detail elements may leak into the payload.
        foreach (array_keys($payload) as $key) {
            $this->assertStringNotContainsString('GrazDokPrekeDetEil', $key);
            $this->assertStringNotContainsString('GrazDokPaslaugaDetEil', $key);
        }
    }

    public function test_builder_save_strips_float_artifacts_inside_xmlstring(): void
    {
        $history = [];
        $http = $this->createHttpClient([
            $this->jsonResponse(['AccessResult' => 'Success', 'nResult' => 0]),
        ], $history);

        $finvalda = new Finvalda(new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        ), $http);

        $finvalda->sale()
            ->client('CLI001')
            ->date('2024-01-20')
            ->product(
                ProductLine::make('PRD001', 1.1 + 2.2)
                    ->price(0.1 + 0.2)
                    ->amount(12.345678)
                    ->vat(percent: 21.123456, amount: 10.123456)
                    ->weight(neto: 2.123456)
            )
            ->save('PARAM');

        $body = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $operation = json_decode($body['xmlstring'], true, flags: JSON_THROW_ON_ERROR);
        $line = $operation['PardDok']['PardDokPrekeDetEil'][0];

        $this->assertSame(0.3, $line['dKaina']);
        $this->assertSame(3.3, $line['nKiekis']);
        $this->assertSame(12.345678, $line['dSumaV']);
        $this->assertSame(12.345678, $line['dSumaL']);
        $this->assertSame(10.123456, $line['dSumaPVMV']);
        $this->assertSame(10.123456, $line['dSumaPVML']);
        $this->assertSame(21.123456, $line['dPVM_Procentas']);
        $this->assertSame(2.123456, $line['dNeto']);
    }
}
