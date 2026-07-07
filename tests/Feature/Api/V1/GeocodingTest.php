<?php

use App\Models\Store;
use App\Models\User;
use App\Services\Geocoding\DTOs\GeocodingResult;
use App\Services\Geocoding\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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

afterEach(function () {
    Mockery::close();
});

test('geocoding search accepts address parameter', function () {
    $mockResult = new GeocodingResult(
        latitude: -31.4201,
        longitude: -64.1888,
        formatted_address: 'Av. Colón 77, Córdoba, X5000JJB, Argentina',
        provider: 'google'
    );

    $mockGeocodingService = Mockery::mock(GeocodingService::class);
    $mockGeocodingService->shouldReceive('search')
        ->with('Av. Colón 77, Córdoba, Argentina')
        ->andReturn($mockResult);

    $this->app->instance(GeocodingService::class, $mockGeocodingService);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', [
            'address' => 'Av. Colón 77, Córdoba, Argentina'
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.latitude', -31.4201)
        ->assertJsonPath('data.longitude', -64.1888)
        ->assertJsonPath('data.formatted_address', 'Av. Colón 77, Córdoba, X5000JJB, Argentina')
        ->assertJsonPath('data.provider', 'google');
});

test('geocoding search accepts input parameter for compatibility', function () {
    $mockResult = new GeocodingResult(
        latitude: -31.4201,
        longitude: -64.1888,
        formatted_address: 'Av. Colón 77, Córdoba, X5000JJB, Argentina',
        provider: 'google'
    );

    $mockGeocodingService = Mockery::mock(GeocodingService::class);
    $mockGeocodingService->shouldReceive('search')
        ->with('Av. Colón 77, Córdoba, Argentina')
        ->andReturn($mockResult);

    $this->app->instance(GeocodingService::class, $mockGeocodingService);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', [
            'input' => 'Av. Colón 77, Córdoba, Argentina'
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.latitude', -31.4201);
});

test('geocoding search rejects when neither address nor input provided', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', []);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

test('geocoding search rejects when both address and input provided', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', [
            'address' => 'Av. Colón 77',
            'input' => 'Av. Colón 77'
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

test('geocoding search returns 401 for unauthenticated user', function () {
    $response = $this->postJson('/api/v1/geocoding/search', [
        'address' => 'Av. Colón 77, Córdoba, Argentina'
    ]);

    $response->assertStatus(401);
});

test('geocoding search resolves coordinates when coordinates format provided', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', [
            'input' => '-34.6037, -58.3816'
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.latitude', -34.6037)
        ->assertJsonPath('data.longitude', -58.3816)
        ->assertJsonPath('data.provider', 'coordinates');
});

test('geocoding search returns error when no resolver can handle input', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', [
            'input' => str_repeat('x', 1000)
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');
});
