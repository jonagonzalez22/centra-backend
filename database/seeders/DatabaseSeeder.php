<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
  public function run(): void
  {
    $this->call([
      \Database\Seeders\RoleSeeder::class,
      \Database\Seeders\Admin\BusinessTypeSeeder::class,
    ]);


    if (app()->environment('local')) {
      $this->call([
        \Database\Seeders\RoleSeeder::class,
        \Database\Seeders\Admin\StoreSeeder::class,
        \Database\Seeders\UserSeeder::class,
        \Database\Seeders\FeatureSeeder::class,
        \Database\Seeders\PlanSeeder::class,
      ]);
    }
  }
}
