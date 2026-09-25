<?php

use App\Support\MoneyMath;

test('it calculates a decimal quantity subtotal with half-up rounding', function () {
    expect(MoneyMath::multiplyQuantityByPrice('2.3750', '3333.33'))->toBe('7916.66');
});

test('it rounds monetary values half up without float arithmetic', function () {
    expect(MoneyMath::roundHalfUp('1.005'))->toBe('1.01')
        ->and(MoneyMath::roundHalfUp('-1.005'))->toBe('-1.01')
        ->and(MoneyMath::proportional('5.00', '2.0000', '5.0000'))->toBe('2.00');
});

test('it compares and bounds monetary values without float arithmetic', function () {
    expect(MoneyMath::compare('10.00', '9.99'))->toBe(1)
        ->and(MoneyMath::min('10.00', '9.99'))->toBe('9.99')
        ->and(MoneyMath::max('10.00', '9.99'))->toBe('10.00');
});
