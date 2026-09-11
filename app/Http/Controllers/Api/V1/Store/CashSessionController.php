<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\CloseCashSessionRequest;
use App\Http\Requests\Api\V1\Store\OpenCashSessionRequest;
use App\Http\Requests\Api\V1\Store\SubmitCashSessionRequest;
use App\Http\Resources\CashSessionBlindResource;
use App\Http\Resources\CashSessionPaymentDetailResource;
use App\Http\Resources\CashSessionPendingResource;
use App\Http\Resources\CashSessionReconciliationResource;
use App\Http\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Models\StorePaymentMethod;
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
                        new OA\Property(property: 'data', ref: '#/components/schemas/CashSessionBlind', nullable: true),
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

        $sessions = app(CashSessionService::class)->operationalSessions($user);

        if ($sessions->count() > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Se detectaron múltiples cajas operativas para la jornada actual.',
                'data' => null,
                'errors' => ['cash' => ['Regularizá las sesiones duplicadas antes de operar.']],
            ], 422);
        }

        $session = $sessions->first();

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
            'data' => CashSessionBlindResource::make($session),
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
                        new OA\Property(property: 'data', ref: '#/components/schemas/CashSessionBlind'),
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
                $user,
                (float) $request->validated('opening_amount'),
                $request->validated('notes')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Sesión de caja abierta correctamente.',
                'data' => CashSessionBlindResource::make($session),
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
        description: 'Cierra definitivamente una sesión pending_reconciliation de otro usuario de la misma tienda.',
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
                new OA\Property(property: 'reconciliation_notes', type: 'string', nullable: true, example: 'Faltante verificado', description: 'Obligatoria cuando existe diferencia'),
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

        $session = CashSession::forStore($user->store_id)->where('id', $cashSession)->first();

        if (! $session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesión de caja no encontrada.',
                'data' => null,
                'errors' => null,
            ], 404);
        }
        if ($session->user_id === $user->id) {
            return $this->forbidden('No podés realizar el arqueo de tu propia caja.');
        }

        try {
            $session = app(CashSessionService::class)->close(
                $session,
                $user,
                (float) $request->validated('real_amount'),
                $request->validated('reconciliation_notes')
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

    #[OA\Get(
        path: '/store/cash/overview',
        summary: 'Obtener panorama operativo blind del cajero',
        security: [['sanctum' => []]],
        tags: ['Store - Caja']
    )]
    #[OA\Response(response: 200, description: 'Sesión vigente, abiertas antiguas y pendientes propias')]
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermissionTo('cash.view')) {
            return $this->forbidden('No tenés permiso para acceder a Gestión de caja.');
        }

        $service = app(CashSessionService::class);
        $businessDate = $service->currentBusinessDate($user);
        $current = $service->operationalSessions($user);
        if ($current->count() > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Se detectaron múltiples cajas operativas para la jornada actual.',
                'data' => null,
                'errors' => ['cash' => ['Regularizá las sesiones duplicadas antes de operar.']],
            ], 422);
        }

        $stale = CashSession::forStore($user->store_id)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->where(fn ($query) => $query->whereNull('business_date')->orWhereDate('business_date', '!=', $businessDate))
            ->orderByDesc('opened_at')
            ->get();
        $pending = CashSession::forStore($user->store_id)
            ->where('user_id', $user->id)
            ->where('status', 'pending_reconciliation')
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Resumen de cajas obtenido correctamente.',
            'data' => [
                'current' => $current->first() ? CashSessionBlindResource::make($current->first()) : null,
                'stale_open' => CashSessionBlindResource::collection($stale),
                'pending_reconciliation' => CashSessionBlindResource::collection($pending),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/store/cash/{cashSession}/submit',
        summary: 'Enviar caja abierta a arqueo',
        security: [['sanctum' => []]],
        tags: ['Store - Caja']
    )]
    #[OA\Parameter(name: 'cashSession', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['declared_amount'],
        properties: [
            new OA\Property(property: 'declared_amount', type: 'number', format: 'float'),
            new OA\Property(property: 'declaration_notes', type: 'string', nullable: true),
        ]
    ))]
    #[OA\Response(response: 200, description: 'Caja enviada a arqueo', content: new OA\JsonContent(ref: '#/components/schemas/CashSessionBlind'))]
    public function submit(SubmitCashSessionRequest $request, string $cashSession): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermissionTo('cash.submit')) {
            return $this->forbidden('No tenés permiso para enviar una caja a arqueo.');
        }

        $session = CashSession::forStore($user->store_id)->whereKey($cashSession)->first();
        if (! $session) {
            return $this->notFound();
        }
        if ($session->user_id !== $user->id) {
            return $this->forbidden('Sólo el propietario puede enviar esta caja a arqueo.');
        }

        $session = app(CashSessionService::class)->submit(
            $session,
            $user,
            (float) $request->validated('declared_amount'),
            $request->validated('declaration_notes')
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Caja enviada a arqueo correctamente.',
            'data' => CashSessionBlindResource::make($session),
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: '/store/cash/pending-reconciliation',
        summary: 'Listar cajas pendientes de arqueo de la tienda',
        security: [['sanctum' => []]],
        tags: ['Store - Caja']
    )]
    #[OA\Response(response: 200, description: 'Listado paginado de cajas pendientes')]
    public function pendingReconciliation(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermissionTo('cash.close')) {
            return $this->forbidden('No tenés permiso para consultar cajas pendientes de arqueo.');
        }

        $sessions = CashSession::forStore($user->store_id)
            ->where('status', 'pending_reconciliation')
            ->with('user')
            ->orderBy('submitted_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'status' => 'success',
            'message' => 'Cajas pendientes obtenidas correctamente.',
            'data' => [
                'items' => CashSessionPendingResource::collection($sessions->items()),
                'total' => $sessions->total(),
                'per_page' => $sessions->perPage(),
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: '/store/cash/{cashSession}/reconciliation',
        summary: 'Obtener detalle financiero para arqueo',
        security: [['sanctum' => []]],
        tags: ['Store - Caja']
    )]
    #[OA\Parameter(name: 'cashSession', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Detalle de arqueo', content: new OA\JsonContent(ref: '#/components/schemas/CashSessionReconciliation'))]
    public function reconciliation(Request $request, string $cashSession): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermissionTo('cash.close')) {
            return $this->forbidden('No tenés permiso para realizar arqueos.');
        }

        $session = CashSession::forStore($user->store_id)
            ->whereKey($cashSession)
            ->where('status', 'pending_reconciliation')
            ->with('user')
            ->first();
        if (! $session) {
            return $this->notFound();
        }
        if ($session->user_id === $user->id) {
            return $this->forbidden('No podés realizar el arqueo de tu propia caja.');
        }

        $summary = app(CashSessionService::class)->reconciliationSummary($session);

        return response()->json([
            'status' => 'success',
            'message' => 'Detalle de arqueo obtenido correctamente.',
            'data' => CashSessionReconciliationResource::make(compact('session', 'summary')),
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: '/store/cash/{cashSession}/reconciliation/payment-methods/{storePaymentMethod}/payments',
        summary: 'Listar pagos no efectivos de un medio dentro de un arqueo',
        security: [['sanctum' => []]],
        tags: ['Store - Caja']
    )]
    #[OA\Parameter(name: 'cashSession', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'storePaymentMethod', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))]
    #[OA\Response(response: 200, description: 'Pagos paginados del medio seleccionado', content: new OA\JsonContent(
        allOf: [
            new OA\Schema(ref: '#/components/schemas/ApiResponse'),
            new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CashSessionPaymentPage')]),
        ]
    ))]
    #[OA\Response(response: 403, description: 'Sin permiso, sesión propia o medio efectivo')]
    #[OA\Response(response: 404, description: 'Sesión o medio no encontrado')]
    public function reconciliationPayments(
        Request $request,
        string $cashSession,
        string $storePaymentMethod
    ): JsonResponse {
        $user = $request->user();
        if (! $user->hasPermissionTo('cash.close')) {
            return $this->forbidden('No tenés permiso para consultar pagos del arqueo.');
        }

        $session = CashSession::forStore($user->store_id)
            ->whereKey($cashSession)
            ->where('status', 'pending_reconciliation')
            ->first();
        if (! $session) {
            return $this->notFound();
        }
        if ($session->user_id === $user->id) {
            return $this->forbidden('No podés consultar el arqueo de tu propia caja.');
        }

        $method = StorePaymentMethod::forStore($user->store_id)
            ->whereKey($storePaymentMethod)
            ->whereHas('paymentMethod', fn ($query) => $query->where('code', '!=', 'cash'))
            ->with('paymentMethod')
            ->first();

        if (! $method || ! $session->payments()->where('store_payment_method_id', $method->id)->exists()) {
            return $this->notFound();
        }

        $payments = app(CashSessionService::class)->reconciliationPayments(
            $session,
            $method,
            min(max($request->integer('per_page', 10), 1), 100)
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Pagos obtenidos correctamente.',
            'data' => [
                'payment_method' => [
                    'id' => $method->id,
                    'name' => $method->custom_name ?? $method->paymentMethod->name,
                    'code' => $method->paymentMethod->code,
                ],
                'items' => CashSessionPaymentDetailResource::collection($payments->items()),
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message, 'data' => null, 'errors' => null], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Sesión de caja no encontrada.', 'data' => null, 'errors' => null], 404);
    }
}
