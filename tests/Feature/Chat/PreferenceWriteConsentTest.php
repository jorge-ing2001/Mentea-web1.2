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
 * Seguridad de la ruta de escritura/consentimiento de preferencias (pendiente
 * 1). Refleja la estructura de ConversationSecurityTest: aislamiento por
 * usuario (anti-IDOR, incluido super_admin), consentimiento obligatorio en
 * servidor (regla 9), lista blanca/catálogo (regla 7), endurecimiento del
 * read-path en all() y log de denegación sin valor rechazado (regla 10).
 */
class PreferenceWriteConsentTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role = Role::Patient): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_guest_cannot_reach_preference_endpoints(): void
    {
        $this->getJson(route('chat.preferences.index'))->assertUnauthorized();
        $this->postJson(route('chat.preferences.store'), [
            'key' => 'response_length',
            'value' => 'breve',
            'consented' => true,
        ])->assertUnauthorized();
        $this->deleteJson(route('chat.preferences.destroy', ['key' => 'response_length']))
            ->assertUnauthorized();
    }

    public function test_store_without_consent_is_not_persisted(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('chat.preferences.store'), [
                'key' => 'response_length',
                'value' => 'breve',
                'consented' => false,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('chat_user_preferences', [
            'user_id' => $user->id,
            'key' => 'response_length',
        ]);
    }

    public function test_store_with_consent_persists_and_sets_consented_at(): void
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
        $this->assertNotNull($preference->consented_at);
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

    public function test_index_returns_only_the_callers_preferences(): void
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

        $response = $this->actingAs($user)
            ->getJson(route('chat.preferences.index'))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('breve', $response->json('data.0.value'));
    }

    public function test_user_cannot_overwrite_another_users_preference(): void
    {
        $owner = $this->user();
        $attacker = $this->user();

        ChatUserPreference::factory()->create([
            'user_id' => $owner->id,
            'key' => 'response_length',
            'value' => 'breve',
        ]);

        // El store se acota por el usuario autenticado: el atacante crea/
        // actualiza SU propia preferencia, nunca la del dueño.
        $this->actingAs($attacker)
            ->postJson(route('chat.preferences.store'), [
                'key' => 'response_length',
                'value' => 'detallada',
                'consented' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('chat_user_preferences', [
            'user_id' => $owner->id,
            'key' => 'response_length',
            'value' => 'breve',
        ]);
        $this->assertDatabaseHas('chat_user_preferences', [
            'user_id' => $attacker->id,
            'key' => 'response_length',
            'value' => 'detallada',
        ]);
    }

    public function test_super_admin_cannot_read_or_delete_another_users_preference(): void
    {
        $owner = $this->user();
        $superAdmin = $this->user(Role::SuperAdmin);

        ChatUserPreference::factory()->create([
            'user_id' => $owner->id,
            'key' => 'response_length',
            'value' => 'breve',
        ]);

        // El Gate::before global aprueba a super_admin, pero el servicio acota
        // cada consulta por $user->id: no existe un id crudo que manipular.
        $this->actingAs($superAdmin)
            ->getJson(route('chat.preferences.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($superAdmin)
            ->deleteJson(route('chat.preferences.destroy', ['key' => 'response_length']))
            ->assertNotFound();

        $this->assertDatabaseHas('chat_user_preferences', [
            'user_id' => $owner->id,
            'key' => 'response_length',
        ]);
    }

    public function test_hardened_all_omits_a_key_removed_from_the_whitelist(): void
    {
        $user = $this->user();
        $memory = app(ConversationMemoryService::class);

        ChatUserPreference::factory()->create([
            'user_id' => $user->id,
            'key' => 'response_length',
            'value' => 'breve',
        ]);

        // Clave presente mientras está en la lista blanca.
        $this->assertArrayHasKey('response_length', $memory->all($user));

        // Se retira la clave del catálogo en caliente: la fila sigue en BD pero
        // el read-path la re-filtra y ya no aflora al modelo/UI.
        config(['chat.preference_keys' => [
            'explanation_style' => ['paso_a_paso', 'resumido'],
        ]]);

        $this->assertArrayNotHasKey('response_length', $memory->all($user));

        // El endpoint hereda el mismo endurecimiento.
        $this->actingAs($user)
            ->getJson(route('chat.preferences.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_denial_log_carries_no_rejected_value(): void
    {
        $user = $this->user();
        $memory = app(ConversationMemoryService::class);

        $secret = 'valor-clinico-rechazado-que-no-debe-loguearse';

        Log::spy();

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
