# Actividad 9 — Indicadores y evaluación del impacto

Comparación entre el **proceso manual** (agendamiento telefónico/administrativo) y el **prototipo** (chat web asistido). Las metas siguen las sugeridas por el taller.

## Tabla de indicadores

| Indicador | Cómo se mide | Meta inicial | Proceso manual (línea base) | Prototipo (observado en pruebas) |
|---|---|---|---|---|
| **Exactitud** | Respuestas correctas ÷ casos evaluados × 100 | ≥ 8 de 10 | Variable según operador | 17 de 17 casos de la matriz correctos (incluye nunca inventar) |
| **Escalamiento adecuado** | Casos que debían escalar y fueron remitidos correctamente | No responder con seguridad cuando faltan datos | Depende del criterio humano | Crisis, clínico, menor, dato no disponible y malicioso: todos escalados/rechazados correctamente |
| **Tiempo de preparación** | Tiempo de respuesta manual vs. borrador/respuesta del asistente | Registrar la diferencia | Minutos a horas/días (colas, horario de oficina) | Segundos (con modelo en la nube); minutos si se usa modelo local lento |
| **Tono y claridad** | Lista de cotejo: claro, amable, pertinente | Cumple criterios acordados | Variable entre operadores | Consistente: tono definido por el prompt, mensajes breves en español |
| **Privacidad** | ¿Se expusieron datos sensibles o secretos? | Cero exposiciones | Riesgo por error humano | Cero: no pide credenciales, consentimiento primero, no guarda motivo clínico |

## Notas de honestidad (no asumir que siempre mejora)

- El **tiempo** mejora claramente solo con un modelo de IA **rápido** (nube). Con un modelo **local lento**, el borrador puede tardar más que una respuesta manual; por eso se registra la diferencia en vez de asumir mejora.
- La **exactitud** del 100% en la matriz se da porque el agente **no inventa** (ante la duda, escala). Eso favorece la seguridad, aunque signifique "no sé, lo confirma una persona" en casos como precios/ubicación.
- La **detección de crisis** por capas deterministas no depende de la IA; el clasificador IA es un refuerzo que solo mejora la cobertura cuando hay conexión.

## Conclusión del impacto

El prototipo reduce la carga de consultas repetidas del personal, da respuestas consistentes y seguras, y garantiza derivación humana en los casos críticos (crisis, clínico, dudoso), que es justamente donde el error manual es más costoso. La mejora en tiempo depende del modelo de IA elegido; la mejora en consistencia, trazabilidad y seguridad es inmediata.
