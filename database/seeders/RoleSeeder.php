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
    Role::firstOrCreate(['name' => 'SUPER_ADMIN'], ['guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'STORE_ADMIN'], ['guard_name' => 'web']);
  }
}
