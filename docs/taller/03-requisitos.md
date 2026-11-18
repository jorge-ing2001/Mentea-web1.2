# Actividad 3 — Tabla de requisitos priorizados

## Roles

| Rol | Quién es | Responsabilidad |
|---|---|---|
| **Usuario** | Paciente (o acudiente) | Hace consultas y agenda/reprograma/cancela citas por el chat web. |
| **Administrador de información** | Personal administrativo de Mentea | Mantiene la base de conocimiento (servicios, horarios, textos) vía `SystemSetting` y `config/`. |
| **Aprobador de respuestas** | Equipo clínico/administrativo | Define y aprueba los textos (consentimiento, crisis, escalado) y atiende los casos escalados. |

## Requisitos funcionales (priorizados)

| ID | Requisito | Prioridad | Estado en el prototipo |
|---|---|---|---|
| RF-01 | El usuario podrá enviar una pregunta o mensaje. | Alta | ✅ Implementado (widget de chat → `POST /chat`) |
| RF-02 | El asistente identificará el tema o intención principal del mensaje. | Alta | ✅ Implementado (detección de intención: agendar, reprogramar, cancelar, info, crisis, escalado) |
| RF-03 | El asistente consultará información aprobada antes de responder. | Alta | ✅ Implementado (catálogo de servicios, disponibilidad real desde la agenda, copys aprobados) |
| RF-04 | La respuesta tendrá un tono respetuoso, claro y coherente con la empresa. | Alta | ✅ Implementado (system prompt define rol/tono; el modelo solo redacta sobre datos verificados) |
| RF-05 | Si falta información o no hay respuesta confiable, lo indicará y pedirá apoyo humano. | Alta | ✅ Implementado (escalado + "no inventar"; sin disponibilidad → lista de espera/alternativas) |
| RF-06 | El sistema registrará mensaje, categoría, respuesta y resultado de la evaluación. | Alta | ✅ Implementado (tablas `chat_conversations` y `chat_messages` con `meta`: intención, crisis, escalado) |
| RF-07 | Para correo, la primera versión generará un borrador sujeto a revisión humana. | Media | ◻️ Fuera del canal elegido; el correo de la plataforma está en modo borrador/log (mejora futura) |
| RF-08 | Para WhatsApp, podrá simular la conversación; conexión real opcional y autorizada. | Baja | ◻️ Simulación/futuro; canal del taller es chat web |

## Requisitos no funcionales

| ID | Requisito | Cómo se cumple en el prototipo |
|---|---|---|
| RNF-Privacidad | Usar datos ficticios o autorizados; no cargar información sensible en herramientas públicas. | Datos de prueba sembrados (usuarios `@mentea.test`). Consentimiento primero (Ley 1581/2012). No se guarda el motivo clínico. |
| RNF-Seguridad | No compartir contraseñas, tokens ni claves API en documentos/repos. | `.env` en `.gitignore`; la API key nunca se commitea; documentación sin secretos. |
| RNF-Transparencia | Informar que se interactúa con un asistente automatizado. | El saludo del widget se presenta como "asistente de Mentea". |
| RNF-Calidad | Respuestas basadas en información vigente; no inventar precios, políticas ni compromisos. | "El código manda, el modelo solo redacta": nunca inventa horarios/servicios; guardia anti-alucinación. |
| RNF-Usabilidad | Mensajes cortos, claros y fáciles de comprender. | Copys breves en español; opciones de cupo como botones. |
| RNF-Trazabilidad | Guardar evidencia de pruebas sin exponer datos personales. | Historial en BD con `meta` (categoría/flags), sin motivo clínico. |
| RNF-Control humano | Permitir que una persona revise respuestas y atienda casos complejos. | Rutas de escalado (crisis, clínico, dudoso) marcan la conversación y derivan al equipo. |
