<?php

declare(strict_types=1);

namespace Finvalda\Support;

/**
 * Strips PHP binary-float artifacts (e.g. 0.30000000000000004) from outbound
 * request payloads by rounding every float to a precision high enough to
 * preserve any genuine accounting value while discarding representation noise.
 */
final class OutboundNumericNormalizer
{
    public function __construct(
        private readonly bool $enabled = true,
        private readonly int $precision = 10,
    ) {
    }

    public function normalize(mixed $value): mixed
    {
        if (! $this->enabled) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $childValue) {
                $value[$key] = $this->normalize($childValue);
            }

            return $value;
        }

        if (is_float($value)) {
            return round($value, $this->precision);
        }

        return $value;
    }
}
