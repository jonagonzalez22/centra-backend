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

    Permission::create(['name' => 'commercial_groups.view', 'guard_name' => 'web']);
    Permission::create(['name' => 'commercial_groups.create', 'guard_name' => 'web']);
    Permission::create(['name' => 'commercial_groups.edit', 'guard_name' => 'web']);
    Permission::create(['name' => 'commercial_groups.delete', 'guard_name' => 'web']);
    Permission::create(['name' => 'stores.view', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $this->token = $user->createToken('test-token')->plainTextToken;
});

test('permissions endpoint includes commercial_groups group', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/admin/permissions');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonStructure([
            'data' => [
                'commercial_groups',
                'stores',
            ],
        ])
        ->assertJsonPath('data.commercial_groups', [
            'commercial_groups.view',
            'commercial_groups.create',
            'commercial_groups.edit',
            'commercial_groups.delete',
        ]);
});
