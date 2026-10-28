<?php

use App\Models\Feature;
use App\Models\OperationItem;
use App\Models\Plan;
use App\Models\PriceList;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);

    $this->permissions = ['price_lists.view', 'price_lists.create', 'price_lists.edit', 'price_lists.delete'];
    foreach ($this->permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $feature = Feature::factory()->create(['code' => 'pos']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo($this->permissions);
});

test('lists price lists by store with search and active filters', function () {
    PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Mayorista', 'discount_percentage' => '10.00']);
    PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Inactiva', 'is_active' => false]);
    PriceList::factory()->create(['name' => 'Ajena']);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/price-lists?search=Mayor')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Mayorista')
        ->assertJsonPath('data.items.0.discount_percentage', '10.00');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/price-lists?is_active=0')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Inactiva');
});

test('active selector lists only active store lists without administrative permissions', function () {
    PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Activa']);
    PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Inactiva', 'is_active' => false]);
    PriceList::factory()->create(['name' => 'Ajena']);
    Role::firstOrCreate(['name' => 'STORE_USER', 'guard_name' => 'web']);
    $cashier = User::factory()->create(['store_id' => $this->store->id]);
    $cashier->assignRole('STORE_USER');

    $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/store/price-lists/active')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Activa');
});

test('creates price lists with exact decimal percentages and defaults active', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/price-lists', ['name' => '  Revendedor  ', 'discount_percentage' => '7.5'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Revendedor')
        ->assertJsonPath('data.discount_percentage', '7.50')
        ->assertJsonPath('data.is_active', true);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/price-lists', ['name' => 'Sin descuento', 'discount_percentage' => 0, 'is_active' => false])
        ->assertCreated()
        ->assertJsonPath('data.discount_percentage', '0.00')
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/price-lists', ['name' => 'Máxima', 'discount_percentage' => '100'])
        ->assertCreated()
        ->assertJsonPath('data.discount_percentage', '100.00');
});

test('validates required, unique and bounded price list data per store', function () {
    PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Mayorista']);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/price-lists', ['discount_percentage' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/price-lists', ['name' => 'Mayorista', 'discount_percentage' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    foreach ([-0.01, 100.01, 12.345] as $percentage) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/store/price-lists', ['name' => "Lista {$percentage}", 'discount_percentage' => $percentage])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('discount_percentage');
    }

    $otherStore = Store::factory()->create(['plan_id' => $this->store->plan_id]);
    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherUser->assignRole('STORE_ADMIN');
    $otherUser->givePermissionTo($this->permissions);

    $this->actingAs($otherUser, 'sanctum')
        ->postJson('/api/v1/store/price-lists', ['name' => 'Mayorista', 'discount_percentage' => 10])
        ->assertCreated();
});

test('shows updates and deletes only price lists from the authenticated store', function () {
    $priceList = PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Mayorista']);
    $otherPriceList = PriceList::factory()->create();

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/price-lists/{$priceList->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $priceList->id);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/price-lists/{$priceList->id}", ['name' => 'Distribuidor', 'discount_percentage' => '12.25', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.name', 'Distribuidor')
        ->assertJsonPath('data.discount_percentage', '12.25')
        ->assertJsonPath('data.is_active', false);

    foreach (['getJson', 'putJson', 'deleteJson'] as $method) {
        $response = $method === 'putJson'
            ? $this->actingAs($this->user, 'sanctum')->{$method}("/api/v1/store/price-lists/{$otherPriceList->id}", ['name' => 'Ajena'])
            : $this->actingAs($this->user, 'sanctum')->{$method}("/api/v1/store/price-lists/{$otherPriceList->id}");
        $response->assertNotFound();
    }

    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/v1/store/price-lists/{$priceList->id}")
        ->assertOk();
    $this->assertDatabaseMissing('price_lists', ['id' => $priceList->id]);
});

test('does not delete a list used by an operation and instructs to deactivate it', function () {
    $priceList = PriceList::factory()->create(['store_id' => $this->store->id]);
    OperationItem::factory()->create(['price_list_id' => $priceList->id]);

    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/v1/store/price-lists/{$priceList->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.price_list.0', 'La lista fue utilizada por operaciones históricas. Desactívala en lugar de eliminarla.');

    expect($priceList->fresh())->not->toBeNull();
});

test('price list routes require the pos feature and their corresponding permissions', function () {
    $priceList = PriceList::factory()->create(['store_id' => $this->store->id]);
    $noPermissionUser = User::factory()->create(['store_id' => $this->store->id]);
    $noPermissionUser->assignRole('STORE_ADMIN');

    foreach ([
        'price_lists.view' => ['getJson', '/api/v1/store/price-lists'],
        'price_lists.create' => ['postJson', '/api/v1/store/price-lists', ['name' => 'Nueva', 'discount_percentage' => 10]],
        'price_lists.edit' => ['putJson', "/api/v1/store/price-lists/{$priceList->id}", ['name' => 'Editada']],
        'price_lists.delete' => ['deleteJson', "/api/v1/store/price-lists/{$priceList->id}"],
    ] as $permission => $call) {
        $this->actingAs($noPermissionUser, 'sanctum')->{$call[0]}($call[1], $call[2] ?? [])->assertForbidden();
    }

    $disabledStore = Store::factory()->create(['plan_id' => Plan::factory()->create()->id]);
    $disabledUser = User::factory()->create(['store_id' => $disabledStore->id]);
    $disabledUser->assignRole('STORE_ADMIN');
    $disabledUser->givePermissionTo($this->permissions);

    $this->actingAs($disabledUser, 'sanctum')->getJson('/api/v1/store/price-lists')->assertForbidden();
});
