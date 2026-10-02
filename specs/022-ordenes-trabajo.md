# Spec: Orden de trabajo de la venta (colores de tinta, diseño, estados y hoja de producción)

> **Estado: implementada** el 2026-10-02. Definida con el usuario ese mismo día (ver "Supuestos
> asumidos" y "Estado de implementación").

**Modifica:**

- [019-pedidos-mostrador.md](019-pedidos-mostrador.md): esa spec dice "El sistema **no da
  seguimiento a la fabricación**: no hay órdenes de trabajo, dibujos, colores de tinta ni estados de
  producción". Desde aquí **sí los hay**, en versión mínima y colgados de la venta. El resto de 019
  no cambia: el ticket, la etiqueta, la entrega por escaneo y la autofactura siguen igual.
- [019](019-pedidos-mostrador.md) (`PedidoController::update`): al reescribir las líneas de una
  venta con orden, se conservan los colores ya capturados (ver "Las líneas de la venta cambian").
- [019](019-pedidos-mostrador.md) (listado de ventas): etiqueta "Sin orden" y botón "Hoja de
  producción".

**No modifica:** Cotizaciones (011), Facturación (012), Tesorería (016), Inventario (018) ni la
aceptación de cotizaciones (021). Las imágenes de artículos (010) solo ceden su conversión a WEBP a
una clase compartida, sin cambiar de comportamiento.

## Historia de usuario

Como usuario, quiero agregarle una **orden de trabajo** a una venta ya cobrada (PED-0004), con el
nombre y teléfono del cliente, el número de venta y, por cada artículo, su modelo, su nombre y el
color de tinta, además de la imagen del diseño. Quiero llevarla por los estados **En dibujo → En
proceso → Terminado**, imprimirla y, de una vez, imprimir en una sola hoja todas las órdenes que
están en proceso para ir a producción.

```
VENTA PED-0004  (con al menos un pago)
     │
     └── [Orden de trabajo]
              │
              ├── Cliente, teléfono, PED-0004        ← copiados de la venta, solo lectura
              ├── Sello 4912 · Sello automático    · Tinta: Azul
              ├── Sello 4911 · Fechador            · Tinta: Rojo
              ├── Imagen del diseño (una por orden)
              └── Estado:  En dibujo → En proceso → Terminado

HOJA DE PRODUCCIÓN  =  todas las órdenes "En proceso", con miniatura del diseño
```

## Objetivo / Alcance

1. Una entidad nueva, **Orden de trabajo**, que cuelga **solo de la venta** (`Pedido`), tanto la de
   mostrador como la nacida de una cotización aceptada (021). Una venta, una orden.
2. Se crea cuando la venta tiene **al menos un pago**, de cualquier monto.
3. Cliente, teléfono y folio **no se guardan en la orden**: se leen de la venta. La orden solo
   guarda lo que es suyo: el color de tinta por línea, la imagen y el estado.
4. Tres estados, solo hacia adelante: `en_dibujo`, `en_proceso`, `terminado`.
5. Una imagen del diseño por orden, reducida y convertida a WEBP como las fotos de artículos (010).
6. Dos vistas de impresión: la **orden individual** y la **hoja de producción** (todas las órdenes
   `en_proceso`, con miniatura del diseño).
7. Laravel + Blade + JavaScript nativo, como el resto del sistema. **Ninguna llamada AJAX nueva.**

**No** incluye folio propio, QR en la orden, notas, fecha de entrega, tablero de producción ni
retroceso de estados (ver "Fuera de alcance").

## Backend (Laravel)

### Enums

- `EstadoOrdenTrabajo` (nuevo), backed por `string`:

  | Caso | Valor | Etiqueta | Clase |
  |---|---|---|---|
  | `EnDibujo` | `en_dibujo` | En dibujo | `etiqueta-en-dibujo` |
  | `EnProceso` | `en_proceso` | En proceso | `etiqueta-en-proceso` |
  | `Terminado` | `terminado` | Terminado | `etiqueta-terminado` |

  Con `etiqueta()`, `claseEtiqueta()`, `opciones()` y `siguiente(): ?self` (`EnDibujo` →
  `EnProceso` → `Terminado` → `null`). Las tres clases son nuevas en `app.css`, con los tokens de
  [003](003-estilo-uniforme.md).

- `ColorTinta` (nuevo), backed por `string`: `negro` (Negro), `azul` (Azul), `rojo` (Rojo),
  `verde` (Verde), `morado` (Morado) y `otro` (Otro), con `etiqueta()` y `opciones()`.

### Migración `..._create_ordenes_trabajo_tables.php`

**Tabla `ordenes_trabajo`**

| Columna | Definición |
|---|---|
| `id` | `id()` |
| `user_id` | `foreignId` → `users`, `cascadeOnDelete` |
| `pedido_id` | `foreignId` → `pedidos`, **`cascadeOnDelete`**, **único** |
| `estado` | `string(20)`, default `en_dibujo`, índice |
| `imagen_ruta` | `string`, nullable |
| `timestamps` | |

- `pedido_id` único: **una venta, una orden**.
- `cascadeOnDelete`: si se borra la venta, se borra su orden (supuesto 17). El archivo de la imagen
  lo borra el modelo (ver "Modelo `OrdenTrabajo`"), porque la base de datos no sabe de archivos.
- Sin columna de folio: la orden se identifica por el folio de la venta (supuesto 12).

**Tabla `orden_trabajo_lineas`**

| Columna | Definición |
|---|---|
| `id` | `id()` |
| `orden_trabajo_id` | `foreignId` → `ordenes_trabajo`, `cascadeOnDelete` |
| `pedido_linea_id` | `foreignId` → `pedido_lineas`, **`cascadeOnDelete`**, **único** |
| `color_tinta` | `string(20)` |
| `color_tinta_otro` | `string(40)`, nullable |
| `timestamps` | |

- Un renglón por cada línea de la venta que ya tiene color. Modelo, nombre y cantidad **no se
  copian**: se leen de `pedido_lineas` por la relación, así la orden siempre muestra lo que dice la
  venta (supuestos 5, 8 y 14).
- `color_tinta_otro` solo se llena cuando `color_tinta = otro`.

`down()` borra las dos tablas. Los archivos de `ordenes-trabajo/` no se tocan.

### Modelo `OrdenTrabajo` (nuevo)

- `#[Fillable]` vacío: `estado`, `imagen_ruta` y `pedido_id` los escriben solo el controlador, los
  métodos de regla y el procesador de imágenes.
- `const DIRECTORIO_IMAGENES = 'ordenes-trabajo'` (disco `local`, privado, como 010).
- Casts: `estado` → `EstadoOrdenTrabajo`.
- Relaciones: `user()`, `pedido(): BelongsTo`, `lineas(): HasMany` (`OrdenTrabajoLinea`).
- Atributos: `tiene_imagen` e `imagen_version`, con la misma lógica que `Articulo`, para que un
  reemplazo cambie la URL y el navegador no muestre la imagen vieja.
- **Métodos de regla** (los usan controlador, Policy y Blade):
  - `esEditable(): bool` → la venta **no** está entregada (supuesto 15).
  - `puedeAvanzar(): bool` → `motivoNoAvanza() === null`.
  - `motivoNoAvanza(): ?string`, el primer motivo en este orden:
    - "La orden ya está terminada." (`siguiente()` es `null`);
    - "La venta ya se entregó." (no `esEditable()`);
    - "Falta el color de tinta de: Sello 4911." (alguna línea de la venta sin renglón; ver "Las
      líneas de la venta cambian").
  - `avanzar(): void` → `estado = estado->siguiente()`. No guarda.
  - `lineasSinColor(): Collection` → las líneas de la venta sin renglón en la orden.
- Evento `deleted`: borra `imagen_ruta` del disco con `DB::afterCommit()`, así el archivo solo se va
  si la transacción se confirma. Como la cascada de la base de datos no pasa por Eloquent,
  `PedidoController::destroy` borra la orden **con Eloquent antes** de borrar la venta, dentro de su
  transacción.

### Modelo `OrdenTrabajoLinea` (nuevo)

- `#[Fillable(['pedido_linea_id', 'color_tinta', 'color_tinta_otro'])]`.
- Casts: `color_tinta` → `ColorTinta`.
- Relaciones: `orden(): BelongsTo`, `pedidoLinea(): BelongsTo`.
- `colorTexto(): string` → la etiqueta del enum, o `color_tinta_otro` cuando es `otro`.

### Modelo `Pedido` (Venta)

- Relación `ordenTrabajo(): HasOne`.
- `puedeCrearOrdenTrabajo(): bool` → tiene pagos, no tiene orden y no está entregada.
- `motivoNoCreaOrdenTrabajo(): ?string`, el primer motivo en este orden:
  - "Ya tiene orden de trabajo." (tiene orden);
  - "La venta ya se entregó." (entregada);
  - "Registra un pago antes de crear la orden de trabajo." (`! tienePagos()`, supuesto 3).
- `necesitaOrdenTrabajo(): bool` → estado `anticipo` o `pagado` y sin orden. Es lo que pinta la
  etiqueta "Sin orden" del listado (adición 2). Una venta entregada no la lleva.

**Los pagos se borran después.** Si a una venta con orden se le borran todos los pagos, la orden
**se conserva**: el trabajo ya pudo haber empezado. La regla del pago solo aplica para **crearla**.

### Imagen del diseño

Todo lo que hoy hace `ProcesadorImagenArticulo` (comprobar por contenido, orientar por EXIF,
reducir a `LADO_MAXIMO` = 1200, regenerar como WEBP calidad 82 y reemplazar con **el orden seguro**
de 010: primero el archivo nuevo, luego la columna y al final se borra el anterior) se extrae a
`App\Services\Imagenes\GuardadorImagenWebp`, sin cambiar sus números:

- `esImagen(string)`, `guardar(Model, string $directorio, string $contenido)` y `quitar(Model)`,
  para cualquier modelo con columna `imagen_ruta`.
- `ProcesadorImagenArticulo` queda como fachada delgada que le pasa `Articulo::DIRECTORIO_IMAGENES`;
  sus constantes apuntan a las del guardador y sus pruebas pasan sin cambios. `ImagenLegible` usa el
  guardador.
- La orden lo usa directo con `OrdenTrabajo::DIRECTORIO_IMAGENES`. Nombre:
  `ordenes-trabajo/{id}-{8 aleatorios}.webp`.

Acepta JPEG, PNG y WEBP de hasta 10 MB (`TAMANO_MAXIMO_KB` de 010), comprobados **por contenido**,
no por extensión.

### Las líneas de la venta cambian (019)

Una venta con orden puede seguir editándose mientras está en `anticipo` (019: `esEditable()`). Hoy
`PedidoController::guardarLineas` **borra y vuelve a crear** todas las líneas, así que los renglones
de la orden se perderían por la cascada de `pedido_linea_id`. Para conservarlos:

1. Antes del `delete()`, si la venta tiene orden, se guardan sus colores con la clave de su línea:
   `articulo_id` en las líneas de catálogo y `descripcion` normalizada (`mb_strtolower(trim())`) en
   las libres.
2. Después de crear las líneas nuevas, cada color vuelve a la **primera línea nueva con la misma
   clave** que no tenga color todavía. Así, dos líneas del mismo artículo conservan sus dos colores
   en el orden en que aparecen.
3. Las líneas nuevas sin pareja se quedan **sin color**, y los colores sin línea desaparecen
   (supuesto 14).

Vive en `App\Services\OrdenesTrabajo\ConservadorColores` (`recordar(Pedido): array` y
`reaplicar(Pedido, array): void`), dentro de la misma transacción de `update`.

Una orden con líneas sin color se muestra con un `x-alerta` ("Falta el color de tinta de: …") y no
avanza de estado hasta completarla.

### Rutas (web)

Dentro del grupo autenticado, **antes** del `Route::resource('pedidos', ...)`:

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/pedidos/produccion` | hoja de producción | `pedidos.produccion` |
| GET | `/pedidos/{pedido}/orden-trabajo/crear` | formulario de alta | `pedidos.orden-trabajo.create` |
| POST | `/pedidos/{pedido}/orden-trabajo` | guarda la orden | `pedidos.orden-trabajo.store` |
| GET | `/pedidos/{pedido}/orden-trabajo` | detalle | `pedidos.orden-trabajo.show` |
| GET | `/pedidos/{pedido}/orden-trabajo/editar` | formulario de edición | `pedidos.orden-trabajo.edit` |
| PUT | `/pedidos/{pedido}/orden-trabajo` | guarda colores e imagen | `pedidos.orden-trabajo.update` |
| POST | `/pedidos/{pedido}/orden-trabajo/avanzar` | siguiente estado | `pedidos.orden-trabajo.avanzar` |
| GET | `/pedidos/{pedido}/orden-trabajo/imprimir` | vista de impresión | `pedidos.orden-trabajo.imprimir` |
| GET | `/pedidos/{pedido}/orden-trabajo/imagen` | sirve la imagen | `pedidos.orden-trabajo.imagen` |

`pedidos/produccion` va antes que `pedidos/{pedido}` para que no se lea "produccion" como id. Las
rutas cuelgan de la venta porque una venta tiene una sola orden: no hace falta el id de la orden en
la URL.

### Controladores

**`OrdenTrabajoController`** (nuevo): `create`, `store`, `show`, `edit`, `update`, `avanzar`,
`imprimir` e `imagen`.

- `create` / `store`: si `motivoNoCreaOrdenTrabajo()` no es nulo, regresa al detalle de la venta con
  ese motivo. `store` crea la orden en `en_dibujo` con sus renglones en una transacción, con
  `lockForUpdate()` sobre la venta y la regla revisada con la fila bloqueada: dos clics no crean dos
  órdenes, y el índice único de `pedido_id` es la última red. La imagen se guarda **dentro** de la
  transacción, como el alta de artículos (010): si falla, no queda la orden a medias. Redirige a
  `show`: "Orden de trabajo de PED-0004 creada."
- `update`: reemplaza los renglones de color y, si viene, la imagen. Casilla "Quitar imagen" →
  `quitar()`. Redirige a `show`.
- `avanzar`: revisa `puedeAvanzar()` con la orden bloqueada, llama a `avanzar()` y guarda.
  "PED-0004 pasó a En proceso." Al llegar a `terminado`, el detalle ofrece el enlace "Avisar que está
  listo" de 019, que ya existe en la venta (no se crea uno nuevo).
- `imprimir`: vista sin el layout de la aplicación, con `window.print()` al cargar.
- `imagen`: responde el archivo del disco privado con `Cache-Control: private, max-age=604800`, como
  las fotos de artículos (la URL lleva `?v={imagen_version}`, así que un reemplazo cambia la
  dirección). 404 sin imagen.

**`HojaProduccionController`** (nuevo, invocable): todas las órdenes `en_proceso` del usuario, con
`pedido.lineas` y `lineas` precargadas, ordenadas por **folio de la venta** (la más antigua
primero). Comparte la vista `ordenes-trabajo/imprimir` con la orden individual, con
`window.print()` al cargar. Sin órdenes, la página dice "No hay órdenes en proceso." y no abre la
impresión.

### Autorización: `PedidoPolicy`

La orden vive dentro de la venta y las rutas reciben el `Pedido`, así que sus reglas son tres
habilidades nuevas de `PedidoPolicy` (no hay policy propia):

- Venta ajena → **404** en todas, como el resto de `PedidoPolicy`.
- `crearOrdenTrabajo`: dueño y `motivoNoCreaOrdenTrabajo() === null`; si no, 403 con el motivo.
- `verOrdenTrabajo` (detalle, avanzar, imprimir, imagen): dueño y con orden; **sin orden → 404**.
  El estado de "avanzar" lo revisa el controlador con la fila bloqueada.
- `editarOrdenTrabajo`: lo de `verOrdenTrabajo` y `esEditable()`; si no, 403 "La venta ya se
  entregó: la orden queda solo para consulta."

### Validaciones (`OrdenTrabajoRequest`)

- `colores`: requerido, arreglo con **una entrada por cada línea de la venta**, con el id de la
  línea (`pedido_linea_id`) como clave. Una clave que no sea línea de esa venta → error.
- `colores.*.color`: requerido, `Rule::enum(ColorTinta::class)`. Mensaje: "Elige el color de tinta
  de {descripción de la línea}."
- `colores.*.otro`: `required_if` el color es `otro`, máx. 40. Mensaje: "Escribe el color de tinta."
- `imagen`: las mismas reglas que la foto del artículo: `mimes:jpg,jpeg,png,webp`, `max:10240` y
  `ImagenLegible` (comprobación por contenido).
- `quitar_imagen`: `boolean`.

La imagen es **opcional** (supuesto 11): el diseño puede llegar después de crear la orden.

## Vistas (Blade)

### Venta: detalle (`pedidos/show`)

- Botón **"Orden de trabajo"**: con orden lleva a su detalle; sin orden lleva al alta cuando
  `puedeCrearOrdenTrabajo()`. Sin pagos aparece deshabilitado con `title="Registra un pago antes de
  crear la orden de trabajo."`. En una venta entregada sin orden no aparece.
- Con orden: la línea "Orden de trabajo:" con la etiqueta de su estado y el enlace "Ver orden".

### Venta: listado (`pedidos/index`, `pedidos/_filas`)

- Etiqueta **"Sin orden"** (`etiqueta-sin-orden`, nueva) junto al estado de la venta cuando
  `necesitaOrdenTrabajo()` (adición 2). `ordenTrabajo` se precarga en la consulta del listado para
  no hacer una consulta por fila.
- Botón **"Hoja de producción"** en la barra del listado, que abre `pedidos.produccion` en una
  pestaña nueva.

### Orden: alta y edición (`ordenes-trabajo/_formulario`, `crear`, `editar`)

- Encabezado de **solo lectura**: cliente, teléfono (`telefono_legible`) y folio de la venta, con
  enlace a la venta.
- Tabla con una fila por cada línea de la venta: **modelo**, **nombre del artículo** (la
  `descripcion` de la línea), cantidad y el select de color de tinta (`x-campo`). Si la línea no
  tiene modelo se muestra "—". Debajo, el campo "¿Qué color?" para "Otro" (ver "JavaScript").
- Campo de imagen con la imagen actual y la casilla "Quitar imagen", igual que la foto del artículo
  (010), sin JavaScript.
- `enctype="multipart/form-data"` y `data-enviar-una-vez`.

### Orden: detalle (`ordenes-trabajo/show`)

- Encabezado: "Orden de trabajo · PED-0004", etiqueta del estado, cliente y teléfono.
- Tabla de artículos con su color, y el `x-alerta` de líneas sin color si las hay.
- La imagen del diseño en grande, o "Sin imagen del diseño".
- Botones:
  - **"Pasar a En proceso"** / **"Pasar a Terminado"**, cuando `puedeAvanzar()`, con la confirmación
    `data-confirmar` de `app.js`: "… No se puede regresar." (supuesto 13);
  - **"Editar"**, cuando `esEditable()`;
  - **"Imprimir"**, que abre la vista de impresión en una pestaña nueva;
  - en `terminado`, **"Avisar que está listo"**, el mismo enlace `wa.me` de 019.

### Orden: impresión individual (`ordenes-trabajo/imprimir`)

Hoja tamaño carta, sin el layout de la aplicación y con estilos propios, en blanco y negro:

- Folio de la venta en grande, cliente y teléfono.
- Tabla de artículos: modelo, nombre, cantidad y **color de tinta** en negritas.
- La imagen del diseño, hasta media hoja de alto.
- Sin QR (adición 1), sin precios, sin saldo.

### Hoja de producción (misma vista `ordenes-trabajo/imprimir`)

Hoja tamaño carta, sin layout, con estilos propios:

- Título "Hoja de producción" y la fecha y hora de impresión.
- Un bloque por orden, con `break-inside: avoid` para que una orden no se parta entre dos hojas:
  - a la izquierda, la **miniatura** del diseño (unos 30 × 30 mm, `object-fit: contain`) o un
    recuadro vacío "Sin imagen";
  - a la derecha, folio, cliente, teléfono y la tabla de modelo, nombre, cantidad y color.
- Sin QR, sin precios, sin saldo.

## JavaScript

- `public/js/orden-trabajo.js` (nuevo): muestra u oculta el campo "¿Qué color?" de cada fila al
  elegir "Otro". Sin JavaScript el campo se ve siempre y la validación del servidor manda igual.
- `public/js/imprimir-al-cargar.js` (nuevo): `window.print()` al terminar de cargar las imágenes
  (`load`), compartido por la orden y la hoja de producción. `etiqueta-pedido.js` se deja como está.
- Los diálogos de confirmación usan el manejador de `app.js`.

## Pruebas (Pest)

`tests/Feature/OrdenTrabajoTest.php`, con la hoja de producción incluida. Las pruebas de imágenes
de artículos y de ventas pasan sin cambios.

1. Invitado → login; venta ajena → 404 en todas las rutas.
2. Sin pagos no se crea la orden (botón deshabilitado y `store` rechazado). Con un anticipo de
   cualquier monto, sí. Una venta de cotización aceptada (021) también la admite.
3. `store` exige un color válido para **cada** línea; "Otro" exige el texto. Una línea de otra venta
   se rechaza.
4. La orden nace `en_dibujo`, muestra cliente, teléfono y folio de la venta, y modelo y nombre de
   cada línea; una línea libre muestra su descripción y modelo vacío.
5. Dos peticiones seguidas crean **una** orden.
6. Imagen: se guarda como WEBP reducido en `ordenes-trabajo/`; reemplazarla borra la anterior;
   quitarla borra el archivo; un archivo que no es imagen se rechaza aunque diga `.jpg`.
7. Avanzar: `en_dibujo` → `en_proceso` → `terminado`; desde `terminado` se rechaza; no hay ruta para
   regresar. Con líneas sin color, no avanza.
8. Venta entregada: la orden se ve e imprime, pero no se edita ni avanza. Deshacer la entrega (019)
   la vuelve editable.
9. Editar las líneas de la venta (en `anticipo`) conserva los colores de las líneas que siguen, deja
   sin color las nuevas (también dos líneas del mismo artículo) y quita las que se borraron.
10. Borrar todos los pagos conserva la orden. Borrar la venta después borra la orden, sus renglones y
    el archivo de la imagen.
11. Listado: "Sin orden" aparece en ventas `anticipo` y `pagado` sin orden; no aparece en
    `pendiente`, en `entregado` ni en las que ya tienen orden. Sin consultas N+1.
12. Hoja de producción: solo las órdenes `en_proceso` del usuario, con su miniatura; sin órdenes,
    el mensaje "No hay órdenes en proceso.".
13. Las suites de imágenes de artículos, ventas, pagos, entrega, autofactura e inventario siguen
    pasando sin cambios.

## Fuera de alcance

- **Folio propio** (`OT-0001`): la orden se identifica por el folio de la venta.
- **QR en la orden o en la hoja de producción** (adición 1, descartada).
- Notas, fecha de entrega, varias imágenes por orden o una imagen por artículo.
- **Regresar de estado**. Si hace falta, sería una spec aparte.
- **Tablero de producción** (una pantalla con las órdenes por estado) y filtro del listado por estado
  de la orden. Por ahora se llega a cada orden desde su venta, y a las "En proceso" con la hoja de
  producción.
- Ligar el estado `terminado` con la entrega de la venta: la entrega sigue siendo por escaneo (019),
  con orden terminada o sin ella.
- Envío a domicilio, repartidor y tarifas de [remotas/038](remotas/038-produccion-ordenes-trabajo.md).
- Órdenes de trabajo para cotizaciones sin aceptar o para facturas.
- Borrar la orden a mano (solo se borra con su venta). Ver supuesto 18.

## Estado de implementación

Implementada el 2026-10-02.

- **Archivos nuevos**:
  - migración `2026_10_06_100000_create_ordenes_trabajo_tables` (revisada con `migrate --pretend`
    contra MySQL y **aplicada en la base local**),
  - enums `EstadoOrdenTrabajo` y `ColorTinta`, modelos `OrdenTrabajo` y `OrdenTrabajoLinea`,
  - `App\Services\Imagenes\GuardadorImagenWebp`, `App\Services\OrdenesTrabajo\ConservadorColores`,
  - `OrdenTrabajoController`, `HojaProduccionController`, `OrdenTrabajoRequest`,
  - vistas `ordenes-trabajo/_formulario`, `crear`, `editar`, `show` e `imprimir`,
  - `public/js/orden-trabajo.js` y `public/js/imprimir-al-cargar.js`,
  - pruebas `OrdenTrabajoTest` (37).
- **Archivos modificados**:
  - `ProcesadorImagenArticulo` (fachada sobre `GuardadorImagenWebp`) e `ImagenLegible`,
  - `Pedido` (`ordenTrabajo()`, `motivoNoCreaOrdenTrabajo()`, `necesitaOrdenTrabajo()`), `User`
    (`ordenesTrabajo()`), `PedidoPolicy` (tres habilidades),
  - `PedidoController` (conserva colores al editar, borra la orden con Eloquent, precarga la orden
    en listado y detalle), `routes/web.php`,
  - vistas `pedidos/show`, `pedidos/index`, `pedidos/_filas` y `app.css` (`etiqueta-orden-*`,
    `etiqueta-sin-orden`, `imagen-diseno`),
  - [019](019-pedidos-mostrador.md): nota que remite a esta spec.
- **Decisiones al implementar** (ya reflejadas arriba):
  - Las reglas viven en `PedidoPolicy`, no en una policy propia: las rutas reciben el `Pedido`.
  - No hay un procesador de imágenes propio de la orden: la conversión y el reemplazo seguro de 010
    pasaron a `GuardadorImagenWebp`, que usan los dos.
  - La imagen se guarda dentro de la transacción, como el alta de artículos.
  - La hoja de producción se ordena por folio de la venta.
  - Los selects de color usan `x-campo` (lo exige `EstiloUniformeTest`); el valor anterior se le
    pasa ya resuelto porque `old()` no entiende nombres con corchetes.
- **Verificación**: `php artisan test` con 1041 pruebas en verde (1004 anteriores + 37 nuevas),
  `node --test "tests/js/*.test.js"` (37) y Pint sin cambios.

  **No se revisó la UI en un navegador real.** Falta probar el formulario (sobre todo mostrar y
  ocultar "¿Qué color?"), la impresión de la orden y la hoja de producción en papel carta.

## Criterios de aceptación

1. En una venta con al menos un pago aparece "Orden de trabajo". Sin pagos, el botón está
   deshabilitado y explica por qué.
2. La orden muestra cliente, teléfono y PED de la venta, y una fila por artículo con modelo, nombre y
   color de tinta obligatorio (lista fija u "Otro").
3. Se puede subir, reemplazar y quitar una imagen del diseño, que se guarda reducida.
4. La orden avanza En dibujo → En proceso → Terminado, sin regreso.
5. La orden se imprime sola, y la hoja de producción imprime todas las "En proceso" con miniatura
   del diseño.
6. El listado de ventas marca "Sin orden" en las ventas cobradas que todavía no tienen orden.
7. Editar los artículos de la venta conserva los colores de los que siguen. Una venta entregada deja
   la orden solo para consulta.
8. `php artisan test`, `pint` y `node --test "tests/js/*.test.js"` en verde.

## Supuestos asumidos (registro completo)

Revisados con el usuario el 2026-10-02. Los marcados **[decidir]** surgieron al redactar y no se
revisaron uno por uno.

1. La orden cuelga **solo de la venta** (mostrador o cotización aceptada), no de cotizaciones ni de
   facturas.
2. **Una venta, una orden.**
3. **Se crea con cualquier pago** registrado, aunque sea un anticipo (decidido por el usuario).
4. Se abre con el botón "Orden de trabajo" del detalle de la venta.
5. Cliente, teléfono y PED se **leen de la venta** y no se editan en la orden.
6. Sin teléfono en la venta, la orden se crea igual con el teléfono vacío. *(Al implementar: la
   venta siempre exige teléfono, en mostrador (019) y al aceptar la cotización (021), así que este
   caso no ocurre.)*
7. **Un renglón por cada línea de la venta**, cada uno con su color.
8. Modelo y nombre salen de la línea; una línea libre muestra su descripción y modelo vacío.
9. Color de tinta de una **lista fija** (Negro, Azul, Rojo, Verde, Morado) más "Otro" con texto.
10. El color es **obligatorio** en cada renglón.
11. **Una imagen del diseño por orden** (decidido por el usuario). **[decidir]** Es opcional: la
    orden puede crearse antes de tener el diseño.
12. Sin folio propio: se identifica por el PED.
13. Estados **En dibujo → En proceso → Terminado**, solo hacia adelante (decidido por el usuario).
    **[decidir]** No se avanza con líneas sin color.
14. Si cambian las líneas de la venta, la orden se ajusta: las nuevas quedan sin color y las borradas
    desaparecen.
15. Se edita mientras la venta no esté entregada; después, solo consulta.
16. Se imprime cada orden y una **hoja de producción** con todas las órdenes **En proceso** (decidido
    por el usuario).
17. Si se borra la venta, se borra su orden.
18. **[decidir]** La orden no se borra a mano, ni siquiera si se borran todos los pagos de la venta.
19. **[decidir]** "Sin orden" solo marca ventas en `anticipo` o `pagado`; las entregadas no, para no
    marcar las ventas anteriores a esta spec.

**Adiciones técnicas revisadas:**

1. QR en la orden: **descartada**.
2. Etiqueta "Sin orden" en el listado de ventas: **aceptada**.
3. Reducir la imagen al subirla, igual que las fotos de artículos: **aceptada**.
4. Miniatura del diseño en la hoja de producción: **aceptada**.
