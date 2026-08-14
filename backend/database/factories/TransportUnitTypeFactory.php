<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TransportUnitType>
 */
class TransportUnitTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('TU-???')),
            'name' => fake()->words(2, true),
            'default_capacity_quantity' => 100,
            'unit_of_measure' => 'pcs',
            'is_active' => true,
        ];
    }
}
