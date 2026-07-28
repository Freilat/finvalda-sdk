<?php

declare(strict_types=1);

namespace Finvalda\Resources;

use DateTimeInterface;
use Finvalda\Responses\Response;

/**
 * Stock and inventory balance operations.
 */
final class Stock extends Resource
{
    /**
     * GetPrekesIstorija reports the operation kind as a localised name in
     * op_rusis_pav. The spec documents the column but never enumerates its
     * values — these two are observed against a live Finvalda, not specified.
     */
    private const OP_KIND_PURCHASE = 'Pirkimai';

    private const OP_KIND_SALE = 'Pardavimai';

    /**
     * Get current stock balances. Calls GetEinamiejiLikuciai.
     *
     * @param  string|null  $productCode  Filter by product code
     * @param  string|null  $warehouseCode  Filter by warehouse code
     * @param  DateTimeInterface|string|null  $modifiedSince  Return records modified since this date
     * @param  DateTimeInterface|string|null  $createdSince  Return records created since this date
     */
    public function balances(
        ?string $productCode = null,
        ?string $warehouseCode = null,
        DateTimeInterface|string|null $modifiedSince = null,
        DateTimeInterface|string|null $createdSince = null,
    ): Response {
        return $this->http->get('GetEinamiejiLikuciai', [
            'sPrekesKodas' => $productCode,
            'sSandelioKodas' => $warehouseCode,
            'tKoregavimoData' => $this->formatDate($modifiedSince),
            'tSukurimoData' => $this->formatDate($createdSince),
        ]);
    }

    /**
     * Get extended stock balances with additional fields. Calls GetEinamiejiLikuciaiExt.
     *
     * @param  string|null  $productCode  Filter by product code
     * @param  string|null  $warehouseCode  Filter by warehouse code
     * @param  DateTimeInterface|string|null  $modifiedSince  Return records modified since this date
     * @param  DateTimeInterface|string|null  $createdSince  Return records created since this date
     */
    public function balancesExtended(
        ?string $productCode = null,
        ?string $warehouseCode = null,
        DateTimeInterface|string|null $modifiedSince = null,
        DateTimeInterface|string|null $createdSince = null,
    ): Response {
        return $this->http->get('GetEinamiejiLikuciaiExt', [
            'sPrekesKodas' => $productCode,
            'sSandelioKodas' => $warehouseCode,
            'tKoregavimoData' => $this->formatDate($modifiedSince),
            'tSukurimoData' => $this->formatDate($createdSince),
        ]);
    }

    /**
     * Get extended stock balances including prices. Calls GetEinamiejiLikuciaiExtSuKainom.
     *
     * @param  string|null  $productCode  Filter by product code
     * @param  string|null  $warehouseCode  Filter by warehouse code
     * @param  bool|null  $includeZeroQuantity  Whether to include items with zero stock
     * @param  DateTimeInterface|string|null  $modifiedSince  Return records modified since this date
     * @param  DateTimeInterface|string|null  $createdSince  Return records created since this date
     */
    public function balancesWithPrices(
        ?string $productCode = null,
        ?string $warehouseCode = null,
        ?bool $includeZeroQuantity = null,
        DateTimeInterface|string|null $modifiedSince = null,
        DateTimeInterface|string|null $createdSince = null,
    ): Response {
        return $this->http->get('GetEinamiejiLikuciaiExtSuKainom', [
            'sPrekesKodas' => $productCode,
            'sSandelioKodas' => $warehouseCode,
            'bNuliniaiLikuciai' => $includeZeroQuantity !== null ? ($includeZeroQuantity ? 'true' : 'false') : null,
            'tKoregavimoData' => $this->formatDate($modifiedSince),
            'tSukurimoData' => $this->formatDate($createdSince),
        ]);
    }

    /**
     * Get stock balances grouped by warehouse group. Calls GetEinamiejiLikuciaiGrp.
     *
     * @param  string|null  $productCode  Filter by product code
     * @param  string|null  $warehouseGroupCode  Filter by warehouse group code
     * @param  DateTimeInterface|string|null  $modifiedSince  Return records modified since this date
     * @param  DateTimeInterface|string|null  $createdSince  Return records created since this date
     */
    public function balancesByGroup(
        ?string $productCode = null,
        ?string $warehouseGroupCode = null,
        DateTimeInterface|string|null $modifiedSince = null,
        DateTimeInterface|string|null $createdSince = null,
    ): Response {
        return $this->http->get('GetEinamiejiLikuciaiGrp', [
            'sPrekesKodas' => $productCode,
            'sSandelioGrupesKodas' => $warehouseGroupCode,
            'tKoregavimoData' => $this->formatDate($modifiedSince),
            'tSukurimoData' => $this->formatDate($createdSince),
        ]);
    }

    /**
     * Resolve the purchase operation that currently holds $productCode as stock,
     * and whether a sale has followed it. Derived from GetPrekesIstorija.
     *
     * The LATEST purchase wins: a re-acquired item has several purchase rows and
     * only the most recent one holds the current stock layer. A sale counts as
     * "sold" only when it is dated at or after that purchase — an older sale
     * belongs to a previous ownership cycle.
     *
     * Note this is derived, not raw: unlike the rest of this resource it returns a
     * plain array rather than a Response, and never throws — it exists to be used
     * as a pre-flight check (see PurchaseUpdateBuilder). Use Products::history()
     * for the raw rows.
     *
     * @param  string  $productCode  Product code (for serialised stock, typically the serial/VIN).
     * @return array{journal:string, op_number:int, warehouse:string, op_date:string,
     *               sold:bool, sale_journal:?string, sale_op_number:?int, sale_date:?string}|null
     *         Null when the product has no purchase history, or the call failed.
     */
    public function purchaseOpFor(string $productCode): ?array
    {
        $code = trim($productCode);

        if ($code === '') {
            return null;
        }

        // No warehouse or date-from narrowing: the whole history is needed to find
        // the latest purchase.
        $response = $this->http->get('GetPrekesIstorija', ['sPreKod' => $code]);

        if (! $response->successful()) {
            return null;
        }

        $purchase = null;
        $purchaseDate = null;
        $sale = null;
        $saleDate = null;

        foreach ($response->data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->historyRowDate($row);

            if ($date === null) {
                continue;
            }

            // Unknown operation kinds are ignored rather than guessed at.
            $kind = $row['op_rusis_pav'] ?? null;

            if ($kind === self::OP_KIND_PURCHASE && ($purchaseDate === null || $date >= $purchaseDate)) {
                $purchase = $row;
                $purchaseDate = $date;
            } elseif ($kind === self::OP_KIND_SALE && ($saleDate === null || $date >= $saleDate)) {
                $sale = $row;
                $saleDate = $date;
            }
        }

        if ($purchase === null || $purchaseDate === null) {
            return null;
        }

        $sold = $sale !== null && $saleDate !== null && $saleDate >= $purchaseDate;

        return [
            'journal' => (string) ($purchase['zurnalas'] ?? ''),
            'op_number' => (int) ($purchase['op_numeris'] ?? 0),
            'warehouse' => (string) ($purchase['sandelis'] ?? ''),
            'op_date' => $purchaseDate,
            'sold' => $sold,
            'sale_journal' => $sold ? (string) ($sale['zurnalas'] ?? '') : null,
            'sale_op_number' => $sold ? (int) ($sale['op_numeris'] ?? 0) : null,
            'sale_date' => $sold ? $saleDate : null,
        ];
    }

    /**
     * Normalise a GetPrekesIstorija row's op_data to Y-m-d for comparison.
     *
     * @param  array<string, mixed>  $row
     */
    private function historyRowDate(array $row): ?string
    {
        $raw = $row['op_data'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return substr($raw, 0, 10);
    }

    /**
     * Get ordered products (pending purchase orders). Calls GetUzsakytasPrekes.
     *
     * @param  string|null  $productCode  Filter by product code
     * @param  string|null  $warehouseCode  Filter by warehouse code
     * @return Response
     */
    public function orderedProducts(
        ?string $productCode = null,
        ?string $warehouseCode = null,
    ): Response {
        return $this->http->get('GetUzsakytasPrekes', [
            'sPrekesKodas' => $productCode,
            'sSandelioKodas' => $warehouseCode,
        ]);
    }
}
