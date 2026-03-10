<?php

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('api can list all stores', function () {
  /** @var \Tests\TestCase $this */
  Store::factory()->create(['name' => 'Store A']);
  Store::factory()->create(['name' => 'Store B']);

  $response = $this->getJson('/api/stores');

  $response->assertStatus(200)
    ->assertJsonCount(2);
});

test('api can create a new store', function () {
  $data = [
    'name' => 'Ferretería Central',
    'email' => 'central@test.com',
    'status' => 'active'
  ];

  $response = $this->postJson('/api/stores', $data);

  $response->assertStatus(201);
  $this->assertDatabaseHas('stores', ['name' => 'Ferretería Central']);
});
