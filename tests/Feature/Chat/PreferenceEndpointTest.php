<?php

namespace Tests\Feature\Chat;

use App\Enums\Role;
use App\Models\ChatUserPreference;
use App\Models\User;
use App\Services\Chat\ConversationMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Cobertura de la ruta de escritura/consentimiento de preferencias
 * (pendiente 1) y del endurecimiento de all() (ítem 2). Reglas 7/9/10 y
 * aislamiento por usuario (anti-IDOR, incluido super_admin).
 */
class PreferenceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role = Role::Patient): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_guest_cannot_reach_preference_endpoints(): void
    {
        $this->getJson(route('chat.preferences.index'))->assertUnauthorized();
        $this->postJson(route('chat.preferences.store'))->assertUnauthorized();
        $this->deleteJson(route('chat.preferences.destroy', ['key' => 'response_length']))
            ->assertUnauthorized();
    }

    public function test_store_requires_affirmative_consent(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('chat.preferences.store'), [
                'key' => 'response_length',
                'value' => 'breve',
                'consented' => false,
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson(route('chat.preferences.store'), [
                'key' => 'response_length',
                'value' => 'breve',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('chat_user_preferences', [
            'user_id' => $user->id,
            'key' => 'response_length',
        ]);
    }

    public function test_store_persists_whitelisted_preference_with_consent(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('chat.preferences.store'), [
                'key' => 'response_length',
                'value' => 'breve',
                'consented' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'response_length')
            ->assertJsonPath('data.value', 'breve')
            ->assertJsonMissingPath('data.user_id');

        $preference = ChatUserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', 'response_length')
            ->first();

        $this->assertNotNull($preference);
        $this->assertSame('breve', $preference->value);
        $this->assertNotNull($preference->consented_at);
    }

    public function test_store_rejects_out_of_catalog_value(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('chat.preferences.store'), [
                'key' => 'response_length',
                'value' => 'gigantesca',
                'consented' => true,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('chat_user_preferences', [
            'user_id' => $user->id,
            'key' => 'response_length',
        ]);
    }

    public function test_store_rejects_non_whitelisted_key(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('chat.preferences.store'), [
                'key' => 'diagnostico_clinico',
                'value' => 'lo que sea',
                'consented' => true,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('chat_user_preferences', [
            'user_id' => $user->id,
            'key' => 'diagnostico_clinico',
        ]);
    }

    public function test_index_returns_only_callers_whitelisted_preferences(): void
    {
        $user = $this->user();
        $other = $this->user();

        ChatUserPreference::factory()->create([
            'user_id' => $user->id,
            'key' => 'response_length',
            'value' => 'breve',
        ]);
        ChatUserPreference::factory()->create([
            'user_id' => $other->id,
            'key' => 'response_length',
            'value' => 'detallada',
        ]);
        // Clave ya no presente en la lista blanca: no debe aflorar.
        ChatUserPreference::factory()->create([
            'user_id' => $user->id,
            'key' => 'stale_key',
            'value' => 'algo',
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('chat.preferences.index'))
            ->assertOk();

        $keys = collect($response->json('data'))->pluck('key')->all();

        $this->assertContains('response_length', $keys);
        $this->assertNotContains('stale_key', $keys);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_destroy_removes_only_callers_key(): void
    {
        $user = $this->user();
        $other = $this->user();

        ChatUserPreference::factory()->create([
            'user_id' => $user->id,
            'key' => 'response_length',
            'value' => 'breve',
        ]);
        ChatUserPreference::factory()->create([
            'user_id' => $other->id,
            'key' => 'response_length',
            'value' => 'detallada',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('chat.preferences.destroy', ['key' => 'response_length']))
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertDatabaseMissing('chat_user_preferences', [
            'user_id' => $user->id,
            'key' => 'response_length',
        ]);
        // La preferencia del otro usuario sigue intacta.
        $this->assertDatabaseHas('chat_user_preferences', [
            'user_id' => $other->id,
            'key' => 'response_length',
        ]);
    }

    public function test_destroy_returns_404_when_nothing_to_remove(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->deleteJson(route('chat.preferences.destroy', ['key' => 'response_length']))
            ->assertNotFound();
    }

    public function test_super_admin_cannot_delete_another_users_preference(): void
    {
        $owner = $this->user();
        $superAdmin = $this->user(Role::SuperAdmin);

        ChatUserPreference::factory()->create([
            'user_id' => $owner->id,
            'key' => 'response_length',
            'value' => 'breve',
        ]);

        // No hay id crudo que manipular: el servicio acota por el usuario
        // autenticado, de modo que el super_admin no toca la preferencia ajena.
        $this->actingAs($superAdmin)
            ->deleteJson(route('chat.preferences.destroy', ['key' => 'response_length']))
            ->assertNotFound();

        $this->assertDatabaseHas('chat_user_preferences', [
            'user_id' => $owner->id,
            'key' => 'response_length',
        ]);
    }

    public function test_service_denial_log_carries_no_rejected_value(): void
    {
        $user = $this->user();
        $memory = app(ConversationMemoryService::class);

        $secret = 'valor-clinico-rechazado-que-no-debe-loguearse';

        Log::spy();

        // Valor fuera de catálogo: el servicio rechaza y registra la denegación
        // con user_id/key/reason, nunca con el valor rechazado (regla 10).
        $this->assertFalse($memory->set($user, 'response_length', $secret, true));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user, $secret) {
                $this->assertSame($user->id, $context['user_id']);
                $this->assertSame('response_length', $context['key']);
                $this->assertArrayHasKey('reason', $context);
                $this->assertArrayNotHasKey('value', $context);
                $this->assertStringNotContainsString($secret, json_encode($context));
                $this->assertStringNotContainsString($secret, $message);

                return true;
            });
    }
}
