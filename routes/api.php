<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\StoreController;
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

    Route::apiResource('stores', StoreController::class);
  });
});
