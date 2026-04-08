<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
  public function run(): void
  {
    $this->call([
      RoleSeeder::class,
      BusinessTypeSeeder::class,
    ]);


    if (app()->environment('local')) {
      $this->call([
        RoleSeeder::class,
        StoreSeeder::class,
        UserSeeder::class,
      ]);
    }
  }
}
