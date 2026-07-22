# Tasks: Commercial Operation Events

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~510 (implementation ~250 + tests ~260) |
| 400-line budget risk | High |
| Chained PRs recommended | No (single-pr-default strategy) |
| Suggested split | Single PR with size:exception |
| Delivery strategy | single-pr-default |
| Chain strategy | N/A |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: N/A
400-line budget risk: High

> **⚠️ SIZE EXCEPTION REQUIRED**: Estimated ~510 changed lines exceeds the 400-line review budget. Request `size:exception` from maintainer before apply. The change is cohesive (new feature + bug fix) and splitting would fragment the review context unnecessarily.

---

## Phase 1: Permissions Configuration

- [ ] 1.1 Add `orders.edit` permission to `config/permissions_catalog.php` under new `'Pedidos'` section
- [ ] 1.2 Add `'orders' => 'pos'` mapping to `config/permissions_mapping.php`
- [ ] 1.3 Add `orders.edit` to `database/seeders/PermissionSeeder.php` permission array (idempotent firstOrCreate)

**Verification**: `php artisan db:seed --class=PermissionSeeder --force` succeeds; permission exists in DB.

---

## Phase 2: Database Migration

- [ ] 2.1 Create migration `database/migrations/xxxx_xx_xx_create_commercial_operation_events_table.php` with:
  - UUID PK, `store_id` (FK→stores, restrict), `operation_id` (FK→commercial_operations, cascade), `event_type` (string:50), `previous_date` (date), `new_date` (date), `reason` (string:50), `observation` (text, nullable), `user_id` (FK→users, restrict), `created_at` (timestamp, useCurrent)
  - NO `updated_at` column (immutable)
  - Composite indexes: `(store_id, operation_id)`, `(store_id, event_type)`, `(store_id, created_at)`

**Verification**: `php artisan migrate` succeeds; table structure matches spec.

---

## Phase 3: Models

- [ ] 3.1 Create `app/Models/CommercialOperationEvent.php` with:
  - `HasUuids` trait, `$keyType = 'string'`, `$incrementing = false`
  - `const UPDATED_AT = null` (immutable)
  - `$fillable`: all columns except `id` and `created_at`
  - `$casts`: `previous_date → date`, `new_date → date`, `created_at → datetime`
  - Relationships: `store()` BelongsTo(Store), `operation()` BelongsTo(CommercialOperation), `user()` BelongsTo(User)
  - Scope: `scopeForStore(Builder $query, string $storeId)`

- [ ] 3.2 Add `events(): HasMany` relationship to `app/Models/CommercialOperation.php`

**Verification**: `php artisan tinker` — `$op->events` returns collection; `$event->store`, `$event->operation`, `$event->user` resolve correctly.

---

## Phase 4: Service Layer

- [ ] 4.1 Add `rescheduleDeliveryDate()` method to `app/Services/CommercialOperationService.php`:
  - Signature: `(CommercialOperation $operation, string $newDate, string $reason, ?string $observation, User $user): CommercialOperation`
  - Transaction with `lockForUpdate()` on operation
  - Business validations: type=order, status∈{open,confirmed}, requested_delivery_date≠null, new_date≠current_date
  - Create `CommercialOperationEvent` with `store_id = operation.store_id`, `event_type = 'delivery_date_changed'`
  - Update `operation.requested_delivery_date = $newDate`
  - Return `$operation->fresh()`

**Verification**: Unit test validates all business rules throw correct exceptions.

---

## Phase 5: Form Request

- [ ] 5.1 Create `app/Http/Requests/Api/V1/Store/RescheduleDeliveryDateRequest.php`:
  - `authorize()`: return true (permission checked by middleware)
  - `rules()`: `new_date` (required, date, after_or_equal:today), `reason` (required, in:6 allowed values), `observation` (required_if:reason,other, nullable, string, max:1000)
  - Spanish validation messages

**Verification**: `POST` with missing fields returns 422 with Spanish error messages.

---

## Phase 6: Controller

- [ ] 6.1 Add `reschedule()` method to `app/Http/Controllers/Api/V1/Store/CommercialOperationController.php`:
  - Inject `RescheduleDeliveryDateRequest`, `CommercialOperationService`
  - Manual lookup: `CommercialOperation::forStore($user->store_id)->find($operation)` → 404 if null
  - Call `$service->rescheduleDeliveryDate(...)`
  - Load relations: `customer, user, items.product, payments.storePaymentMethod.paymentMethod, events.user`
  - Return `CommercialOperationResource::make($operation)` with 200

**Verification**: Feature test confirms 200 response shape matches spec.

---

## Phase 7: Routes

- [ ] 7.1 Add PUT route in `routes/api.php` inside existing `feature:pos` middleware group:
  ```php
  Route::put('operations/{operation}/reschedule', [CommercialOperationController::class, 'reschedule'])
      ->middleware('permission:orders.edit')
      ->name('store.operations.reschedule');
  ```

**Verification**: `php artisan route:list --path=operations/reschedule` shows route with correct middleware chain.

---

## Phase 8: Resources

- [ ] 8.1 Create `app/Http/Resources/CommercialOperationEventResource.php`:
  - Map: `id`, `event_type`, `previous_date` (Y-m-d), `new_date` (Y-m-d), `reason`, `observation`, `user` (whenLoaded), `created_at` (Iso8601)
- [ ] 8.2 Fix `app/Http/Resources/CommercialOperationResource.php` line ~54:
  - Change `$this->delivery_date` → `$this->requested_delivery_date` (preserve response key `delivery_date`)
- [ ] 8.3 Add conditional `events` key to CommercialOperationResource:
  ```php
  'events' => CommercialOperationEventResource::collection($this->whenLoaded('events')),
  ```

**Verification**: Resource serializes correctly; `delivery_date` key present in response; events array appears when eager-loaded.

---

## Phase 9: Tests

- [ ] 9.1 Create `tests/Feature/Api/V1/Store/CommercialOperationRescheduleTest.php`:
  - `it returns 200 with updated operation on valid reschedule` — assertOk, assertDatabaseHas on events table
  - `it returns 422 when new_date is missing` — assertJsonValidationErrors
  - `it returns 422 when reason is invalid` — assertJsonValidationErrors
  - `it returns 422 when observation required for other reason` — assertJsonValidationErrors
  - `it returns 422 when new_date is in the past` — assertJsonValidationErrors
  - `it returns 422 when new_date equals current date` — assertJsonValidationErrors
  - `it returns 403 when user lacks orders.edit permission` — assertForbidden
  - `it returns 404 when operation belongs to different store` — assertNotFound
  - `it returns 422 when operation type is sale` — assertJsonValidationErrors
  - `it returns 422 when operation status is cancelled` — assertJsonValidationErrors
  - `it creates event record with correct dates on reschedule` — assertDatabaseHas
  - `it updates operation requested_delivery_date` — assertDatabaseHas

- [ ] 9.2 Create `tests/Unit/Services/CommercialOperationRescheduleServiceTest.php`:
  - `it throws exception when operation type is not order`
  - `it throws exception when status is not open or confirmed`
  - `it throws exception when requested_delivery_date is null`
  - `it throws exception when new_date equals current date`
  - `it creates event and updates date within transaction`

- [ ] 9.3 Verify resource bug fix:
  - `it returns delivery_date from requested_delivery_date attribute`

**Verification**: `php artisan test --filter=CommercialOperationReschedule` — all tests pass.

---

## Summary

| Phase | Tasks | Focus |
|-------|-------|-------|
| Phase 1 | 3 | Permissions config |
| Phase 2 | 1 | Migration |
| Phase 3 | 2 | Models |
| Phase 4 | 1 | Service layer |
| Phase 5 | 1 | Form request |
| Phase 6 | 1 | Controller |
| Phase 7 | 1 | Routes |
| Phase 8 | 3 | Resources |
| Phase 9 | 3 | Tests |
| **Total** | **16** | |

## Implementation Order

Tasks are dependency-ordered: permissions first (no code depends on them but they must exist), then migration → model → service → FormRequest → controller → routes → resource → tests. Each phase builds on the previous. Tests last because they verify the complete integration.
