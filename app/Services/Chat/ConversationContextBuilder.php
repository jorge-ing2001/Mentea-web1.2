<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\User;

/**
 * Ensambla el arreglo messages[] que recibe el proveedor de IA, de forma
 * PROVIDER-INDEPENDIENTE (ni Ollama ni OpenRouter saben nada de esto; reciben
 * el arreglo ya construido, como hoy).
 *
 * El orden de los bloques es el del diseño §2.4 y NO debe alterarse:
 *
 *   1. Prompt de sistema del agente (systemPrompt() de ChatAgentService).
 *   2. Resumen no sensible de identidad/rol/permisos del usuario AUTENTICADO
 *      (solo sus propios datos; nunca datos clínicos de terceros — regla 8).
 *   3. Recordatorio de reglas de seguridad.
 *   4. Resumen de la conversación (chat_conversations.summary) cuando existe.
 *   5. Mensajes recientes relevantes (últimos N, configurable: context_window).
 *   6. Bloque RAG de KnowledgeBase (solo cuando hay coincidencias).
 *   7. Instrucción determinista + mensaje actual del usuario.
 *
 * Reparto del presupuesto (config('chat.context_token_budget')): se recorta
 * PRIMERO la ventana reciente (los mensajes más antiguos se descartan), nunca
 * los bloques de sistema/seguridad ni el mensaje actual. Así el prompt se
 * mantiene acotado para el modelo local (qwen3:8b) sin reenviar todo el
 * historial en cada turno.
 *
 * Importante: el bloque (7) conserva BYTE A BYTE la instrucción
 * "...usando EXACTAMENTE la siguiente información verificada..." y el mensaje
 * del usuario tal cual, para que la guardia anti-alucinación de
 * ChatAgentService siga funcionando igual.
 */
class ConversationContextBuilder
{
    public function __construct(
        private KnowledgeBase $knowledge,
    ) {}

    /**
     * Construye el arreglo messages[] en el orden exacto de §2.4.
     *
     * @return array<int,array{role:string,content:string}>
     */
    public function build(
        ChatConversation $conversation,
        string $userMessage,
        string $deterministic,
        string $systemPrompt,
        ?User $user = null,
    ): array {
        // ---- Bloques fijos de sistema/seguridad (nunca se recortan) --------
        $head = [];

        // (1) Prompt del agente.
        $head[] = ['role' => 'system', 'content' => $systemPrompt];

        // (2) Identidad/rol/permisos del usuario autenticado (no sensible).
        $identity = $this->identityBlock($user);
        if ($identity !== '') {
            $head[] = ['role' => 'system', 'content' => $identity];
        }

        // (3) Recordatorio de reglas de seguridad.
        $head[] = ['role' => 'system', 'content' => $this->securityReminder()];

        // (4) Resumen de la conversación, si existe.
        $summary = is_string($conversation->summary) ? trim($conversation->summary) : '';
        if ($summary !== '') {
            $head[] = [
                'role' => 'system',
                'content' => 'Resumen de la conversación hasta ahora (contexto previo, no lo repitas literalmente): '
                    .$summary,
            ];
        }

        // ---- Bloque RAG (solo si hay coincidencias) ------------------------
        $knowledgeBlock = $this->knowledge->contextBlock($userMessage);
        $rag = $knowledgeBlock !== ''
            ? [['role' => 'system', 'content' => $knowledgeBlock]]
            : [];

        // ---- Cola fija: instrucción determinista + mensaje actual ----------
        // Se conserva BYTE A BYTE respecto de la versión histórica de phrase()
        // para no alterar la guardia anti-alucinación.
        $tail = [
            [
                'role' => 'system',
                'content' => 'Responde al usuario en español, de forma cálida y neutra, usando EXACTAMENTE '
                    .'la siguiente información verificada (no inventes datos, horarios ni servicios): '
                    .$deterministic,
            ],
            ['role' => 'user', 'content' => $userMessage],
        ];

        // (5) Ventana reciente, recortada al presupuesto disponible.
        $window = $this->recentWindow($conversation);
        $window = $this->trimToBudget($window, $head, $rag, $tail);

        return array_merge($head, $window, $rag, $tail);
    }

    /**
     * Mensajes recientes (user/assistant) en orden cronológico, limitados a
     * config('chat.context_window'). Reemplaza al fijo recentHistory(12).
     *
     * @return array<int,array{role:string,content:string}>
     */
    private function recentWindow(ChatConversation $conversation): array
    {
        $window = (int) config('chat.context_window', config('chat.history_window', 12));

        if ($window < 1) {
            return [];
        }

        return $conversation->recentHistory($window);
    }

    /**
     * Recorta la ventana reciente (descartando los mensajes MÁS ANTIGUOS
     * primero) hasta que el tamaño total aproximado del prompt entra en el
     * presupuesto configurado. Nunca descarta los bloques de sistema/seguridad
     * ni el mensaje actual: si aun así no cabe, se envía la ventana vacía.
     *
     * @param  array<int,array{role:string,content:string}>  $window
     * @param  array<int,array{role:string,content:string}>  $head
     * @param  array<int,array{role:string,content:string}>  $rag
     * @param  array<int,array{role:string,content:string}>  $tail
     * @return array<int,array{role:string,content:string}>
     */
    private function trimToBudget(array $window, array $head, array $rag, array $tail): array
    {
        $budget = (int) config('chat.context_token_budget', 8000);

        if ($budget < 1) {
            return $window;
        }

        $fixedSize = $this->sizeOf($head) + $this->sizeOf($rag) + $this->sizeOf($tail);

        // Descartar desde el frente (más antiguo) mientras no quepa.
        while ($window !== [] && ($fixedSize + $this->sizeOf($window)) > $budget) {
            array_shift($window);
        }

        return $window;
    }

    /**
     * Tamaño aproximado (en caracteres) de un arreglo de mensajes.
     *
     * @param  array<int,array{role:string,content:string}>  $messages
     */
    private function sizeOf(array $messages): int
    {
        $total = 0;

        foreach ($messages as $message) {
            $total += mb_strlen((string) ($message['content'] ?? ''));
        }

        return $total;
    }

    /**
     * Bloque (2): resumen NO sensible de identidad/rol/permisos del usuario
     * AUTENTICADO. Solo datos propios y no clínicos (nombre de pila, rol,
     * estado de consentimiento). Para invitados devuelve cadena vacía. Nunca
     * incluye datos de otros pacientes (regla 8).
     */
    private function identityBlock(?User $user): string
    {
        if (! $user instanceof User) {
            return 'Usuario no autenticado (invitado). Solo información pública y general; '
                .'para operaciones sobre su cuenta debe iniciar sesión.';
        }

        $role = $this->roleLabel($user);
        $name = $this->firstName($user);

        $parts = ['Usuario autenticado'];

        if ($name !== '') {
            $parts[] = "nombre: {$name}";
        }

        $parts[] = "rol: {$role}";

        if ($user->isPatient()) {
            $parts[] = $user->hasInformedConsent()
                ? 'consentimiento informado: aceptado'
                : 'consentimiento informado: pendiente';
        }

        return 'Contexto del usuario (solo sus propios datos, no clínicos; respeta su rol y permisos): '
            .implode('; ', $parts).'.';
    }

    private function roleLabel(User $user): string
    {
        return match (true) {
            $user->isSuperAdmin() => 'super administrador',
            $user->isAdmin() => 'administrador',
            $user->isProfessional() => 'profesional',
            $user->isPatient() => 'paciente',
            default => 'usuario',
        };
    }

    private function firstName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));

        if ($name === '') {
            return '';
        }

        return explode(' ', $name)[0];
    }

    /**
     * Bloque (3): recordatorio compacto de las reglas de seguridad y del
     * principio de solo lectura. Es un refuerzo; las reglas duras se aplican
     * en PHP (ChatAgentService), no dependen del modelo.
     */
    private function securityReminder(): string
    {
        return 'Reglas de seguridad y privacidad: solo orientas (lectura), no ejecutas operaciones; '
            .'usas EXCLUSIVAMENTE la información verificada del sistema y la base de conocimiento; '
            .'no inventas datos, horarios, profesionales ni estados; no muestras datos de otros pacientes; '
            .'respetas el rol y los permisos del usuario autenticado; ante señales de crisis el protocolo '
            .'de seguridad lo aplica el sistema, no tú.';
    }
}
