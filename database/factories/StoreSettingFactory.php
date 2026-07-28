<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreSetting>
 */
class StoreSettingFactory extends Factory
{
    protected $model = StoreSetting::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'delivery_unload_time_minutes' => 15,
        ];
    }
}
