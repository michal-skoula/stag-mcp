<?php

namespace Database\Factories;

use App\Models\StagCall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StagCall>
 */
class StagCallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tool' => null,
            'method' => 'GET',
            'endpoint' => fake()->randomElement(['znamky/getZnamkyByStudent', 'ucitel/getUcitelInfo']),
            'status' => 200,
            'duration_ms' => fake()->randomFloat(1, 20, 1500),
        ];
    }
}
