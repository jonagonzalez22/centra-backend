<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    #[OA\Get(
        path: '/admin/dashboard',
        summary: 'Métricas y datos del dashboard',
        description: 'Retorna KPIs, gráficos y actividad reciente para el dashboard del backoffice.',
        operationId: 'dashboard',
        security: [['sanctum' => []]],
        tags: ['Dashboard']
    )]

    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'No autenticado.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]

    #[OA\Response(
        response: 403,
        description: 'Sin permisos',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'No tenés permisos para realizar esta acción.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]

    #[OA\Response(
        response: 200,
        description: 'Datos del dashboard obtenidos correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'metrics',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'total_stores', type: 'integer', example: 150),
                                        new OA\Property(property: 'total_users', type: 'integer', example: 320),
                                        new OA\Property(property: 'estimated_mrr', type: 'number', example: 45750.00),
                                        new OA\Property(property: 'active_plans_count', type: 'integer', example: 4),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'charts',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(
                                            property: 'stores_by_plan',
                                            type: 'array',
                                            items: new OA\Items(
                                                type: 'object',
                                                properties: [
                                                    new OA\Property(property: 'plan_name', type: 'string', example: 'Plan Básico'),
                                                    new OA\Property(property: 'store_count', type: 'integer', example: 85),
                                                ]
                                            )
                                        ),
                                        new OA\Property(
                                            property: 'stores_by_business_type',
                                            type: 'array',
                                            items: new OA\Items(
                                                type: 'object',
                                                properties: [
                                                    new OA\Property(property: 'business_type_name', type: 'string', example: 'Ferretería'),
                                                    new OA\Property(property: 'store_count', type: 'integer', example: 45),
                                                ]
                                            )
                                        ),
                                        new OA\Property(
                                            property: 'growth_last_6_months',
                                            type: 'array',
                                            items: new OA\Items(
                                                type: 'object',
                                                properties: [
                                                    new OA\Property(property: 'month', type: 'string', example: '2026-01'),
                                                    new OA\Property(property: 'store_count', type: 'integer', example: 12),
                                                ]
                                            )
                                        ),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'recent_activity',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(
                                            property: 'latest_stores',
                                            type: 'array',
                                            items: new OA\Items(
                                                type: 'object',
                                                properties: [
                                                    new OA\Property(property: 'id', type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf'),
                                                    new OA\Property(property: 'name', type: 'string', example: 'Mi Tienda'),
                                                    new OA\Property(property: 'created_at', type: 'string', example: '2026-05-20T10:30:00Z'),
                                                ]
                                            )
                                        ),
                                    ]
                                ),
                            ]
                        ),
                    ]
                ),
            ]
        )
    )]
    public function __invoke(): JsonResponse
    {
        $metrics = $this->getMetrics();
        $charts = $this->getCharts();
        $recentActivity = $this->getRecentActivity();

        return response()->json([
            'status' => 'success',
            'message' => 'Datos del dashboard obtenidos correctamente.',
            'data' => [
                'metrics' => $metrics,
                'charts' => $charts,
                'recent_activity' => $recentActivity,
            ],
            'errors' => null,
        ]);
    }

    private function getMetrics(): array
    {
        $totalStores = Store::count();

        $totalUsers = DB::table('users')->count();

        $estimatedMrr = Store::where('stores.is_active', true)
            ->whereNotNull('plan_id')
            ->join('plans', 'stores.plan_id', '=', 'plans.id')
            ->where('plans.is_active', true)
            ->sum('plans.price');

        $activePlansCount = Plan::where('plans.is_active', true)
            ->whereHas('stores', fn ($q) => $q->where('stores.is_active', true))
            ->count();

        return [
            'total_stores' => $totalStores,
            'total_users' => $totalUsers,
            'estimated_mrr' => round((float) $estimatedMrr, 2),
            'active_plans_count' => $activePlansCount,
        ];
    }

    private function getCharts(): array
    {
        $storesByPlan = Store::select('plans.name as plan_name', DB::raw('COUNT(stores.id) as store_count'))
            ->join('plans', 'stores.plan_id', '=', 'plans.id')
            ->where('stores.is_active', true)
            ->groupBy('plans.name')
            ->orderBy('plan_name')
            ->get()
            ->map(fn ($row) => [
                'plan_name' => $row->plan_name,
                'store_count' => (int) $row->store_count,
            ])
            ->toArray();

        $storesByBusinessType = Store::select('business_types.name as business_type_name', DB::raw('COUNT(stores.id) as store_count'))
            ->join('business_types', 'stores.business_type_id', '=', 'business_types.id')
            ->where('stores.is_active', true)
            ->groupBy('business_types.name')
            ->orderBy('business_type_name')
            ->get()
            ->map(fn ($row) => [
                'business_type_name' => $row->business_type_name,
                'store_count' => (int) $row->store_count,
            ])
            ->toArray();

        $sixMonthsAgo = now()->subMonths(5)->startOfMonth();
        $growthLast6Months = Store::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw('COUNT(id) as store_count')
        )
            ->where('created_at', '>=', $sixMonthsAgo)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'store_count' => (int) $row->store_count,
            ])
            ->toArray();

        return [
            'stores_by_plan' => $storesByPlan,
            'stores_by_business_type' => $storesByBusinessType,
            'growth_last_6_months' => $growthLast6Months,
        ];
    }

    private function getRecentActivity(): array
    {
        $latestStores = Store::select('id', 'name', 'created_at')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($store) => [
                'id' => $store->id,
                'name' => $store->name,
                'created_at' => $store->created_at->toIso8601String(),
            ])
            ->toArray();

        return [
            'latest_stores' => $latestStores,
        ];
    }
}
