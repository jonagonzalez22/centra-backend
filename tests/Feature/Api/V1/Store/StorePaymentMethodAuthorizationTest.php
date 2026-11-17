<?php

use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    foreach (['STORE_ADMIN', 'STORE_USER', 'STORE_DRIVER'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    foreach ([
        'store_payment_methods.view',
        'store_payment_methods.configure',
        'pos.create',
        'orders.collect',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $plan = Plan::factory()->create();
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    foreach (['store_settings', 'pos'] as $code) {
        $plan->features()->attach(Feature::firstOrCreate(['code' => $code], ['name' => $code])->id);
    }

    $this->admin = User::factory()->create(['store_id' => $this->store->id]);
    $this->admin->assignRole('STORE_ADMIN');
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_USER');
    $this->driver = User::factory()->create(['store_id' => $this->store->id]);
    $this->driver->assignRole('STORE_DRIVER');

    $global = PaymentMethod::factory()->create(['name' => 'Transferencia', 'code' => 'transfer']);
    $this->method = StorePaymentMethod::factory()->create([
        'store_id' => $this->store->id,
        'payment_method_id' => $global->id,
        'is_enabled' => true,
        'requires_reference' => true,
        'account_details' => ['alias' => 'tienda.alias', 'account_number' => '123456'],
    ]);
});

function paymentToken(User $user): string
{
    return $user->createToken('payment-method-test')->plainTextToken;
}

test('administrative list requires view permission and preserves the complete configuration for authorized users', function (): void {
    $token = paymentToken($this->user);
    $this->withToken($token)->getJson('/api/v1/store/payment-methods')->assertForbidden();

    $this->user->givePermissionTo('store_payment_methods.view');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/store/payment-methods')
        ->assertOk()
        ->assertJsonStructure(['data' => ['items' => [['account_details']]]]);
});

test('administrative configuration requires configure permission and may be delegated to a store user', function (): void {
    $this->user->givePermissionTo('store_payment_methods.view');
    $token = paymentToken($this->user);

    $this->withToken($token)->patchJson("/api/v1/store/payment-methods/{$this->method->payment_method_id}", [
        'is_enabled' => false,
    ])->assertForbidden();

    $this->user->givePermissionTo('store_payment_methods.configure');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->patchJson("/api/v1/store/payment-methods/{$this->method->payment_method_id}", [
        'is_enabled' => false,
    ])->assertOk();

    expect($this->method->fresh()->is_enabled)->toBeFalse();
});

test('store admin retains configuration access only when the corresponding permissions are assigned', function (): void {
    $token = paymentToken($this->admin);
    $this->withToken($token)->getJson('/api/v1/store/payment-methods')->assertForbidden();

    $this->admin->givePermissionTo(['store_payment_methods.view', 'store_payment_methods.configure']);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->patchJson("/api/v1/store/payment-methods/{$this->method->payment_method_id}", [
        'custom_name' => 'Transferencia CENTRA',
    ])->assertOk();
});

test('administrative endpoints reject drivers and a disabled store settings feature', function (): void {
    $this->withToken(paymentToken($this->driver))->getJson('/api/v1/store/payment-methods')->assertForbidden();

    $this->user->givePermissionTo('store_payment_methods.view');
    $this->store->plan->features()->where('code', 'store_settings')->detach();
    $this->app['auth']->forgetGuards();
    $this->withToken(paymentToken($this->user))->getJson('/api/v1/store/payment-methods')->assertForbidden();
});

test('commercial selector supports POS and order collection without exposing administrative fields or another store methods', function (): void {
    $otherStore = Store::factory()->create();
    $foreign = StorePaymentMethod::factory()->create([
        'store_id' => $otherStore->id,
        'payment_method_id' => PaymentMethod::factory()->create()->id,
        'is_enabled' => true,
        'account_details' => ['alias' => 'otra.tienda'],
    ]);
    $this->user->givePermissionTo('pos.create');

    $response = $this->withToken(paymentToken($this->user))
        ->getJson('/api/v1/store/operations/payment-methods')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $this->method->id)
        ->assertJsonMissingPath('data.items.0.account_details')
        ->assertJsonMissing(['id' => $foreign->id]);

    expect($response->json('data.items.0'))->toHaveKeys(['id', 'name', 'code', 'requires_reference']);
});

test('commercial selector supports authorized order collection without administrative permission', function (): void {
    $this->user->givePermissionTo('orders.collect');

    $this->withToken(paymentToken($this->user))
        ->getJson('/api/v1/store/operations/payment-methods')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $this->method->id)
        ->assertJsonMissingPath('data.items.0.account_details');
});

test('driver commercial selector requires an assigned dispatched route and never returns account details', function (): void {
    $token = paymentToken($this->driver);
    $this->withToken($token)->getJson('/api/v1/driver/payment-methods')->assertNotFound();

    DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => Vehicle::factory()->forStore($this->store),
        'driver_id' => $this->driver->id,
        'status' => 'dispatched',
    ]);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/driver/payment-methods')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $this->method->id)
        ->assertJsonMissingPath('data.items.0.account_details');
});
