<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Servicio de consultas/CRUD de conversaciones, provider-independiente.
 *
 * Replica el patrón de autorización forzada del lado servidor de
 * {@see ChatReadTools}: cualquier identificador provisto por el cliente o el
 * modelo se usa ÚNICAMENTE para acotar dentro del alcance del usuario
 * (where user_id = $user->id). Un id ajeno nunca concede acceso: findForUser
 * devuelve null, lo que neutraliza el IDOR (reglas 1, 2, 3 y 6 de §2.9).
 *
 * No contiene lógica de proveedor de IA ni de memoria del modelo; solo
 * persistencia y lectura.
 */
class ConversationRepository
{
    /**
     * Conversaciones del usuario, más recientes primero, con el último mensaje
     * precargado para evitar N+1 al listarlas.
     *
     * @return Collection<int,ChatConversation>
     */
    public function listForUser(User $user): Collection
    {
        return ChatConversation::query()
            ->forUser($user->id)
            ->with(['messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->recent()
            ->get();
    }

    /**
     * Conversación activa más reciente del usuario (o null si no tiene).
     */
    public function activeForUser(User $user): ?ChatConversation
    {
        return ChatConversation::query()
            ->forUser($user->id)
            ->active()
            ->recent()
            ->first();
    }

    /**
     * Conversación del usuario por id. Devuelve null si el id no pertenece al
     * usuario autenticado: el id del cliente SOLO sirve para acotar dentro de
     * su propio alcance (patrón ChatReadTools, anti-IDOR).
     */
    public function findForUser(User $user, int $id): ?ChatConversation
    {
        return ChatConversation::query()
            ->forUser($user->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * Crea una conversación nueva para el usuario.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function create(User $user, array $attributes = []): ChatConversation
    {
        return ChatConversation::create(array_merge([
            'user_id' => $user->id,
            'status' => 'active',
            'last_activity_at' => now(),
            'last_message_at' => now(),
        ], $attributes));
    }

    /**
     * Archiva una conversación (no la elimina).
     */
    public function archive(ChatConversation $conversation): ChatConversation
    {
        $conversation->forceFill(['status' => 'archived'])->save();

        return $conversation;
    }

    /**
     * Elimina una conversación (los mensajes caen por FK cascade).
     */
    public function delete(ChatConversation $conversation): void
    {
        $conversation->delete();
    }

    /**
     * Busca un mensaje previo por client_message_id dentro de la conversación,
     * para idempotencia de reintentos. Devuelve null si no existe.
     */
    public function findByClientMessageId(ChatConversation $conversation, string $clientMessageId): ?ChatMessage
    {
        return $conversation->messages()
            ->where('client_message_id', $clientMessageId)
            ->first();
    }
}
