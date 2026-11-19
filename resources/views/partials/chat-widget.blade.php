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
            <div class="mentea-chat-header-actions">
                {{-- Historial de conversaciones: solo útil para usuarios
                     autenticados (los endpoints exigen auth). Se oculta a
                     invitados para no mostrar un panel vacío. --}}
                @auth
                    <button
                        type="button"
                        class="mentea-chat-icon-btn"
                        @click="toggleHistory()"
                        :aria-expanded="showHistory.toString()"
                        aria-label="Ver conversaciones anteriores"
                        title="Conversaciones anteriores"
                    >&#9776;</button>
                @endauth
                <button type="button" class="mentea-chat-close" @click="open = false" aria-label="Cerrar chat">&times;</button>
            </div>
        </div>

        {{-- Historial de conversaciones (lista compacta, sin SPA). Se alimenta
             de GET chat.conversations.index; permite abrir una anterior, crear
             una nueva y eliminar con confirmación. --}}
        @auth
            <div x-show="showHistory" x-transition.opacity.duration.150ms class="mentea-chat-history" role="menu" aria-label="Conversaciones">
                <div class="mentea-chat-history-head">
                    <span>Tus conversaciones</span>
                    <button type="button" class="mentea-chat-newconv" @click="startNewConversation()" aria-label="Nueva conversación">+ Nueva</button>
                </div>
                <div x-show="historyLoading" class="mentea-chat-history-empty">Cargando…</div>
                <div x-show="!historyLoading && historyError" class="mentea-chat-history-empty mentea-chat-history-error" x-text="historyError"></div>
                <div x-show="!historyLoading && !historyError && conversations.length === 0" class="mentea-chat-history-empty">
                    Aún no tienes conversaciones guardadas.
                </div>
                <ul class="mentea-chat-history-list">
                    <template x-for="conv in conversations" :key="conv.id">
                        <li :class="conv.id === conversationId ? 'mentea-chat-history-item is-active' : 'mentea-chat-history-item'">
                            <button type="button" class="mentea-chat-history-open" @click="openConversation(conv.id)">
                                <span class="mentea-chat-history-title" x-text="conv.title || 'Conversación'"></span>
                                <span class="mentea-chat-history-date" x-text="formatDate(conv.last_message_at)"></span>
                            </button>
                            <button type="button" class="mentea-chat-history-del" @click="confirmDelete(conv.id)" aria-label="Eliminar conversación" title="Eliminar">&times;</button>
                        </li>
                    </template>
                </ul>
            </div>
        @endauth

        <div class="mentea-chat-messages" x-ref="messages" @scroll="onScroll()">
            {{-- Estado vacío explícito: conversación nueva sin mensajes. --}}
            <div x-show="!loading && messages.length === 0" class="mentea-chat-empty">
                Escribe tu primer mensaje para comenzar.
            </div>

            <template x-for="(msg, idx) in messages" :key="idx">
                <div :class="bubbleClass(msg)">
                    {{-- Mensajes del usuario: texto plano escapado (x-text, seguro).
                         Mensajes del asistente: Markdown SANITIZADO vía renderMarkdown()
                         (escape de HTML primero, luego subconjunto seguro). Nunca se
                         usa una ruta de innerHTML sin sanitizar. --}}
                    <template x-if="msg.role === 'user'">
                        <span x-text="msg.content"></span>
                    </template>
                    <template x-if="msg.role !== 'user'">
                        <span class="mentea-chat-md" x-html="renderMarkdown(msg.content)"></span>
                    </template>
                </div>
            </template>
            <div x-show="loading" class="mentea-chat-msg mentea-chat-msg-bot mentea-chat-typing" aria-label="El asistente está escribiendo">
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

        // Historial de conversaciones (UX/estados).
        showHistory: false,
        historyLoading: false,
        historyError: '',
        conversations: [],

        // Auto-scroll: solo se fuerza al final cuando el usuario ya está abajo;
        // si está revisando historial más arriba, se respeta su posición.
        stickToBottom: true,

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
                this.stickToBottom = true;
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
                this.stickToBottom = true;
                this.scrollDown();
            } else {
                this.showHistory = false;
            }
        },

        // Auto-scroll al último mensaje SOLO si el usuario está pegado abajo.
        scrollDown() {
            if (!this.stickToBottom) { return; }
            this.$nextTick(() => {
                const box = this.$refs.messages;
                if (box) { box.scrollTop = box.scrollHeight; }
            });
        },

        // Detecta si el usuario se separó del fondo para preservar su posición
        // mientras revisa el historial anterior.
        onScroll() {
            const box = this.$refs.messages;
            if (!box) { return; }
            const distance = box.scrollHeight - box.scrollTop - box.clientHeight;
            this.stickToBottom = distance < 40;
        },

        bubbleClass(msg) {
            return msg.role === 'user'
                ? 'mentea-chat-msg mentea-chat-msg-user'
                : 'mentea-chat-msg mentea-chat-msg-bot';
        },

        // --- Markdown seguro (UX 1) -------------------------------------
        // Enfoque de sanitización: ESCAPAR TODO el HTML primero (ningún tag
        // del modelo sobrevive) y después aplicar un subconjunto mínimo y
        // controlado de Markdown sobre el texto ya escapado:
        //   - negritas **texto**  -> <strong>
        //   - cursivas *texto*    -> <em>
        //   - enlaces [texto](url) -> <a> solo con href http/https,
        //     con rel="noopener noreferrer" y target="_blank"
        //   - listas con "- " / "* " -> <ul><li>
        //   - saltos de línea -> <br>
        // Como la entrada ya está escapada, no existe ninguna ruta de
        // innerHTML sin sanitizar: nunca se inyecta HTML del asistente.
        escapeHtml(text) {
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        renderMarkdown(raw) {
            let text = this.escapeHtml(raw == null ? '' : raw);

            // Enlaces [texto](url): solo se aceptan esquemas http(s). El texto
            // ya está escapado; la url se valida y se vuelve a escapar en el
            // atributo href.
            text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (match, label, url) => {
                const safeUrl = this.escapeHtml(url);
                return '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            });

            // Negritas y cursivas (sobre texto ya escapado).
            text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');

            // Listas simples: líneas que empiezan con "- " o "* ".
            const lines = text.split('\n');
            let html = '';
            let inList = false;
            for (const line of lines) {
                const item = line.match(/^\s*[-*]\s+(.*)$/);
                if (item) {
                    if (!inList) { html += '<ul>'; inList = true; }
                    html += '<li>' + item[1] + '</li>';
                } else {
                    if (inList) { html += '</ul>'; inList = false; }
                    html += line + '<br>';
                }
            }
            if (inList) { html += '</ul>'; }

            // Limpia el <br> sobrante antes de un bloque de lista o al final.
            return html.replace(/<br><\/ul>/g, '</ul>').replace(/<br>$/, '');
        },

        formatDate(iso) {
            if (!iso) { return ''; }
            try {
                const d = new Date(iso);
                return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short' })
                    + ' ' + d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
            } catch (e) { return ''; }
        },

        // --- Historial de conversaciones (UX/estados) -------------------
        toggleHistory() {
            this.showHistory = !this.showHistory;
            if (this.showHistory) { this.loadConversations(); }
        },

        async loadConversations() {
            this.historyLoading = true;
            this.historyError = '';
            try {
                const res = await fetch('{{ url('chat/conversations') }}', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!res.ok) { throw new Error('http ' + res.status); }
                const data = await res.json();
                this.conversations = (data && Array.isArray(data.data)) ? data.data : [];
            } catch (e) {
                this.historyError = 'No pudimos cargar tus conversaciones. Inténtalo de nuevo.';
                this.conversations = [];
            } finally {
                this.historyLoading = false;
            }
        },

        async openConversation(id) {
            if (id === this.conversationId) { this.showHistory = false; return; }
            this.conversationId = id;
            this.greeted = false;
            this.messages = [];
            this.showHistory = false;
            await this.hydrate();
        },

        async startNewConversation() {
            const token = document.querySelector('meta[name="csrf-token"]');
            this.historyError = '';
            try {
                const res = await fetch('{{ url('chat/conversations') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({}),
                });
                if (!res.ok) { throw new Error('http ' + res.status); }
                const data = await res.json();
                const conv = (data && data.data) ? data.data : null;
                this.conversationId = conv ? conv.id : null;
                this.greeted = false;
                this.messages = [];
                this.ensureGreeting();
                this.showHistory = false;
                this.stickToBottom = true;
                this.scrollDown();
            } catch (e) {
                this.historyError = 'No pudimos iniciar una conversación nueva.';
            }
        },

        // Confirmación explícita antes de un DELETE destructivo.
        async confirmDelete(id) {
            if (!window.confirm('¿Eliminar esta conversación? Esta acción no se puede deshacer.')) {
                return;
            }
            const token = document.querySelector('meta[name="csrf-token"]');
            try {
                const res = await fetch('{{ url('chat/conversations') }}/' + id, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!res.ok) { throw new Error('http ' + res.status); }
                this.conversations = this.conversations.filter((c) => c.id !== id);

                // Si se borró la activa, vuelve a una conversación limpia.
                if (id === this.conversationId) {
                    this.conversationId = null;
                    this.greeted = false;
                    this.messages = [];
                    this.ensureGreeting();
                }
            } catch (e) {
                this.historyError = 'No pudimos eliminar la conversación.';
            }
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
            this.stickToBottom = true;
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
