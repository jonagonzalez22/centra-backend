<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\Geocoding\DTOs\GeocodingResult;
use App\Services\Geocoding\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);
    Role::create(['name' => 'BACKOFFICE_USER', 'guard_name' => 'web']);
    Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
    foreach (['customer_addresses.view', 'customer_addresses.create', 'customer_addresses.edit'] as $permission) {
        \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
    }

    $feature = Feature::firstOrCreate(['code' => 'customers'], ['name' => 'Clientes']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('customer_addresses.create');
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

test('only customer-address writers in store roles can use geocoding', function () {
    $readOnly = User::factory()->create(['store_id' => $this->store->id]);
    $readOnly->assignRole('STORE_USER');
    $readOnly->givePermissionTo('customer_addresses.view');

    $service = Mockery::mock(GeocodingService::class);
    $service->shouldNotReceive('search');
    $this->app->instance(GeocodingService::class, $service);

    $this->actingAs($readOnly, 'sanctum')
        ->postJson('/api/v1/geocoding/search', ['address' => 'Av. Colón 77'])
        ->assertForbidden();

    $writer = User::factory()->create(['store_id' => $this->store->id]);
    $writer->assignRole('STORE_USER');
    $writer->givePermissionTo('customer_addresses.edit');
    $this->actingAs($writer, 'sanctum')
        ->postJson('/api/v1/geocoding/search', ['input' => '-34.6037, -58.3816'])
        ->assertOk();
});

test('geocoding rejects drivers and backoffice users before resolving', function () {
    $service = Mockery::mock(GeocodingService::class);
    $service->shouldNotReceive('search');
    $this->app->instance(GeocodingService::class, $service);

    $driver = User::factory()->create(['store_id' => $this->store->id]);
    $driver->assignRole('STORE_DRIVER');
    $driver->givePermissionTo('customer_addresses.create');
    $this->actingAs($driver, 'sanctum')
        ->postJson('/api/v1/geocoding/search', ['address' => 'Av. Colón 77'])
        ->assertForbidden();

    $backoffice = User::factory()->create(['store_id' => null]);
    $backoffice->assignRole('BACKOFFICE_USER');
    $backoffice->givePermissionTo('customer_addresses.create');
    $this->actingAs($backoffice, 'sanctum')
        ->postJson('/api/v1/geocoding/search', ['address' => 'Av. Colón 77'])
        ->assertForbidden();

    $superAdmin = User::factory()->create(['store_id' => null]);
    $superAdmin->assignRole('SUPER_ADMIN');
    $superAdmin->givePermissionTo('customer_addresses.create');
    $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/geocoding/search', ['address' => 'Av. Colón 77'])
        ->assertForbidden();
});

test('geocoding requires the customers feature', function () {
    $planWithoutCustomers = Plan::factory()->create();
    $store = Store::factory()->create(['plan_id' => $planWithoutCustomers->id]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('STORE_USER');
    $user->givePermissionTo('customer_addresses.create');

    $service = Mockery::mock(GeocodingService::class);
    $service->shouldNotReceive('search');
    $this->app->instance(GeocodingService::class, $service);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/geocoding/search', ['address' => 'Av. Colón 77'])
        ->assertForbidden();
});

test('geocoding validates blank and unsupported URL inputs', function () {
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', ['input' => '   '])
        ->assertUnprocessable();
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', ['input' => 'http://maps.google.com/maps?q=1,2'])
        ->assertUnprocessable();
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', ['input' => 'https://maps.google.com.evil.test/maps?q=1,2'])
        ->assertUnprocessable();
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', ['input' => '//127.0.0.1/private'])
        ->assertUnprocessable();
});

test('geocoding preserves local coordinates from Google and Apple Maps links', function () {
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', [
            'input' => 'https://maps.google.com/maps?q=-34.6037,-58.3816',
        ])
        ->assertOk()
        ->assertJsonPath('data.provider', 'google_maps_link');

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', [
            'input' => 'https://maps.apple.com/?ll=-34.6037,-58.3816',
        ])
        ->assertOk()
        ->assertJsonPath('data.provider', 'apple_maps_link');
});

test('geocoding expands an official Apple short link without calling the final destination', function () {
    $shortUrl = 'https://maps.apple/p/ILVtVVY7vHAiJi';
    $finalUrl = 'https://maps.apple.com/place?coordinate=-32.854738,-68.842072&name=Patricias%20Mendocinas%20624&map=explore';
    Http::preventStrayRequests();
    Http::fake([
        $shortUrl => Http::response('', 301, ['Location' => $finalUrl]),
    ]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', ['input' => $shortUrl])
        ->assertOk()
        ->assertJsonPath('data.latitude', -32.854738)
        ->assertJsonPath('data.longitude', -68.842072)
        ->assertJsonPath('data.provider', 'apple_maps_link');
    Http::assertSentCount(1);
});

test('geocoding does not expose provider diagnostics', function () {
    $service = Mockery::mock(GeocodingService::class);
    $service->shouldReceive('search')->andThrow(new RuntimeException('provider failed: secret-marker'));
    $this->app->instance(GeocodingService::class, $service);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', ['address' => 'Av. Colón 77'])
        ->assertUnprocessable()
        ->assertDontSee('secret-marker');
});

test('geocoding has a dedicated per-user limit', function () {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->withHeader('Authorization', "Bearer $this->token")
            ->postJson('/api/v1/geocoding/search', ['input' => '-34.6037, -58.3816'])
            ->assertOk();
    }

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', ['input' => '-34.6037, -58.3816'])
        ->assertStatus(429);
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
    $input = str_repeat('x', 1000);
    $mockGeocodingService = Mockery::mock(GeocodingService::class);
    $mockGeocodingService->shouldReceive('search')
        ->with($input)
        ->andThrow(new RuntimeException('No se pudo resolver la ubicación.'));
    $this->app->instance(GeocodingService::class, $mockGeocodingService);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/geocoding/search', [
            'input' => $input,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');
});
