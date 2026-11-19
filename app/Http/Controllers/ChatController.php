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
        // volver a crear mensajes ni invocar al agente.
        if ($clientMessageId !== null && $clientMessageId !== '') {
            $existing = $this->repository->findByClientMessageId($conversation, $clientMessageId);

            if ($existing !== null) {
                return $this->replayPreviousReply($conversation, $existing);
            }
        }

        try {
            $result = $agent->handle($conversation, $validated['message'], $user);
        } catch (\Throwable $e) {
            Log::error('Fallo en el agente de chat de Mentea.', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

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
     * idempotencia de futuros reintentos) y actualiza last_message_at,
     * conservando intacto el last_activity_at que ya escribe el agente.
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

        $conversation->forceFill(['last_message_at' => $now])->save();
    }

    /**
     * Reconstruye la respuesta de un turno ya procesado (reintento idempotente):
     * devuelve el contenido del siguiente mensaje del asistente, sin crear filas
     * nuevas. Las banderas se neutralizan (el estado de crisis/escalado ya se
     * aplicó en el turno original).
     */
    private function replayPreviousReply(ChatConversation $conversation, ChatMessage $userMessage): JsonResponse
    {
        $assistant = $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '>', $userMessage->id)
            ->orderBy('id')
            ->first();

        return response()->json([
            'reply' => $assistant?->content ?? '',
            'conversation_id' => $conversation->id,
            'flags' => [
                'crisis' => false,
                'escalated' => false,
                'requires_consent' => false,
                'requires_login' => false,
            ],
            'slots' => [],
        ]);
    }
}
