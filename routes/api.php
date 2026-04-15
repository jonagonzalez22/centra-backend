<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Admin\StoreController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

  // Public routes with strict rate limit
  Route::middleware('throttle:auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
  });

  // Protected routes with general rate limit
  Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me',      [AuthController::class, 'me']);
  });
});

Route::prefix('v1/admin')->group(function () {


  Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    Route::apiResource('stores', StoreController::class);
  });
});
