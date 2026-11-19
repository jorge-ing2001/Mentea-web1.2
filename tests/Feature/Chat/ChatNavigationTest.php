<?php

namespace Tests\Feature\Chat;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Semántica de BACKEND de la que depende la continuidad de navegación del
 * widget persistente (Etapas 1 y 2 de FEAT-004): cambio de módulo, minimizar,
 * refresco y re-login. El widget hidrata su historial desde estos endpoints
 * (la BD es la fuente de verdad); aquí validamos ese contrato sin navegador.
 */
class ChatNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): User
    {
        return User::factory()->create([
            'role' => Role::Patient,
            'is_active' => true,
        ]);
    }

    public function test_after_sending_index_returns_the_active_conversation_and_show_returns_its_messages_in_order(): void
    {
        Http::fake();
        $patient = $this->patient();

        // Primer turno: crea la conversación activa y fija el conversation_id
        // que el widget guardará en localStorage.
        $first = $this->actingAs($patient)
            ->postJson(route('chat.send'), [
                'message' => 'Primer mensaje',
                'client_message_id' => 'cmid-nav-1',
            ])
            ->assertOk();

        $conversationId = $first->json('conversation_id');
        $this->assertNotNull($conversationId);

        // Segundo turno en la MISMA conversación (continuidad tras navegar).
        $this->actingAs($patient)
            ->postJson(route('chat.send'), [
                'message' => 'Segundo mensaje',
                'conversation_id' => $conversationId,
                'client_message_id' => 'cmid-nav-2',
            ])
            ->assertOk();

        // index: la conversación activa aparece para el usuario (lo que el
        // widget lista/usa como puntero tras un refresco o re-login).
        $index = $this->actingAs($patient)
            ->getJson(route('chat.conversations.index'))
            ->assertOk();

        $indexIds = collect($index->json('data'))->pluck('id')->all();
        $this->assertContains($conversationId, $indexIds);

        // show: el historial completo en orden cronológico (hidratación).
        $show = $this->actingAs($patient)
            ->getJson(route('chat.conversations.show', $conversationId))
            ->assertOk();

        $roles = collect($show->json('data.messages'))->pluck('role')->all();
        $contents = collect($show->json('data.messages'))->pluck('content')->all();

        $this->assertSame('user', $roles[0]);
        $this->assertSame('Primer mensaje', $contents[0]);
        $this->assertContains('Segundo mensaje', $contents);
        // El orden es monotónico por id (lo que el widget pinta de arriba abajo).
        $ids = collect($show->json('data.messages'))->pluck('id')->all();
        $sorted = collect($ids)->sort()->values()->all();
        $this->assertSame($sorted, $ids);
    }

    public function test_reauthenticated_user_still_sees_prior_conversations(): void
    {
        Http::fake();
        $patient = $this->patient();

        $send = $this->actingAs($patient)
            ->postJson(route('chat.send'), [
                'message' => 'Hola antes de cerrar sesión',
                'client_message_id' => 'cmid-relogin-1',
            ])
            ->assertOk();

        $conversationId = $send->json('conversation_id');

        // Simula re-login: una nueva sesión autenticada para el mismo usuario.
        $this->app['auth']->forgetGuards();

        $index = $this->actingAs($patient->fresh())
            ->getJson(route('chat.conversations.index'))
            ->assertOk();

        $ids = collect($index->json('data'))->pluck('id')->all();
        $this->assertContains($conversationId, $ids, 'El historial debe sobrevivir al re-login.');

        $show = $this->actingAs($patient->fresh())
            ->getJson(route('chat.conversations.show', $conversationId))
            ->assertOk();

        $this->assertNotEmpty($show->json('data.messages'));
    }

    public function test_show_of_another_users_conversation_is_not_accessible(): void
    {
        Http::fake();
        $owner = $this->patient();
        $intruder = $this->patient();

        $send = $this->actingAs($owner)
            ->postJson(route('chat.send'), ['message' => 'conversación privada'])
            ->assertOk();

        $conversationId = $send->json('conversation_id');

        // El puntero de localStorage de un atacante no concede acceso: 404.
        $this->actingAs($intruder)
            ->getJson(route('chat.conversations.show', $conversationId))
            ->assertNotFound();
    }

    public function test_widget_partial_renders_markers_and_csrf_for_guests(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('mentea-chat-widget', false);
        $response->assertSee('menteaChat()', false);
        $response->assertSee('name="csrf-token"', false);
        // Etapa 2: el flujo de envío incluye client_message_id en el payload.
        $response->assertSee('client_message_id', false);
    }
}
