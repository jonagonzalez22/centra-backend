<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'CashSessionPaymentPage', description: 'Detalle paginado de pagos de un medio no efectivo')]
class CashSessionPaymentPageSchema
{
    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'code', type: 'string'),
    ])]
    public object $payment_method;

    #[OA\Property(type: 'array', items: new OA\Items(ref: '#/components/schemas/CashSessionPaymentDetail'))]
    public array $items;

    #[OA\Property(type: 'integer')]
    public int $total;

    #[OA\Property(type: 'integer')]
    public int $per_page;

    #[OA\Property(type: 'integer')]
    public int $current_page;

    #[OA\Property(type: 'integer')]
    public int $last_page;
}
