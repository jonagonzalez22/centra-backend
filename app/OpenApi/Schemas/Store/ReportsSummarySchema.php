<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReportsSummary',
    title: 'ReportsSummary',
    description: 'Resumen comercial y operativo de la tienda autenticada. Las métricas corresponden al período solicitado; current representa estado actual.'
)]
class ReportsSummarySchema
{
    #[OA\Property(
        type: 'object',
        description: 'Período solicitado, interpretado en la zona horaria de la tienda.',
        properties: [
            new OA\Property(property: 'date_from', type: 'string', format: 'date', example: '2026-11-01', description: 'Fecha inicial solicitada.'),
            new OA\Property(property: 'date_to', type: 'string', format: 'date', example: '2026-11-30', description: 'Fecha final solicitada.'),
            new OA\Property(property: 'timezone', type: 'string', example: 'America/Argentina/Mendoza', description: 'Zona horaria utilizada para interpretar el período.'),
        ]
    )]
    public object $period;

    #[OA\Property(
        type: 'object',
        description: 'Métricas del período solicitado.',
        properties: [
            new OA\Property(
                property: 'pos_sales',
                type: 'object',
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 1850000, description: 'Importe total de ventas de mostrador confirmadas creadas durante el período.'),
                    new OA\Property(property: 'count', type: 'integer', example: 74, description: 'Cantidad de ventas de mostrador confirmadas creadas durante el período.'),
                    new OA\Property(property: 'average_ticket', type: 'number', format: 'float', example: 25000, description: 'Importe promedio por venta de mostrador durante el período.'),
                ]
            ),
            new OA\Property(
                property: 'realized_sales',
                type: 'object',
                description: 'Ventas realizadas: ventas POS confirmadas más entregas monetizadas de pedidos. Las entregas usan el snapshot financiero delivery_sale_allocations.net_amount.',
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 245000),
                    new OA\Property(property: 'channels', type: 'object', properties: [
                        new OA\Property(property: 'pos', type: 'object', properties: [
                            new OA\Property(property: 'amount', type: 'number', format: 'float', example: 166600),
                            new OA\Property(property: 'percentage', type: 'integer', example: 68),
                        ]),
                        new OA\Property(property: 'orders', type: 'object', properties: [
                            new OA\Property(property: 'amount', type: 'number', format: 'float', example: 78400),
                            new OA\Property(property: 'percentage', type: 'integer', example: 32),
                        ]),
                    ]),
                ]
            ),
            new OA\Property(
                property: 'gross_profit',
                type: 'object',
                description: 'Ganancia bruta del período: ventas realizadas menos el costo comercial histórico conocido de los SKU vendidos. POS usa operation_items.unit_cost_snapshot y delivery usa delivery_sale_allocations.unit_cost_snapshot. Si status no es complete, amount no representa un cálculo exacto.',
                properties: [
                    new OA\Property(property: 'sales_amount', type: 'number', format: 'float', example: 245000),
                    new OA\Property(property: 'known_cost_amount', type: 'number', format: 'float', example: 147000, description: 'Costo comercial histórico cuyo snapshot fue conocido al realizarse la venta.'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', nullable: true, example: 98000),
                    new OA\Property(property: 'margin_percentage', type: 'number', format: 'float', nullable: true, example: 40),
                    new OA\Property(property: 'status', type: 'string', enum: ['complete', 'partial', 'unavailable'], example: 'complete'),
                    new OA\Property(property: 'has_missing_costs', type: 'boolean', example: false),
                ]
            ),
            new OA\Property(
                property: 'orders_taken',
                type: 'object',
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 930000, description: 'Importe total vigente de pedidos no cancelados creados durante el período.'),
                    new OA\Property(property: 'count', type: 'integer', example: 21, description: 'Cantidad de pedidos no cancelados creados durante el período.'),
                ]
            ),
            new OA\Property(
                property: 'deliveries_completed',
                type: 'object',
                properties: [
                    new OA\Property(property: 'count', type: 'integer', example: 14, description: 'Cantidad de entregas de mercadería realizadas durante el período seleccionado, parciales o completas.'),
                ]
            ),
            new OA\Property(
                property: 'collected',
                type: 'object',
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 2420000, description: 'Importe total de pagos activos registrados durante el período. Los pagos revertidos no se incluyen.'),
                    new OA\Property(property: 'channels', type: 'object', description: 'Desglose de pagos activos por canal comercial.', properties: [
                        new OA\Property(property: 'pos_amount', type: 'number', format: 'float', example: 167340),
                        new OA\Property(property: 'orders_amount', type: 'number', format: 'float', example: 33520),
                    ]),
                ]
            ),
        ]
    )]
    public object $metrics;

    #[OA\Property(
        type: 'object',
        description: 'Estado actual de la tienda; no depende del período solicitado.',
        properties: [
            new OA\Property(
                property: 'pending_balance',
                type: 'object',
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 470000, description: 'Saldo pendiente vigente de operaciones comerciales actualmente cobrables. Representa estado actual y no está limitado por el período solicitado.'),
                ]
            ),
            new OA\Property(
                property: 'inventory_alerts',
                type: 'object',
                description: 'Alertas de inventario actuales; no dependen del período solicitado.',
                properties: [
                    new OA\Property(property: 'low_stock_count', type: 'integer', example: 7, description: 'Cantidad actual de productos activos con stock físico mayor que cero, stock mínimo configurado y stock menor o igual al mínimo.'),
                    new OA\Property(property: 'out_of_stock_count', type: 'integer', example: 2, description: 'Cantidad actual de productos activos sin disponibilidad para venta.'),
                ]
            ),
            new OA\Property(
                property: 'attention',
                type: 'object',
                description: 'Indicadores operativos actuales que pueden requerir seguimiento; no dependen del período solicitado.',
                properties: [
                    new OA\Property(property: 'partially_delivered_orders', type: 'integer', example: 3, description: 'Pedidos actualmente con estado partially_delivered.'),
                    new OA\Property(property: 'delivered_with_balance', type: 'integer', example: 5, description: 'Pedidos entregados cuyo total supera la suma de sus pagos activos.'),
                    new OA\Property(property: 'routes_pending_reconciliation', type: 'integer', example: 2, description: 'Rutas actualmente en awaiting_reconciliation, listas para el proceso administrativo de conciliación.'),
                ]
            ),
        ]
    )]
    public object $current;
}
