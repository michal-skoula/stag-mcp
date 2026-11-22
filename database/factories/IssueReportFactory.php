<?php

namespace Database\Factories;

use App\Models\IssueReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueReport>
 */
class IssueReportFactory extends Factory
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
            'tool' => 'get-znamky',
            'description' => fake()->sentence(),
            'error' => null,
            'arguments' => null,
            'occurred_at' => now(),
        ];
    }
}
