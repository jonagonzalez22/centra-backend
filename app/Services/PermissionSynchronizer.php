<?php

namespace App\Services;

use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSynchronizer
{
    public function differences(): array
    {
        $roles = Role::query()->where('guard_name', 'web')->get()->keyBy('name');
        $existingPermissions = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        sort($existingPermissions);
        $canonical = PermissionRegistry::names();

        $missingRolePermissions = [];
        $unexpectedRolePermissions = [];
        foreach (PermissionRegistry::roles() as $roleName) {
            $role = $roles->get($roleName);
            if (! $role) {
                $expected = PermissionRegistry::baselineFor($roleName);
                sort($expected);
                if ($expected) $missingRolePermissions[$roleName] = $expected;
                continue;
            }
            $actual = $role->permissions()->pluck('name')->sort()->values()->all();
            $expected = PermissionRegistry::baselineFor($roleName);
            sort($expected);
            $missing = array_values(array_diff($expected, $actual));
            $unexpected = array_values(array_diff($actual, $expected));
            if ($missing) $missingRolePermissions[$roleName] = $missing;
            if ($unexpected) $unexpectedRolePermissions[$roleName] = $unexpected;
        }

        return [
            'missing_roles' => array_values(array_diff(PermissionRegistry::roles(), $roles->keys()->all())),
            'missing_permissions' => array_values(array_diff($canonical, $existingPermissions)),
            'missing_role_permissions' => $missingRolePermissions,
            'unexpected_existing_permissions' => array_values(array_diff($existingPermissions, $canonical)),
            'unexpected_role_permissions' => $unexpectedRolePermissions,
            'unexpected_direct_user_permissions' => $this->unexpectedDirectPermissions(),
        ];
    }

    /** Applies only additive canonical changes. Existing assignments are never removed. */
    public function apply(): array
    {
        $before = $this->differences();
        if (! $before['missing_roles'] && ! $before['missing_permissions'] && ! $before['missing_role_permissions']) {
            return $before;
        }

        DB::transaction(function () use ($before): void {
            foreach ($before['missing_roles'] as $name) {
                Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            foreach ($before['missing_permissions'] as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            foreach ($before['missing_role_permissions'] as $roleName => $permissions) {
                Role::findByName($roleName, 'web')->givePermissionTo($permissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->differences();
    }

    private function unexpectedDirectPermissions(): array
    {
        $result = [];
        User::query()->with(['roles:name', 'permissions:name'])->lazy()->each(function (User $user) use (&$result): void {
            $roles = $user->roles->pluck('name')->all();
            foreach ($user->permissions as $permission) {
                if (! PermissionRegistry::isDelegableTo($permission->name, $roles)) {
                    $result[$permission->name] = ($result[$permission->name] ?? 0) + 1;
                }
            }
        });
        ksort($result);

        return $result;
    }
}
