<?php

namespace App\Services\Chat;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Professional;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Capa de herramientas de SOLO LECTURA del agente Mentea AI (CONTRATO-
 * CHATREADTOOLS.md). Cada método:
 *
 *   1. Es solo consulta: NUNCA llama a create/update/save/delete ni abre
 *      transacciones de escritura.
 *   2. Autoriza del lado servidor con Auth::user()/roles ANTES de devolver
 *      datos. El modelo no decide el alcance.
 *   3. Para datos personales usa SIEMPRE Auth::id()/el profesional autenticado;
 *      ignora cualquier identificador provisto por el modelo o el texto del
 *      usuario. Un id ajeno o "dame las citas del paciente X" devuelve vacío.
 *   4. No inventa: si no hay datos autorizados devuelve vacío y el agente lo
 *      comunica como "no disponible".
 *
 * Reutiliza únicamente las LECTURAS de los servicios de negocio existentes
 * (AvailabilityService) y los modelos. No duplica lógica transaccional.
 */
class ChatReadTools
{
    /** Tope de profesionales públicos devueltos por consulta. */
    public const MAX_PROFESSIONALS = 20;

    public function __construct(
        private AvailabilityService $availability,
        private KnowledgeBase $knowledge,
        private ChatLinks $links,
    ) {}

    // ---- Comunes (datos públicos) ----------------------------------------

    /**
     * Catálogo de los servicios configurados. Público.
     *
     * @return array<int,string>
     */
    public function listServices(): array
    {
        return array_values((array) config('chat.services', []));
    }

    /**
     * Profesionales habilitados (datos PÚBLICOS: nombre y profesión), opcional-
     * mente filtrados por servicio. Nunca expone datos sensibles (email, etc.).
     *
     * @return array<int,array{name:string,profession:string}>
     */
    public function listProfessionals(?string $service = null): array
    {
        $needle = $service !== null ? $this->normalize($service) : null;

        return Professional::query()
            ->where('is_available', true)
            ->orderBy('name')
            ->get()
            ->filter(function (Professional $p) use ($needle) {
                if ($needle === null || $needle === '') {
                    return true;
                }

                $profession = $this->normalize((string) $p->profession);
                $specialty = $this->normalize((string) $p->specialty);

                return str_contains($profession, $needle)
                    || str_contains($needle, $profession)
                    || str_contains($specialty, $needle)
                    || str_contains($needle, $specialty);
            })
            ->take(self::MAX_PROFESSIONALS)
            ->map(fn (Professional $p) => [
                'name' => (string) $p->name,
                'profession' => (string) $p->profession,
            ])
            ->values()
            ->all();
    }

    /**
     * Fechas y horarios reales disponibles para un servicio (y opcionalmente un
     * profesional). Solo lectura vía AvailabilityService. Público.
     *
     * @return array<int,array{professional:string,date:string,times:array<int,string>}>
     */
    public function getAvailability(string $service, ?string $professionalName = null, int $daysAhead = 45): array
    {
        $needleProf = $professionalName !== null ? $this->normalize($professionalName) : null;
        $needleService = $this->normalize($service);

        $professionals = Professional::query()
            ->where('is_available', true)
            ->get()
            ->filter(function (Professional $p) use ($needleService) {
                $profession = $this->normalize((string) $p->profession);
                $specialty = $this->normalize((string) $p->specialty);

                return str_contains($profession, $needleService)
                    || str_contains($needleService, $profession)
                    || str_contains($specialty, $needleService)
                    || str_contains($needleService, $specialty);
            })
            ->when($needleProf !== null && $needleProf !== '', function ($collection) use ($needleProf) {
                return $collection->filter(
                    fn (Professional $p) => str_contains($this->normalize((string) $p->name), $needleProf)
                );
            });

        $result = [];

        foreach ($professionals as $professional) {
            foreach ($this->availability->getAvailableDates($professional, $daysAhead) as $date) {
                $times = $this->availability->getAvailableSlots($professional, Carbon::parse($date))
                    ->values()
                    ->all();

                if ($times === []) {
                    continue;
                }

                $result[] = [
                    'professional' => (string) $professional->name,
                    'date' => $date,
                    'times' => $times,
                ];
            }
        }

        return $result;
    }

    /**
     * Fragmentos de la base de conocimiento relevantes a la consulta (RAG).
     * Público. Nunca devuelve el CSV completo.
     *
     * @return array<int,array<string,string>>
     */
    public function getKnowledge(string $query): array
    {
        return $this->knowledge->retrieve($query);
    }

    /**
     * Orientación de uso de un módulo: pasos + enlace interno verificado para el
     * rol. Devuelve ['text' => ..., 'url' => ?string]. El enlace solo se incluye
     * si la ruta existe y el rol la puede usar (ChatLinks).
     *
     * @return array{text:string,url:?string,route:?string}
     */
    public function getModuleHelp(string $topic, ?string $role = null): array
    {
        $role ??= $this->currentRole();
        $intent = $this->intentForTopic($topic, $role);

        $url = $intent !== null ? $this->links->urlFor($intent, $role) : null;
        $route = $intent !== null ? $this->links->routeNameFor($intent, $role) : null;

        $knowledge = $this->knowledge->retrieve($topic);
        $text = $knowledge !== [] ? $knowledge[0]['respuesta_oficial'] : '';

        return [
            'text' => $text,
            'url' => $url,
            'route' => $route,
        ];
    }

    // ---- Paciente (role:patient) — solo lo suyo --------------------------

    /**
     * Citas del paciente autenticado. Forzado a Auth::id(); un no-paciente o un
     * invitado obtiene vacío.
     *
     * @return array<int,array<string,mixed>>
     */
    public function myAppointments(): array
    {
        $user = $this->authenticatedPatient();

        if ($user === null) {
            return [];
        }

        return Appointment::query()
            ->where('patient_id', $user->id)
            ->orderBy('date')
            ->orderBy('time')
            ->get()
            ->map(fn (Appointment $a) => $this->presentAppointment($a))
            ->all();
    }

    /**
     * Detalle de UNA cita, filtrada SIEMPRE por patient_id = Auth::id(). Si el
     * id pertenece a otro paciente (o no existe), devuelve null: nunca filtra
     * datos ajenos.
     *
     * @return array<string,mixed>|null
     */
    public function myAppointment(int $id): ?array
    {
        $user = $this->authenticatedPatient();

        if ($user === null) {
            return null;
        }

        $appointment = Appointment::query()
            ->where('id', $id)
            ->where('patient_id', $user->id)
            ->first();

        return $appointment !== null ? $this->presentAppointment($appointment) : null;
    }

    /**
     * Entradas en lista de espera del paciente autenticado (forzado a Auth::id()).
     *
     * @return array<int,array<string,mixed>>
     */
    public function myWaitlist(): array
    {
        $user = $this->authenticatedPatient();

        if ($user === null) {
            return [];
        }

        return WaitlistEntry::query()
            ->where('patient_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WaitlistEntry $e) => [
                'id' => $e->id,
                'specialty' => (string) $e->specialty,
                'status' => $e->status?->value,
                'professional' => $e->professional?->name,
            ])
            ->all();
    }

    /**
     * Próxima cita futura del paciente autenticado (pendiente o confirmada).
     *
     * @return array<string,mixed>|null
     */
    public function myNextAppointment(): ?array
    {
        $user = $this->authenticatedPatient();

        if ($user === null) {
            return null;
        }

        $appointment = Appointment::query()
            ->where('patient_id', $user->id)
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
            ->whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->orderBy('time')
            ->first();

        return $appointment !== null ? $this->presentAppointment($appointment) : null;
    }

    // ---- Profesional (role:professional) — su agenda ---------------------

    /**
     * Agenda del profesional autenticado. Forzado a professional.user_id ==
     * Auth::id(); nunca agendas ajenas. Un no-profesional obtiene vacío.
     *
     * @return array<int,array<string,mixed>>
     */
    public function myAgenda(?string $from = null, ?string $to = null): array
    {
        $professional = $this->authenticatedProfessional();

        if ($professional === null) {
            return [];
        }

        $query = Appointment::query()
            ->where('professional_id', $professional->id)
            ->orderBy('date')
            ->orderBy('time');

        if ($from !== null) {
            $query->whereDate('date', '>=', Carbon::parse($from)->toDateString());
        }

        if ($to !== null) {
            $query->whereDate('date', '<=', Carbon::parse($to)->toDateString());
        }

        return $query->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'date' => $a->date?->format('Y-m-d'),
                'time' => $a->time?->format('H:i'),
                'status' => $a->status?->value,
                'modality' => $a->modality?->value,
            ])
            ->all();
    }

    /**
     * Horarios configurados del profesional autenticado (lectura).
     *
     * @return array<int,array{day_of_week:int,start_time:string,end_time:string}>
     */
    public function myScheduleInfo(): array
    {
        $professional = $this->authenticatedProfessional();

        if ($professional === null) {
            return [];
        }

        return $professional->activeSchedules()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->map(fn ($s) => [
                'day_of_week' => (int) $s->day_of_week,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
            ])
            ->all();
    }

    // ---- Admin / super_admin — según permisos ----------------------------

    /**
     * Indicadores institucionales AGREGADOS (sin datos personales sensibles),
     * gated por rol admin/super_admin. Un rol no autorizado obtiene vacío.
     *
     * @return array<string,int>
     */
    public function adminStats(): array
    {
        if (! $this->isAdmin()) {
            return [];
        }

        return [
            'appointments' => Appointment::query()->count(),
            'professionals' => Professional::query()->where('is_available', true)->count(),
            'waitlist' => WaitlistEntry::query()->count(),
        ];
    }

    /**
     * Orientación de módulos administrativos. Gated por rol admin/super_admin.
     *
     * @return array{text:string,url:?string,route:?string}
     */
    public function adminModuleHelp(string $module): array
    {
        if (! $this->isAdmin()) {
            return ['text' => '', 'url' => null, 'route' => null];
        }

        return $this->getModuleHelp($module, $this->currentRole());
    }

    // ---- Autorización (identidad = Laravel, nunca el modelo) --------------

    private function authenticatedPatient(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->isPatient() ? $user : null;
    }

    private function authenticatedProfessional(): ?Professional
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isProfessional()) {
            return null;
        }

        return Professional::query()->where('user_id', $user->id)->first();
    }

    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isStaffAdmin();
    }

    private function currentRole(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return match (true) {
            $user->isSuperAdmin() => 'super_admin',
            $user->isAdmin() => 'admin',
            $user->isProfessional() => 'professional',
            $user->isPatient() => 'patient',
            default => null,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function presentAppointment(Appointment $a): array
    {
        return [
            'id' => $a->id,
            'date' => $a->date?->format('Y-m-d'),
            'time' => $a->time?->format('H:i'),
            'status' => $a->status?->value,
            'modality' => $a->modality?->value,
            'professional' => $a->professional?->name,
        ];
    }

    /**
     * Mapea un tópico/intención en lenguaje natural a una clave de ChatLinks
     * según el rol. Devuelve null si no hay un enlace verificado aplicable.
     */
    private function intentForTopic(string $topic, ?string $role): ?string
    {
        $t = $this->normalize($topic);

        $patientMap = [
            'agendar_cita' => ['agend', 'reserv', 'nueva cita', 'pedir cita'],
            'ver_citas' => ['cancel', 'reprogram', 'reagend', 'mis citas', 'ver cita', 'mover'],
            'lista_espera' => ['lista de espera', 'espera', 'cupo'],
            'calendario_paciente' => ['calendario'],
            'perfil' => ['perfil', 'consentimiento', 'datos personales'],
        ];

        $professionalMap = [
            'mi_agenda' => ['agenda', 'mis citas', 'cita'],
            'calendario_profesional' => ['calendario'],
            'perfil' => ['perfil', 'datos personales'],
        ];

        $adminMap = [
            'citas_admin' => ['citas', 'agenda'],
            'lista_espera_admin' => ['lista de espera', 'espera'],
            'horarios_profesional_admin' => ['horarios'],
            'perfil' => ['perfil'],
        ];

        $superMap = [
            'configuracion' => ['configuracion', 'ajustes', 'settings'],
            'usuarios' => ['usuarios'],
            'roles' => ['roles', 'permisos'],
            'citas_admin' => ['citas'],
            'perfil' => ['perfil'],
        ];

        $map = match ($role) {
            'super_admin' => $superMap + $adminMap,
            'admin' => $adminMap,
            'professional' => $professionalMap,
            default => $patientMap,
        };

        foreach ($map as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($t, $this->normalize($keyword))) {
                    return $intent;
                }
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];

        return strtr($value, $map);
    }

    public function links(): ChatLinks
    {
        return $this->links;
    }
}
