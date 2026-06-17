<?php

namespace Tests\Feature\Api\V1;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class AuthTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
  }

  /** @test */
  public function a_user_can_login_with_correct_credentials()
  {
    $plan = Plan::factory()->create();
    $feature = Feature::factory()->create(['code' => 'feature1']);
    $plan->features()->attach($feature);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $user = User::factory()->create([
      'email' => 'test@centra.com',
      'password' => bcrypt('password123'),
      'store_id' => $store->id,
    ]);
    $user->assignRole('SUPER_ADMIN');

    $response = $this->postJson('/api/v1/login', [
      'email' => 'test@centra.com',
      'password' => 'password123',
    ]);

    $response->assertStatus(200)
      ->assertJsonStructure([
        'data' => [
          'token',
          'user' => ['id', 'name', 'email', 'store', 'roles', 'permissions', 'features']
        ],
        'message'
      ])
      ->assertJsonPath('data.user.email', 'test@centra.com')
      ->assertJsonPath('data.user.roles.0', 'SUPER_ADMIN')
      ->assertJsonPath('data.user.features.0.code', 'feature1');
  }

  /** @test */
  public function a_user_cannot_login_with_incorrect_password()
  {
    User::factory()->create([
      'email' => 'test@centra.com',
      'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/v1/login', [
      'email' => 'test@centra.com',
      'password' => 'wrong-password',
    ]);

    $response->assertStatus(401)
      ->assertJsonPath('message', 'Credenciales inválidas.');
  }

  /** @test */
  public function an_authenticated_user_can_get_their_data()
  {
    $plan = Plan::factory()->create();
    $feature1 = Feature::factory()->create(['code' => 'feature1']);
    $feature2 = Feature::factory()->create(['code' => 'feature2']);
    $plan->features()->attach([$feature1->id, $feature2->id]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
      ->getJson('/api/v1/me');

    $response->assertStatus(200)
      ->assertJsonStructure([
        'data' => [
          'user' => ['id', 'name', 'email', 'store', 'roles', 'permissions', 'features']
        ]
      ])
      ->assertJsonPath('data.user.email', $user->email)
      ->assertJsonCount(2, 'data.user.features')
      ->assertJsonPath('data.user.features.0.code', 'feature1');
  }

  /** @test */
  public function a_user_can_logout()
  {
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;


    $this->withHeader('Authorization', "Bearer $token")
      ->postJson('/api/v1/logout')
      ->assertStatus(200);

    $this->assertDatabaseCount('personal_access_tokens', 0);
  }

  /** @test */
  public function a_user_without_store_has_empty_features_array()
  {
    $user = User::factory()->create(['store_id' => null]);
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
      ->getJson('/api/v1/me');

    $response->assertStatus(200)
      ->assertJsonPath('data.user.features', []);
  }

  /** @test */
  public function a_user_with_store_without_plan_has_empty_features_array()
  {
    $store = Store::factory()->create(['plan_id' => null]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
      ->getJson('/api/v1/me');

    $response->assertStatus(200)
      ->assertJsonPath('data.user.features', []);
  }
}
