<?php

use App\Support\QuantityMath;

describe('QuantityMath', function () {
    it('normalizes integers and decimal strings to the fixed scale', function () {
        expect(QuantityMath::normalize(1))->toBe('1.0000')
            ->and(QuantityMath::normalize('1'))->toBe('1.0000')
            ->and(QuantityMath::normalize('1.2'))->toBe('1.2000')
            ->and(QuantityMath::normalize('1.25'))->toBe('1.2500')
            ->and(QuantityMath::normalize('1.2500'))->toBe('1.2500')
            ->and(QuantityMath::normalize('0'))->toBe('0.0000')
            ->and(QuantityMath::normalize('-1.25'))->toBe('-1.2500');
    });

    it('accepts values at four decimal positions and rejects excess precision', function () {
        expect(QuantityMath::normalize('0.0001'))->toBe('0.0001')
            ->and(QuantityMath::normalize('1.1234'))->toBe('1.1234');

        expect(fn () => QuantityMath::normalize('1.12345'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('adds, subtracts, and multiplies exact quantities', function () {
        expect(QuantityMath::add('1.2500', '0.5000'))->toBe('1.7500')
            ->and(QuantityMath::add(1, 2))->toBe('3.0000')
            ->and(QuantityMath::add('1.2500', '0'))->toBe('1.2500')
            ->and(QuantityMath::subtract('5.0000', '1.1250'))->toBe('3.8750')
            ->and(QuantityMath::subtract('1.2500', '1.2500'))->toBe('0.0000')
            ->and(QuantityMath::subtract('1.0000', '1.1250'))->toBe('-0.1250')
            ->and(QuantityMath::multiply('2.5000', '5.0000'))->toBe('12.5000')
            ->and(QuantityMath::multiply(2, '1.2500'))->toBe('2.5000')
            ->and(QuantityMath::multiply('2.3750', '5.0000'))->toBe('11.8750');
    });

    it('refuses multiplication results that cannot be persisted at the fixed scale', function () {
        expect(fn () => QuantityMath::multiply('0.0001', '0.0001'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('compares quantities without float conversion', function () {
        expect(QuantityMath::compare('1.2500', '1.2500'))->toBe(0)
            ->and(QuantityMath::compare('2.0000', '1.9999'))->toBe(1)
            ->and(QuantityMath::compare('1.0000', '1.0001'))->toBe(-1);
    });

    it('provides exact min, max, and sign helpers', function () {
        expect(QuantityMath::min('1.2500', '1.2501'))->toBe('1.2500')
            ->and(QuantityMath::max('1.2500', '1.2501'))->toBe('1.2501')
            ->and(QuantityMath::isZero('0.0000'))->toBeTrue()
            ->and(QuantityMath::isPositive('0.0001'))->toBeTrue()
            ->and(QuantityMath::isNegative('-0.0001'))->toBeTrue()
            ->and(QuantityMath::isPositive('0.0000'))->toBeFalse()
            ->and(QuantityMath::isNegative('0.0000'))->toBeFalse();
    });

    it('validates exact multiples without floating point arithmetic', function () {
        expect(QuantityMath::isMultipleOf('1.0000', '1.0000'))->toBeTrue()
            ->and(QuantityMath::isMultipleOf('1.5000', '1.0000'))->toBeFalse()
            ->and(QuantityMath::isMultipleOf('0.5000', '0.2500'))->toBeTrue()
            ->and(QuantityMath::isMultipleOf('0.3000', '0.2500'))->toBeFalse()
            ->and(QuantityMath::isMultipleOf('1.2500', '0.2500'))->toBeTrue()
            ->and(QuantityMath::isMultipleOf('1.2300', '0.0100'))->toBeTrue()
            ->and(QuantityMath::isMultipleOf('1.2340', '0.0010'))->toBeTrue()
            ->and(QuantityMath::isMultipleOf('0.0000', '0.5000'))->toBeTrue();

        expect(fn () => QuantityMath::isMultipleOf('1.0000', '0.0000'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('floors quantities to exact multiples', function () {
        expect(QuantityMath::floorToMultiple('7.5000', '1.0000'))->toBe('7.0000')
            ->and(QuantityMath::floorToMultiple('7.5000', '0.5000'))->toBe('7.5000')
            ->and(QuantityMath::floorToMultiple('7.6300', '0.2500'))->toBe('7.5000')
            ->and(QuantityMath::floorToMultiple('0.2000', '0.5000'))->toBe('0.0000')
            ->and(QuantityMath::floorToMultiple('10000.9999', '0.0010'))->toBe('10000.9990')
            ->and(QuantityMath::floorToMultiple('0.0000', '0.0100'))->toBe('0.0000')
            ->and(QuantityMath::floorToMultiple('-1.0000', '0.5000'))->toBe('0.0000');

        expect(fn () => QuantityMath::floorToMultiple('1.0000', '0'))
            ->toThrow(InvalidArgumentException::class);
    });
});
