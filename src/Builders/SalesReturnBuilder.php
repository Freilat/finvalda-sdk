<?php

declare(strict_types=1);

namespace Finvalda\Builders;

use DateTimeInterface;
use Finvalda\Enums\DocumentType;
use Finvalda\Enums\OperationClass;

/**
 * Fluent builder for sales return operations (PardGrazDok).
 *
 * Usage:
 * ```php
 * $result = $finvalda->salesReturn()
 *     ->client('CLI001')
 *     ->date('2024-01-20')
 *     ->warehouse('MAIN')
 *     ->originalDocument('SF-001', 'PARD', 123)
 *     ->addProduct('PRD001', quantity: 2, price: 19.99)
 *     ->save('RETURN');
 * ```
 *
 * Note: in live testing the full PardGrazDok variant was rejected with error
 * 2012 ("Xml string is incomplete") even with a spec-correct payload, while
 * the identical fields via ->short() (TrumpasPardGrazDok) succeeded. If the
 * full variant fails with 2012, use ->short().
 */
final class SalesReturnBuilder extends OperationBuilder
{
    protected bool $short = false;

    public function getOperationClass(): OperationClass
    {
        return $this->short ? OperationClass::SalesReturnShort : OperationClass::SalesReturn;
    }

    protected function getHeaderKey(): string
    {
        return $this->short ? 'TrumpasPardGrazDok' : 'PardGrazDok';
    }

    protected function getProductLinesKey(): string
    {
        // The FVS spec defines one shared set of detail elements for the whole
        // sales family (PardDok, PardRezDok, PardGrazDok, UVMPardRezDok).
        // PardGrazDok*DetEil does not exist — the server silently ignores it
        // and then rejects the operation with 1037 "no detail rows".
        return 'PardDokPrekeDetEil';
    }

    protected function getServiceLinesKey(): string
    {
        return 'PardDokPaslaugaDetEil';
    }

    /**
     * Use the short/simplified operation variant (TrumpasPardGrazDok).
     */
    public function short(bool $short = true): self
    {
        $this->short = $short;

        return $this;
    }

    // --- Return-specific methods ---

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
     * Set the fulfillment/execution date.
     */
    public function fulfillmentDate(DateTimeInterface|string $date): self
    {
        $this->header['tIvykdymoData'] = $this->formatDate($date);

        return $this;
    }

    /**
     * Set the original document reference.
     *
     * Warning: sGrazDokumentas/sGrazZurnalas/nGrazNumeris do not appear in the
     * official FVS spec. Finvalda silently ignores unknown fields — verify
     * against your server that the linkage actually lands before relying on it.
     */
    public function originalDocument(string $document, string $journal, int $number): self
    {
        $this->header['sGrazDokumentas'] = $document;
        $this->header['sGrazZurnalas'] = $journal;
        $this->header['nGrazNumeris'] = $number;

        return $this;
    }

    /**
     * Set the original document number.
     */
    public function originalDocumentNumber(string $document): self
    {
        $this->header['sGrazDokumentas'] = $document;

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
     * Set the responsible person code.
     */
    public function responsiblePerson(string $personCode): self
    {
        $this->header['sAtsakingasAsmuo'] = $personCode;

        return $this;
    }

    /**
     * Set the return reason.
     *
     * Warning: sGrazPriezastis does not appear in the official FVS spec and may
     * be silently ignored by the server.
     */
    public function reason(string $reason): self
    {
        $this->header['sGrazPriezastis'] = $reason;

        return $this;
    }
}
