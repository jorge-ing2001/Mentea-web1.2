<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatAgentService;
use App\Services\Chat\ConversationManager;
use App\Services\Chat\ConversationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Punto de entrada HTTP del agente conversacional nativo de Mentea.
 *
 * Opera bajo el middleware `web` (sesión + CSRF). Resuelve la conversación del
 * usuario autenticado o, si es invitado, de su sesión, delega en
 * ChatAgentService (que aplica todas las reglas duras) y devuelve la respuesta
 * del asistente como JSON. Nunca expone trazas: ante cualquier fallo responde
 * con un mensaje neutro en español.
 */
class ChatController extends Controller
{
    public function __construct(
        private ConversationManager $conversations,
        private ConversationRepository $repository,
    ) {}

    public function store(Request $request, ChatAgentService $agent): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['sometimes', 'nullable', 'integer'],
            'client_message_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], [
            'message.required' => 'Escribe un mensaje para continuar.',
            'message.string' => 'El mensaje no es válido.',
            'message.max' => 'El mensaje es demasiado largo.',
        ]);

        $user = Auth::user();
        $conversation = $this->resolveConversation(
            $request,
            $user?->id,
            isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null,
        );

        $clientMessageId = $validated['client_message_id'] ?? null;

        // Idempotencia de reintentos: si este client_message_id ya fue procesado
        // en esta conversación, devolvemos la respuesta previa del asistente sin
        // volver a crear mensajes ni invocar al agente. Solo se considera
        // "ya procesado" cuando el turno del usuario tiene además su respuesta
        // del asistente: un turno marcado pero SIN respuesta (porque el agente
        // falló en un intento previo) se reprocesa en lugar de reproducir vacío.
        if ($clientMessageId !== null && $clientMessageId !== '') {
            $existing = $this->repository->findByClientMessageId($conversation, $clientMessageId);

            if ($existing !== null) {
                $reply = $this->replayPreviousReply($conversation, $existing);

                if ($reply !== null) {
                    return $reply;
                }
            }
        }

        try {
            $result = $agent->handle($conversation, $validated['message'], $user);
        } catch (\Throwable $e) {
            Log::error('Fallo en el agente de chat de Mentea.', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            // El agente persiste el turno del usuario ANTES de poder fallar, y
            // al fallar no hay respuesta del asistente para ese turno. Si
            // dejáramos ese turno huérfano, un reintento con el mismo
            // client_message_id crearía un SEGUNDO turno del usuario (duplicado).
            // Para que el reintento sea seguro y sin duplicados, descartamos el
            // turno del usuario sin respuesta: el reintento reprocesa el mensaje
            // desde cero sobre una conversación consistente.
            $this->discardUnansweredUserTurn($conversation, $clientMessageId);

            return response()->json([
                'reply' => 'Lo siento, tuve un inconveniente para procesar tu mensaje. '
                    .'Por favor inténtalo de nuevo en unos instantes.',
                'conversation_id' => $conversation->id,
                'flags' => [
                    'crisis' => false,
                    'escalated' => false,
                    'requires_consent' => false,
                    'requires_login' => false,
                    'error' => true,
                ],
                'slots' => [],
            ], 200);
        }

        // Idempotencia + marca temporal: etiqueta el turno del usuario recién
        // persistido con su client_message_id y refresca last_message_at. Se
        // hace aquí (nivel controlador/repositorio) para no tocar las reglas
        // duras del agente.
        $this->stampTurn($conversation, $clientMessageId);

        return response()->json([
            'reply' => $result['reply'],
            'conversation_id' => $result['conversation_id'],
            'flags' => [
                'crisis' => $result['crisis'],
                'escalated' => $result['escalated'],
                'requires_consent' => $result['requires_consent'],
                'requires_login' => $result['requires_login'] ?? false,
            ],
            'slots' => $result['slots'],
        ]);
    }

    /**
     * Resuelve (o crea) la conversación del turno.
     *
     * Usuarios autenticados: si llega un conversation_id, se resuelve SIEMPRE con
     * scope por user_id vía ConversationManager::resolveById (patrón ChatReadTools,
     * anti-IDOR). Un id ajeno no concede acceso: se cae a la conversación activa
     * del propio usuario (nunca a la de otro). Sin id, se usa la activa más
     * reciente o se crea una. Invitados: se mantienen llaveados por session_id.
     */
    private function resolveConversation(Request $request, ?int $userId, ?int $conversationId): ChatConversation
    {
        if ($userId !== null) {
            $user = Auth::user();

            if ($conversationId !== null) {
                $scoped = $this->conversations->resolveById($user, $conversationId);

                if ($scoped !== null) {
                    return $scoped;
                }

                // El id no pertenece al usuario autenticado: no se concede acceso.
                Log::warning('Intento de usar una conversación de chat ajena en /chat.', [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'reason' => 'conversation_not_owned',
                ]);
            }

            return $this->conversations->resolveActive($user);
        }

        $sessionId = $request->session()->getId();

        return ChatConversation::firstOrCreate(
            ['session_id' => $sessionId, 'user_id' => null, 'status' => 'active'],
            ['last_activity_at' => now()],
        );
    }

    /**
     * Etiqueta el último turno del usuario con su client_message_id (para
     * idempotencia de futuros reintentos) y actualiza las marcas temporales.
     *
     * Se actualizan AMBAS columnas: last_message_at (ventana de memoria nueva) y
     * last_activity_at (compatibilidad: lo mantiene quien depende de la marca
     * histórica —timeouts de sesión, analítica—). El agente ya refresca
     * last_activity_at por turno; aquí se reafirma para no dejarla rezagada.
     */
    private function stampTurn(ChatConversation $conversation, ?string $clientMessageId): void
    {
        $now = now();

        if ($clientMessageId !== null && $clientMessageId !== '') {
            $lastUser = $conversation->messages()
                ->where('role', 'user')
                ->whereNull('client_message_id')
                ->latest('id')
                ->first();

            if ($lastUser !== null) {
                $lastUser->forceFill(['client_message_id' => $clientMessageId])->save();
            }
        }

        $conversation->forceFill([
            'last_message_at' => $now,
            'last_activity_at' => $now,
        ])->save();
    }

    /**
     * Descarta el turno del usuario que quedó SIN respuesta del asistente tras
     * un fallo del agente, para que un reintento con el mismo client_message_id
     * no produzca un turno duplicado. Solo borra el último mensaje del usuario
     * de la conversación cuando no existe ningún mensaje del asistente posterior.
     */
    private function discardUnansweredUserTurn(ChatConversation $conversation, ?string $clientMessageId): void
    {
        $lastUser = $conversation->messages()
            ->where('role', 'user')
            ->latest('id')
            ->first();

        if ($lastUser === null) {
            return;
        }

        $hasReply = $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '>', $lastUser->id)
            ->exists();

        if (! $hasReply) {
            $lastUser->delete();
        }
    }

    /**
     * Reconstruye la respuesta de un turno ya procesado (reintento idempotente):
     * devuelve el contenido del siguiente mensaje del asistente, sin crear filas
     * nuevas, RESTITUYENDO las banderas (requires_login/requires_consent/
     * crisis/escalated) que el turno original persistió en su meta. Así un
     * reintento no pierde la guía de inicio de sesión o consentimiento.
     *
     * Devuelve null cuando el turno del usuario aún no tiene respuesta del
     * asistente (p. ej. un intento previo falló): en ese caso el llamador debe
     * reprocesar el mensaje en vez de reproducir una respuesta vacía.
     */
    private function replayPreviousReply(ChatConversation $conversation, ChatMessage $userMessage): ?JsonResponse
    {
        $assistant = $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '>', $userMessage->id)
            ->orderBy('id')
            ->first();

        if ($assistant === null) {
            return null;
        }

        $flags = is_array($assistant->meta) ? ($assistant->meta['flags'] ?? []) : [];

        return response()->json([
            'reply' => $assistant->content ?? '',
            'conversation_id' => $conversation->id,
            'flags' => [
                'crisis' => (bool) ($flags['crisis'] ?? false),
                'escalated' => (bool) ($flags['escalated'] ?? false),
                'requires_consent' => (bool) ($flags['requires_consent'] ?? false),
                'requires_login' => (bool) ($flags['requires_login'] ?? false),
            ],
            'slots' => [],
        ]);
    }
}
