<?php

namespace App\Support;

use App\Models\Feature;
use Illuminate\Support\Str;

class PermissionFeatureResolver
{
    private static ?array $validCodes = null;

    public static function resolveFeature(string $permission): ?string
    {
        $prefix = Str::before($permission, '.');

        $exceptions = config('permissions_mapping', []);
        $featureCode = $exceptions[$prefix] ?? $prefix;

        // Query once per request
        if (self::$validCodes === null) {
            self::$validCodes = Feature::pluck('code')->toArray();
        }

        return in_array($featureCode, self::$validCodes, true) ? $featureCode : null;
    }

    public static function clearCache(): void
    {
        self::$validCodes = null;
    }
}
