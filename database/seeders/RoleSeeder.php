<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    // firstOrCreate evita duplicados si ya existen
    foreach (config('permission_registry.roles', []) as $role) {
      Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
  }
}
