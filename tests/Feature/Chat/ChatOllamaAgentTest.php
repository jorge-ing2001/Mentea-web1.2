<?php

namespace Tests\Feature\Chat;

use App\Enums\AppointmentStatus;
use App\Enums\Modality;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\ChatConversation;
use App\Models\Professional;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Chat\ChatAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobertura de la ruta por defecto (Ollama) a través de ChatAgentService y de
 * la rama de orientación con el proveedor ARRIBA.
 *
 * phpunit.xml fuerza AI_PROVIDER=openrouter para que la suite OpenAI-shaped
 * heredada siga verde sin editarla; por eso, aquí forzamos explícitamente
 * config('ai.provider') = 'ollama' y falseamos respuestas con forma Ollama
 * ({message:{content:...}}), ejercitando el camino que de verdad se despliega
 * por defecto. Ollama nunca se llama en vivo: todo va por Http::fake().
 */
class ChatOllamaAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /**
     * Activa el proveedor Ollama y falsea tanto el ping de disponibilidad
     * (GET /api/tags) como la respuesta de chat (POST /api/chat) con la FORMA
     * de Ollama (message.content). El binding de AiProvider se resuelve de forma
     * perezosa por el manager leyendo config('ai.provider'), así que basta con
     * fijar la config y olvidar instancias previas antes de resolver el agente.
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
            'name' => 'Dra. Prueba',
            'specialty' => 'Psicología Clínica',
            'profession' => 'Psicología',
            'email' => 'pro-ollama@test.com',
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
     * Concern #3: la ruta Ollama se ejercita END-TO-END a través del agente.
     * El agente debe usar message.content (no choices.0.message.content) y la
     * petición debe ir a /api/chat con {stream:false} y SIN Authorization.
     */
    public function test_agent_uses_ollama_provider_to_phrase_reply(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $this->fakeOllamaUp('Con gusto te cuento sobre nuestros servicios. ¿En qué más te ayudo?');

        $conversation = $this->conversationFor($patient);
        $result = $this->agent()->handle($conversation, 'Hola, ¿qué servicios ofrecen?', $patient);

        // La respuesta proviene de message.content del fake de Ollama.
        $this->assertStringContainsString('Con gusto te cuento sobre nuestros servicios', $result['reply']);

        // La petición de chat fue a /api/chat, con stream:false y sin token.
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/chat')
                && $request->method() === 'POST'
                && $request['stream'] === false
                && $request['model'] === 'qwen3:8b'
                && ! $request->hasHeader('Authorization');
        });
    }

    /**
     * Concern #3 + #2: con Ollama ARRIBA, una orientación ("¿cómo cancelo?")
     * pasa por la redacción del modelo PERO conserva el enlace interno
     * verificado y la aclaración de solo lectura. El modelo aquí "reformula"
     * omitiendo deliberadamente el enlace; el agente debe re-anclarlo.
     */
    public function test_provider_up_orientation_keeps_verified_link(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();

        // El modelo devuelve prosa fluida SIN el enlace ni la aclaración:
        // simula el riesgo de que phrase() deje caer la redirección.
        $this->fakeOllamaUp('Claro, puedo explicarte el proceso de cancelación con mucho gusto.');

        $conversation = $this->conversationFor($patient);
        $result = $this->agent()->handle($conversation, '¿Cómo cancelo mi cita?', $patient);

        // El enlace interno verificado SIEMPRE sobrevive a la redacción.
        $this->assertStringContainsString('/paciente/citas', $result['reply']);
        // La aclaración de solo lectura también se re-ancla.
        $this->assertStringContainsString('tú', mb_strtolower($result['reply']));
        // Sigue siendo de solo lectura: cero cambios en BD.
        $this->assertDatabaseCount('appointments', 0);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/chat'));
    }

    /**
     * Concern #2: aun cuando el modelo CONSERVA el enlace, no se duplica; el
     * enlace aparece exactamente una vez.
     */
    public function test_provider_up_orientation_does_not_duplicate_link(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();

        $this->fakeOllamaUp('Para cancelar, ve a Mis citas. Aquí tienes el acceso directo: /paciente/citas. '
            .'Recuerda que la acción debes realizarla tú desde ese módulo; yo solo te oriento.');

        $conversation = $this->conversationFor($patient);
        $result = $this->agent()->handle($conversation, '¿Cómo cancelo mi cita?', $patient);

        $this->assertSame(1, substr_count($result['reply'], '/paciente/citas'));
    }

    /**
     * Concern #1: con el perfil de SOLO LECTURA activo
     * (chat.agent_transactional_enabled = false), un comando transaccional
     * directo ("Quiero cancelar mi cita") NO ejecuta; se orienta y se enlaza.
     */
    public function test_read_only_profile_routes_direct_command_to_orientation(): void
    {
        config(['chat.agent_transactional_enabled' => false]);

        $pro = $this->seedProfessional();
        $patient = $this->patient();

        // Con proveedor caído: respuesta determinista (más fácil de asertar).
        config(['services.openrouter.key' => null, 'ai.provider' => 'openrouter']);
        Http::fake();

        // Una cita cancelable que, con el perfil transaccional, se cancelaría.
        $appointment = Appointment::create([
            'patient_id' => $patient->id,
            'professional_id' => $pro->id,
            'date' => now()->addDays(10)->toDateString(),
            'time' => '09:00',
            'status' => AppointmentStatus::Confirmed->value,
            'modality' => Modality::InPerson->value,
        ]);

        $conversation = $this->conversationFor($patient);
        $result = $this->agent()->handle($conversation, 'Quiero cancelar mi cita', $patient);

        // No ejecuta: la cita sigue confirmada y se orienta con enlace.
        $this->assertSame(
            AppointmentStatus::Confirmed->value,
            $appointment->fresh()->status->value
        );
        $this->assertStringContainsString('/paciente/citas', $result['reply']);
    }

    /**
     * Control (regresión): con el perfil transaccional POR DEFECTO (true), el
     * comando directo sigue cancelando, como en la suite heredada.
     */
    public function test_default_profile_keeps_transactional_cancel(): void
    {
        $pro = $this->seedProfessional();
        $patient = $this->patient();

        $appointment = Appointment::create([
            'patient_id' => $patient->id,
            'professional_id' => $pro->id,
            'date' => now()->addDays(10)->toDateString(),
            'time' => '09:00',
            'status' => AppointmentStatus::Confirmed->value,
            'modality' => Modality::InPerson->value,
        ]);

        config(['services.openrouter.key' => null]);
        Http::fake();

        $conversation = $this->conversationFor($patient);
        $this->agent()->handle($conversation, 'Quiero cancelar mi cita', $patient);

        $this->assertSame(
            AppointmentStatus::Cancelled->value,
            $appointment->fresh()->status->value
        );
    }
}
