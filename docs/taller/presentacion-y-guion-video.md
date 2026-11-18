# Presentación y guion del video — Asistente Mentea

Documento con (A) el contenido de las diapositivas para la presentación de 5–8 min y (B) el guion del video/demostración. No mostrar contraseñas, tokens ni datos personales en pantalla.

---

## A. Presentación (diapositivas sugeridas, 5–8 min)

### Diapositiva 1 — Portada
- Título: "Asistente conversacional para Mentea (salud mental)"
- Logo de Mentea · Integrantes · Fecha
- Canal: Chat web

### Diapositiva 2 — Empresa y problema (≈45 s)
- Mentea: hospital de salud mental, 5 servicios.
- Problema: agendar toma horas/días, carga de consultas repetidas, citas perdidas, crisis que deben derivarse ya.

### Diapositiva 3 — Objetivo y alcance (≈45 s)
- Objetivo: atender consultas frecuentes y guiar el agendamiento usando solo info aprobada, escalando a una persona cuando toca.
- Resuelve: servicios, horarios, agendar/reprogramar/cancelar, documentos, lista de espera.
- Escala: crisis, preguntas clínicas, menores, casos dudosos.

### Diapositiva 4 — Arquitectura y herramientas (≈60 s)
- Laravel 13 + Blade/Alpine/Tailwind; SQLite/PostgreSQL; IA vía OpenRouter (nube) u Ollama (local).
- Principio: "el código manda, el modelo solo redacta" (la IA nunca inventa).
- Diagrama simple: navegador → ChatController → ChatAgentService (reglas duras + herramientas) → BD.

### Diapositiva 5 — Base de conocimiento, prompt y flujo (≈60 s)
- Base de conocimiento aprobada (servicios, horarios, documentos, copys).
- Prompt con rol/tono/límites + clasificador de crisis "escalate-only".
- Flujo: crisis → escalado → consentimiento → cupos reales → responder/registrar.

### Diapositiva 6 — Demostración (en vivo o video) (≈90 s)
- Mostrar 3–4 casos (ver guion del video abajo).

### Diapositiva 7 — Pruebas e indicadores (≈45 s)
- 17 casos probados; exactitud 17/17; escalado correcto en crisis/clínico/dudoso; cero exposición de datos.
- La mejora de tiempo depende del modelo (rápida en la nube).

### Diapositiva 8 — Riesgos, ética y mejoras futuras (≈45 s)
- Ética: transparencia, no diagnóstico, consentimiento, control humano.
- Limitaciones: crisis fail-open sin IA, latencia de modelo local, precios/ubicación pendientes.
- Futuro: indicador "procesando", correo/WhatsApp, más base de conocimiento.

### Diapositiva 9 — Cierre
- Conclusión en una frase + agradecimiento.

---

## B. Guion del video / demostración (≈3–5 min)

> Antes de grabar: iniciar sesión como `paciente@mentea.test` (NO mostrar la contraseña en pantalla). Tener el chat visible. Hablar claro y breve.

**[0:00–0:20] Introducción**
"Hola, somos [equipo]. Les presentamos el asistente conversacional de Mentea, un centro de salud mental. Funciona dentro de la web, por chat, y ayuda a resolver dudas y agendar citas de forma segura."

**[0:20–0:45] Mostrar el widget**
"Este es el asistente. Se presenta como un asistente automatizado —transparencia— y responde solo con información aprobada de Mentea."
- Acción: abrir el widget, mostrar el saludo.

**[0:45–1:30] Caso 1 — Consulta frecuente + agendar**
"Le preguntamos por los servicios y pedimos una cita."
- Escribir: "¿Qué servicios ofrecen?" → muestra los 5.
- Escribir: "Quiero agendar psicología" → ofrece 2–4 cupos reales.
- Elegir un cupo → el sistema re-verifica y confirma.
"Fíjense que los cupos salen de la agenda real: el asistente nunca inventa horarios."

**[1:30–2:10] Caso 2 — No inventar / dato no disponible**
- Escribir: "¿Cuánto cuesta la consulta?"
"No hay un precio aprobado, así que no lo inventa: indica que una persona lo confirma. Esto es clave en salud."

**[2:10–2:50] Caso 3 — Protocolo de crisis (el más importante)**
- Escribir un mensaje de crisis de ejemplo (p. ej. "ya no le encuentro sentido a nada").
"Ante señales de crisis, el asistente detiene el agendamiento, entrega las líneas de ayuda 123 y 106, y deriva de inmediato a una persona. La seguridad está por encima de todo."

**[2:50–3:20] Caso 4 — Escalado clínico**
- Escribir: "¿Qué medicamento tomo para la ansiedad?"
"No diagnostica ni receta: deriva a un profesional. El control humano siempre se mantiene."

**[3:20–3:50] Trazabilidad**
"Cada conversación queda registrada con su categoría y resultado, sin guardar el motivo clínico, respetando la Ley 1581 de protección de datos."
- (Opcional) mostrar brevemente la tabla `chat_messages` sin datos personales.

**[3:50–4:20] Cierre**
"En resumen: un asistente que responde rápido y consistente, que nunca inventa y que deriva a una persona en los casos críticos. Gracias."

---

### Checklist de grabación (requisito del taller)
- [ ] No se ven contraseñas, tokens ni claves API.
- [ ] No se exponen datos personales reales (usar datos de prueba).
- [ ] Se indica que es un asistente automatizado (transparencia).
- [ ] Duración total entre 5 y 8 min (presentación) / 3–5 min (demo).
