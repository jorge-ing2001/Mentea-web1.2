<?php

namespace Tests\Feature\Chat;

use App\Enums\Role;
use App\Models\ChatConversation;
use App\Models\Professional;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Chat\ChatAgentService;
use App\Services\Chat\ConversationMemoryService;
use App\Services\Chat\ConversationSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobertura del refactor de Fase 3: ConversationContextBuilder (orden de 7
 * bloques + presupuesto de tokens) y ConversationSummaryService (resumen
 * progresivo con fallback determinista).
 *
 * Todo va por Http::fake(); nunca se llama a un proveedor en vivo. Se fuerza el
 * proveedor Ollama (forma message.content) igual que ChatOllamaAgentTest, para
 * ejercitar la ruta que se despliega por defecto. Datos ficticios.
 */
class ConversationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /**
     * Activa Ollama y falsea ping + chat con la forma de Ollama
     * (message.content). El manager resuelve AiProvider de forma perezosa
     * leyendo config('ai.provider'), así que basta fijar la config y olvidar
     * instancias previas.
     */
    private function fakeOllamaUp(string $chatContent): void
    {
        config([
            'ai.provider' => 'ollama',
            'ai.ollama.base_url' => 'http://localhost:11434',
            'ai.ollama.model' => 'qwen3:8b',
        ]);

        Http::clearResolvedInstances();
        $this->app->forgetInstance(Factory::class);
        $this->app->forgetInstance(ChatAgentService::class);

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => $chatContent],
            ], 200),
        ]);
    }

    private function agent(): ChatAgentService
    {
        return app(ChatAgentService::class);
    }

    private function seedProfessional(): Professional
    {
        $pro = Professional::create([
            'name' => 'Dra. Ficticia',
            'specialty' => 'Psicología Clínica',
            'profession' => 'Psicología',
            'email' => 'pro-context@test.com',
            'is_available' => true,
            'appointment_duration_minutes' => 45,
        ]);

        foreach (range(1, 7) as $day) {
            Schedule::create([
                'professional_id' => $pro->id,
                'day_of_week' => $day,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'is_active' => true,
            ]);
        }

        return $pro;
    }

    private function patient(): User
    {
        return User::factory()->create([
            'name' => 'Paciente Ficticio',
            'role' => Role::Patient,
            'is_active' => true,
            'consent_informed_at' => now(),
        ]);
    }

    private function conversationFor(User $user): ChatConversation
    {
        return ChatConversation::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    /**
     * Cuerpo JSON de la petición de REDACCIÓN a Ollama (la que incluye el
     * mensaje actual del usuario como último mensaje 'user'). Se distingue de
     * la petición de RESUMEN, que también va a /api/chat pero cuyo último
     * mensaje es la instrucción de resumir.
     *
     * @return array<string,mixed>
     */
    private function phrasingBody(string $currentUserMessage): array
    {
        $body = [];

        Http::recorded(function ($request) use (&$body, $currentUserMessage) {
            if (! str_ends_with($request->url(), '/api/chat')) {
                return false;
            }

            $messages = $request->data()['messages'] ?? [];
            $last = end($messages) ?: [];

            if (($last['role'] ?? '') === 'user' && ($last['content'] ?? '') === $currentUserMessage) {
                $body = $request->data();
            }

            return false;
        });

        return $body;
    }

    /**
     * El constructor de contexto arma los 7 bloques en el orden exacto de
     * §2.4: (1) prompt del agente, (2) identidad del usuario, (3) reglas de
     * seguridad, (4) resumen, (5) ventana reciente, (6) RAG, (7) instrucción
     * determinista + mensaje actual.
     */
    public function test_context_builder_assembles_seven_blocks_in_order(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        // Sembrar un resumen previo para que el bloque (4) aparezca.
        $conversation->forceFill(['summary' => 'Resumen ficticio de contexto previo.'])->save();

        $this->fakeOllamaUp('Con gusto te oriento sobre nuestros servicios.');

        $this->agent()->handle($conversation, '¿Qué servicios ofrecen?', $patient);

        $messages = $this->phrasingBody('¿Qué servicios ofrecen?')['messages'] ?? [];
        $this->assertNotEmpty($messages);

        // (1) Prompt del agente.
        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('Mentea AI', $messages[0]['content']);

        // (2) Identidad del usuario autenticado (no sensible).
        $this->assertStringContainsString('Usuario autenticado', $messages[1]['content']);
        $this->assertStringContainsString('rol: paciente', $messages[1]['content']);

        // (3) Reglas de seguridad.
        $this->assertStringContainsString('Reglas de seguridad y privacidad', $messages[2]['content']);

        // (4) Resumen de la conversación.
        $this->assertStringContainsString('Resumen de la conversación', $messages[3]['content']);
        $this->assertStringContainsString('Resumen ficticio de contexto previo.', $messages[3]['content']);

        // (7) El último mensaje es el del usuario; el anterior es la
        // instrucción determinista (cola conservada byte a byte).
        $last = $messages[count($messages) - 1];
        $penultimate = $messages[count($messages) - 2];
        $this->assertSame('user', $last['role']);
        $this->assertSame('¿Qué servicios ofrecen?', $last['content']);
        $this->assertSame('system', $penultimate['role']);
        $this->assertStringContainsString('usando EXACTAMENTE', $penultimate['content']);
    }

    /**
     * Las preferencias de interacción CONSENTIDAS y de la lista blanca se
     * inyectan como pista de redacción en el bloque de identidad (2), de forma
     * que la memoria de largo plazo (FEAT-005) queda realmente conectada al
     * contexto del modelo. Nunca cambia ninguna regla dura; es solo una pista.
     */
    public function test_consented_preferences_are_injected_into_the_identity_block(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        app(ConversationMemoryService::class)
            ->set($patient, 'response_length', 'breve', consented: true);

        $this->fakeOllamaUp('Con gusto, en breve.');

        $this->agent()->handle($conversation, '¿Qué servicios ofrecen?', $patient);

        $messages = $this->phrasingBody('¿Qué servicios ofrecen?')['messages'] ?? [];
        $this->assertNotEmpty($messages);

        // El bloque (2) de identidad incluye la pista de preferencias.
        $identity = $messages[1]['content'] ?? '';
        $this->assertStringContainsString('Preferencias de interacción del usuario', $identity);
        $this->assertStringContainsString('breve', $identity);
    }

    /**
     * Sin preferencias consentidas, el bloque de identidad NO menciona
     * preferencias: la memoria no fabrica datos.
     */
    public function test_identity_block_has_no_preference_hint_without_consent(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        $this->fakeOllamaUp('Con gusto te oriento.');

        $this->agent()->handle($conversation, '¿Qué servicios ofrecen?', $patient);

        $messages = $this->phrasingBody('¿Qué servicios ofrecen?')['messages'] ?? [];
        $identity = $messages[1]['content'] ?? '';
        $this->assertStringNotContainsString('Preferencias de interacción', $identity);
    }

    /**
     * Una conversación larga (> umbral) produce un resumen almacenado y el
     * payload enviado al modelo NO contiene todo el historial: se respeta el
     * presupuesto recortando la ventana reciente.
     */
    public function test_long_conversation_produces_summary_and_bounds_payload(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        config([
            'chat.context_window' => 4,
            'chat.summary_threshold_messages' => 6,
            'chat.summary_enabled' => true,
        ]);

        // Sembrar historial antiguo y voluminoso (marcador único para rastrear).
        foreach (range(1, 10) as $i) {
            $conversation->messages()->create([
                'role' => $i % 2 === 1 ? 'user' : 'assistant',
                'content' => "Mensaje antiguo numero {$i} MARCADOR_ANTIGUO con texto de relleno "
                    .str_repeat('relleno ', 40),
            ]);
        }

        $this->fakeOllamaUp('Resumen integrado de la conversación previa y respuesta actual.');

        $this->agent()->handle($conversation, '¿Y sobre el tema anterior?', $patient);

        // Se generó/actualizó el resumen en la conversación.
        $conversation->refresh();
        $this->assertNotNull($conversation->summary);
        $this->assertNotSame('', trim((string) $conversation->summary));

        // La petición de REDACCIÓN (no la de resumen) no reenvía todo el
        // historial: los marcadores más antiguos quedan fuera de la ventana.
        $body = $this->phrasingBody('¿Y sobre el tema anterior?');
        $serialized = json_encode($body['messages'] ?? []);
        $this->assertLessThanOrEqual(
            (int) config('chat.context_token_budget'),
            mb_strlen($serialized),
        );
        $this->assertStringNotContainsString('Mensaje antiguo numero 1 ', $serialized);
    }

    /**
     * Dos conversaciones independientes del mismo usuario no comparten
     * contexto: el resumen/ventana de una no se filtra en la otra.
     */
    public function test_switching_conversations_keeps_contexts_separate(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();

        $convA = $this->conversationFor($patient);
        $convA->forceFill(['summary' => 'SECRETO_DE_A: hablamos de psicología.'])->save();
        $convA->messages()->create(['role' => 'user', 'content' => 'Mensaje propio de A UNICO_A']);

        $convB = $this->conversationFor($patient);
        $convB->forceFill(['summary' => 'SECRETO_DE_B: hablamos de horarios.'])->save();

        $this->fakeOllamaUp('Respuesta para la conversación B.');

        $this->agent()->handle($convB, 'Hola de nuevo', $patient);

        $serialized = json_encode($this->phrasingBody('Hola de nuevo')['messages'] ?? []);
        $this->assertStringContainsString('SECRETO_DE_B', $serialized);
        $this->assertStringNotContainsString('SECRETO_DE_A', $serialized);
        $this->assertStringNotContainsString('UNICO_A', $serialized);
    }

    /**
     * Un seguimiento que refiere a un mensaje anterior es respondible desde la
     * ventana reciente: el referente viaja en el payload.
     */
    public function test_followup_carries_earlier_referent_in_window(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => '¿Dónde actualizo mi perfil REFERENTE_PERFIL?',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Puedes actualizar tu perfil en el módulo correspondiente.',
        ]);

        $this->fakeOllamaUp('Allí mismo encuentras esa opción.');

        $this->agent()->handle($conversation, '¿Y dónde encuentro esa opción?', $patient);

        $serialized = json_encode($this->phrasingBody('¿Y dónde encuentro esa opción?')['messages'] ?? []);
        $this->assertStringContainsString('REFERENTE_PERFIL', $serialized);
    }

    /**
     * Con el proveedor caído, la respuesta es exactamente la determinista y no
     * se intenta ningún resumen (ruta determinista intacta).
     */
    public function test_provider_unavailable_yields_deterministic_reply(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        // Proveedor caído: openrouter sin key.
        config(['services.openrouter.key' => null, 'ai.provider' => 'openrouter']);
        Http::clearResolvedInstances();
        $this->app->forgetInstance(Factory::class);
        $this->app->forgetInstance(ChatAgentService::class);
        Http::fake();

        $result = $this->agent()->handle($conversation, 'Hola', $patient);

        // La respuesta es la determinista (saludo configurado).
        $this->assertStringContainsString(
            'asistente de Mentea',
            $result['reply'],
        );

        // No hay resumen: no se tocó la columna.
        $conversation->refresh();
        $this->assertNull($conversation->summary);

        // No se envió ninguna petición de chat.
        Http::assertNothingSent();
    }

    /**
     * El resumen NO altera meta.pending del último mensaje del asistente: un
     * seguimiento de cupos sigue resolviéndose tras el refactor.
     */
    public function test_summary_preserves_pending_meta_on_last_assistant_message(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        config([
            'chat.context_window' => 4,
            'chat.summary_threshold_messages' => 6,
        ]);

        // Historial antiguo para disparar el resumen.
        foreach (range(1, 8) as $i) {
            $conversation->messages()->create([
                'role' => $i % 2 === 1 ? 'user' : 'assistant',
                'content' => "Turno previo {$i}",
            ]);
        }

        // Último assistant con meta.pending (oferta de cupos).
        $pending = [
            'type' => 'book',
            'service' => 'Psicología',
            'slots' => [
                ['professional_id' => 1, 'date' => '2030-01-10', 'time' => '09:00'],
            ],
        ];
        $last = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Te ofrezco estas opciones.',
            'meta' => ['reason' => 'slots_offered', 'pending' => $pending],
        ]);

        $this->fakeOllamaUp('Resumen generado.');

        // Llamar directamente al servicio de resumen no debe tocar el mensaje.
        app(ConversationSummaryService::class)->summarize($conversation);

        $last->refresh();
        $this->assertSame($pending, $last->meta['pending']);
        $this->assertNotNull($conversation->fresh()->summary);
    }
}
