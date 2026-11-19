{{--
    Widget flotante del agente conversacional de Mentea.

    Burbuja flotante + panel deslizable. Toda la lógica va inline con Alpine
    (x-data) para no depender del build de Vite. Postea a la ruta `chat.send`
    con el token CSRF del <meta name="csrf-token">. Muestra estado de carga y,
    ante cualquier error, un mensaje neutro en español (nunca una traza).
--}}
<div
    x-data="menteaChat()"
    x-cloak
    class="mentea-chat-widget"
    aria-live="polite"
>
    {{-- Panel de conversación --}}
    <div
        x-show="open"
        x-transition.opacity.duration.200ms
        class="mentea-chat-panel"
        role="dialog"
        aria-label="Asistente de Mentea"
    >
        <div class="mentea-chat-header">
            <div class="mentea-chat-header-title">
                <span class="mentea-chat-dot" aria-hidden="true"></span>
                <span>Asistente de Mentea</span>
                @if (config('app.debug'))
                    {{-- Indicador de proveedor solo para desarrollo (APP_DEBUG=true).
                         No se muestra en producción. Lee config('ai.provider'). --}}
                    <span class="mentea-chat-provider" title="Proveedor de IA activo (solo desarrollo)">{{ config('ai.provider') }}</span>
                @endif
            </div>
            <button type="button" class="mentea-chat-close" @click="open = false" aria-label="Cerrar chat">&times;</button>
        </div>

        <div class="mentea-chat-messages" x-ref="messages">
            <template x-for="(msg, idx) in messages" :key="idx">
                <div :class="msg.role === 'user' ? 'mentea-chat-msg mentea-chat-msg-user' : 'mentea-chat-msg mentea-chat-msg-bot'">
                    <span x-text="msg.content"></span>
                </div>
            </template>
            <div x-show="loading" class="mentea-chat-msg mentea-chat-msg-bot mentea-chat-typing">
                <span class="mentea-chat-typing-dot"></span>
                <span class="mentea-chat-typing-dot"></span>
                <span class="mentea-chat-typing-dot"></span>
            </div>
        </div>

        <form class="mentea-chat-input" @submit.prevent="send()">
            <input
                type="text"
                x-model="draft"
                :disabled="loading"
                maxlength="2000"
                placeholder="Escribe tu mensaje…"
                aria-label="Mensaje"
                autocomplete="off"
            >
            <button type="submit" :disabled="loading || draft.trim() === ''" aria-label="Enviar">Enviar</button>
        </form>
    </div>

    {{-- Burbuja flotante --}}
    <button
        type="button"
        class="mentea-chat-bubble"
        @click="toggle()"
        :aria-expanded="open.toString()"
        aria-label="Abrir asistente de Mentea"
    >
        <span x-show="!open" aria-hidden="true">💬</span>
        <span x-show="open" aria-hidden="true">&times;</span>
    </button>
</div>

<script>
function menteaChat() {
    return {
        open: false,
        loading: false,
        draft: '',
        greeted: false,
        messages: [],
        conversationId: null,

        // --- Persistencia (Etapa 1) -------------------------------------
        // La BASE DE DATOS es la fuente de verdad del historial. En
        // localStorage SOLO guardamos punteros ligeros de UI:
        //   - mentea_chat_cid  : id de la conversación activa
        //   - mentea_chat_open : panel abierto/cerrado
        //   - mentea_chat_draft: borrador sin enviar
        // Nunca se persiste el array `messages` (puede contener datos
        // clínicos y la verdad vive en el backend).
        storageKeys: {
            cid: 'mentea_chat_cid',
            open: 'mentea_chat_open',
            draft: 'mentea_chat_draft',
        },

        greeting: '¡Hola! Soy el asistente de Mentea. Puedo darte información sobre nuestros servicios de salud mental y ayudarte a agendar una cita. ¿En qué puedo ayudarte hoy?',

        // Lectura tolerante a fallos de localStorage (modo privado, cuotas…).
        readStore(key) {
            try { return window.localStorage.getItem(key); } catch (e) { return null; }
        },
        writeStore(key, value) {
            try {
                if (value === null || value === undefined) {
                    window.localStorage.removeItem(key);
                } else {
                    window.localStorage.setItem(key, value);
                }
            } catch (e) { /* almacenamiento no disponible: se ignora */ }
        },

        // Al montar: restaura estado de UI + conversación activa e HIDRATA el
        // historial desde el backend. No se crea una conversación nueva de
        // forma automática: se abre la almacenada. El saludo inicial solo se
        // inserta cuando de verdad no hay historial.
        init() {
            this.open = this.readStore(this.storageKeys.open) === '1';
            this.draft = this.readStore(this.storageKeys.draft) || '';

            const storedCid = this.readStore(this.storageKeys.cid);
            this.conversationId = storedCid ? parseInt(storedCid, 10) || null : null;

            // Persiste cambios de UID de forma reactiva.
            this.$watch('open', (value) => this.writeStore(this.storageKeys.open, value ? '1' : '0'));
            this.$watch('draft', (value) => this.writeStore(this.storageKeys.draft, value));
            this.$watch('conversationId', (value) => this.writeStore(this.storageKeys.cid, value ? String(value) : null));

            this.hydrate();
        },

        // Trae el historial de la conversación activa desde la BD. Si no hay
        // conversación almacenada o la petición falla (p. ej. invitado sin
        // acceso al endpoint), se cae con elegancia al saludo inicial.
        async hydrate() {
            if (this.conversationId === null) {
                this.ensureGreeting();
                return;
            }

            try {
                const res = await fetch('{{ url('chat/conversations') }}/' + this.conversationId, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!res.ok) { throw new Error('http ' + res.status); }

                const data = await res.json();
                const conv = (data && data.data) ? data.data : null;
                const history = (conv && Array.isArray(conv.messages)) ? conv.messages : [];

                this.messages = history.map((m) => ({ role: m.role, content: m.content }));

                if (this.messages.length === 0) {
                    this.ensureGreeting();
                } else {
                    this.greeted = true;
                }
                this.scrollDown();
            } catch (e) {
                // Puntero obsoleto o sin acceso: reinicia a una conversación
                // limpia en memoria sin romper la UI.
                this.conversationId = null;
                this.ensureGreeting();
            }
        },

        // Inserta el saludo solo cuando no hay historial alguno.
        ensureGreeting() {
            if (!this.greeted && this.messages.length === 0) {
                this.greeted = true;
                this.messages.push({ role: 'assistant', content: this.greeting });
            }
        },

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.ensureGreeting();
                this.scrollDown();
            }
        },
        scrollDown() {
            this.$nextTick(() => {
                const box = this.$refs.messages;
                if (box) { box.scrollTop = box.scrollHeight; }
            });
        },

        // Identificador único por mensaje saliente (Etapa 2). El backend es
        // idempotente sobre él: un reenvío/doble-submit con el mismo id NO crea
        // mensajes duplicados. Fallback para entornos sin crypto.randomUUID.
        newClientMessageId() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return 'cmid-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        },

        async send() {
            const text = this.draft.trim();
            if (text === '' || this.loading) { return; }

            // Mismo id para el intento y cualquier reintento del mismo mensaje:
            // garantiza idempotencia en el backend ante errores de red.
            const clientMessageId = this.newClientMessageId();

            this.messages.push({ role: 'user', content: text });
            this.draft = '';
            this.loading = true;
            this.scrollDown();

            const token = document.querySelector('meta[name="csrf-token"]');
            try {
                const res = await fetch('{{ route('chat.send') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        message: text,
                        conversation_id: this.conversationId,
                        client_message_id: clientMessageId,
                    }),
                });

                if (!res.ok) { throw new Error('http ' + res.status); }

                const data = await res.json();

                // La BD manda: fija/persiste el id de conversación devuelto.
                if (data && data.conversation_id) {
                    this.conversationId = data.conversation_id;
                }

                this.messages.push({
                    role: 'assistant',
                    content: (data && data.reply) ? data.reply : this.fallback(),
                });

                // Separa "debe iniciar sesión" de "debe aceptar consentimiento"
                // para mensajear al invitado correctamente.
                const flags = (data && data.flags) ? data.flags : {};
                if (flags.requires_login) {
                    this.messages.push({
                        role: 'assistant',
                        content: 'Puedes iniciar sesión o registrarte en {{ route('login') }} para agendar tu cita.',
                    });
                } else if (flags.requires_consent) {
                    this.messages.push({
                        role: 'assistant',
                        content: 'Recuerda aceptar el consentimiento informado desde tu perfil antes de agendar.',
                    });
                }
            } catch (e) {
                this.messages.push({ role: 'assistant', content: this.fallback() });
            } finally {
                this.loading = false;
                this.scrollDown();
            }
        },
        fallback() {
            return 'Lo siento, no pude procesar tu mensaje en este momento. Por favor inténtalo de nuevo en unos instantes.';
        },
    };
}
</script>
