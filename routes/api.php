<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Admin\StoreController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

  // Rutas públicas (Login)
  Route::middleware('throttle:auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
  });

  // Rutas protegidas generales (Cualquier usuario logueado)
  Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me',      [AuthController::class, 'me']);
  });
  Route::prefix('admin')
    ->middleware(['auth:sanctum', 'throttle:api', 'role:SUPER_ADMIN'])
    ->group(function () {
      Route::apiResource('stores', StoreController::class);
      Route::apiResource('users', UserController::class);
    });

  Route::prefix('store')
    ->middleware(['auth:sanctum', 'throttle:api', 'role:STORE_ADMIN|STORE_USER'])
    ->group(function () {});
});
