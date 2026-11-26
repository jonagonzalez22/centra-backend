<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialIncidentResource;
use App\Models\FinancialIncidentFollowup;
use App\Services\FinancialIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialIncidentController extends Controller
{
    public function __construct(private readonly FinancialIncidentService $incidents) {}

    public function index(Request $request): JsonResponse
    {
        $this->view($request);
        $filters = $this->listFilters($request);
        $page = $this->incidents->paginate($request->user(), $filters);
        $items = collect($page->items())->map(fn ($row) => $this->incidents->incident($request->user(), $row->source_type, $row->source_id));

        return $this->response('Incidencias financieras obtenidas correctamente.', ['items' => FinancialIncidentResource::collection($items), 'total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'date_filter_semantics' => 'occurred_at es el cierre de caja o la fecha de rechazo/verificación de la cobranza, filtrada por fecha local de la tienda.']);
    }

    public function counters(Request $request): JsonResponse
    {
        $this->view($request);
        $counts = $this->incidents->counters($request->user(), $this->listFilters($request));

        return $this->response('Contadores de incidencias obtenidos correctamente.', ['detected' => (int) ($counts['detected'] ?? 0), 'in_review' => (int) ($counts['in_review'] ?? 0), 'resolved' => (int) ($counts['resolved'] ?? 0), 'dismissed' => (int) ($counts['dismissed'] ?? 0)]);
    }

    public function assignees(Request $request): JsonResponse
    {
        $this->manage($request);

        return $this->response('Responsables elegibles obtenidos correctamente.', $this->incidents->assignees($request->user())->map(fn ($user) => ['id' => $user->id, 'name' => $user->name]));
    }

    public function show(Request $request, string $sourceType, string $sourceId): JsonResponse
    {
        $this->view($request);

        return $this->response('Incidencia financiera obtenida correctamente.', FinancialIncidentResource::make($this->incidents->incident($request->user(), $sourceType, $sourceId)));
    }

    public function history(Request $request, string $sourceType, string $sourceId): JsonResponse
    {
        $this->view($request);

        return $this->response('Historial de incidencia obtenido correctamente.', $this->incidents->history($request->user(), $sourceType, $sourceId)->map(fn ($event) => ['id' => $event->id, 'action' => $event->action, 'actor' => $event->actor ? ['id' => $event->actor->id, 'name' => $event->actor->name] : null, 'previous_status' => $event->previous_status, 'new_status' => $event->new_status, 'previous_assigned_to' => $event->previousAssignedTo ? ['id' => $event->previousAssignedTo->id, 'name' => $event->previousAssignedTo->name] : null, 'new_assigned_to' => $event->newAssignedTo ? ['id' => $event->newAssignedTo->id, 'name' => $event->newAssignedTo->name] : null, 'comment' => $event->comment, 'metadata' => $event->metadata, 'created_at' => $event->created_at?->utc()->toIso8601String()]));
    }

    public function startReview(Request $request, string $sourceType, string $sourceId): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $this->incidents->startReview($request->user(), $sourceType, $sourceId, $data['comment'] ?? null);

        return $this->show($request, $sourceType, $sourceId);
    }

    public function assign(Request $request, string $sourceType, string $sourceId): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['assigned_to' => ['required', 'uuid'], 'comment' => ['nullable', 'string', 'max:2000']]);
        $this->incidents->assign($request->user(), $sourceType, $sourceId, $data['assigned_to'], $data['comment'] ?? null);

        return $this->show($request, $sourceType, $sourceId);
    }

    public function comment(Request $request, string $sourceType, string $sourceId): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $this->incidents->comment($request->user(), $sourceType, $sourceId, $data['comment']);

        return $this->show($request, $sourceType, $sourceId);
    }

    public function resolve(Request $request, string $sourceType, string $sourceId): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['resolution_type' => ['required', Rule::in(['justified_difference', 'regularized', 'confirmed_loss', 'other'])], 'resolution_note' => ['required', 'string', 'max:2000']]);
        $this->incidents->resolve($request->user(), $sourceType, $sourceId, $data['resolution_type'], $data['resolution_note'] ?? null);

        return $this->show($request, $sourceType, $sourceId);
    }

    public function dismiss(Request $request, string $sourceType, string $sourceId): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->incidents->dismiss($request->user(), $sourceType, $sourceId, $data['reason']);

        return $this->show($request, $sourceType, $sourceId);
    }

    private function listFilters(Request $request): array
    {
        return $request->validate(['source_type' => ['nullable', Rule::in([FinancialIncidentFollowup::SOURCE_CASH_DIFFERENCE, FinancialIncidentFollowup::SOURCE_ROUTE_COLLECTION_REJECTED])], 'status' => ['nullable', Rule::in(FinancialIncidentFollowup::STATUSES)], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'], 'assigned_to' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
    }

    private function view(Request $request): void
    {
        abort_unless($request->user()->hasPermissionTo('financial_incidents.view'), 403);
    }

    private function manage(Request $request): void
    {
        $this->view($request);
        abort_unless($request->user()->hasPermissionTo('financial_incidents.manage'), 403);
    }

    private function response(string $message, mixed $data): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data, 'errors' => null]);
    }
}
