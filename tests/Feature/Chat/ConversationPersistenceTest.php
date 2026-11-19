<?php

namespace Tests\Feature\Chat;

use App\Enums\Role;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): User
    {
        return User::factory()->create([
            'role' => Role::Patient,
            'is_active' => true,
        ]);
    }

    public function test_send_persists_a_turn_for_the_user(): void
    {
        Http::fake();
        $patient = $this->patient();

        $this->actingAs($patient)
            ->postJson(route('chat.send'), ['message' => 'Hola, información general por favor'])
            ->assertOk();

        $this->assertDatabaseHas('chat_conversations', ['user_id' => $patient->id]);

        $conversation = ChatConversation::where('user_id', $patient->id)->firstOrFail();
        $this->assertDatabaseHas('chat_messages', [
            'chat_conversation_id' => $conversation->id,
            'role' => 'user',
        ]);
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_index_lists_only_the_authenticated_users_conversations(): void
    {
        $owner = $this->patient();
        $other = $this->patient();

        $mine = ChatConversation::factory()->count(2)->create(['user_id' => $owner->id]);
        ChatConversation::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($owner)
            ->getJson(route('chat.conversations.index'))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame($mine->pluck('id')->sort()->values()->all(), $ids);
    }

    public function test_show_returns_the_conversation_messages_in_order(): void
    {
        $patient = $this->patient();
        $conversation = ChatConversation::factory()->create(['user_id' => $patient->id]);

        ChatMessage::factory()->user()->create([
            'chat_conversation_id' => $conversation->id,
            'content' => 'primer mensaje',
        ]);
        ChatMessage::factory()->assistant()->create([
            'chat_conversation_id' => $conversation->id,
            'content' => 'segunda respuesta',
        ]);

        $response = $this->actingAs($patient)
            ->getJson(route('chat.conversations.show', $conversation))
            ->assertOk();

        $contents = collect($response->json('data.messages'))->pluck('content')->all();
        $this->assertSame(['primer mensaje', 'segunda respuesta'], $contents);
    }

    public function test_store_creates_a_new_independent_conversation(): void
    {
        $patient = $this->patient();
        $existing = ChatConversation::factory()->create(['user_id' => $patient->id]);

        $response = $this->actingAs($patient)
            ->postJson(route('chat.conversations.store'), ['title' => 'Nueva charla'])
            ->assertCreated();

        $newId = $response->json('data.id');
        $this->assertNotSame($existing->id, $newId);
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $newId,
            'user_id' => $patient->id,
            'title' => 'Nueva charla',
            'status' => 'active',
        ]);
    }

    public function test_most_recent_active_conversation_is_reused_on_send(): void
    {
        Http::fake();
        $patient = $this->patient();
        $conversation = ChatConversation::factory()->create(['user_id' => $patient->id]);

        $response = $this->actingAs($patient)
            ->postJson(route('chat.send'), ['message' => '¿Qué servicios ofrecen?'])
            ->assertOk();

        $this->assertSame($conversation->id, $response->json('conversation_id'));
        $this->assertSame(1, ChatConversation::where('user_id', $patient->id)->count());
    }

    public function test_explicit_conversation_id_continues_that_conversation(): void
    {
        Http::fake();
        $patient = $this->patient();
        $target = ChatConversation::factory()->create(['user_id' => $patient->id]);
        ChatConversation::factory()->create(['user_id' => $patient->id]);

        $response = $this->actingAs($patient)
            ->postJson(route('chat.send'), [
                'message' => 'Continuemos aquí',
                'conversation_id' => $target->id,
            ])
            ->assertOk();

        $this->assertSame($target->id, $response->json('conversation_id'));
    }

    public function test_repeated_client_message_id_does_not_duplicate_messages(): void
    {
        Http::fake();
        $patient = $this->patient();

        $payload = [
            'message' => 'Hola, ¿me pueden dar información?',
            'client_message_id' => 'retry-abc-123',
        ];

        $first = $this->actingAs($patient)->postJson(route('chat.send'), $payload)->assertOk();
        $conversationId = $first->json('conversation_id');
        $payload['conversation_id'] = $conversationId;

        $countAfterFirst = ChatMessage::where('chat_conversation_id', $conversationId)->count();

        $second = $this->actingAs($patient)->postJson(route('chat.send'), $payload)->assertOk();

        $this->assertSame(
            $countAfterFirst,
            ChatMessage::where('chat_conversation_id', $conversationId)->count(),
            'Un reintento con el mismo client_message_id no debe crear mensajes nuevos.'
        );
        $this->assertSame($first->json('reply'), $second->json('reply'));
    }
}
