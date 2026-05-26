<?php

use App\Http\Controllers\Api\V1\Admin\BusinessTypeController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\FeatureController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\StoreController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });

    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->group(function () {

            Route::middleware('role:SUPER_ADMIN|BACKOFFICE_USER')->group(function () {

                Route::get('dashboard', [DashboardController::class, '__invoke']);

                Route::get('stores/filter-options', [StoreController::class, 'filterOptions'])
                    ->middleware('permission:stores.view');

                Route::get('stores', [StoreController::class, 'index'])
                    ->middleware('permission:stores.view');

                Route::post('stores', [StoreController::class, 'store'])
                    ->middleware('permission:stores.create');

                Route::get('stores/{id}', [StoreController::class, 'show'])
                    ->middleware('permission:stores.view');

                Route::put('stores/{id}', [StoreController::class, 'update'])
                    ->middleware('permission:stores.edit');

                Route::delete('stores/{id}', [StoreController::class, 'destroy'])
                    ->middleware('permission:stores.delete');

                Route::apiResource('business-types', BusinessTypeController::class);

                Route::apiResource('features', FeatureController::class);
            });

            Route::middleware('role:SUPER_ADMIN')->group(function () {
                Route::apiResource('plans', PlanController::class);
                Route::post('plans/{plan}/sync-features', [PlanController::class, 'syncFeatures']);

                Route::apiResource('roles', RoleController::class);
                Route::post('roles/{role}/sync-permissions', [RoleController::class, 'syncPermissions']);
                Route::get('permissions', [PermissionController::class, 'index']);
            });

            Route::middleware('role:SUPER_ADMIN|STORE_ADMIN')->group(function () {
                Route::get('users/filter-options', [UserController::class, 'filterOptions']);
                Route::apiResource('users', UserController::class);
            });
        });

    Route::prefix('store')
        ->middleware(['auth:sanctum', 'throttle:api', 'role:STORE_ADMIN|STORE_USER'])
        ->group(function () {});
});
