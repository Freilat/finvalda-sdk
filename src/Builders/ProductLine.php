<?php

declare(strict_types=1);

namespace Finvalda\Builders;

use Finvalda\Exceptions\ValidationException;

/**
 * Fluent value object for building product detail lines.
 *
 * Usage:
 * ```php
 * ProductLine::make('MILTAI', 12.25)
 *     ->warehouse('CENTR.')
 *     ->amount(161.16, local: 161.16)
 *     ->vat(percent: 21, amount: 33.84, amountLocal: 33.84)
 *     ->object(1, 'DEPT01')
 *     ->object(4, '1234567')
 * ```
 */
final class ProductLine
{
    /** @var array<string, mixed> */
    private array $data;

    private function __construct(string $code, float $quantity)
    {
        // nKiekis is always sent verbatim — the SDK never rescales product quantities.
        // What changes how Finvalda READS nKiekis is the nPirmasMat flag plus the product's
        // measurement unit, which has a first unit, a second unit, and a first/second ratio
        // (pirm_antr_sant from GetMatavimoVienetus). Examples: M -> first=m, second=cm, ratio 100;
        // KG -> first=kg, second=g, ratio 1000; VNT -> ratio 1.
        //
        //   - nPirmasMat=1  (default): nKiekis is read in the FIRST (primary) unit, as-is.
        //                              250 on an "M" product means 250 m. This matches every
        //                              official Finvalda API example.
        //   - nPirmasMat absent (second measurement, via secondMeasurement()): Finvalda reads
        //                              nKiekis in the SECOND unit and rescales by the ratio, so
        //                              250 on an "M" product becomes 250 cm = 2.5 m. VNT products
        //                              (ratio 1) are unaffected, which masks the difference for
        //                              piece quantities.
        //
        // Default is nPirmasMat=1 because real quantities are expressed in the primary unit;
        // omitting it silently divided M/KG quantities by their ratio. Call secondMeasurement()
        // to opt back into the legacy second-unit behavior.
        $this->data = [
            'sKodas' => $code,
            'nKiekis' => $quantity,
            'nPirmasMat' => 1,
        ];
    }

    public static function make(string $code, float $quantity): self
    {
        return new self($code, $quantity);
    }

    /**
     * Set the unit price (dKaina).
     */
    public function price(float $price): self
    {
        $this->data['dKaina'] = $price;

        return $this;
    }

    /**
     * Set amounts excluding VAT.
     *
     * @param  float  $currency  Amount in operation currency (dSumaV)
     * @param  float|null  $local  Amount in EUR (dSumaL). Defaults to $currency if null.
     */
    public function amount(float $currency, ?float $local = null): self
    {
        $this->data['dSumaV'] = $currency;
        $this->data['dSumaL'] = $local ?? $currency;

        return $this;
    }

    /**
     * Set VAT details.
     *
     * @param  float  $percent  VAT percentage (dPVM_Procentas)
     * @param  float|null  $amount  VAT amount in currency (dSumaPVMV)
     * @param  float|null  $amountLocal  VAT amount in EUR (dSumaPVML)
     */
    public function vat(float $percent, ?float $amount = null, ?float $amountLocal = null): self
    {
        $this->data['dPVM_Procentas'] = $percent;

        if ($amount !== null) {
            $this->data['dSumaPVMV'] = $amount;
            $this->data['dSumaPVML'] = $amountLocal ?? $amount;
        }

        return $this;
    }

    /**
     * Set discount details.
     *
     * @param  float|null  $percent  Discount percentage (dNlProc)
     * @param  float|null  $amount  Discount amount in currency (dSumaNV)
     * @param  float|null  $amountLocal  Discount amount in EUR (dSumaNL)
     */
    public function discount(?float $percent = null, ?float $amount = null, ?float $amountLocal = null): self
    {
        if ($percent !== null) {
            $this->data['dNlProc'] = $percent;
        }

        if ($amount !== null) {
            $this->data['dSumaNV'] = $amount;
            $this->data['dSumaNL'] = $amountLocal ?? $amount;
        }

        return $this;
    }

    /**
     * Set the warehouse code (sSandelis).
     */
    public function warehouse(string $code): self
    {
        $this->data['sSandelis'] = $code;

        return $this;
    }

    /**
     * Set a single analytical object by level (1-6).
     */
    public function object(int $level, string $code): self
    {
        $this->data["sObjektas{$level}"] = $code;

        return $this;
    }

    /**
     * Set multiple analytical objects at once, keyed by level.
     *
     * @param  array<int, string>  $map  e.g. [1 => 'DEPT01', 4 => '1234567']
     */
    public function objects(array $map): self
    {
        foreach ($map as $level => $code) {
            $this->data["sObjektas{$level}"] = $code;
        }

        return $this;
    }

    /**
     * Allocate an additional-cost (papildomos išlaidos) amount to this line.
     *
     * Sets dPapIsldSumaV{slot} and dPapIsldSumaL{slot}. The slot number refers to
     * the bucket declared in the operation header's sPapIslaiduKodas{slot} — see
     * PurchaseBuilder::additionalCostCodes(). A line amount with no matching header
     * bucket has nowhere to land; the line object cannot see the header, so that
     * pairing is the caller's responsibility.
     *
     * Product lines only: the spec marks these fields "Tik PirkDokPrekeDetEil" and
     * service detail lines do not define them.
     *
     * @param  int  $slot  Additional-cost slot, 1-4.
     * @param  float  $currency  Amount in operation currency (dPapIsldSumaV{slot}).
     * @param  float|null  $local  Amount in EUR (dPapIsldSumaL{slot}). Defaults to $currency.
     *
     * @throws ValidationException  When $slot is outside 1-4.
     */
    public function additionalCost(int $slot, float $currency, ?float $local = null): self
    {
        if ($slot < 1 || $slot > 4) {
            throw new ValidationException("additionalCost() slot must be 1-4, {$slot} given");
        }

        $this->data["dPapIsldSumaV{$slot}"] = $currency;
        $this->data["dPapIsldSumaL{$slot}"] = $local ?? $currency;

        return $this;
    }

    /**
     * Allocate several additional-cost amounts at once, keyed by slot.
     *
     * @param  array<int, float>  $map  e.g. [2 => 950.00, 4 => 310.00]
     *
     * @throws ValidationException  When any slot is outside 1-4.
     */
    public function additionalCosts(array $map): self
    {
        foreach ($map as $slot => $amount) {
            $this->additionalCost($slot, $amount);
        }

        return $this;
    }

    /**
     * Set the VAT tax code (sPvmKodas).
     */
    public function vatCode(string $code): self
    {
        $this->data['sPvmKodas'] = $code;

        return $this;
    }

    /**
     * Set Intrastat classification.
     *
     * @param  string  $code  Intrastat commodity code (sIntrastatKodas)
     * @param  string|null  $origin  Country of origin ISO code (sKilmesSalis)
     */
    public function intrastat(string $code, ?string $origin = null): self
    {
        $this->data['sIntrastatKodas'] = $code;

        if ($origin !== null) {
            $this->data['sKilmesSalis'] = $origin;
        }

        return $this;
    }

    /**
     * Set weight and volume.
     *
     * @param  float|null  $neto  Net weight (dNeto)
     * @param  float|null  $bruto  Gross weight (dBruto)
     * @param  float|null  $volume  Volume (dTuris)
     */
    public function weight(?float $neto = null, ?float $bruto = null, ?float $volume = null): self
    {
        if ($neto !== null) {
            $this->data['dNeto'] = $neto;
        }

        if ($bruto !== null) {
            $this->data['dBruto'] = $bruto;
        }

        if ($volume !== null) {
            $this->data['dTuris'] = $volume;
        }

        return $this;
    }

    /**
     * Use the first (primary) measurement unit for the quantity (nPirmasMat=1).
     *
     * This is the default, so calling it is only needed to re-enable the first
     * measurement after secondMeasurement(). The server reads nKiekis as-is in the
     * primary unit; nKiekis is never rescaled by the SDK. Passing false is equivalent
     * to secondMeasurement().
     */
    public function firstMeasurement(bool $flag = true): self
    {
        if ($flag) {
            $this->data['nPirmasMat'] = 1;

            return $this;
        }

        return $this->secondMeasurement();
    }

    /**
     * Use the second measurement unit for the quantity (omits nPirmasMat).
     *
     * Opt-out of the default first-measurement behavior. With nPirmasMat absent,
     * Finvalda reads nKiekis in the SECOND unit and rescales it by the product's
     * first/second ratio (e.g. 250 on an "M" product = 250 cm = 2.5 m). Only use this
     * if you are genuinely supplying second-unit quantities.
     */
    public function secondMeasurement(): self
    {
        unset($this->data['nPirmasMat']);

        return $this;
    }

    /**
     * Set additional info text (sPapInf).
     */
    public function info(string $text): self
    {
        $this->data['sPapInf'] = $text;

        return $this;
    }

    /**
     * Set the line marked flag (nPozymis).
     */
    public function marked(bool $flag = true): self
    {
        $this->data['nPozymis'] = $flag ? 1 : 0;

        return $this;
    }

    /**
     * Set a raw API field directly. Escape hatch for fields not covered by named methods.
     */
    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Convert to the raw API array format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
