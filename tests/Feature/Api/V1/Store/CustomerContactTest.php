<?php

use App\Models\Customer;
use App\Models\CustomerContact;
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

    $this->customer = Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

test('lists all contacts for a customer', function () {
    CustomerContact::factory()->forCustomer($this->customer)->create();
    CustomerContact::factory()->forCustomer($this->customer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/contacts");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.current_page', 1);
});

test('only shows contacts from the authenticated user store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);
    CustomerContact::factory()->forCustomer($this->customer)->create();
    CustomerContact::factory()->forCustomer($otherCustomer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/contacts");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items');
});

test('cannot list contacts of customer from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$otherCustomer->id}/contacts");

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('creates a new contact', function () {
    $data = [
        'customer_id' => $this->customer->id,
        'name' => 'Juan Pérez',
        'position' => 'Gerente',
        'email' => 'juan@ejemplo.com',
        'phone' => '+54 11 1234-5678',
        'is_main' => true,
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/contacts", $data);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.name', 'Juan Pérez')
        ->assertJsonPath('data.email', 'juan@ejemplo.com')
        ->assertJsonPath('data.is_main', true)
        ->assertJsonStructure([
            'data' => ['id', 'customer_id', 'name', 'position', 'email', 'phone', 'is_main', 'created_at', 'updated_at'],
        ]);

    $this->assertDatabaseHas('customer_contacts', [
        'customer_id' => $this->customer->id,
        'name' => 'Juan Pérez',
    ]);
});

test('creates a contact with minimal fields', function () {
    $data = [
        'customer_id' => $this->customer->id,
        'name' => 'María García',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/contacts", $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'María García');
});

test('fails without name', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/contacts", []);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonValidationErrors(['name']);
});

test('fails with invalid email', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/contacts", [
            'customer_id' => $this->customer->id,
            'name' => 'Test',
            'email' => 'not-an-email',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('shows a contact', function () {
    $contact = CustomerContact::factory()->forCustomer($this->customer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/contacts/{$contact->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $contact->id);
});

test('returns 404 for non-existent contact', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/customers/{$this->customer->id}/contacts/00000000-0000-0000-0000-000000000000");

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('returns 404 when customer does not exist for contact show', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/customers/00000000-0000-0000-0000-000000000000/contacts');

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('updates a contact', function () {
    $contact = CustomerContact::factory()->forCustomer($this->customer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/customers/{$this->customer->id}/contacts/{$contact->id}", [
            'name' => 'Nombre Actualizado',
            'position' => 'CEO',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Nombre Actualizado')
        ->assertJsonPath('data.position', 'CEO');

    $this->assertDatabaseHas('customer_contacts', [
        'id' => $contact->id,
        'name' => 'Nombre Actualizado',
    ]);
});

test('cannot update contact from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);
    $otherContact = CustomerContact::factory()->forCustomer($otherCustomer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/customers/{$otherCustomer->id}/contacts/{$otherContact->id}", [
            'name' => 'Hackeado',
        ]);

    $response->assertStatus(404);
});

test('deletes a contact', function () {
    $contact = CustomerContact::factory()->forCustomer($this->customer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/customers/{$this->customer->id}/contacts/{$contact->id}");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseMissing('customer_contacts', ['id' => $contact->id]);
});

test('cannot delete contact from another store', function () {
    $otherStore = Store::factory()->create();
    $otherCustomer = Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
    ]);
    $otherContact = CustomerContact::factory()->forCustomer($otherCustomer)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/customers/{$otherCustomer->id}/contacts/{$otherContact->id}");

    $response->assertStatus(404);
});

test('unauthenticated request returns 401', function () {
    $response = $this->getJson("/api/v1/store/customers/{$this->customer->id}/contacts");
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
        ->getJson("/api/v1/store/customers/{$customerWithoutFeature->id}/contacts");

    $response->assertStatus(403);
});

test('is_main toggle sets other contacts to false', function () {
    $first = CustomerContact::factory()->forCustomer($this->customer)->asMain()->create();
    expect($first->fresh()->is_main)->toBeTrue();

    $second = CustomerContact::factory()->forCustomer($this->customer)->asMain()->create();
    expect($second->fresh()->is_main)->toBeTrue();
    expect($first->fresh()->is_main)->toBeFalse();
});

test('creates contact with only name', function () {
    $data = [
        'customer_id' => $this->customer->id,
        'name' => 'Solo Nombre',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/customers/{$this->customer->id}/contacts", $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Solo Nombre')
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.phone', null)
        ->assertJsonPath('data.position', null);
});
