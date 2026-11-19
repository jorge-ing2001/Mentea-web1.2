<?php

namespace Tests\Feature\Chat;

use App\Enums\AppointmentStatus;
use App\Enums\Modality;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Professional;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Chat\ChatLinks;
use App\Services\Chat\ChatReadTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Matriz de seguridad del CONTRATO-CHATREADTOOLS.md §4. La identidad es
 * Laravel (Auth), nunca el modelo; ningún id libre del usuario accede a datos
 * ajenos; los enlaces son rutas reales; no hay inventiva.
 */
class ChatReadToolsSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // sin red real para el proveedor.
    }

    private function tools(): ChatReadTools
    {
        return app(ChatReadTools::class);
    }

    private function patient(bool $withConsent = true): User
    {
        $user = User::factory()->create([
            'role' => Role::Patient,
            'is_active' => true,
            'consent_informed_at' => $withConsent ? now() : null,
        ]);

        return $user;
    }

    private function professionalUser(string $email): array
    {
        $user = User::factory()->create([
            'role' => Role::Professional,
            'is_active' => true,
        ]);

        $professional = Professional::create([
            'user_id' => $user->id,
            'name' => 'Dra. '.ucfirst(explode('@', $email)[0]),
            'specialty' => 'Psicología Clínica',
            'profession' => 'Psicología',
            'email' => $email,
            'is_available' => true,
            'appointment_duration_minutes' => 45,
        ]);

        foreach (range(1, 7) as $day) {
            Schedule::create([
                'professional_id' => $professional->id,
                'day_of_week' => $day,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'is_active' => true,
            ]);
        }

        return [$user, $professional];
    }

    private function appointmentFor(User $patient, Professional $professional, string $date = '2099-01-05', string $time = '09:00'): Appointment
    {
        return Appointment::create([
            'patient_id' => $patient->id,
            'professional_id' => $professional->id,
            'date' => $date,
            'time' => $time,
            'modality' => Modality::InPerson,
            'status' => AppointmentStatus::Pending,
        ]);
    }

    // #4 — Paciente consulta sus citas: solo las de Auth::id().
    public function test_patient_sees_only_own_appointments(): void
    {
        [$proUser, $pro] = $this->professionalUser('pro-a@test.com');
        $patient = $this->patient();
        $other = $this->patient();

        $mine = $this->appointmentFor($patient, $pro, '2099-01-05');
        $theirs = $this->appointmentFor($other, $pro, '2099-01-06', '10:00');

        $this->actingAs($patient);
        $ids = collect($this->tools()->myAppointments())->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    // #5 — Paciente intenta ver cita de OTRO por id: vacío, nunca datos ajenos.
    public function test_patient_cannot_read_foreign_appointment_by_id(): void
    {
        [$proUser, $pro] = $this->professionalUser('pro-b@test.com');
        $patient = $this->patient();
        $other = $this->patient();

        $foreign = $this->appointmentFor($other, $pro);

        $this->actingAs($patient);

        // El id ajeno proviene "del usuario/modelo": debe devolver null.
        $this->assertNull($this->tools()->myAppointment($foreign->id));
    }

    // #6 — "dame las citas del paciente X": solo lo propio (identidad = Auth).
    public function test_identity_comes_from_auth_not_from_requested_id(): void
    {
        [$proUser, $pro] = $this->professionalUser('pro-c@test.com');
        $patient = $this->patient();
        $other = $this->patient();

        $this->appointmentFor($other, $pro);
        $mine = $this->appointmentFor($patient, $pro, '2099-02-02', '11:00');

        $this->actingAs($patient);

        // myAppointments ignora cualquier id; siempre Auth::id().
        $ids = collect($this->tools()->myAppointments())->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }

    // #7 — Profesional ve solo su agenda.
    public function test_professional_sees_only_own_agenda(): void
    {
        [$userA, $proA] = $this->professionalUser('pro-d@test.com');
        [$userB, $proB] = $this->professionalUser('pro-e@test.com');
        $patient = $this->patient();

        $mine = $this->appointmentFor($patient, $proA, '2099-03-01');
        $theirs = $this->appointmentFor($patient, $proB, '2099-03-02', '10:00');

        $this->actingAs($userA);
        $ids = collect($this->tools()->myAgenda())->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    // #8 — Profesional NO ve la agenda de otro profesional (no hay forma).
    public function test_professional_cannot_access_another_agenda(): void
    {
        [$userA, $proA] = $this->professionalUser('pro-f@test.com');
        [$userB, $proB] = $this->professionalUser('pro-g@test.com');
        $patient = $this->patient();

        $this->appointmentFor($patient, $proB, '2099-04-01');

        $this->actingAs($userA);
        // La agenda se fuerza al profesional autenticado (proA, sin citas).
        $this->assertSame([], $this->tools()->myAgenda());
    }

    // #9 — Rol gating: un paciente no obtiene datos de herramientas de admin.
    public function test_role_gated_tools_reject_unauthorized_roles(): void
    {
        $patient = $this->patient();
        $this->actingAs($patient);

        $this->assertSame([], $this->tools()->adminStats());

        // myAgenda es de profesional: un paciente obtiene vacío.
        $this->assertSame([], $this->tools()->myAgenda());

        // myAppointments es de paciente: un profesional obtiene vacío.
        [$proUser, $pro] = $this->professionalUser('pro-h@test.com');
        $this->actingAs($proUser);
        $this->assertSame([], $this->tools()->myAppointments());
    }

    public function test_admin_tools_available_to_admin(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'is_active' => true]);

        $this->actingAs($admin);
        $stats = $this->tools()->adminStats();

        $this->assertArrayHasKey('appointments', $stats);
        $this->assertArrayHasKey('professionals', $stats);
    }

    // #13 — No inventar: sin dato autorizado, vacío.
    public function test_no_invention_when_no_data(): void
    {
        $patient = $this->patient();
        $this->actingAs($patient);

        $this->assertSame([], $this->tools()->myAppointments());
        $this->assertNull($this->tools()->myNextAppointment());
        $this->assertSame([], $this->tools()->myWaitlist());
    }

    // Guest: ninguna consulta personal devuelve datos.
    public function test_guest_gets_no_personal_data(): void
    {
        $this->assertFalse(Auth::check());

        $this->assertSame([], $this->tools()->myAppointments());
        $this->assertNull($this->tools()->myNextAppointment());
        $this->assertSame([], $this->tools()->myAgenda());
        $this->assertSame([], $this->tools()->adminStats());
    }

    // Knowledge retrieval real + sin inventar.
    public function test_knowledge_returns_csv_grounded_fragments(): void
    {
        $patient = $this->patient();
        $this->actingAs($patient);

        $fragments = $this->tools()->getKnowledge('¿qué servicios ofrece Mentea?');
        $this->assertNotEmpty($fragments);

        $blob = collect($fragments)->pluck('respuesta_oficial')->implode(' ');
        $this->assertStringContainsString('Hospital Especializado Granja Integral', $blob);

        // Sin coincidencia: vacío.
        $this->assertSame([], $this->tools()->getKnowledge('receta de pizza napolitana'));
    }

    // #11 — Enlaces internos corresponden a rutas reales.
    public function test_links_map_to_real_named_routes(): void
    {
        $links = app(ChatLinks::class);

        foreach ($links->allRouteNames() as $name) {
            $this->assertTrue(Route::has($name), "La ruta {$name} no existe en routes/web.php");
        }
    }

    public function test_module_help_emits_role_appropriate_real_link(): void
    {
        $patient = $this->patient();
        $this->actingAs($patient);

        $help = $this->tools()->getModuleHelp('ver mis citas', 'patient');
        $this->assertNotNull($help['route']);
        $this->assertTrue(Route::has($help['route']));
        $this->assertStringContainsString('/paciente/citas', (string) $help['url']);

        // Un enlace de super_admin no se entrega a un paciente.
        $links = app(ChatLinks::class);
        $this->assertNull($links->urlFor('configuracion', 'patient'));
    }

    // #12 — Evasión / prompt injection no altera los controles PHP.
    public function test_prompt_injection_does_not_bypass_php_controls(): void
    {
        [$proUser, $pro] = $this->professionalUser('pro-i@test.com');
        $patient = $this->patient();
        $other = $this->patient();
        $foreign = $this->appointmentFor($other, $pro);

        $this->actingAs($patient);

        // Aunque el "modelo" pidiera un id ajeno, la herramienta lo ignora.
        $this->assertNull($this->tools()->myAppointment($foreign->id));
        $this->assertSame([], $this->tools()->myAppointments());

        // Y la BD no cambia (solo lectura).
        $this->assertDatabaseHas('appointments', [
            'id' => $foreign->id,
            'patient_id' => $other->id,
        ]);
    }
}
