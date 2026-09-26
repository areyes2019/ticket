# Spec: El QR como hilo conductor hacia Producción

## Historia de usuario

Como usuario único del sistema, fabrico sellos a partir de lo que coticé ([008](008-cotizaciones.md))
o vendí por mostrador ([027](027-venta-mostrador-ticket.md)), y ya llevo esos trabajos por un tablero
de Producción ([038](038-produccion-ordenes-trabajo.md)) con su propio QR de entrega. Lo que me falta
es que, cuando el cliente llega y escaneo ese QR, el sistema me lleve **directo a la ficha del
trabajo en Producción** — no a la pantalla de entrega del ticket o la cotización, que es donde me
deja hoy. Ahí quiero ver de un vistazo el cliente, el producto, la imagen del sello y el estado, sin
tener que buscar nada más.

Cuando el trabajo ya está listo y el cliente lo recoge en el mostrador, quiero marcarlo como
**entregado** desde esa misma ficha. Si todavía debe algo, quiero que aparezca un botón **Cobrar**
que me lleve al formulario de pago que ya existe — no que el sistema cobre solo al momento de
entregar, como hace hoy. Prefiero separar las dos cosas: primero confirmo que el sello salió de mis
manos, y después, aparte, registro el cobro si hace falta.

También quiero poder compartirle el QR de una cotización al cliente sin bajar la imagen a mano y
buscarla en una carpeta: un botón "Compartir QR" en la pantalla de la cotización que abra un cuadro
con el código en grande y los datos básicos del trabajo, con un botón que use el panel nativo de
compartir de Windows.

El QR en sí no cambia: sigue siendo el mismo desde que se genera la cotización o el pedido hasta que
el trabajo se entrega, y sigue apareciendo igual en pantalla, PDF y ticket. Lo único que cambia es a
dónde me lleva escanearlo, y cómo separo la entrega del cobro una vez ahí.

## Objetivo / Alcance

Tres cambios sobre lo que ya construyó [038](038-produccion-ordenes-trabajo.md), sin tocar el
mecanismo de generación del QR:

1. **El escáner de mostrador (029/038) redirige a Producción cuando corresponde.** Si el documento
   escaneado (`Pedido` o `Cotizacion`) ya tiene una Orden de Trabajo asociada, el escaneo navega
   directo a `/produccion/{id}` en vez de a la vista de entrega del documento. Si no la tiene, el
   comportamiento no cambia.
2. **La ficha de Producción entrega en mostrador sin cobrar sola.** Desde `listo_para_entregar`, un
   botón nuevo "Marcar como entregado" cierra el documento origen y sincroniza la orden, sin exigir
   ni cobrar el saldo en el mismo paso. Si queda saldo, aparece un botón "Cobrar" que lleva al
   formulario de pago que ya existe en el detalle del documento.
3. **"Compartir QR" en la Cotización.** Botón nuevo en la pantalla de detalle (PC) que abre un modal
   con el QR en grande, datos básicos del trabajo, y un botón "Compartir" sobre la Web Share API del
   navegador (panel nativo de Windows 11 cuando está disponible).

No se toca la generación del QR, su formato, ni las columnas o tablas que ya existen: todo lo que se
necesita ya está expuesto (`orden_trabajo_id` en `PedidoResource`/`CotizacionResource`, `qr_entrega`
en `CotizacionResource`, `saldo_pendiente` en `OrdenTrabajoResource`).

## Backend (Laravel)

**No se necesita ningún endpoint, migración ni columna nueva.** Todo lo que este cambio requiere ya
existe:

- `PedidoController::show` y `CotizacionController::show` ya cargan la relación `ordenTrabajo`, y sus
  Resources ya exponen `orden_trabajo_id` — suficiente para que el frontend decida a dónde navegar al
  escanear.
- `PedidoController::entregar` / `CotizacionController::entregar` **ya soportan marcar entregado sin
  cobrar**: solo registran un pago si reciben `cuenta_id` y hay saldo (`if ($saldo > 0 && $cuentaId
  !== null)`); si no llega `cuenta_id`, el documento se marca `Entregado` igual y
  `OrdenTrabajoController::sincronizarEntrega()` cierra la orden en el mismo paso — exactamente el
  comportamiento que pide la sección 2 de la historia. El frontend solo necesita dejar de mandar
  `cuenta_id` cuando la entrega se dispara desde la ficha de Producción.
- `OrdenTrabajoResource` ya expone `saldo_pendiente` (leído del documento origen) para decidir si se
  muestra el botón "Cobrar".
- `CotizacionResource` ya expone `qr_entrega` (base64) y `url_entrega` en el `show` — el modal
  "Compartir QR" los consume tal cual, sin pedir nada nuevo al backend.

## Frontend (Vue 3)

### `MostradorEscanearView.vue` — redirección a Producción

Hoy `onCodigo()` navega siempre a `pedidos-entregar`/`cotizaciones-entregar` en cuanto
`documentoDeCodigoEtiqueta()` reconoce el código. Se agrega un paso: antes de navegar, se consulta el
documento (`pedidos.fetchOne(id)` / `cotizaciones.fetchOne(id)`, las mismas llamadas que ya usan sus
vistas de detalle) y se lee `orden_trabajo_id`:

- Si existe, navega a `produccion-detalle` con `params: { id: orden_trabajo_id }`.
- Si es `null`, navega a `pedidos-entregar`/`cotizaciones-entregar` como hasta ahora.

El comentario actual de este archivo ("lo que hace el escaneo no cambia: cobra el saldo, marca
entregado...") deja de ser cierto para documentos con Orden de Trabajo y se corrige.

### `OrdenTrabajoDetalleView.vue` — entregar en mostrador y cobrar aparte

- **Nueva acción, visible cuando `orden.estado === 'listo_para_entregar'`:** botón "Marcar como
  entregado" (además del ya existente "Enviar a domicilio" — las dos conviven en ese estado, una para
  quien recoge en persona y otra para quien lo recibe a domicilio). Llama a
  `pedidos.entregar(orden.documentable_id)` o `cotizaciones.entregar(orden.documentable_id)` según
  `orden.documentable_type`, **sin** pasar `cuentaId`. Al responder, se refresca la orden
  (`ordenesTrabajo.fetchOne`) para traer el nuevo `estado` (`entregado`, sincronizado por el backend)
  y el `saldo_pendiente` actualizado.
- Se retira el texto "La entrega en mostrador se hace escaneando el QR del ticket, no desde aquí" —
  ahora es justo al revés: el QR trae hasta aquí.
- **Nuevo botón "Cobrar"**, visible cuando `orden.estado === 'entregado'` y `orden.saldo_pendiente >
  0`. Abre un diálogo **dentro de la misma ficha** (monto, fecha, cuenta) en vez de navegar a otra
  pantalla — ver "Modo mostrador" abajo, la razón por la que no se reutiliza el detalle completo del
  documento. Al confirmar, llama a `pedidos.registrarPago(...)` o, para Cotización, primero trae el
  documento completo (`cotizaciones.fetchOne`, necesario porque la ficha de Producción no carga sus
  `pagos`) para deducir el `tipo` con `tipoDePago()` de `lib/pagoCotizacion.ts` (ya usado por el
  mostrador de Cotización, 031) y luego llama a `cotizaciones.registrarPago(...)`. Ningún formulario
  de pago nuevo: mismo endpoint, mismo cálculo de tipo, solo que capturado sin salir de Producción.
- El botón "Marcar como entregado" que ya existía para `a_domicilio` (llama a
  `ordenesTrabajo.entregar`, el endpoint de la propia Orden de Trabajo) no cambia — sigue siendo la
  confirmación del repartidor, distinta de la entrega en mostrador.

### Modo mostrador (`lib/modoMostrador.ts`) — la ficha de Producción se suma a la lista blanca

El escáner (029) vive dentro del modo mostrador (la PWA instalada en el aparato del mostrador), que
solo permite navegar a una lista fija de rutas — 038 había decidido explícitamente que Producción
quedaba fuera de esa lista, pensada para la pantalla completa de escritorio. Como ahora el escaneo
navega ahí, `produccion-detalle` se agrega a `RUTAS_PERMITIDAS`, con el mismo criterio que ya se usó
para `pedidos-entregar`/`cotizaciones-entregar`: un solo registro, no el tablero completo (`produccion`
sigue bloqueada). Por la misma razón, "Cobrar" no navega a `pedidos-detalle`/`cotizaciones-detalle`
—ninguna de las dos está en la lista blanca, y no existe una pantalla de consulta de Pedido para
mostrador (031 solo agregó la de Cotización)— sino que abre su propio diálogo dentro de la ficha.

### `CotizacionDetalleView.vue` — botón y modal "Compartir QR"

Junto a los botones "Imprimir etiqueta"/"Crear Orden de Trabajo" (sección de acciones del detalle),
un botón nuevo "Compartir QR" abre un `Dialog` con:

- La imagen `cotizacion.qr_entrega` (ya viene en base64 desde el `show`) en grande.
- Cliente (`cliente_razon_social`), folio, e importe (`total` si no hay pagos, `saldo_pendiente` si
  los hay — mismo dato que ya se muestra en el resto de la pantalla).
- Botón "Compartir" que convierte el data URI de `qr_entrega` a `Blob` y llama a
  `compartirArchivo(blob, 'QR-cotizacion-{folio}.png', texto)` de `lib/compartir.ts` (ya existente,
  usado hoy por envíos y ticket) — sin lógica de compartir nueva. El `texto` opcional lleva cliente y
  folio, para que el respaldo de WhatsApp (cuando no hay panel nativo) ya traiga el mensaje escrito.

El `Pedido` no gana un botón equivalente: su ticket ya incluye el mismo QR y se comparte como imagen
completa (027).

## Fuera de alcance

- Cambiar el mecanismo de generación del QR, su formato, o el hecho de que sea uno solo por
  documento — ya resuelto por 027/038.
- Un botón "Compartir QR" en `Pedido`: el ticket ya cumple esa función.
- Un botón "Deshacer" para la entrega hecha desde la ficha de Producción (`marcar como entregado` en
  `listo_para_entregar`). El `deshacerEntrega` de `Pedido`/`Cotizacion` (027/038) sigue existiendo y
  sigue siendo aplicable en su ventana de 10 minutos, pero no se expone un atajo para él desde
  Producción — se corrige desde el detalle del documento, como cualquier otra entrega.
- Cobrar automáticamente al marcar entregado desde Producción: es exactamente lo que esta spec
  elimina.
- Crear la Orden de Trabajo automáticamente al generar la cotización/pedido: se sigue creando a mano
  desde el documento, como en 038.
- Un segundo QR propio de la Orden de Trabajo: sigue sin existir (038, "fuera de alcance").
- Tocar el flujo de "Enviar a domicilio" (formulario, tarifas, Tesorería): no cambia nada de 038 ahí.

## Criterios de aceptación

1. Escanear el QR de un `Pedido`/`Cotizacion` **sin** Orden de Trabajo asociada sigue llevando a la
   vista de entrega de siempre.
2. Escanear el QR de un `Pedido`/`Cotizacion` **con** Orden de Trabajo asociada navega directo a
   `/produccion/{id}` de esa orden, sin pasar por la vista de entrega del documento.
3. Desde la ficha de Producción, con la orden en `listo_para_entregar`, "Marcar como entregado" cierra
   el documento origen y sincroniza la orden a `entregado`, sin registrar ningún pago aunque haya
   saldo pendiente.
4. "Enviar a domicilio" sigue disponible en `listo_para_entregar` junto con el nuevo "Marcar como
   entregado" — ninguna de las dos deshabilita a la otra mientras no se elija una.
5. Si al marcar entregado el documento tenía saldo pendiente, la ficha de Producción muestra un botón
   "Cobrar" que abre, en un diálogo dentro de la misma ficha, el registro de pago con el mismo
   endpoint que ya usan Pedido/Cotización.
6. Si no queda saldo pendiente, no aparece el botón "Cobrar".
7. En modo mostrador (PWA instalada), escanear un QR con Orden de Trabajo asociada llega a la ficha
   de Producción sin quedar bloqueado por el candado de rutas — y "Cobrar" funciona ahí mismo, sin
   depender de una pantalla fuera de la lista blanca.
8. La pantalla de detalle de Cotización tiene un botón "Compartir QR" que abre un modal con el QR en
   grande, cliente, folio e importe.
9. El botón "Compartir" del modal usa `navigator.share` cuando el navegador lo soporta; si no,
   descarga la imagen del QR (mismo respaldo que ya usa `compartirArchivo` en el resto del sistema).
10. El `Pedido` no gana ningún botón "Compartir QR" adicional.
11. ESLint/Prettier y Pint corren sin errores sobre el código nuevo; `php artisan test` sigue en
    verde.

## Supuestos asumidos (registro completo)

1. La Orden de Trabajo se sigue creando manualmente desde el detalle del `Pedido`/`Cotizacion` (038)
   — esta spec no la vuelve automática.
2. Mientras un documento no tenga Orden de Trabajo, escanear su QR mantiene el comportamiento actual
   (vista de entrega del documento) — no hay ficha de Producción a la cual ir todavía.
3. En cuanto el documento tiene Orden de Trabajo, escanear lleva directo a `/produccion/{id}`, sin
   excepción por el estado de la orden (incluida `entregado`, que muestra su mensaje ya existente).
4. "Marcar como entregado" desde Producción reutiliza el endpoint `entregar` de `Pedido`/`Cotizacion`
   sin `cuenta_id` — no se crea ningún endpoint nuevo. El backend ya soporta este caso: solo cobra si
   recibe `cuenta_id`.
5. El botón "Cobrar" no navega a otra pantalla: abre un diálogo dentro de la propia ficha de
   Producción, con el mismo endpoint de pagos de `Pedido`/`Cotizacion`. Decisión tomada durante la
   implementación (ver "Modo mostrador" en Frontend): el diseño original navegaba al detalle del
   documento origen, pero esa pantalla queda fuera de la lista blanca del modo mostrador (029/038) y
   no existe una versión ligera de ella para `Pedido` en ese modo — se descartó antes de escribir
   ningún componente nuevo.
6. Para Cotización, el diálogo de "Cobrar" deduce el tipo de pago ("saldo" si ya hay un anticipo
   registrado, "pago total" si no) con `tipoDePago()` de `lib/pagoCotizacion.ts` — la misma función
   que ya usa el mostrador de Cotización (031) — consultando el documento completo en el momento de
   cobrar, porque la ficha de Producción no trae cargados sus pagos.
7. `produccion-detalle` se agrega a la lista blanca del modo mostrador (`RUTAS_PERMITIDAS`), como
   excepción puntual igual que `pedidos-entregar`/`cotizaciones-entregar` — un solo registro, no el
   tablero. Esto amplía, sin contradecirla del todo, la decisión de 038 (supuesto 16) de mantener
   Producción fuera del modo mostrador: el tablero (`produccion`) sigue fuera: solo la ficha de un
   trabajo específico, alcanzada por QR, entra a la lista blanca.
8. El botón "Compartir QR" se agrega solo en la pantalla de Cotización (PC); el `Pedido` no lo
   necesita porque su ticket ya lleva el QR integrado como imagen compartible completa.
9. El modal "Compartir QR" muestra: imagen del QR, cliente, folio e importe (total o saldo pendiente,
   el que ya se esté mostrando en el resto de esa pantalla).
10. "Compartir" reutiliza `lib/compartir.ts` (`compartirArchivo`) convirtiendo el `qr_entrega` (base64)
    a `Blob` en el cliente — no se pide una imagen nueva al backend.
11. Si el navegador no soporta `navigator.share`, el botón "Compartir" descarga la imagen del QR como
    respaldo (mismo comportamiento ya definido en `lib/compartir.ts` para cualquier otro archivo).
12. No se agrega un botón "Deshacer" para la entrega hecha desde Producción; se sigue corrigiendo
    desde el detalle del documento si hace falta, dentro de la misma ventana de 10 minutos que ya
    existe.
13. El botón "Marcar como entregado" que ya existía para el estado `a_domicilio` (confirmación del
    repartidor) no cambia: sigue llamando al endpoint propio de la Orden de Trabajo, distinto del que
    usa la entrega en mostrador.
