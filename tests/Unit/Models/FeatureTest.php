<?php

namespace Tests\Unit\Models;

use App\Models\Feature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use PHPUnit\Framework\TestCase;

class FeatureTest extends TestCase
{
  public function test_feature_model_uses_has_factory(): void
  {
    $this->assertTrue(in_array(HasFactory::class, class_uses(Feature::class)));
  }

  public function test_feature_model_uses_has_uuids(): void
  {
    $this->assertTrue(in_array(HasUuids::class, class_uses(Feature::class)));
  }

  public function test_feature_fillable_attributes(): void
  {
    $feature = new Feature();
    $expectedFillable = ['code', 'name', 'description'];

    $this->assertEquals($expectedFillable, $feature->getFillable());
  }
}
