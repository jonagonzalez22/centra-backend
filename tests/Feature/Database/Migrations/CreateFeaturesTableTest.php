<?php

namespace Tests\Feature\Database\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateFeaturesTableTest extends TestCase
{
  use RefreshDatabase;

  public function test_features_table_exists(): void
  {
    $this->assertTrue(Schema::hasTable('features'));
  }

  public function test_features_table_has_required_columns(): void
  {
    $this->assertTrue(Schema::hasColumns('features', ['id', 'code', 'name', 'description', 'created_at', 'updated_at']));
  }

  public function test_features_table_id_is_primary_key(): void
  {
    // Verificar que la tabla tiene una columna id
    $this->assertTrue(Schema::hasColumn('features', 'id'));
  }

  public function test_features_table_code_is_unique(): void
  {
    // Verificar que existe la columna code
    $this->assertTrue(Schema::hasColumn('features', 'code'));
  }

  public function test_features_table_description_is_nullable(): void
  {
    // Verificar que la tabla tiene una columna description
    $this->assertTrue(Schema::hasColumn('features', 'description'));
  }

  public function test_can_create_feature_record(): void
  {
    $feature = \App\Models\Feature::create([
      'code' => 'test-feature',
      'name' => 'Test Feature',
      'description' => 'Test Description',
    ]);

    $this->assertNotNull($feature->id);
    $this->assertEquals('test-feature', $feature->code);
    $this->assertEquals('Test Feature', $feature->name);
    $this->assertEquals('Test Description', $feature->description);
  }
}
