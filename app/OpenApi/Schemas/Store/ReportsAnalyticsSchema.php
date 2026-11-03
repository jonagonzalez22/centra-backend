<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'ReportsAnalytics', title: 'ReportsAnalytics', description: 'Análisis diario de la tienda autenticada para el período solicitado. Todas las fechas se agrupan en la zona horaria de la tienda.')]
class ReportsAnalyticsSchema
{
    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'date_from', type: 'string', format: 'date', example: '2026-11-01'),
        new OA\Property(property: 'date_to', type: 'string', format: 'date', example: '2026-11-30'),
        new OA\Property(property: 'timezone', type: 'string', example: 'America/Argentina/Mendoza'),
    ])]
    public object $period;

    #[OA\Property(type: 'array', description: 'Pagos activos registrados por día local. Incluye días sin actividad.', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-11-01'),
        new OA\Property(property: 'collected_amount', type: 'number', format: 'float', example: 98000, description: 'Pagos active registrados ese día.'),
    ]))]
    public array $payments_series;

    #[OA\Property(type: 'array', description: 'Pedidos no cancelados creados y entregas físicas realizadas por día local. Incluye días sin actividad.', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-11-01'),
        new OA\Property(property: 'orders_taken_count', type: 'integer', example: 10),
        new OA\Property(property: 'deliveries_count', type: 'integer', example: 7, description: 'Stops completed con mercadería entregada; incluye parciales y reentregas.'),
    ]))]
    public array $orders_series;

    #[OA\Property(type: 'array', description: 'Pagos activos del período agrupados por medio de pago histórico, incluidos medios hoy deshabilitados.', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'payment_method_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Efectivo'),
        new OA\Property(property: 'amount', type: 'number', format: 'float', example: 150000),
        new OA\Property(property: 'count', type: 'integer', example: 18),
    ]))]
    public array $payment_methods;
}
