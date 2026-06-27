<?php

namespace App\Support;

use App\Models\Feature;
use Illuminate\Support\Str;

class PermissionFeatureResolver
{
    private static ?array $validFeatures = null;

    public static function resolveFeature(string $permission): ?string
    {
        $prefix = Str::before($permission, '.');

        $exceptions = config('permissions_mapping', []);
        $featureCode = $exceptions[$prefix] ?? $prefix;

        self::$validFeatures ??= Feature::pluck('code')->toArray();

        return in_array($featureCode, self::$validFeatures) ? $featureCode : null;
    }

    public static function clearCache(): void
    {
        self::$validFeatures = null;
    }
}
