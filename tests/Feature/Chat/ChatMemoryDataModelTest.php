<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChatMemoryDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_conversation_factory_creates_row_with_cast_columns(): void
    {
        $conversation = ChatConversation::factory()->create([
            'context_metadata' => ['module' => 'citas', 'topic' => 'reprogramacion'],
        ]);

        $fresh = $conversation->fresh();

        $this->assertIsArray($fresh->context_metadata);
        $this->assertSame('citas', $fresh->context_metadata['module']);
        $this->assertInstanceOf(Carbon::class, $fresh->last_message_at);
        $this->assertNotNull($fresh->title);
        $this->assertSame('active', $fresh->status);
    }

    public function test_chat_message_factory_sets_role_and_client_message_id_is_fillable(): void
    {
        $message = ChatMessage::factory()->assistant()->create([
            'client_message_id' => 'abc-123',
            'meta' => ['pending' => ['type' => 'offer']],
        ]);

        $fresh = $message->fresh();

        $this->assertSame('assistant', $fresh->role);
        $this->assertSame('abc-123', $fresh->client_message_id);
        $this->assertIsArray($fresh->meta);
    }

    public function test_user_chat_conversations_relation_works(): void
    {
        $user = User::factory()->create();
        ChatConversation::factory()->count(2)->create(['user_id' => $user->id]);
        ChatConversation::factory()->guest()->create();

        $this->assertCount(2, $user->fresh()->chatConversations);
    }

    public function test_conversation_scopes_filter_and_order(): void
    {
        $user = User::factory()->create();

        $older = ChatConversation::factory()->create([
            'user_id' => $user->id,
            'last_message_at' => now()->subDay(),
        ]);
        $newer = ChatConversation::factory()->create([
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);
        ChatConversation::factory()->archived()->create(['user_id' => $user->id]);
        ChatConversation::factory()->create(['user_id' => User::factory()]);

        $active = ChatConversation::forUser($user->id)->active()->recent()->get();

        $this->assertCount(2, $active);
        $this->assertTrue($active->first()->is($newer));
        $this->assertTrue($active->last()->is($older));
        $this->assertCount(1, ChatConversation::forUser($user->id)->archived()->get());
    }
}
