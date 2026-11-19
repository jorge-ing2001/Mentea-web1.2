<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\Providers\AiProvider;
use Illuminate\Support\Collection;

/**
 * Resumen progresivo de la conversación, PROVIDER-INDEPENDIENTE.
 *
 * Cuando una conversación supera config('chat.summary_threshold_messages'),
 * condensa los turnos ANTIGUOS (los que quedan FUERA de la ventana reciente de
 * config('chat.context_window')) en chat_conversations.summary y lo actualiza
 * de forma incremental: NO re-resume todo el historial en cada turno, solo los
 * turnos nuevos que han salido de la ventana desde la última vez.
 *
 * Diseño §2.4/§2.7:
 *   - Usa AiProvider de forma agnóstica (chat()); ninguna lógica entra en los
 *     proveedores concretos.
 *   - Si el proveedor no está disponible o devuelve null, cae a un resumen
 *     determinista (truncar/concatenar) para que la función NUNCA se rompa ni
 *     añada latencia-en-fallo.
 *   - NUNCA altera meta.pending del último mensaje del asistente (no toca los
 *     mensajes; solo escribe la columna summary + marcas en context_metadata).
 */
class ConversationSummaryService
{
    public function __construct(
        private AiProvider $ai,
    ) {}

    /**
     * Mantiene el resumen de la conversación al día si procede. Devuelve el
     * resumen vigente (posiblemente sin cambios). Es idempotente y seguro de
     * llamar en cada turno: si no hay nada nuevo que condensar, no hace nada.
     */
    public function summarize(ChatConversation $conversation): ?string
    {
        if (! (bool) config('chat.summary_enabled', true)) {
            return $conversation->summary;
        }

        $threshold = max(1, (int) config('chat.summary_threshold_messages', 12));
        $window = max(0, (int) config('chat.context_window', config('chat.history_window', 12)));

        // IDs de los mensajes de diálogo en orden cronológico.
        $ids = $this->dialogueMessageIds($conversation);
        $total = $ids->count();

        if ($total < $threshold) {
            return $conversation->summary;
        }

        // Los turnos "antiguos" son todos menos la ventana reciente.
        $oldCount = max(0, $total - $window);

        if ($oldCount === 0) {
            return $conversation->summary;
        }

        // Incremental: solo condensar los turnos antiguos que aún NO se han
        // resumido. context_metadata.summary_last_message_id marca el último
        // mensaje antiguo ya incorporado al resumen.
        $meta = is_array($conversation->context_metadata) ? $conversation->context_metadata : [];
        $lastSummarizedId = (int) ($meta['summary_last_message_id'] ?? 0);

        $oldIds = $ids->take($oldCount);
        $boundaryId = (int) $oldIds->last();

        // Nada nuevo salió de la ventana desde la última vez.
        if ($boundaryId <= $lastSummarizedId) {
            return $conversation->summary;
        }

        $newOldMessages = ChatMessage::query()
            ->where('chat_conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'assistant'])
            ->where('id', '>', $lastSummarizedId)
            ->where('id', '<=', $boundaryId)
            ->orderBy('id')
            ->get();

        if ($newOldMessages->isEmpty()) {
            return $conversation->summary;
        }

        $existing = is_string($conversation->summary) ? trim($conversation->summary) : '';
        $summary = $this->buildSummary($existing, $newOldMessages);

        $meta['summary_last_message_id'] = $boundaryId;

        // forceFill + save escribe SOLO la conversación; no toca ningún
        // chat_message, por lo que meta.pending del último assistant permanece
        // intacto.
        $conversation->forceFill([
            'summary' => $summary,
            'context_metadata' => $meta,
        ])->save();

        return $summary;
    }

    /**
     * IDs de los mensajes de diálogo (user/assistant) en orden cronológico.
     *
     * @return Collection<int,int>
     */
    private function dialogueMessageIds(ChatConversation $conversation): Collection
    {
        return $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Construye el resumen combinando el resumen previo con los turnos antiguos
     * nuevos. Intenta el proveedor; si no hay respuesta, cae al determinista.
     *
     * @param  Collection<int,ChatMessage>  $messages
     */
    private function buildSummary(string $existing, Collection $messages): string
    {
        $transcript = $this->transcript($messages);

        $modelSummary = $this->summarizeWithProvider($existing, $transcript);

        $summary = $modelSummary ?? $this->deterministicSummary($existing, $messages);

        return $this->cap($summary);
    }

    /**
     * Resume con el proveedor de IA (agnóstico). Devuelve null si no está
     * disponible o la respuesta es vacía/nula; el llamador cae al determinista.
     */
    private function summarizeWithProvider(string $existing, string $transcript): ?string
    {
        if (! $this->ai->isAvailable()) {
            return null;
        }

        $system = 'Eres un asistente que resume conversaciones de soporte de una plataforma de salud mental. '
            .'Condensa en español, en pocas frases, los puntos clave, decisiones y temas tratados. '
            .'No inventes datos. No incluyas información clínica sensible ni datos de terceros. '
            .'Devuelve SOLO el resumen, sin preámbulos.';

        $user = ($existing !== ''
            ? "Resumen previo:\n{$existing}\n\nNuevos mensajes a incorporar:\n{$transcript}\n\n"
                .'Entrega un único resumen actualizado que integre lo previo con lo nuevo.'
            : "Mensajes a resumir:\n{$transcript}");

        $reply = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        if (! is_string($reply) || trim($reply) === '') {
            return null;
        }

        return trim($reply);
    }

    /**
     * Fallback determinista: concatena el resumen previo con una transcripción
     * truncada de los turnos nuevos. Nunca lanza ni consulta la red.
     *
     * @param  Collection<int,ChatMessage>  $messages
     */
    private function deterministicSummary(string $existing, Collection $messages): string
    {
        $lines = $messages->map(function (ChatMessage $m) {
            $who = $m->role === 'user' ? 'Usuario' : 'Asistente';
            $content = trim((string) $m->content);
            $content = $this->truncate($content, 200);

            return "{$who}: {$content}";
        })->implode(' ');

        $combined = $existing !== '' ? $existing.' '.$lines : $lines;

        return trim($combined);
    }

    /**
     * Transcripción compacta de los mensajes para el prompt del resumen.
     *
     * NOTA DELIBERADA (dato derivado nuevo y persistido): esta transcripción
     * envía al proveedor el contenido de los turnos user/assistant DE ESTA
     * MISMA conversación, igual que ya ocurre con la ventana de historial
     * reciente en cada turno. Nunca se incluye contenido de terceros ni de
     * otras conversaciones: $messages proviene exclusivamente de
     * dialogueMessageIds($conversation), acotado por chat_conversation_id. El
     * resultado (summary) es un dato DERIVADO que se persiste; el prompt de
     * sistema PIDE al modelo excluir información clínica sensible, pero el
     * filtrado sensible fuerte sigue siendo responsabilidad del protocolo en
     * PHP, no del modelo. Si en el futuro se requiere un filtro determinista
     * previo del contenido a resumir, este es el punto donde aplicarlo.
     *
     * @param  Collection<int,ChatMessage>  $messages
     */
    private function transcript(Collection $messages): string
    {
        return $messages->map(function (ChatMessage $m) {
            $who = $m->role === 'user' ? 'Usuario' : 'Asistente';

            return "{$who}: ".trim((string) $m->content);
        })->implode("\n");
    }

    private function cap(string $summary): string
    {
        $max = max(1, (int) config('chat.summary_max_chars', 1500));

        return $this->truncate(trim($summary), $max);
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max - 1)).'…';
    }
}
