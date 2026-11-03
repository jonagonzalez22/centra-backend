<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\AnalyticsReportRequest;
use App\Http\Resources\ReportsAnalyticsResource;
use App\Reporting\ReportContext;
use App\Reporting\ReportsAnalyticsService;
use Illuminate\Http\JsonResponse;

class ReportsAnalyticsController extends Controller
{
    /**
     * @OA\Get(
     *   path="/store/reports/analytics",
     *   summary="Obtener análisis diario de informes",
     *   tags={"Reports"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="date_from", in="query", required=true, description="Fecha inicial del período en la zona horaria de la tienda.", @OA\Schema(type="string", format="date", example="2026-11-01")),
     *   @OA\Parameter(name="date_to", in="query", required=true, description="Fecha final inclusiva del período en la zona horaria de la tienda.", @OA\Schema(type="string", format="date", example="2026-11-30")),
     *
     *   @OA\Response(response=200, description="Análisis de informes obtenido exitosamente", @OA\JsonContent(@OA\Property(property="status", type="string", example="success"), @OA\Property(property="message", type="string", example="Análisis de informes obtenido exitosamente."), @OA\Property(property="data", ref="#/components/schemas/ReportsAnalytics"), @OA\Property(property="errors", type="null", example=null))),
     *   @OA\Response(response=401, description="No autenticado"),
     *   @OA\Response(response=403, description="La feature reports no está disponible en el plan o el usuario no tiene una tienda operativa."),
     *   @OA\Response(response=422, description="Parámetros de fecha inválidos.")
     * )
     */
    public function __invoke(AnalyticsReportRequest $request, ReportsAnalyticsService $reports): JsonResponse
    {
        $user = $request->user();

        if (! $user->store_id || ! $user->store) {
            return response()->json([
                'status' => 'error',
                'message' => 'El usuario autenticado no tiene una tienda operativa.',
                'data' => null,
                'errors' => ['store' => ['Se requiere una tienda válida para consultar informes.']],
            ], 403);
        }

        $validated = $request->validated();
        $timezone = $user->store->timezone ?: config('app.timezone', 'UTC');
        $context = ReportContext::forPeriod($user->store_id, $timezone, $validated['date_from'], $validated['date_to']);

        return response()->json([
            'status' => 'success',
            'message' => 'Análisis de informes obtenido exitosamente.',
            'data' => ReportsAnalyticsResource::make([
                'period' => [
                    'date_from' => $context->fromLocal->format('Y-m-d'),
                    'date_to' => $context->toLocal->format('Y-m-d'),
                    'timezone' => $context->timezone,
                ],
                ...$reports->analytics($context),
            ]),
            'errors' => null,
        ]);
    }
}
