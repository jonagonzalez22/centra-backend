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
                property: 'orders_taken',
                type: 'object',
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 930000, description: 'Importe total vigente de pedidos no cancelados creados durante el período.'),
                    new OA\Property(property: 'count', type: 'integer', example: 21, description: 'Cantidad de pedidos no cancelados creados durante el período.'),
                ]
            ),
            new OA\Property(
                property: 'collected',
                type: 'object',
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', example: 2420000, description: 'Importe total de pagos activos registrados durante el período. Los pagos revertidos no se incluyen.'),
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
        ]
    )]
    public object $current;
}
