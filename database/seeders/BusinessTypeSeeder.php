<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use Illuminate\Database\Seeder;

class BusinessTypeSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    BusinessType::updateOrCreate(
      ['name' => 'Ferretería'],
      [
        'description' => 'Businesses that sell hardware and tools',
        'status' => 'active',
      ],
    );
  }
}
