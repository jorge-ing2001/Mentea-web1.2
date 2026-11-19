<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\ChatUserPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatUserPreference> */
class ChatUserPreferenceFactory extends Factory
{
    protected $model = ChatUserPreference::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => Role::Patient]),
            'key' => 'response_length',
            'value' => fake()->randomElement(['breve', 'detallada']),
            'consented_at' => now(),
        ];
    }

    /**
     * Preferencia aún no consentida (sin consented_at).
     */
    public function withoutConsent(): static
    {
        return $this->state(fn () => ['consented_at' => null]);
    }

    public function explanationStyle(): static
    {
        return $this->state(fn () => [
            'key' => 'explanation_style',
            'value' => 'paso_a_paso',
        ]);
    }
}
