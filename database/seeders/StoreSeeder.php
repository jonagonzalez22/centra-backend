<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
  public function run(): void
  {
    Store::updateOrCreate(
      ['email' => 'ferreteria@central.com'],
      [
        'name' => 'Ferretería Central',
        'status' => 'active',
      ]
    );
  }
}
