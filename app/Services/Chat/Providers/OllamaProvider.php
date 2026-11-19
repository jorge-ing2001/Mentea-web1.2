<?php

namespace App\Services\Chat\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proveedor Ollama local (activo por defecto).
 *
 * Habla con el Ollama de la PC del usuario (p.ej. http://localhost:11434). A
 * diferencia de OpenRouter:
 *
 *   - Sin token: NO se envía header Authorization.
 *   - Request: POST {base_url}/api/chat con {model, messages, stream:false}.
 *   - Respuesta: se lee message.content (no choices.0.message.content).
 *   - "Disponible": el ping local GET /api/tags (o /api/version) responde.
 *   - Timeout mayor: el modelo local (qwen3:8b) puede tardar; usa el valor
 *     configurable CHAT_HTTP_TIMEOUT.
 *
 * Degradación grácil: nunca lanza; devuelve null y registra sin exponer datos
 * ante timeout, respuesta vacía o error de conexión. El orquestador aplica
 * entonces su ruta determinista de seguridad (nunca se simula una respuesta
 * del modelo).
 */
class OllamaProvider implements AiProvider
{
    public function name(): string
    {
        return 'ollama';
    }

    /**
     * Disponible si el ping local responde. Prueba GET /api/tags y cae a
     * GET /api/version. Timeout corto para no bloquear el turno; cualquier
     * error de conexión significa "no disponible" (nunca lanza).
     */
    public function isAvailable(): bool
    {
        $baseUrl = rtrim($this->baseUrl(), '/');
        $pingTimeout = (int) config('ai.ollama.ping_timeout', 3);

        foreach (['/api/tags', '/api/version'] as $path) {
            try {
                $response = Http::timeout($pingTimeout)
                    ->acceptJson()
                    ->get($baseUrl.$path);

                if ($response->successful()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Probar el siguiente endpoint de ping.
            }
        }

        return false;
    }

    /**
     * Envía los mensajes a Ollama y devuelve message.content, o null ante
     * timeout / error / respuesta vacía.
     *
     * @param  array<int,array<string,mixed>>  $messages
     */
    public function chat(array $messages): ?string
    {
        $baseUrl = rtrim($this->baseUrl(), '/');
        $endpoint = (string) config('ai.ollama.endpoint', '/api/chat');
        $model = $this->model();
        $timeout = (int) config('ai.ollama.timeout', config('chat.timeout', 20));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.$endpoint, [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                ]);

            if (! $response->successful()) {
                Log::warning('Ollama respondió con error; se usa la ruta determinista.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $content = $response->json('message.content');

            return is_string($content) && trim($content) !== '' ? trim($content) : null;
        } catch (\Throwable $e) {
            Log::warning('Fallo al llamar a Ollama; se usa la ruta determinista.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Clasifica reusando chat() con un par system+user (misma única ruta HTTP
     * falseable con Http::fake() y misma degradación grácil).
     */
    public function classify(string $systemPrompt, string $message): ?string
    {
        return $this->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message],
        ]);
    }

    private function baseUrl(): string
    {
        return (string) config('ai.ollama.base_url', 'http://localhost:11434');
    }

    private function model(): string
    {
        return (string) config('ai.ollama.model', 'qwen3:8b');
    }
}
