# Pretty Tracker en trackerGram — Investigación y Decisión

> Este documento registra la investigación sobre Pretty Tracker de TikiWiki, por qué **no se usó** en trackerGram, y cuál es la alternativa correcta para el futuro.

---

## ¿Qué es Pretty Tracker?

Es una funcionalidad de TikiWiki que permite personalizar **la vista de un item individual** de un tracker. Reemplaza la tabla genérica de campos uno abajo del otro por HTML/Smarty personalizado.

Se configura en: **Admin → Trackers → (tracker) → Format → Template to display an item**

Acepta tres tipos de referencia:
- `wiki:NombrePagina` — wiki page que pasa por el parser wiki (escapa `&`, agrega `<br />`)
- `tplwiki:NombrePagina` — wiki page que va directo a Smarty sin parseo wiki (recomendado)
- `archivo.tpl` — archivo físico en `templates/` del servidor

---

## ¿Qué investigamos?

1. **Creamos** un template personalizado (`tracker-pretty-template.wiki`)
2. **Configuramos** el tracker para usarlo (con `wiki:` primero, después `tplwiki:`)
3. **Debuggeamos** errores de Smarty (el wiki parser rompe `&&`, agrega `<br />`, no procesa `{~`)
4. **Documentamos** la solución en `PRETTY_TRACKER.md`

---

## Por qué NO lo usamos

**Pretty Tracker solo personaliza la vista de UN item.** Para un chat de Telegram lo que importa es la **vista de lista** — ver todos los mensajes uno atrás del otro como un feed. Pretty Tracker no puede hacer eso.

La vista de lista nativa de TikiWiki (`tiki-view_tracker.php`) **no es personalizable** sin hackear el core. Siempre renderiza una tabla HTML fija.

---

## Alternativa correcta para el futuro

Para lograr un feed tipo chat con todos los mensajes, hay que usar **PluginList** (o PluginTrackerList) dentro de una wiki page, con un template Smarty personalizado:

```
{LIST()}
    {filter type="trackeritem"}
    {filter field="tracker_id" content="12"}
    {sort mode="created_desc"}
    {pagination max="50"}
    {output tplwiki="Chat Message Template"}
{LIST}
```

Esto permite:
- ✅ **Todos los mensajes** en un solo flujo continuo
- ✅ Multimedia embebida (imágenes, videos, audio)
- ✅ Autor, fecha, reacciones, topics
- ✅ Paginación, filtros, sorting
- ✅ Control total del HTML/CSS
- ✅ No depende de la tabla nativa del tracker

---
## ✅ Implementado (v0.2.1)

La solución está **implementada y funcionando**:

- **Plugin elegido**: `{TRACKERLIST}` (PluginTrackerList) — consulta directa a BD, no requiere Unified Search
- **Template**: `plantillaTrackergram` (wiki page con `tplwiki:`) con Smarty para renderizar cada burbuja de chat
- **Página principal**: `ChatTelegram` (o la página wiki que contiene el `{TRACKERLIST(...)}`)
- **Multimedia**: HTML5 directo — `<img>`, `<video controls>`, `<audio controls>` según el tipo de mensaje
- **CSS**: Via `{HTML()}<style>...</style>{HTML}` en la misma página (requiere plugin HTML habilitado y aprobado)
- **Datos**: `mediaUrl` (`$f_184`) se popula automáticamente desde webhook e import

### Detalles de implementación

| Componente | Ubicación | Descripción |
|---|---|---|
| Plugin | `{TRACKERLIST(trackerId="22", tplwiki="plantillaTrackergram", sort_mode="f_176_desc")}` | Lista items del tracker 22 ordenados por fecha descendente |
| Template | Página wiki `plantillaTrackergram` | Smarty con burbujas de chat, multimedia condicional, reacciones |
| CSS | En la página principal via `{HTML()}` | Estilos de burbuja, header, multimedia, reacciones |
| `mediaUrl` | `WebhookHandler.php`, `import.php`, `MessageMapper.php` | Se popula tras cada upload exitoso |

### Lecciones aprendidas

1. `{CSS()}` **no existe** como plugin en TikiWiki 27.x — usar `{HTML()}<style>` en su lugar (con plugin HTML habilitado)
2. `{$f_XX|wiki}` no es un modifier Smarty válido en tplwiki — usar `{wiki}{$f_XX}{/wiki}` o mostrar texto plano
3. Los templates `tplwiki` reciben los fieldIds numéricos del tracker como `{$f_XX}`
4. El plugin `img` de TikiWiki espera un **fileId numérico**, no el display del campo FG
5. Es más confiable poblar `mediaUrl` desde el código PHP y usar HTML5 directo que depender de los plugins de TikiWiki para multimedia

---

## Resumen técnico

| Aspecto | Pretty Tracker | PluginList + template |
|---|---|---|
| Ámbito | Item individual | Lista completa |
| Adecuado para chat | ❌ | ✅ |
| Personalización | HTML/Smarty | HTML/Smarty |
| Configuración | En el tracker (Format) | Wiki page |
| Template | `tplwiki:` o `.tpl` | `tplwiki:` o `.tpl` |
| Permisos | `tiki_p_use_as_template` | `tiki_p_use_as_template` |
| Cache | No cacheable | Cacheable con índice unificado |
| Rendimiento con miles de items | N/A | Bueno con `server="y"` |

---

## Referencias

- [Documentación de Pretty Tracker](https://doc.tiki.org/Pretty-Tracker)
- [PluginList documentation](https://doc.tiki.org/PluginList)
- [PluginTrackerList documentation](https://doc.tiki.org/PluginTrackerList)
- [Pretty Tracker How-To](https://doc.tiki.org/Pretty-Tracker-HowTo)
