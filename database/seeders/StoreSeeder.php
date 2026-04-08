<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
  public function run(): void
  {


    $businessType = BusinessType::where('id', 1)->first();
    Store::updateOrCreate(
      [
        'name' => 'Ferretería Central',
        'business_type_id' => $businessType->id,
        'cuit' => '20-1234567890',
        'address' => 'Sarmiento 4455',
        'state' => 'Buenos Aires',
        'city' => 'Buenos Aires',
        'country' => 'Argentina',
        'phone' => '+54 11 1234-5678',
        'email' => 'ferreteria@central.com',
        'url_logo' => 'https://www.testcentral.com/logo.png',
        'status' => 'active',
      ]
    );
  }
}
