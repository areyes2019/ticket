# Spec: Dashboard con cotizaciones y facturas, nueva cotización en ventana y timbrado directo

**Modifica:**

- [013-dashboard-bandeja-correo.md](013-dashboard-bandeja-correo.md): el inicio del dashboard deja de
  ser la bandeja de correo de demostración. Ahora muestra cotizaciones y facturas reales con la misma
  distribución (listas y visor). La bandeja de correo sigue existiendo y se abre desde el menú de
  aplicaciones ("Correo", `?app=correo`).
- [014-cotizaciones-bandeja.md](014-cotizaciones-bandeja.md): la vista previa de la cotización se
  comparte con el dashboard. `bandeja-documentos.js` admite varias listas con un solo visor. El botón
  "Facturar" de la vista previa ya no abre el formulario: **timbra directo** tras confirmar.
- [015-cotizacion-a-factura-y-duplicar.md](015-cotizacion-a-factura-y-duplicar.md): la asunción 3
  ("la conversión no timbra de inmediato") sigue valiendo **solo para el detalle** de la cotización.
  Desde la vista previa (dashboard y `/cotizaciones`) se timbra directo, con una ventana de
  confirmación que pide los tres datos fiscales. Se retira de "Fuera de alcance" el botón "Facturar"
  en la vista previa.
- [012-facturacion.md](012-facturacion.md): ruta nueva `facturas.vista-previa` y la hoja de la factura
  en HTML.
- [022-ordenes-trabajo.md](022-ordenes-trabajo.md) (por la **Corrección 1**): ruta nueva
  `pedidos.orden-trabajo.vista-previa`, parcial `ordenes-trabajo/_vista-previa` y `origen=dashboard`
  en "avanzar". Se retira de "Fuera de alcance" de 022 una lista de órdenes fuera de la venta (aquí,
  solo en el dashboard).

> **Corrección 1 (2026-10-02, implementada):** cotizaciones y facturas pasan a **una sola
> columna con acordeón**, y la columna que hoy es "Facturas" pasa a ser **Órdenes de trabajo**. Ver
> la sección "Corrección 1". Donde esa sección contradiga lo de arriba, manda la corrección.

## Historia de usuario

Como usuario, quiero ver en mi inicio mis cotizaciones y mis facturas recientes, abrir cualquiera sin
cambiar de página, crear una cotización ahí mismo y, cuando una cotización está lista, timbrar su
factura con un clic y una confirmación, viendo cómo aparece en la columna de facturas sin recargar.

**Ruta:** `http://ticket_factura.test/public/dashboard` (ruta `dashboard`, ya existente).

## Objetivo / Alcance

1. **Inicio con dos listas y un visor:** columna de cotizaciones, columna de facturas y, a la derecha,
   la vista previa del documento elegido.
2. **Nueva cotización en ventana:** junto al título "Cotizaciones", dos botones pequeños de solo
   icono: "Ver todas" (ojo) y "Nueva cotización" (`+`). El segundo abre una ventana con el formulario
   de alta; no lleva a `/cotizaciones`.
3. **Timbrado directo:** el botón "Facturar" de la vista previa de una cotización abre una ventana de
   confirmación. Al confirmar se crea la factura con los datos de la cotización y se timbra en ese
   momento, sin pasar por el formulario de factura. Sin recargar la página:
   - la factura nueva aparece arriba en la columna "Facturas",
   - la fila de la cotización y su vista previa se actualizan (etiqueta "Facturada"; el botón
     "Facturar" desaparece).

## Backend (Laravel)

### Rutas (web)

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/facturas/{factura}/vista-previa` | fragmento HTML del visor de una factura | `facturas.vista-previa` |
| POST | `/cotizaciones/{cotizacion}/timbrar` | crea la factura de la cotización y la timbra | `cotizaciones.timbrar` |

Las dos van antes de sus `Route::resource`. `dashboard` y `cotizaciones.store` no cambian de URL.

### `DashboardController` (invocable)

- Cotizaciones del usuario: las **25** más recientes (`RECIENTES`), con `cliente`, `pagos_count`,
  `pagos_sum_monto` y `factura_vigente_exists` precargados, como la bandeja de 014.
- Facturas del usuario: las 25 más recientes, con `cliente`.
- **Documento abierto:** el de `?factura=` o `?cotizacion=` si es del usuario (un id ajeno o
  inexistente se ignora). Si no hay, la primera cotización y, si no hay cotizaciones, la primera
  factura.
- Datos de la ventana "Nueva cotización": `CotizacionController::datosFormulario()` (ahora
  `public static`), más `abrirNuevaCotizacion = old('origen') === 'dashboard'`.
- Sigue pasando los datos de `BandejaCorreoDemo` para la bandeja de correo.

### `FacturaController::vistaPrevia`

`Gate::authorize('view')` (ajena → 404) y el parcial `facturas/_vista-previa`. No consulta la
cancelación en facturapi.io: muestra el último estado guardado (el detalle sí la refresca).

### `CotizacionController::store`

Con `origen=dashboard` redirige a `dashboard?cotizacion={id}` con el aviso "Cotización COT-… creada.".
Sin `origen`, sigue yendo al detalle.

### Timbrado directo: `FacturaController::timbrarCotizacion`

**Validación (`TimbrarCotizacionRequest`, extiende `FacturaRequest`):**

- Autoriza con `view` sobre la cotización: una ajena responde **404**.
- El navegador solo manda `uso_cfdi`, `metodo_pago` y `forma_pago`. Antes de validar, la petición se
  completa **con la cotización**: `cliente_id`, descuento global, `cotizacion_id` y sus líneas
  (artículo, cantidad, descripción, modelo, precio, descuento y tasa de IVA) tal como están. Lo que
  el navegador mande en esos campos se ignora.
- Se aplican **todas** las reglas de `FacturaRequest`: cada línea con artículo del catálogo y modelo,
  tasa Exento para artículos que no son objeto de impuesto, regla PUE/PPD de la forma de pago, etc.
  Si una regla de las líneas falla, el error dice qué corregir y la ventana ofrece "Revisar en el
  formulario".

**Guardado:** el mismo de `store`, extraído a un método privado que comparten los dos:

- transacción con `lockForUpdate()` sobre la cotización;
- si ya tiene factura vigente, no se crea otra (**409**, "Esta cotización ya se facturó en FAC-…");
- si `motivoNoFacturable()` no es `null`, no se crea (**422** con ese motivo);
- folio, totales del servidor, líneas con sus claves SAT y `cotizacion_id`.

Después del commit se timbra con `TimbradorFacturas`, igual que `store`.

**Respuesta con JavaScript (JSON):**

| Caso | Código | Contenido |
|---|---|---|
| Timbrada | 200 | `tipo: "exito"`, `mensaje` ("Factura timbrada. Folio fiscal A12, UUID …"), `factura` (id), `fila` (HTML de `<x-facturas.fila>`), `filaCotizacion` (HTML de `<x-cotizaciones.fila>`) |
| Timbrado fallido (datos, PAC o en curso) | 200 | Igual, con `tipo: "error"` y el mensaje de `store`. La factura existe (pendiente) y también se agrega a la lista |
| Ya facturada | 409 | `mensaje` y `url` de la factura vigente |
| No facturable | 422 | `mensaje` con el motivo |
| Datos inválidos | 422 | Errores de validación de Laravel |

**Sin JavaScript:** el formulario de la ventana se envía normal y la respuesta es la misma redirección
que da `store` (detalle de la factura con el aviso, o de regreso con el error).

### Modelo `Cotizacion`

`avisosDePrecio(): list<string>` reúne los avisos de "precio cambiado en el catálogo" de 015 (antes
calculados dentro de `FacturaController`). Los usan el formulario de factura y la ventana de
confirmación.

## Vistas (Blade)

### `dashboard.blade.php`

- Avisos de sesión (`status`, `exito`), y alertas ocultas para los errores de vista previa, de
  compartir y del timbrado (`data-timbrado-aviso`).
- **Inicio** (`.bandeja.bandeja-dashboard`, `data-escritorio-inicio`, `data-bandeja-documentos`,
  `data-parametro="cotizacion factura"`). Se oculta cuando el menú abre la bandeja de correo.

| Columna | Contenido |
|---|---|
| Cotizaciones | Título con icono; a la derecha "Ver todas" (`bi-eye`, `cotizaciones.index`) y "Nueva cotización" (`bi-plus-lg`, abre la ventana), los dos pequeños y de solo icono; buscador "Buscar cotización"; filas `<x-cotizaciones.fila>`; "Sin cotizaciones" |
| Facturas | Título con icono y enlace "Ver todas"; buscador "Buscar factura"; filas `<x-facturas.fila>`; "Sin facturas" |
| Visor | Vista previa de la cotización o de la factura abierta, o "Selecciona una cotización o una factura" |

- Los buscadores (`data-filtro-local`) filtran **solo las filas cargadas**, sin ir al servidor y sin
  distinguir mayúsculas ni acentos.
- La bandeja de correo de 013 (`#app-correo`) y su ventana "Redactar" siguen en la página.

### Componentes y parciales nuevos

| Pieza | Contenido |
|---|---|
| `<x-facturas.fila>` | Iniciales del cliente, razón social, fecha corta, folio visible, total, estado y "Cancelación en proceso". Enlace al detalle con `data-vista-previa` |
| `<x-facturas.hoja>` | La factura como hoja: emisor, folio y fecha, receptor (RFC, régimen, C.P.), uso de CFDI, método y forma de pago, UUID, líneas con clave SAT, subtotal, descuento, IVA y total |
| `facturas/_vista-previa` | Acciones (Volver, Enviar por correo, Ver PDF, Descargar PDF, Descargar XML, Abrir), estado y la hoja. Lleva `data-vista-previa-de` y `data-documento="factura"` |
| `cotizaciones/_dialogo-nueva` | La ventana "Nueva cotización" (abajo) |
| `cotizaciones/_dialogo-timbrar` | La ventana de confirmación del timbrado (abajo) |

`<x-bandeja.encabezado-lista>` gana la prop `carpetas` (por defecto `true`); con `false` no pinta el
botón "Carpetas". La vista previa de la cotización gana `data-documento="cotizacion"`.

### Ventana "Nueva cotización" (`#dialogo-nueva-cotizacion`)

- `<dialog>` ancho (hasta 72rem; pantalla completa en celular), con franja de color arriba, sombra y
  una entrada suave (sin animación si el sistema pide reducir movimiento).
- **Encabezado:** icono en recuadro, "Nueva cotización", una línea de ayuda y "Cerrar" (×).
- **Cuerpo con scroll propio**, tres pasos numerados en tarjetas:
  1. **Cliente** ("¿Para quién es?").
  2. **Artículos y servicios:** buscador del catálogo, "Línea libre", tabla de líneas
     (`documentos/_linea`) y un recuadro punteado cuando no hay líneas.
  3. **Descuento y totales:** descuento global y resumen (subtotal, descuento, IVA).
- **Pie fijo:** "Total estimado" en grande, actualizado mientras se captura, "Cancelar" y "Guardar
  cotización" (`data-enviar-una-vez`).
- Envía a `cotizaciones.store` con `origen=dashboard`. Si la validación falla, la página vuelve con la
  ventana abierta (`data-abrir-al-cargar`), lo capturado y los errores arriba.
- Un clic en el fondo **no** la cierra, para no perder lo capturado. Esc y "Cancelar" sí.

### Botón "Facturar" de la vista previa de la cotización

- Mismo icono (`bi-receipt`), descripción "Timbrar factura". Aparece solo si `motivoNoFacturable()`
  es `null`, como hoy.
- Ya **no** es un enlace a `facturas.create`: abre `#dialogo-timbrar`.
- El detalle de la cotización (`show`) **no cambia**: su "Facturar" sigue llevando al formulario.

### Ventana de confirmación del timbrado (`#dialogo-timbrar`)

- Título: "¿Timbrar la factura de COT-0012?".
- Resumen: cliente (razón social y RFC), número de líneas y total.
- Avisos de precio (`avisosDePrecio()`) como advertencia, si los hay. La factura conserva el precio
  cotizado.
- Tres campos: **Uso de CFDI** (G03 – Gastos en general), **Método de pago** (PUE) y **Forma de pago**
  (sin elegir, requerida). Son los mismos valores por defecto del formulario de factura.
- Nota: "Se emite el CFDI ante el SAT con las líneas y precios de la cotización. Una factura timbrada
  ya no se edita: solo se cancela."
- Botones: **"Sí, timbrar"** (principal, `bi-receipt`), "Revisar en el formulario" (enlace a
  `facturas.create?cotizacion={id}`) y "Cancelar".
- Mientras se timbra, el botón dice "Timbrando…" y queda deshabilitado.
- Los errores (validación, ya facturada, no facturable) se muestran **dentro** de la ventana, que
  sigue abierta.

## JavaScript

### `public/js/bandeja-documentos.js` (extensión)

- `data-parametro` acepta varios nombres separados por espacio (`"cotizacion factura"`). La abierta se
  identifica como `"<parametro>:<id>"` y la URL guarda solo el suyo (`?cotizacion=` o `?factura=`).
- Listas `[data-filtro-local]`: su buscador filtra las filas cargadas.
- Escucha el evento `documento:recargar` (lo dispara `timbrar-cotizacion.js`) para volver a pedir la
  vista previa del documento abierto.

### `public/js/timbrar-cotizacion.js` (nuevo)

Se carga en el dashboard y en `/cotizaciones`. Escucha (por delegación, porque la vista previa llega
por AJAX) el envío de `form[data-timbrar-cotizacion]`:

1. Evita el envío normal, deshabilita "Sí, timbrar" ("Timbrando…") y manda el formulario con Axios.
2. **Respuesta 200:**
   - cierra la ventana,
   - si la página tiene la lista de facturas (`[data-lista-facturas]`), inserta `fila` al principio,
     la resalta un momento, oculta "Sin facturas" y quita la última fila si pasan de 25,
   - reemplaza la fila de la cotización por `filaCotizacion`,
   - dispara `documento:recargar` para refrescar la vista previa de la cotización,
   - muestra `mensaje` en el aviso de la página (éxito o error según `tipo`).
3. **409, 422 o error de validación:** muestra el mensaje (o los errores) dentro de la ventana y
   reactiva el botón.
4. **401, 419 o redirección:** recarga la página. **Otro error:** "No se pudo timbrar. Intenta de
   nuevo." dentro de la ventana.

## Diseño adaptable

- **Escritorio (≥1024px):** cotizaciones, facturas y visor en tres columnas.
- **Tableta (768–1023px):** las dos listas, una sobre otra, junto al visor.
- **Celular (<768px):** las listas apiladas; el visor a pantalla completa con "Volver". La ventana
  "Nueva cotización" ocupa toda la pantalla y sus botones se reparten el ancho.
- Sin scroll horizontal de página en ningún tamaño. Esquinas rectas y Bootstrap Icons (003).

## Pruebas (Pest)

- **`DashboardTest`:**
  - las dos listas solo con documentos propios, y la cotización más reciente abierta;
  - `?factura=` propia abre la factura; ajena se ignora;
  - listas vacías: "Sin cotizaciones" y "Selecciona una cotización o una factura";
  - `facturas.vista-previa` con hoja y acciones;
  - botones "Ver todas" y "Nueva cotización", y la ventana con `origen=dashboard`;
  - alta con `origen=dashboard` → regresa al dashboard con la cotización abierta y el aviso;
  - alta inválida → la ventana vuelve abierta con lo capturado.
- **`FacturasTest`:** `GET /facturas/{id}/vista-previa` de una factura ajena → 404.
- **`TimbrarCotizacionTest`** (nuevo):
  - timbrado exitoso (JSON): crea la factura con cliente, líneas, precios y `cotizacion_id` de la
    cotización, los datos fiscales elegidos, estado timbrada, y devuelve `fila` y `filaCotizacion`;
  - lo que mande el navegador en `cliente_id` o `lineas` se ignora;
  - forma de pago faltante, o 99 con PUE → 422 sin crear factura ni llamar a facturapi.io;
  - cotización ya facturada → 409; en borrador o con línea libre → 422; ajena → 404;
  - timbrado fallido → 200 con `tipo: "error"`, factura pendiente y cotización facturada;
  - sin JavaScript → redirección al detalle de la factura;
  - la vista previa muestra "Timbrar factura" y la ventana solo cuando la cotización es facturable;
    el detalle sigue enlazando al formulario.
- Las pruebas de 011 a 019 siguen pasando.

## Fuera de alcance

- Timbrar directo desde el detalle de la cotización o desde el listado de facturas.
- Editar las líneas en la ventana de confirmación (para eso está "Revisar en el formulario").
- Preferencias fiscales guardadas por cliente.
- Paginar o filtrar por periodo las listas del dashboard (solo las 25 más recientes).
- Botón "Nueva factura" en la columna de facturas.
- Volver al dashboard después de enviar por correo, pagar o duplicar desde el visor (esas acciones
  siguen su destino de 014 y 012).

## Corrección 1: documentos en acordeón y columna de órdenes de trabajo

> **Estado: implementada** el 2026-10-02 (ver "Estado de implementación de la corrección 1").

### Historia de usuario

Como usuario, quiero tener cotizaciones y facturas juntas en una sola columna, abriendo y cerrando
cada grupo según lo que esté usando, y dedicar la segunda columna a las **órdenes de trabajo**, para
ver desde el inicio qué está en dibujo y qué está en producción, y abrir cualquier orden en el visor
sin salir de la página.

```
┌──────────────────────────┬──────────────────────────┬──────────────────────────┐
│ Documentos               │ Órdenes de trabajo  🖨   │ Visor                    │
│ ▾ Cotizaciones   👁  +   │ Buscar orden             │ (cotización, factura     │
│   Buscar cotización      │ PED-0004 · En dibujo     │  u orden abierta)        │
│   COT-0012 …             │ PED-0003 · En proceso    │                          │
│ ▸ Facturas       👁      │ …                        │                          │
└──────────────────────────┴──────────────────────────┴──────────────────────────┘
```

### Qué cambia

| Antes | Ahora |
|---|---|
| Columna 1: Cotizaciones | Columna 1: **Documentos**, con dos secciones en acordeón: Cotizaciones y Facturas |
| Columna 2: Facturas | Columna 2: **Órdenes de trabajo** |
| Visor: cotización o factura | Visor: cotización, factura **u orden de trabajo** |

Todo lo demás de esta spec sigue igual: los botones "Ver todas" y "Nueva cotización", la ventana de
alta, el timbrado directo, los buscadores locales y las 25 más recientes por lista.

### Columna 1: Documentos (acordeón)

- Cada sección es un `<details>` con su `<summary>` como encabezado: icono, título, número de
  documentos cargados y, a la derecha, sus botones (los de hoy). Funciona **sin JavaScript**.
- Secciones **independientes**: se pueden tener las dos abiertas, una o ninguna. No es un acordeón
  exclusivo.
- Cada sección conserva su buscador (`data-filtro-local`), sus filas, su "Sin …" y, en Facturas,
  `data-lista-facturas`.
- **Al cargar:** Cotizaciones abierta y Facturas cerrada. Si el documento abierto es una factura
  (`?factura=`), Facturas también se abre.
- Abrir o cerrar una sección se recuerda **por navegador** (`localStorage`, envuelto en
  `try/catch`). Sin almacenamiento disponible se usa la regla de "al cargar".
- Un clic en los botones del encabezado ("Ver todas", "Nueva cotización") **no** abre ni cierra la
  sección: hacen lo suyo (navegar o abrir la ventana). Lo resuelve el navegador, porque un `<summary>`
  no se activa cuando el clic cae en un enlace o botón dentro de él; no hace falta JavaScript.
- **Timbrado directo:** al llegar la factura nueva, si la sección Facturas está cerrada se abre, para
  que se vea la fila insertada y resaltada (`timbrar-cotizacion.js`).

### Columna 2: Órdenes de trabajo

- Encabezado con icono (`bi-clipboard-check`) y "Órdenes de trabajo". A la derecha, un botón de solo
  icono **"Hoja de producción"** (`bi-printer`, `pedidos.produccion`, pestaña nueva). No hay "Ver
  todas": no existe un listado de órdenes fuera de la venta (022).
- Buscador "Buscar orden" (`data-filtro-local`).
- Las **25 órdenes más recientes** del usuario (`created_at` descendente), **de cualquier estado**,
  con `pedido.cliente` y el conteo de líneas sin color precargados (sin N+1).
- Vacía: "Sin órdenes de trabajo".

**Componente `<x-ordenes-trabajo.fila>` (nuevo):**

- Miniatura del diseño (o el icono si no tiene imagen), folio de la venta (PED-0004), cliente,
  número de artículos, fecha corta y la etiqueta del estado (`claseEtiqueta()` de 022).
- Si tiene líneas sin color, la marca "Falta color".
- Enlace a `pedidos.orden-trabajo.show` con `data-vista-previa` hacia la vista previa, y
  `data-ot="{id de la orden}"`.

### Visor: vista previa de la orden

**Ruta nueva** (en el grupo de 022, antes de `Route::resource('pedidos', ...)`):

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/pedidos/{pedido}/orden-trabajo/vista-previa` | fragmento HTML del visor de la orden | `pedidos.orden-trabajo.vista-previa` |

`OrdenTrabajoController::vistaPrevia`: `Gate::authorize('verOrdenTrabajo')` (ajena o sin orden →
404) y el parcial `ordenes-trabajo/_vista-previa`.

**Parcial `ordenes-trabajo/_vista-previa`** (`data-vista-previa-de="{id de la orden}"`,
`data-documento="ot"`):

- Acciones: Volver (celular), **Avanzar** ("Pasar a En proceso" / "Pasar a Terminado", con la
  misma confirmación de 022, solo si `puedeAvanzar()`), **Editar** (si `esEditable()`),
  **Imprimir** (pestaña nueva), **Ver venta** y **Abrir** (detalle de la orden).
- El cuerpo de `ordenes-trabajo/show`: encabezado con folio y estado, cliente y teléfono, tabla de
  artículos con su color, alerta de líneas sin color y la imagen del diseño. Se extrae a un parcial
  que comparten `show` y la vista previa, para no duplicarlo.
- En `terminado`, "Avisar que está listo" como en 022.

**Avanzar desde el dashboard:** el formulario lleva `origen=dashboard`. `OrdenTrabajoController::
avanzar` redirige entonces a `dashboard?ot={id}` con el mismo aviso ("PED-0004 pasó a En proceso.");
sin `origen`, sigue yendo al detalle de la orden. Es un envío normal (recarga la página), sin AJAX
nuevo.

### `DashboardController`

- Se agrega `ordenesTrabajo` (las 25 de arriba). Facturas y cotizaciones siguen cargándose igual.
- **Documento abierto:** `?factura=`, `?cotizacion=` o `?ot=` si es del usuario (ajeno o
  inexistente se ignora). Sin parámetro, la primera cotización; sin cotizaciones, la primera factura;
  sin facturas, la primera orden.
- Pasa `facturasAbiertas = $abierta instanceof Factura` para abrir la sección al cargar.

### `bandeja-documentos.js`

- `data-parametro="cotizacion factura ot"`. Se usa `ot` (una palabra) porque el script lee
  `fila.dataset[parametro]`, y `data-orden` ya lo usan las órdenes de compra.
- Sin seleccionar: "Selecciona una cotización, una factura o una orden".
- Las filas dentro de un `<details>` cerrado no cambian nada: el clic solo ocurre si están visibles.

### Diseño adaptable (reemplaza la sección de arriba para el dashboard)

- **Escritorio (≥1024px):** Documentos, Órdenes de trabajo y visor en tres columnas. Cada sección
  del acordeón con scroll propio cuando las dos están abiertas, para que la columna no crezca más que
  la pantalla.
- **Tableta (768–1023px):** Documentos y Órdenes, una sobre otra, junto al visor.
- **Celular (<768px):** las columnas apiladas; el visor a pantalla completa con "Volver".
- Sin scroll horizontal de página. Esquinas rectas y Bootstrap Icons (003).

### Pruebas (Pest)

- **`DashboardTest`:**
  - la columna "Órdenes de trabajo" solo con órdenes propias, de todos los estados, con folio,
    cliente y estado; vacía, "Sin órdenes de trabajo";
  - el botón "Hoja de producción" apunta a `pedidos.produccion`;
  - cotizaciones y facturas dentro de dos `<details>`; Cotizaciones con `open` y Facturas sin él;
  - `?factura=` propia abre la sección Facturas;
  - `?ot=` propia abre la orden en el visor; ajena se ignora;
  - sin cotizaciones ni facturas, se abre la orden más reciente;
  - la lista de órdenes no hace una consulta por fila.
- **`OrdenTrabajoTest`:**
  - `vista-previa` muestra la orden con sus acciones; ajena o venta sin orden → 404;
  - "avanzar" con `origen=dashboard` redirige a `dashboard?ot={id}` con el aviso; sin `origen`, al
    detalle;
  - la vista previa no muestra "Avanzar" ni "Editar" en una venta entregada.
- **`TimbrarCotizacionTest`** y las pruebas de 011 a 022 siguen pasando.

### Criterios de aceptación

1. La primera columna se llama "Documentos" y tiene Cotizaciones y Facturas como secciones que se
   abren y cierran por separado, también sin JavaScript.
2. Al entrar, Cotizaciones está abierta y Facturas cerrada (salvo que se abra una factura); la
   elección se recuerda en ese navegador.
3. Los botones "Ver todas" y "Nueva cotización" funcionan sin abrir ni cerrar la sección.
4. Al timbrar directo, la sección Facturas se abre y muestra la factura nueva arriba.
5. La segunda columna muestra las 25 órdenes de trabajo más recientes con folio, cliente, estado y
   "Falta color" si aplica, con buscador y el botón "Hoja de producción".
6. Clic en una orden la muestra en el visor sin recargar; recargar la conserva (`?ot=`).
7. Desde el visor, "Avanzar" pide confirmación y regresa al dashboard con la orden abierta y el
   aviso.
8. Nada funciona con órdenes ajenas (404). Pint, Pest y `node --check` pasan.

### Supuestos asumidos

1. El acordeón es de secciones independientes (no se cierra una al abrir la otra).
2. Facturas empieza cerrada porque cotizaciones es lo que más se usa en el inicio.
3. La columna de órdenes muestra **todos** los estados, incluidas las terminadas, ordenadas por
   alta; el buscador y la etiqueta de estado bastan para ubicar las pendientes. Si se prefiere ver
   solo `en_dibujo` y `en_proceso`, es un cambio de una línea en la consulta.
4. No se agrega "Nueva orden" en la columna: una orden se crea desde su venta, que es donde se ve si
   tiene pago (022).
5. Avanzar desde el visor recarga la página (envío normal), en lugar de AJAX, para no agregar
   llamadas nuevas a lo de 022.
6. El parámetro de la URL es `ot` con el id de la orden; la ruta de la vista previa usa la venta,
   como el resto de las rutas de 022.
7. Este cambio reemplaza el supuesto 2 de arriba ("dos listas y un visor").

### Estado de implementación de la corrección 1

Implementada el 2026-10-02.

- **Archivos nuevos**:
  - `components/ordenes-trabajo/fila`, `ordenes-trabajo/_vista-previa` y `ordenes-trabajo/_detalle`
    (el cuerpo de la orden, que ahora comparten `show` y la vista previa),
  - `public/js/dashboard-secciones.js` (recuerda qué secciones del acordeón están abiertas).
- **Archivos modificados**:
  - `DashboardController` (órdenes recientes, `?ot=`, `facturasAbiertas`),
  - `OrdenTrabajoController` (`vistaPrevia`; `datosVistaPrevia()` público y estático, que también usan
    `show` y el dashboard; `avanzar` con `origen=dashboard`), `routes/web.php`,
  - vistas `dashboard` y `ordenes-trabajo/show`,
  - `timbrar-cotizacion.js` (abre la sección Facturas), `bandeja-documentos.js` (comentario) y
    `app.css` (acordeón, miniatura de la fila y tarjetas de la orden en el visor),
  - pruebas `DashboardTest` (6 nuevas) y `OrdenTrabajoTest` (2 nuevas y la ruta `vista-previa` en las
    de sesión, venta ajena y venta sin orden).
- **Decisiones al implementar**:
  - "Ver todas" de Facturas pasó de enlace de texto a botón de solo icono (`bi-eye`), igual que el
    de Cotizaciones, para que los dos encabezados del acordeón se vean parejos.
  - El número junto a cada título es el de documentos **cargados** (máximo 25), no el total.
  - La sección que contiene el documento abierto en el visor se queda abierta aunque el navegador
    recuerde que estaba cerrada.
  - Abrir Facturas por un timbrado también queda recordado (el evento `toggle` no distingue quién
    abrió la sección).
  - Con las dos secciones abiertas, cada lista se limita a `40vh` con scroll propio (`:has()`); con
    una sola, la lista crece y la columna hace scroll.
  - La fila de la orden muestra la miniatura del diseño en lugar de las iniciales del cliente; sin
    imagen, un recuadro con el icono.
  - No se agregó prueba JS para `bandeja-documentos.js`: es un script ligado al DOM sin funciones
    exportadas, y las pruebas de `tests/js` solo cubren funciones puras.
- **Verificación**:
  - la suite Pest pasa completa (1052 pruebas),
  - `node --test tests/js/*.test.js` pasa (37),
  - Pint no reporta cambios y `node --check` valida los scripts tocados.

  **No se revisó la UI en un navegador real.** Falta probar en escritorio, tableta y celular: abrir y
  cerrar las secciones (también que los botones del encabezado no las abran ni cierren), que se
  recuerden al recargar, el scroll con las dos abiertas, el clic en filas de órdenes, "Avanzar" desde
  el visor y la sección Facturas abriéndose tras un timbrado directo.

## Estado de implementación

Implementada el 2026-10-02, en tres entregas: inicio con dos listas y visor, ventana "Nueva
cotización" y timbrado directo.

- **Archivos nuevos**:
  - `TimbrarCotizacionRequest`,
  - `components/facturas/fila` y `hoja`, `facturas/_vista-previa`,
    `cotizaciones/_dialogo-nueva` y `_dialogo-timbrar`,
  - `public/js/timbrar-cotizacion.js`,
  - pruebas `TimbrarCotizacionTest` (16).
- **Archivos modificados**:
  - `DashboardController`, `FacturaController` (`vistaPrevia`, `timbrarCotizacion`, guardado
    compartido `guardarNueva`, mensajes en `mensajeTimbrado`), `CotizacionController` (`store` con
    `origen=dashboard`; `datosAcciones` y `datosFormulario` públicos y estáticos),
  - modelo `Cotizacion` (`avisosDePrecio`), `routes/web.php`,
  - vistas `dashboard`, `cotizaciones/index`, `cotizaciones/_vista-previa`,
    `components/bandeja/encabezado-lista`,
  - `bandeja-documentos.js`, `dashboard-apps.js` (comentario) y `app.css` (se quitaron los estilos
    del saludo que tenía el inicio),
  - pruebas `DashboardTest` y `FacturasTest`.
- **Decisiones al implementar**:
  - `timbrarCotizacion` sin JavaScript delega en `store`: misma redirección y mismos mensajes.
  - Con el timbrado fallido la respuesta JSON también trae la fila, porque la factura pendiente ya
    existe y debe verse en la lista.
  - La ventana de confirmación no se cierra con un clic en el fondo (no hay manejador para eso en
    los diálogos de `app.js`); sí con Esc y "Cancelar".
- **Verificación**:
  - la suite Pest pasa completa (975 pruebas),
  - `node --test "tests/js/*.test.js"` pasa (37),
  - Pint no reporta cambios y `node --check` valida los scripts tocados.

  **No se revisó la UI en un navegador real.** Falta probar en escritorio, tableta y celular: el
  clic en filas de las dos listas, los buscadores, la ventana "Nueva cotización" (alta y error) y el
  timbrado directo con facturapi.io en modo de pruebas (fila nueva, aviso y vista previa refrescada).

## Criterios de aceptación

1. El dashboard muestra las columnas Cotizaciones y Facturas con los 25 documentos más recientes del
   usuario, y a la derecha la cotización más reciente.
2. Clic en una fila muestra el documento en el visor sin recargar; recargar conserva el documento
   abierto.
3. Los buscadores de cada columna filtran mientras se escribe, sin importar mayúsculas ni acentos.
4. Junto a "Cotizaciones" hay dos botones pequeños de solo icono, a la derecha: "Ver todas" (ojo) y
   "Nueva cotización" (+).
5. "Nueva cotización" abre la ventana de tres pasos; al guardar se vuelve al dashboard con la
   cotización nueva abierta y el aviso; con errores, la ventana vuelve abierta con lo capturado.
6. En la vista previa de una cotización facturable, "Facturar" abre la confirmación con el resumen,
   los avisos de precio y los tres datos fiscales.
7. "Sí, timbrar" timbra sin salir de la página: la factura aparece arriba en "Facturas", la cotización
   muestra "Facturada" y su "Facturar" desaparece, y un aviso da el folio fiscal y el UUID.
8. Si el timbrado falla, la factura pendiente también aparece en la lista y el aviso explica el error.
9. Una cotización ya facturada, no facturable o con datos fiscales inválidos no crea factura y el
   motivo se ve en la ventana.
10. El detalle de la cotización sigue llevando al formulario de factura.
11. Nada funciona con documentos ajenos (404).
12. Pint no reporta cambios, la suite Pest pasa y `node --check` valida los scripts.

## Supuestos asumidos (registro completo)

1. El inicio del dashboard muestra cotizaciones y facturas reales; la bandeja de correo de
   demostración pasa al menú de aplicaciones.
2. No hay columna de carpetas: dos listas y un visor. *(Reemplazado por la Corrección 1:
   documentos en acordeón, órdenes de trabajo y visor.)*
3. Cada lista muestra las 25 más recientes, sin paginar; "Ver todas" lleva al listado completo.
4. Los buscadores del dashboard filtran solo lo cargado.
5. Se abre al entrar la cotización más reciente; el documento abierto queda en la URL.
6. "Nueva cotización" es una ventana sobre el dashboard; al guardar se vuelve al dashboard.
7. Un clic fuera de la ventana "Nueva cotización" no la cierra.
8. "Facturar" en la vista previa timbra directo tras una confirmación; el detalle sigue usando el
   formulario.
9. La confirmación pide uso de CFDI, método y forma de pago, con los valores por defecto del
   formulario, porque la forma de pago no tiene un valor seguro por defecto.
10. La factura toma de la cotización el cliente, las líneas, los precios cotizados y el descuento
    global, sin cambios.
11. Tras timbrar se refrescan la lista de facturas, la fila y la vista previa de la cotización; el
    visor sigue mostrando la cotización.
12. Si el timbrado falla, la factura queda pendiente (como en 012) y se ve en la lista.
13. El timbrado directo también funciona en `/cotizaciones`, que comparte la vista previa; ahí no hay
    lista de facturas que actualizar.
