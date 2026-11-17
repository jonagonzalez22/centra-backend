<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\DocumentType;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Support\PermissionFeatureResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Store::clearFeatureCache();
    PermissionFeatureResolver::clearCache();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);
    foreach ([
        'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
        'customer_addresses.view', 'customer_addresses.create', 'customer_addresses.edit', 'customer_addresses.delete',
        'customer_contacts.view', 'customer_contacts.create', 'customer_contacts.edit', 'customer_contacts.delete',
        'pos.view', 'orders.edit',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $customers = Feature::firstOrCreate(['code' => 'customers'], ['name' => 'Clientes']);
    $pos = Feature::firstOrCreate(['code' => 'pos'], ['name' => 'Punto de Venta']);
    $plan = Plan::factory()->create();
    $plan->features()->attach([$customers->id, $pos->id]);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->documentType = DocumentType::factory()->create();
    $this->customer = Customer::factory()->forStore($this->store)->create([
        'document_type_id' => $this->documentType->id,
        'display_name' => 'Cliente propio',
    ]);
    $this->address = CustomerAddress::factory()->forCustomer($this->customer)->create();
    $this->contact = CustomerContact::factory()->forCustomer($this->customer)->create();
});

function customerAuthorizationUser(Store $store, array $permissions = []): User
{
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('STORE_USER');
    if ($permissions) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}

test('store user without permissions cannot access administrative customers, addresses, or contacts', function () {
    $user = customerAuthorizationUser($this->store);
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/store/customers')->assertForbidden();
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/store/customers/{$this->customer->id}/addresses")->assertForbidden();
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/store/customers/{$this->customer->id}/contacts")->assertForbidden();
});

test('read permissions do not grant customer, address, or contact writes', function () {
    $user = customerAuthorizationUser($this->store, [
        'customers.view', 'customer_addresses.view', 'customer_contacts.view',
    ]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/store/customers')->assertOk();
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/store/customers/{$this->customer->id}/addresses")->assertOk();
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/store/customers/{$this->customer->id}/contacts")->assertOk();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/store/customers', [])->assertForbidden();
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/store/customers/{$this->customer->id}/addresses", [])->assertForbidden();
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/store/customers/{$this->customer->id}/contacts", [])->assertForbidden();
    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/store/customers/{$this->customer->id}", [])->assertForbidden();
    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/store/customers/{$this->customer->id}")->assertForbidden();
});

test('administrative customer resources remain hidden across tenants', function () {
    $otherStore = Store::factory()->create(['plan_id' => $this->store->plan_id]);
    $otherCustomer = Customer::factory()->forStore($otherStore)->create(['document_type_id' => $this->documentType->id]);
    $otherAddress = CustomerAddress::factory()->forCustomer($otherCustomer)->create();
    $otherContact = CustomerContact::factory()->forCustomer($otherCustomer)->create();
    $user = customerAuthorizationUser($this->store, [
        'customers.view', 'customers.edit', 'customer_addresses.view', 'customer_addresses.edit',
        'customer_contacts.view', 'customer_contacts.edit',
    ]);

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/store/customers/{$otherCustomer->id}")->assertNotFound();
    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/store/customers/{$otherCustomer->id}", ['display_name' => 'No'])->assertNotFound();
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/store/customers/{$otherCustomer->id}/addresses/{$otherAddress->id}")->assertNotFound();
    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/store/customers/{$otherCustomer->id}/contacts/{$otherContact->id}", ['name' => 'No'])->assertNotFound();
});

test('POS customer selection remains available without customers view and stays tenant scoped', function () {
    $otherStore = Store::factory()->create(['plan_id' => $this->store->plan_id]);
    Customer::factory()->forStore($otherStore)->create([
        'document_type_id' => $this->documentType->id,
        'display_name' => 'Cliente ajeno',
    ]);
    $user = customerAuthorizationUser($this->store, ['pos.view']);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/store/customers')->assertForbidden();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/store/operations/customers/search?search=cliente&per_page=20')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $this->customer->id);
});
