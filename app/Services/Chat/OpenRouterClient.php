<?php

namespace App\Services\Chat;

use App\Services\Chat\Providers\OpenRouterProvider;

/**
 * Alias histórico conservado por compatibilidad.
 *
 * La lógica de OpenRouter vive ahora en App\Services\Chat\Providers\
 * OpenRouterProvider (implementa la interfaz AiProvider). Esta clase se
 * mantiene como subclase delgada para que cualquier referencia o test que
 * siga tipando/resolviendo OpenRouterClient continúe funcionando.
 *
 * NO se elimina: OpenRouter se conserva como proveedor alternativo.
 */
class OpenRouterClient extends OpenRouterProvider
{
    /**
     * Compatibilidad: el nombre histórico de isAvailable() era isConfigured().
     */
    public function isConfigured(): bool
    {
        return $this->isAvailable();
    }
}
