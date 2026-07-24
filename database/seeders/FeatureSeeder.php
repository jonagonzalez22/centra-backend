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
      ['code' => 'deliveries', 'name' => 'Módulo de Pedidos', 'description' => 'Gestión de entregas.'],
      ['code' => 'route_mapping', 'name' => 'Hoja de Rutas', 'description' => 'Armado automático de rutas por mapas.'],
      ['code' => 'messaging', 'name' => 'Central de Mensajería', 'description' => 'Envío de notificaciones a clientes.'],
      ['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas de empleados.'],
      ['code' => 'customers', 'name' => 'Clientes', 'description' => 'Gestión de grupos comerciales y módulo de clientes.'],
      ['code' => 'store_settings', 'name' => 'Configuraciones de tienda', 'description' => 'Acceso a las configuraciones operativas propias de una tienda.'],
      ['code' => 'payment_methods', 'name' => 'Métodos de pago', 'description' => 'Modulo para metodos de pagos.'],
      ['code' => 'orders', 'name' => 'Ordenes', 'description' => 'Gestion de ordenes.'],
      ['code' => 'geography', 'name' => 'Geografia', 'description' => 'Data referente a la geografia.'],
      ['code' => 'categories', 'name' => 'Categorias', 'description' => 'Categoría de los productos.'],
      ['code' => 'cash', 'name' => 'Caja', 'description' => 'Modulos de caja.'],
    ];

    foreach ($features as $feature) {
      Feature::updateOrCreate(['code' => $feature['code']], $feature);
    }
  }
}
