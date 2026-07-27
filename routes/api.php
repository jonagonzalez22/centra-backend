<?php

use App\Http\Controllers\Api\V1\Admin\BusinessTypeController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\FeatureController;
use App\Http\Controllers\Api\V1\Admin\PaymentMethodController as AdminPaymentMethodController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\StoreController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogsController;
use App\Http\Controllers\Api\V1\GeocodingController;
use App\Http\Controllers\Api\V1\Store\CashSessionController;
use App\Http\Controllers\Api\V1\Store\CategoryController;
use App\Http\Controllers\Api\V1\Store\CommercialGroupController;
use App\Http\Controllers\Api\V1\Store\CommercialOperationController;
use App\Http\Controllers\Api\V1\Store\CustomerAddressController;
use App\Http\Controllers\Api\V1\Store\OrderController;
use App\Http\Controllers\Api\V1\Store\CustomerContactController;
use App\Http\Controllers\Api\V1\Store\CustomerController;
use App\Http\Controllers\Api\V1\Store\DriverController;
use App\Http\Controllers\Api\V1\Store\GenerateSkuController;
use App\Http\Controllers\Api\V1\Store\InventoryController;
use App\Http\Controllers\Api\V1\Store\PaymentMethodController as StorePaymentMethodController;
use App\Http\Controllers\Api\V1\Store\PermissionCatalogController;
use App\Http\Controllers\Api\V1\Store\ProductController;
use App\Http\Controllers\Api\V1\Store\ProductSearchController;
use App\Http\Controllers\Api\V1\Store\RouteController;
use App\Http\Controllers\Api\V1\Store\StoreUserController;
use App\Http\Controllers\Api\V1\Store\StoreUserPermissionController;
use App\Http\Controllers\Api\V1\Store\VehicleCatalogController;
use App\Http\Controllers\Api\V1\Store\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('geocoding/search', [GeocodingController::class, 'search'])->name('geocoding.search');
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

                Route::get('payment-methods', [AdminPaymentMethodController::class, 'index'])
                    ->middleware('permission:payment_methods.view');
                Route::post('payment-methods', [AdminPaymentMethodController::class, 'store'])
                    ->middleware('permission:payment_methods.create');
                Route::get('payment-methods/{id}', [AdminPaymentMethodController::class, 'show'])
                    ->middleware('permission:payment_methods.view');
                Route::put('payment-methods/{id}', [AdminPaymentMethodController::class, 'update'])
                    ->middleware('permission:payment_methods.edit');
                Route::delete('payment-methods/{id}', [AdminPaymentMethodController::class, 'destroy'])
                    ->middleware('permission:payment_methods.delete');
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

    Route::prefix('catalogs')
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->group(function () {
            Route::get('document-types', [CatalogsController::class, 'documentTypes'])
                ->name('catalogs.document-types');
            Route::get('provinces', [CatalogsController::class, 'provinces'])
                ->name('catalogs.provinces');
            Route::get('provinces/{province}/localities', [CatalogsController::class, 'localities'])
                ->name('catalogs.provinces.localities');
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

            Route::middleware('feature:cash')->group(function () {
                Route::get('cash/current', [CashSessionController::class, 'current'])->name('store.cash.current');
                Route::post('cash/open', [CashSessionController::class, 'open'])->name('store.cash.open');
                Route::post('cash/{cashSession}/close', [CashSessionController::class, 'close'])->name('store.cash.close');
            });

            Route::middleware('feature:pos')->group(function () {
                Route::get('orders', [OrderController::class, 'index'])
                    ->middleware('permission:orders.view')
                    ->name('store.orders.index');
                Route::get('orders/{id}', [OrderController::class, 'show'])
                    ->middleware('permission:orders.view')
                    ->name('store.orders.show');

                Route::get('operations', [CommercialOperationController::class, 'index'])->name('store.operations.index');
                Route::get('operations/{operation}', [CommercialOperationController::class, 'show'])->name('store.operations.show');
                Route::post('operations', [CommercialOperationController::class, 'store'])->name('store.operations.store');
                Route::put('operations/{operation}/reschedule', [CommercialOperationController::class, 'reschedule'])
                    ->middleware('permission:orders.edit')
                    ->name('store.operations.reschedule');
                Route::put('operations/{operation}/cancel', [CommercialOperationController::class, 'cancel'])
                    ->middleware('permission:orders.edit')
                    ->name('store.operations.cancel');
            });

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

            Route::middleware('feature:deliveries')->group(function () {
                // Catalog routes — MUST be before {vehicle} to avoid capture
                Route::get('vehicles/catalogs/types', [VehicleCatalogController::class, 'types'])
                    ->middleware('permission:vehicles.view')
                    ->name('store.vehicles.catalogs.types');
                Route::get('vehicles/catalogs/reasons', [VehicleCatalogController::class, 'reasons'])
                    ->middleware('permission:vehicles.view')
                    ->name('store.vehicles.catalogs.reasons');

                // Vehicle CRUD
                Route::get('vehicles', [VehicleController::class, 'index'])
                    ->middleware('permission:vehicles.view')
                    ->name('store.vehicles.index');
                Route::post('vehicles', [VehicleController::class, 'store'])
                    ->middleware('permission:vehicles.create')
                    ->name('store.vehicles.store');
                Route::get('vehicles/{vehicle}', [VehicleController::class, 'show'])
                    ->middleware('permission:vehicles.view')
                    ->name('store.vehicles.show');
                Route::put('vehicles/{vehicle}', [VehicleController::class, 'update'])
                    ->middleware('permission:vehicles.edit')
                    ->name('store.vehicles.update');
                Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy'])
                    ->middleware('permission:vehicles.delete')
                    ->name('store.vehicles.destroy');
                Route::patch('vehicles/{vehicle}/toggle-active', [VehicleController::class, 'toggleActive'])
                    ->middleware('permission:vehicles.edit')
                    ->name('store.vehicles.toggle-active');

                // Driver listing
                Route::get('drivers', [DriverController::class, 'index'])
                    ->middleware('permission:drivers.view')
                    ->name('store.drivers.index');

                // Route Management — eligible-orders BEFORE parameterized routes
                Route::get('routes/eligible-orders', [RouteController::class, 'eligibleOrders'])
                    ->middleware('permission:logistics.routes.view')
                    ->name('store.routes.eligible-orders');

                Route::get('routes', [RouteController::class, 'index'])
                    ->middleware('permission:logistics.routes.view')
                    ->name('store.routes.index');

                Route::post('routes', [RouteController::class, 'store'])
                    ->middleware('permission:logistics.routes.manage')
                    ->name('store.routes.store');

                Route::get('routes/{route}', [RouteController::class, 'show'])
                    ->middleware('permission:logistics.routes.view')
                    ->name('store.routes.show');

                Route::put('routes/{route}', [RouteController::class, 'update'])
                    ->middleware('permission:logistics.routes.manage')
                    ->name('store.routes.update');

                Route::post('routes/{route}/stops', [RouteController::class, 'addStop'])
                    ->middleware('permission:logistics.routes.manage')
                    ->name('store.routes.stops.store');

                Route::delete('routes/{route}/stops/{stop}', [RouteController::class, 'removeStop'])
                    ->middleware('permission:logistics.routes.manage')
                    ->name('store.routes.stops.destroy');

                Route::put('routes/{route}/stops/reorder', [RouteController::class, 'reorderStops'])
                    ->middleware('permission:logistics.routes.manage')
                    ->name('store.routes.stops.reorder');

                Route::post('routes/{route}/plan', [RouteController::class, 'plan'])
                    ->middleware('permission:logistics.routes.plan')
                    ->name('store.routes.plan');

                Route::post('routes/{route}/revert', [RouteController::class, 'revert'])
                    ->middleware('permission:logistics.routes.revert')
                    ->name('store.routes.revert');

                Route::post('routes/{route}/cancel', [RouteController::class, 'cancel'])
                    ->middleware('permission:logistics.routes.cancel')
                    ->name('store.routes.cancel');
            });

            Route::middleware('feature:store_settings')->group(function () {
                Route::get('payment-methods', [StorePaymentMethodController::class, 'index'])
                    ->name('store.payment-methods.index');
                Route::patch('payment-methods/{paymentMethod}', [StorePaymentMethodController::class, 'update'])
                    ->name('store.payment-methods.update');
            });
        });
});
