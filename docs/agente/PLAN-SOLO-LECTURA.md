# Mentea AI — Redefinición a asistente de SOLO LECTURA (orientación + consulta autorizada)

> Refina el documento `AUDITORIA-Y-PLAN-OLLAMA.md` con la nueva filosofía:
> **El modelo interpreta y comunica. Laravel autoriza y consulta. El usuario ejecuta.**
> El agente NO realiza operaciones transaccionales; solo orienta, enseña y consulta
> información autorizada en modo lectura.

## 0. Cambio de filosofía respecto al plan anterior

| Antes (plan v1) | Ahora (solo lectura) |
|---|---|
| Herramientas funcionales que **ejecutaban** (book/cancel/reschedule/joinWaitlist) | Herramientas **ChatReadTools** solo de lectura |
| El agente podía acompañar y ejecutar operaciones | El agente **orienta, enseña y consulta**; redirige al módulo para que el usuario ejecute |
| Riesgo: el agente altera datos institucionales | El agente **no puede** alterar nada; cero capacidad de escritura |

Las operaciones de escritura (crear/cancelar/reprogramar citas, usuarios, perfiles, roles, horarios, etc.) **siguen ocurriendo únicamente por los módulos y procesos legítimos de Laravel** (controladores/formularios existentes), nunca por el agente.

## 1. Capacidades del agente (todas de lectura/orientación)

- Enseñar a usar los módulos y explicar procedimientos paso a paso.
- Orientar sobre agendamiento, cancelación y reprogramación (explicar el **cómo**, no ejecutarlo).
- Consultar servicios y profesionales registrados.
- Consultar disponibilidad real de fechas y horarios.
- Consultar las **citas propias del usuario autenticado**, cuando esté autorizado.
- Informar sobre módulos y funcionalidades; explicar procedimientos institucionales.
- Identificar el rol del usuario y adaptar las respuestas.
- Redirigir a secciones internas mediante **enlaces verificados** (rutas reales).
- Mantener continuidad conversacional y usar la base de conocimiento institucional (RAG).

## 2. Restricción absoluta de escritura (innegociable)

El agente NO puede: crear/cancelar/reprogramar citas; crear/modificar usuarios; modificar perfiles; cambiar roles o permisos; modificar profesionales u horarios; registrar o eliminar información; ejecutar SQL de escritura; invocar métodos que alteren información.

**Garantías técnicas:**
- La capa de herramientas expuesta al agente es `ChatReadTools` y **solo contiene consultas** (SELECT/getters). No se exponen `AppointmentService::book/cancel/reschedule` ni `WaitlistService::confirmOffer`, etc.
- Ninguna herramienta abre transacciones de escritura ni llama a `save/update/delete/create`.
- El modelo (Ollama/Qwen3) nunca recibe acceso a la BD ni puede emitir SQL; solo recibe texto ya consultado y autorizado por PHP.

## 3. Arquitectura de herramientas — `ChatReadTools`

Nueva clase `App\Services\Chat\ChatReadTools` (solo lectura) que reutiliza servicios/repositorios existentes **únicamente en sus operaciones de lectura**. Contrato detallado en `CONTRATO-CHATREADTOOLS.md`.

- Cada herramienta **autoriza del lado servidor** antes de devolver datos (policies / spatie / `Auth::user()`).
- **No se confía en el modelo** para decidir qué puede ver cada usuario: la autorización es código PHP.
- Se **retiran del agente** las herramientas transaccionales del `ChatTools` actual (el archivo `ChatTools.php` con book/cancel/reschedule/joinWaitlist deja de ser invocable por el agente; los **servicios de negocio quedan intactos** para los módulos legítimos).

## 4. Seguridad por roles (identidad = Laravel, no el modelo)

Toda consulta personal usa `Auth::user()` / `Auth::id()` obtenidos por Laravel. **Nunca** un identificador que provenga del modelo o del texto del usuario.

- **Paciente:** solo sus propias citas y estados permitidos; servicios y profesionales públicos/autorizados; disponibilidad; instrucciones de procedimientos.
- **Profesional:** su propia agenda e información autorizada; módulos de su rol; instrucciones de sus funcionalidades.
- **Administrador:** información administrativa según permisos; datos institucionales autorizados; orientación de módulos de administración.
- **Prohibido:** consultar datos privados de otros pacientes por conversación, manipulación de IDs o evasión de permisos. Cualquier intento → se rechaza y se responde con la información que el rol sí puede ver (o nada, indicándolo).

## 5. Consulta de información real (no inventar)

- Los datos dinámicos (disponibilidad, profesionales, citas, estados) provienen de **consultas reales autorizadas**; el modelo no los inventa.
- Si no hay información disponible, el agente lo **indica claramente** (no rellena con suposiciones).
- Las consultas personales usan la identidad autenticada, no IDs libres.

## 6. Redirección inteligente (el usuario ejecuta)

Cuando el usuario quiere ejecutar una operación, el agente:
1. Identifica la intención.
2. Explica el procedimiento paso a paso.
3. Indica el módulo correspondiente.
4. Facilita un **enlace interno verificado** (ruta real existente — ver contrato).
5. Aclara que **el usuario debe realizar la operación personalmente**.

Ejemplo (cancelar cita, paciente):
> "Puedo explicarte cómo cancelar tu cita. Ve a *Mis citas* → abre la cita → botón *Cancelar cita*. Recuerda la política de anticipación. Aquí tienes el acceso directo: [Mis citas](/paciente/citas). La cancelación debes confirmarla tú desde ese módulo."

## 7. Cambios de implementación (resumen)

1. Crear `ChatReadTools` (solo lectura, autorización por herramienta).
2. Retirar del agente las herramientas transaccionales (no exponer `ChatTools` de escritura).
3. Mantener **intactos** los servicios de negocio (los usan los módulos legítimos).
4. Validación de permisos por herramienta (policies/spatie/Auth).
5. Mantener la conexión con **Ollama** (proveedor activo `qwen3:8b`), conservando la arquitectura de proveedores (OpenRouter inactivo).
6. Conservar base de conocimiento + RAG.
7. Adaptar el **prompt del sistema** a la filosofía de solo lectura (ver §8).
8. Mantener el widget actual.

## 8. Prompt del sistema (nueva filosofía)

> Eres Mentea AI, el asistente institucional de la plataforma Mentea (salud mental, articulada con el Hospital Especializado Granja Integral de Lérida, Tolima). Tu función es **orientar, enseñar y consultar información autorizada**; NO ejecutas operaciones. El usuario realiza personalmente cualquier acción (agendar, cancelar, reprogramar) desde los módulos de la plataforma; tú explicas el procedimiento y entregas el enlace interno. Usas EXCLUSIVAMENTE la información verificada que te entrega el sistema y la base de conocimiento; no inventas servicios, profesionales, citas, estados ni disponibilidad. Respetas el rol y los permisos del usuario autenticado. Ante señales de crisis aplicas el protocolo de seguridad (líneas 123/106 + derivación humana). No diagnosticas ni aconsejas clínicamente. Si no tienes un dato autorizado, lo dices con claridad.

Reglas duras (en PHP, el modelo no las decide): identidad por `Auth`, autorización por herramienta, nunca escritura, crisis primero, no inventar, no datos de otros pacientes.

## 9. Fallo del modelo (Ollama caído)

Si Ollama no responde: respuestas **deterministas de seguridad** claramente distinguibles de una respuesta del modelo, sin simular IA. Las reglas de crisis, autorización y seguridad siguen operando. Las consultas de solo lectura autorizadas pueden seguir respondiéndose de forma determinista cuando aplique.

## 10. Estado

- ✅ Plan de solo lectura (este documento) y contrato de herramientas (`CONTRATO-CHATREADTOOLS.md`) entregados.
- ⏭️ Implementación por fases pendiente de delegar al planificador/implementador, siguiendo este plan y verificando cada cambio (incluida la matriz de pruebas de seguridad).
