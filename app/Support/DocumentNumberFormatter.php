<?php

namespace App\Support;

/** Formats persisted decimal strings for commercial documents without floats. */
final class DocumentNumberFormatter
{
    private function __construct() {}

    public static function money(string $value): string
    {
        $normalized = MoneyMath::normalize($value);
        $negative = str_starts_with($normalized, '-');
        [$integer, $fraction] = explode('.', ltrim($normalized, '-'));

        return ($negative ? '-$ ' : '$ ').self::group($integer).','.$fraction;
    }

    public static function quantity(string $value): string
    {
        $normalized = QuantityMath::normalize($value);
        $negative = str_starts_with($normalized, '-');
        [$integer, $fraction] = explode('.', ltrim($normalized, '-'));
        $fraction = rtrim($fraction, '0');

        return ($negative ? '-' : '').self::group($integer).($fraction === '' ? '' : ','.$fraction);
    }

    public static function quantityWithUnit(string $quantity, string $unitCode, string $unitSymbol): string
    {
        $unit = strtolower($unitCode) === 'unit' ? 'unid.' : $unitSymbol;

        return trim(self::quantity($quantity).' '.$unit);
    }

    private static function group(string $integer): string
    {
        return strrev(implode('.', str_split(strrev($integer), 3)));
    }
}
