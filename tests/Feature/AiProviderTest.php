<?php

namespace Tests\Feature;

use App\Services\Chat\Providers\AiProvider;
use App\Services\Chat\Providers\AiProviderManager;
use App\Services\Chat\Providers\OllamaProvider;
use App\Services\Chat\Providers\OpenRouterProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pruebas de la capa de proveedores de IA desacoplada (Fase 3).
 *
 * Todo el tráfico HTTP se simula con Http::fake(): Ollama NO es alcanzable en
 * el entorno de pruebas y OpenRouter nunca debe llamarse en vivo. Se valida la
 * forma del request (endpoint, header, cuerpo), el parseo de la respuesta, la
 * selección por config('ai.provider') y la degradación a null sin lanzar.
 */
class AiProviderTest extends TestCase
{
    // ----- Ollama: chat() -----

    public function test_ollama_chat_posts_to_api_chat_without_auth_and_parses_message_content(): void
    {
        config([
            'ai.provider' => 'ollama',
            'ai.ollama.base_url' => 'http://localhost:11434',
            'ai.ollama.model' => 'qwen3:8b',
        ]);

        Http::fake([
            '*/api/chat' => Http::response(['message' => ['content' => 'Hola desde Ollama']], 200),
        ]);

        $provider = new OllamaProvider;
        $reply = $provider->chat([['role' => 'user', 'content' => 'Hola']]);

        $this->assertSame('Hola desde Ollama', $reply);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:11434/api/chat'
                && $request->method() === 'POST'
                && ! $request->hasHeader('Authorization')
                && $request['stream'] === false
                && $request['model'] === 'qwen3:8b'
                && $request['messages'][0]['content'] === 'Hola';
        });
    }

    public function test_ollama_classify_uses_the_same_api_chat_path(): void
    {
        config(['ai.ollama.base_url' => 'http://localhost:11434']);

        Http::fake([
            '*/api/chat' => Http::response(['message' => ['content' => '{"crisis": true}']], 200),
        ]);

        $provider = new OllamaProvider;
        $raw = $provider->classify('Eres un clasificador', 'mensaje');

        $this->assertSame('{"crisis": true}', $raw);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/chat'));
    }

    // ----- Ollama: isAvailable() -----

    public function test_ollama_is_available_when_tags_ping_responds_200(): void
    {
        config(['ai.ollama.base_url' => 'http://localhost:11434']);

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
        ]);

        $this->assertTrue((new OllamaProvider)->isAvailable());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/tags'));
    }

    public function test_ollama_is_not_available_on_connection_failure(): void
    {
        config(['ai.ollama.base_url' => 'http://localhost:11434']);

        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->assertFalse((new OllamaProvider)->isAvailable());
    }

    // ----- Ollama: degradación -----

    public function test_ollama_chat_returns_null_on_http_500_without_throwing(): void
    {
        config(['ai.ollama.base_url' => 'http://localhost:11434']);

        Http::fake(['*/api/chat' => Http::response('boom', 500)]);

        $this->assertNull((new OllamaProvider)->chat([['role' => 'user', 'content' => 'Hola']]));
    }

    public function test_ollama_chat_returns_null_on_connection_error_without_throwing(): void
    {
        config(['ai.ollama.base_url' => 'http://localhost:11434']);

        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        $this->assertNull((new OllamaProvider)->chat([['role' => 'user', 'content' => 'Hola']]));
    }

    // ----- OpenRouter: chat() -----

    public function test_openrouter_chat_posts_to_chat_completions_with_bearer_and_parses_choices(): void
    {
        config([
            'ai.openrouter.key' => 'sk-test-123',
            'ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'ai.openrouter.model' => 'openai/gpt-4o-mini',
            'services.openrouter.key' => 'sk-test-123',
        ]);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hola desde OpenRouter']]],
            ], 200),
        ]);

        $provider = new OpenRouterProvider;
        $reply = $provider->chat([['role' => 'user', 'content' => 'Hola']]);

        $this->assertSame('Hola desde OpenRouter', $reply);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer sk-test-123')
                && $request['model'] === 'openai/gpt-4o-mini';
        });
    }

    // ----- OpenRouter: isAvailable() -----

    public function test_openrouter_is_not_available_without_key(): void
    {
        config(['ai.openrouter.key' => null, 'services.openrouter.key' => null]);

        $this->assertFalse((new OpenRouterProvider)->isAvailable());
        Http::assertNothingSent();
    }

    public function test_openrouter_is_available_with_key(): void
    {
        config(['ai.openrouter.key' => 'sk-test-123']);

        $this->assertTrue((new OpenRouterProvider)->isAvailable());
    }

    public function test_openrouter_makes_no_http_call_without_key(): void
    {
        config(['ai.openrouter.key' => null, 'services.openrouter.key' => null]);
        Http::fake();

        $this->assertNull((new OpenRouterProvider)->chat([['role' => 'user', 'content' => 'Hola']]));
        Http::assertNothingSent();
    }

    // ----- OpenRouter: degradación -----

    public function test_openrouter_chat_returns_null_on_http_500_without_throwing(): void
    {
        config(['ai.openrouter.key' => 'sk-test-123', 'services.openrouter.key' => 'sk-test-123']);

        Http::fake(['*/chat/completions' => Http::response('boom', 500)]);

        $this->assertNull((new OpenRouterProvider)->chat([['role' => 'user', 'content' => 'Hola']]));
    }

    public function test_openrouter_chat_returns_null_on_connection_error_without_throwing(): void
    {
        config(['ai.openrouter.key' => 'sk-test-123', 'services.openrouter.key' => 'sk-test-123']);

        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        $this->assertNull((new OpenRouterProvider)->chat([['role' => 'user', 'content' => 'Hola']]));
    }

    // ----- Manager / factory -----

    public function test_manager_returns_ollama_provider_by_default(): void
    {
        config(['ai.provider' => 'ollama']);

        $provider = app(AiProviderManager::class)->provider();

        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $this->assertSame('ollama', $provider->name());
    }

    public function test_manager_returns_openrouter_provider_when_configured(): void
    {
        config(['ai.provider' => 'openrouter']);

        $provider = app(AiProviderManager::class)->provider();

        $this->assertInstanceOf(OpenRouterProvider::class, $provider);
        $this->assertSame('openrouter', $provider->name());
    }

    public function test_manager_falls_back_to_ollama_for_unknown_provider(): void
    {
        config(['ai.provider' => 'definitivamente-no-existe']);

        $this->assertInstanceOf(OllamaProvider::class, app(AiProviderManager::class)->provider());
    }

    public function test_manager_falls_back_to_ollama_for_empty_provider(): void
    {
        config(['ai.provider' => '']);

        $this->assertInstanceOf(OllamaProvider::class, app(AiProviderManager::class)->provider());
    }

    // ----- Binding de la interfaz -----

    public function test_container_resolves_ai_provider_interface_via_manager(): void
    {
        config(['ai.provider' => 'ollama']);
        $this->assertInstanceOf(OllamaProvider::class, app(AiProvider::class));

        config(['ai.provider' => 'openrouter']);
        $this->assertInstanceOf(OpenRouterProvider::class, app(AiProvider::class));
    }
}
