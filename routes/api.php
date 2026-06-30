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
use App\Http\Controllers\Api\V1\GeographyController;
use App\Http\Controllers\Api\V1\Store\CategoryController;
use App\Http\Controllers\Api\V1\Store\GenerateSkuController;
use App\Http\Controllers\Api\V1\Store\InventoryController;
use App\Http\Controllers\Api\V1\Store\ProductController;
use App\Http\Controllers\Api\V1\Store\ProductSearchController;
use App\Http\Controllers\Api\V1\Store\CommercialGroupController;
use App\Http\Controllers\Api\V1\Store\CustomerAddressController;
use App\Http\Controllers\Api\V1\Store\CustomerContactController;
use App\Http\Controllers\Api\V1\Store\CustomerController;
use App\Http\Controllers\Api\V1\Store\StoreUserController;
use App\Http\Controllers\Api\V1\Store\StoreUserPermissionController;
use App\Http\Controllers\Api\V1\Store\PermissionCatalogController;
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

            Route::middleware('role:SUPER_ADMIN')->group(function () {
                Route::get('users/filter-options', [UserController::class, 'filterOptions']);
                Route::apiResource('users', UserController::class);
            });
        });

    Route::prefix('geography')
        ->middleware(['auth:sanctum', 'throttle:api', 'permission:geography.view'])
        ->group(function () {
            Route::get('provinces', [GeographyController::class, 'provinces']);
            Route::get('provinces/{id}/localities', [GeographyController::class, 'localities']);
        });

    Route::prefix('store')
        ->middleware(['auth:sanctum', 'throttle:api', 'role:STORE_ADMIN|STORE_USER'])
        ->group(function () {
            Route::get('categories', [CategoryController::class, 'index'])->name('store.categories.index');
            Route::get('categories/{category}', [CategoryController::class, 'show'])->name('store.categories.show');
            Route::post('categories', [CategoryController::class, 'store'])->name('store.categories.store');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->name('store.categories.update');
            Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('store.categories.destroy');

            Route::get('inventory/movements', [InventoryController::class, 'index'])->name('store.inventory.movements');
            Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->name('store.inventory.adjust');

            Route::get('products', [ProductController::class, 'index'])->name('store.products.index');
            Route::get('products/search', [ProductSearchController::class, '__invoke'])
                ->middleware('feature:inventory')
                ->name('store.products.search');
            Route::get('products/generate-sku', [GenerateSkuController::class, '__invoke'])
                ->middleware('feature:inventory')
                ->name('store.products.generate-sku');
            Route::get('products/{product}', [ProductController::class, 'show'])->name('store.products.show');
            Route::post('products', [ProductController::class, 'store'])->name('store.products.store');
            Route::put('products/{product}', [ProductController::class, 'update'])->name('store.products.update');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('store.products.destroy');

            Route::get('permissions/catalog', [PermissionCatalogController::class, 'index'])->name('store.permissions.catalog');

            Route::middleware('feature:customers')->group(function () {
                Route::get('commercial-groups', [CommercialGroupController::class, 'index'])->name('store.commercial-groups.index');
                Route::get('commercial-groups/{commercial_group}', [CommercialGroupController::class, 'show'])->name('store.commercial-groups.show');
                Route::post('commercial-groups', [CommercialGroupController::class, 'store'])->name('store.commercial-groups.store');
                Route::put('commercial-groups/{commercial_group}', [CommercialGroupController::class, 'update'])->name('store.commercial-groups.update');
                Route::delete('commercial-groups/{commercial_group}', [CommercialGroupController::class, 'destroy'])->name('store.commercial-groups.destroy');

                Route::get('customers', [CustomerController::class, 'index'])->name('store.customers.index');
                Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('store.customers.show');
                Route::post('customers', [CustomerController::class, 'store'])->name('store.customers.store');
                Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('store.customers.update');
                Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('store.customers.destroy');

                Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index'])->name('store.customers.addresses.index');
                Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store'])->name('store.customers.addresses.store');
                Route::get('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'show'])->name('store.customers.addresses.show');
                Route::put('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update'])->name('store.customers.addresses.update');
                Route::delete('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy'])->name('store.customers.addresses.destroy');

                Route::get('customers/{customer}/contacts', [CustomerContactController::class, 'index'])->name('store.customers.contacts.index');
                Route::post('customers/{customer}/contacts', [CustomerContactController::class, 'store'])->name('store.customers.contacts.store');
                Route::get('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'show'])->name('store.customers.contacts.show');
                Route::put('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'update'])->name('store.customers.contacts.update');
                Route::delete('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'destroy'])->name('store.customers.contacts.destroy');
            });

            Route::middleware(['role:STORE_ADMIN', 'feature:multi_user'])->group(function () {
                Route::get('users/filter-options', [StoreUserController::class, 'filterOptions'])->name('store.users.filter-options');
                Route::get('users', [StoreUserController::class, 'index'])->name('store.users.index');
                Route::get('users/{user}', [StoreUserController::class, 'show'])->name('store.users.show');
                Route::post('users', [StoreUserController::class, 'store'])->name('store.users.store');
                Route::put('users/{user}', [StoreUserController::class, 'update'])->name('store.users.update');
                Route::delete('users/{user}', [StoreUserController::class, 'destroy'])->name('store.users.destroy');

                Route::get('users/{user}/permissions', [StoreUserPermissionController::class, 'show'])->name('store.users.permissions.show');
                Route::post('users/{user}/permissions', [StoreUserPermissionController::class, 'update'])->name('store.users.permissions.update');
            });
        });
});
