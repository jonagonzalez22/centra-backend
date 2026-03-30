<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
  public function run(): void
  {
    $this->call([
      RoleSeeder::class,
    ]);


    if (app()->environment('local')) {
      User::firstOrCreate(
        ['email' => 'admin@centra.com'],
        [
          'name' => 'Super Admin Local',
          'password' => bcrypt('password123'),
        ]
      )->assignRole('SUPER_ADMIN');
    }
  }
}
