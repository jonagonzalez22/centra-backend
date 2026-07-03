<?php

use App\Models\DocumentType;
use App\Models\Locality;
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
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

test('document types returns all document types for authenticated user', function () {
    DocumentType::factory()->create(['code' => 'DNI', 'name' => 'DNI']);
    DocumentType::factory()->create(['code' => 'CUIT', 'name' => 'CUIT']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/catalogs/document-types');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('status', 'success');

    $codes = collect($response->json('data.items'))->pluck('code')->toArray();
    expect($codes)->toContain('DNI')->toContain('CUIT');
});

test('document types returns 401 for unauthenticated user', function () {
    $response = $this->getJson('/api/v1/catalogs/document-types');

    $response->assertStatus(401);
});

test('provinces returns all provinces for authenticated user', function () {
    Province::factory()->create(['name' => 'Mendoza']);
    Province::factory()->create(['name' => 'Buenos Aires']);
    Province::factory()->create(['name' => 'Córdoba']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/catalogs/provinces');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('status', 'success');
});

test('provinces returns provinces ordered alphabetically', function () {
    Province::factory()->create(['name' => 'Mendoza']);
    Province::factory()->create(['name' => 'Buenos Aires']);
    Province::factory()->create(['name' => 'Córdoba']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/catalogs/provinces');

    $response->assertStatus(200);
    $items = $response->json('data.items');
    expect($items[0]['name'])->toBe('Buenos Aires');
    expect($items[1]['name'])->toBe('Córdoba');
    expect($items[2]['name'])->toBe('Mendoza');
});

test('provinces supports with_localities_count parameter', function () {
    $province = Province::factory()->create(['name' => 'Mendoza']);
    Locality::factory()->count(3)->create(['province_id' => $province->id]);
    Locality::factory()->count(2)->create(); // another province

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/catalogs/provinces?with_localities_count=true');

    $response->assertStatus(200);

    $provinces = collect($response->json('data.items'));
    $mendoza = $provinces->firstWhere('name', 'Mendoza');
    expect($mendoza['localities_count'])->toBe(3);
});

test('provinces returns 401 for unauthenticated user', function () {
    $response = $this->getJson('/api/v1/catalogs/provinces');

    $response->assertStatus(401);
});

test('localities returns paginated localities for a province', function () {
    $province = Province::factory()->create(['name' => 'Mendoza']);
    Locality::factory()->count(60)->create(['province_id' => $province->id]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/catalogs/provinces/{$province->id}/localities?per_page=50");

    $response->assertStatus(200)
        ->assertJsonCount(50, 'data.items')
        ->assertJsonPath('data.total', 60)
        ->assertJsonPath('data.per_page', 50)
        ->assertJsonPath('data.current_page', 1)
        ->assertJsonPath('data.last_page', 2)
        ->assertJsonPath('status', 'success');
});

test('localities returns localities ordered alphabetically', function () {
    $province = Province::factory()->create(['name' => 'Mendoza']);
    Locality::factory()->create(['province_id' => $province->id, 'name' => 'Godoy Cruz']);
    Locality::factory()->create(['province_id' => $province->id, 'name' => 'Las Heras']);
    Locality::factory()->create(['province_id' => $province->id, 'name' => 'San Rafael']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/catalogs/provinces/{$province->id}/localities");

    $response->assertStatus(200);
    $items = $response->json('data.items');
    expect($items[0]['name'])->toBe('Godoy Cruz');
    expect($items[1]['name'])->toBe('Las Heras');
    expect($items[2]['name'])->toBe('San Rafael');
});

test('localities returns 404 for non-existent province', function () {
    $fakeUuid = '00000000-0000-0000-0000-000000000000';

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/catalogs/provinces/{$fakeUuid}/localities");

    $response->assertStatus(404);
});

test('localities returns 401 for unauthenticated user', function () {
    $province = Province::factory()->create();

    $response = $this->getJson("/api/v1/catalogs/provinces/{$province->id}/localities");

    $response->assertStatus(401);
});

test('localities uses route model binding with province parameter', function () {
    $province = Province::factory()->create(['name' => 'Mendoza']);
    Locality::factory()->count(5)->create(['province_id' => $province->id]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/catalogs/provinces/{$province->id}/localities");

    $response->assertStatus(200)
        ->assertJsonCount(5, 'data.items');
});
