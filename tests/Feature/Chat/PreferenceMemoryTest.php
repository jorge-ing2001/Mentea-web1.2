<?php

namespace Tests\Feature\Chat;

use App\Models\ChatUserPreference;
use App\Models\User;
use App\Services\Chat\ConversationMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreferenceMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ConversationMemoryService
    {
        return app(ConversationMemoryService::class);
    }

    public function test_preference_is_stored_only_after_consent_with_consented_at(): void
    {
        $user = User::factory()->create();

        $stored = $this->service()->set($user, 'response_length', 'breve', consented: true);

        $this->assertTrue($stored);
        $this->assertDatabaseHas('chat_user_preferences', [
            'user_id' => $user->id,
            'key' => 'response_length',
            'value' => 'breve',
        ]);

        $preference = ChatUserPreference::where('user_id', $user->id)->first();
        $this->assertInstanceOf(Carbon::class, $preference->consented_at);
    }

    public function test_setting_without_consent_is_refused(): void
    {
        $user = User::factory()->create();

        $stored = $this->service()->set($user, 'response_length', 'breve', consented: false);

        $this->assertFalse($stored);
        $this->assertDatabaseCount('chat_user_preferences', 0);
    }

    public function test_sensitive_or_clinical_key_is_rejected_and_nothing_persisted(): void
    {
        $user = User::factory()->create();

        $stored = $this->service()->set($user, 'diagnosis', 'trastorno de ansiedad', consented: true);

        $this->assertFalse($stored);
        $this->assertDatabaseCount('chat_user_preferences', 0);
        $this->assertNull($this->service()->get($user, 'diagnosis'));
    }

    public function test_disallowed_value_for_allowed_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $stored = $this->service()->set($user, 'response_length', 'medicacion diaria', consented: true);

        $this->assertFalse($stored);
        $this->assertDatabaseCount('chat_user_preferences', 0);
    }

    public function test_free_text_preference_is_bounded_and_rejected_when_too_long(): void
    {
        $user = User::factory()->create();

        $ok = $this->service()->set($user, 'preferred_topic', 'mindfulness', consented: true);
        $this->assertTrue($ok);
        $this->assertSame('mindfulness', $this->service()->get($user, 'preferred_topic'));

        $tooLong = str_repeat('a', 200);
        $rejected = $this->service()->set($user, 'preferred_topic', $tooLong, consented: true);
        $this->assertFalse($rejected);
        $this->assertSame('mindfulness', $this->service()->get($user, 'preferred_topic'));
    }

    public function test_get_and_all_are_scoped_to_the_owner(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->service()->set($alice, 'response_length', 'breve', consented: true);
        $this->service()->set($bob, 'response_length', 'detallada', consented: true);

        $this->assertSame('breve', $this->service()->get($alice, 'response_length'));
        $this->assertSame('detallada', $this->service()->get($bob, 'response_length'));

        $this->assertSame(['response_length' => 'breve'], $this->service()->all($alice));
        $this->assertSame(['response_length' => 'detallada'], $this->service()->all($bob));
    }

    public function test_user_cannot_read_another_users_preference(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->service()->set($bob, 'explanation_style', 'paso_a_paso', consented: true);

        $this->assertNull($this->service()->get($alice, 'explanation_style'));
        $this->assertSame([], $this->service()->all($alice));
    }

    public function test_forget_removes_a_key_only_for_the_owner(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->service()->set($alice, 'response_length', 'breve', consented: true);
        $this->service()->set($bob, 'response_length', 'detallada', consented: true);

        $this->assertTrue($this->service()->forget($alice, 'response_length'));

        $this->assertNull($this->service()->get($alice, 'response_length'));
        $this->assertSame('detallada', $this->service()->get($bob, 'response_length'));
        $this->assertFalse($this->service()->forget($alice, 'response_length'));
    }

    public function test_set_updates_existing_preference_and_refreshes_consent(): void
    {
        $user = User::factory()->create();

        $this->service()->set($user, 'response_length', 'breve', consented: true);
        $this->service()->set($user, 'response_length', 'detallada', consented: true);

        $this->assertDatabaseCount('chat_user_preferences', 1);
        $this->assertSame('detallada', $this->service()->get($user, 'response_length'));
    }

    public function test_user_chat_preferences_relation_works(): void
    {
        $user = User::factory()->create();
        ChatUserPreference::factory()->for($user)->create();
        ChatUserPreference::factory()->for($user)->explanationStyle()->create();
        ChatUserPreference::factory()->create();

        $this->assertCount(2, $user->fresh()->chatPreferences);
    }
}
