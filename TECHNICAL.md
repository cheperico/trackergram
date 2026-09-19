# TECHNICAL.md — Hub de documentación técnica

> Este archivo es el **hub**. El contenido se dividió en dos niveles para públicos distintos. Elige tu nivel:

| Documento | Para quién | Qué encontrás |
|---|---|---|
| [`TECHNICAL-basico.md`](TECHNICAL-basico.md) | **Pocos conocimientos técnicos** | Qué hace trackerGram, cómo lo hace y por qué, sin código. Glosario, ejemplo real, 8 pasos en lenguaje llano + Mermaid. 5 min. |
| [`TECHNICAL-avanzado.md`](TECHNICAL-avanzado.md) | **Conocimientos básicos a medios** | Cómo está construido con detalle: snippets `api.php`/`lib/Handler/WebhookHandler`, `lib/Infra/ConfigManager` fan-out, TOCTOU, álbumes, reintentos, deuda v0.7.1, seguridad, lecciones, constantes. |
| [`docs/TRACKER_SCHEMA.md`](docs/TRACKER_SCHEMA.md) | Ambos | Schema de 28 campos + INI importable (extraído del apéndice). |

## Historia

Antes `TECHNICAL.md` tenía 1200 líneas con código, deuda y 500 líneas de INI. Ahora `TECHNICAL-basico.md` (~350 líneas) explica el flujo para curiosos y `TECHNICAL-avanzado.md` (~700 líneas) conserva todo el detalle técnico. El INI vive en `docs/TRACKER_SCHEMA.md`.

```
TECHNICAL.md (hub) → TECHNICAL-basico.md (llano) → TECHNICAL-avanzado.md (profundo) → docs/TRACKER_SCHEMA.md (INI)
```

## Dónde seguir

* **Instalar:** [`INSTALL.md`](INSTALL.md)
* **Usar:** [`README.md`](README.md)
* **Roadmap/deuda:** [`roadmap.md`](roadmap.md)
* **Contribuir:** [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) (ver sección `TECHNICAL-basico/avanzado`)
