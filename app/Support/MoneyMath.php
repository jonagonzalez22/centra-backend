<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Small exact-money helper for calculations directly affected by decimal
 * physical quantities. Monetary values are normalized to two decimal places
 * using HALF_UP rounding; it is not a replacement for a broader money domain.
 */
final class MoneyMath
{
    public const SCALE = 2;

    private const INPUT_PATTERN = '/^-?\d+(?:\.\d+)?$/D';

    private function __construct() {}

    public static function normalize(int|string $value): string
    {
        $value = (string) $value;

        if (! preg_match(self::INPUT_PATTERN, $value)) {
            throw new InvalidArgumentException('El importe debe ser un decimal válido.');
        }

        return self::roundHalfUp($value, self::SCALE);
    }

    public static function add(int|string $left, int|string $right): string
    {
        return bcadd(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function subtract(int|string $left, int|string $right): string
    {
        return bcsub(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function multiplyQuantityByPrice(int|string $quantity, int|string $price): string
    {
        $product = bcmul(QuantityMath::normalize($quantity), self::normalize($price), 8);

        return self::roundHalfUp($product, self::SCALE);
    }

    public static function proportional(int|string $amount, int|string $part, int|string $whole): string
    {
        if (QuantityMath::isZero($whole)) {
            return '0.00';
        }

        $perUnit = bcdiv(self::normalize($amount), QuantityMath::normalize($whole), 8);

        return self::roundHalfUp(bcmul($perUnit, QuantityMath::normalize($part), 8), self::SCALE);
    }

    /**
     * Round a decimal string with HALF_UP semantics without converting through float.
     */
    public static function roundHalfUp(string $value, int $scale = self::SCALE): string
    {
        if ($scale < 0 || ! preg_match(self::INPUT_PATTERN, $value)) {
            throw new InvalidArgumentException('El importe debe ser un decimal válido.');
        }

        $sign = str_starts_with($value, '-') ? '-' : '';
        $unsigned = ltrim($value, '+-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = str_pad($fraction, $scale + 1, '0');
        $keptFraction = substr($fraction, 0, $scale);
        $discardedFirstDigit = $fraction[$scale] ?? '0';
        $base = $sign.$integer.($scale > 0 ? '.'.$keptFraction : '');

        if ($discardedFirstDigit >= '5') {
            $increment = $scale > 0 ? '0.'.str_repeat('0', $scale - 1).'1' : '1';
            $base = bcadd($base, $sign === '-' ? '-'.$increment : $increment, $scale);
        }

        return bcadd($base, '0', $scale);
    }
}
