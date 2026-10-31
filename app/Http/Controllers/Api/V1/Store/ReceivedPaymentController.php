<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\ListReceivedPaymentsRequest;
use App\Http\Resources\ReceivedPaymentResource;
use App\Models\OperationPayment;
use App\Models\RouteStopCollection;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Services\ReceivedPaymentQueryService;
use Illuminate\Http\JsonResponse;

class ReceivedPaymentController extends Controller
{
    public function filterOptions(ListReceivedPaymentsRequest $request): JsonResponse
    {
        $paymentMethods = StorePaymentMethod::query()
            ->forStore($request->user()->store_id)
            ->with('paymentMethod')
            ->where(function ($query): void {
                $query->where('is_enabled', true)
                    ->orWhereHas('operationPayments');
            })
            ->orderByDesc('is_enabled')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (StorePaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->custom_name ?? $method->paymentMethod?->name,
                'code' => $method->paymentMethod?->code,
                'is_enabled' => $method->is_enabled,
            ])
            ->values();

        $operationCollectorIds = OperationPayment::query()
            ->whereHas('operation', fn ($query) => $query->forStore($request->user()->store_id))
            ->whereNotNull('registered_by')
            ->distinct()
            ->pluck('registered_by');
        $routeCollectorIds = RouteStopCollection::query()
            ->forStore($request->user()->store_id)
            ->whereNotNull('operation_payment_id')
            ->whereNotNull('declared_by')
            ->whereHas('operationPayment.operation', fn ($query) => $query->forStore($request->user()->store_id))
            ->distinct()
            ->pluck('declared_by');
        $collectors = User::query()
            ->whereIn('id', $operationCollectorIds->merge($routeCollectorIds)->unique())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $collector) => ['id' => $collector->id, 'name' => $collector->name])
            ->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Opciones de filtros obtenidas exitosamente.',
            'data' => ['payment_methods' => $paymentMethods, 'collectors' => $collectors],
            'errors' => null,
        ]);
    }

    public function index(ListReceivedPaymentsRequest $request, ReceivedPaymentQueryService $queryService): JsonResponse
    {
        $payments = $queryService->paginate($request->user()->store_id, $request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Pagos recibidos obtenidos exitosamente.',
            'data' => [
                'items' => ReceivedPaymentResource::collection($payments->items()),
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ],
            'errors' => null,
        ]);
    }
}
