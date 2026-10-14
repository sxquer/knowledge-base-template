<?php

namespace Database\Factories;

use App\Models\PasswordEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PasswordEntry>
 */
class PasswordEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->company().' · кабинет',
            'username' => fake()->safeEmail(),
            'secret' => 'test-only-secret-'.fake()->uuid(),
            'url' => fake()->url(),
            'notes' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
