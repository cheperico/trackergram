# CONTRIBUTING.md — Gestión de Documentación de trackerGram

> Este archivo contiene las **reglas detalladas de mantenimiento de documentación**.
> Antes existían en AGENTS.md §12; se movieron acá para mantener AGENTS.md liviano (contexto de alto valor para agentes).
> El **agente orquestador** es responsable de mantener la documentación sincronizada con el código.

## Reglas por archivo

| Archivo | Para quién | Qué contiene | Regla clave |
|---------|-----------|-------------|-------------|
| `README.md` | Usuario final (técnico + no técnico) | Qué hace, cómo se usa, instalación rápida | Simplificar, mover tecnicismos a otros docs |
| `TECHNICAL.md` | Desarrollador / aprendiz | Decisiones de arquitectura, flujo educativo, lecciones | Explicar el "por qué" + "cómo" |
| `INSTALL.md` | Usuario que instala | Pasos de instalación exhaustivos | Solo instalación, detalle completo |
| `roadmap.md` | Equipo de desarrollo | Items pendientes por fase | Marcar completados, agregar nuevos, consolidar |
| `AGENTS.md` | Agentes de IA | Contexto completo del proyecto | Fuente de verdad para agentes |
| `CAMBIOS.md` | Todos | Historial de cambios por versión | Changelog cronológico |
| `design/*` | Equipo de desarrollo | Diseño exploratorio pre-implementación (activos) | Mantener como referencia, pasar a roadmap cuando madure |
| `design/archived/*` | Equipo de desarrollo | Diseños implementados o consolidados | **Nunca borrar** — referencia histórica de decisiones |
| `reports/*` | Histórico | Auditorías externas | NO borrar, roadmap consolida items accionables |
| `opt/*` | Uso local | Credenciales, templates de instancia | NO versionar en GitHub |
| `config.php` | Todos | Constantes globales, timeouts, versión del proyecto | **`TRACKERGRAM_VERSION` debe actualizarse en cada versión** — es la fuente de verdad que se muestra en la UI del admin |

## Reglas detalladas

### README.md
- **Debe** tener una sección de "Instalación Rápida" al inicio y enlaces al resto de la documentación
- **Debe** incluir tabla de mensajes soportados para que el usuario sepa qué esperar
- **Puede** incluir el schema del tracker (campos) solo si es necesario para configuración manual; si pesa mucho, mover a TECHNICAL.md
- **NO**: Decisiones de arquitectura, detalles internos de código, referencias a constantes internas

### TECHNICAL.md
- **Debe** ser educativo: explicar el "por qué" además del "cómo"
- **Debe** actualizarse cuando cambia la arquitectura (DI, multi-conexión, async, etc.)
- **Debe** incluir referencias a APIs externas (Telegram, TikiWiki)
- **Debe** incluir lecciones aprendidas y problemas resueltos
- **NO**: Guías de instalación, tablas de compatibilidad de mensajes (eso va en README)

### INSTALL.md
- **Debe** actualizarse cuando cambian los requisitos de instalación
- **Debe** ser exhaustivo: cubrir todos los pasos desde cero (crear bot, configurar TikiWiki, deploy, configurar webhook)
- El README tiene la "instalación rápida", este es el detalle completo

### roadmap.md
- **Debe** actualizarse cuando un item se completa (mover a "funciona sólido")
- **Debe** agregar items nuevos cuando surgen
- Items de `design/` pasan al roadmap cuando el diseño está listo y solo necesita retoques de implementación
- **NO**: Items ya implementados (solo si están en "funciona sólido")
- **Formato**: Tabla por fase con #, Item, Esfuerzo, Notas/Por qué ahora

### design/*
- **Propósito**: Capturar decisiones, alternativas y discusiones antes de implementar (activos)
- **Cuándo pasan a roadmap**: Cuando el diseño avanza lo suficiente y solo necesita retoques de implementación
- **Cuándo archivar**: Cuando el diseño está implementado o consolidado en otro documento — mover a `design/archived/`
- **NO**: Borrar — mantener como referencia histórica de decisiones (archivados también)

### reports/*
- **Propósito**: Referencia histórica de investigaciones/auditorías externas
- **Cuándo borrar**: NUNCA — mantener como referencia. El roadmap.md ya consolidó los items accionables.
- Excepción: Si un reporte fue dividido y absorbido completamente por otro archivo (ej: template-wiki-feed → opt/visualizacion-tiki.md), el original puede eliminarse.

### opt/*
- **Propósito**: Credenciales locales, templates específicos de instancia, cosas útiles pero no parte del código
- **NO versionar en GitHub** — son locales

## Flujo de actualización

Cuando el orquestador recibe una tarea que toca documentación:
1. **Leer el código** — entender qué cambió realmente
2. **Leer cada doc** — identificar qué está obsoleto
3. **Evaluar reports/design/** — ¿hay items para mover/archivar?
4. **Actualizar docs** según las reglas de cada uno
5. **Verificar consistencia** — que los docs no se contradigan entre sí
6. **Reportar** al usuario qué cambió
