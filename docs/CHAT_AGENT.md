# Agente conversacional nativo en PHP (Mentea AI)

> Documento de diseño / contrato de arquitectura. Lo escribe FEAT-001 y lo
> implementan FEAT-002 (backend: config, migraciones, servicios, cliente
> OpenRouter, controlador) y FEAT-003 (widget Blade + Alpine y rutas).
>
> Idioma de cara al usuario: **español** (locale `es`). Todo texto visible debe
> estar en español, neutro y respetuoso de la privacidad.

## 0. Estado de implementación (PR #3)

El agente ya corre sobre una **capa de proveedores desacoplada** con **Ollama
local** como proveedor por defecto, una capa **ChatReadTools de solo lectura**
y **RAG** sobre la base de conocimiento institucional. Secciones nuevas:

- [§0.1 Arquitectura de proveedores de IA](#01-arquitectura-de-proveedores-de-ia)
- [§0.2 Diferencias Ollama vs OpenRouter](#02-diferencias-ollama-vs-openrouter)
- [§0.3 Configuración y variables de entorno](#03-configuracion-configai-php-y-variables-de-entorno)
- [§0.4 Ejecución local contra Ollama](#04-ejecucion-local-contra-ollama)
- [§0.5 Cómo cambiar de Ollama a OpenRouter (y viceversa)](#05-como-cambiar-de-ollama-a-openrouter-y-viceversa)
- [§0.6 Modelo de solo lectura: ChatReadTools, RAG y enlaces verificados](#06-modelo-de-solo-lectura-chatreadtools-rag-y-enlaces-verificados)
- [§0.7 Invariantes duros](#07-invariantes-duros)

Las secciones §1–§9 siguientes son el contrato de diseño original; se conservan
como referencia. Donde el diseño original citaba `OpenRouterClient` como único
cliente, ahora aplica la capa de proveedores de §0.1 (OpenRouter queda
conservado como un proveedor más, inactivo por defecto).

### 0.1 Arquitectura de proveedores de IA

El agente ya no depende de un cliente concreto. `ChatAgentService` depende de la
interfaz `App\Services\Chat\Providers\AiProvider`, y un manager elige la
implementación activa según `config('ai.provider')`.

```
ChatAgentService  (orquestador; reglas duras en PHP)
        │ depende de la interfaz
        ▼
AiProvider  (interfaz: chat / classify / isAvailable / name)
        ▲                         ▲
        │                         │
OllamaProvider            OpenRouterProvider
(local, por defecto)      (de pago, conservado e inactivo)
        ▲
        │  selección por config('ai.provider')
AiProviderManager  (factory; vacío/desconocido -> ollama)
```

Piezas:

- `app/Services/Chat/Providers/AiProvider.php`: interfaz con
  `chat(array $messages): ?string`, `classify(string $systemPrompt, string $message): ?string`,
  `isAvailable(): bool`, `name(): string`.
- `app/Services/Chat/Providers/OllamaProvider.php`: proveedor local. `POST {base}/api/chat`
  con cuerpo `{model, messages, stream:false}` y **sin** cabecera de
  autenticación; lee `message.content`. `isAvailable()` hace ping a
  `GET {base}/api/tags` (con respaldo `/api/version`). Usa el timeout
  configurable (`CHAT_HTTP_TIMEOUT`) porque `qwen3:8b` es lento.
- `app/Services/Chat/Providers/OpenRouterProvider.php`: proveedor de pago
  (refactor del antiguo `OpenRouterClient`). `POST {base}/chat/completions` con
  `Authorization: Bearer <key>`; lee `choices.0.message.content`.
  `isAvailable()` es `true` solo si hay API key.
- `app/Services/Chat/Providers/AiProviderManager.php`: factory que resuelve el
  proveedor por `config('ai.provider')`; un valor vacío o desconocido cae a
  `ollama` para no activar nunca un proveedor de pago por accidente.
- `app/Services/Chat/OpenRouterClient.php`: **conservado** como subclase fina
  de `OpenRouterProvider` (no se eliminó); las referencias y tests que
  type-hintean `OpenRouterClient` siguen funcionando.
- `app/Providers/AppServiceProvider.php`: `register()` enlaza
  `AiProvider::class` al proveedor que elige el manager.

Ambos proveedores usan `Illuminate\Support\Facades\Http`, así que `Http::fake()`
funciona en tests y ninguna prueba toca la red real. Ante timeout, HTTP 500,
error de conexión o respuesta inparseable, cada proveedor devuelve `null` (no
lanza excepción) y el orquestador cae a su ruta determinista.

### 0.2 Diferencias Ollama vs OpenRouter

| Aspecto | Ollama (por defecto) | OpenRouter (conservado, inactivo) |
|---|---|---|
| Endpoint de chat | `POST {base}/api/chat` | `POST {base}/chat/completions` |
| Autenticación | **Ninguna** (local, sin key) | `Authorization: Bearer <OPENROUTER_API_KEY>` |
| Cuerpo clave | `{model, messages, stream:false}` | `{model, messages}` (formato OpenAI) |
| Lectura de la respuesta | `message.content` | `choices.0.message.content` |
| `isAvailable()` | ping a `GET {base}/api/tags` (respaldo `/api/version`) | hay API key presente |
| Modelo por defecto | `qwen3:8b` | `openai/gpt-4o-mini` |
| Base URL por defecto | `http://localhost:11434` | `https://openrouter.ai/api/v1` |
| Costo / red | gratuito, local | de pago, nube |

### 0.3 Configuración (`config/ai.php`) y variables de entorno

`config/ai.php` es la fuente única de selección de proveedor:

```php
return [
    'provider' => env('AI_PROVIDER', 'ollama'),
    'ollama' => [
        'base_url'     => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model'        => env('OLLAMA_MODEL', 'qwen3:8b'),
        'endpoint'     => '/api/chat',
        'timeout'      => (int) env('CHAT_HTTP_TIMEOUT', 20),
        'ping_timeout' => (int) env('OLLAMA_PING_TIMEOUT', 3),
    ],
    'openrouter' => [
        'key'      => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model'    => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
    ],
];
```

El bloque `openrouter` lee las **mismas** variables `OPENROUTER_*` que
`config/services.php`; no se duplica la fuente de credenciales.

| Variable | Para | Valor por defecto |
|---|---|---|
| `AI_PROVIDER` | proveedor activo (`ollama` \| `openrouter`) | `ollama` |
| `OLLAMA_BASE_URL` | URL del Ollama local | `http://localhost:11434` |
| `OLLAMA_MODEL` | modelo local | `qwen3:8b` |
| `OLLAMA_PING_TIMEOUT` | timeout corto del ping `isAvailable()` (s) | `3` |
| `CHAT_HTTP_TIMEOUT` | timeout de la llamada de chat (s) | `20` |
| `OPENROUTER_API_KEY` | key de OpenRouter (solo si se usa ese proveedor) | *(vacío)* |
| `OPENROUTER_MODEL` | modelo de OpenRouter | `openai/gpt-4o-mini` |
| `OPENROUTER_BASE_URL` | base URL de OpenRouter | `https://openrouter.ai/api/v1` |

`.env.example` y `.env.production.example` traen `AI_PROVIDER=ollama`,
`OLLAMA_BASE_URL`, `OLLAMA_MODEL=qwen3:8b`, y mantienen `OPENROUTER_API_KEY`
**vacío**.

> Nota de pruebas: `phpunit.xml` fija `AI_PROVIDER=openrouter` para que la
> batería de tests del chat (que usa `Http::fake()` con respuestas de forma
> OpenAI) siga verde sin cambios. El valor por defecto en local y producción es
> `ollama`.

### 0.4 Ejecución local contra Ollama

1. Instala y arranca Ollama en tu PC y descarga el modelo:
   `ollama pull qwen3:8b` (se sirve en `http://localhost:11434`).
2. En `.env`: `AI_PROVIDER=ollama`, `OLLAMA_BASE_URL=http://localhost:11434`,
   `OLLAMA_MODEL=qwen3:8b`. Deja `OPENROUTER_API_KEY` vacío.
3. `php artisan config:clear` y levanta la app con `php artisan serve`.
4. Abre el widget de chat y conversa.

`qwen3:8b` es un modelo local y puede **tardar** varios segundos por turno. Si
ves timeouts, sube `CHAT_HTTP_TIMEOUT` (p. ej. `CHAT_HTTP_TIMEOUT=120`) y el
`max_execution_time` de PHP para `php artisan serve`
(`php -d max_execution_time=180 artisan serve`). Si Ollama está apagado o no
responde, el agente **no se cae**: usa su ruta determinista de seguridad (ver
§0.7), claramente distinguible de una respuesta del modelo.

### 0.5 Cómo cambiar de Ollama a OpenRouter (y viceversa)

Sin tocar código, solo `.env`:

- **A OpenRouter:** fija `AI_PROVIDER=openrouter` y provee una
  `OPENROUTER_API_KEY` válida (ver `docs/ACTIVAR_IA.md`). Ejecuta
  `php artisan config:clear`. `OpenRouterProvider::isAvailable()` solo es `true`
  con key presente; sin key, el agente cae a la ruta determinista.
- **De vuelta a Ollama:** fija `AI_PROVIDER=ollama` (o deja la variable vacía;
  cualquier valor desconocido también cae a `ollama`) y
  `php artisan config:clear`.

La API key **nunca** va en código, en `.env.example` ni en commits; vive solo en
el `.env` local, que está en `.gitignore`.

### 0.6 Modelo de solo lectura: ChatReadTools, RAG y enlaces verificados

El agente es de **solo lectura**: orienta, enseña y consulta información
autorizada, pero **nunca ejecuta escrituras**. Las operaciones (agendar,
cancelar, reprogramar, gestionar usuarios/roles/horarios) ocurren únicamente por
los módulos legítimos de Laravel; el agente explica el procedimiento y entrega
un enlace interno verificado para que **el usuario** lo realice.

- `app/Services/Chat/ChatReadTools.php`: solo métodos de lectura (getters /
  SELECT). No expone `book/cancel/reschedule/confirmOffer` ni abre
  transacciones de escritura. La identidad proviene de `Auth::user()` /
  `Auth::id()`, **nunca** de un id en el texto del usuario ni del modelo. Las
  consultas personales están forzadas al usuario autenticado: un id ajeno
  devuelve vacío, nunca datos de otro paciente. Las herramientas de
  administración están gateadas por rol (spatie / enum `role`).
- `app/Services/Chat/KnowledgeBase.php` (RAG): carga
  `docs/agente/base-conocimiento.csv` en tiempo de ejecución (ruta configurable
  vía `chat.knowledge_base_path`) y recupera **3 a 6** fragmentos relevantes por
  turno (coincidencia insensible a acentos), que se inyectan como bloque de
  "conocimiento verificado" en el prompt. Nunca se vuelca el CSV completo. La
  base de conocimiento se edita por CSV, sin tocar código.
- `app/Services/Chat/ChatLinks.php`: mapea intenciones a **rutas nombradas
  reales** de `routes/web.php` (verificadas con `Route::has`); nunca inventa
  URLs. Cubre rutas de paciente, profesional, administración y super.

Seguridad por rol (identidad = Laravel, no el modelo): paciente ve solo lo
suyo; profesional ve su propia agenda; administración según permisos. Un intento
de "dame las citas del paciente X" o de manipular ids se ignora y se responde
solo con lo que el rol puede ver.

> Tensión honesta (aditivo): por invariante del encargo de no desactivar los
> tests existentes, la capa transaccional previa (`ChatTools` y el flujo de
> reserva/cancelación/reprogramación de `ChatAgentService`) se **conservó
> intacta** junto a la nueva capa de solo lectura, en vez de eliminarse. La
> rama de orientación de solo lectura se activa ante frases de tipo "cómo /
> dónde"; los comandos transaccionales directos siguen enrutando al flujo
> existente. Esto es una diferencia conocida frente a `PLAN-SOLO-LECTURA.md`
> (que pide un agente estrictamente de solo lectura) y queda para revisión del
> orquestador.

### 0.7 Invariantes duros

1. El proveedor por defecto es **Ollama**; nunca se activa un proveedor de pago
   por accidente (valor vacío/desconocido de `AI_PROVIDER` cae a `ollama`).
2. **OpenRouter se conserva** (refactorizado a proveedor), inactivo por defecto;
   no se eliminó.
3. Las **API keys nunca** aparecen en código ni en logs; solo en el `.env`
   local (gitignored).
4. El modelo **nunca** ejecuta SQL ni muta registros; solo recibe texto ya
   consultado y autorizado por PHP (solo lectura vía `ChatReadTools`).
5. Cuando el proveedor está caído o devuelve `null`, el agente responde con una
   **ruta determinista de seguridad** claramente distinguible, sin simular una
   respuesta de IA. Crisis, consentimiento y permisos siguen operando.

---

## 1. Objetivo y alcance

Agente conversacional embebido en la propia web Laravel de Mentea (hospital de
salud mental, Colombia). Agenda citas y responde consultas reutilizando los
servicios ya existentes. **No** cambia de plataforma ni reimplementa la lógica
de agenda: orquesta `AvailabilityService`, `AppointmentService` y
`WaitlistService`. OpenRouter se invoca desde PHP con el cliente HTTP de
Laravel (una sola API para hablar con cualquier modelo, modelo configurable).

El agente ofrece de **2 a 4** opciones reales de cupo, hace una reserva
temporal (hold), confirma tras re-validar, reprograma, cancela y ofrece lista
de espera cuando no hay cupo.

### Principio rector: el código manda, el modelo solo redacta

El modelo (LLM) **nunca** decide reglas de negocio ni inventa datos. El modelo
únicamente **redacta** (phrasing) a partir de datos y decisiones que el código
PHP ya calculó y validó. Toda regla dura se aplica en PHP, antes y después de
llamar al modelo. Si el modelo propusiera algo que viola una regla, el código
lo descarta: la verdad son los datos de la agenda y los chequeos de los
servicios.

## 2. Componentes

```
Navegador (widget Blade + Alpine)
        │  POST /chat  (web + session + CSRF; auth opcional)
        ▼
ChatController
        │  valida request, resuelve conversación/memoria
        ▼
ChatAgentService  (orquestador)
   ├── aplica reglas duras (consentimiento, crisis, escalado, alcance)
   ├── Tool layer  ──► AvailabilityService / AppointmentService / WaitlistService
   └── OpenRouterClient (Illuminate\Support\Facades\Http) ──► OpenRouter
        │  (solo redacta; degradación grácil si no hay API key)
        ▼
chat_conversations + chat_messages  (memoria persistente)
```

### 2.1 `ChatController`

- Ruta `POST /chat` con nombre `chat.message`, bajo el grupo middleware `web`
  (sesión + CSRF). **No** requiere `auth`: los invitados pueden conversar y
  pedir información; agendar sí exige paciente autenticado con consentimiento
  (ver §4 y §8). Ruta de salud/estado opcional `GET /chat` para rehidratar la
  conversación actual.
- Responsabilidades: validar el payload (`message` string, límite de longitud),
  resolver la conversación (por `user_id` si hay sesión autenticada, o por
  `session()->getId()` para invitados), delegar en `ChatAgentService` y
  devolver JSON (`reply`, `conversation_id`, metadatos como `escalated`,
  `crisis`, `requires_consent`, `slots` cuando aplique).
- No contiene lógica de negocio: delega todo en el servicio.

### 2.2 `ChatAgentService` (orquestador)

Flujo por turno:

1. Persistir el mensaje entrante del usuario en `chat_messages`.
2. **Reglas duras previas** (en orden; cualquiera puede cortar el turno):
   - Detección de **crisis** → responde texto de crisis configurable + línea
     123 + línea de crisis configurable, marca escalado, **detiene** el
     agendamiento. No se llama al modelo para "mejorar" este texto.
   - Detección de contenido que exige **escalado a humano** (preguntas
     clínicas / diagnóstico, menores o acudientes, fuera de alcance, casos
     dudosos) → mensaje neutro de escalado.
   - **Puerta de consentimiento** (Ley 1581/2012): antes de recolectar datos
     personales o de agendar, exige consentimiento. Sin consentimiento no
     continúa el flujo de datos/reserva.
3. Construir el contexto: *system prompt* (reglas + catálogo + tono) + ventana
   de historial reciente (§5) + mensaje actual + definiciones de herramientas.
4. Llamar a `OpenRouterClient`. El modelo puede solicitar herramientas; el
   servicio ejecuta la herramienta correspondiente (datos reales) y vuelve a
   pedir al modelo que **redacte** la respuesta con esos datos.
5. **Re-verificación antes de confirmar**: cualquier reserva/confirmación se
   revalida contra `AvailabilityService` / `AppointmentService` en PHP justo
   antes de persistir. Nunca se confirma un cupo que el modelo "recuerde".
6. Persistir la respuesta del asistente en `chat_messages` y devolverla.

Si no hay API key o la llamada a OpenRouter falla, se usa una **ruta de
respuesta determinista** (fallback) que sigue aplicando las reglas duras y
puede ofrecer cupos reales / escalar, con copy fijo en español (§6).

### 2.3 Capa de herramientas (tool layer)

Adaptadores finos sobre los servicios existentes; **no** reimplementan lógica.
Firmas de referencia (ya existentes en el código):

- `AvailabilityService::getAvailableSlots(Professional, Carbon): Collection<'H:i'>`
- `AvailabilityService::getAvailableDates(Professional, int=45): Collection<'Y-m-d'>`
- `AvailabilityService::isSlotAvailable(Professional, Carbon, string)`
- `AvailabilityService::isSlotFreeOfAppointments(Professional, Carbon, string)`
- `AppointmentService::book(User, Professional, string $date, string $time, Modality, ?reason, ?orderDoc, ?authDoc, bool $skipAvailabilityCheck)`
  - ya aplica: bloqueo de agendamiento, `hasInformedConsent()`, disponibilidad,
    doble-reserva del paciente y transacción DB.
- `AppointmentService::reschedule(...)`, `cancelByPatient(...)`, `confirm(...)`,
  `assertPatientHasNoConflict(...)`
- `WaitlistService` (`HOLD_MINUTES = 60`): `onSlotFreed`, `offerSlot`,
  `confirmOffer(WaitlistEntry, AppointmentService)`, y alta de entrada.

Herramientas que expondrá el agente (nombres orientativos, FEAT-002 fija los
definitivos):

| Herramienta           | Delegación                                   | Devuelve |
|-----------------------|----------------------------------------------|----------|
| `list_services`       | catálogo (§7)                                | servicios disponibles |
| `find_availability`   | `getAvailableDates` + `getAvailableSlots`    | **2 a 4** cupos reales |
| `hold_slot`           | reserva temporal (hold de 60 min)            | hold + expiración |
| `confirm_booking`     | re-verifica y `AppointmentService::book`     | cita confirmada |
| `reschedule_booking`  | `AppointmentService::reschedule`             | cita reprogramada |
| `cancel_booking`      | `AppointmentService::cancelByPatient`        | cita cancelada |
| `join_waitlist`       | `WaitlistService` (alta de `WaitlistEntry`)  | entrada en lista |

Regla transversal de todas las herramientas de cupo: **solo** se ofrecen slots
devueltos por `AvailabilityService`; nunca se inventan horarios. Antes de
confirmar se vuelve a validar el cupo (holds de lista de espera se respetan vía
`isSlotFreeOfAppointments` / `skipAvailabilityCheck`).

### 2.4 `OpenRouterClient`

- Usa `Illuminate\Support\Facades\Http` (para que `Http::fake()` funcione en
  tests; **sin** red real en CI).
- Lee credenciales y modelo de config (§6). Base URL configurable.
- **Degradación grácil**: si `OPENROUTER_API_KEY` está ausente, el cliente
  reporta "no configurado" y el orquestador usa la ruta determinista; la app
  nunca se cae por falta de key.
- Timeouts cortos y manejo de error: cualquier fallo de red cae al fallback.

## 3. Frontera de reglas duras (code enforces, model phrases)

Estas reglas se implementan y verifican en PHP. El modelo solo cambia la
redacción, nunca la decisión:

1. **Consentimiento primero (Ley 1581/2012).** Antes de recolectar datos
   personales o agendar, el agente exige consentimiento de tratamiento de
   datos. Sin consentimiento, el flujo de datos/reserva no continúa. En paciente
   autenticado se cruza con `User::hasInformedConsent()`
   (`consent_informed_at`); `AppointmentService::book()` ya rechaza si falta.
2. **Nunca inventar horarios.** Solo se ofrece disponibilidad real
   (`AvailabilityService`) y se **re-verifica** inmediatamente antes de
   confirmar. 2 a 4 opciones por turno.
3. **Protocolo de crisis.** Ante señales de crisis/riesgo: detener el
   agendamiento, entregar el **texto de crisis configurable** con la línea de
   emergencias **123** y una **línea de crisis configurable**, y **escalar a una
   persona** de inmediato. El agente solo ejecuta ese texto (configurable); no
   improvisa.
4. **Escalado a humano** en casos dudosos, preguntas clínicas (el agente **no
   diagnostica ni aconseja**), menores/acudientes, o mensajes fuera de alcance.
5. **Mensajes/recordatorios neutros** por privacidad (sin detalles clínicos
   sensibles en texto visible/notificaciones).

Orden de evaluación por turno: **crisis → escalado → consentimiento → cupo /
redacción**. La regla más protectora corta antes.

## 4. Modelo de datos de memoria

Dos tablas nuevas (FEAT-002 crea las migraciones):

### `chat_conversations`
- `id`
- `user_id` nullable (FK a `users`) — conversación de paciente autenticado.
- `session_id` nullable (string) — conversación de invitado (`session()->getId()`).
- `status` / flags: p.ej. `escalated_at`, `crisis_flagged_at` (nullables) para
  auditoría del protocolo.
- `consent_captured_at` nullable — consentimiento capturado dentro del chat
  (complementa `users.consent_informed_at`).
- timestamps.
- Índices por `user_id` y por `session_id`.

### `chat_messages`
- `id`, `chat_conversation_id` (FK, cascade on delete).
- `role` enum/string: `user` | `assistant` | `system` | `tool`.
- `content` text.
- `meta` json nullable (herramienta usada, slots ofrecidos, flags de crisis/
  escalado, modelo utilizado, etc.).
- timestamps.
- Índice por `chat_conversation_id` + `created_at`.

**Ventana de historial**: en cada turno se envía al modelo el *system prompt* +
los últimos N mensajes (`user`/`assistant`) de la conversación (N configurable,
p.ej. 10-20) + el mensaje nuevo. El historial se **persiste** siempre; solo la
ventana reciente se manda al modelo para acotar tokens.

Resolución de conversación: si hay usuario autenticado → por `user_id`; si no →
por `session_id`. Al iniciar sesión se puede vincular la conversación de
invitado al usuario (opcional, FEAT-002 decide).

## 5. Configuración y credenciales

### `config/services.php` (bloque nuevo `openrouter`)
```php
'openrouter' => [
    'key'      => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'model'    => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
],
```

### `config/chat.php` (nuevo) — parámetros del agente
- `model`, `base_url` (pueden leer de `services.openrouter`).
- `history_window` (N mensajes recientes).
- `system_prompt` (reglas + tono en español).
- `consent_copy` (texto de la puerta de consentimiento).
- `crisis_copy` (texto de crisis con línea 123 + línea de crisis).
- `crisis_line` (línea de crisis configurable).
- `escalation_copy` (texto de escalado a humano).
- `enabled` / flags de degradación.

> Alternativa admitida: añadir estas claves en `config/mentea.php`
> (que ya existe con `meet_mode`, etc.). FEAT-002 elige; el contrato es que
> exista una fuente de config única para prompt + copys + modelo.

### Override por `SystemSetting`
El texto de **crisis** (y opcionalmente consentimiento/escalado) debe poder
sobreescribirse en caliente vía `SystemSetting::getValue(key, default)` /
`SystemSetting::setValue(...)` (store cacheado de la app). El orquestador lee
primero `SystemSetting`, luego cae a `config/chat.php`.

### Degradación grácil (sin API key / sin red)
- Si falta `OPENROUTER_API_KEY`: el agente funciona en modo determinista.
  Sigue aplicando reglas duras, puede listar servicios, ofrecer cupos reales y
  escalar/entregar crisis, usando los copys fijos en español. **No** se cae.
- En tests: `Http::fake()` simula OpenRouter; **ninguna** prueba requiere red.
- `.env.example` documentará `OPENROUTER_API_KEY`, `OPENROUTER_MODEL`,
  `OPENROUTER_BASE_URL`, `CHAT_*` (FEAT-002).

## 6. Catálogo de servicios (5 de negocio → `Professional.profession`)

Los 5 servicios del plan de negocio se mapean al campo `profession` del modelo
`Professional`:

| Servicio de negocio    | `profession`         | ¿Sembrado? |
|------------------------|----------------------|------------|
| Psicología             | `Psicología`         | Sí |
| Psiquiatría            | `Psiquiatría`        | Sí |
| Neuropsicología        | `Neuropsicología`    | Sí |
| Terapia ocupacional    | `Terapia Ocupacional`| **No** |
| Terapia física         | `Terapia Física`     | **No** |

El seeder actual solo crea profesionales de **Psicología / Psiquiatría /
Neuropsicología**. Para Terapia Ocupacional y Terapia Física no hay
profesionales disponibles: el agente debe **informarlo con gracia** ("por el
momento no tenemos disponibilidad para ese servicio") y ofrecer alternativas
(otro servicio, lista de espera), **sin inventar** profesionales ni cupos.

Nota: la lista de espera (`WaitlistService::onSlotFreed`) hace match por
`professional->specialty` exacto (granular, p.ej. "Psicología Clínica"),
mientras que el catálogo del chat agrupa por `profession` (amplio). Las
herramientas deben traducir entre ambos niveles con cuidado.

## 7. Widget (Blade + Alpine)

- Componente Blade + Alpine incluido en `resources/views/layouts/app.blade.php`
  (layout autenticado). El layout ya expone `<meta name="csrf-token">` y
  `@vite([...])`; Alpine ya es dependencia. El widget envía el token CSRF en el
  `POST /chat`.
- UI: burbuja flotante que abre un panel de chat; lista de mensajes, input,
  estado de "escribiendo", y render de los **2-4 cupos** como botones
  seleccionables cuando el backend los devuelve.
- **Comportamiento de invitado**: un invitado puede conversar y pedir
  información general; **agendar** requiere paciente autenticado con
  consentimiento. Si un invitado intenta agendar, el agente degrada con
  gracia (invita a iniciar sesión / registrarse), **nunca** rompe la página.
  El widget debe funcionar sin usuario y sin API key.
- Rutas de paciente: el grupo real vive tras
  `Route::middleware('role:patient')->prefix('paciente')->name('patient.')`.
  El `POST /chat` en sí va en el grupo `web` general (sin `role:patient`) para
  permitir invitados; la autorización de agendar se decide dentro del servicio.

## 8. Pruebas (contrato para FEAT-002/003)

- `Http::fake()` para OpenRouter; sin red real.
- Casos mínimos: puerta de consentimiento bloquea reserva sin consentimiento;
  crisis devuelve copy configurable + 123 + línea de crisis y marca escalado;
  disponibilidad devuelve 2-4 cupos reales y nunca inventa; confirmación
  re-verifica el cupo; servicio sin profesionales (terapia ocupacional/física)
  responde "sin disponibilidad" sin inventar; invitado puede conversar pero no
  agendar; funcionamiento sin `OPENROUTER_API_KEY` (fallback determinista).
- Todo debe mantener verde `.github/workflows/ci.yml` (PHP 8.3, sqlite,
  `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`). Estilo con Laravel Pint.

## 8.1 Ciclo de agendamiento conversacional

El agente completa el ciclo completo dentro de la conversación, no solo la
oferta:

1. **Oferta** (`offerRealSlots`): ante intención de agendar de un paciente
   autenticado con consentimiento, se ofrecen de **2 a 4** cupos reales. El
   mínimo de 2 se garantiza SOLO cuando existen de verdad: si el primer
   profesional tiene un único cupo en su primera fecha, se completan opciones
   con sus siguientes fechas y con otros profesionales del mismo servicio. Si en
   total solo hay 1 cupo real, se ofrece como única opción (nunca se inventa).
2. **Estado pendiente**: la oferta se guarda en `chat_messages.meta.pending`
   (tipo `book`/`reschedule`, servicio, profesional, fecha y la lista de cupos).
   `ChatConversation::pendingAction()` la recupera en el turno siguiente.
3. **Selección**: un turno de seguimiento ("la primera", "las 9:00", "sí,
   confirmo") se mapea a un cupo concreto (`matchSelection`: por hora, por
   ordinal, o confirmación genérica si hay una sola opción).
4. **Re-verificación antes de confirmar**: inmediatamente antes de reservar se
   vuelve a validar el cupo con `AvailabilityService::isSlotAvailable`; además
   `AppointmentService::book` re-valida y lanza si el cupo se tomó entre medio.
   En ese caso se responde "ese cupo ya no está disponible, te ofrezco estas
   otras opciones" y se re-ofrece disponibilidad real.
5. **Confirmar** → `AppointmentService::book` (crea la cita `Pending`).
6. **Reprogramar** → detecta la próxima cita modificable del paciente, ofrece
   cupos y confirma vía `AppointmentService::reschedule`.
7. **Cancelar** → cancela la próxima cita cancelable vía
   `AppointmentService::cancelByPatient` (con sus reglas de 5 días).
8. **Lista de espera** → `join_waitlist` crea un `WaitlistEntry` cuando el
   paciente lo pide o no hay cupo.

Reservar / reprogramar / cancelar / lista de espera siguen exigiendo **paciente
autenticado con consentimiento**; un invitado degrada con gracia. El meta de la
respuesta separa `requires_login` (debe iniciar sesión) de `requires_consent`
(debe aceptar el consentimiento) para que el widget mensajee correctamente.

### Detección de crisis reforzada (tres capas, sesgo a fallar-seguro)

La detección de crisis se evalúa en **tres capas**, todas orquestadas en PHP y
antes de cualquier otra regla (`detectsCrisis`). El **orden** es lo que hace el
diseño seguro: las capas deterministas son el piso auditable y el modelo solo
puede **elevar** una ausencia, nunca bajar un hit.

1. **Piso determinista (siempre dispara, incondicional)** — lista de
   palabras/frases exactas (`config('chat.crisis_keywords')`, normalizada sin
   acentos). Un match aquí **siempre** dispara: ninguna negación, capa posterior
   ni el modelo puede anularlo. Es auditable y ampliable sin tocar código.
2. **Capa de refuerzo por paráfrasis (escalate-only, atenuada por negación)** —
   expresiones regulares sobre el texto normalizado
   (`config('chat.crisis_patterns')`) que capturan construcciones que la lista
   exacta no cubre ("ya no aguanto más", "ya no le veo sentido a nada",
   "quisiera no despertar", "todos estarían mejor sin mí"). Esta capa **solo
   eleva** la señal. Para acotar falsos positivos se descarta únicamente ante
   una **negación directa** de la ideación
   (`config('chat.crisis_pattern_negations')`, p.ej. "no quiero hacerme daño");
   esa atenuación aplica **solo a la capa 2**, nunca a la capa 1.
3. **Clasificador asistido por el modelo (OPCIONAL, escalate-only)** — se
   consulta **únicamente** cuando las dos capas deterministas NO dispararon
   (`ChatAgentService::classifierDetectsCrisis` vía `OpenRouterClient::classify`
   con `config('chat.crisis_classifier_prompt')`). Si cualquiera de las capas
   deterministas ya marcó crisis, `detectsCrisis` retorna `true` de inmediato y
   el modelo **no se consulta siquiera**: así el modelo jamás puede des-marcar
   un hit de palabra/patrón (asimetría que preserva la seguridad). El modelo
   **solo puede elevar** una paráfrasis muy indirecta que ni la lista ni los
   patrones capturen. Se activa/desactiva con el toggle auditable
   `config('chat.crisis_classifier_enabled')` (`CHAT_CRISIS_CLASSIFIER`).

El diseño prioriza deliberadamente el **falso positivo** sobre el falso negativo:
ante la duda, entregar el protocolo de crisis es preferible a enrutar a una
persona en riesgo hacia el flujo de agendamiento.

**Fail-safe end-to-end (sin key / sin red):** el clasificador es puramente
aditivo. Si el toggle está desactivado, no hay `OPENROUTER_API_KEY`, la red o el
HTTP fallan, o la respuesta es inparseable o no-crisis, se cae al **resultado
determinista** (palabras clave + regex) sin lanzar excepción y sin dejar pasar
un hit determinista. Con cero configuración el agente detecta crisis con las dos
capas deterministas, exactamente como antes.

**Límite residual (mitigado, ya no solo documentado):** la capa 3 mitiga el
hueco de "fail-open" de la detección basada solo en patrones: una paráfrasis muy
indirecta que no contenga ni palabra clave ni patrón ahora **puede** elevarse
por el clasificador cuando OpenRouter está configurado. Cuando no lo está, el
comportamiento es el piso determinista. En todos los casos el modelo **nunca
decide reglas ni baja la bandera**: solo puede elevar una ausencia; el código
manda, el modelo solo redacta/clasifica dentro de esa asimetría.

### Guardia anti-alucinación del texto redactado

Cuando OpenRouter está configurado, `phrase()` le pide redactar a partir del
texto determinista ya calculado. La salida del modelo se valida en PHP: si
introduce un horario (HH:MM) que no estaba en el texto verificado, se descarta y
se devuelve el determinista tal cual. Así el modelo nunca puede surfacear un
cupo que el código no ofreció.

## 9. Resumen de reglas no negociables

1. Consentimiento antes de datos personales / reserva (Ley 1581/2012).
2. Solo cupos reales de la agenda; re-verificar antes de confirmar; 2-4 opciones.
3. Crisis → texto configurable + 123 + línea de crisis + escalado; detener agenda.
4. Escalado a humano en casos clínicos, dudosos, menores/acudientes o fuera de alcance; el agente no diagnostica ni aconseja.
5. Mensajes neutros por privacidad.
6. El código aplica las reglas; el modelo solo redacta.
7. Sin API key / sin red: degradación grácil determinista, nunca se cae.

---

## 10. Memoria conversacional persistente (Etapas 4-6)

> Implementado en la rama `feat/chat-persistent-memory` siguiendo el contrato de
> `docs/agente/AUDITORIA-Y-PLAN-MEMORIA.md`. Hallazgo de la auditoría (Etapa 1):
> el backend **ya persistía** la conversación en `chat_conversations` /
> `chat_messages`, pero el frontend **nunca la recuperaba** y no existían
> endpoints de lectura, de modo que el hilo se perdía al navegar, minimizar o
> recargar. El diseño (Etapa 2) es **reuse-first**: extender tablas y servicios
> existentes, mantener la memoria en Laravel/BD y conservar la abstracción de
> proveedores y todas las reglas duras en PHP.

### 10.1 Las cuatro capas de memoria

| Capa | Dónde vive | Qué guarda |
|---|---|---|
| **Conversación** | `chat_messages` (tabla existente) | cada turno `user`/`assistant`/`system`/`tool`, con `meta` (slots ofrecidos, `pending`, flags) |
| **Sesión** | `localStorage` del navegador + `chat_conversations.session_id` | `conversation_id` activo y estado de UI (abierto, borrador). **Nunca** el historial |
| **Contextual** | `chat_conversations.summary` + `context_metadata` + ventana reciente | resumen progresivo de los turnos antiguos, metadatos (módulo/tema/flags) y los últimos N mensajes |
| **Preferencias** | `chat_user_preferences` (tabla nueva) | preferencias de interacción **no sensibles** y **consentidas** (`consented_at`); nunca datos clínicos |

La **BD es la fuente oficial**. `localStorage` es solo apoyo (id activo + UI); al
montar, el widget hidrata el historial desde el servidor. No se requiere SPA.

### 10.2 Modelo de datos (reutilización + extensión)

`chat_conversations` (tabla existente, **ALTER** reversible, columnas nuevas
`nullable`): se añadieron `title`, `summary`, `context_metadata` (json),
`last_message_at`, más el índice compuesto `(user_id, status, last_message_at)`
para la lista de conversaciones. Se conservan `session_id`,
`consent_captured_at`, `crisis_flagged_at`, `escalated_at` y `last_activity_at`.

`chat_messages` (tabla existente): se añadió `client_message_id` (`string`
nullable, único por conversación) para **idempotencia** de reintentos; el resto
del esquema (`role`, `content`, `meta`) no cambia.

`chat_user_preferences` (tabla nueva): `user_id` (FK `cascadeOnDelete`), `key`,
`value`, `consented_at` nullable, único `(user_id, key)`.

Relaciones nuevas: `User hasMany ChatConversation` (`chatConversations()`),
`User hasMany ChatUserPreference`. Scopes en `ChatConversation`: `forUser`,
`active`, `archived`, `recent` (orden por `last_message_at`).

### 10.3 Servicios provider-independientes

Ninguna lógica de memoria entra en `OllamaProvider` ni en `OpenRouterProvider`:
ambos reciben solo el arreglo `messages[]` ya construido.

- `ConversationRepository`: consultas CRUD acotadas por usuario (listar, activa,
  por id con `where user_id`, crear, archivar, eliminar, deduplicar por
  `client_message_id`).
- `ConversationManager`: orquesta resolver/crear/cambiar la conversación activa
  sobre el repositorio; lo usan el controlador y el agente.
- `ConversationContextBuilder`: extraído de `ChatAgentService::phrase()`; arma
  `messages[]` en **7 bloques** en orden fijo (§10.5) y respeta el presupuesto
  de tokens. La cola (instrucción determinista + mensaje actual) se conserva
  byte a byte para que la guardia anti-alucinación siga operando.
- `ConversationSummaryService`: genera/actualiza `summary` cuando la
  conversación supera el umbral, vía `AiProvider` (provider-agnóstico) con
  **fallback determinista** si no hay proveedor. No re-resume todo cada turno y
  no toca `meta.pending` del último mensaje del asistente.
- `ConversationMemoryService`: lee/escribe `chat_user_preferences` con control
  de consentimiento y lista blanca de claves/valores (`config('chat.preference_keys')`).

### 10.4 Endpoints y rutas

`POST /chat` (`chat.send`) ahora acepta, además de `message`, un
`conversation_id` opcional y un `client_message_id` para idempotencia. Las
rutas de gestión viven bajo `middleware(['auth', 'throttle:60,1'])`; cada acción
resuelve la conversación acotada por `user_id` (anti-IDOR) antes de aplicar la
política:

| Método | Ruta | Nombre | Acción |
|---|---|---|---|
| `POST` | `/chat` | `chat.send` | enviar mensaje (acepta `conversation_id` + `client_message_id`) |
| `GET` | `/chat/conversations` | `chat.conversations.index` | listar conversaciones del usuario |
| `POST` | `/chat/conversations` | `chat.conversations.store` | iniciar conversación nueva |
| `GET` | `/chat/conversations/{conversation}` | `chat.conversations.show` | mensajes de UNA conversación |
| `PATCH` | `/chat/conversations/{conversation}` | `chat.conversations.update` | renombrar / archivar |
| `DELETE` | `/chat/conversations/{conversation}` | `chat.conversations.destroy` | eliminación controlada |

El parámetro `{conversation}` está restringido con `whereNumber`. Ninguna ruta
existente se rompe.

### 10.5 `ChatConversationPolicy` y mapa de las 10 reglas de seguridad

`ChatConversationPolicy` (registrada vía `Gate::policy` en
`AppServiceProvider::boot()`) exige en **todas** sus habilidades (`view`,
`update`, `archive`, `delete`, `addMessage`) que `conversation.user_id ===
user.id`. No concede acceso cruzado a admin/super_admin sobre conversaciones de
pacientes. Como existe un `Gate::before` global que devuelve `true` para
super_admin, la defensa real contra IDOR es que los endpoints resuelven la
conversación **siempre** con `ConversationRepository::findForUser` (scoped
`where user_id = Auth::id()`): ni siquiera un super_admin puede leer la
conversación de otro cambiando el id.

| Regla §2.9 | Punto de aplicación |
|---|---|
| 1. Solo sus propias conversaciones | `ChatConversationPolicy::view` + scope `where user_id = Auth::id()` en todos los endpoints |
| 2. Sin IDOR por cambio de id | resolución acotada en `ConversationRepository::findForUser`; binding con `whereNumber` |
| 3. Pacientes no ven datos de otros | patrón `ChatReadTools` (ignora ids ajenos) + política |
| 4. Profesionales solo lo autorizado | roles/permisos existentes en `ChatReadTools` |
| 5. Admins según permisos institucionales | la política NO concede acceso cruzado por defecto |
| 6. No mezclar conversaciones entre roles/usuarios | clave `user_id` + ausencia de rutas de acceso cruzado |
| 7. No guardar clínico sensible como memoria permanente | `ConversationMemoryService` filtra a preferencias no sensibles de la lista blanca + `consented_at` |
| 8. No enviar datos clínicos de terceros al modelo | el context builder solo usa datos del usuario autenticado + RAG público |
| 9. Validación en servidor, no en frontend | `FormRequest` + política + reglas duras en `ChatAgentService` |
| 10. Log de seguridad sin contenido clínico | denegaciones registradas con ids/razón, sin `content` |

El agente sigue siendo **solo lectura de orientación**: la memoria **no** amplía
permisos. Crisis, consentimiento, privacidad y escalado permanecen en PHP e
independientes del modelo (§3, §8).

### 10.6 Ventana de contexto + resumen (optimizado para qwen3:8b)

`ConversationContextBuilder` arma `messages[]` en 7 bloques y en este orden:

1. prompt del agente (system),
2. identidad/rol/permisos del usuario autenticado (no sensible),
3. reglas de seguridad y privacidad,
4. **resumen** de la conversación (`chat_conversations.summary`),
5. ventana de los últimos `context_window` mensajes relevantes,
6. RAG (fragmentos de la base de conocimiento),
7. instrucción determinista + mensaje actual del usuario (cola conservada byte a byte).

El `recentHistory(12)` fijo se reemplazó por **ventana reciente + resumen**.
`ConversationSummaryService` condensa los turnos antiguos cuando se superan
`summary_threshold_messages`, de forma incremental, y guarda el resultado
(acotado a `summary_max_chars`) en `summary`. Un tope aproximado de caracteres
(`context_token_budget`) limita el payload total; el constructor recorta
**primero** la ventana reciente, nunca los bloques de sistema/seguridad. Esto
reduce la latencia del modelo local y da continuidad temática (un "¿y dónde
encuentro esa opción?" encuentra el referente en resumen + ventana) sin reenviar
indefinidamente todo el historial. `meta.pending` del último mensaje del
asistente se preserva intacto para no romper los seguimientos de cupos.

### 10.7 Independencia de proveedor (Ollama por defecto, OpenRouter conservado)

La memoria es provider-independiente: `OllamaProvider` (por defecto) y
`OpenRouterProvider` (conservado, inactivo por defecto) reciben el mismo
`messages[]` ya construido. La compatibilidad se verifica con `Http::fake()` en
`tests/Feature/Chat/MemoryCompatibilityTest.php`:

- la ruta **Ollama** redacta a través del context builder y publica en
  `POST {base}/api/chat` (forma `message.content`, sin `Authorization`);
- **OpenRouter** sigue seleccionable (`config('ai.provider') = 'openrouter'`,
  forma OpenAI `choices[].message.content` en `/chat/completions`);
- con el **proveedor no disponible**, la ruta determinista de seguridad
  (crisis / consentimiento / escalado) gobierna sin tocar el modelo;
- un **timeout / HTTP 500 / error de conexión** se recupera con gracia: el
  agente devuelve el texto determinista y nunca propaga un 500.

### 10.8 Claves de configuración (`config/chat.php`)

| Clave | Env | Defecto | Para |
|---|---|---|---|
| `context_window` | `CHAT_CONTEXT_WINDOW` | `8` | nº de mensajes recientes en la ventana (reemplaza `history_window=12`) |
| `summary_threshold_messages` | `CHAT_SUMMARY_THRESHOLD` | `12` | a partir de cuántos mensajes se genera/actualiza el resumen |
| `summary_enabled` | `CHAT_SUMMARY_ENABLED` | `true` | interruptor del resumen progresivo |
| `context_token_budget` | `CHAT_CONTEXT_TOKEN_BUDGET` | `8000` | tope aproximado (caracteres) del payload total |
| `summary_max_chars` | `CHAT_SUMMARY_MAX_CHARS` | `1500` | tope del resumen almacenado |
| `preference_keys` | — | ver config | lista blanca de preferencias consentibles (`response_length`, `explanation_style`, `preferred_topic`) |
| `preference_text_max_chars` | `CHAT_PREFERENCE_TEXT_MAX` | `60` | tope para valores de preferencia de texto libre acotado |

El proveedor por defecto sigue siendo `ollama` en `config/ai.php`
(`AI_PROVIDER=ollama`); `OpenRouter` queda conservado e inactivo.

---

## 11. Ejecución, migración, configuración y pruebas (Etapa 6)

### 11.1 Runtime PHP 8.3 (entorno de sandbox)

El `php` por defecto del sandbox (8.4) está roto (faltan librerías). Prefija
**todo** comando de php/artisan/pint con el runtime 8.3:

```bash
LD_LIBRARY_PATH=/inner_container/usr/lib64 \
PATH=/root/.local/share/mise/installs/php/8.3/bin:$PATH \
php artisan <comando>
```

En CI (GitHub Actions) PHP 8.3 es nativo y no requiere el prefijo.

### 11.2 Migraciones (reversibles)

Las migraciones de memoria son reversibles y sus columnas nuevas son `nullable`
para compatibilidad con datos existentes.

```bash
# Aplicar
LD_LIBRARY_PATH=/inner_container/usr/lib64 PATH=/root/.local/share/mise/installs/php/8.3/bin:$PATH \
  php artisan migrate

# Revertir el último lote (ALTER chat_conversations, client_message_id,
# chat_user_preferences)
LD_LIBRARY_PATH=/inner_container/usr/lib64 PATH=/root/.local/share/mise/installs/php/8.3/bin:$PATH \
  php artisan migrate:rollback
```

En pruebas se usa `RefreshDatabase` sobre SQLite `:memory:`, por lo que el
esquema se migra/revierte limpio en cada corrida.

### 11.3 Configuración relevante

- `config/ai.php`: `AI_PROVIDER=ollama` (defecto). Cambiar a OpenRouter solo por
  `.env` (`AI_PROVIDER=openrouter` + `OPENROUTER_API_KEY`). Ver §0.3-§0.5.
- `config/chat.php`: ventana/resumen/preferencias (§10.8), timeouts, copys de
  crisis/consentimiento/escalado, catálogo de servicios, heurística de crisis.
- `phpunit.xml` fija `AI_PROVIDER=openrouter` para que la batería OpenAI-shaped
  siga verde con `Http::fake()`; **no editar**. El defecto local/producción es
  `ollama`.

### 11.4 Ejecutar la suite y el linter

```bash
# Suite completa
LD_LIBRARY_PATH=/inner_container/usr/lib64 PATH=/root/.local/share/mise/installs/php/8.3/bin:$PATH \
  php artisan test

# Solo las pruebas del chat
LD_LIBRARY_PATH=/inner_container/usr/lib64 PATH=/root/.local/share/mise/installs/php/8.3/bin:$PATH \
  php artisan test tests/Feature/Chat tests/Feature/AiProviderTest.php

# Estilo (Laravel Pint)
LD_LIBRARY_PATH=/inner_container/usr/lib64 PATH=/root/.local/share/mise/installs/php/8.3/bin:$PATH \
  ./vendor/bin/pint
```

Todas las pruebas del chat usan **datos ficticios** y `Http::fake()`; ninguna
llama a un proveedor en vivo (Ollama no es alcanzable en el sandbox). Las
pruebas de memoria cubren persistencia, navegación, memoria contextual,
seguridad (aislamiento por usuario, anti-IDOR, deduplicación) y compatibilidad
de proveedores.
