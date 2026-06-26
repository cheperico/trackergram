# 005 — Crear tracker al crear conexión

> **Fecha**: 26/06/2026
> **Estado**: Diseño — pendiente de implementación
> **Tags**: conexión, tracker, UX, admin

---

## 1. Problema

Hoy el flujo para conectar trackerGram a un nuevo grupo es:

1. Ir a pestaña **Crear Tracker** → llenar nombre, prefix, galería → submit → obtener tracker ID
2. Ir a pestaña **Webhook** → crear nueva conexión → tipear tracker ID manualmente
3. Guardar

Esto requiere dos pasos separados y obliga al usuario a copiarse un número de una pestaña a otra. No es crítico pero es fricción innecesaria.

---

## 2. Solución propuesta

Integrar la creación del tracker **dentro del modal de nueva conexión**. Es decir: si el usuario no tiene un tracker ID, puede crear el tracker directamente desde el mismo formulario.

### 2.1 UX

En el modal de conexión, el campo `tracker_id` hoy es un `<input type="number">`. Se agrega al lado un checkbox o botón "Crear tracker nuevo" que despliega campos adicionales:

```
Tracker ID: [________]  ☐ Crear tracker nuevo
                          ├─ Nombre: [________________]
                          ├─ Field prefix: [__________]
                          └─ Galería de medios: [Galería existente | Crear nueva]
```

- Si **no se tilda**: funciona como hoy (tracker_id manual)
- Si **se tilda**: el `tracker_id` se deshabilita y los campos de creación aparecen. Al guardar:
  1. Se crea el tracker vía `TikiWikiClient::createTracker()`
  2. Se obtiene el tracker ID
  3. Se guarda la conexión con ese tracker ID

### 2.2 Consideraciones

| Aspecto | A considerar |
|---------|-------------|
| **Prefix** | Si el usuario tipea un prefix, usarlo. Si no, usar `telegrammessage` (default). Debería persistirse en `field_prefix` de la conexión. |
| **Galería** | Las mismas opciones que en "Crear Tracker": usar galería existente o crear una nueva automáticamente. |
| **Errores** | Si el tracker se crea pero falla la galería, la conexión se guarda igual (el tracker existe, la galería se puede asignar después). |
| **Edición** | Al editar una conexión existente, NO mostrar la opción de crear tracker (el tracker ya existe). |

### 2.3 Backend

El handler `save_connection` en `admin.php` necesitaría:

```php
if (!empty($_POST['create_tracker'])) {
    $tikiClient = new TikiWikiClient(...);
    $trackerId = $tikiClient->createTracker(
        name: $_POST['tracker_name'],
        prefix: $_POST['field_prefix'] ?? 'telegrammessage',
        galleryId: $galleryId
    );
    if ($trackerId === null) {
        $errorMessage = 'Error al crear el tracker';
        break;
    }
    // Seguir con save_connection normal usando $trackerId
    $_POST['tracker_id'] = $trackerId;
    // Persistir field_prefix
    $data['field_prefix'] = $_POST['field_prefix'] ?? 'telegrammessage';
}
```

---

## 3. Por qué no se hizo todavía

- Requiere modificar el modal de conexión (HTML + JS)
- Requiere manejar estados: "modo tracker manual" vs "modo crear tracker"
- La validación CSRF y la lógica de guardado están en el mismo switch
- Hay que decidir el comportamiento exacto del campo galería

---

## 4. Alternativas descartadas

| Alternativa | Motivo |
|-------------|--------|
| **Wizard multi-paso** | Sobredimensionado para un proyecto chico |
| **Página separada de creación** | Es lo que ya existe (pestaña "Crear Tracker"), no resuelve la fricción |
| **Auto-crear al guardar si tracker_id=0** | El usuario perdería control sobre el nombre y prefix del tracker |

---

## 5. Prerequisitos

Antes de implementar esto, conviene tener:

- `TikiWikiClient::synchronizeTrackerFields()` — ya implementado ✅
- `TikiWikiClient::getTrackerFieldDefinitions()` como source of truth — ya implementado ✅
- El botón "Sincronizar tracker" en cada conexión — ya implementado ✅

Con esas piezas, la creación de tracker desde conexión es un paso UX que no requiere cambios arquitectónicos.
