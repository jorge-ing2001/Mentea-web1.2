<?php

namespace Tests\Feature\Chat;

use App\Enums\Role;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ConversationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role = Role::Patient): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_user_cannot_view_another_users_conversation(): void
    {
        $owner = $this->user();
        $attacker = $this->user();
        $conversation = ChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($attacker)
            ->getJson(route('chat.conversations.show', $conversation))
            ->assertNotFound();
    }

    public function test_user_cannot_update_another_users_conversation(): void
    {
        $owner = $this->user();
        $attacker = $this->user();
        $conversation = ChatConversation::factory()->create(['user_id' => $owner->id, 'title' => 'original']);

        $this->actingAs($attacker)
            ->patchJson(route('chat.conversations.update', $conversation), ['title' => 'hackeado'])
            ->assertNotFound();

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'title' => 'original',
        ]);
    }

    public function test_user_cannot_delete_another_users_conversation(): void
    {
        $owner = $this->user();
        $attacker = $this->user();
        $conversation = ChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($attacker)
            ->deleteJson(route('chat.conversations.destroy', $conversation))
            ->assertNotFound();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function test_super_admin_cannot_read_another_users_conversation_despite_gate_before(): void
    {
        $owner = $this->user();
        $superAdmin = $this->user(Role::SuperAdmin);
        $conversation = ChatConversation::factory()->create(['user_id' => $owner->id]);

        // El Gate::before global aprueba a super_admin, pero la resolución
        // acotada por user_id impide leer la conversación de otro usuario.
        $this->actingAs($superAdmin)
            ->getJson(route('chat.conversations.show', $conversation))
            ->assertNotFound();
    }

    public function test_tampering_the_id_in_the_url_fails(): void
    {
        $attacker = $this->user();
        $owner = $this->user();
        $conversation = ChatConversation::factory()->create(['user_id' => $owner->id]);

        // Id directo manipulado: no existe dentro del alcance del atacante.
        $this->actingAs($attacker)
            ->getJson('/chat/conversations/'.$conversation->id)
            ->assertNotFound();
    }

    public function test_guest_cannot_reach_the_manage_endpoints(): void
    {
        $conversation = ChatConversation::factory()->create();

        $this->getJson(route('chat.conversations.index'))->assertUnauthorized();
        $this->getJson(route('chat.conversations.show', $conversation))->assertUnauthorized();
        $this->postJson(route('chat.conversations.store'))->assertUnauthorized();
        $this->patchJson(route('chat.conversations.update', $conversation))->assertUnauthorized();
        $this->deleteJson(route('chat.conversations.destroy', $conversation))->assertUnauthorized();
    }

    public function test_denied_attempt_is_logged_without_message_content(): void
    {
        $owner = $this->user();
        $attacker = $this->user();
        $conversation = ChatConversation::factory()->create(['user_id' => $owner->id]);

        $secret = 'contenido clinico sensible que no debe aparecer en logs';
        ChatMessage::factory()->user()->create([
            'chat_conversation_id' => $conversation->id,
            'content' => $secret,
        ]);

        Log::spy();

        $this->actingAs($attacker)
            ->getJson(route('chat.conversations.show', $conversation))
            ->assertNotFound();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($conversation, $attacker, $secret) {
                $this->assertArrayHasKey('conversation_id', $context);
                $this->assertArrayHasKey('user_id', $context);
                $this->assertArrayHasKey('reason', $context);
                $this->assertSame($conversation->id, $context['conversation_id']);
                $this->assertSame($attacker->id, $context['user_id']);
                $this->assertStringNotContainsString($secret, json_encode($context));
                $this->assertStringNotContainsString($secret, $message);

                return true;
            });
    }

    public function test_send_with_foreign_conversation_id_does_not_grant_access(): void
    {
        Http::fake();
        $owner = $this->user();
        $attacker = $this->user();
        $foreign = ChatConversation::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($attacker)
            ->postJson(route('chat.send'), [
                'message' => 'Hola',
                'conversation_id' => $foreign->id,
            ])
            ->assertOk();

        // El turno no se escribió en la conversación ajena; se usó/creó la
        // propia del atacante.
        $this->assertNotSame($foreign->id, $response->json('conversation_id'));
        $this->assertDatabaseMissing('chat_messages', [
            'chat_conversation_id' => $foreign->id,
            'role' => 'user',
        ]);
    }
}
