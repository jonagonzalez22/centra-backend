<?php

use App\Models\CommercialGroup;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Feature;
use App\Models\Plan;
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

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

test('lists all customers for the store', function () {
    Customer::factory()->forStore($this->store)->create(['document_type_id' => $this->documentType->id]);
    Customer::factory()->forStore($this->store)->create(['document_type_id' => $this->documentType->id]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/customers');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.current_page', 1);
});

test('filters customers by search', function () {
    Customer::factory()->forStore($this->store)->create([
        'display_name' => 'Juan Pérez',
        'document_type_id' => $this->documentType->id,
    ]);
    Customer::factory()->forStore($this->store)->create([
        'display_name' => 'María García',
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/customers?search=juan');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.display_name', 'Juan Pérez');
});

test('filters customers by status', function () {
    Customer::factory()->forStore($this->store)->create([
        'display_name' => 'Juan Pérez',
        'status' => 'active',
        'document_type_id' => $this->documentType->id,
    ]);
    Customer::factory()->forStore($this->store)->inactive()->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/customers?status=inactive');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.status', 'inactive');
});

test('only shows customers from the authenticated user store', function () {
    $otherStore = Store::factory()->create();
    Customer::factory()->forStore($this->store)->create(['document_type_id' => $this->documentType->id]);
    Customer::factory()->forStore($otherStore)->create(['document_type_id' => $this->documentType->id]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/customers');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items');
});

test('creates a new customer', function () {
    $data = [
        'display_name' => 'Juan Pérez',
        'first_name' => 'Juan',
        'last_name' => 'Pérez',
        'document_type_id' => $this->documentType->id,
        'document_number' => '20-12345678-5',
        'status' => 'active',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/customers', $data);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.display_name', 'Juan Pérez')
        ->assertJsonPath('data.document_number', '20-12345678-5')
        ->assertJsonStructure([
            'data' => ['id', 'customer_code', 'display_name', 'document_type', 'document_number', 'status', 'created_at', 'updated_at'],
        ]);

    $this->assertDatabaseHas('customers', [
        'store_id' => $this->store->id,
        'display_name' => 'Juan Pérez',
    ]);
});

test('generates customer_code and normalized fields on create', function () {
    $data = [
        'display_name' => 'Juan Pérez',
        'document_type_id' => $this->documentType->id,
        'document_number' => '20-12345678-5',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/customers', $data);

    $response->assertStatus(201);

    $customerId = $response->json('data.id');

    $this->assertDatabaseHas('customers', [
        'id' => $customerId,
        'customer_code' => 'C-000001',
        'document_number_normalized' => '20123456785',
    ]);

    $customer = Customer::find($customerId);
    expect($customer->search_text)->toContain('juan perez');
    expect($customer->search_text)->toContain('20123456785');
    expect($customer->search_text)->toContain('c-000001');
});

test('increments customer_code per store', function () {
    Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
        'document_number' => '11-11111111-1',
    ]);

    $data = [
        'display_name' => 'Otro Cliente',
        'document_type_id' => $this->documentType->id,
        'document_number' => '22-22222222-2',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/customers', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer_code', 'C-000002');
});

test('fails with duplicate document number in same store', function () {
    Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
        'document_number' => '20-12345678-5',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/customers', [
            'display_name' => 'Juan Pérez',
            'document_type_id' => $this->documentType->id,
            'document_number' => '20-12345678-5',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonValidationErrors(['document_number']);
});

test('allows duplicate document number in different stores', function () {
    Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
        'document_number' => '20-12345678-5',
    ]);

    $otherStore = Store::factory()->create();
    $otherPlan = Plan::factory()->create();
    $customersFeature = Feature::where('code', 'customers')->first();
    $otherPlan->features()->attach($customersFeature->id);
    $otherStore->update(['plan_id' => $otherPlan->id]);

    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherUser->assignRole('STORE_ADMIN');
    $otherToken = $otherUser->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $otherToken")
        ->postJson('/api/v1/store/customers', [
            'display_name' => 'Juan Pérez',
            'document_type_id' => $this->documentType->id,
            'document_number' => '20-12345678-5',
        ]);

    $response->assertStatus(201);
});

test('fails without required fields', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/customers', []);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonValidationErrors(['display_name', 'document_type_id', 'document_number']);
});

test('shows a customer', function () {
    $customer = Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$customer->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $customer->id);
});

test('returns 404 for non-existent customer', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/customers/00000000-0000-0000-0000-000000000000');

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('cannot view customer from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$otherCustomer->id}");

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('updates a customer', function () {
    $customer = Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/customers/{$customer->id}", [
            'display_name' => 'Nombre Actualizado',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.display_name', 'Nombre Actualizado');

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'display_name' => 'Nombre Actualizado',
    ]);
});

test('regenerates search_text on update', function () {
    $customer = Customer::factory()->forStore($this->store)->create([
        'display_name' => 'Juan Pérez',
        'document_type_id' => $this->documentType->id,
        'document_number' => '20-12345678-5',
    ]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/customers/{$customer->id}", [
            'display_name' => 'Pedro López',
        ]);

    $customer->refresh();
    expect($customer->search_text)->toContain('pedro lopez');
    expect($customer->search_text)->not->toContain('juan perez');
});

test('cannot update customer from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/customers/{$otherCustomer->id}", [
            'display_name' => 'Hackeado',
        ]);

    $response->assertStatus(404);
});

test('deletes a customer', function () {
    $customer = Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/customers/{$customer->id}");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
});

test('cannot delete customer from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/customers/{$otherCustomer->id}");

    $response->assertStatus(404);
});

test('unauthenticated request returns 401', function () {
    $response = $this->getJson('/api/v1/store/customers');
    $response->assertStatus(401);
});

test('user without customers feature gets 403', function () {
    $storeWithoutFeature = Store::factory()->create();
    $planWithoutCustomers = Plan::factory()->create();
    $storeWithoutFeature->update(['plan_id' => $planWithoutCustomers->id]);

    $userWithoutFeature = User::factory()->create(['store_id' => $storeWithoutFeature->id]);
    $userWithoutFeature->assignRole('STORE_ADMIN');
    $tokenWithoutFeature = $userWithoutFeature->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $tokenWithoutFeature")
        ->getJson('/api/v1/store/customers');

    $response->assertStatus(403);
});

test('creates customer with commercial group', function () {
    $commercialGroup = CommercialGroup::factory()->forStore($this->store)->create();

    $data = [
        'display_name' => 'Juan Pérez',
        'document_type_id' => $this->documentType->id,
        'document_number' => '20-12345678-5',
        'commercial_group_id' => $commercialGroup->id,
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/customers', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.commercial_group.id', $commercialGroup->id);
});

test('fails with commercial group from another store', function () {
    $otherStore = Store::factory()->create();
    $otherGroup = CommercialGroup::factory()->forStore($otherStore)->create();

    $data = [
        'display_name' => 'Juan Pérez',
        'document_type_id' => $this->documentType->id,
        'document_number' => '20-12345678-5',
        'commercial_group_id' => $otherGroup->id,
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/customers', $data);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['commercial_group_id']);
});

test('normalizes document_number removing non-numeric characters', function () {
    $data = [
        'display_name' => 'Test',
        'document_type_id' => $this->documentType->id,
        'document_number' => 'ABC-20.123.456/78-5#',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/customers', $data);

    $response->assertStatus(201);

    $customerId = $response->json('data.id');
    $this->assertDatabaseHas('customers', [
        'id' => $customerId,
        'document_number_normalized' => '20123456785',
    ]);
});

test('search works with accented characters', function () {
    Customer::factory()->forStore($this->store)->create([
        'display_name' => 'María José Rodríguez',
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/customers?search=maria');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.display_name', 'María José Rodríguez');
});
