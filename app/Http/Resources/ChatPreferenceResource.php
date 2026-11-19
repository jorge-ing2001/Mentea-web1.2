<?php

namespace App\Http\Resources;

use App\Models\ChatUserPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa una preferencia de interacción de forma segura: expone solo la
 * clave, el valor saneado y la marca de consentimiento. Nunca expone user_id
 * ni ids internos. La colección ya viene acotada por el usuario autenticado.
 *
 * @mixin ChatUserPreference
 */
class ChatPreferenceResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'consented_at' => $this->consented_at?->toIso8601String(),
        ];
    }
}
