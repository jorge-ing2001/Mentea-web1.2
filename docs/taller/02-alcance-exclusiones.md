# Actividad 2 — Objetivo, alcance y exclusiones

## Canal elegido

**Chat web** embebido en la plataforma de Mentea (widget flotante). Es un único canal, ya implementado y funcional. El correo electrónico y WhatsApp quedan como mejoras futuras (ver exclusiones).

## Objetivo del asistente

Atender consultas frecuentes de pacientes sobre los servicios de salud mental de Mentea y ayudarles a agendar, reprogramar o cancelar una cita de forma guiada, usando únicamente información aprobada por la empresa, y derivando a una persona cuando el caso lo requiere.

## Tipos de consulta que SÍ resuelve (entre 5 y 10)

1. **Servicios disponibles** — qué especialidades ofrece Mentea.
2. **Horarios de atención** — días y franjas de atención.
3. **Agendar una cita** — ofrecer cupos reales y reservar.
4. **Reprogramar una cita** existente.
5. **Cancelar una cita** existente.
6. **Documentos requeridos** — orden médica, autorización, copia de cédula.
7. **Modalidad** — presencial o virtual.
8. **Lista de espera** — anotar al paciente cuando no hay cupo.
9. **Información general / contacto** — cómo obtener ayuda humana.

## Casos que SE REMITEN a una persona (exclusiones / escalado)

- **Señales de crisis o riesgo** (ideación suicida, autolesión, desesperanza severa): se detiene el agendamiento, se entregan las líneas de ayuda (emergencias 123 y línea de crisis) y se alerta al equipo humano de inmediato.
- **Preguntas clínicas**: diagnósticos, qué medicamento tomar, dosis, recetas. El agente **no diagnostica ni aconseja clínicamente**.
- **Menores de edad o acudientes**: se derivan para manejo con el acudiente.
- **Reclamos, casos dudosos o fuera de alcance**: se pasan al equipo.
- **Validez final de documentos**: el agente solo verifica presencia/legibilidad; la validez la decide el personal.

## Fuera del alcance de esta primera versión

- Conexión real con WhatsApp (solo simulación/futuro, requiere autorización).
- Envío real de correos (en la plataforma el correo está en modo borrador/log).
- Diagnóstico o cualquier orientación clínica.
- Gestión de pagos o autorizaciones con aseguradoras.
- Decidir qué servicio necesita el paciente (lo define la orden médica).
