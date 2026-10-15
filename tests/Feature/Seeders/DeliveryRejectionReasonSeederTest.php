<?php

use App\Models\DeliveryRejectionReason;
use App\Models\Store;
use Database\Seeders\DeliveryRejectionReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('creates the expected global rejection reasons', function () {
    $this->seed(DeliveryRejectionReasonSeeder::class);

    $expectedReasons = [
        'customer_absent' => ['Cliente ausente', true],
        'wrong_address' => ['Dirección incorrecta', true],
        'rejected_by_customer' => ['Mercadería rechazada por el cliente', true],
        'access_issue' => ['Problema de acceso al domicilio', true],
        'damaged_goods' => ['Mercadería dañada', false],
        'no_payment' => ['Cliente sin efectivo para abonar', true],
    ];

    $globalReasons = DeliveryRejectionReason::query()
        ->whereNull('store_id')
        ->orderBy('code')
        ->get();

    expect($globalReasons)->toHaveCount(6);

    foreach ($expectedReasons as $code => [$label, $suggestExtraSale]) {
        $reason = $globalReasons->firstWhere('code', $code);

        expect($reason)
            ->not->toBeNull()
            ->code->toBe($code)
            ->label->toBe($label)
            ->is_active->toBeTrue()
            ->suggest_extra_sale->toBe($suggestExtraSale);
    }
});

test('can run repeatedly without duplicating global rejection reasons', function () {
    $this->seed(DeliveryRejectionReasonSeeder::class);
    $this->seed(DeliveryRejectionReasonSeeder::class);

    expect(DeliveryRejectionReason::query()->whereNull('store_id')->count())->toBe(6);
});

test('preserves the active state of existing global rejection reasons', function () {
    $reason = DeliveryRejectionReason::create([
        'code' => 'customer_absent',
        'label' => 'Etiqueta anterior',
        'is_active' => false,
        'suggest_extra_sale' => false,
    ]);

    $this->seed(DeliveryRejectionReasonSeeder::class);

    expect($reason->fresh())
        ->is_active->toBeFalse()
        ->label->toBe('Cliente ausente')
        ->suggest_extra_sale->toBeTrue();
});

test('does not modify store-specific rejection reasons with a global code', function () {
    $store = Store::factory()->create();
    $storeReason = DeliveryRejectionReason::create([
        'store_id' => $store->id,
        'code' => 'customer_absent',
        'label' => 'Motivo específico',
        'is_active' => false,
        'suggest_extra_sale' => false,
    ]);

    $this->seed(DeliveryRejectionReasonSeeder::class);

    expect($storeReason->fresh())
        ->label->toBe('Motivo específico')
        ->is_active->toBeFalse()
        ->suggest_extra_sale->toBeFalse();
});
