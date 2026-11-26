<?php

use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\FinancialIncidentFollowup;
use App\Models\FinancialIncidentFollowupHistory;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);
    foreach (['financial_incidents.view', 'financial_incidents.manage'] as $name) {
        Permission::create(['name' => $name, 'guard_name' => 'web']);
    }
    $this->store = Store::factory()->create(['timezone' => 'America/Argentina/Mendoza']);
    $plan = Plan::factory()->create();
    $plan->features()->attach(Feature::firstOrCreate(['code' => 'financial_incidents'], ['name' => 'Incidencias financieras'])->id);
    $this->store->update(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['financial_incidents.view', 'financial_incidents.manage']);
    $this->token = $this->user->createToken('financial-incidents')->plainTextToken;
});

function fiCash(object $test, string $expected, string $real, string $status = 'closed'): CashSession
{
    return CashSession::create(['store_id' => $test->store->id, 'user_id' => $test->user->id, 'status' => $status, 'business_date' => '2026-11-25', 'opening_amount' => '0.00', 'expected_amount' => $expected, 'real_amount' => $real, 'opened_at' => now()->subHour(), 'closed_at' => $status === 'closed' ? now() : null]);
}

function fiRejectedCollection(object $test, string $status = 'rejected'): RouteStopCollection
{
    $vehicle = Vehicle::factory()->create(['store_id' => $test->store->id]);
    $route = DeliveryRoute::factory()->create(['store_id' => $test->store->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $test->user->id, 'status' => 'completed']);
    $operation = CommercialOperation::factory()->create(['store_id' => $test->store->id, 'user_id' => $test->user->id, 'type' => 'order']);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operation->id]);
    $method = StorePaymentMethod::factory()->create(['store_id' => $test->store->id, 'payment_method_id' => PaymentMethod::factory()->create()->id]);

    return RouteStopCollection::create(['store_id' => $test->store->id, 'route_stop_id' => $stop->id, 'commercial_operation_id' => $operation->id, 'store_payment_method_id' => $method->id, 'amount' => '8000.00', 'declared_by' => $test->user->id, 'declared_at' => now()->subHour(), 'status' => $status, 'verified_by' => $status === 'rejected' ? $test->user->id : null, 'verified_at' => $status === 'rejected' ? now() : null, 'rejection_reason' => $status === 'rejected' ? 'Comprobante inválido' : null]);
}

function fiGet(object $test, string $path, ?string $token = null)
{
    return $test->withToken($token ?? $test->token)->getJson('/api/v1/store/financial-incidents'.$path);
}

test('detects only closed cash shortages as decimal string amounts without persistence', function () {
    $shortage = fiCash($this, '100.00', '20.25');
    fiCash($this, '100.00', '100.00');
    fiCash($this, '100.00', '120.00');
    fiCash($this, '100.00', '20.00', 'pending_reconciliation');

    fiGet($this, '?source_type=cash_difference')->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.source_id', $shortage->id)
        ->assertJsonPath('data.items.0.status', 'detected')
        ->assertJsonPath('data.items.0.amount', '79.75')
        ->assertJsonPath('data.items.0.source_difference', '-79.75');
    expect(FinancialIncidentFollowup::count())->toBe(0);
});

test('detects rejected collections even after the route is completed and excludes other statuses', function () {
    $rejected = fiRejectedCollection($this);
    fiRejectedCollection($this, 'declared');
    fiRejectedCollection($this, 'verified');

    fiGet($this, '?source_type=route_collection_rejected')->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.source_id', $rejected->id)
        ->assertJsonPath('data.items.0.amount', '8000.00')
        ->assertJsonPath('data.items.0.source_summary.rejection_reason', 'Comprobante inválido');
});

test('lists only active same-store assignees accepted by assignment in stable order', function () {
    $this->user->update(['name' => 'Marta']);
    $zara = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Zara', 'is_active' => true]);
    $ana = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Ana', 'is_active' => true]);
    User::factory()->create(['store_id' => $this->store->id, 'name' => 'Inactiva', 'is_active' => false]);
    $otherStore = Store::factory()->create();
    User::factory()->create(['store_id' => $otherStore->id, 'name' => 'Ajena', 'is_active' => true]);

    fiGet($this, '/assignees')->assertOk()
        ->assertJsonPath('data.0.id', $ana->id)
        ->assertJsonPath('data.1.id', $this->user->id)
        ->assertJsonPath('data.2.id', $zara->id)
        ->assertJsonCount(3, 'data');
});

test('assignees requires both incident permissions and the incident feature', function () {
    $viewOnly = User::factory()->create(['store_id' => $this->store->id]);
    $viewOnly->assignRole('STORE_USER');
    $viewOnly->givePermissionTo('financial_incidents.view');
    $token = $viewOnly->createToken('view-only')->plainTextToken;

    fiGet($this, '/assignees', $token)->assertForbidden();
    $this->store->plan->features()->detach(Feature::where('code', 'financial_incidents')->sole()->id);
    fiGet($this, '/assignees')->assertForbidden();
});

test('tracks explicit management actions append-only and preserves source', function () {
    $cash = fiCash($this, '10.00', '1.00');
    $path = "/api/v1/store/financial-incidents/cash_difference/{$cash->id}";
    $this->withToken($this->token)->postJson("{$path}/start-review", ['comment' => 'Revisando'])->assertOk()->assertJsonPath('data.status', 'in_review');
    $this->withToken($this->token)->postJson("{$path}/assign", ['assigned_to' => $this->user->id])->assertOk();
    $this->withToken($this->token)->postJson("{$path}/comments", ['comment' => 'Se contrastó el arqueo'])->assertOk();
    $this->withToken($this->token)->postJson("{$path}/resolve", ['resolution_type' => 'confirmed_loss', 'resolution_note' => 'Faltante confirmado'])->assertOk()->assertJsonPath('data.status', 'resolved');

    expect($cash->fresh()->real_amount)->toBe('1.00')
        ->and(FinancialIncidentFollowup::count())->toBe(1)
        ->and(FinancialIncidentFollowupHistory::count())->toBe(4);
    $this->withToken($this->token)->postJson("{$path}/comments", ['comment' => 'No permitido'])->assertStatus(422);
    $this->withToken($this->token)->getJson("{$path}/history")->assertOk()->assertJsonCount(4, 'data');
});

test('paginates stably, filters live sources and returns coherent counters', function () {
    $older = fiCash($this, '10.00', '1.00');
    $older->update(['closed_at' => '2026-11-25 02:00:00']); // 23:00 of the prior Mendoza day.
    $newer = fiCash($this, '10.00', '2.00');
    $newer->update(['closed_at' => '2026-11-26 15:00:00']);
    $rejected = fiRejectedCollection($this);

    $base = "/api/v1/store/financial-incidents/cash_difference/{$newer->id}";
    $this->withToken($this->token)->postJson("{$base}/start-review")->assertOk();

    fiGet($this, '?per_page=1')->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.current_page', 1);
    fiGet($this, '?source_type=route_collection_rejected')->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.source_id', $rejected->id);
    fiGet($this, '?status=in_review')->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.source_id', $newer->id);
    fiGet($this, '?date_from=2026-11-25&date_to=2026-11-25&source_type=cash_difference')->assertOk()
        ->assertJsonPath('data.total', 0);
    fiGet($this, '?date_from=2026-11-24&date_to=2026-11-24&source_type=cash_difference')->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.source_id', $older->id);
    fiGet($this, '/counters')->assertOk()
        ->assertJsonPath('data.detected', 2)
        ->assertJsonPath('data.in_review', 1)
        ->assertJsonPath('data.resolved', 0)
        ->assertJsonPath('data.dismissed', 0);
});

test('supports reassignment and dismisses terminally with an immutable history', function () {
    $cash = fiCash($this, '15.00', '1.00');
    $assignee = User::factory()->create(['store_id' => $this->store->id, 'is_active' => true]);
    $path = "/api/v1/store/financial-incidents/cash_difference/{$cash->id}";

    $this->withToken($this->token)->postJson("{$path}/start-review")->assertOk();
    $this->withToken($this->token)->postJson("{$path}/assign", ['assigned_to' => $this->user->id, 'comment' => 'Primera asignación'])->assertOk();
    $this->withToken($this->token)->postJson("{$path}/assign", ['assigned_to' => $assignee->id, 'comment' => 'Reasignación'])->assertOk()
        ->assertJsonPath('data.assigned_to.id', $assignee->id);
    $this->withToken($this->token)->postJson("{$path}/dismiss", ['reason' => 'Duplicado documental'])->assertOk()
        ->assertJsonPath('data.status', 'dismissed');
    $this->withToken($this->token)->postJson("{$path}/start-review")->assertStatus(422);
    $this->withToken($this->token)->postJson("{$path}/resolve", ['resolution_type' => 'other', 'resolution_note' => 'No permitido'])->assertStatus(422);

    $this->withToken($this->token)->getJson("{$path}/history")->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.2.previous_assigned_to.id', $this->user->id)
        ->assertJsonPath('data.2.new_assigned_to.id', $assignee->id)
        ->assertJsonPath('data.3.action', 'dismissed');
});

test('requires review before assignment, comments, resolution or dismissal', function () {
    $cash = fiCash($this, '15.00', '1.00');
    $path = "/api/v1/store/financial-incidents/cash_difference/{$cash->id}";

    $this->withToken($this->token)->postJson("{$path}/assign", ['assigned_to' => $this->user->id])->assertStatus(422);
    $this->withToken($this->token)->postJson("{$path}/comments", ['comment' => 'No permitido'])->assertStatus(422);
    $this->withToken($this->token)->postJson("{$path}/resolve", ['resolution_type' => 'other', 'resolution_note' => 'No permitido'])->assertStatus(422);
    $this->withToken($this->token)->postJson("{$path}/dismiss", ['reason' => 'No permitido'])->assertStatus(422);

    $this->withToken($this->token)->postJson("{$path}/start-review")->assertOk();
    $this->withToken($this->token)->postJson("{$path}/dismiss", ['reason' => 'Investigación concluida'])->assertOk()
        ->assertJsonPath('data.status', 'dismissed');
});

test('requires a resolution note and keeps an unknown source from creating tracking', function () {
    $cash = fiCash($this, '10.00', '1.00');
    $path = "/api/v1/store/financial-incidents/cash_difference/{$cash->id}";
    $this->withToken($this->token)->postJson("{$path}/start-review")->assertOk();
    $this->withToken($this->token)->postJson("{$path}/resolve", ['resolution_type' => 'other'])->assertStatus(422);
    expect(FinancialIncidentFollowup::where('source_id', $cash->id)->sole()->status)->toBe('in_review');
    $this->withToken($this->token)->postJson('/api/v1/store/financial-incidents/not_a_type/00000000-0000-0000-0000-000000000000/start-review')->assertStatus(422);
    expect(FinancialIncidentFollowup::count())->toBe(1);
});

test('requires both permissions and prevents cross-store sources and assignees', function () {
    $cash = fiCash($this, '10.00', '1.00');
    $otherStore = Store::factory()->create();
    $other = User::factory()->create(['store_id' => $otherStore->id]);
    $this->withToken($this->token)->postJson("/api/v1/store/financial-incidents/cash_difference/{$cash->id}/assign", ['assigned_to' => $other->id])->assertStatus(422);
    $otherCash = CashSession::create(['store_id' => $otherStore->id, 'user_id' => $other->id, 'status' => 'closed', 'business_date' => '2026-11-25', 'opening_amount' => '0', 'expected_amount' => '10', 'real_amount' => '1', 'opened_at' => now(), 'closed_at' => now()]);
    fiGet($this, "/cash_difference/{$otherCash->id}")->assertNotFound();
    $viewOnly = User::factory()->create(['store_id' => $this->store->id]);
    $viewOnly->assignRole('STORE_USER');
    $viewOnly->givePermissionTo('financial_incidents.view');
    expect($viewOnly->hasPermissionTo('financial_incidents.manage'))->toBeFalse();
    $this->actingAs($viewOnly, 'sanctum')->postJson("/api/v1/store/financial-incidents/cash_difference/{$cash->id}/start-review")->assertForbidden();
});

test('rejects inactive and missing assignees without changing source or tracking', function () {
    $cash = fiCash($this, '10.00', '1.00');
    $inactive = User::factory()->create(['store_id' => $this->store->id, 'is_active' => false]);
    $path = "/api/v1/store/financial-incidents/cash_difference/{$cash->id}/assign";

    $this->withToken($this->token)->postJson($path, ['assigned_to' => $inactive->id])->assertStatus(422);
    $this->withToken($this->token)->postJson($path, ['assigned_to' => '00000000-0000-0000-0000-000000000000'])->assertStatus(422);

    expect($cash->fresh()->real_amount)->toBe('1.00')
        ->and(FinancialIncidentFollowup::count())->toBe(0)
        ->and(FinancialIncidentFollowupHistory::count())->toBe(0);
});
