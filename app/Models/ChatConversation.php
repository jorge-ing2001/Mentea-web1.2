<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'title',
        'summary',
        'context_metadata',
        'status',
        'last_activity_at',
        'last_message_at',
        'consent_captured_at',
        'crisis_flagged_at',
        'escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'context_metadata' => 'array',
            'last_activity_at' => 'datetime',
            'last_message_at' => 'datetime',
            'consent_captured_at' => 'datetime',
            'crisis_flagged_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    /**
     * Conversaciones de un usuario concreto.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Conversaciones activas.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Conversaciones archivadas.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', 'archived');
    }

    /**
     * Orden descendente por última actividad del mensaje (y luego id) para la
     * lista de conversaciones.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('last_message_at')->orderByDesc('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * Última intención "pendiente" (oferta de cupos o de reprogramación)
     * registrada en el meta del último mensaje del asistente. Permite que un
     * turno de seguimiento ("la primera", "las 9:00", "sí, confirmo") se
     * resuelva a un profesional+fecha+hora concretos.
     *
     * @return array<string,mixed>|null
     */
    public function pendingAction(): ?array
    {
        /** @var ChatMessage|null $last */
        $last = $this->messages()
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        if ($last === null || ! is_array($last->meta)) {
            return null;
        }

        $pending = $last->meta['pending'] ?? null;

        return is_array($pending) ? $pending : null;
    }

    /**
     * Últimos N mensajes user/assistant en orden cronológico para la ventana
     * de memoria que se envía al modelo.
     */
    public function recentHistory(int $window): array
    {
        return $this->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit($window)
            ->get()
            ->sortBy('id')
            ->map(fn (ChatMessage $m) => [
                'role' => $m->role,
                'content' => $m->content,
            ])
            ->values()
            ->all();
    }
}
