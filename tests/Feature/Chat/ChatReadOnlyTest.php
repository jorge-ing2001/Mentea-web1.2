<?php

namespace Tests\Feature\Chat;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\ChatConversation;
use App\Models\Professional;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Chat\ChatAgentService;
use App\Services\Chat\ChatReadTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * Garantías de SOLO LECTURA (CONTRATO §4, filas 1-3 y 14): ChatReadTools no
 * expone ninguna ruta de escritura; una orientación no crea filas y entrega un
 * enlace; el protocolo de crisis opera con el proveedor caído.
 */
class ChatReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
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
            'email' => 'pro-readonly@test.com',
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
        $user = User::factory()->create([
            'role' => Role::Patient,
            'is_active' => true,
            'consent_informed_at' => now(),
        ]);

        return $user;
    }

    private function conversationFor(User $user): ChatConversation
    {
        return ChatConversation::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    /**
     * Reflexión estática: ningún método de ChatReadTools llama a una operación
     * de escritura de Eloquent (create/update/save/delete/forceFill/insert) ni
     * abre transacciones. La única capacidad del agente es consultar.
     */
    public function test_read_tools_source_has_no_write_calls(): void
    {
        $reflection = new ReflectionClass(ChatReadTools::class);
        $source = file_get_contents($reflection->getFileName());

        $forbidden = [
            '->save(', '->update(', '->delete(', '->create(', '->forceFill(',
            '->insert(', '->firstOrCreate(', '->updateOrCreate(', '->restore(',
            'DB::transaction', '->increment(', '->decrement(',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "ChatReadTools no debe contener la llamada de escritura: {$needle}"
            );
        }
    }

    /**
     * Behavioral: una orientación de "cómo cancelar" no crea ni modifica filas;
     * responde con pasos + enlace interno real.
     */
    public function test_orientation_turn_writes_nothing_and_returns_link(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        $before = Appointment::count();
        $beforeWaitlist = WaitlistEntry::count();

        $result = $this->agent()->handle($conversation, '¿Cómo cancelo mi cita?', $patient);

        $this->assertSame($before, Appointment::count(), 'La orientación no debe crear/cancelar citas');
        $this->assertSame($beforeWaitlist, WaitlistEntry::count());

        // Entrega el enlace interno verificado a "Mis citas".
        $this->assertStringContainsString('/paciente/citas', $result['reply']);
        $this->assertStringContainsString('tú', mb_strtolower($result['reply'])); // el usuario ejecuta
    }

    public function test_orientation_how_to_schedule_returns_create_link(): void
    {
        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        $result = $this->agent()->handle($conversation, '¿Dónde agendo una cita nueva?', $patient);

        $this->assertStringContainsString('/paciente/citas/agendar', $result['reply']);
        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * #14 — Crisis con el proveedor caído: el protocolo determinista opera sin
     * Ollama/OpenRouter y no se hace ninguna llamada de red.
     */
    public function test_crisis_protocol_works_with_provider_down(): void
    {
        config(['services.openrouter.key' => null]);
        Http::fake();

        $this->seedProfessional();
        $patient = $this->patient();
        $conversation = $this->conversationFor($patient);

        $result = $this->agent()->handle($conversation, 'Ya no quiero vivir, quiero quitarme la vida', $patient);

        $this->assertTrue($result['crisis']);
        $this->assertTrue($result['escalated']);
        $this->assertStringContainsString('123', $result['reply']);
        $this->assertStringContainsString((string) config('chat.crisis_line'), $result['reply']);
        $this->assertDatabaseCount('appointments', 0);
        Http::assertNothingSent();
    }
}
