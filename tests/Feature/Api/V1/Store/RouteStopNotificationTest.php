<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\Locality;
use App\Models\Plan;
use App\Models\Province;
use App\Models\RouteStop;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RouteStopService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00'));

    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);
    Permission::create(['name' => 'logistics.routes.manage', 'guard_name' => 'web']);
    Permission::create(['name' => 'logistics.routes.view', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($deliveriesFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['logistics.routes.manage', 'logistics.routes.view']);
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Helpers ──────────────────────────────────────────────────────────

function createNotifiedTestStop(Store $store, ?string $eta = null): RouteStop
{
    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();

    $customer = Customer::factory()->create(['store_id' => $store->id]);
    CustomerAddress::factory()->forCustomer($customer)->for($locality)->asMain()->create([
        'latitude' => -34.6037,
        'longitude' => -58.3816,
    ]);
    \App\Models\CustomerContact::factory()->forCustomer($customer)->create([
        'phone' => '+541112345678',
    ]);

    $order = \App\Models\CommercialOperation::factory()
        ->forStore($store)
        ->forCustomer($customer)
        ->order()
        ->create(['status' => 'confirmed', 'requested_delivery_date' => now()->addDay()->format('Y-m-d')]);

    $vehicle = Vehicle::factory()->forStore($store)->create(['is_active' => true]);
    $driver = User::factory()->create(['store_id' => $store->id]);
    $driver->assignRole('STORE_DRIVER');

    $route = DeliveryRoute::create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'planned',
        'departure_time' => '08:00',
    ]);

    return RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
        'estimated_arrival_at' => $eta,
    ]);
}

// ── Unit Tests: calculateNotificationWindow ─────────────────────────

test('case A: first stop with ETA 09:00 returns 09:00-10:00', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-11 09:00:00');

    $window = $service->calculateNotificationWindow($stop);

    expect($window['start_rounded'])->toBe('09:00');
    expect($window['end_rounded'])->toBe('10:00');
    expect($window['day_label'])->toBe('mañana');
    expect($window['eta'])->not->toBeNull();
});

test('case B: second stop with ETA 09:35 returns 09:05-10:05', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-11 09:35:00');
    $stop->update(['sequence' => 2]);

    $window = $service->calculateNotificationWindow($stop);

    // start = 09:35 - 30min = 09:05, floor 5 = 09:05
    // end = 09:35 + 30min = 10:05, ceil 5 = 10:05
    expect($window['start_rounded'])->toBe('09:05');
    expect($window['end_rounded'])->toBe('10:05');
});

test('case C: non-first stop with ETA 10:00 returns 09:30-10:30', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-11 10:00:00');
    $stop->update(['sequence' => 3]);

    $window = $service->calculateNotificationWindow($stop);

    // start = 10:00 - 30min = 09:30, floor 5 = 09:30
    // end = 10:00 + 30min = 10:30, ceil 5 = 10:30
    expect($window['start_rounded'])->toBe('09:30');
    expect($window['end_rounded'])->toBe('10:30');
});

test('case D: ETA null returns all nulls', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, null);

    $window = $service->calculateNotificationWindow($stop);

    expect($window['eta'])->toBeNull();
    expect($window['start_rounded'])->toBeNull();
    expect($window['end_rounded'])->toBeNull();
    expect($window['day_label'])->toBeNull();
});

test('rounding: floor to nearest 5 works correctly', function () {
    $service = new RouteStopService();
    // 09:33 - 30min = 09:03 → floor 5 = 09:00
    $stop = createNotifiedTestStop($this->store, '2026-08-11 09:33:00');
    $stop->update(['sequence' => 2]);

    $window = $service->calculateNotificationWindow($stop);

    expect($window['start_rounded'])->toBe('09:00');
    // 09:33 + 30min = 10:03 → ceil 5 = 10:05
    expect($window['end_rounded'])->toBe('10:05');
});

test('raw ISO fields preserve seconds before rounding', function () {
    $service = new RouteStopService();
    // ETA with seconds: 09:33:47 — seconds should be preserved in raw values
    $stop = createNotifiedTestStop($this->store, '2026-08-11 09:33:47');
    $stop->update(['sequence' => 2]);

    $window = $service->calculateNotificationWindow($stop);

    // Raw start = 09:33:47 - 30min = 09:03:47 (ISO with TZ offset)
    expect($window['start_raw'])->toBe('2026-08-11T09:03:47-03:00');
    // Raw end = 09:33:47 + 30min = 10:03:47
    expect($window['end_raw'])->toBe('2026-08-11T10:03:47-03:00');
    // Rounded values should be snapped to 5-min marks
    expect($window['start_rounded'])->toBe('09:00');
    expect($window['end_rounded'])->toBe('10:05');
});

test('raw ISO fields are null when ETA is null', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, null);

    $window = $service->calculateNotificationWindow($stop);

    expect($window['eta'])->toBeNull();
    expect($window['start_raw'])->toBeNull();
    expect($window['end_raw'])->toBeNull();
    expect($window['start_rounded'])->toBeNull();
    expect($window['end_rounded'])->toBeNull();
});

test('raw ISO fields reflect first-stop morning exception start', function () {
    $service = new RouteStopService();
    // First stop at 07:00:22 — raw start should preserve the exact ETA
    $stop = createNotifiedTestStop($this->store, '2026-08-11 07:00:22');

    $window = $service->calculateNotificationWindow($stop);

    // First stop, hour 7 ∈ [7,9] → start = ETA directly (no -30 buffer)
    // Raw start = 07:00:22, raw end = 08:00:22
    expect($window['start_raw'])->toBe('2026-08-11T07:00:22-03:00');
    expect($window['end_raw'])->toBe('2026-08-11T08:00:22-03:00');
    expect($window['start_rounded'])->toBe('07:00');
    expect($window['end_rounded'])->toBe('08:00');
});

test('day label: today returns hoy', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-10 15:00:00');
    $stop->update(['sequence' => 1]);

    $window = $service->calculateNotificationWindow($stop);

    expect($window['day_label'])->toBe('hoy');
});

test('day label: tomorrow returns mañana', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-11 15:00:00');
    $stop->update(['sequence' => 1]);

    $window = $service->calculateNotificationWindow($stop);

    expect($window['day_label'])->toBe('mañana');
});

test('day label: future date returns DD/MM format', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-15 15:00:00');
    $stop->update(['sequence' => 1]);

    $window = $service->calculateNotificationWindow($stop);

    expect($window['day_label'])->toBe('15/08');
});

test('first stop with ETA at 07:00 returns 07:00-08:00', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-11 07:00:00');

    $window = $service->calculateNotificationWindow($stop);

    // first stop, hour 7 ∈ [7,9] → start = ETA, end = ETA+60
    expect($window['start_rounded'])->toBe('07:00');
    expect($window['end_rounded'])->toBe('08:00');
});

test('first stop with ETA at 09:00 returns 09:00-10:00', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-11 09:00:00');

    $window = $service->calculateNotificationWindow($stop);

    // first stop, hour 9 ∈ [7,9] → start = ETA, end = ETA+60
    expect($window['start_rounded'])->toBe('09:00');
    expect($window['end_rounded'])->toBe('10:00');
});

test('first stop with ETA at 06:59 uses default ±30 window', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-11 06:59:00');

    $window = $service->calculateNotificationWindow($stop);

    // first stop, hour 6 ∉ [7,9] → default ±30
    // start = 06:59 - 30 = 06:29, floor 5 = 06:25
    // end = 06:59 + 30 = 07:29, ceil 5 = 07:30
    expect($window['start_rounded'])->toBe('06:25');
    expect($window['end_rounded'])->toBe('07:30');
});

test('first stop with ETA at 10:00 uses default ±30 window', function () {
    $service = new RouteStopService();
    $stop = createNotifiedTestStop($this->store, '2026-08-11 10:00:00');

    $window = $service->calculateNotificationWindow($stop);

    // first stop, hour 10 ∉ [7,9] → default ±30
    expect($window['start_rounded'])->toBe('09:30');
    expect($window['end_rounded'])->toBe('10:30');
});

// ── Integration Test: PATCH /route-stops/{id}/notified ──────────────

test('PATCH notified marks stop as notified and returns notification window', function () {
    $stop = createNotifiedTestStop($this->store, '2026-08-11 09:00:00');

    expect($stop->notified_at)->toBeNull();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/route-stops/{$stop->id}/notified");

    $response->assertStatus(200)
        ->assertJsonPath('data.notified_at', function ($value) {
            return $value !== null;
        })
        ->assertJsonPath('data.notification_window_start', '09:00')
        ->assertJsonPath('data.notification_window_end', '10:00')
        ->assertJsonPath('data.notification_window_day', 'mañana')
        ->assertJsonPath('data.notification_window_raw_eta', function ($value) {
            return $value !== null;
        })
        ->assertJsonPath('data.notification_window_start_raw_iso', function ($value) {
            return $value !== null && str_contains($value, 'T');
        })
        ->assertJsonPath('data.notification_window_end_raw_iso', function ($value) {
            return $value !== null && str_contains($value, 'T');
        });

    expect($stop->fresh()->notified_at)->not->toBeNull();
});

test('PATCH notified is idempotent — returns 200 on second call', function () {
    $stop = createNotifiedTestStop($this->store, '2026-08-11 09:00:00');

    // First call
    $response1 = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/route-stops/{$stop->id}/notified");

    $response1->assertStatus(200);
    $firstNotifiedAt = $stop->fresh()->notified_at;
    expect($firstNotifiedAt)->not->toBeNull();

    // Second call — should also return 200, not 422
    $response2 = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/route-stops/{$stop->id}/notified");

    $response2->assertStatus(200)
        ->assertJsonPath('data.notification_window_start', '09:00');

    // notified_at should not have changed
    expect($stop->fresh()->notified_at->timestamp)->toBe($firstNotifiedAt->timestamp);
});

test('PATCH notified returns 404 for stop from another store', function () {
    $otherStore = Store::factory()->create();
    $stop = createNotifiedTestStop($otherStore, '2026-08-11 09:00:00');

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/route-stops/{$stop->id}/notified");

    $response->assertStatus(404);
});
