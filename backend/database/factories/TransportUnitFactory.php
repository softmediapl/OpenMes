<?php

namespace Database\Factories;

use App\Models\TransportUnit;
use App\Models\TransportUnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportUnit>
 */
class TransportUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transport_unit_type_id' => TransportUnitType::factory(),
            'code' => strtoupper(fake()->unique()->bothify('UNIT-####')),
            'capacity_quantity' => null,
            'unit_of_measure' => null,
            'status' => TransportUnit::STATUS_AVAILABLE,
        ];
    }
}
