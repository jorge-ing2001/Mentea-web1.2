<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de servidor (regla 9) para renombrar o archivar una conversación.
 * La pertenencia se garantiza por resolución acotada al usuario en el
 * controlador.
 */
class UpdateConversationRequest extends FormRequest
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
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'archived'])],
        ];
    }
}
