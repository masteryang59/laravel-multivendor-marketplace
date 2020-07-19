<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'vendor_id' => Vendor::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'sku' => strtoupper(Str::random(8)),
            'description' => fake()->paragraph(),
            'price_cents' => fake()->numberBetween(5, 120) * 100,
            'stock' => 10,
            'status' => ProductStatus::Active,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft]);
    }
}
