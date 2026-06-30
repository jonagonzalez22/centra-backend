<?php

namespace App\Providers;

use App\Models\Customer;
use App\Observers\CustomerObserver;
use App\Support\PermissionFeatureResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->app->resolving(Gate::class, function ($gate) {
      $gate->before(function ($user, $ability) {
        if ($user->hasRole('SUPER_ADMIN')) {
          return null;
        }

        if (is_null($user->store_id)) {
          return null;
        }

        $featureCode = PermissionFeatureResolver::resolveFeature($ability);

        if ($featureCode === null) {
          return null;
        }

        if (! $user->store || ! $user->store->hasFeature($featureCode)) {
          return false;
        }

        return null;
      });
    });
  }

  public function boot(): void
  {
    Customer::observe(CustomerObserver::class);

    RateLimiter::for('api', function (Request $request) {
      return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });

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
