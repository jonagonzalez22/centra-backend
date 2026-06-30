<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
  public function run(): void
  {
    $features = [
      ['code' => 'pos', 'name' => 'Punto de Venta', 'description' => 'Acceso al módulo de cajas y ventas diarias.'],
      ['code' => 'inventory', 'name' => 'Gestión de Stock', 'description' => 'Administración de productos y existencias.'],
      ['code' => 'reports', 'name' => 'Informes', 'description' => 'Centro de informes y métricas de negocio.'],
      ['code' => 'deliveries', 'name' => 'Módulo de Pedidos', 'description' => 'Gestión de pedidos y entregas.'],
      ['code' => 'route_mapping', 'name' => 'Hoja de Rutas', 'description' => 'Armado automático de rutas por mapas.'],
      ['code' => 'messaging', 'name' => 'Central de Mensajería', 'description' => 'Envío de notificaciones a clientes.'],
      ['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas de empleados.'],
      ['code' => 'customers', 'name' => 'Clientes', 'description' => 'Gestión de grupos comerciales y módulo de clientes.'],
    ];

    foreach ($features as $feature) {
      Feature::updateOrCreate(['code' => $feature['code']], $feature);
    }
  }
}
