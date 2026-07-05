<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Builders\CapitalizationBuilder;
use Finvalda\Builders\ClearingBuilder;
use Finvalda\Builders\DisbursementBuilder;
use Finvalda\Builders\InflowBuilder;
use Finvalda\Builders\InternalTransferBuilder;
use Finvalda\Builders\InventoryCountBuilder;
use Finvalda\Builders\NonAnalyticalBuilder;
use Finvalda\Builders\ProductionBuilder;
use Finvalda\Builders\PurchaseBuilder;
use Finvalda\Builders\PurchaseOrderBuilder;
use Finvalda\Builders\PurchaseReturnBuilder;
use Finvalda\Builders\SaleBuilder;
use Finvalda\Builders\SalesReservationBuilder;
use Finvalda\Builders\SalesReturnBuilder;
use Finvalda\Builders\UvmCancellationBuilder;
use Finvalda\Builders\UvmPurchaseOrderBuilder;
use Finvalda\Builders\UvmSalesReservationBuilder;
use Finvalda\Builders\WriteOffBuilder;
use Finvalda\Enums\OperationClass;
use Finvalda\Finvalda;
use Finvalda\FinvaldaConfig;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    private Finvalda $finvalda;

    protected function setUp(): void
    {
        $config = new FinvaldaConfig(
            baseUrl: 'https://example.com',
            username: 'user',
            password: 'pass',
        );

        $this->finvalda = new Finvalda($config);
    }

    // --- Factory method tests ---

    public function test_sale_returns_sale_builder(): void
    {
        $this->assertInstanceOf(SaleBuilder::class, $this->finvalda->sale());
    }

    public function test_sales_reservation_returns_builder(): void
    {
        $this->assertInstanceOf(SalesReservationBuilder::class, $this->finvalda->salesReservation());
    }

    public function test_sales_return_returns_builder(): void
    {
        $this->assertInstanceOf(SalesReturnBuilder::class, $this->finvalda->salesReturn());
    }

    public function test_purchase_returns_builder(): void
    {
        $this->assertInstanceOf(PurchaseBuilder::class, $this->finvalda->purchase());
    }

    public function test_purchase_order_returns_builder(): void
    {
        $this->assertInstanceOf(PurchaseOrderBuilder::class, $this->finvalda->purchaseOrder());
    }

    public function test_purchase_return_returns_builder(): void
    {
        $this->assertInstanceOf(PurchaseReturnBuilder::class, $this->finvalda->purchaseReturn());
    }

    public function test_internal_transfer_returns_builder(): void
    {
        $this->assertInstanceOf(InternalTransferBuilder::class, $this->finvalda->internalTransfer());
    }

    public function test_inflow_returns_builder(): void
    {
        $this->assertInstanceOf(InflowBuilder::class, $this->finvalda->inflow());
    }

    public function test_disbursement_returns_builder(): void
    {
        $this->assertInstanceOf(DisbursementBuilder::class, $this->finvalda->disbursement());
    }

    public function test_write_off_returns_builder(): void
    {
        $this->assertInstanceOf(WriteOffBuilder::class, $this->finvalda->writeOff());
    }

    public function test_capitalization_returns_builder(): void
    {
        $this->assertInstanceOf(CapitalizationBuilder::class, $this->finvalda->capitalization());
    }

    public function test_clearing_returns_builder(): void
    {
        $this->assertInstanceOf(ClearingBuilder::class, $this->finvalda->clearing());
    }

    public function test_production_returns_builder(): void
    {
        $this->assertInstanceOf(ProductionBuilder::class, $this->finvalda->production());
    }

    public function test_non_analytical_returns_builder(): void
    {
        $this->assertInstanceOf(NonAnalyticalBuilder::class, $this->finvalda->nonAnalytical());
    }

    public function test_inventory_count_returns_builder(): void
    {
        $this->assertInstanceOf(InventoryCountBuilder::class, $this->finvalda->inventoryCount());
    }

    public function test_uvm_sales_reservation_returns_builder(): void
    {
        $this->assertInstanceOf(UvmSalesReservationBuilder::class, $this->finvalda->uvmSalesReservation());
    }

    public function test_uvm_cancellation_returns_builder(): void
    {
        $this->assertInstanceOf(UvmCancellationBuilder::class, $this->finvalda->uvmCancellation());
    }

    public function test_uvm_purchase_order_returns_builder(): void
    {
        $this->assertInstanceOf(UvmPurchaseOrderBuilder::class, $this->finvalda->uvmPurchaseOrder());
    }

    // --- Operation class tests ---

    public function test_sale_builder_operation_class(): void
    {
        $builder = new SaleBuilder();
        $this->assertSame(OperationClass::Sale, $builder->getOperationClass());
    }

    public function test_sale_builder_short_operation_class(): void
    {
        $builder = new SaleBuilder();
        $builder->short();
        $this->assertSame(OperationClass::SaleShort, $builder->getOperationClass());
    }

    public function test_sales_reservation_builder_short_operation_class(): void
    {
        $builder = new SalesReservationBuilder();
        $builder->short();
        $this->assertSame(OperationClass::SalesReservationShort, $builder->getOperationClass());
    }

    public function test_sales_return_builder_short_operation_class(): void
    {
        $builder = new SalesReturnBuilder();
        $builder->short();
        $this->assertSame(OperationClass::SalesReturnShort, $builder->getOperationClass());
    }

    public function test_purchase_builder_short_operation_class(): void
    {
        $builder = new PurchaseBuilder();
        $builder->short();
        $this->assertSame(OperationClass::PurchaseShort, $builder->getOperationClass());
    }

    public function test_purchase_order_builder_short_operation_class(): void
    {
        $builder = new PurchaseOrderBuilder();
        $builder->short();
        $this->assertSame(OperationClass::PurchaseOrderShort, $builder->getOperationClass());
    }

    public function test_purchase_return_builder_short_operation_class(): void
    {
        $builder = new PurchaseReturnBuilder();
        $builder->short();
        $this->assertSame(OperationClass::PurchaseReturnShort, $builder->getOperationClass());
    }

    public function test_short_can_be_toggled_off(): void
    {
        $builder = new SaleBuilder();
        $builder->short();
        $this->assertSame(OperationClass::SaleShort, $builder->getOperationClass());

        $builder->short(false);
        $this->assertSame(OperationClass::Sale, $builder->getOperationClass());
    }

    // --- Build output tests ---

    public function test_sale_builder_builds_correct_structure(): void
    {
        $data = (new SaleBuilder())
            ->client('CLI001')
            ->date('2024-01-15')
            ->warehouse('MAIN')
            ->currency('EUR')
            ->paymentDays(30)
            ->discount(5.0)
            ->vatIncluded()
            ->series('SF')
            ->fulfillmentDate('2024-01-20')
            ->addProduct('PRD001', quantity: 10, price: 19.99)
            ->addService('SVC001', quantity: 1, amount: 50.00)
            ->build();

        // build() returns the operation wrapped under its class key, with
        // detail-row arrays nested INSIDE the wrapper (siblings of header fields).
        $this->assertArrayHasKey('PardDok', $data);
        $this->assertSame('CLI001', $data['PardDok']['sKlientas']);
        $this->assertSame('2024-01-15', $data['PardDok']['tData']);
        $this->assertSame('MAIN', $data['PardDok']['sSandelis']);
        $this->assertSame('EUR', $data['PardDok']['sValiuta']);
        $this->assertSame(30, $data['PardDok']['nAtsiskDien']);
        $this->assertSame(5.0, $data['PardDok']['dNuolaida']);
        $this->assertTrue($data['PardDok']['bPVMSkaiciuotiIKaina']);
        $this->assertSame('SF', $data['PardDok']['sSerija']);
        $this->assertSame('2024-01-20', $data['PardDok']['tIvykdymoData']);

        $this->assertArrayHasKey('PardDokPrekeDetEil', $data['PardDok']);
        $this->assertCount(1, $data['PardDok']['PardDokPrekeDetEil']);
        $this->assertSame('PRD001', $data['PardDok']['PardDokPrekeDetEil'][0]['sKodas']);

        $this->assertArrayHasKey('PardDokPaslaugaDetEil', $data['PardDok']);
        $this->assertCount(1, $data['PardDok']['PardDokPaslaugaDetEil']);
        $this->assertSame('SVC001', $data['PardDok']['PardDokPaslaugaDetEil'][0]['sKodas']);
    }

    public function test_sale_short_builds_with_short_header_key(): void
    {
        $data = (new SaleBuilder())
            ->short()
            ->client('CLI001')
            ->date('2024-01-15')
            ->series('SF')
            ->documentType('S')
            ->addProduct('PRD001', quantity: 10, price: 19.99)
            ->build();

        $this->assertArrayHasKey('TrumpasPardDok', $data);
        $this->assertArrayNotHasKey('PardDok', $data);
        $this->assertSame('CLI001', $data['TrumpasPardDok']['sKlientas']);
        $this->assertSame('SF', $data['TrumpasPardDok']['sSerija']);
        $this->assertSame('S', $data['TrumpasPardDok']['sDokRusis']);
    }

    public function test_sales_return_short_uses_pard_dok_line_keys(): void
    {
        $data = (new SalesReturnBuilder())
            ->short()
            ->client('CLI001')
            ->date('2024-01-20')
            ->addProduct('PRD001', quantity: 2, price: 19.99)
            ->build();

        $this->assertArrayHasKey('TrumpasPardGrazDok', $data);
        $this->assertArrayHasKey('PardDokPrekeDetEil', $data['TrumpasPardGrazDok']);
        $this->assertArrayNotHasKey('PardGrazDokPrekeDetEil', $data['TrumpasPardGrazDok']);
    }

    public function test_sales_return_full_uses_shared_pard_dok_line_keys(): void
    {
        $data = (new SalesReturnBuilder())
            ->client('CLI001')
            ->date('2024-01-20')
            ->addProduct('PRD001', quantity: 2, price: 19.99)
            ->addService('SRV001', quantity: 1, price: 5.00)
            ->build();

        $this->assertArrayHasKey('PardGrazDok', $data);
        // The FVS spec defines one shared set of detail elements for the whole
        // sales family; PardGrazDok*DetEil does not exist and is silently ignored.
        $this->assertArrayHasKey('PardDokPrekeDetEil', $data['PardGrazDok']);
        $this->assertArrayHasKey('PardDokPaslaugaDetEil', $data['PardGrazDok']);
        $this->assertArrayNotHasKey('PardGrazDokPrekeDetEil', $data['PardGrazDok']);
        $this->assertArrayNotHasKey('PardGrazDokPaslaugaDetEil', $data['PardGrazDok']);
    }

    public function test_purchase_return_short_uses_pirk_dok_line_keys(): void
    {
        $data = (new PurchaseReturnBuilder())
            ->short()
            ->client('SUP001')
            ->date('2024-01-20')
            ->addProduct('PRD001', quantity: 10, price: 9.99)
            ->build();

        $this->assertArrayHasKey('TrumpasPirkGrazDok', $data);
        $this->assertArrayHasKey('PirkDokPrekeDetEil', $data['TrumpasPirkGrazDok']);
        $this->assertArrayNotHasKey('PirkGrazDokPrekeDetEil', $data['TrumpasPirkGrazDok']);
    }

    public function test_purchase_return_full_uses_shared_pirk_dok_line_keys(): void
    {
        $data = (new PurchaseReturnBuilder())
            ->client('SUP001')
            ->date('2024-01-20')
            ->addProduct('PRD001', quantity: 10, price: 9.99)
            ->addService('SRV001', quantity: 1, price: 5.00)
            ->build();

        $this->assertArrayHasKey('PirkGrazDok', $data);
        // Same shared-detail-element rule as the sales family: PirkGrazDok*DetEil
        // does not exist in the FVS spec and is silently ignored by the server.
        $this->assertArrayHasKey('PirkDokPrekeDetEil', $data['PirkGrazDok']);
        $this->assertArrayHasKey('PirkDokPaslaugaDetEil', $data['PirkGrazDok']);
        $this->assertArrayNotHasKey('PirkGrazDokPrekeDetEil', $data['PirkGrazDok']);
        $this->assertArrayNotHasKey('PirkGrazDokPaslaugaDetEil', $data['PirkGrazDok']);
    }

    public function test_write_off_builder_builds_correct_structure(): void
    {
        $data = (new WriteOffBuilder())
            ->date('2024-01-15')
            ->name('Monthly write-off')
            ->note('Damaged goods')
            ->employee('John')
            ->addItem('PRD001', quantity: 5, warehouse: 'MAIN', account: '6110')
            ->build();

        $this->assertArrayHasKey('NurasymasDok', $data);
        $this->assertSame('Monthly write-off', $data['NurasymasDok']['sPavadinimas']);
        $this->assertSame('Damaged goods', $data['NurasymasDok']['sPastaba']);
        $this->assertSame('John', $data['NurasymasDok']['sDarbuotojas']);

        $this->assertArrayHasKey('NurasymasDokDetEil', $data['NurasymasDok']);
        $this->assertCount(1, $data['NurasymasDok']['NurasymasDokDetEil']);
        $this->assertSame('PRD001', $data['NurasymasDok']['NurasymasDokDetEil'][0]['sKodas']);
        $this->assertSame(5.0, $data['NurasymasDok']['NurasymasDokDetEil'][0]['nKiekis']);
        $this->assertSame('MAIN', $data['NurasymasDok']['NurasymasDokDetEil'][0]['sSandelis']);
        $this->assertSame('6110', $data['NurasymasDok']['NurasymasDokDetEil'][0]['sSaskaita']);
    }

    public function test_capitalization_builder_builds_correct_structure(): void
    {
        $data = (new CapitalizationBuilder())
            ->date('2024-01-15')
            ->name('Receiving')
            ->addItem('PRD001', quantity: 10, amount: 199.90, warehouse: 'MAIN', account: '2010')
            ->build();

        $this->assertArrayHasKey('PajamavimasDok', $data);
        $this->assertArrayHasKey('PajamavimasDokDetEil', $data['PajamavimasDok']);
        $this->assertSame(199.90, $data['PajamavimasDok']['PajamavimasDokDetEil'][0]['dSuma']);
    }

    public function test_clearing_builder_builds_correct_structure(): void
    {
        $data = (new ClearingBuilder())
            ->date('2024-01-15')
            ->name('Monthly clearing')
            ->debtor('CLI001')
            ->creditor('CLI002')
            ->addDebitLine(amount: 270.00, series: 'SF', document: '001', type: 3)
            ->addCreditLine(amount: 270.00, series: 'PF', document: '002', type: 2)
            ->addDebitAccount(amount: 100.00, account: '241000')
            ->addCreditAccount(amount: 100.00, account: '241001')
            ->build();

        $this->assertArrayHasKey('UzskaitaDok', $data);
        $this->assertSame('CLI001', $data['UzskaitaDok']['sDebitorius']);
        $this->assertSame('CLI002', $data['UzskaitaDok']['sKreditorius']);

        // Detail rows must be nested inside the class wrapper (docs & Postman
        // InsertNewOperation UzskaitaDok example), like all other operations.
        $this->assertArrayHasKey('UzskaitaDebitDetEil', $data['UzskaitaDok']);
        $this->assertCount(2, $data['UzskaitaDok']['UzskaitaDebitDetEil']);
        $this->assertSame(3, $data['UzskaitaDok']['UzskaitaDebitDetEil'][0]['nTipas']);
        $this->assertSame(6, $data['UzskaitaDok']['UzskaitaDebitDetEil'][1]['nTipas']);
        $this->assertSame('241000', $data['UzskaitaDok']['UzskaitaDebitDetEil'][1]['sSaskaita']);

        $this->assertArrayHasKey('UzskaitaKreditDetEil', $data['UzskaitaDok']);
        $this->assertCount(2, $data['UzskaitaDok']['UzskaitaKreditDetEil']);
    }

    public function test_production_builder_builds_correct_structure(): void
    {
        $data = (new ProductionBuilder())
            ->date('2024-01-15')
            ->finishedProduct('FINISHED001')
            ->documentNumber('PROD-001')
            ->description('Daily production')
            ->addFinishedGood('FINISHED001', warehouse: 'MAIN', quantity: 100, amount: 500.00)
            ->addRawMaterial('RAW001', warehouse: 'MAIN', quantity: 200)
            ->addProductionService('SVC001', amount: 100.00, quantity: 1)
            ->build();

        $this->assertArrayHasKey('GamybaDok', $data);
        $this->assertSame('FINISHED001', $data['GamybaDok']['sGaminys']);

        $this->assertArrayHasKey('GamybaGDetEil', $data['GamybaDok']);
        $this->assertCount(1, $data['GamybaDok']['GamybaGDetEil']);
        $this->assertSame(500.00, $data['GamybaDok']['GamybaGDetEil'][0]['dSuma']);

        $this->assertArrayHasKey('GamybaZDetEil', $data['GamybaDok']);
        $this->assertCount(1, $data['GamybaDok']['GamybaZDetEil']);

        $this->assertArrayHasKey('GamybaPDetEil', $data['GamybaDok']);
        $this->assertCount(1, $data['GamybaDok']['GamybaPDetEil']);
    }

    public function test_non_analytical_builder_builds_correct_structure(): void
    {
        $data = (new NonAnalyticalBuilder())
            ->date('2024-01-15')
            ->currency('EUR')
            ->documentNumber('TEST1')
            ->description1('Depreciation')
            ->addEntry('6110', 'Equipment', debitLocal: 500.00, creditLocal: 0)
            ->addEntry('1240', 'Accumulated', debitLocal: 0, creditLocal: 500.00)
            ->build();

        $this->assertArrayHasKey('KtNeanalitDok', $data);
        $this->assertSame('Depreciation', $data['KtNeanalitDok']['sPavadinimas1']);

        $this->assertArrayHasKey('KtNeanalitDetEil', $data['KtNeanalitDok']);
        $this->assertCount(2, $data['KtNeanalitDok']['KtNeanalitDetEil']);
        $this->assertSame(500.00, $data['KtNeanalitDok']['KtNeanalitDetEil'][0]['dDebetasL']);
        $this->assertSame(0.0, $data['KtNeanalitDok']['KtNeanalitDetEil'][0]['dKreditasL']);
    }

    public function test_uvm_cancellation_builder_builds_correct_structure(): void
    {
        $data = (new UvmCancellationBuilder())
            ->date('2024-01-15')
            ->name('Cancel reservation')
            ->documentNumber('ANUL-001')
            ->addCancellation(journal: 'UVMPARD', number: 123)
            ->addCancellation(journal: 'UVMPARD', number: 124)
            ->build();

        $this->assertArrayHasKey('UVMAnulDok', $data);
        $this->assertSame('Cancel reservation', $data['UVMAnulDok']['sPavadinimas']);

        $this->assertArrayHasKey('UVMAnulDokDetEil', $data['UVMAnulDok']);
        $this->assertCount(2, $data['UVMAnulDok']['UVMAnulDokDetEil']);
        $this->assertSame('UVMPARD', $data['UVMAnulDok']['UVMAnulDokDetEil'][0]['sZurnalas']);
        $this->assertSame(123, $data['UVMAnulDok']['UVMAnulDokDetEil'][0]['nNumeris']);
    }

    public function test_special_builders_reject_generic_product_and_service_lines(): void
    {
        // These builders override build() with their own line arrays; inherited
        // addProduct()/addService() lines used to be silently discarded.
        $cases = [
            [fn () => (new ClearingBuilder())->addProduct('PRD001', 1.0), 'ClearingBuilder'],
            [fn () => (new ProductionBuilder())->addService('SVC001', 1.0), 'ProductionBuilder'],
            [fn () => (new NonAnalyticalBuilder())->addProductLine(['sKodas' => 'PRD001']), 'NonAnalyticalBuilder'],
            [fn () => (new UvmCancellationBuilder())->addServiceLine(['sKodas' => 'SVC001']), 'UvmCancellationBuilder'],
            [fn () => (new InventoryCountBuilder())->addProduct('PRD001', 1.0), 'InventoryCountBuilder'],
        ];

        foreach ($cases as [$make, $builder]) {
            try {
                $make()->build();
                $this->fail("{$builder}::build() should reject generic product/service lines");
            } catch (\BadMethodCallException $e) {
                $this->assertStringContainsString('does not support', $e->getMessage());
            }
        }
    }

    public function test_inventory_count_builder_builds_correct_structure(): void
    {
        $data = (new InventoryCountBuilder())
            ->journal('INVENT')
            ->warehouse('01')
            ->date('2024-03-03')
            ->addItem('B.BENZINAS', quantity: 15.45, account: '1275')
            ->addItem('B.DYZELINAS', quantity: 20.00)
            ->build();

        $this->assertSame(0, $data['mode']);
        $this->assertArrayHasKey('Inventorizacija', $data);
        $this->assertCount(2, $data['Inventorizacija']);

        // Common header fields are merged into each item
        $this->assertSame('INVENT', $data['Inventorizacija'][0]['sZurnalas']);
        $this->assertSame('01', $data['Inventorizacija'][0]['sSandelis']);
        $this->assertSame('2024-03-03', $data['Inventorizacija'][0]['tData']);
        $this->assertSame('B.BENZINAS', $data['Inventorizacija'][0]['sKodas']);
        $this->assertSame(15.45, $data['Inventorizacija'][0]['dKiekis']);
        $this->assertSame('1275', $data['Inventorizacija'][0]['sSaskaita']);

        $this->assertSame('B.DYZELINAS', $data['Inventorizacija'][1]['sKodas']);
        $this->assertArrayNotHasKey('sSaskaita', $data['Inventorizacija'][1]);
    }

    public function test_inventory_count_builder_mode(): void
    {
        $data = (new InventoryCountBuilder())
            ->mode(1)
            ->date('2024-03-03')
            ->addItem('B.BENZINAS', quantity: 15.45)
            ->build();

        $this->assertSame(1, $data['mode']);
    }

    public function test_uvm_sales_reservation_builder_builds_correct_structure(): void
    {
        $data = (new UvmSalesReservationBuilder())
            ->client('HTNT')
            ->date('2024-01-15')
            ->operationType('PARDSERV')
            ->fulfillmentDate('2024-01-20')
            ->currency('EUR')
            ->vatIncluded()
            ->addService('5054', quantity: 1, price: 0, additionalData: [
                'sPavadinimas' => 'Test description',
            ])
            ->build();

        $this->assertArrayHasKey('UVMPardRezDok', $data);
        $this->assertSame('PARDSERV', $data['UVMPardRezDok']['sOpTipas']);
        $this->assertSame('2024-01-20', $data['UVMPardRezDok']['tIvykdymoData']);
        $this->assertTrue($data['UVMPardRezDok']['bPVMSkaiciuotiIKaina']);

        $this->assertArrayHasKey('PardDokPaslaugaDetEil', $data['UVMPardRezDok']);
        $this->assertArrayNotHasKey('UVMPardRezDokPaslaugaDetEil', $data['UVMPardRezDok']);
        $this->assertSame('Test description', $data['UVMPardRezDok']['PardDokPaslaugaDetEil'][0]['sPavadinimas']);
    }

    public function test_purchase_order_builder_builds_correct_structure(): void
    {
        $data = (new PurchaseOrderBuilder())
            ->client('SUP001')
            ->date('2024-01-15')
            ->currency('EUR')
            ->name('Order for supplies')
            ->addProduct('PRD001', quantity: 24, price: 3.50, warehouse: 'CENTR.')
            ->build();

        $this->assertArrayHasKey('PirkUzsDok', $data);
        $this->assertSame('Order for supplies', $data['PirkUzsDok']['sPavadinimas']);

        $this->assertArrayHasKey('PirkDokPrekeDetEil', $data['PirkUzsDok']);
    }

    public function test_purchase_order_short_builds_with_short_header_key(): void
    {
        $data = (new PurchaseOrderBuilder())
            ->short()
            ->client('SUP001')
            ->currency('EUR')
            ->series('UZS')
            ->addProduct('PRD001', quantity: 50, price: 5.00)
            ->build();

        $this->assertArrayHasKey('TrumpasPirkUzsDok', $data);
        $this->assertArrayNotHasKey('PirkUzsDok', $data);
        $this->assertSame('SUP001', $data['TrumpasPirkUzsDok']['sKlientas']);
        $this->assertSame('UZS', $data['TrumpasPirkUzsDok']['sSerija']);
    }

    // --- Save throws without finvalda or parameter ---

    public function test_save_throws_without_finvalda_instance(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Finvalda instance not set');

        (new SaleBuilder())->save('STANDARD');
    }

    public function test_save_throws_without_parameter(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Parameter not set');

        $this->finvalda->sale()->save();
    }

    // --- Empty build still emits the class wrapper ---

    public function test_empty_builder_produces_empty_wrapper(): void
    {
        $data = (new SaleBuilder())->build();
        $this->assertSame(['PardDok' => []], $data);
    }

    // --- Object helpers ---

    public function test_objects_sets_multiple_levels(): void
    {
        $data = (new SaleBuilder())
            ->objects([1 => 'OBJ1', 3 => 'OBJ3', 6 => 'OBJ6'])
            ->build();

        $this->assertSame('OBJ1', $data['PardDok']['sObjektas1']);
        $this->assertSame('OBJ3', $data['PardDok']['sObjektas3']);
        $this->assertSame('OBJ6', $data['PardDok']['sObjektas6']);
        $this->assertArrayNotHasKey('sObjektas2', $data['PardDok']);
    }

    // --- Document type (sDokRusis) ---

    public function test_document_type_accepts_raw_string(): void
    {
        $data = (new PurchaseBuilder())
            ->client('SUP001')
            ->date('2024-01-15')
            ->series('SF')
            ->documentType('SF')
            ->build();

        $this->assertSame('SF', $data['PirkDok']['sSerija']);
        $this->assertSame('SF', $data['PirkDok']['sDokRusis']);
    }

    public function test_document_type_accepts_enum(): void
    {
        $data = (new PurchaseBuilder())
            ->client('SUP001')
            ->date('2024-01-15')
            ->documentType(\Finvalda\Enums\DocumentType::VatInvoice)
            ->build();

        $this->assertSame('SF', $data['PirkDok']['sDokRusis']);
    }

    public function test_document_type_enum_on_sale_builder(): void
    {
        $data = (new SaleBuilder())
            ->client('CLI001')
            ->date('2024-01-15')
            ->documentType(\Finvalda\Enums\DocumentType::CreditVatInvoice)
            ->build();

        $this->assertSame('KS', $data['PardDok']['sDokRusis']);
    }

    // --- Internal transfer warehouse fields (header-level sIsSandelio/sISandeli) ---

    public function test_internal_transfer_uses_documented_warehouse_fields(): void
    {
        $data = (new InternalTransferBuilder())
            ->date('2024-01-15')
            ->fromWarehouse('MAIN')
            ->toWarehouse('BRANCH')
            ->addTransfer('PRD001', quantity: 50)
            ->build();

        $this->assertSame('MAIN', $data['VidPerkDok']['sIsSandelio']);
        $this->assertSame('BRANCH', $data['VidPerkDok']['sISandeli']);
        // The legacy/incorrect field names must not be emitted.
        $this->assertArrayNotHasKey('sSandIs', $data['VidPerkDok']);
        $this->assertArrayNotHasKey('sSandI', $data['VidPerkDok']);
    }

    public function test_internal_transfer_detail_rows_have_no_warehouse_fields(): void
    {
        $data = (new InternalTransferBuilder())
            ->date('2024-01-15')
            ->addTransfer('PRD001', quantity: 50, fromWarehouse: 'MAIN', toWarehouse: 'BRANCH')
            ->build();

        $row = $data['VidPerkDok']['VidPerkDokDetEil'][0];
        $this->assertSame('PRD001', $row['sKodas']);
        $this->assertSame(50.0, $row['nKiekis']);
        $this->assertArrayNotHasKey('sSandIs', $row);
        $this->assertArrayNotHasKey('sSandI', $row);
        $this->assertArrayNotHasKey('sIsSandelio', $row);
        $this->assertArrayNotHasKey('sISandeli', $row);

        // addTransfer() warehouse args are routed to the header.
        $this->assertSame('MAIN', $data['VidPerkDok']['sIsSandelio']);
        $this->assertSame('BRANCH', $data['VidPerkDok']['sISandeli']);
    }
}
