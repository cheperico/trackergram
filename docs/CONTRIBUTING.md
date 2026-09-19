# CONTRIBUTING.md — Gestión de Documentación de trackerGram

> Este archivo contiene las **reglas detalladas de mantenimiento de documentación**.
> Antes existían en AGENTS.md §12; se movieron acá para mantener AGENTS.md liviano (contexto de alto valor para agentes).
> El **agente orquestador** es responsable de mantener la documentación sincronizada con el código.

## Reglas por archivo

| Archivo | Para quién | Qué contiene | Regla clave |
|---------|-----------|-------------|-------------|
| `README.md` | Usuario final (técnico + no técnico) | Qué hace, cómo se usa, instalación rápida | Simplificar, mover tecnicismos a otros docs |
| `TECHNICAL.md` | Hub técnico | Índice a `TECHNICAL-basico.md` / `TECHNICAL-avanzado.md` / `docs/TRACKER_SCHEMA.md` | No editar contenido, solo redirigir |
| `TECHNICAL-basico.md` | Curioso / pocos conocimientos | Qué hace, cómo lo hace, glosario + 8 pasos llanos + Mermaid, sin código | Explicar el "por qué" en lenguaje llano, neutro, ejemplo real |
| `TECHNICAL-avanzado.md` | Dev / básico-medio | Arquitectura profunda: código, `lib/`, fan-out, TOCTOU, álbumes, deuda v0.7.1, seguridad, constantes, lecciones | Código en `<details>`, decisiones con fundamento |
| `docs/TRACKER_SCHEMA.md` | Ambos | Schema 28 campos + INI importable (ex apéndice) | Fuente única del INI, link desde basico/avanzado |
| `INSTALL.md` | Usuario que instala | Pasos de instalación exhaustivos | Solo instalación, detalle completo |
| `roadmap.md` | Equipo de desarrollo | Items pendientes por fase | Marcar completados, agregar nuevos, consolidar |
| `AGENTS.md` | Agentes de IA | Contexto completo del proyecto | Fuente de verdad para agentes |
| `CAMBIOS.md` | Todos | Historial de cambios por versión | Changelog cronológico |
| `design/*` | Equipo de desarrollo | Diseño exploratorio pre-implementación (activos) | Mantener como referencia, pasar a roadmap cuando madure |
| `design/archived/*` | Equipo de desarrollo | Diseños implementados o consolidados | **Nunca borrar** — referencia histórica de decisiones |
| `reports/*` | Histórico | Auditorías externas | NO borrar, roadmap consolida items accionables |
| `opt/*` | Referencia versionada + uso local | `*.md` versionados (`visualizacion-tiki.md`, `shared_hosting.md`) son referencia histórica; credenciales locales no versionar | Versionar `*.md`, ignorar credenciales/`tmp/` |
| `tikipickit/` | PWA offline standalone | `tikipickit/README.md` + `tikipickit/roadmap.md` | Inconcluso, **no destacar** en README raíz hasta prueba |
| `templates/visualization/` | Templates base visualización | `item_template_base.smarty`, `page_template_base.txt` | Fuente para `VisualizationDeployer` |
| `lib/` | Programa | Clases `Client/`, `Core/`, `Infra/`, `Handler/` — ver `bootstrap.php` | `bootstrap.php` carga `lib/...`, no root |
| `config/setup.json` | Runtime (prod) | Conexiones multi-bot (generado por admin), `setup.json.example` es plantilla vacía | **NO versionar** `setup.json` (`*` no copia dotfiles, `rsync` con `.sftpignore`) |
| `.sftpignore` | Deploy | Lista de excludes para SFTP/rsync al dock | `rsync -av --exclude-from=.sftpignore` |
| `config.php` | Todos | Constantes globales, timeouts, versión del proyecto | **`TRACKERGRAM_VERSION` debe actualizarse en cada versión** — es la fuente de verdad que se muestra en la UI del admin |

## Reglas detalladas

### README.md
- **Debe** tener una sección de "Instalación Rápida" al inicio y enlaces al resto de la documentación
- **Debe** incluir tabla de mensajes soportados para que el usuario sepa qué esperar
- **Puede** incluir el schema del tracker (campos) solo si es necesario para configuración manual; si pesa mucho, mover a TECHNICAL.md
- **NO**: Decisiones de arquitectura, detalles internos de código, referencias a constantes internas

### TECHNICAL.md / TECHNICAL-basico.md / TECHNICAL-avanzado.md / docs/TRACKER_SCHEMA.md
- **Razón del split:** `TECHNICAL.md` tenía 1200 líneas y mezclaba público curioso con dev. Ahora `TECHNICAL.md` es hub de 10 líneas, `basico` (~350 líneas) es para pocos conocimientos (glosario + Mermaid + 8 pasos sin código), `avanzado` (~700 líneas) es para básico-medio (snippets `api.php:70`, `lib/Handler/WebhookHandler` TOCTOU, `lib/Infra/ConfigManager` fan-out, deuda, seguridad). `TRACKER_SCHEMA.md` es la única fuente del INI 28 campos.
- **Guía basico:** Lenguaje neutro, analogías (webhook = correo que toca puerta), ejemplo real `Hola #urgente + foto` → item, sin `flock`/`CURLOPT_RESOLVE`/`SSRF`.
- **Guía avanzado:** Código en `<details>`, explicar el "por qué" además del "cómo", mantener diagrama `lib/` `524-544`, deuda `551-610`, constantes `673-694`.
- **Fundamento:** Curioso entiende en 5 min (basico), junior entiende para contribuir (avanzado), ambos linkean a schema. Evita duplicación INI.
- **NO:** Poner INI en basico/avanzado (solo en schema), poner código en basico, poner glosario solo en avanzado.

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

### opt/* y templates/visualization/*
- **Propósito**: `opt/*.md` son documentación versionada de referencia (visualización, hosting). `templates/visualization/*` son templates base para deploy automático. Credenciales locales en `opt/` no se versionan.
- **Regla**: Versionar `*.md` y `templates/`; ignorar credenciales, `.env` local, `tmp/` vía `.gitignore`
- **tikipickit/**: PWA standalone inconclusa — mantener `tikipickit/README.md` y `tikipickit/roadmap.md` actualizados pero **no destacar** en README raíz hasta que esté probada

## Flujo de actualización

Cuando el orquestador recibe una tarea que toca documentación:
1. **Leer el código** — entender qué cambió realmente
2. **Leer cada doc** — identificar qué está obsoleto
3. **Evaluar reports/design/** — ¿hay items para mover/archivar?
4. **Actualizar docs** según las reglas de cada uno
5. **Verificar consistencia** — que los docs no se contradigan entre sí
6. **Reportar** al usuario qué cambió
