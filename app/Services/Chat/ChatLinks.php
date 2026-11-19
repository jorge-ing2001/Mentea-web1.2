<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Route;

/**
 * Enlaces internos VERIFICADOS para la redirección del agente de solo lectura.
 *
 * Cada intención se mapea a un NOMBRE de ruta real de routes/web.php (ver
 * CONTRATO-CHATREADTOOLS.md §3). El agente nunca inventa URLs: si la ruta no
 * existe (Route::has falsa) o el rol no corresponde, el enlace se omite.
 *
 * Las rutas con parámetros ({appointment}, {professional}) no se resuelven aquí
 * salvo que se provea el id; se exponen por nombre para que el llamador decida.
 */
class ChatLinks
{
    /**
     * Catálogo intención -> ['route' => nombre, 'roles' => [...], 'needs' => bool].
     * 'needs' indica que la ruta requiere un parámetro para resolver la URL.
     *
     * @var array<string,array{route:string,roles:array<int,string>,needs?:string}>
     */
    private const MAP = [
        'ver_citas' => ['route' => 'patient.appointments.index', 'roles' => ['patient']],
        'agendar_cita' => ['route' => 'patient.appointments.create', 'roles' => ['patient']],
        'ver_cita' => ['route' => 'patient.appointments.show', 'roles' => ['patient'], 'needs' => 'appointment'],
        'lista_espera' => ['route' => 'patient.waitlist.index', 'roles' => ['patient']],
        'calendario_paciente' => ['route' => 'patient.calendar.index', 'roles' => ['patient']],
        'perfil' => ['route' => 'profile.edit', 'roles' => ['patient', 'professional', 'admin', 'super_admin']],
        'mi_agenda' => ['route' => 'professional.appointments.index', 'roles' => ['professional']],
        'calendario_profesional' => ['route' => 'professional.calendar.index', 'roles' => ['professional']],
        'citas_admin' => ['route' => 'admin.appointments.index', 'roles' => ['admin', 'super_admin']],
        'horarios_profesional_admin' => ['route' => 'admin.schedules.edit', 'roles' => ['admin', 'super_admin'], 'needs' => 'professional'],
        'lista_espera_admin' => ['route' => 'admin.waitlist.index', 'roles' => ['admin', 'super_admin']],
        'configuracion' => ['route' => 'super.settings.index', 'roles' => ['super_admin']],
        'usuarios' => ['route' => 'super.users.index', 'roles' => ['super_admin']],
        'roles' => ['route' => 'super.roles.index', 'roles' => ['super_admin']],
    ];

    /**
     * Resuelve la URL de una intención si (1) la ruta existe realmente y (2) el
     * rol dado está autorizado para ella. Devuelve null en caso contrario
     * (jamás inventa una URL).
     *
     * @param  array<string,mixed>  $parameters  parámetros para rutas con {id}
     */
    public function urlFor(string $intent, ?string $role = null, array $parameters = []): ?string
    {
        $entry = self::MAP[$intent] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($role !== null && ! in_array($role, $entry['roles'], true)) {
            return null;
        }

        $name = $entry['route'];

        if (! Route::has($name)) {
            return null;
        }

        if (isset($entry['needs']) && ! array_key_exists($entry['needs'], $parameters)) {
            // Ruta con parámetro obligatorio sin valor: no se puede construir.
            return null;
        }

        try {
            return route($name, $parameters);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Nombre de ruta mapeado a una intención (o null si no existe o el rol no
     * aplica). Útil para aserciones con Route::has en pruebas.
     */
    public function routeNameFor(string $intent, ?string $role = null): ?string
    {
        $entry = self::MAP[$intent] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($role !== null && ! in_array($role, $entry['roles'], true)) {
            return null;
        }

        return Route::has($entry['route']) ? $entry['route'] : null;
    }

    /**
     * Todos los nombres de ruta usados por el mapa (para verificar que existen).
     *
     * @return array<int,string>
     */
    public function allRouteNames(): array
    {
        return collect(self::MAP)->pluck('route')->unique()->values()->all();
    }
}
