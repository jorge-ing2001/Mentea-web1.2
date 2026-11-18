# Informe final — Asistente conversacional Mentea
## Taller Integrador · IA aplicada a procesos empresariales

---

## 1. Portada y nombres de los integrantes

**Proyecto:** Asistente conversacional (chat web) para Mentea — salud mental
**Empresa:** Mentea (hospital de salud mental, Colombia)
**Canal:** Chat web embebido en la plataforma
**Repositorio:** jorge-ing2001/Mentea-web1.2

**Integrantes:**
- _[Nombre y apellido]_ — _[rol]_
- _[Nombre y apellido]_ — _[rol]_
- _[Nombre y apellido]_ — _[rol]_

> (Completar con los nombres reales del equipo.)

---

## 2. Descripción de la empresa y del problema

**Mentea** es un centro/hospital de salud mental en Colombia cuyo lema es "Conecta con tu bienestar mental". Ofrece cinco servicios: Psicología, Psiquiatría, Neuropsicología, Terapia Ocupacional y Terapia Física.

**Problema:** el agendamiento de citas y la resolución de consultas básicas dependen de atención telefónica y manual del personal administrativo. Esto causa demoras (horas o días para conseguir cita), carga operativa por preguntas repetidas (horarios, servicios, documentos), citas perdidas por olvido e inconsistencia en la información. Además, los mensajes con señales de crisis deben derivarse de inmediato a una persona, y el proceso manual no garantiza esa derivación.

---

## 3. Objetivo general y objetivos específicos

**Objetivo general:** implementar un asistente conversacional en el chat web de Mentea que atienda consultas frecuentes y guíe el agendamiento de citas usando solo información aprobada, derivando a una persona cuando el caso lo requiera.

**Objetivos específicos:**
1. Identificar la intención del mensaje (agendar, reprogramar, cancelar, información, crisis, caso clínico).
2. Responder solo con base de conocimiento aprobada y disponibilidad real de la agenda (nunca inventar).
3. Agendar, reprogramar y cancelar citas reales con consentimiento previo (Ley 1581/2012).
4. Detectar señales de crisis y escalar a una persona de inmediato.
5. Registrar cada interacción (categoría, respuesta, resultado) para trazabilidad.

---

## 4. Alcance, usuarios y requisitos

**Alcance (canal chat web):** resuelve servicios, horarios, agendar/reprogramar/cancelar, documentos requeridos, modalidad, lista de espera e información general. Remite a una persona: crisis, preguntas clínicas, menores/acudientes, reclamos y casos fuera de alcance. (Detalle en `02-alcance-exclusiones.md`.)

**Usuarios:** paciente/acudiente (usuario final), personal administrativo (mantiene la información) y equipo clínico/administrativo (aprueba textos y atiende escalados).

**Requisitos:** 8 funcionales (RF-01…RF-08) y 7 no funcionales (privacidad, seguridad, transparencia, calidad, usabilidad, trazabilidad, control humano). Tabla priorizada en `03-requisitos.md`.

---

## 5. Herramientas y arquitectura de la solución

**Stack:** Laravel 13 (PHP 8.3), Blade + Alpine.js + Tailwind CSS (Vite). Base de datos SQLite en local / PostgreSQL en producción (Render). IA vía una API compatible con OpenAI (OpenRouter en la nube, u Ollama local para pruebas), configurable por variable de entorno.

**Arquitectura del agente (presentación del flujo):**

```
Navegador (widget de chat Blade + Alpine)
        │  POST /chat (sesión + CSRF)
        ▼
ChatController
        ▼
ChatAgentService  (orquestador — EL CÓDIGO MANDA)
   ├── Reglas duras en PHP: crisis → escalado → consentimiento → cupos reales
   ├── Herramientas: AvailabilityService / AppointmentService / WaitlistService
   └── OpenRouterClient (solo REDACTA; degradación grácil si no hay IA)
        ▼
chat_conversations + chat_messages  (memoria + trazabilidad)
```

**Principio rector:** el modelo de lenguaje solo redacta sobre datos ya verificados por el código; nunca decide reglas ni inventa información.

---

## 6. Diseño de la base de conocimiento, prompt y flujo

- **Base de conocimiento:** catálogo de 5 servicios, horarios y documentos requeridos; copys aprobados de consentimiento, crisis y escalado (configurables en `config/chat.php` y sobreescribibles vía `SystemSetting`). ≥14 preguntas y respuestas en `04-base-conocimiento.csv`, con lo "no disponible" marcado para revisión humana.
- **Prompt:** rol, objetivo, tono, fuentes permitidas, formato, límites y reglas de escalado documentados y versionados en `06-prompt.md`. Incluye el clasificador de crisis "escalate-only".
- **Flujo:** diagrama en `05-diagrama-flujo.md` (orden crisis → escalado → consentimiento → intención → responder/escalar → registrar).

---

## 7. Implementación: capturas o evidencias

Evidencias disponibles en el prototipo funcional:
- **Widget de chat** en la plataforma (botón flotante + ventana de conversación).
- **Flujo de agendamiento:** oferta de 2–4 cupos reales → selección → confirmación.
- **Protocolo de crisis:** respuesta con líneas 123/106 y marca de escalado.
- **Persistencia:** registros en `chat_conversations` / `chat_messages` con `meta` (categoría y banderas).

> Insertar aquí las capturas de pantalla del equipo (sin mostrar contraseñas, tokens ni datos personales). Sugerencia: (a) saludo del asistente, (b) oferta de cupos, (c) respuesta de crisis, (d) respuesta de escalado clínico, (e) registro en base de datos.

---

## 8. Pruebas, resultados e indicadores

Se ejecutó una batería de 17 casos (normales, incompletos, confusos, maliciosos, de privacidad, de crisis y fuera de alcance) — ver `08-matriz-pruebas.md`. Todos cumplieron el resultado esperado, con énfasis en no inventar y escalar correctamente.

Indicadores (ver `09-indicadores.md`): exactitud (17/17 en la matriz), escalamiento adecuado en todos los casos críticos, tono consistente, cero exposiciones de datos sensibles. La mejora de tiempo depende del modelo de IA usado (rápida en la nube; lenta con modelo local).

---

## 9. Riesgos, limitaciones y aspectos éticos

**Riesgos y limitaciones:**
- La detección de crisis por palabras clave puede no cubrir toda paráfrasis si no hay IA conectada (fail-open documentado); las capas deterministas son el piso seguro.
- Modelos locales lentos degradan la experiencia; se recomienda un modelo rápido en producción.
- Precios/ubicación aún no aprobados: se marcan como "revisión humana".

**Aspectos éticos:**
- **Transparencia:** el usuario sabe que habla con un asistente automatizado.
- **No daño:** el agente no diagnostica ni aconseja clínicamente; ante crisis, prioriza la derivación humana y las líneas de ayuda.
- **Privacidad (Ley 1581/2012):** consentimiento antes de datos; no se guarda el motivo clínico; no se piden credenciales.
- **Control humano:** toda decisión sensible queda en manos de una persona.

---

## 10. Conclusiones y mejoras futuras

**Conclusiones:** el prototipo demuestra que un asistente acotado y seguro puede resolver las consultas frecuentes y guiar el agendamiento real de Mentea, manteniendo el control humano en los casos críticos. El diseño "el código manda, el modelo solo redacta" evita que la IA invente información, que es el principal riesgo en salud.

**Mejoras futuras:**
- Indicador visual "procesando tu mensaje…" para esperas largas.
- Canal de correo (borrador con revisión humana) y WhatsApp (simulación → conexión real autorizada).
- Ampliar la base de conocimiento (precios/ubicación aprobados).
- Reforzar la detección de crisis y las métricas en producción.

---

## 11. Referencias y enlaces consultados

- Ley 1581 de 2012 (Colombia) — Protección de Datos Personales.
- Documentación del proyecto: `docs/CHAT_AGENT.md`, `docs/REDISENO_UXUI.md`.
- Laravel 13, Tailwind CSS, Alpine.js (documentación oficial).
- OpenRouter / Ollama (APIs de modelos de lenguaje).
- Línea de emergencias nacional 123; línea de atención en salud mental 106.

> (Añadir aquí cualquier otro enlace o material consultado por el equipo.)
