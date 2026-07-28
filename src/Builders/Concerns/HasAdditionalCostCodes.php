<?php

declare(strict_types=1);

namespace Finvalda\Builders\Concerns;

use Finvalda\Exceptions\ValidationException;

/**
 * Additional-cost bucket codes (sPapIslaiduKodas1..4) on an operation header.
 *
 * Finvalda allows up to four named additional-cost buckets (papildomos išlaidos)
 * per purchase header — freight, registration, insurance and so on. Declaring a
 * bucket is inert until product lines allocate amounts to it with
 * ProductLine::additionalCost(); the slot NUMBER is what binds the two.
 *
 * The spec marks these fields "Tik PirkDok ir PirkUzsDok" on the insert path and
 * "Tik KoregPirkDok ir KoregPirkUzsDok" on the correction path, so only purchase
 * and purchase-order headers (full variants) carry them.
 *
 * Requires the using class to hold an array $header property.
 */
trait HasAdditionalCostCodes
{
    private const MAX_ADDITIONAL_COST_SLOTS = 4;

    private const ADDITIONAL_COST_CODE_LENGTH = 10;

    /**
     * Set the additional-cost bucket codes (sPapIslaiduKodas1..4).
     *
     * Accepts an ordered list, filled into slots 1..4 in order:
     *   ->additionalCostCodes(['KITOS', 'TRANSP', 'ILGALSAV', 'DRAUDIM'])
     * or a slot-keyed map, to set individual slots:
     *   ->additionalCostCodes([2 => 'TRANSP', 4 => 'DRAUDIM'])
     *
     * Slots are positional and server-configured; do not reorder them between
     * bookings of the same journal, or previously booked allocations stop lining
     * up with their buckets.
     *
     * Not available on short() operations — TrumpasPirkDok/TrumpasPirkUzsDok have
     * no such fields. Because short() may be called afterwards, that check runs at
     * build() time rather than here.
     *
     * @param  array<int, string>  $codes  Ordered list (max 4) or map keyed by slot 1-4.
     *
     * @throws ValidationException  On more than 4 codes, a slot outside 1-4, a code
     *                              longer than 10 characters, or a non-string/empty code.
     */
    public function additionalCostCodes(array $codes): static
    {
        if (count($codes) > self::MAX_ADDITIONAL_COST_SLOTS) {
            throw new ValidationException(sprintf(
                'additionalCostCodes() accepts at most %d codes, %d given',
                self::MAX_ADDITIONAL_COST_SLOTS,
                count($codes),
            ));
        }

        $isList = array_is_list($codes);
        $position = 0;

        foreach ($codes as $key => $code) {
            $slot = $isList ? ++$position : $key;

            // in_array over the literal slots rather than a range check: it also
            // rejects a string key, which PHP would otherwise splice straight into
            // the field name.
            if (! in_array($slot, [1, 2, 3, 4], true)) {
                throw new ValidationException(sprintf(
                    'additionalCostCodes() slot must be 1-%d, %s given',
                    self::MAX_ADDITIONAL_COST_SLOTS,
                    (string) $slot,
                ));
            }

            if (trim($code) === '') {
                throw new ValidationException(
                    "Additional cost code must be a non-empty string (slot {$slot})"
                );
            }

            if (mb_strlen($code) > self::ADDITIONAL_COST_CODE_LENGTH) {
                throw new ValidationException(sprintf(
                    "Additional cost code '%s' exceeds %d characters (slot %d)",
                    $code,
                    self::ADDITIONAL_COST_CODE_LENGTH,
                    $slot,
                ));
            }

            $this->header["sPapIslaiduKodas{$slot}"] = $code;
        }

        return $this;
    }

    /**
     * Whether the builder's current mode accepts additional-cost codes.
     *
     * Overridden by builders with a short() variant, whose Trumpas* header has no
     * additional-cost fields.
     */
    protected function allowsAdditionalCostCodes(): bool
    {
        return true;
    }

    /**
     * @throws ValidationException  When codes are set on a mode that has no such fields.
     */
    protected function assertAdditionalCostCodesAllowed(string $headerKey): void
    {
        if ($this->allowsAdditionalCostCodes()) {
            return;
        }

        for ($slot = 1; $slot <= self::MAX_ADDITIONAL_COST_SLOTS; $slot++) {
            if (isset($this->header["sPapIslaiduKodas{$slot}"])) {
                throw new ValidationException(
                    "additionalCostCodes() is not supported on {$headerKey}"
                );
            }
        }
    }
}
