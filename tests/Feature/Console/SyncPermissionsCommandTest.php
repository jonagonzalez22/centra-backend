<?php

use App\Models\User;
use App\Services\PermissionSynchronizer;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('dry run is read only and reports a new database deterministically', function () {
    $this->artisan('centra:permissions-sync', ['--dry-run' => true])
        ->expectsOutputToContain('missing_roles')
        ->expectsOutputToContain('missing_permissions')
        ->assertExitCode(0);

    expect(Role::count())->toBe(0)
        ->and(Permission::count())->toBe(0);
});

test('apply reconstructs the canonical roles and baselines without direct user changes', function () {
    $employee = User::factory()->create();
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);
    Permission::create(['name' => 'inventory.view', 'guard_name' => 'web']);
    $employee->assignRole('STORE_USER');
    $employee->givePermissionTo('inventory.view');

    $this->artisan('centra:permissions-sync', ['--apply' => true])->assertExitCode(0);

    expect(Role::where('guard_name', 'web')->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionRegistry::roles())->sort()->values()->all())
        ->and(Permission::where('guard_name', 'web')->count())->toBe(98)
        ->and(Role::findByName('SUPER_ADMIN', 'web')->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionRegistry::baselineFor('SUPER_ADMIN'))->sort()->values()->all())
        ->and(Role::findByName('STORE_ADMIN', 'web')->permissions()->count())->toBe(76)
        ->and(Role::findByName('BACKOFFICE_USER', 'web')->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['plans.view', 'stores.view'])
        ->and(Role::findByName('STORE_USER', 'web')->permissions()->count())->toBe(0)
        ->and(Role::findByName('STORE_DRIVER', 'web')->permissions()->count())->toBe(0)
        ->and($employee->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['inventory.view']);
});

test('a second apply is idempotent and does not revoke unexpected historical permissions', function () {
    $this->artisan('centra:permissions-sync', ['--apply' => true])->assertExitCode(0);
    $historical = Permission::create(['name' => 'legacy.view', 'guard_name' => 'web']);
    $admin = Role::findByName('STORE_ADMIN', 'web');
    $admin->givePermissionTo($historical);

    $this->artisan('centra:permissions-sync', ['--apply' => true])->assertExitCode(0);

    expect(Permission::where('name', 'legacy.view')->exists())->toBeTrue()
        ->and($admin->fresh()->hasPermissionTo('legacy.view'))->toBeTrue();
});

test('apply rolls back roles and permissions if a canonical permission cannot be created', function () {
    Permission::creating(function (Permission $permission): void {
        if ($permission->name === 'plans.view') {
            throw new RuntimeException('simulated persistence failure');
        }
    });

    expect(fn () => app(PermissionSynchronizer::class)->apply())->toThrow(RuntimeException::class);

    expect(Role::count())->toBe(0)->and(Permission::count())->toBe(0);
});

test('command requires exactly one explicit mode', function () {
    $this->artisan('centra:permissions-sync')->assertExitCode(2);
    $this->artisan('centra:permissions-sync', ['--dry-run' => true, '--apply' => true])->assertExitCode(2);
});
