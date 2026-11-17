<?php

namespace Database\Seeders;

use App\Services\PermissionSynchronizer;
use Illuminate\Database\Seeder;

/** @deprecated Use `centra:permissions-sync --apply` explicitly in operations. */
class SuperAdminPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionSynchronizer::class)->apply();
    }
}
