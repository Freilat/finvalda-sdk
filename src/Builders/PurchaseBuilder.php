<?php

declare(strict_types=1);

namespace Finvalda\Builders;

use DateTimeInterface;
use Finvalda\Builders\Concerns\HasAdditionalCostCodes;
use Finvalda\Enums\DocumentType;
use Finvalda\Enums\OperationClass;

/**
 * Fluent builder for purchase operations (PirkDok).
 *
 * Usage:
 * ```php
 * $result = $finvalda->purchase()
 *     ->client('SUP001')
 *     ->date('2024-01-15')
 *     ->warehouse('MAIN')
 *     ->supplierInvoice('INV-2024-001')
 *     ->addProduct('PRD001', quantity: 100, price: 9.99)
 *     ->save('STANDARD');
 * ```
 */
final class PurchaseBuilder extends OperationBuilder
{
    use HasAdditionalCostCodes;

    protected bool $short = false;

    public function getOperationClass(): OperationClass
    {
        return $this->short ? OperationClass::PurchaseShort : OperationClass::Purchase;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $this->assertAdditionalCostCodesAllowed($this->getHeaderKey());

        return parent::build();
    }

    protected function allowsAdditionalCostCodes(): bool
    {
        return ! $this->short;
    }

    protected function getHeaderKey(): string
    {
        return $this->short ? 'TrumpasPirkDok' : 'PirkDok';
    }

    protected function getProductLinesKey(): string
    {
        return 'PirkDokPrekeDetEil';
    }

    protected function getServiceLinesKey(): string
    {
        return 'PirkDokPaslaugaDetEil';
    }

    /**
     * Use the short/simplified operation variant (TrumpasPirkDok).
     */
    public function short(bool $short = true): self
    {
        $this->short = $short;

        return $this;
    }

    // --- Purchase-specific methods ---

    /**
     * Set the document series.
     */
    public function series(string $series): self
    {
        $this->header['sSerija'] = $series;

        return $this;
    }

    /**
     * Set the document type/kind (sDokRusis).
     *
     * Accepts a DocumentType enum case or a raw 2-char code (S, SF, D, DS, K, KS, KT, VS, VD, VK).
     */
    public function documentType(DocumentType|string $type): self
    {
        $this->header['sDokRusis'] = $type instanceof DocumentType ? $type->value : $type;

        return $this;
    }

    /**
     * Set the supplier invoice number.
     */
    public function supplierInvoice(string $invoiceNumber): self
    {
        $this->header['sTiekejoSF'] = $invoiceNumber;

        return $this;
    }

    /**
     * Set the supplier invoice date.
     */
    public function supplierInvoiceDate(DateTimeInterface|string $date): self
    {
        $this->header['tTiekejoSFData'] = $this->formatDate($date);

        return $this;
    }

    /**
     * Set the payment days.
     */
    public function paymentDays(int $days): self
    {
        $this->header['nAtsiskDien'] = $days;

        return $this;
    }

    /**
     * Set the payment due date.
     */
    public function dueDate(DateTimeInterface|string $date): self
    {
        $this->header['tAtsiskData'] = $this->formatDate($date);

        return $this;
    }

    /**
     * Set if the operation is a prepayment/advance.
     */
    public function isAdvance(bool $isAdvance = true): self
    {
        $this->header['bAvansas'] = $isAdvance;

        return $this;
    }

    /**
     * Set the responsible person code.
     */
    public function responsiblePerson(string $personCode): self
    {
        $this->header['sAtsakingasAsmuo'] = $personCode;

        return $this;
    }

    /**
     * Set VAT included flag.
     */
    public function vatIncluded(bool $included = true): self
    {
        $this->header['bPVMSkaiciuotiIKaina'] = $included;

        return $this;
    }

    /**
     * Set the rounding amount for cent rounding.
     */
    public function roundingAmount(float $amount): self
    {
        $this->header['dGrApvalinimoSuma'] = $amount;

        return $this;
    }

    /**
     * Set whether to export to iVAZ.
     */
    public function exportToIvaz(bool $export = true): self
    {
        $this->header['nIVAZ'] = $export ? 1 : 0;

        return $this;
    }

    /**
     * Set the operation locked flag.
     */
    public function locked(bool $locked = true): self
    {
        $this->header['nVarna'] = $locked ? 1 : 0;

        return $this;
    }

    /**
     * Set the Finvalda employee name.
     */
    public function employee(string $employee): self
    {
        $this->header['sDarbuotojas'] = $employee;

        return $this;
    }
}
