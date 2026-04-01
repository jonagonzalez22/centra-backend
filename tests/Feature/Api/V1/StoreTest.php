<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('api can list all stores', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $token = $user->createToken('test-token')->plainTextToken;

  Store::factory()->create(['name' => 'Store A']);
  Store::factory()->create(['name' => 'Store B']);

  $response = $this->withHeader('Authorization', "Bearer $token")
    ->getJson('/api/v1/stores');

  $response->assertStatus(200)
    ->assertJsonCount(2);
});

test('api can create a new store', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $token = $user->createToken('test-token')->plainTextToken;

  $data = [
    'name' => 'Ferretería Central',
    'email' => 'central@test.com',
    'status' => 'active'
  ];

  $response = $this->withHeader('Authorization', "Bearer $token")
    ->postJson('/api/v1/stores', $data);

  $response->assertStatus(201);
  $this->assertDatabaseHas('stores', ['name' => 'Ferretería Central']);
});
