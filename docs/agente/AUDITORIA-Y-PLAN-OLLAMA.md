# Transformación del agente de Mentea con Ollama local — Auditoría y plan técnico

> Fases 1 (Auditoría) y 2 (Diseño técnico) del encargo. La implementación (Fases 3–8)
> se hará por el agente planificador/implementador por fases, verificando cada cambio.
> Este documento es el contrato de diseño: se actualiza antes de divergir de él.

## 0. Contexto confirmado por el usuario

- **Ollama ya está instalado y funcionando en la PC local del usuario**, con los modelos
  necesarios (incluido `qwen3:8b`) descargados y **respondiendo**. La integración NO debe
  instalar Ollama ni modelos: solo debe apuntar Laravel al Ollama local y hablar con él.
- Proveedor activo objetivo: **Ollama** (`http://localhost:11434`, endpoint `/api/chat`, modelo `qwen3:8b`).
- **OpenRouter se conserva** como proveedor alternativo (de pago), seleccionable por configuración.

---

## 1. Fase 1 — Auditoría de la arquitectura actual del agente

### 1.1 Componentes existentes (repo `mentea-web1.2`, rama `main`)

| Componente | Archivo | Rol actual |
|---|---|---|
| Orquestador | `app/Services/Chat/ChatAgentService.php` (~45 KB) | Aplica TODAS las reglas duras en PHP (crisis → escalado → consentimiento → cupos reales); el modelo solo redacta. |
| Cliente LLM | `app/Services/Chat/OpenRouterClient.php` | Cliente HTTP único, acoplado a OpenRouter. Llama a `POST {base_url}/chat/completions` con `{model, messages}` y lee `choices.0.message.content`. Degrada a null si no hay key/red. |
| Herramientas | `app/Services/Chat/ChatTools.php` | Adaptadores sobre servicios reales: `listServices`, `professionalsFor`, `getAvailableDates`, `getSlots`, `book`, `reschedule`, `cancel`, `joinWaitlist`. NO reimplementa lógica. |
| HTTP | `app/Http/Controllers/ChatController.php` | Ruta `POST /chat` (web, CSRF, throttle). Inyecta `ChatAgentService`. |
| Memoria | modelos `ChatConversation` / `ChatMessage` + migraciones | Historial reciente por conversación; `meta` guarda intención/flags. |
| Config | `config/chat.php` + `config/services.php` (bloque `openrouter`) | Prompt, copys, servicios, 3 capas de detección de crisis, ventana de historial, timeout. |
| Servicios de negocio (fuente de verdad) | `app/Services/{AvailabilityService,AppointmentService,WaitlistService,GoogleMeetService}.php` | Disponibilidad real, reserva/cancelación/reprogramación con validaciones, lista de espera. |
| Dominio | `app/Models/{User,Professional,Appointment,Schedule,WaitlistEntry,...}`, `app/Enums/{Modality,AppointmentStatus,Role,WaitlistStatus}`, `spatie/laravel-permission` | Roles (super_admin/admin/professional/patient), permisos, estados. |

### 1.2 Diagnóstico — limitaciones del agente actual frente al objetivo

1. **Acoplamiento a un solo proveedor.** `OpenRouterClient` está cableado a OpenRouter (`services.openrouter.*`, header Bearer, ruta `/chat/completions`). No hay abstracción de proveedor ni forma de seleccionar Ollama por configuración.
2. **Sin Ollama.** No existe cliente para el endpoint nativo de Ollama (`/api/chat`, formato de request/response distinto a OpenAI; sin token Bearer).
3. **`isConfigured()` asume "key".** Ollama no usa API key, así que el criterio de "configurado" debe cambiar por proveedor (Ollama = responder a un ping; OpenRouter = tener key).
4. **Conocimiento institucional disperso y limitado.** Los copys viven en `config/chat.php`; no hay base de conocimiento consultable (RAG) ni el nuevo contexto del Hospital Especializado Granja Integral de Lérida, Tolima.
5. **Agente poco "funcional" de cara al rol.** Las herramientas existen pero el agente no adapta capacidades/porte por rol (paciente vs profesional vs admin) de forma explícita ni orienta a secciones de la plataforma por rol.
6. **Detección de intención parcialmente por heurística.** Hay buena base de reglas duras, pero el objetivo pide comprensión de intención más natural (manteniendo el código como autoridad).

### 1.3 Lo que NO hay que tocar (autoridad = lógica de negocio)

Validaciones, disponibilidad real, reglas de agendamiento/cancelación/reprogramación, roles/permisos (spatie), consentimiento (Ley 1581), protocolo de crisis (3 capas), escalado a humano. El agente nunca ejecuta SQL ni modifica registros directamente: siempre a través de los servicios existentes.

---

## 2. Fase 2 — Diseño técnico

### 2.1 Capa de proveedores desacoplada (lo central)

Introducir una **interfaz** y seleccionar el proveedor por configuración, sin mezclar lógica entre proveedores.

```
App\Services\Chat\Providers\
  ├─ AiProvider (interface)            // chat(array $messages): ?string ; classify(...): ?string ; isAvailable(): bool ; name(): string
  ├─ OllamaProvider                    // POST {base}/api/chat  {model, messages, stream:false} -> message.content
  ├─ OpenRouterProvider                // (refactor de OpenRouterClient) POST {base}/chat/completions (Bearer)
  └─ AiProviderManager / factory       // elige el proveedor según config('ai.provider')
```

- `ChatAgentService` deja de depender de `OpenRouterClient` concreto y pasa a depender de `AiProvider` (resuelto por el manager). **El orquestador y las reglas duras no cambian de comportamiento.**
- `OpenRouterClient` se conserva (se renombra/adapta a `OpenRouterProvider` implementando la interfaz) — **no se elimina**.
- `isConfigured()`/degradación se generaliza a `isAvailable()` por proveedor:
  - Ollama: disponible si responde el ping local (`GET /api/tags` o `/api/version`).
  - OpenRouter: disponible si hay `OPENROUTER_API_KEY`.

### 2.2 Configuración y variables de entorno

Nuevo `config/ai.php` (o bloque en `config/services.php`) que centraliza:

```php
'provider' => env('AI_PROVIDER', 'ollama'),   // ollama | openrouter
'ollama' => [
    'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    'model'    => env('OLLAMA_MODEL', 'qwen3:8b'),
    'endpoint' => '/api/chat',
],
'openrouter' => [                              // se conserva, inactivo por defecto
    'key'      => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'model'    => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
],
```

`.env` (local, Ollama activo):
```
AI_PROVIDER=ollama
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=qwen3:8b
OPENROUTER_API_KEY=
OPENROUTER_MODEL=
```
Se mantiene compatibilidad con las variables OPENROUTER_* ya existentes (no duplicar).

### 2.3 Diferencias Ollama vs OpenRouter (a encapsular en cada provider)

| Aspecto | Ollama (`/api/chat`) | OpenRouter (`/chat/completions`) |
|---|---|---|
| Auth | Sin token | `Authorization: Bearer <key>` |
| Request | `{model, messages, stream:false}` | `{model, messages}` |
| Respuesta | `message.content` | `choices.0.message.content` |
| "Disponible" | ping local responde | hay API key |
| Timeout | mayor (modelo local lento: `qwen3:8b`) | menor |

> Nota de operación local: `qwen3:8b` puede tardar; el provider de Ollama usará un timeout más alto (`CHAT_HTTP_TIMEOUT` configurable) y se documentará subir `max_execution_time` en el `php artisan serve` local, como ya experimentó el usuario.

### 2.4 Base de conocimiento institucional + RAG ligero

- Fuente: `docs/agente/base-conocimiento.csv` (45 entradas, incluye articulación con el **Hospital Especializado Granja Integral de Lérida, Tolima**).
- Mecanismo: recuperación contextual simple (keyword/categoría; embeddings opcionales a futuro) que selecciona 3–6 fragmentos relevantes y los inyecta en el prompt del sistema por turno — **no** se envía todo el CSV en cada petición.
- Categorías: institucional, funcional, por rol, protocolos, contexto de conversación (según el encargo).
- Actualizable sin tocar código (editar el CSV / tabla). No se guarda información de pacientes en el conocimiento general.

### 2.5 Agente funcional por rol (herramientas)

Reutiliza `ChatTools` (que ya envuelve los servicios reales) y añade enrutamiento por rol:
- **Paciente:** servicios, disponibilidad real, agendar/consultar/cancelar/reprogramar, lista de espera, estado de solicitud.
- **Profesional:** orientación y consulta de su agenda/funciones según permisos.
- **Admin/otros:** según permisos existentes; no inventar funcionalidades.
- **Regla de ejecución:** modelo interpreta intención → Laravel verifica identidad y permisos (policies/spatie) → consulta datos reales → pide confirmación cuando el protocolo lo exige → ejecuta por el servicio → verifica en BD → informa lo que realmente ocurrió. El modelo nunca toca la BD.

### 2.6 Memoria y continuidad

Se mantiene el historial en `ChatConversation`/`ChatMessage` con la ventana reciente ya existente; se refuerza el seguimiento del "paso actual" de cada procedimiento (slot pendiente) que ya usa `meta.pending`. No se guarda información clínica sensible en la memoria conversacional general.

### 2.7 Interfaz

Conservar el widget actual (ya rediseñado): ventana, mensajes diferenciados, indicador de "escribiendo", Enter/boton, responsive. Añadir, si aporta: indicador de proveedor activo (solo dev) y confirmaciones antes de operaciones sensibles. No rediseñar la plataforma.

### 2.8 Errores y seguridad en modo local

- Comprobar disponibilidad de Ollama y existencia del modelo; manejar timeouts/respuestas vacías/errores de conexión; registrar sin exponer datos.
- **No simular respuestas de IA**: si Ollama no está, se usan respuestas deterministas de seguridad **claramente distinguibles** de una respuesta del modelo, y las reglas de crisis/autorización siguen operando.

---

## 3. Archivos afectados (previsto) y riesgos

| Acción | Archivo(s) | Riesgo |
|---|---|---|
| Crear interfaz + providers | `app/Services/Chat/Providers/*` | Bajo (código nuevo) |
| Refactor cliente → provider | `OpenRouterClient.php` → `OpenRouterProvider.php` | Medio (no romper la ruta actual; mantener tests) |
| Inyección por manager | `ChatAgentService.php` (constructor), `AppServiceProvider`/binding | Medio (núcleo del agente; cubrir con tests) |
| Config | nuevo `config/ai.php`, `.env.example`, `.env.production.example` | Bajo |
| RAG de conocimiento | nuevo servicio de recuperación + `docs/agente/base-conocimiento.csv` | Bajo-Medio |
| Pruebas | `tests/Feature/Chat*` + nuevos tests de provider (Http::fake, Ollama/OpenRouter) | Medio (no desactivar tests para pasar) |
| Docs | `docs/agente/*`, `docs/CHAT_AGENT.md` | Bajo |

**Invariantes que el plan garantiza:** no se elimina OpenRouter; no se deja un proveedor de pago activo por defecto; no se exponen claves; el modelo no ejecuta SQL; no se duplica la lógica de reservas; las reglas de crisis/permiso/consentimiento siguen aunque el modelo falle.

---

## 4. Fases de implementación (3–8) y criterios de verificación

- **Fase 3 — Integración local:** providers + manager + config; Ollama activo; verificar comunicación real Laravel↔Ollama (no simulada). Prueba: un mensaje produce texto generado por `qwen3:8b`; con Ollama apagado, degrada a determinista distinguible.
- **Fase 4 — Conocimiento:** cargar el CSV + recuperación contextual; el agente responde con datos verificables (Hospital Granja Integral, servicios, horarios).
- **Fase 5 — Agente funcional:** herramientas por rol con verificación de permisos y confirmación; ejecutar operaciones reales por los servicios existentes.
- **Fase 6 — Interfaz:** ajustes finos del widget (sin rediseñar).
- **Fase 7 — Pruebas:** batería de conexión, conversacional, funcional y de seguridad (incluye intentos de saltar permisos, pedir datos de otro paciente, diagnóstico, crisis, "ignora tus reglas"). Sin datos reales de pacientes. No desactivar pruebas para pasar el build.
- **Fase 8 — Documentación:** arquitectura, variables, ejecución local, modelos, herramientas, protocolos y cómo cambiar de Ollama a OpenRouter.

### Criterio de aceptación (del encargo)
Poder conversar desde la plataforma y comprobar: comprende Mentea y sus funciones reales; responde preguntas abiertas; recuerda contexto; distingue roles; consulta información real; ejecuta operaciones autorizadas; respeta protocolos; no inventa; mantiene seguridad aunque el modelo falle; funciona con Ollama local; conserva la arquitectura para activar proveedores de pago.

---

## 5. Estado de entrega de este documento

- ✅ **Fase 1 (Auditoría)** y **Fase 2 (Diseño técnico)** entregadas aquí.
- ✅ **Base de conocimiento** actualizada y corregida (UTF-8) en `docs/agente/base-conocimiento.csv` (45 entradas, Hospital Especializado Granja Integral de Lérida, Tolima).
- ⏭️ **Fases 3–8 (implementación):** pendientes de ejecutar por el agente planificador/implementador, siguiendo este plan y verificando cada cambio antes de darlo por terminado.
