<?php

namespace App\Http\Requests\Chat;

use App\Services\Chat\ConversationMemoryService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de servidor (regla 9) para guardar/actualizar una preferencia de
 * interacción con CONSENTIMIENTO explícito. Rechaza claves fuera de la lista
 * blanca y valores fuera del catálogo ANTES de llegar al servicio; el servicio
 * (ConversationMemoryService::set/canStore) sigue siendo la compuerta final.
 *
 * El consentimiento es obligatorio y afirmativo: sin 'consented' = true la
 * petición no pasa la validación, de modo que el frontend no puede omitirlo
 * (el modelo nunca persiste nada en silencio).
 */
class StorePreferenceRequest extends FormRequest
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
        $catalog = app(ConversationMemoryService::class)->allowedKeys();
        $max = (int) config('chat.preference_text_max_chars', 60);

        return [
            // Consentimiento afirmativo obligatorio (regla 9): debe estar
            // presente y ser verdadero/aceptado para siquiera validar.
            'consented' => ['required', 'accepted'],

            // Solo claves de la lista blanca actual.
            'key' => ['required', 'string', Rule::in(array_keys($catalog))],

            // El valor se acota siempre al tope de texto; el catálogo concreto
            // (enumerado o texto) se valida con la clave ya conocida.
            'value' => [
                'required',
                'string',
                "max:{$max}",
                function (string $attribute, mixed $value, Closure $fail) use ($catalog): void {
                    $key = $this->input('key');

                    if (! is_string($key) || ! array_key_exists($key, $catalog)) {
                        // La regla de 'key' ya reportará el problema.
                        return;
                    }

                    $allowed = $catalog[$key];

                    // Catálogo enumerado: el valor debe estar exactamente en él.
                    if (is_array($allowed) && ! in_array($value, $allowed, true)) {
                        $fail('El valor de la preferencia no es válido.');
                    }
                },
            ],
        ];
    }
}
