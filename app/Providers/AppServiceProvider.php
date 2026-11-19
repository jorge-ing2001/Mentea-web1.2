<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\ChatConversation;
use App\Models\Professional;
use App\Policies\AppointmentPolicy;
use App\Policies\ChatConversationPolicy;
use App\Policies\ProfessionalPolicy;
use App\Services\Chat\Providers\AiProvider;
use App\Services\Chat\Providers\AiProviderManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El agente depende de la interfaz AiProvider; el manager elige la
        // implementación concreta (Ollama por defecto, OpenRouter si se
        // configura) según config('ai.provider').
        $this->app->bind(
            AiProvider::class,
            fn ($app) => $app->make(AiProviderManager::class)->provider()
        );
    }

    public function boot(): void
    {
        // Forzar HTTPS en producción
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Permitir todas las acciones al superadministrador
        Gate::before(function ($user, $ability) {
            return method_exists($user, 'hasRole')
                && $user->hasRole('super_admin')
                    ? true
                    : null;
        });

        // Registrar políticas de autorización
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(Professional::class, ProfessionalPolicy::class);
        Gate::policy(ChatConversation::class, ChatConversationPolicy::class);
    }
}
