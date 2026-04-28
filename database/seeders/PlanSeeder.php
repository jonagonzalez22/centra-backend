<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Feature;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
  public function run(): void
  {

    $beta = Plan::updateOrCreate(
      ['name' => 'Beta (Piloto)'],
      ['description' => 'Acceso total para clientes iniciales.', 'price' => 0, 'is_trial' => true]
    );
    $beta->features()->sync(Feature::pluck('id'));

    $esencial = Plan::updateOrCreate(
      ['name' => 'Esencial'],
      ['description' => 'Ideal para negocios pequeños iniciando.', 'price' => 10, 'is_trial' => false]
    );
    $esencial->features()->sync(
      Feature::whereIn('code', ['pos', 'inventory', 'multi_user'])->pluck('id')
    );

    $avanzado = Plan::updateOrCreate(
      ['name' => 'Avanzado'],
      ['description' => 'Para comercios con reparto y necesidad de control.', 'price' => 25, 'is_trial' => false]
    );
    $avanzado->features()->sync(
      Feature::whereIn('code', ['pos', 'inventory', 'reports', 'deliveries', 'messaging', 'multi_user'])->pluck('id')
    );
  }
}
