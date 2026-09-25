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
