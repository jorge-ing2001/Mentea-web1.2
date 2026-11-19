<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\User;

/**
 * Orquestador delgado sobre {@see ConversationRepository}. Resuelve "qué
 * conversación usar" para un turno: la activa más reciente, una concreta por id
 * (acotada al usuario) o una nueva. Lo usan el controlador y, donde convenga,
 * el agente.
 *
 * El comportamiento de invitados (keyed por session_id) NO pasa por aquí: la
 * memoria persistente es solo para usuarios autenticados; los invitados siguen
 * resolviéndose en ChatController como siempre.
 */
class ConversationManager
{
    public function __construct(private ConversationRepository $repository) {}

    /**
     * Conversación activa más reciente del usuario; si no tiene ninguna, crea
     * una nueva.
     */
    public function resolveActive(User $user): ChatConversation
    {
        return $this->repository->activeForUser($user)
            ?? $this->repository->create($user);
    }

    /**
     * Conversación del usuario por id, acotada por user_id. Devuelve null si el
     * id no le pertenece (anti-IDOR): la decisión de abortar/caer a la activa
     * es del llamador.
     */
    public function resolveById(User $user, int $id): ?ChatConversation
    {
        return $this->repository->findForUser($user, $id);
    }

    /**
     * Inicia una conversación nueva para el usuario.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function startNew(User $user, array $attributes = []): ChatConversation
    {
        return $this->repository->create($user, $attributes);
    }
}
