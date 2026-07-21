<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\UpdateStorePaymentMethodRequest;
use App\Http\Resources\StorePaymentMethodResource;
use App\Models\PaymentMethod;
use App\Models\StorePaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class PaymentMethodController extends Controller
{
    #[OA\Get(
        path: '/store/payment-methods',
        summary: 'Listar métodos de pago disponibles para la tienda',
        description: 'Retorna la lista de métodos de pago globales activos (is_active=true) combinados con la configuración de la tienda. Si la tienda no configuró un método, se retornan los valores por defecto.',
        operationId: 'storePaymentMethodIndex',
        security: [['sanctum' => []]],
        tags: ['Store - Payment Methods']
    )]
    #[OA\Response(
        response: 200,
        description: 'Métodos de pago obtenidos correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Métodos de pago obtenidos correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/StorePaymentMethod')),
                            ]
                        ),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 403,
        description: 'Sin permisos',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function index(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $paymentMethods = PaymentMethod::where('payment_methods.is_active', true)
            ->leftJoin('store_payment_methods', function ($join) use ($storeId) {
                $join->on('payment_methods.id', '=', 'store_payment_methods.payment_method_id')
                    ->where('store_payment_methods.store_id', '=', $storeId);
            })
            ->select(
                'payment_methods.id',
                'payment_methods.name',
                'payment_methods.code',
                'payment_methods.icon',
                'payment_methods.is_active',
                'store_payment_methods.id as store_payment_method_id',
                'store_payment_methods.custom_name',
                'store_payment_methods.is_enabled',
                'store_payment_methods.requires_reference',
                'store_payment_methods.account_details',
                'store_payment_methods.sort_order',
            )
            ->orderBy(DB::raw('COALESCE(store_payment_methods.sort_order, 0)'))
            ->orderBy('payment_methods.name')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Métodos de pago obtenidos correctamente.',
            'data' => [
                'items' => StorePaymentMethodResource::collection($paymentMethods),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Patch(
        path: '/store/payment-methods/{paymentMethod}',
        summary: 'Configurar método de pago para la tienda',
        description: 'Permite a la tienda habilitar/deshabilitar un método de pago global y configurar sus campos específicos (nombre personalizado, datos bancarios, etc.). El método global debe estar activo (is_active=true).',
        operationId: 'storePaymentMethodUpdate',
        security: [['sanctum' => []]],
        tags: ['Store - Payment Methods']
    )]
    #[OA\Parameter(
        name: 'paymentMethod',
        in: 'path',
        required: true,
        description: 'ID del método de pago global',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'is_enabled', type: 'boolean', example: true),
                new OA\Property(property: 'custom_name', type: 'string', nullable: true, example: 'Transferencia Bco. Chile'),
                new OA\Property(property: 'requires_reference', type: 'boolean', example: true),
                new OA\Property(
                    property: 'account_details',
                    type: 'object',
                    nullable: true,
                    example: '{"bank":"Banco Chile","account_number":"123-456-789","alias":"mi.alias","cuit_rut":"12.345.678-9","holder_name":"Mi Tienda SPA"}',
                    properties: [
                        new OA\Property(property: 'bank', type: 'string', nullable: true, example: 'Banco Chile'),
                        new OA\Property(property: 'account_number', type: 'string', nullable: true, example: '123-456-789'),
                        new OA\Property(property: 'alias', type: 'string', nullable: true, example: 'mi.alias'),
                        new OA\Property(property: 'cuit_rut', type: 'string', nullable: true, example: '12.345.678-9'),
                        new OA\Property(property: 'holder_name', type: 'string', nullable: true, example: 'Mi Tienda SPA'),
                    ]
                ),
                new OA\Property(property: 'sort_order', type: 'integer', example: 1),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Configuración de método de pago actualizada correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Configuración de método de pago actualizada correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/StorePaymentMethod'),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 403,
        description: 'Sin permisos',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 404,
        description: 'Método de pago no encontrado o no disponible',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Método de pago no encontrado o no disponible.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['payment_method' => ['El método de pago no existe o no está activo.']]
                        ),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Error de validación.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    public function update(UpdateStorePaymentMethodRequest $request, string $paymentMethodId): JsonResponse
    {
        if (! $request->user()->hasRole('STORE_ADMIN')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tenés permisos para configurar medios de pago.',
                'data' => null,
                'errors' => ['role' => ['Solo los administradores de tienda pueden configurar medios de pago.']],
            ], 403);
        }

        $paymentMethod = PaymentMethod::where('id', $paymentMethodId)
            ->where('is_active', true)
            ->first();

        if (! $paymentMethod) {
            return response()->json([
                'status' => 'error',
                'message' => 'Método de pago no encontrado o no disponible.',
                'data' => null,
                'errors' => ['payment_method' => ['El método de pago no existe o no está activo.']],
            ], 404);
        }

        $storeId = $request->user()->store_id;

        StorePaymentMethod::updateOrCreate(
            [
                'store_id' => $storeId,
                'payment_method_id' => $paymentMethodId,
            ],
            $request->only([
                'is_enabled',
                'custom_name',
                'requires_reference',
                'account_details',
                'sort_order',
            ])
        );

        $result = PaymentMethod::where('payment_methods.id', $paymentMethodId)
            ->leftJoin('store_payment_methods', function ($join) use ($storeId) {
                $join->on('payment_methods.id', '=', 'store_payment_methods.payment_method_id')
                    ->where('store_payment_methods.store_id', '=', $storeId);
            })
            ->select(
                'payment_methods.id',
                'payment_methods.name',
                'payment_methods.code',
                'payment_methods.icon',
                'payment_methods.is_active',
                'store_payment_methods.custom_name',
                'store_payment_methods.is_enabled',
                'store_payment_methods.requires_reference',
                'store_payment_methods.account_details',
                'store_payment_methods.sort_order',
            )
            ->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Configuración de método de pago actualizada correctamente.',
            'data' => StorePaymentMethodResource::make($result),
            'errors' => null,
        ]);
    }
}
