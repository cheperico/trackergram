# 006 — MTProto + Pyrogram (Kurigram) como alternativa a Bot API

> **Fecha**: 01/07/2026
> **Estado**: Exploración / Diseño preliminar
> **Sesión**: Orquestador principal con usuario (cheperico)
> **Tags**: mtproto, pyrogram, kurigram, userbot, arquitectura, reevaluación
> **Decisión**: ⏳ Evaluar más adelante — documentado para referencia futura

---

## Propósito

trackerGram actualmente usa la **Telegram Bot API (HTTP)** para recibir mensajes (vía webhook) e importar historial (vía export ZIP de Telegram Desktop). Esta exploración analiza si convendría reemplazar (o complementar) ese stack con **MTProto directo vía Pyrogram o uno de sus forks activos**, usando cuenta de usuario o de bot.

### Por qué considerar esto ahora

- Los **exports ZIP** son un paso manual que requiere que el usuario los genere desde Telegram Desktop
- La **Bot API** no expone `getChatHistory`, limitando la importación automática
- El **Privacy Mode** de los bots requiere que sean admin del grupo para ver todos los mensajes
- Hay **forks activos de Pyrogram** (Kurigram, Irenogram) que mantienen vivo el ecosistema

---

## Stack Comparado

| Capa | Actual (Bot API) | Alternativa (MTProto) |
|------|-----------------|----------------------|
| **Protocolo** | HTTP REST + JSON | TL binario sobre TCP/WS/UDP |
| **Framework** | Clientes HTTP propios (TelegramClient.php) | Python (Kurigram / Irenogram) |
| **Crypto** | TLS estándar | AES-256 IGE + DH + SHA-256 |
| **Auth** | Bearer token de bot | `.session` persistente (auth_key) |
| **Deploy** | PHP + Apache, sin estado | Python + asyncio, stateful (.session file) |

---

## Escenarios

### Escenario A: MTProto con Bot Token (reemplazar webhook)

Los bots en MTProto tienen más métodos que en Bot API, pero **siguen sin poder obtener historial completo del chat**. No mejora la importación. El real-time sería vía polling (no webhook), lo cual es un paso atrás en simplicidad.

**Veredicto**: ❌ No conviene. El webhook HTTP es superior para bots en real-time.

### Escenario B: MTProto con User Account (userbot) — reemplazo total

Un user account conectado vía MTProto:

- ✅ Ve **todos los mensajes** del grupo (sin Privacy Mode, sin ser admin)
- ✅ Puede leer **historial completo** con `get_chat_history()`
- ✅ Puede descargar media sin límite de 20MB
- ✅ Acceso a toda la API de Telegram (canales, foros, reacciones, etc.)

**Riesgos**:
- ⚠️ Usar accounts de usuario para automatización está en zona gris de ToS
- ⚠️ La `.session` es más sensible que un bot token
- ⚠️ Si Telegram cambia algo en MTProto, el fork que uses tiene que actualizarse
- ⚠️ Estado (stateful) — no es tan fácil de escalar/replicar como webhooks sin estado
- ✅ Para **solo lectura** (trackerGram), el riesgo de ban es mínimo

**Veredicto**: ✅ Opción más interesante del análisis, pero requiere repensar la arquitectura.

### Escenario C: Híbrido (Bot API real-time + MTProto user para import)

- **Real-time**: Bot API webhook (PHP actual, probado, funciona)
- **Importación**: Script Python/Kurigram con user account para `get_chat_history()` + descarga directa de media
- **Autenticación**: El user se autentica una vez, se guarda la `.session`, se usa solo para importar

**Ventaja**: No tocar la infraestructura de real-time que ya funciona. Solo agregar un nuevo módulo de importación.

**Desventaja**: Mantener dos sistemas (PHP + Python), dos protocolos, dos formas de descargar media.

**Veredicto**: ✅ Alternativa pragmática si se quiere eliminar la dependencia de exports ZIP sin reescribir todo.

---

## Estado del Ecosistema Pyrogram

### Pyrogram (original)
- **Stars**: ~4600
- **Estado**: Archivado (no mantenido desde 2024)
- **Último release**: v2.0.106
- **Problema**: Layer desactualizado, errores de "unknown constructor" en métodos nuevos
- **❌ No usar**

### Kurigram (fork activo)
- **Base**: Pyrogram
- **Estado**: Mantenimiento activo
- **Soporte**: Gifts, Stories, Topics, Business Accounts, últimos layers de MTProto
- **PIP**: `pip install kurigram`
- **Docs**: kurigram.icu
- **✅ Recomendado para proyecto nuevo**

### Irenogram (fork activo)
- **Base**: Pyrogram
- **Estado**: Mantenimiento activo (2026)
- **Soporte**: Bot API 9.6, Managed Bots, Poll Revolution, Layer 224
- **Compatible**: Instalás como `irenogram`, importás como `pyrogram`
- **PIP**: `pip install irenogram`
- **Nota**: Más orientado a features de bot que a user accounts

---

## Cómo sería la arquitectura con Kurigram (user account)

Diagrama conceptual:

```
[Telegram Cloud]
      ↕ MTProto (TCP con obfuscación)
[Kurigram Client] ── asyncio ──► [trackerGram Python Service]
      │                                       │
      │  get_chat_history()                   │  POST /api/trackers/{id}/items
      │  on_message() callback                │  POST /api/galleries/upload
      │  download_media()                     │
      │                                       ▼
      │                              [TikiWiki API]
      │
      ▼
[.session file] (persistente, ~1KB)
```

**Componentes nuevos**:
- `tg_listener.py` — Daemon asyncio que se conecta vía Kurigram, recibe updates en vivo, escribe a buffer/cola
- `tg_importer.py` — Script CLI/web que usa `get_chat_history()` para importación masiva con checkpointing
- `session/` — Directorio con archivos `.session` (protegido como `config/`)

**Lo que NO cambia**:
- TikiWikiClient (PHP) sigue siendo el destino — el servicio Python traduce NormalizedMessage → fields y llama a la API de TikiWiki
- La estructura de trackers (schema) puede seguir igual
- La deduplicación por (chat_id, message_id) sigue funcionando

---

## Migración desde la arquitectura actual

| Paso | Qué | Esfuerzo |
|------|-----|----------|
| 1 | Elegir fork (Kurigram vs Irenogram) según necesidades | Bajo |
| 2 | Implementar `tg_listener.py` (updates polling + traducción a NormalizedMessage) | Medio |
| 3 | Implementar `tg_importer.py` (get_chat_history con checkpointing + progreso) | Medio |
| 4 | Decidir si media se baja vía MTProto o se sigue usando Bot API | Medio |
| 5 | Mantener Bot API webhook como respaldo o migrar completamente | Alto |
| 6 | Probar en staging con grupo real + cuenta de usuario dedicada | Alto |

---

## Puntos a resolver antes de decidir

- [ ] ¿Usar cuenta de usuario personal o crear una cuenta "bot" de usuario dedicada?
- [ ] ¿Riesgo real de ban por userbot en modo solo-lectura? (investigar casos documentados)
- [ ] ¿Kurigram/Irenogram tienen soporte para todos los tipos de mensaje que trackerGram necesita?
- [ ] ¿Performance de MTProto polling vs webhook HTTP para el mismo volumen de mensajes?
- [ ] ¿Se puede hacer deploy del servicio Python junto al PHP actual (mismo server)?
- [ ] ¿Cómo manejar la sesión expirada / 2FA de la cuenta de usuario?

---

## Conclusión

| Opción | Recomendación |
|--------|:------------:|
| MTProto + bot (reemplazar webhook) | ❌ No mejora nada |
| MTProto + user account (reemplazo total) | ✅ Interesante, requiere rewrite grande |
| Híbrido: Bot API webhook + MTProto user para import | ✅✅ Más pragmático, agrega valor sin romper lo que funciona |
| Seguir como hoy (Bot API + export ZIP) | ✅ Válido, probado, funciona |

**Recomendación para cuando se evalúe**: Ir por el **híbrido** como primer paso. Agrega el valor más grande (importación automática de historial sin exports ZIP) con el menor riesgo y esfuerzo. Si funciona bien, evaluar migración completa a MTProto.

---

## Referencias

- [MTProto Detailed Description](https://core.telegram.org/mtproto/description)
- [MTProto Transports](https://core.telegram.org/mtproto/mtproto-transports)
- [Pyrogram Documentation](https://docs.pyrogram.org)
- [Kurigram](https://github.com/KurimuzonAkuma/kurigram)
- [Irenogram](https://github.com/ISmartThinker/irenogram)
- [Telegram Bot API vs MTProto](https://docs.pyrogram.org/topics/mtproto-vs-botapi)
