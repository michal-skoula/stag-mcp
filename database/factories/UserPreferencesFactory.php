<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreferences>
 */
class UserPreferencesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'city' => fake()->city(),
        ];
    }
}
