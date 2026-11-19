# Auditoría técnica y plan de memoria conversacional persistente — Mentea AI

> **Alcance de este documento.** Cubre exclusivamente la **Etapa 1 (auditoría técnica)** y la **Etapa 2 (diseño técnico)** solicitadas en el prompt maestro. **No se implementó ningún cambio**: no se crearon, modificaron ni eliminaron modelos, migraciones, servicios, rutas, vistas ni configuración de la aplicación. El único archivo escrito es este documento. La implementación (Etapas 4 a 6) queda a la espera de la aprobación explícita del usuario (Etapa 3).

Auditoría realizada contra el árbol de trabajo actual de `main` (incluye el PR #3 ya fusionado, que integró Ollama, la capa de proveedores, RAG y las herramientas de solo lectura).

---

## ETAPA 1 — Diagnóstico de la arquitectura actual

### Mapa de los componentes del chat (archivos reales verificados)

| Capa | Archivo | Rol real hoy |
|------|---------|--------------|
| Entrada HTTP | `app/Http/Controllers/ChatController.php` | Única acción `store()`; valida `message` (máx. 2000), resuelve la conversación, delega en `ChatAgentService::handle()`, responde JSON. Captura cualquier `Throwable` y degrada a mensaje neutro. |
| Orquestador | `app/Services/Chat/ChatAgentService.php` (~1430 líneas) | Aplica las reglas duras en PHP: crisis → escalado → consentimiento → cupos. Construye el contexto del modelo en `phrase()`. Perfil de solo lectura vía `config('chat.agent_transactional_enabled')`. |
| Contrato de proveedor | `app/Services/Chat/Providers/AiProvider.php` | Interfaz: `chat(array $messages): ?string`, `classify(string,string): ?string`, `isAvailable(): bool`, `name(): string`. |
| Proveedor local | `app/Services/Chat/Providers/OllamaProvider.php` | `POST {base}/api/chat` con `stream:false`, sin auth, lee `message.content`; disponibilidad por `GET /api/tags` → `/api/version`. Degrada a `null`. |
| Proveedor de pago | `app/Services/Chat/Providers/OpenRouterProvider.php` + `OpenRouterClient.php` (subclase delgada conservada) | Bearer + `/chat/completions`, lee `choices.0.message.content`. Inactivo por defecto. |
| Selección | `app/Services/Chat/Providers/AiProviderManager.php` | Elige por `config('ai.provider')`; valor vacío/desconocido cae a Ollama. |
| Binding | `app/Providers/AppServiceProvider.php` | `bind(AiProvider::class, …manager->provider())`. |
| Herramientas de escritura | `app/Services/Chat/ChatTools.php` | Adaptadores transaccionales sobre los servicios de negocio (gateados por la bandera transaccional). |
| Herramientas de lectura | `app/Services/Chat/ChatReadTools.php` | Solo lectura, con autorización por `Auth::user()`/rol forzada en el servidor. |
| RAG | `app/Services/Chat/KnowledgeBase.php` | Recupera 3–6 fragmentos de `docs/agente/base-conocimiento.csv`; nunca el CSV completo. |
| Enlaces | `app/Services/Chat/ChatLinks.php` | URLs internas verificadas con `Route::has`. |
| Modelos | `app/Models/ChatConversation.php`, `app/Models/ChatMessage.php` | Memoria de conversación ya persistida en BD. |
| Migraciones | `database/migrations/2026_09_29_100000_create_chat_conversations_table.php`, `…100001_create_chat_messages_table.php` | Tablas ya existentes. |
| Config | `config/ai.php`, `config/chat.php` | Proveedor, modelo, ventana de historial (12), timeout, copys, crisis. |
| Ruta | `routes/web.php` | `POST /chat` → `chat.send`, middleware `web` + `throttle:30,1`. |
| Frontend | `resources/views/partials/chat-widget.blade.php` + `public/css/mentea.css` (líneas 800–990) | Widget flotante Alpine. Estado 100 % en memoria del navegador. |
| Montaje del widget | `layouts/app.blade.php:37`, `layouts/guest.blade.php:31`, `welcome.blade.php:126` | Incluido vía `@include('partials.chat-widget')` en cada layout/página. |

### Respuestas a los 10 puntos del entregable de auditoría

**1. Cómo funciona actualmente el chat.**
El widget (`chat-widget.blade.php`) hace `fetch` por `POST` a `route('chat.send')` con el token CSRF del `<meta name="csrf-token">` y el cuerpo `{ message }`. `ChatController::store()` valida el mensaje, resuelve una `ChatConversation` y llama a `ChatAgentService::handle($conversation, $message, $user)`. El servicio persiste el turno del usuario, evalúa en orden las reglas duras (crisis → escalado → consentimiento/orientación/transaccional), calcula un texto determinista y, si el proveedor está disponible, lo pasa a `phrase()` para que el modelo **solo lo redacte** (con guardia anti-alucinación que rechaza horas/fechas/nombres no presentes en el texto verificado). Devuelve `{ reply, conversation_id, flags, slots }`. El modelo nunca decide reglas ni datos: "el código manda, el modelo solo redacta".

**2. Cómo se conservan actualmente los mensajes.**
Hay **dos niveles de persistencia, desconectados entre sí**:

- *Backend (sí persiste).* `ChatAgentService::persist()` escribe cada turno (`user` y `assistant`) en `chat_messages` vía la relación `ChatConversation::messages()`, con `role`, `content` y `meta` (JSON: `pending`, `reason`, `appointment_id`, etc.). La conversación se marca `last_activity_at`. Esto ya es memoria de conversación en base de datos.
- *Frontend (no persiste).* El array `messages` vive solo en el estado Alpine (`x-data="menteaChat()"`). El saludo inicial se inserta en cliente (`greeted`), **no** se lee del backend. **No existe ningún endpoint ni llamada que recupere el historial guardado**; el controlador solo expone `store()`.

Consecuencia: la BD tiene el historial, pero el frontend nunca lo lee, así que para el usuario el historial es efímero.

**3. Qué ocurre cuando el usuario cambia de página.**
El widget se reinstancia en cada carga de página (está en el layout, y la navegación es multipágina Blade, sin SPA). Al reinstanciarse, `menteaChat()` arranca con `messages: []`, `open: false`, `greeted: false`. **La ventana del chat se pierde visualmente**; el historial en BD sigue intacto pero no se muestra. El usuario ve un chat vacío.

**4. Qué ocurre cuando minimiza o cierra el asistente.**
Minimizar/cerrar solo cambia `open = false` (`@click="open = false"` y `toggle()`). Mientras **no** recargue la página, el array `messages` en memoria se conserva y al reabrir ve lo mismo. Pero no hay persistencia de `open` ni del borrador: cualquier recarga posterior lo pierde (ver punto 5).

**5. Qué ocurre al actualizar el navegador.**
Recarga total del estado Alpine: `messages` vuelve a `[]` y `greeted` a `false`. **Se pierde toda la conversación visible.** No hay `localStorage`, ni endpoint de recuperación, ni hidratación desde el servidor. El historial en BD queda huérfano respecto a la UI.

**6. Qué información se envía actualmente a Ollama.**
Solo cuando `AiProvider::isAvailable()` es verdadero, en `ChatAgentService::phrase()` se arma el arreglo `messages`:
1. `system`: prompt del agente (`systemPrompt()`, override por `config('chat.system_prompt')`).
2. `system`: bloque RAG `KnowledgeBase::contextBlock($userMessage)` (3–6 fragmentos), **solo si hay coincidencias**.
3. Historial reciente: `ChatConversation::recentHistory(config('chat.history_window', 12))` → los últimos 12 mensajes `user`/`assistant` en orden cronológico (solo `role` + `content`).
4. `system`: instrucción de redactar en español usando **exactamente** el texto determinista calculado.
5. `user`: el mensaje actual.

Para el clasificador de crisis (capa 3, opcional) se envía `classify(prompt, message)` sin historial. **Ya existe, por tanto, una forma primitiva de memoria contextual** (ventana fija de 12 mensajes), pero **sin resumen, sin selección por relevancia y sin límite de tokens**: con conversaciones largas la ventana crece hasta 12 turnos completos, lo que para `qwen3:8b` en ~4 GB de VRAM puede ser costoso.

**7. Qué funcionalidades pueden reutilizarse.**
Mucho. El PR #3 ya dejó buena parte de la arquitectura de memoria:
- **Tablas y modelos** `chat_conversations` / `chat_messages` (reutilizables; faltan columnas; ver Etapa 2).
- `ChatConversation::recentHistory()` y `pendingAction()` (base de "últimos mensajes" y "contexto pendiente").
- La **abstracción de proveedores** (`AiProvider` + manager + binding) hace que la memoria sea trivialmente independiente del proveedor: toda la memoria vive en Laravel/BD, nunca en el proveedor.
- `KnowledgeBase` (RAG) y `ChatReadTools` (lectura autorizada por rol) ya aportan el bloque 6 del orden de contexto solicitado.
- `ChatController::store()` + ruta `chat.send` + CSRF + throttle como patrón de endpoint.
- El patrón de autorización forzada por servidor (`Auth::id()`, ignorar identificadores del modelo/usuario) de `ChatReadTools` es el modelo a replicar para las políticas de conversación.
- El widget Alpine inline (sin dependencia de Vite) y el CSS `mentea-*` como base visual.

**8. Qué componentes necesitan modificaciones.**
- `chat_conversations`: faltan `title`, `summary`, `context_metadata`, `last_message_at` (hoy existe `last_activity_at`, semánticamente cercano pero distinto).
- `ChatConversation` / `ChatMessage`: nuevos `$fillable`/`casts`, scopes, relación con el usuario reforzada.
- `ChatController`: una sola acción `store()`; necesita endpoints de lectura/gestión (listar, mostrar, nueva, archivar, eliminar) y aceptar un `conversation_id` explícito en el envío.
- `resolveConversation()`: hoy fuerza **una sola** conversación activa por usuario (`firstOrCreate(['user_id','status'=>'active'])`); el requisito de "iniciar conversaciones independientes" y "consultar anteriores" exige soportar múltiples conversaciones y selección por id (con autorización).
- `ChatAgentService::phrase()`: sustituir la ventana fija de 12 por un constructor de contexto con resumen + selección + límite de tokens.
- `chat-widget.blade.php` + JS Alpine: hidratación desde backend, persistencia del `conversation_id` activo y estado de UI en `localStorage`, render de Markdown, diferenciación visual reforzada, manejo de respuestas largas, deduplicación de envíos.
- `config/chat.php`: nuevas claves de límites de tokens/resumen/preferencias.

**9. Qué archivos nuevos sería necesario crear.**
- Migraciones: `ALTER` de `chat_conversations` (añadir columnas) y, para preferencias de largo plazo, una tabla nueva `chat_user_preferences` (o `user_memories`).
- Servicios provider-independientes (nombres orientativos, ver Etapa 2): `ConversationManager`, `ConversationRepository`, `ConversationContextBuilder`, `ConversationSummaryService`, `ConversationMemoryService` (preferencias), reutilizando lo existente.
- Política `ChatConversationPolicy` (+ registro en `AppServiceProvider`).
- `FormRequest`(s) para los nuevos endpoints.
- Vistas/recursos de la UI de historial (lista de conversaciones) y, posiblemente, un `ChatConversationResource` (API resource) para serializar de forma segura.
- Factories (`ChatConversationFactory`, `ChatMessageFactory`) y pruebas de feature nuevas.

**10. Qué riesgos de regresión existen.**
- **Cambio de `resolveConversation()`**: pasar de "una activa por usuario" a "múltiples + por id" puede romper `ChatEndpointTest::test_authenticated_patient_receives_json_reply` (que asume `assertDatabaseHas('chat_conversations', ['user_id'=>…])`) y los tests de flujo que dependen de `pendingAction()` sobre la última conversación.
- **Invitados**: hoy la conversación de invitado se llavea por `session_id`. La memoria persistente exige "usuario autenticado" (Etapa 2 del prompt); hay que decidir explícitamente qué pasa con invitados sin romper `ChatWidgetVisibilityTest` ni `test_guest_can_chat_without_server_error`.
- **Guardia anti-alucinación y reglas duras**: cualquier recorte/resumen del historial no debe alterar el orden crisis → escalado → consentimiento → cupos ni `pendingAction()` (los seguimientos "la primera"/"las 9:00" dependen del `meta.pending` del último mensaje del asistente).
- **Contexto para qwen3:8b**: un resumen mal dimensionado puede aumentar latencia en vez de reducirla.
- **Exposición de datos**: nuevos endpoints de lectura son superficie de IDOR; sin política estricta, un usuario podría leer conversaciones ajenas cambiando el id.
- **Markdown en el frontend**: introducir un render de Markdown sin sanitización abre XSS; el contenido del asistente ya es texto plano hoy (`x-text`, seguro) y pasar a HTML exige escape/sanitización.
- **Duplicación de mensajes en reintentos**: hoy no hay idempotencia; un doble `submit`/reintento de red crea mensajes duplicados en `chat_messages`.
- **Migraciones**: deben ser reversibles y compatibles con datos existentes (columnas nuevas `nullable`).

---

## ETAPA 2 — Diseño técnico propuesto

Principio rector conservado: **el código manda, el modelo solo redacta**; la memoria vive en Laravel + base de datos, **nunca** en el proveedor de IA ni en el navegador como fuente oficial. Diseño **reuse-first**: primero extender lo que ya existe.

### 2.1 Esquema de base de datos (propuesto vs. existente)

**`chat_conversations` — EXTENDER la tabla existente (no crear una nueva).**

| Campo solicitado | Estado actual | Acción |
|------------------|---------------|--------|
| `id` | existe | — |
| `user_id` | existe (`nullable`, FK `nullOnDelete`, indexado) | conservar |
| `title` | **falta** | añadir `string, nullable` (derivable del primer mensaje) |
| `summary` | **falta** | añadir `text, nullable` (resumen progresivo) |
| `status` | existe (`string default 'active'`) | reutilizar; formalizar valores `active`/`archived` |
| `context_metadata` | **falta** | añadir `json, nullable` (módulo actual, tema, flags) |
| `created_at`/`updated_at` | existen | — |
| `last_message_at` | parcial: existe `last_activity_at` | reutilizar `last_activity_at` como equivalente, **o** añadir `last_message_at` y migrar; recomendación: añadir `last_message_at nullable` y mantener `last_activity_at` para no romper código existente |

Columnas extra ya presentes y conservadas: `session_id`, `consent_captured_at`, `crisis_flagged_at`, `escalated_at`. Índices actuales (`user_id`, `session_id`) se conservan; añadir índice compuesto `(user_id, status, last_message_at)` para la lista de conversaciones.

**`chat_messages` — ya cumple el esquema solicitado.**

| Campo solicitado | Estado actual |
|------------------|---------------|
| `id` | existe |
| `conversation_id` | existe como `chat_conversation_id` (FK `cascadeOnDelete`, index `(chat_conversation_id, created_at)`) |
| `role` | existe (`user`/`assistant`/`system`/`tool`) |
| `content` | existe (`text`) |
| `metadata` | existe como `meta` (`json nullable`) |
| `created_at`/`updated_at` | existen |

No requiere nuevas columnas salvo, para idempotencia de reintentos, un `client_message_id` (`string nullable`, index único por conversación) opcional. El orden cronológico ya está garantizado por `id`/`created_at`.

**Tabla nueva `chat_user_preferences` (memoria de largo plazo, Etapa 5).**
Campos propuestos: `id`, `user_id` (FK `cascadeOnDelete`), `key` (string), `value` (text/json), `consented_at` (timestamp nullable), `created_at`, `updated_at`; único `(user_id, key)`. Solo preferencias **no sensibles** y **autorizadas** (p. ej. "respuestas breves", "explicación paso a paso"). Precedente en el repo: `users.preferred_theme` ya guarda una preferencia de UI.

Todas las migraciones: **reversibles** (`down()` elimina columnas/tablas añadidas), columnas nuevas **`nullable`** para compatibilidad con datos existentes.

### 2.2 Relaciones Eloquent

- `User hasMany ChatConversation` (nueva relación `chatConversations()`; hoy no existe en `User`).
- `ChatConversation belongsTo User` (ya existe) + `hasMany ChatMessage` (ya existe) + `hasOne` último mensaje para la lista.
- `ChatMessage belongsTo ChatConversation` (ya existe, FK `chat_conversation_id`).
- `User hasMany ChatUserPreference` (nueva).
- Scopes nuevos en `ChatConversation`: `scopeForUser`, `scopeActive`, `scopeArchived`, `scopeRecent` (orden por `last_message_at`).

### 2.3 Políticas de autorización (regla no negociable: servidor, no frontend)

Nueva `ChatConversationPolicy` registrada en `AppServiceProvider::boot()` junto a las existentes:
- `view`, `update`, `archive`, `delete`, `addMessage`: todas exigen `conversation.user_id === $user->id`.
- Los endpoints resuelven la conversación **siempre** con `->where('user_id', Auth::id())` (patrón idéntico a `ChatReadTools`, que ya ignora cualquier id provisto por el modelo/usuario). Esto neutraliza IDOR por cambio de identificador.
- `super_admin` ya tiene `Gate::before` global; se evaluará si debe excluirse del acceso a conversaciones de pacientes para no mezclar roles (requisito 6 de §9). Recomendación: la política de conversaciones **no** concede acceso cruzado ni siquiera a admin/super_admin salvo necesidad institucional explícita y auditada.
- Logging de seguridad (requisito 10 de §9): registrar intentos denegados con `conversation_id` y `user_id`, **sin** volcar `content` clínico.

### 2.4 Servicios provider-independientes (mapeo a lo ya existente)

| Servicio propuesto | ¿Existe? | Plan |
|--------------------|----------|------|
| `ConversationRepository` | No (lógica dispersa en `ChatController::resolveConversation` y `ChatConversation`) | Nuevo: CRUD de consultas (listar por usuario, activa, por id con scope de usuario, crear, archivar, eliminar, deduplicar por `client_message_id`). |
| `ConversationManager` | No | Nuevo, delgado: orquesta "resolver/crear/cambiar conversación activa" sobre el repositorio; lo usan el controlador y el agente. |
| `ConversationContextBuilder` | Parcial: la lógica está embebida en `ChatAgentService::phrase()` (prompt + RAG + `recentHistory(12)` + determinista) | Extraer a un servicio con el orden exacto solicitado: (1) prompt del agente, (2) identidad/rol/permisos del usuario, (3) reglas de seguridad, (4) **resumen** de la conversación, (5) últimos mensajes relevantes, (6) RAG, (7) mensaje actual. Aplica límite de tokens configurable. |
| `ConversationSummaryService` | No | Nuevo: genera/actualiza `summary` cuando la conversación supera un umbral de mensajes/tokens, usando `AiProvider` (provider-agnóstico) con fallback determinista si no hay proveedor. |
| `ConversationMemoryService` | No | Nuevo: lee/escribe `chat_user_preferences` con control de consentimiento; expone consultar/actualizar/eliminar preferencias. |
| `ConversationAuthorizationService` | Cubierto por `ChatConversationPolicy` + patrón `ChatReadTools` | **No crear clase redundante**; usar la política y el patrón de Auth existente (regla §13 "no dupliques servicios"). |

Ninguna lógica de memoria entra en `OllamaProvider` ni en `OpenRouterProvider`: todos reciben solo el arreglo `messages` ya construido, como hoy.

### 2.5 Rutas / API

Añadir bajo el mismo middleware `web` (sesión + CSRF) y `throttle`, siguiendo el patrón de `chat.send`:

| Método | Ruta | Nombre | Acción |
|--------|------|--------|--------|
| `POST` | `/chat` | `chat.send` (existente) | aceptar `conversation_id` opcional + `client_message_id` para idempotencia |
| `GET` | `/chat/conversations` | `chat.conversations.index` | listar conversaciones del usuario (título, fecha, último mensaje, estado) |
| `GET` | `/chat/conversations/{conversation}` | `chat.conversations.show` | mensajes de UNA conversación (con política) |
| `POST` | `/chat/conversations` | `chat.conversations.store` | iniciar conversación nueva |
| `PATCH` | `/chat/conversations/{conversation}` | `chat.conversations.update` | archivar / renombrar |
| `DELETE` | `/chat/conversations/{conversation}` | `chat.conversations.destroy` | eliminación controlada (con confirmación en UI) |

Route-model binding con scope por usuario (o resolución manual con `where user_id`). No se rompe ninguna ruta existente.

### 2.6 Estrategia de persistencia en frontend

- **Mantener el widget en el layout** (`app.blade.php`, `guest.blade.php`, `welcome.blade.php`) como hoy; es la "estructura persistente equivalente" que pide el prompt. No se requiere SPA.
- **BD como fuente oficial.** Al montar, el widget llama a `chat.conversations.index`/`show` para **hidratar** el historial de la conversación activa.
- **`localStorage` solo como apoyo**: guardar el `conversation_id` activo y estado de UI (`open`, borrador). Nunca el historial.
- **Caso A (cambio de módulo) / Caso C (recarga):** al reinstanciarse, el widget lee el `conversation_id` de `localStorage` (o la última activa del backend) e hidrata los mensajes desde el servidor. No inicia conversación nueva automáticamente.
- **Caso B (minimizar):** solo alterna `open`; con `localStorage` el estado sobrevive incluso a recarga.
- **Caso D (re-login):** tras autenticarse, `index` devuelve las conversaciones del usuario según política de conservación.
- **Anti-duplicados:** generar `client_message_id` por envío; el backend ignora reintentos con el mismo id. Deshabilitar el botón mientras `loading` (ya se hace) y no reenviar en errores de red sin id.
- **Estados de carga/vacío/error** explícitos (ya hay `loading` y `fallback()`; ampliar a estado vacío y errores comprensibles).

### 2.7 Ventana de contexto + resumen (optimizado para qwen3:8b, ~4 GB VRAM)

- Reemplazar `recentHistory(12)` fijo por: **últimos N mensajes** (configurable, p. ej. 6–8) **+ `summary`** de lo anterior.
- `ConversationSummaryService` condensa los turnos antiguos cuando se supera un umbral (p. ej. 10–12 mensajes o X tokens), guardando el resultado en `chat_conversations.summary` y actualizándolo de forma incremental (no re-resumir todo en cada turno).
- **Límite de tokens configurable** (`config/chat.php`): tope para prompt + RAG + resumen + ventana, dejando margen para el modelo local. Evitar enviar indefinidamente todo el historial.
- Preservar intacto `meta.pending` del último mensaje del asistente para no romper los seguimientos de cupos.
- Continuidad temática (ejemplo "¿y dónde encuentro esa opción?"): el resumen + los últimos mensajes dan al modelo el referente del módulo mencionado sin pedir repetición.

### 2.8 Memoria de largo plazo con consentimiento

Diferenciación explícita de capas:
- **Conversación**: `chat_messages` (ya existe).
- **Sesión**: `localStorage` (id activo + UI) + `session_id` existente.
- **Contextual**: `summary` + `context_metadata` + ventana reciente.
- **Preferencias persistentes**: `chat_user_preferences`, **solo con consentimiento** (`consented_at`), solo datos no sensibles, nunca clínicos. Mecanismos de consultar/actualizar/eliminar. No convertir automáticamente cualquier dato en memoria permanente.

### 2.9 Las 10 reglas de seguridad/privacidad → puntos de aplicación concretos

| Regla §9 | Dónde se aplica |
|----------|-----------------|
| 1. Solo sus propias conversaciones | `ChatConversationPolicy::view` + scope `where user_id = Auth::id()` en todos los endpoints |
| 2. Sin IDOR por cambio de id | Resolución de conversación siempre con scope de usuario; binding con scope |
| 3. Pacientes no ven datos de otros | Patrón `ChatReadTools` (ya ignora ids ajenos) + política |
| 4. Profesionales solo lo autorizado | Reutilizar roles/permisos existentes en `ChatReadTools`/policies |
| 5. Admins según permisos institucionales | Política de conversación no concede acceso cruzado por defecto |
| 6. No mezclar conversaciones entre roles/usuarios | Clave `user_id` + ausencia de rutas de acceso cruzado |
| 7. No guardar clínico sensible como memoria permanente | `ConversationMemoryService` filtra a preferencias no sensibles + `consented_at` |
| 8. No enviar datos clínicos de terceros al modelo | Context builder solo usa datos del usuario autenticado + RAG público |
| 9. Validación en servidor, no en frontend | `FormRequest` + política + reglas duras en `ChatAgentService` (ya en PHP) |
| 10. Log de seguridad sin contenido clínico | Logging de denegaciones con ids/razón, sin `content` (patrón ya usado en `ChatController` y proveedores) |

El agente sigue siendo **solo lectura de orientación**: no crea/cancela/modifica citas, usuarios, roles, horarios ni registros. La memoria **no** amplía permisos. Crisis, consentimiento, privacidad y escalado permanecen en PHP (`ChatAgentService`), independientes del modelo.

### 2.10 Mejoras de UX del chat + latencia (prioridad del usuario)

- **Markdown**: renderizar en el frontend con un parser ligero y **sanitización** (o convertir en el backend a HTML seguro). Hoy se usa `x-text` (plano, seguro); el cambio exige escape para evitar XSS.
- **Indicador de escritura**: ya existe (`mentea-chat-typing`, tres puntos animados); conservar y conectarlo a `loading`.
- **Diferenciación visual usuario/asistente**: ya hay `mentea-chat-msg-user` vs. `mentea-chat-msg-bot` (CSS 907/913); reforzar contraste/alineación.
- **Respuestas largas**: limitar altura con scroll interno por burbuja y/o "ver más"; evitar que una respuesta extensa domine el panel.
- **Scroll automático** al último mensaje (ya hay `scrollDown()`) y **conservación de posición** al revisar historial anterior.
- **Latencia**: reducir el contexto enviado (resumen + ventana corta) para aliviar a qwen3:8b; evitar consultas N+1 (eager loading del último mensaje en la lista); deduplicar envíos; no re-resumir en cada turno; `isAvailable()` ya evita llamadas cuando no hay proveedor.

### 2.11 Archivos: reutilizar / modificar / crear

**Reutilizar tal cual:** `AiProvider`, `OllamaProvider`, `OpenRouterProvider`, `AiProviderManager`, binding en `AppServiceProvider`, `ChatTools`, `ChatReadTools`, `ChatLinks`, `KnowledgeBase`, `chat_messages` (tabla), reglas duras de `ChatAgentService`, CSS `mentea-*`.

**Modificar:** `chat_conversations` (migración `ALTER`), `ChatConversation`, `ChatMessage`, `User` (relaciones), `ChatController` (endpoints + `conversation_id`/idempotencia), `ChatAgentService` (extraer context builder, usar resumen), `config/chat.php` (límites/resumen/preferencias), `chat-widget.blade.php` + JS Alpine, `public/css/mentea.css` (ajustes de UX), `AppServiceProvider` (registrar política).

**Crear:** migraciones (`ALTER chat_conversations`, `create chat_user_preferences`, opcional `client_message_id`), `ConversationManager`, `ConversationRepository`, `ConversationContextBuilder`, `ConversationSummaryService`, `ConversationMemoryService`, `ChatConversationPolicy`, `FormRequest`(s), factories y pruebas de feature nuevas, recurso de UI de historial.

### 2.12 Plan de implementación por fases (Etapas 4–6 del prompt) — NO ejecutar aún

- **Fase 1 — Persistencia y modelo de datos.** Migración `ALTER` de `chat_conversations` (+`title`/`summary`/`context_metadata`/`last_message_at`), factories, relaciones y scopes. *Verificación:* migrar/rollback limpio; pruebas de persistencia (crear, recuperar, última activa, nueva, abrir anterior) con `RefreshDatabase`.
- **Fase 2 — Repositorio, manager y endpoints + política.** `ConversationRepository`/`ConversationManager`, rutas `index/show/store/update/destroy`, `ChatConversationPolicy`, idempotencia por `client_message_id`. *Verificación:* pruebas de seguridad (acceso a conversación ajena, cambio de id, aislamiento por usuario/rol) y de deduplicación.
- **Fase 3 — Context builder + resumen.** Extraer `ConversationContextBuilder` de `phrase()` con el orden de 7 bloques y límite de tokens; `ConversationSummaryService` con fallback. *Verificación:* pruebas de memoria contextual (preguntas de seguimiento, referencia a mensajes previos, conversación extensa recuperada vía resumen) con `Http::fake()` del proveedor.
- **Fase 4 — Frontend persistente.** Hidratación desde backend, `localStorage` para id/UI, Markdown sanitizado, diferenciación visual, respuestas largas, estados de carga/vacío/error, confirmación de borrado. *Verificación:* pruebas de navegación (cambio de módulo, minimizar, recarga, re-login) y de render del widget (ampliar `ChatWidgetVisibilityTest`).
- **Fase 5 — Memoria de largo plazo.** `chat_user_preferences` + `ConversationMemoryService` con consentimiento. *Verificación:* pruebas de consentimiento y de no persistir datos sensibles.
- **Fase 6 — Validación integral + documentación.** Suite completa (persistencia, navegación, memoria contextual, seguridad, compatibilidad Ollama/OpenRouter/sin proveedor/timeout). Instrucciones de migración, configuración y prueba. *Verificación:* `php artisan test` verde + `pint`.

Pruebas siempre con **datos ficticios**, nunca clínicos reales (requisito §11).

### 2.13 Riesgos de regresión a vigilar durante la implementación

- Reescritura de `resolveConversation()` afecta a `ChatEndpointTest` y a los flujos que dependen de `pendingAction()`.
- Política demasiado estricta/laxa sobre invitados y admins.
- Resumen que aumente latencia en lugar de reducirla en qwen3:8b.
- XSS al introducir Markdown; IDOR en los nuevos endpoints; duplicados en reintentos.
- Compatibilidad de migraciones con datos existentes (todo `nullable`, `down()` reversible).

---

## Cierre — Etapa 3 (aprobación)

La auditoría (Etapa 1) y el diseño técnico (Etapa 2) están completos y **no se modificó ningún archivo de la aplicación**. Hallazgo clave: el backend **ya persiste** la memoria de conversación en `chat_conversations`/`chat_messages`, pero el frontend **nunca la recupera** y no existen endpoints de lectura; por eso el usuario pierde el hilo al navegar, minimizar o recargar. El plan es **reuse-first**: extender las tablas y servicios existentes, mantener la memoria en Laravel/BD, preservar la abstracción de proveedores y todas las reglas duras de seguridad en PHP.

**La implementación (Etapas 4 a 6) queda a la espera de la aprobación explícita del usuario.** No se ejecutará ningún cambio de código, migración ni frontend hasta recibir esa autorización.
