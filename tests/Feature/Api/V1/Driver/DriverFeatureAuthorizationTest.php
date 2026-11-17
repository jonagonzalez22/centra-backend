<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);

    $this->store = Store::factory()->create(['plan_id' => null]);
    $this->driver = User::factory()->create(['store_id' => $this->store->id]);
    $this->driver->assignRole('STORE_DRIVER');
    $this->token = $this->driver->createToken('driver-without-deliveries')->plainTextToken;
    $this->routeId = (string) Str::uuid();
    $this->stopId = (string) Str::uuid();
});

dataset('driverEndpoints', [
    ['get', 'active-route', []],
    ['post', 'stops/{stop}/arrive', []],
    ['post', 'stops/{stop}/complete', []],
    ['post', 'stops/{stop}/collection-preview', []],
    ['get', 'routes/{route}/stops', []],
    ['get', 'stops/{stop}', []],
    ['get', 'routes/{route}/available-surplus', []],
    ['post', 'stops/{stop}/extra-sales', []],
    ['get', 'payment-methods', []],
    ['get', 'rejection-reasons', []],
]);

test('every driver endpoint requires deliveries', function (string $method, string $uri, array $payload) {
    $uri = str_replace(['{route}', '{stop}'], [$this->routeId, $this->stopId], $uri);
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->json(strtoupper($method), "/api/v1/driver/{$uri}", $payload);

    $response->assertForbidden();
})->with('driverEndpoints');
