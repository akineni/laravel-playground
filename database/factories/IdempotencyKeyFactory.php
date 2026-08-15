<?php

namespace Database\Factories;

use App\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'status' => 'completed',
            'response_status' => 200,
            'response_body' => ['status' => 'success', 'message' => 'OK'],
        ];
    }
}
