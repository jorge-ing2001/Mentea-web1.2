<?php

namespace Tests\Feature\Chat;

use App\Enums\Role;
use App\Models\ChatConversation;
use App\Models\Professional;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Chat\ChatAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Compatibilidad de proveedores con la memoria persistente (Fase 6).
 *
 * Verifica, END-TO-END a través de ChatAgentService y el nuevo constructor de
 * contexto provider-independiente, que:
 *
 *   (a) la ruta por defecto (Ollama) funciona vía Http::fake() con la forma
 *       {message:{content}} y publica en POST {base}/api/chat;
 *   (b) OpenRouter sigue conservado y seleccionable (config('ai.provider')
 *       = 'openrouter', forma OpenAI choices[].message.content en
 *       /chat/completions), exactamente como lo fija phpunit.xml;
 *   (c) con el proveedor NO disponible, la ruta determinista de seguridad
 *       (crisis / consentimiento / escalado) sigue gobernando sin tocar el
 *       modelo;
 *   (d) un timeout / HTTP 500 / error de conexión del proveedor se recupera
 *       con gracia: el agente devuelve el texto determinista y NUNCA lanza.
 *
 * Todo el tráfico se simula con Http::fake(); nunca se llama a un proveedor en
 * vivo. Datos ficticios (requisito §11). Las rutas Ollama y
 * provider-unavailable-determinista ya tienen cobertura propia en
 * ChatOllamaAgentTest / ConversationContextTest; aquí se cubre lo que de verdad
 * faltaba: la ruta OpenRouter a través del agente + el resumen de contexto, la
 * supervivencia de crisis/consentimiento/escalado con el proveedor caído, y la
 * recuperación a nivel de handle() ante 500 / timeout / error de conexión.
 */
class MemoryCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /**
     * Fija el proveedor Ollama y falsea ping (GET /api/tags) + chat
     * (POST /api/chat) con la FORMA de Ollama. El manager resuelve AiProvider
     * de forma perezosa leyendo config('ai.provider').
     */
    private function fakeOllamaUp(string $chatContent): void
    {
        config([
            'ai.provider' => 'ollama',
            'ai.ollama.base_url' => 'http://localhost:11434',
            'ai.ollama.model' => 'qwen3:8b',
        ]);

        $this->forgetResolved();

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => $chatContent],
            ], 200),
        ]);
    }

    /**
     * Fija el proveedor OpenRouter (como phpunit.xml) con key presente y
     * falsea /chat/completions con la FORMA OpenAI (choices[].message.content).
     */
    private function fakeOpenRouterUp(string $chatContent): void
    {
        config([
            'ai.provider' => 'openrouter',
            'ai.openrouter.key' => 'sk-test-123',
            'services.openrouter.key' => 'sk-test-123',
            'ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'ai.openrouter.model' => 'openai/gpt-4o-mini',
        ]);

        $this->forgetResolved();

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $chatContent]]],
            ], 200),
        ]);
    }

    /**
     * Deja al proveedor NO disponible: OpenRouter sin key (isAvailable()=false)
     * y sin red falseada que responda; el agente debe usar el determinista.
     */
    private function providerUnavailable(): void
    {
        config([
            'ai.provider' => 'openrouter',
            'ai.openrouter.key' => null,
            'services.openrouter.key' => null,
            // El clasificador de crisis asistido por el modelo también queda
            // fuera: sin proveedor no hay llamada, pero lo desactivamos para
            // dejar explícito que las capas deterministas gobiernan solas.
            'chat.crisis_classifier_enabled' => false,
        ]);

        $this->forgetResolved();
        Http::fake();
    }

    private function forgetResolved(): void
    {
        Http::clearResolvedInstances();
        $this->app->forgetInstance(Factory::class);
        $this->app->forgetInstance(ChatAgentService::class);
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
            'email' => 'pro-compat@test.com',
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
     * (a) La ruta por defecto (Ollama) redacta a través del agente usando el
     * nuevo constructor de contexto: el payload incluye el bloque de resumen
     * sembrado y publica en /api/chat con {stream:false} y sin Authorization.
     */
    public function test_ollama_path_phrases_through_context_builder(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);
        $conversation->forceFill(['summary' => 'Resumen ficticio previo MARCADOR_RESUMEN.'])->save();

        $this->fakeOllamaUp('Con gusto te oriento sobre nuestros servicios de salud mental.');

        $result = $this->agent()->handle($conversation, '¿Qué servicios ofrecen?', $patient);

        $this->assertStringContainsString('Con gusto te oriento', $result['reply']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/chat') || $request->method() !== 'POST') {
                return false;
            }

            return $request['stream'] === false
                && $request['model'] === 'qwen3:8b'
                && ! $request->hasHeader('Authorization')
                && str_contains(json_encode($request['messages']), 'MARCADOR_RESUMEN');
        });
    }

    /**
     * (b) OpenRouter sigue conservado y seleccionable: con el proveedor fijado
     * a 'openrouter' (como en phpunit.xml) el agente redacta leyendo
     * choices[].message.content y publica en /chat/completions con
     * Authorization: Bearer. El contexto se arma igual (resumen incluido).
     */
    public function test_openrouter_remains_conserved_and_selectable(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);
        $conversation->forceFill(['summary' => 'Contexto previo MARCADOR_OR.'])->save();

        $this->fakeOpenRouterUp('Claro, te cuento sobre los servicios disponibles.');

        $result = $this->agent()->handle($conversation, '¿Qué servicios ofrecen?', $patient);

        $this->assertStringContainsString('Claro, te cuento', $result['reply']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/chat/completions') || $request->method() !== 'POST') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer sk-test-123')
                && $request['model'] === 'openai/gpt-4o-mini'
                && str_contains(json_encode($request['messages']), 'MARCADOR_OR');
        });

        // No se tocó la ruta de Ollama.
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/chat'));
    }

    /**
     * (c) Con el proveedor NO disponible, la REGLA DURA de crisis gobierna: se
     * entrega el texto de crisis + líneas, se marca escalado y no se consulta
     * ningún proveedor.
     */
    public function test_crisis_safety_path_survives_without_provider(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        $this->providerUnavailable();

        $result = $this->agent()->handle($conversation, 'ya no quiero vivir, quiero quitarme la vida', $patient);

        $this->assertTrue($result['crisis']);
        $this->assertTrue($result['escalated']);
        // El copy de crisis trae las líneas de emergencia y de crisis.
        $this->assertStringContainsString((string) config('chat.emergency_line'), $result['reply']);
        $this->assertStringContainsString((string) config('chat.crisis_line'), $result['reply']);
        $this->assertNotNull($conversation->fresh()->crisis_flagged_at);

        Http::assertNothingSent();
    }

    /**
     * (c) Con el proveedor NO disponible, la puerta de CONSENTIMIENTO sigue
     * bloqueando el agendamiento de un paciente sin consentimiento.
     */
    public function test_consent_gate_survives_without_provider(): void
    {
        $this->seedProfessional();
        $patient = User::factory()->create([
            'role' => Role::Patient,
            'is_active' => true,
            'consent_informed_at' => null,
        ]);
        $conversation = $this->conversationFor($patient);

        $this->providerUnavailable();

        $result = $this->agent()->handle($conversation, 'Quiero agendar una cita de psicología', $patient);

        $this->assertTrue($result['requires_consent']);
        $this->assertStringContainsString('consentimiento', mb_strtolower($result['reply']));
        Http::assertNothingSent();
    }

    /**
     * (c) Con el proveedor NO disponible, el ESCALADO a humano (pregunta
     * clínica) sigue gobernando.
     */
    public function test_escalation_path_survives_without_provider(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        $this->providerUnavailable();

        $result = $this->agent()->handle($conversation, '¿Qué medicamento me tomo para la ansiedad?', $patient);

        $this->assertTrue($result['escalated']);
        $this->assertNotNull($conversation->fresh()->escalated_at);
        Http::assertNothingSent();
    }

    /**
     * (d) Un HTTP 500 del proveedor en la redacción se recupera con gracia: el
     * agente devuelve el texto determinista y no propaga un 500.
     */
    public function test_provider_http_500_recovers_with_deterministic_reply(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        config([
            'ai.provider' => 'ollama',
            'ai.ollama.base_url' => 'http://localhost:11434',
            'ai.ollama.model' => 'qwen3:8b',
        ]);
        $this->forgetResolved();

        // Disponible (ping OK) pero el chat devuelve 500.
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/chat' => Http::response('boom', 500),
        ]);

        $result = $this->agent()->handle($conversation, 'Hola, ¿qué servicios tienen?', $patient);

        // Hay respuesta (no excepción, no 500) y es la determinista.
        $this->assertNotSame('', trim($result['reply']));
        $this->assertSame($conversation->id, $result['conversation_id']);
    }

    /**
     * (d) Un error de conexión / timeout del proveedor en la redacción también
     * se recupera: el agente devuelve el determinista y nunca lanza.
     */
    public function test_provider_connection_error_recovers_with_deterministic_reply(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        config([
            'ai.provider' => 'ollama',
            'ai.ollama.base_url' => 'http://localhost:11434',
            'ai.ollama.model' => 'qwen3:8b',
        ]);
        $this->forgetResolved();

        // Ping disponible, pero el POST de chat lanza un error de conexión.
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/chat' => function () {
                throw new ConnectionException('timeout');
            },
        ]);

        $result = $this->agent()->handle($conversation, '¿Qué servicios ofrecen?', $patient);

        $this->assertNotSame('', trim($result['reply']));
        $this->assertSame($conversation->id, $result['conversation_id']);
    }
}
