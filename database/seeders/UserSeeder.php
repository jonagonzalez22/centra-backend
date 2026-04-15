<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Admin\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
  public function run(): void
  {

    $admin = User::updateOrCreate(
      ['email' => 'admin@centra.com'],
      [
        'name' => 'Super Admin',
        'password' => Hash::make('password'),
        'store_id' => null,
      ]
    );
    $admin->assignRole('SUPER_ADMIN');


    $store = Store::where('email', 'ferreteria@central.com')->firstOrFail();

    $userStore = User::updateOrCreate(
      ['email' => 'cliente@test.com'],
      [
        'name' => 'Admin Ferretería',
        'password' => Hash::make('password'),
        'store_id' => $store->id,
      ]
    );
    $userStore->assignRole('STORE_ADMIN');
  }
}
