<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DocumentType;
use App\Models\Feature;
use App\Models\Locality;
use App\Models\Plan;
use App\Models\Province;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $customersFeature = Feature::create(['code' => 'customers', 'name' => 'Clientes']);
    $plan->features()->attach($customersFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->documentType = DocumentType::factory()->create();

    $this->customer = Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $province = Province::factory()->create();
    $this->locality = Locality::factory()->create(['province_id' => $province->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

test('lists all addresses for a customer', function () {
    CustomerAddress::factory()->forCustomer($this->customer)->create();
    CustomerAddress::factory()->forCustomer($this->customer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/addresses");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.current_page', 1);
});

test('filters addresses by type', function () {
    CustomerAddress::factory()->forCustomer($this->customer)->ofType('billing')->create();
    CustomerAddress::factory()->forCustomer($this->customer)->ofType('delivery')->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/addresses?type=billing");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.type', 'billing');
});

test('only shows addresses from the authenticated user store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);
    CustomerAddress::factory()->forCustomer($this->customer)->create();
    CustomerAddress::factory()->forCustomer($otherCustomer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/addresses");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items');
});

test('cannot list addresses of customer from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$otherCustomer->id}/addresses");

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('creates a new address', function () {
    $data = [
        'customer_id' => $this->customer->id,
        'locality_id' => $this->locality->id,
        'street' => 'Av. Corrientes',
        'number' => '1234',
        'floor' => '3',
        'apartment' => 'A',
        'postal_code' => 'C1043AAN',
        'latitude' => -34.603722,
        'longitude' => -58.381592,
        'type' => 'billing',
        'is_main' => true,
        'observations' => 'Cerca del obelisco',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/addresses", $data);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.street', 'Av. Corrientes')
        ->assertJsonPath('data.number', '1234')
        ->assertJsonPath('data.type', 'billing')
        ->assertJsonPath('data.is_main', true)
        ->assertJsonStructure([
            'data' => ['id', 'customer_id', 'locality', 'street', 'number', 'floor', 'apartment', 'postal_code', 'latitude', 'longitude', 'type', 'is_main', 'observations', 'created_at', 'updated_at'],
        ]);

    $this->assertDatabaseHas('customer_addresses', [
        'customer_id' => $this->customer->id,
        'street' => 'Av. Corrientes',
    ]);
});

test('fails with invalid locality_id', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/addresses", [
            'customer_id' => $this->customer->id,
            'locality_id' => '00000000-0000-0000-0000-000000000000',
            'street' => 'Av. Corrientes',
            'number' => '1234',
            'postal_code' => 'C1043AAN',
            'type' => 'billing',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['locality_id']);
});

test('fails with invalid type', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/addresses", [
            'customer_id' => $this->customer->id,
            'locality_id' => $this->locality->id,
            'street' => 'Av. Corrientes',
            'number' => '1234',
            'postal_code' => 'C1043AAN',
            'type' => 'invalid_type',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

test('fails without required fields', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/addresses", []);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonValidationErrors(['locality_id', 'street', 'number', 'postal_code', 'type']);
});

test('shows an address', function () {
    $address = CustomerAddress::factory()->forCustomer($this->customer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/addresses/{$address->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $address->id);
});

test('returns 404 for non-existent address', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/addresses/00000000-0000-0000-0000-000000000000");

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('returns 404 when customer does not exist for address show', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/customers/00000000-0000-0000-0000-000000000000/addresses');

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('updates an address', function () {
    $address = CustomerAddress::factory()->forCustomer($this->customer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/customers/{$this->customer->id}/addresses/{$address->id}", [
            'street' => 'Av. Santa Fe',
            'number' => '5678',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.street', 'Av. Santa Fe')
        ->assertJsonPath('data.number', '5678');

    $this->assertDatabaseHas('customer_addresses', [
        'id' => $address->id,
        'street' => 'Av. Santa Fe',
    ]);
});

test('cannot update address from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);
    $otherAddress = CustomerAddress::factory()->forCustomer($otherCustomer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/customers/{$otherCustomer->id}/addresses/{$otherAddress->id}", [
            'street' => 'Hackeado',
        ]);

    $response->assertStatus(404);
});

test('deletes an address', function () {
    $address = CustomerAddress::factory()->forCustomer($this->customer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/customers/{$this->customer->id}/addresses/{$address->id}");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseMissing('customer_addresses', ['id' => $address->id]);
});

test('cannot delete address from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);
    $otherAddress = CustomerAddress::factory()->forCustomer($otherCustomer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/customers/{$otherCustomer->id}/addresses/{$otherAddress->id}");

    $response->assertStatus(404);
});

test('unauthenticated request returns 401', function () {
    $response = $this->getJson("/api/v1/store/customers/{$this->customer->id}/addresses");
    $response->assertStatus(401);
});

test('user without customers feature gets 403', function () {
    $storeWithoutFeature = Store::factory()->create();
    $planWithoutCustomers = Plan::factory()->create();
    $storeWithoutFeature->update(['plan_id' => $planWithoutCustomers->id]);

    $userWithoutFeature = User::factory()->create(['store_id' => $storeWithoutFeature->id]);
    $userWithoutFeature->assignRole('STORE_ADMIN');
    $tokenWithoutFeature = $userWithoutFeature->createToken('test-token')->plainTextToken;

    $customerWithoutFeature = Customer::factory()->forStore($storeWithoutFeature)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $tokenWithoutFeature")
        ->getJson("/api/v1/store/customers/{$customerWithoutFeature->id}/addresses");

    $response->assertStatus(403);
});

test('is_main toggle sets other addresses to false', function () {
    $first = CustomerAddress::factory()->forCustomer($this->customer)->asMain()->create();
    expect($first->fresh()->is_main)->toBeTrue();

    $second = CustomerAddress::factory()->forCustomer($this->customer)->asMain()->create();
    expect($second->fresh()->is_main)->toBeTrue();
    expect($first->fresh()->is_main)->toBeFalse();
});

test('creates address with minimal fields', function () {
    $data = [
        'customer_id' => $this->customer->id,
        'locality_id' => $this->locality->id,
        'street' => 'Calle Falsa',
        'number' => '123',
        'postal_code' => '1000',
        'type' => 'delivery',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/addresses", $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.street', 'Calle Falsa')
        ->assertJsonPath('data.type', 'delivery');
});

test('provides the locality relation on address show', function () {
    $address = CustomerAddress::factory()->forCustomer($this->customer)->create([
        'locality_id' => $this->locality->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/addresses/{$address->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.locality.id', $this->locality->id)
        ->assertJsonPath('data.locality.name', $this->locality->name);
});
