<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckFeatureTest extends TestCase
{
  use RefreshDatabase;

  #[Test]
  public function backoffice_user_without_store_can_access_any_feature()
  {
    // Un usuario sin tienda (backoffice) siempre puede acceder
    $user = User::factory()->create([
      'store_id' => null,
    ]);

    $this->assertNull($user->store_id);
  }

  #[Test]
  public function store_user_without_plan_cannot_access_feature()
  {
    $store = Store::factory()->create([
      'plan_id' => null,
    ]);
    $user = User::factory()->create([
      'store_id' => $store->id,
    ]);

    // La tienda no tiene plan
    $this->assertNull($store->plan);
  }

  #[Test]
  public function store_user_with_plan_can_access_feature_if_included()
  {
    // Crear feature
    $feature = Feature::factory()->create([
      'code' => 'feature_test',
      'name' => 'Feature Test',
    ]);

    // Crear plan
    $plan = Plan::factory()->create([
      'name' => 'Plan Test',
    ]);

    // Asociar feature al plan
    $plan->features()->sync([$feature->id]);

    // Crear store con plan
    $store = Store::factory()->create(['plan_id' => $plan->id]);

    // Recargar las relaciones
    $plan = $plan->fresh();
    $store = $store->fresh();

    // Verificar que el plan tiene la feature
    $this->assertTrue($plan->features->contains('id', $feature->id));
    $this->assertTrue($store->hasFeature('feature_test'));
  }

  #[Test]
  public function store_user_with_plan_cannot_access_feature_if_not_included()
  {
    // Crear dos features
    $feature1 = Feature::factory()->create([
      'code' => 'feature_1',
      'name' => 'Feature 1',
    ]);

    $feature2 = Feature::factory()->create([
      'code' => 'feature_2',
      'name' => 'Feature 2',
    ]);

    // Crear plan con solo feature1
    $plan = Plan::factory()->create();
    $plan->features()->sync([$feature1->id]);

    // Crear store
    $store = Store::factory()->create(['plan_id' => $plan->id]);
    $store = $store->fresh();

    // Verificar que tiene feature1 pero no feature2
    $this->assertTrue($store->hasFeature('feature_1'));
    $this->assertFalse($store->hasFeature('feature_2'));
  }

  #[Test]
  public function plan_with_multiple_features_works_correctly()
  {
    $feature1 = Feature::factory()->create(['code' => 'feature_1']);
    $feature2 = Feature::factory()->create(['code' => 'feature_2']);
    $feature3 = Feature::factory()->create(['code' => 'feature_3']);

    $plan = Plan::factory()->create();
    $plan->features()->sync([$feature1->id, $feature2->id]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);
    $store = $store->fresh();

    $this->assertTrue($store->hasFeature('feature_1'));
    $this->assertTrue($store->hasFeature('feature_2'));
    $this->assertFalse($store->hasFeature('feature_3'));
  }

  #[Test]
  public function store_without_plan_returns_false_on_has_feature()
  {
    $store = Store::factory()->create([
      'plan_id' => null,
    ]);

    $this->assertFalse($store->hasFeature('any_feature'));
  }

  #[Test]
  public function feature_code_lookup_is_case_sensitive()
  {
    $feature = Feature::factory()->create([
      'code' => 'feature_test',
    ]);

    $plan = Plan::factory()->create();
    $plan->features()->sync([$feature->id]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);
    $store = $store->fresh();

    // Buscar con el código exacto
    $this->assertTrue($store->hasFeature('feature_test'));

    // Buscar con código diferente debería ser falso
    $this->assertFalse($store->hasFeature('FEATURE_TEST'));
    $this->assertFalse($store->hasFeature('Feature_Test'));
  }
}
