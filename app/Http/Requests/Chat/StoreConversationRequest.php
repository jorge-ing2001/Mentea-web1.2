<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de servidor (regla 9) para crear una conversación nueva. La
 * autorización de acceso se resuelve con scope de usuario en el controlador,
 * no aquí.
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:120'],
        ];
    }
}
