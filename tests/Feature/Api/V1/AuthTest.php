<?php

namespace Tests\Feature\Api\V1;

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
    $user = User::factory()->create([
      'email' => 'test@centra.com',
      'password' => bcrypt('password123'),
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
          'user' => ['id', 'name', 'email', 'roles', 'permissions']
        ],
        'message'
      ])
      ->assertJsonPath('data.user.email', 'test@centra.com')
      ->assertJsonPath('data.user.roles.0', 'SUPER_ADMIN');
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
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
      ->getJson('/api/v1/me');

    $response->assertStatus(200)
      ->assertJsonPath('data.user.email', $user->email);
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
}
