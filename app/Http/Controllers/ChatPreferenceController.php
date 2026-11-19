<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\StorePreferenceRequest;
use App\Http\Resources\ChatPreferenceResource;
use App\Models\ChatUserPreference;
use App\Services\Chat\ConversationMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Endpoints de lectura/escritura/borrado de PREFERENCIAS de interacción para
 * usuarios AUTENTICADOS (memoria de largo plazo, §2.8). El consentimiento es
 * explícito y se valida en servidor (regla 9); todas las reglas duras viven en
 * ConversationMemoryService (lista blanca + catálogo + consentimiento +
 * registro de denegación), nunca en este controlador.
 *
 * Aislamiento por usuario (anti-IDOR, reglas 1-3/6 de §2.9): NUNCA se acepta un
 * user_id/foreign id del cliente. Toda operación se acota con $request->user(),
 * de modo que no existe un id crudo que manipular; ni siquiera un super_admin
 * puede leer/escribir/borrar preferencias de otro usuario (el servicio acota
 * cada consulta por $user->id).
 */
class ChatPreferenceController extends Controller
{
    public function __construct(
        private ConversationMemoryService $memory,
    ) {}

    /**
     * Lista las preferencias CONSENTIDAS del usuario autenticado. Se parte del
     * mapa ya filtrado por la lista blanca (memory->all) y se cargan las marcas
     * de consentimiento desde el modelo, siempre acotado por user_id.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        // Mapa key => value ya re-filtrado por la lista blanca vigente.
        $allowed = $this->memory->all($user);

        $preferences = ChatUserPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('key', array_keys($allowed))
            ->orderBy('key')
            ->get();

        return ChatPreferenceResource::collection($preferences);
    }

    /**
     * Guarda/actualiza una preferencia del usuario con consentimiento explícito.
     * La validación ya garantizó clave/valor de catálogo y consentimiento
     * afirmativo; el servicio es la compuerta final. Si set() rechaza (false),
     * se responde 422 neutro y el servicio ya registró la denegación (regla 10).
     */
    public function store(StorePreferenceRequest $request): JsonResponse
    {
        $user = $request->user();
        $key = (string) $request->validated('key');
        $value = (string) $request->validated('value');

        $stored = $this->memory->set($user, $key, $value, $request->boolean('consented'));

        if (! $stored) {
            return response()->json([
                'message' => 'No fue posible guardar la preferencia.',
            ], 422);
        }

        $preference = ChatUserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->firstOrFail();

        return (new ChatPreferenceResource($preference))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Elimina una sola preferencia del usuario autenticado. 404 neutro si no
     * había nada que borrar (no revela existencia de claves de terceros).
     */
    public function destroy(Request $request, string $key): JsonResponse
    {
        $removed = $this->memory->forget($request->user(), $key);

        if (! $removed) {
            abort(404);
        }

        return response()->json(['deleted' => true]);
    }
}
