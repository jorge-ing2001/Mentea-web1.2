<?php

namespace App\Services\Chat\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proveedor OpenRouter (de pago, conservado e inactivo por defecto).
 *
 * Refactor de la lógica histórica de OpenRouterClient dentro de la interfaz
 * AiProvider. Llama a POST {base_url}/chat/completions con header
 * Authorization: Bearer <key> y lee choices.0.message.content.
 *
 * Degradación grácil: si no hay OPENROUTER_API_KEY NO se hace ninguna llamada
 * de red; se devuelve null y el orquestador usa su ruta determinista. Nunca
 * lanza; registra los fallos sin exponer la clave.
 *
 * Lee config('ai.openrouter.*') cayendo a config('services.openrouter.*') por
 * compatibilidad (ambos leen las mismas variables OPENROUTER_*).
 */
class OpenRouterProvider implements AiProvider
{
    private static bool $warnedMissingKey = false;

    public function name(): string
    {
        return 'openrouter';
    }

    public function isAvailable(): bool
    {
        return ! empty($this->key());
    }

    /**
     * Envía los mensajes a OpenRouter y devuelve el contenido de texto de la
     * respuesta del asistente, o null si no hay key / falla la red.
     *
     * @param  array<int,array<string,mixed>>  $messages
     */
    public function chat(array $messages): ?string
    {
        if (! $this->isAvailable()) {
            if (! self::$warnedMissingKey) {
                Log::warning('OpenRouter no configurado (OPENROUTER_API_KEY vacío); el agente usa la ruta determinista.');
                self::$warnedMissingKey = true;
            }

            return null;
        }

        $baseUrl = rtrim($this->baseUrl(), '/');
        $model = $this->model();
        $timeout = (int) config('chat.timeout', 20);

        try {
            $response = Http::withToken((string) $this->key())
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                ]);

            if (! $response->successful()) {
                Log::warning('OpenRouter respondió con error; se usa la ruta determinista.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $content = $response->json('choices.0.message.content');

            return is_string($content) && trim($content) !== '' ? trim($content) : null;
        } catch (\Throwable $e) {
            Log::warning('Fallo al llamar a OpenRouter; se usa la ruta determinista.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Clasifica un mensaje en crisis / no-crisis consultando al modelo. Es una
     * llamada dedicada (prompt de clasificación + mensaje del usuario) que
     * devuelve el contenido crudo del asistente para que el llamador lo parsee,
     * o null si no hay key / falla la red / respuesta vacía. Reusa `chat()`
     * (misma ruta HTTP falseable con Http::fake() y misma degradación grácil).
     */
    public function classify(string $systemPrompt, string $message): ?string
    {
        return $this->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message],
        ]);
    }

    private function key(): ?string
    {
        $key = config('ai.openrouter.key');

        return ! empty($key) ? $key : config('services.openrouter.key');
    }

    private function baseUrl(): string
    {
        $base = config('ai.openrouter.base_url');

        if (empty($base)) {
            $base = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        }

        return (string) $base;
    }

    private function model(): string
    {
        $model = config('ai.openrouter.model');

        if (empty($model)) {
            $model = config('services.openrouter.model', 'openai/gpt-4o-mini');
        }

        return (string) $model;
    }
}
