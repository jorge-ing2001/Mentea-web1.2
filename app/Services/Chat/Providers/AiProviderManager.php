<?php

namespace App\Services\Chat\Providers;

use Illuminate\Contracts\Container\Container;

/**
 * Fábrica que resuelve el proveedor de IA activo según config('ai.provider').
 *
 * Valores soportados: 'ollama' (por defecto) y 'openrouter'. Un valor vacío o
 * desconocido cae a Ollama, para no activar jamás un proveedor de pago por
 * accidente.
 */
class AiProviderManager
{
    public function __construct(private Container $app) {}

    /**
     * Devuelve la instancia del proveedor seleccionado por configuración.
     */
    public function provider(): AiProvider
    {
        return match ($this->activeName()) {
            'openrouter' => $this->app->make(OpenRouterProvider::class),
            default => $this->app->make(OllamaProvider::class),
        };
    }

    /**
     * Nombre normalizado del proveedor activo ('ollama' por defecto).
     */
    public function activeName(): string
    {
        $configured = strtolower(trim((string) config('ai.provider', 'ollama')));

        return in_array($configured, ['ollama', 'openrouter'], true) ? $configured : 'ollama';
    }
}
