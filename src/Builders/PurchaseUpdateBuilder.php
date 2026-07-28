<?php

declare(strict_types=1);

namespace Finvalda\Builders;

use Finvalda\Builders\Concerns\HasAdditionalCostCodes;
use Finvalda\Enums\UpdateOperationClass;
use Finvalda\Exceptions\ConflictException;
use Finvalda\Exceptions\ValidationException;
use Finvalda\Finvalda;
use Finvalda\Responses\OperationResult;

/**
 * Fluent builder for purchase corrections (KoregPirkDok / UpdateOperation).
 *
 * !! DESTRUCTIVE. A correction deletes the named detail lines and re-adds the ones
 * !! you supply. Re-adding a product line REBUILDS ITS FIFO STOCK LAYER, and the
 * !! internal delete fails with error 4027 ("Operacijos detalios eilutės yra
 * !! panaudotos kitose operacijose!") once the goods have been consumed by another
 * !! operation — a sale, write-off, transfer or production run. Note that 4027 is
 * !! documented under operation *deletion* errors, not the 5000-5005 correction
 * !! family, so an update call can return a deletion-class code.
 * !!
 * !! Consequences worth knowing before you ship a caller:
 * !!  - This is not an idempotent edit. Re-sending the same correction is not a no-op.
 * !!  - It fails late: unsold stock corrects fine, and the same code path starts
 * !!    failing the day someone sells the goods.
 * !!  - On a multi-line rejection, do not assume the other lines were untouched —
 * !!    re-read the operation before retrying.
 * !!
 * !! Check Stock::purchaseOpFor($code)['sold'] first, or call assertNotSold().
 * !! UpdPrekeDetEil is NOT an escape hatch: the spec gives it only sKodas, nKodasN,
 * !! nPozymis and sPapInfo, so it can flip a line's marked flag and free text but
 * !! cannot restate amounts — there is no way to re-allocate costs without the
 * !! delete/re-add cycle.
 *
 * Usage:
 * ```php
 * $stock = $finvalda->stock()->purchaseOpFor('WSM000001TB061527');
 *
 * if ($stock === null || $stock['sold']) {
 *     return; // unsafe — report it and let an accountant handle it
 * }
 *
 * $finvalda->purchaseUpdate()
 *     ->journal($stock['journal'])
 *     ->number($stock['op_number'])
 *     ->additionalCostCodes([2 => 'TRANSP'])
 *     ->removeProduct('WSM000001TB061527', $stock['warehouse'])
 *     ->product(
 *         ProductLine::make('WSM000001TB061527', 1)
 *             ->warehouse($stock['warehouse'])
 *             ->amount(38_500.00)
 *             ->additionalCost(2, 1_270.00)
 *     )
 *     ->save('PIRKNAU');
 * ```
 */
final class PurchaseUpdateBuilder
{
    use HasAdditionalCostCodes;

    /**
     * The documented PirkDokHeadEil column set. Everything in the node is optional
     * and the node itself may be omitted; anything outside this list would be
     * silently dropped by the server, so header() rejects it instead.
     */
    private const HEADER_FIELDS = [
        'sSerija', 'sDokumentas', 'sDokRusis',
        'sPavadinimas1', 'sPavadinimas2', 'sPavadinimas3', 'sPavadinimas4', 'sPavadinimas5',
        'sPastaba', 'sSutartis', 'sVezejas', 'sGavejas', 'sIniciatorius', 'sAdresas',
        'sObjektas1', 'sObjektas2', 'sObjektas3', 'sObjektas4',
        'tMokejimoData',
        'sPapIslaiduKodas1', 'sPapIslaiduKodas2', 'sPapIslaiduKodas3', 'sPapIslaiduKodas4',
        'nPozymis',
    ];

    /**
     * Waybill (važtaraštis) fields. Present in the shared PirkDokHeadEil table but
     * documented as applying only to sales, sales reservations and purchase
     * returns — not to purchases.
     */
    private const WAYBILL_FIELDS = [
        'sKrovVazt', 'sSurasVieta', 'sPakrovimoVieta', 'sIskrovimoVieta', 'sKrovSvoris',
        'sKrovIsdAsmuo', 'sKrovPrAsmuo', 'tSurasData', 'tPakrovimoData', 'tIskrovimoData',
        'sVairuotojas', 'sMasina', 'sPapInfo',
    ];

    /** PirkDokHeadEil node. @var array<string, mixed> */
    protected array $header = [];

    private ?string $journal = null;

    private ?int $number = null;

    /** @var array<int, array<string, mixed>> */
    private array $deleteProducts = [];

    /** @var array<int, array<string, mixed>> */
    private array $deleteServices = [];

    /** @var array<int, array<string, mixed>> */
    private array $productLines = [];

    /** @var array<int, array<string, mixed>> */
    private array $serviceLines = [];

    private ?Finvalda $finvalda = null;

    private ?string $parameter = null;

    /**
     * Target operation journal (wrapper: sZurnalas). Required.
     */
    public function journal(string $journal): self
    {
        $this->journal = $journal;

        return $this;
    }

    /**
     * Target operation number (wrapper: nNumeris). Required.
     */
    public function number(int $number): self
    {
        $this->number = $number;

        return $this;
    }

    /**
     * Header fields to change (PirkDokHeadEil). Merged across calls; omit entirely
     * to leave the header alone.
     *
     * Raw field names, validated against the documented PirkDokHeadEil column set.
     * Not settable here: sKlientas (a purchase cannot change supplier), any
     * operation-date field (PirkDokHeadEil defines none — not tData, not
     * tTiekejoSFData), analytical object levels 5-6 (the node stops at
     * sObjektas4), and the waybill fields (sales/reservations/purchase returns
     * only).
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws ValidationException  On a field PirkDokHeadEil does not accept for a purchase.
     */
    public function header(array $fields): self
    {
        foreach ($fields as $key => $value) {
            if ($key === 'sKlientas') {
                throw new ValidationException(
                    "'sKlientas' cannot be changed by a purchase correction; the spec marks it "
                    . 'Tik KoregPardDok ir KoregPardRezDok.'
                );
            }

            if (in_array($key, self::WAYBILL_FIELDS, true)) {
                throw new ValidationException(
                    "'{$key}' is a waybill field, documented for sales, sales reservations and "
                    . 'purchase returns only — not for purchase corrections.'
                );
            }

            if (! in_array($key, self::HEADER_FIELDS, true)) {
                throw new ValidationException("'{$key}' is not a PirkDokHeadEil field");
            }

            $this->header[$key] = $value;
        }

        return $this;
    }

    /**
     * Remove a product line (DelPrekeDetEil).
     *
     * @param  string  $code  Product code (sKodas, max 20).
     * @param  string|null  $warehouse  Warehouse code (sSandelis, max 10).
     *
     * @throws ValidationException  On an empty or over-long code/warehouse.
     */
    public function removeProduct(string $code, ?string $warehouse = null): self
    {
        $row = ['sKodas' => $this->assertLength($code, 20, 'Product code')];

        if ($warehouse !== null) {
            $row['sSandelis'] = $this->assertLength($warehouse, 10, 'Warehouse code');
        }

        $this->deleteProducts[] = $row;

        return $this;
    }

    /**
     * Remove a service line (DelPaslaugaDetEil).
     *
     * @throws ValidationException  On an empty or over-long code.
     */
    public function removeService(string $code): self
    {
        $this->deleteServices[] = ['sKodas' => $this->assertLength($code, 20, 'Service code')];

        return $this;
    }

    /**
     * Add/replace a product line (PirkDokPrekeDetEil).
     *
     * A re-added line is a replacement, not an edit — see the class docblock. Adding
     * without a matching removeProduct() is legal but rarely what a correction means.
     *
     * @throws ValidationException  When the line lacks a field the spec requires.
     */
    public function product(ProductLine $line): self
    {
        $this->productLines[] = $this->assertRequiredLineFields(
            $line->toArray(),
            ['sKodas', 'sSandelis', 'dSumaV', 'dSumaL', 'nKiekis'],
            'PirkDokPrekeDetEil',
        );

        return $this;
    }

    /**
     * Add/replace a service line (PirkDokPaslaugaDetEil).
     *
     * @throws ValidationException  When the line lacks a field the spec requires.
     */
    public function service(ServiceLine $line): self
    {
        $this->serviceLines[] = $this->assertRequiredLineFields(
            $line->toArray(),
            ['sKodas', 'dSumaV', 'dSumaL', 'nKiekis'],
            'PirkDokPaslaugaDetEil',
        );

        return $this;
    }

    /**
     * Set the Finvalda instance for saving.
     */
    public function using(Finvalda $finvalda): self
    {
        $this->finvalda = $finvalda;

        return $this;
    }

    /**
     * Set the server-configured import parameter.
     */
    public function parameter(string $parameter): self
    {
        $this->parameter = $parameter;

        return $this;
    }

    /**
     * Refuse to correct an operation whose stock has already been sold.
     *
     * One Stock::purchaseOpFor() round trip per distinct product code touched by
     * this correction (removals and re-adds alike). Service-only corrections make
     * no calls. Deliberately not run by save(): it costs a round trip per code and
     * hides a decision the caller should be making.
     *
     * Fails closed — a product whose purchase history cannot be resolved is
     * refused too, since a guard on a destructive call must not pass on a failed
     * lookup.
     *
     * @throws ConflictException  When a touched product is sold or unresolvable.
     * @throws \RuntimeException  When no Finvalda instance is set.
     */
    public function assertNotSold(): self
    {
        $codes = array_unique(array_merge(
            array_column($this->deleteProducts, 'sKodas'),
            array_column($this->productLines, 'sKodas'),
        ));

        if ($codes === []) {
            return $this;
        }

        $finvalda = $this->requireFinvalda();

        foreach ($codes as $code) {
            $op = $finvalda->stock()->purchaseOpFor((string) $code);

            if ($op === null) {
                throw new ConflictException(
                    "Refusing to correct: no purchase operation could be resolved for '{$code}'."
                );
            }

            if ($op['sold']) {
                throw new ConflictException(sprintf(
                    "Product '%s' has been sold (%s #%d on %s); correcting its purchase would "
                    . 'fail with error 4027 or rebuild a stock layer under the sale.',
                    $code,
                    $op['sale_journal'] ?? '',
                    $op['sale_op_number'] ?? 0,
                    $op['sale_date'] ?? '',
                ));
            }
        }

        return $this;
    }

    /**
     * Build the KoregPirkDok envelope.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException  Missing journal/number, nothing to change, or
     *                              cost codes on an unsupported mode.
     */
    public function build(): array
    {
        if ($this->journal === null) {
            throw new ValidationException('journal() is required for a purchase correction');
        }

        if ($this->number === null) {
            throw new ValidationException('number() is required for a purchase correction');
        }

        if ($this->header === []
            && $this->deleteProducts === []
            && $this->deleteServices === []
            && $this->productLines === []
            && $this->serviceLines === []
        ) {
            throw new ValidationException(
                'This purchase correction has nothing to change; an empty KoregPirkDok is a '
                . 'wasted destructive call.'
            );
        }

        $payload = [
            'sZurnalas' => $this->journal,
            'nNumeris' => $this->number,
        ];

        if ($this->header !== []) {
            $payload['PirkDokHeadEil'] = $this->header;
        }

        if ($this->deleteProducts !== []) {
            $payload['DelPrekeDetEil'] = $this->deleteProducts;
        }

        if ($this->deleteServices !== []) {
            $payload['DelPaslaugaDetEil'] = $this->deleteServices;
        }

        if ($this->productLines !== []) {
            $payload['PirkDokPrekeDetEil'] = $this->productLines;
        }

        if ($this->serviceLines !== []) {
            $payload['PirkDokPaslaugaDetEil'] = $this->serviceLines;
        }

        return ['KoregPirkDok' => $payload];
    }

    /**
     * Post via Operations::update(UpdateOperationClass::Purchase, ...).
     *
     * @throws ValidationException  Missing journal/number, or nothing to change.
     * @throws \RuntimeException  Finvalda instance or parameter not set.
     * @throws \JsonException
     */
    public function save(?string $parameter = null): OperationResult
    {
        $finvalda = $this->requireFinvalda();

        $param = $parameter ?? $this->parameter;

        if ($param === null) {
            throw new \RuntimeException('Parameter not set. Use parameter() method or pass to save().');
        }

        return $finvalda->operations()->update(
            UpdateOperationClass::Purchase,
            $this->build(),
            $param,
        );
    }

    private function requireFinvalda(): Finvalda
    {
        if ($this->finvalda === null) {
            throw new \RuntimeException('Finvalda instance not set. Use using() method first.');
        }

        return $this->finvalda;
    }

    /**
     * @throws ValidationException
     */
    private function assertLength(string $value, int $max, string $label): string
    {
        if (trim($value) === '') {
            throw new ValidationException("{$label} must be a non-empty string");
        }

        if (mb_strlen($value) > $max) {
            throw new ValidationException("{$label} '{$value}' exceeds {$max} characters");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function assertRequiredLineFields(array $line, array $required, string $node): array
    {
        foreach ($required as $field) {
            if (! isset($line[$field])) {
                throw new ValidationException(
                    "{$node} requires {$field}; the spec marks it mandatory on a corrected line."
                );
            }
        }

        return $line;
    }
}
