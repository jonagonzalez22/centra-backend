<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact arithmetic for physical quantities persisted at DECIMAL(18,4).
 *
 * Values are accepted as integers or decimal strings with at most four
 * fractional digits. Every returned value is a normalized decimal string with
 * exactly four fractional digits. Floats are deliberately not accepted.
 */
final class QuantityMath
{
    public const SCALE = 4;

    private const INPUT_PATTERN = '/^-?\d+(?:\.\d{1,4})?$/D';

    private function __construct() {}

    public static function normalize(int|string $value): string
    {
        $value = (string) $value;

        if (! preg_match(self::INPUT_PATTERN, $value)) {
            throw new InvalidArgumentException(sprintf(
                'La cantidad "%s" debe ser un entero o decimal con hasta %d posiciones decimales.',
                $value,
                self::SCALE,
            ));
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function add(int|string $left, int|string $right): string
    {
        return bcadd(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function subtract(int|string $left, int|string $right): string
    {
        return bcsub(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function multiply(int|string $left, int|string $right): string
    {
        $result = bcmul(self::normalize($left), self::normalize($right), self::SCALE * 2);
        $normalized = bcadd($result, '0', self::SCALE);

        // A product that needs more than the persisted scale must be handled
        // explicitly by its caller; silently truncating it would lose stock.
        if (bccomp($result, $normalized, self::SCALE * 2) !== 0) {
            throw new InvalidArgumentException(sprintf(
                'El resultado "%s" excede la precisión de %d posiciones decimales para cantidades.',
                $result,
                self::SCALE,
            ));
        }

        return $normalized;
    }

    public static function compare(int|string $left, int|string $right): int
    {
        return bccomp(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function min(int|string $left, int|string $right): string
    {
        return self::compare($left, $right) <= 0 ? self::normalize($left) : self::normalize($right);
    }

    public static function max(int|string $left, int|string $right): string
    {
        return self::compare($left, $right) >= 0 ? self::normalize($left) : self::normalize($right);
    }

    public static function isZero(int|string $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isPositive(int|string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isNegative(int|string $value): bool
    {
        return self::compare($value, '0') < 0;
    }
}
