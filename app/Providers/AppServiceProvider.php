<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
  /**
   * Register any application services.
   */
  public function register(): void
  {
    //
  }

  /**
   * Bootstrap any application services.
   */
  public function boot(): void
  {
    // Rate limit general to all API (60 req/min by IP or user)
    RateLimiter::for('api', function (Request $request) {
      return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });

    // Strict rate limit for login (5 attempts/min by IP)
    RateLimiter::for('auth', function (Request $request) {
      return Limit::perMinute(5)->by($request->ip())->response(function () {
        return response()->json([
          'status'      => 'error',
          'message'     => 'Demasiados intentos. Esperá un momento.',
          'data'        => null,
          'errors'      => [],
          'retry_after' => 60,
        ], 429);
      });
    });

    // Rate limit for registration (10 attempts/hour by IP)
    RateLimiter::for('register', function (Request $request) {
      return Limit::perHour(10)->by($request->ip())->response(function () {
        return response()->json([
          'status'      => 'error',
          'message'     => 'Demasiados registros. Intentá más tarde.',
          'data'        => null,
          'errors'      => [],
        ], 429);
      });
    });
  }
}
