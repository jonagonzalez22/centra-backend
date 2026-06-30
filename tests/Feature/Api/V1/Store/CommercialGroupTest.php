<?php

use App\Models\CommercialGroup;
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

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

test('lists all commercial groups for the store', function () {
    CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes VIP']);
    CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes Premium']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/commercial-groups');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.current_page', 1);
});

test('filters commercial groups by name', function () {
    CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes VIP']);
    CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes Premium']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/commercial-groups?name=VIP');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Clientes VIP');
});

test('only shows groups from the authenticated user store', function () {
    $otherStore = Store::factory()->create();
    CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Mi Grupo']);
    CommercialGroup::create(['store_id' => $otherStore->id, 'name' => 'Otro Grupo']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/commercial-groups');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Mi Grupo');
});

test('creates a new commercial group', function () {
    $data = [
        'name' => 'Clientes VIP',
        'description' => 'Grupo con descuentos especiales',
        'settings' => '{"discount_percent": 10}',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/commercial-groups', $data);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.name', 'Clientes VIP')
        ->assertJsonPath('data.store_id', $this->store->id)
        ->assertJsonStructure([
            'data' => ['id', 'store_id', 'name', 'description', 'settings', 'created_at', 'updated_at'],
        ]);

    $this->assertDatabaseHas('commercial_groups', [
        'store_id' => $this->store->id,
        'name' => 'Clientes VIP',
    ]);
});

test('fails with duplicate name in same store', function () {
    CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes VIP']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/commercial-groups', ['name' => 'Clientes VIP']);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonValidationErrors(['name']);
});

test('allows duplicate name in different stores', function () {
    CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes VIP']);

    $otherStore = Store::factory()->create();
    $otherPlan = Plan::factory()->create();
    $customersFeature = Feature::where('code', 'customers')->first();
    $otherPlan->features()->attach($customersFeature->id);
    $otherStore->update(['plan_id' => $otherPlan->id]);

    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherUser->assignRole('STORE_ADMIN');
    $otherToken = $otherUser->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $otherToken")
        ->postJson('/api/v1/store/commercial-groups', ['name' => 'Clientes VIP']);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Clientes VIP');
});

test('fails without name', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/commercial-groups', []);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonValidationErrors(['name']);
});

test('fails with short name', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/commercial-groups', ['name' => 'A']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('shows a commercial group', function () {
    $group = CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes VIP']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/commercial-groups/$group->id");

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Clientes VIP');
});

test('returns 404 for non-existent group', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/commercial-groups/00000000-0000-0000-0000-000000000000');

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('cannot view group from another store', function () {
    $otherStore = Store::factory()->create();
    $otherGroup = CommercialGroup::create(['store_id' => $otherStore->id, 'name' => 'Otro Grupo']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/commercial-groups/$otherGroup->id");

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('updates a commercial group', function () {
    $group = CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes VIP']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/commercial-groups/$group->id", [
            'name' => 'Clientes Premium',
            'description' => 'Actualizado',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Clientes Premium')
        ->assertJsonPath('data.description', 'Actualizado');

    $this->assertDatabaseHas('commercial_groups', [
        'id' => $group->id,
        'name' => 'Clientes Premium',
    ]);
});

test('cannot update group from another store', function () {
    $otherStore = Store::factory()->create();
    $otherGroup = CommercialGroup::create(['store_id' => $otherStore->id, 'name' => 'Otro Grupo']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/commercial-groups/$otherGroup->id", [
            'name' => 'Hackeado',
        ]);

    $response->assertStatus(404);
});

test('deletes a commercial group', function () {
    $group = CommercialGroup::create(['store_id' => $this->store->id, 'name' => 'Clientes VIP']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/commercial-groups/$group->id");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseMissing('commercial_groups', ['id' => $group->id]);
});

test('cannot delete group from another store', function () {
    $otherStore = Store::factory()->create();
    $otherGroup = CommercialGroup::create(['store_id' => $otherStore->id, 'name' => 'Otro Grupo']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/commercial-groups/$otherGroup->id");

    $response->assertStatus(404);
});

test('unauthenticated request returns 401', function () {
    $response = $this->getJson('/api/v1/store/commercial-groups');
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
        ->getJson('/api/v1/store/commercial-groups');

    $response->assertStatus(403);
});
