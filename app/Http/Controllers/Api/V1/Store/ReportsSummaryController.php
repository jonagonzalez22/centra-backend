<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\SummaryReportRequest;
use App\Http\Resources\ReportsSummaryResource;
use App\Reporting\ReportContext;
use App\Reporting\ReportsSummaryService;
use Illuminate\Http\JsonResponse;

class ReportsSummaryController extends Controller
{
    /**
     * Devuelve el resumen comercial y operativo de la tienda autenticada para un período determinado.
     * El resumen corresponde exclusivamente a la tienda asociada al usuario autenticado.
     * Las métricas bajo metrics pertenecen al período; current representa estado actual.
     *
     * @OA\Get(
     *   path="/store/reports/summary",
     *   summary="Obtener resumen de informes",
     *   tags={"Reports"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(
     *     name="date_from",
     *     in="query",
     *     required=true,
     *     description="Fecha inicial del período en la zona horaria de la tienda.",
     *
     *     @OA\Schema(type="string", format="date", example="2026-11-01")
     *   ),
     *
     *   @OA\Parameter(
     *     name="date_to",
     *     in="query",
     *     required=true,
     *     description="Fecha final inclusiva del período en la zona horaria de la tienda. Debe ser igual o posterior a date_from.",
     *
     *     @OA\Schema(type="string", format="date", example="2026-11-30")
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Resumen de informes obtenido exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Resumen de informes obtenido exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/ReportsSummary"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="No autenticado"),
     *   @OA\Response(response=403, description="La feature reports no está disponible en el plan o el usuario no tiene una tienda operativa."),
     *   @OA\Response(response=422, description="Parámetros de fecha inválidos: date_from y date_to son obligatorios, deben tener formato YYYY-MM-DD y date_to no puede ser anterior a date_from.")
     * )
     */
    public function __invoke(SummaryReportRequest $request, ReportsSummaryService $reports): JsonResponse
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
        $summary = $reports->summary($context);

        return response()->json([
            'status' => 'success',
            'message' => 'Resumen de informes obtenido exitosamente.',
            'data' => ReportsSummaryResource::make([
                'period' => [
                    'date_from' => $context->fromLocal->format('Y-m-d'),
                    'date_to' => $context->toLocal->format('Y-m-d'),
                    'timezone' => $context->timezone,
                ],
                ...$summary,
            ]),
            'errors' => null,
        ]);
    }
}
