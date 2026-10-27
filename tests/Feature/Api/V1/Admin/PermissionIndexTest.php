<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);

    Permission::create(['name' => 'stores.view', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $this->token = $user->createToken('test-token')->plainTextToken;
});

test('permissions endpoint returns permissions grouped by resource', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/admin/permissions');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonStructure([
            'data' => [
                'stores',
            ],
        ])
        ->assertJsonPath('data.stores', ['stores.view']);
});
