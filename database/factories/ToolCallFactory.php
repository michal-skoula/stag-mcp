<?php

namespace Database\Factories;

use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToolCall>
 */
class ToolCallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tool' => fake()->randomElement(['who-am-i', 'get-znamky', 'search-ucitele']),
            'failed' => false,
            'duration_ms' => fake()->randomFloat(1, 5, 900),
        ];
    }
}
