<?php

namespace App\Http\Resources;

use App\Models\ChatConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa una conversación de forma segura: expone solo id, título, estado y
 * marcas temporales. Los mensajes solo se incluyen cuando se cargan
 * explícitamente (endpoint show), nunca en el listado. Nunca filtra datos de
 * otros usuarios porque la colección ya viene acotada por user_id.
 *
 * @mixin ChatConversation
 */
class ChatConversationResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'messages' => ChatMessageResource::collection(
                $this->whenLoaded('messages')
            ),
        ];
    }
}
