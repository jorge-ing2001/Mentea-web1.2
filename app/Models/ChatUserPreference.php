<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferencia de interacción NO sensible y consentida de un usuario
 * (memoria de largo plazo, §2.8). Nunca almacena datos clínicos ni de
 * terceros: el filtrado a una lista blanca ocurre en
 * ConversationMemoryService (regla 7).
 */
class ChatUserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'key',
        'value',
        'consented_at',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
