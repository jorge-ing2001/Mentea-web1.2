<?php

namespace App\Services\Chat\Providers;

/**
 * Contrato de proveedor de IA para el agente de Mentea.
 *
 * Desacopla al orquestador (ChatAgentService) del proveedor concreto (Ollama
 * local, OpenRouter de pago, u otro futuro). Toda implementación DEBE:
 *
 *   - Usar Illuminate\Support\Facades\Http para que Http::fake() funcione.
 *   - Degradar con gracia: devolver null (nunca lanzar) ante falta de
 *     configuración, timeout, error de red o respuesta vacía/inparseable.
 *   - Registrar los fallos sin exponer claves ni datos sensibles.
 *
 * El código manda, el modelo solo redacta: si chat()/classify() devuelven null
 * el orquestador usa su ruta determinista de seguridad.
 */
interface AiProvider
{
    /**
     * Envía los mensajes al modelo y devuelve el contenido de texto del
     * asistente, o null si el proveedor no está disponible o la llamada falla.
     *
     * @param  array<int,array<string,mixed>>  $messages
     */
    public function chat(array $messages): ?string;

    /**
     * Clasifica un mensaje consultando al modelo con un prompt de sistema y el
     * mensaje del usuario. Devuelve el contenido crudo del asistente para que
     * el llamador lo parsee, o null ante fallo/indisponibilidad.
     */
    public function classify(string $systemPrompt, string $message): ?string;

    /**
     * Indica si el proveedor está disponible para atender peticiones:
     * OpenRouter -> hay API key; Ollama -> el ping local responde.
     */
    public function isAvailable(): bool;

    /**
     * Nombre corto del proveedor (p.ej. 'ollama', 'openrouter').
     */
    public function name(): string;
}
