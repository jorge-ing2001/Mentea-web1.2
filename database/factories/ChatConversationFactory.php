<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatConversation> */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => Role::Patient]),
            'session_id' => fake()->uuid(),
            'title' => fake()->sentence(3),
            'summary' => null,
            'context_metadata' => null,
            'status' => 'active',
            'last_activity_at' => now(),
            'last_message_at' => now(),
        ];
    }

    public function guest(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => 'archived']);
    }
}
