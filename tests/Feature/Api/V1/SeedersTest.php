<?php

use App\Models\Admin\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('DatabaseSeeder siembra roles en env testing', function () {
  /** @var \Tests\TestCase $this */
  app()->detectEnvironment(fn() => 'testing');

  app()->make(DatabaseSeeder::class)->run();

  $this->assertDatabaseHas('roles', ['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
  $this->assertDatabaseHas('roles', ['name' => 'STORE_ADMIN', 'guard_name' => 'web']);

  $this->assertDatabaseMissing('stores', ['email' => 'ferreteria@central.com']);
  $this->assertDatabaseMissing('users', ['email' => 'admin@centra.com']);
});

test('DatabaseSeeder siembra store y usuarios con roles en env local', function () {
  /** @var \Tests\TestCase $this */
  app()->detectEnvironment(fn() => 'local');

  app()->make(DatabaseSeeder::class)->run();

  $this->assertDatabaseHas('stores', [
    'email' => 'ferreteria@central.com',
    'is_active' => true,
  ]);

  $store = Store::where('email', 'ferreteria@central.com')->firstOrFail();

  $this->assertDatabaseHas('users', ['email' => 'admin@centra.com', 'store_id' => null]);
  $this->assertDatabaseHas('users', ['email' => 'cliente@test.com', 'store_id' => $store->id]);

  /** @var \App\Models\User $admin */
  $admin = User::where('email', 'admin@centra.com')->firstOrFail();
  /** @var \App\Models\User $storeAdmin */
  $storeAdmin = User::where('email', 'cliente@test.com')->firstOrFail();

  $this->assertTrue($admin->hasRole('SUPER_ADMIN'));
  $this->assertTrue($storeAdmin->hasRole('STORE_ADMIN'));

  // Validación extra: que las roles creadas tengan el guard correcto.
  $this->assertSame('web', Role::where('name', 'SUPER_ADMIN')->value('guard_name'));
  $this->assertSame('web', Role::where('name', 'STORE_ADMIN')->value('guard_name'));
});
