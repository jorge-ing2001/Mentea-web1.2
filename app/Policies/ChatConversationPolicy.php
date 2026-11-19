<?php

namespace App\Policies;

use App\Models\ChatConversation;
use App\Models\User;

/**
 * Política de aislamiento por usuario para las conversaciones del chat.
 *
 * Cada habilidad exige estrictamente conversation.user_id === user.id. La
 * política NO concede acceso cruzado a admin/super_admin sobre conversaciones de
 * pacientes (reglas 5 y 6 de §2.9).
 *
 * IMPORTANTE: AppServiceProvider::boot() registra un Gate::before que devuelve
 * true para super_admin de forma global, por lo que este allow adelantaría a la
 * política. Para honrar la regla 6 los endpoints resuelven SIEMPRE la
 * conversación vía ConversationRepository::findForUser (scoped where user_id =
 * Auth::id()); así ni siquiera un super_admin puede leer la conversación de
 * otro cambiando el id. La política cubre el chequeo de habilidad una vez que la
 * pertenencia ya quedó garantizada por la resolución acotada.
 */
class ChatConversationPolicy
{
    public function view(User $user, ChatConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function update(User $user, ChatConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function archive(User $user, ChatConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function delete(User $user, ChatConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function addMessage(User $user, ChatConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    private function owns(User $user, ChatConversation $conversation): bool
    {
        return $conversation->user_id !== null
            && (int) $conversation->user_id === (int) $user->id;
    }
}
