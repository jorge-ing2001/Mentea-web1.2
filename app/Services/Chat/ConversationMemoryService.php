<?php

namespace App\Services\Chat;

use App\Models\ChatUserPreference;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Memoria de largo plazo PROVIDER-INDEPENDIENTE (§2.8). Lee, escribe y borra
 * preferencias de interacción/UI en chat_user_preferences.
 *
 * Reglas duras (viven en PHP, no en el modelo):
 *  - Regla 7: NUNCA se guarda contenido clínico ni sensible como memoria
 *    permanente. Solo se persisten claves de una LISTA BLANCA explícita
 *    (config('chat.preference_keys')) con valores de su catálogo permitido.
 *    Cualquier otra clave o valor se rechaza y NO se persiste nada.
 *  - Consentimiento obligatorio: set() solo escribe cuando el llamador afirma
 *    el consentimiento del usuario; se registra consented_at.
 *  - Aislamiento por usuario: get/all/forget siempre se acotan por user_id del
 *    usuario dado (un usuario nunca ve preferencias de otro).
 *
 * La memoria NO amplía permisos: es solo una pista de redacción/UI.
 */
class ConversationMemoryService
{
    /**
     * Devuelve el valor de una preferencia consentida del usuario, o null si
     * no existe (o la clave no está permitida).
     */
    public function get(User $user, string $key): ?string
    {
        if (! $this->isAllowedKey($key)) {
            return null;
        }

        $preference = ChatUserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        return $preference?->value;
    }

    /**
     * Todas las preferencias consentidas del usuario como mapa key => value.
     *
     * Se re-filtra CADA fila por la lista blanca actual (misma compuerta que
     * usa get() vía isAllowedKey). Así, si una clave se retira después de
     * config('chat.preference_keys'), una fila antigua con esa clave NO vuelve
     * a aflorar al modelo/UI aunque siga en la base de datos.
     *
     * @return array<string,string>
     */
    public function all(User $user): array
    {
        $preferences = ChatUserPreference::query()
            ->where('user_id', $user->id)
            ->pluck('value', 'key')
            ->all();

        return array_filter(
            $preferences,
            fn (string $key): bool => $this->isAllowedKey($key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Catálogo normalizado de la lista blanca para la capa HTTP (FormRequest /
     * Resource): clave => catálogo de valores (array enumerado) o 'text'. No
     * duplica reglas: set()/canStore() siguen siendo la compuerta final.
     *
     * @return array<string,array<int,string>|string>
     */
    public function allowedKeys(): array
    {
        return $this->allowList();
    }

    /**
     * Guarda/actualiza una preferencia NO sensible del usuario.
     *
     * Requiere consentimiento explícito ($consented) y que (key, value) estén
     * en la lista blanca. Si falta consentimiento o la combinación no es
     * válida, NO persiste nada y devuelve false.
     */
    public function set(User $user, string $key, string $value, bool $consented): bool
    {
        if (! $consented) {
            $this->logDenied($user, $key, 'consent_missing');

            return false;
        }

        $normalized = $this->sanitizeValue($key, $value);

        if ($normalized === null) {
            $this->logDenied($user, $key, 'not_allowed');

            return false;
        }

        ChatUserPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            ['value' => $normalized, 'consented_at' => now()],
        );

        return true;
    }

    /**
     * Elimina una preferencia del usuario. Devuelve true si se borró algo.
     */
    public function forget(User $user, string $key): bool
    {
        return ChatUserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->delete() > 0;
    }

    /**
     * Compuerta de consentimiento: ¿puede escribirse esta (clave, valor)?
     * No toca la base de datos; solo valida lista blanca + consentimiento.
     */
    public function canStore(string $key, string $value, bool $consented): bool
    {
        return $consented && $this->sanitizeValue($key, $value) !== null;
    }

    private function isAllowedKey(string $key): bool
    {
        return array_key_exists($key, $this->allowList());
    }

    /**
     * Normaliza y valida un valor contra el catálogo permitido de su clave.
     * Devuelve el valor saneado o null si la (clave, valor) no es admisible.
     */
    private function sanitizeValue(string $key, string $value): ?string
    {
        $allowList = $this->allowList();

        if (! array_key_exists($key, $allowList)) {
            return null;
        }

        $catalog = $allowList[$key];
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Catálogo de texto libre acotado (p. ej. pista de tema preferido):
        // se limita la longitud y se descartan saltos de línea/control para
        // mantenerlo como una pista de UI pequeña y no clínica.
        if ($catalog === 'text') {
            $value = preg_replace('/\s+/u', ' ', $value) ?? '';
            $max = (int) config('chat.preference_text_max_chars', 60);

            if ($value === '' || mb_strlen($value) > $max) {
                return null;
            }

            return $value;
        }

        // Catálogo enumerado: el valor debe estar exactamente en la lista.
        if (is_array($catalog) && in_array($value, $catalog, true)) {
            return $value;
        }

        return null;
    }

    /**
     * Lista blanca de claves de preferencia permitidas => catálogo de valores
     * (array enumerado) o 'text' para texto libre acotado.
     *
     * @return array<string,array<int,string>|string>
     */
    private function allowList(): array
    {
        $keys = config('chat.preference_keys', []);

        return is_array($keys) ? $keys : [];
    }

    /**
     * Log de denegación sin contenido clínico (regla 10): solo user_id, clave
     * y motivo. Nunca se registra el valor rechazado.
     */
    private function logDenied(User $user, string $key, string $reason): void
    {
        Log::warning('Preferencia de chat rechazada (memoria de largo plazo).', [
            'user_id' => $user->id,
            'key' => $key,
            'reason' => $reason,
        ]);
    }
}
