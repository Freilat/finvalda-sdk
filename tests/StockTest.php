<?php

declare(strict_types=1);

namespace Finvalda\Tests;

use Finvalda\Resources\Stock;
use Finvalda\Tests\Concerns\CreatesMockHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Stock::purchaseOpFor() — which purchase operation currently holds a product as
 * stock, and has a sale followed it. Derived from GetPrekesIstorija rows.
 */
class StockTest extends TestCase
{
    use CreatesMockHttpClient;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function stockWithHistory(array $rows, array &$history = []): Stock
    {
        return new Stock($this->createHttpClient([
            $this->jsonResponse(['AccessResult' => 'Success', 'items' => $rows]),
        ], $history));
    }

    private function purchaseRow(string $journal, int $number, string $date, string $warehouse = 'WH01'): array
    {
        return [
            'op_rusis_pav' => 'Pirkimai',
            'zurnalas' => $journal,
            'op_numeris' => $number,
            'op_data' => $date,
            'sandelis' => $warehouse,
        ];
    }

    private function saleRow(string $journal, int $number, string $date): array
    {
        return [
            'op_rusis_pav' => 'Pardavimai',
            'zurnalas' => $journal,
            'op_numeris' => $number,
            'op_data' => $date,
            'sandelis' => 'WH01',
        ];
    }

    public function test_returns_the_purchase_operation_for_unsold_stock(): void
    {
        $stock = $this->stockWithHistory([
            $this->purchaseRow('PIRKNAU', 1421, '2026-07-01'),
        ]);

        $op = $stock->purchaseOpFor('WSM000001TB061527');

        $this->assertSame('PIRKNAU', $op['journal']);
        $this->assertSame(1421, $op['op_number']);
        $this->assertSame('WH01', $op['warehouse']);
        $this->assertSame('2026-07-01', $op['op_date']);
        $this->assertFalse($op['sold']);
        $this->assertNull($op['sale_journal']);
        $this->assertNull($op['sale_op_number']);
        $this->assertNull($op['sale_date']);
    }

    public function test_queries_the_full_history_for_the_product(): void
    {
        $history = [];
        $this->stockWithHistory([$this->purchaseRow('PIRKNAU', 1, '2026-01-01')], $history)
            ->purchaseOpFor('WSM000001TB061527');

        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('GetPrekesIstorija', $uri);
        $this->assertStringContainsString('sPreKod=WSM000001TB061527', $uri);
        // No warehouse or date-from narrowing: the whole history is needed to find
        // the latest purchase.
        $this->assertStringNotContainsString('sSandKod=', $uri);
        $this->assertStringNotContainsString('tDataNuo=', $uri);
    }

    public function test_latest_purchase_wins_for_a_reacquired_product(): void
    {
        $stock = $this->stockWithHistory([
            $this->purchaseRow('PIRKNAU', 1000, '2025-03-10', 'WH02'),
            $this->purchaseRow('PIRKNP', 1421, '2026-07-01', 'WH01'),
        ]);

        $op = $stock->purchaseOpFor('WSM000001TB061527');

        $this->assertSame('PIRKNP', $op['journal']);
        $this->assertSame(1421, $op['op_number']);
        $this->assertSame('WH01', $op['warehouse']);
    }

    public function test_a_sale_after_the_purchase_marks_the_stock_sold(): void
    {
        $stock = $this->stockWithHistory([
            $this->purchaseRow('PIRKNAU', 1421, '2026-07-01'),
            $this->saleRow('PARD1', 77, '2026-07-20'),
        ]);

        $op = $stock->purchaseOpFor('WSM000001TB061527');

        $this->assertTrue($op['sold']);
        $this->assertSame('PARD1', $op['sale_journal']);
        $this->assertSame(77, $op['sale_op_number']);
        $this->assertSame('2026-07-20', $op['sale_date']);
    }

    public function test_a_sale_on_the_purchase_date_counts_as_sold(): void
    {
        $stock = $this->stockWithHistory([
            $this->purchaseRow('PIRKNAU', 1421, '2026-07-01'),
            $this->saleRow('PARD1', 77, '2026-07-01'),
        ]);

        $this->assertTrue($stock->purchaseOpFor('X')['sold']);
    }

    public function test_a_sale_before_the_latest_purchase_belongs_to_a_previous_cycle(): void
    {
        // Bought, sold, bought back: the older sale must not mark the current
        // stock layer as sold.
        $stock = $this->stockWithHistory([
            $this->purchaseRow('PIRKNAU', 1000, '2025-03-10'),
            $this->saleRow('PARD1', 55, '2025-06-01'),
            $this->purchaseRow('PIRKNP', 1421, '2026-07-01'),
        ]);

        $op = $stock->purchaseOpFor('WSM000001TB061527');

        $this->assertSame(1421, $op['op_number']);
        $this->assertFalse($op['sold']);
        $this->assertNull($op['sale_date']);
    }

    public function test_the_latest_sale_is_reported_when_several_follow(): void
    {
        $stock = $this->stockWithHistory([
            $this->purchaseRow('PIRKNAU', 1421, '2026-07-01'),
            $this->saleRow('PARD1', 77, '2026-07-10'),
            $this->saleRow('PARD2', 88, '2026-07-20'),
        ]);

        $op = $stock->purchaseOpFor('X');

        $this->assertSame(88, $op['sale_op_number']);
        $this->assertSame('2026-07-20', $op['sale_date']);
    }

    public function test_returns_null_without_a_purchase_row(): void
    {
        $stock = $this->stockWithHistory([$this->saleRow('PARD1', 77, '2026-07-20')]);

        $this->assertNull($stock->purchaseOpFor('X'));
    }

    public function test_returns_null_for_an_empty_history(): void
    {
        $this->assertNull($this->stockWithHistory([])->purchaseOpFor('X'));
    }

    public function test_returns_null_when_the_call_fails(): void
    {
        $stock = new Stock($this->createHttpClient([
            $this->jsonResponse(['AccessResult' => 'Fail', 'error' => 'boom']),
        ]));

        $this->assertNull($stock->purchaseOpFor('X'));
    }

    public function test_returns_null_for_a_blank_code_without_a_round_trip(): void
    {
        $history = [];
        $stock = $this->stockWithHistory([$this->purchaseRow('PIRKNAU', 1, '2026-01-01')], $history);

        $this->assertNull($stock->purchaseOpFor('   '));
        $this->assertCount(0, $history);
    }

    public function test_trims_but_does_not_upper_case_the_product_code(): void
    {
        $history = [];
        $this->stockWithHistory([$this->purchaseRow('PIRKNAU', 1, '2026-01-01')], $history)
            ->purchaseOpFor('  wsm000001tb061527  ');

        $this->assertStringContainsString('sPreKod=wsm000001tb061527', (string) $history[0]['request']->getUri());
    }

    public function test_unrecognised_operation_kinds_are_ignored(): void
    {
        // op_rusis_pav values are observed, not specified. An unknown kind must not
        // be mistaken for a purchase or a sale.
        $stock = $this->stockWithHistory([
            ['op_rusis_pav' => 'Vidiniai perkėlimai', 'zurnalas' => 'VID1', 'op_numeris' => 5, 'op_data' => '2026-07-25', 'sandelis' => 'WH02'],
            $this->purchaseRow('PIRKNAU', 1421, '2026-07-01'),
        ]);

        $op = $stock->purchaseOpFor('X');

        $this->assertSame(1421, $op['op_number']);
        $this->assertFalse($op['sold']);
    }

    public function test_handles_datetime_stamped_operation_dates(): void
    {
        $stock = $this->stockWithHistory([
            ['op_rusis_pav' => 'Pirkimai', 'zurnalas' => 'PIRKNAU', 'op_numeris' => 1421, 'op_data' => '2026-07-01T00:00:00', 'sandelis' => 'WH01'],
            ['op_rusis_pav' => 'Pardavimai', 'zurnalas' => 'PARD1', 'op_numeris' => 77, 'op_data' => '2026-07-20T13:45:00', 'sandelis' => 'WH01'],
        ]);

        $op = $stock->purchaseOpFor('X');

        $this->assertSame('2026-07-01', $op['op_date']);
        $this->assertTrue($op['sold']);
        $this->assertSame('2026-07-20', $op['sale_date']);
    }
}
