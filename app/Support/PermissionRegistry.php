<?php

namespace App\Support;

class PermissionRegistry
{
    public static function all(): array
    {
        return config('permission_registry.permissions', []);
    }

    public static function definition(string $permission): ?array
    {
        return self::all()[$permission] ?? null;
    }

    public static function names(): array
    {
        $names = array_keys(self::all());
        sort($names);

        return $names;
    }

    public static function roles(): array
    {
        return config('permission_registry.roles', []);
    }

    public static function baselineFor(string $role): array
    {
        if ($role === 'BACKOFFICE_USER') {
            return config('permission_registry.backoffice_baseline', []);
        }

        return array_values(array_keys(array_filter(
            self::all(),
            fn (array $definition) => in_array($role, $definition['baseline_roles'], true)
        )));
    }

    public static function isDelegableTo(string $permission, array $roles): bool
    {
        $definition = self::definition($permission);
        if (! $definition || ! $definition['delegable']) {
            return false;
        }

        return $roles !== [] && collect($roles)->every(
            fn (string $role) => in_array($role, $definition['delegable_roles'], true)
        );
    }
}
