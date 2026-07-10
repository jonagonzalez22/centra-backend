<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\CloseCashSessionRequest;
use App\Http\Requests\Api\V1\Store\OpenCashSessionRequest;
use App\Http\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Services\CashSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CashSessionController extends Controller
{
    #[OA\Get(
        path: '/store/cash/current',
        summary: 'Obtener sesión de caja actual',
        description: 'Retorna la sesión de caja abierta del usuario autenticado en su tienda.',
        operationId: 'cashSessionCurrent',
        security: [['sanctum' => []]],
        tags: ['Store - Caja']
    )]
    #[OA\Response(
        response: 200,
        description: 'Sesión de caja obtenida correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Sesión de caja obtenida correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CashSession', nullable: true),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'No autorizado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermissionTo('cash.view')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tenés permiso para acceder a este recurso.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        $session = CashSession::forStore($user->store_id)
            ->current($user->id)
            ->first();

        if (! $session) {
            return response()->json([
                'status' => 'success',
                'message' => 'No hay una sesión de caja activa.',
                'data' => null,
                'errors' => null,
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Sesión de caja obtenida correctamente.',
            'data' => CashSessionResource::make($session),
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/store/cash/open',
        summary: 'Abrir sesión de caja',
        description: 'Abre una nueva sesión de caja para el usuario autenticado en su tienda.',
        operationId: 'cashSessionOpen',
        security: [['sanctum' => []]],
        tags: ['Store - Caja']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['opening_amount'],
            properties: [
                new OA\Property(property: 'opening_amount', type: 'number', format: 'float', example: 1000.00, description: 'Monto inicial de apertura'),
                new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Turno mañana', description: 'Notas u observaciones'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Sesión de caja abierta correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Sesión de caja abierta correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CashSession'),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'No autorizado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Validación fallida o sesión ya abierta',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function open(OpenCashSessionRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermissionTo('cash.open')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tenés permiso para abrir una sesión de caja.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        try {
            $session = app(CashSessionService::class)->open(
                $user->store_id,
                $user->id,
                (float) $request->validated('opening_amount')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Sesión de caja abierta correctamente.',
                'data' => CashSessionResource::make($session),
                'errors' => null,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo abrir la sesión de caja.',
                'data' => null,
                'errors' => ['cash' => [$e->getMessage()]],
            ], 422);
        }
    }

    #[OA\Post(
        path: '/store/cash/{cashSession}/close',
        summary: 'Cerrar sesión de caja',
        description: 'Cierra una sesión de caja abierta del usuario autenticado. Requiere que la sesión pertenezca a la tienda, al usuario y esté en estado open.',
        operationId: 'cashSessionClose',
        security: [['sanctum' => []]],
        tags: ['Store - Caja']
    )]
    #[OA\Parameter(
        name: 'cashSession',
        in: 'path',
        required: true,
        description: 'ID de la sesión de caja',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['real_amount'],
            properties: [
                new OA\Property(property: 'real_amount', type: 'number', format: 'float', example: 1495.50, description: 'Monto real contado al cierre'),
                new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Cierre de turno mañana', description: 'Notas u observaciones'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Sesión de caja cerrada correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Sesión de caja cerrada correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CashSession'),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'No autorizado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 404,
        description: 'Sesión no encontrada o no cerrable',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Validación fallida o sesión ya cerrada',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function close(CloseCashSessionRequest $request, string $cashSession): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermissionTo('cash.close')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tenés permiso para cerrar una sesión de caja.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        $session = CashSession::forStore($user->store_id)
            ->where('user_id', $user->id)
            ->where('id', $cashSession)
            ->where('status', 'open')
            ->first();

        if (! $session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesión de caja no encontrada.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        try {
            $session = app(CashSessionService::class)->close(
                $session,
                (float) $request->validated('real_amount'),
                $request->validated('notes')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Sesión de caja cerrada correctamente.',
                'data' => CashSessionResource::make($session),
                'errors' => null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo cerrar la sesión de caja.',
                'data' => null,
                'errors' => ['cash' => [$e->getMessage()]],
            ], 422);
        }
    }
}
