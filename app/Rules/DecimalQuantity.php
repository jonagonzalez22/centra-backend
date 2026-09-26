<?php

namespace App\Rules;

use App\Support\QuantityMath;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a physical quantity accepted by the API.
 *
 * The public contract accepts integer JSON values for backwards compatibility
 * and decimal strings with up to four fractional positions. Floats and
 * scientific notation are intentionally rejected so quantities do not enter
 * the domain through binary floating-point values.
 */
final class DecimalQuantity implements ValidationRule
{
    public function __construct(private readonly bool $allowZero = false) {}

    public static function positive(): self
    {
        return new self;
    }

    public static function nonNegative(): self
    {
        return new self(true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! is_string($value)) {
            $fail('La cantidad debe enviarse como un entero o string decimal válido.');

            return;
        }

        try {
            $normalized = QuantityMath::normalize($value);
        } catch (\InvalidArgumentException) {
            $fail('La cantidad debe tener como máximo cuatro decimales y no puede usar notación científica.');

            return;
        }

        if ($this->allowZero ? QuantityMath::isNegative($normalized) : ! QuantityMath::isPositive($normalized)) {
            $fail($this->allowZero
                ? 'La cantidad no puede ser negativa.'
                : 'La cantidad debe ser mayor a cero.');
        }
    }
}
