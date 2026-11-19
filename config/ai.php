<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor de IA activo
    |--------------------------------------------------------------------------
    |
    | Selecciona el proveedor que usa el agente conversacional. Valores:
    | 'ollama' (local, por defecto) u 'openrouter' (de pago, conservado e
    | inactivo por defecto). Un valor vacío/desconocido cae a 'ollama' para no
    | activar jamás un proveedor de pago por accidente.
    |
    */

    'provider' => env('AI_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Ollama (local)
    |--------------------------------------------------------------------------
    |
    | Apunta al Ollama de la máquina del usuario. Sin API key; "disponible" si
    | el ping local responde. El modelo local (qwen3:8b) puede tardar, por eso
    | el timeout es configurable y mayor (CHAT_HTTP_TIMEOUT).
    |
    */

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3:8b'),
        'endpoint' => '/api/chat',
        'timeout' => (int) env('CHAT_HTTP_TIMEOUT', 20),
        'ping_timeout' => (int) env('OLLAMA_PING_TIMEOUT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenRouter (de pago, conservado — inactivo por defecto)
    |--------------------------------------------------------------------------
    |
    | Lee las mismas variables OPENROUTER_* que config/services.php (no se
    | duplica la fuente de credenciales). "Disponible" si hay API key.
    |
    */

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
    ],

];
