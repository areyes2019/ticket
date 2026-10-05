# Spec: El pago de la cotización crea el Pedido y la Orden de trabajo

> **Estado: implementada** el 2026-10-04, con autorización del usuario. Ver "Estado de
> implementación".

**Modifica:**

- [021-cotizacion-aceptada-a-venta.md](021-cotizacion-aceptada-a-venta.md): **se retira el botón
  "Aceptar"**. La venta (Pedido) de una cotización ya no nace de "Aceptar", nace **del primer pago**
  y solo cuando el cliente no es distribuidor y la cotización no es solo de suministros. Los pagos
  de esa venta **se quedan en la cotización**.
- [022-ordenes-trabajo.md](022-ordenes-trabajo.md): la orden de una venta de cotización **nace sola**
  con el primer pago, sin colores, y lleva **solo las líneas de producción**. La de mostrador sigue
  creándose a mano, igual que hoy.
- [011-cotizaciones.md](011-cotizaciones.md): los pagos de la cotización vuelven a ser **el** flujo
  vigente (021 los dejaba como "flujo anterior"). El primer pago se admite también en `borrador`, y
  los siguientes en `aceptada`.
- [008-catalogos.md](008-catalogos.md): el catálogo gana la casilla **"Requiere producción"**, que
  heredan todos sus artículos.
- [007-gestion-articulos.md](007-gestion-articulos.md): la ficha y el formulario del artículo muestran
  "Producción (por su catálogo)". No hay campo propio.
- [019-pedidos-mostrador.md](019-pedidos-mostrador.md): una venta cobrada en su cotización no recibe
  pagos propios, se corrige editando la cotización y cobra el saldo de la entrega en la cotización.
- [018-inventario.md](018-inventario.md): la cotización **con** venta descuenta al nacer la venta
  (como en 021). La que **no** tiene venta descuenta al marcarse "producto entregado" (como en 018).
- [020-dashboard-cotizaciones-facturas.md](020-dashboard-cotizaciones-facturas.md): sale el botón
  "Aceptar" de la vista previa. La carpeta "Aceptadas" se queda.

**No modifica:** Tesorería (016): el pago sigue siendo un `CotizacionPago` con su ingreso "Anticipo
de Cotización COT-0012". Tampoco modifica Facturación (012, 015), cuya regla de una sola factura
vigente de 021 se queda igual, ni el precio distribuidor (028), cuya marca `es_distribuidor` se usa
tal cual.

## Historia de usuario

Como usuario, quiero que al registrar el primer pago de una cotización el sistema decida solo si hace
falta un Pedido y una Orden de trabajo: a un distribuidor o a una venta de puros suministros no les
hace falta ninguno; a un cliente normal le hace falta el Pedido y, si algo se fabrica, también la
Orden de trabajo. Así ya no tengo que "Aceptar" la cotización ni acordarme de abrir la orden.

```
AL REGISTRAR UN PAGO EN UNA COTIZACIÓN

SI pago = 0                                  → No hacer nada
SI todos los artículos son SUMINISTROS       → Ni Pedido ni Orden de trabajo
SI cliente = DISTRIBUIDOR                    → Ni Pedido ni Orden de trabajo
SI cliente = NORMAL                          → Crear Pedido
      SI al menos un artículo es de PRODUCCIÓN → Crear Orden de trabajo
      SI NO                                    → Sin Orden de trabajo
```

```
COT-0012 (cliente normal: Sello 4912 [Sellos = producción], Cojín [Accesorios = suministro])
     │
     └── [Registrar pago]  anticipo $500
              │   aviso: "Al registrar este pago se creará la venta y su orden de trabajo."
              ▼
         COT-0012  → Aceptada, guarda el pago (Tesorería: "Anticipo de Cotización COT-0012")
              │
              └── VENTA PED-0050  ← las 2 líneas, mismo total; saldo leído de la cotización
                       │
                       └── ORDEN DE TRABAJO (En dibujo)  ← solo "Sello 4912", sin color
```

## Objetivo / Alcance

1. Una marca **suministro / producción** por catálogo, heredada sin excepción por sus artículos.
2. Una regla única, `Cotizacion::destinoAlCobrar()`, que dice qué nace con el primer pago: nada, la
   venta, o la venta y su orden de trabajo.
3. El **primer pago** aplica la regla en la misma transacción del pago.
4. La venta de cotización **no tiene pagos propios**: los lee de la cotización. Así no hay dos
   ingresos en Tesorería por el mismo dinero.
5. Las tres adiciones técnicas aceptadas: el aviso en la ventana del pago, la insignia
   "Producción" y el mensaje con enlaces después de cobrar.
6. Laravel + Blade + JavaScript nativo. **Ninguna llamada AJAX nueva.**

**No** incluye la migración de las ventas creadas con "Aceptar" (021), ni cambios a la venta de
mostrador, ni marcar producción por artículo (ver "Fuera de alcance").

## La regla

### "Pago = 0"

No hace falta código nuevo: el sistema ya impide un pago de $0. `CotizacionPagoRequest` exige
`monto > 0` en el anticipo, y el saldo y el pago total son el saldo pendiente, que
`puedeRegistrarPago()` exige mayor que cero. Una cotización con total $0 no recibe pagos, así que
tampoco crea nada. Se agrega una prueba que lo deja fijo.

### Qué es producción

- `catalogos.requiere_produccion` (`boolean`, default `false`). Por omisión todo es **suministro**.
- `Articulo::requiereProduccion(): bool` → la casilla de su catálogo (`withTrashed()`).
- `CotizacionLinea::esProduccion(): bool` → una línea libre (sin `articulo_id`) **es producción**.
  Una línea de artículo es producción si el artículo lo es.
- La marca se lee **en el momento del primer pago**. Cambiar la casilla después no crea ni borra
  ventas ni órdenes que ya existen.

### `App\Enums\DestinoCobro` (nuevo)

| Caso | Valor | Aviso en la ventana del pago |
|---|---|---|
| `SinVentaDistribuidor` | `sin_venta_distribuidor` | "Cliente distribuidor: no se creará venta ni orden de trabajo." |
| `SinVentaSuministros` | `sin_venta_suministros` | "Solo suministros: no se creará venta ni orden de trabajo." |
| `VentaYOrden` | `venta_y_orden` | "Al registrar este pago se creará la venta y su orden de trabajo." |

Con `creaVenta(): bool`, `creaOrden(): bool` y `aviso(): string`. No hay un caso "venta sin orden"
(supuesto 20).

### `Cotizacion::destinoAlCobrar(): ?DestinoCobro`

Devuelve `null` cuando el pago que viene **no es el primero**: la cotización ya tiene pagos o ya
tiene venta. En los demás casos revisa, en este orden:

1. Ninguna línea `esProduccion()` → `SinVentaSuministros`.
2. `cliente->es_distribuidor` → `SinVentaDistribuidor`.
3. En cualquier otro caso → `VentaYOrden`.

Con el orden de la tabla del usuario, "todo suministros" se revisa antes que "distribuidor". Para el
resultado da igual, porque los dos casos terminan sin venta. El orden solo decide qué aviso se lee.

> **Decidido por el usuario (supuesto 20):** una cotización de puros suministros **no crea nada**,
> sea quien sea el cliente: se cobra, se entrega y se factura desde la cotización (011). A un
> cliente normal la venta solo se le crea cuando lleva algo de producción, y siempre con su orden.
> La línea "cliente normal sin producción → Pedido sin orden" de la tabla no se usa.

## Backend (Laravel)

### Migración `..._pago_cotizacion_crea_venta.php`

| Tabla | Columna | Definición |
|---|---|---|
| `catalogos` | `requiere_produccion` | `boolean`, default `false`, después de `utilidad_distribuidor_porcentaje` |
| `pedidos` | `cobro_en_cotizacion` | `boolean`, default `false`, después de `cliente_id` |
| `cotizacion_pagos` | `registrado_al_entregar` | `boolean`, default `false` |

- `cobro_en_cotizacion = true` marca las ventas que nacen con esta spec. Las ventas que nacieron con
  "Aceptar" (021) se quedan en `false` y conservan sus pagos propios y sus reglas (supuesto 18).
- `registrado_al_entregar` cumple en `cotizacion_pagos` el papel que tiene en `pedido_pagos` (019):
  marca el cobro que hizo el botón "Entregado". "Deshacer entrega" lo revisa.

`down()` quita las tres columnas.

### Modelo `Catalogo`

- `requiere_produccion` en `#[Fillable]`, con cast `boolean`.
- Cambiarla **no** dispara el recálculo de precios: `articulosPorRecalcular()` no la mira.

### Modelo `Cotizacion`

- `destinoAlCobrar(): ?DestinoCobro` (ver "La regla").
- `lineasDeProduccion(): Collection` → las líneas con `esProduccion()`. Precarga
  `lineas.articulo.catalogo` en una sola consulta.
- **`puedeRegistrarPago()` cambia**: saldo > 0 y, además, uno de estos casos:
  - estado `borrador` o `enviada` y **sin venta**. El primer pago se admite en `borrador` porque el
    cliente puede pagar en persona una cotización que nunca se le envió, como permitía "Aceptar"
    (021);
  - estado `aceptada` y su venta con `cobro_en_cotizacion` **no entregada**.
- `motivoRechazoPago()` gana el motivo "La venta PED-0050 ya se entregó." para el segundo caso.
- **Se retiran** `puedeAceptarse()`, `motivoNoAceptable()` y `ESTADOS_ACEPTABLES`. Se conserva
  `faltantesAlAceptar()` con el nombre `faltantesAlCrearVenta()`, que ahora usa la ventana del pago.
- `marcarAceptada()` y `revertirAceptacion()` se quedan como están.
- **`esEditable()` cambia**: además de `borrador` y `enviada`, una cotización `aceptada` cuya venta
  tiene `cobro_en_cotizacion` se edita mientras se cumplan estas condiciones:
  - la venta no está entregada;
  - ni la cotización ni la venta tienen factura vigente.

  Ver "Editar la cotización con venta". El motivo 403 de `CotizacionPolicy` para la aceptada se
  queda solo para las ventas de "Aceptar" (021), y para la de cotización entregada o facturada
  cambia a "La venta PED-0050 ya se entregó: la cotización queda solo para consulta." o "Ya tiene la
  factura FAC-0012."
- `puedeEliminarse()` no cambia: exige estar sin pagos, así que una aceptada con pagos no se borra.
- `puedeEntregarse()` no cambia (estado `pagada`): una cotización **con** venta está en `aceptada` y se
  entrega desde la venta.

**Estados de la cotización con el flujo nuevo:**

| Caso | Primer pago | Pagada por completo | Entrega |
|---|---|---|---|
| Con venta | `borrador`/`enviada` → `aceptada` | sigue en `aceptada` (la venta pasa a `pagado`) | botón "Entregado" de la venta (022) |
| Sin venta | `borrador` → `enviada`; `enviada` se queda | `pagada` | "Producto entregado" de la cotización (011) |

### Modelo `CotizacionLinea`

- `esProduccion(): bool` (ver "Qué es producción").

### Modelo `Pedido` (Venta)

`cobro_en_cotizacion` queda fuera de `#[Fillable]`, con cast `boolean`. Lo escribe solo
`CreadorVentaDeCotizacion`. Con `cobro_en_cotizacion = true` cambian estas reglas:

| Método | Regla nueva |
|---|---|
| `totalPagado()` | `cotizacion->totalPagado()`. El total de la venta es el de la cotización, porque la venta se copia de ella al editarla. |
| `tienePagos()` | `cotizacion->tienePagos()` |
| `puedeRegistrarPago()` | `false`. El detalle muestra "Los pagos se registran en la cotización COT-0012." con el enlace. |
| `puedeEliminarPago()` | `false` (no tiene pagos propios) |
| `esEditable()` | `false`: la venta **se edita desde la cotización** (supuesto 21) |
| `puedeDeshacerEntrega()` | la marca `registrado_al_entregar` se busca en `cotizacion->pagos()` |

`recalcularEstado()` no cambia: deriva `pendiente`/`anticipo`/`pagado` de `totalPagado()`, que ya
apunta a la cotización. Al llegar a `pagado` nace el token de autofactura, como en 019.

`PedidoPagoController::store` y `destroy` rechazan una venta así con ese mismo motivo. Es la red de
seguridad si alguien envía el formulario a mano.

### Crear la venta: `App\Services\Ventas\CreadorVentaDeCotizacion`

Sustituye a `AceptadorCotizacion` (021), con casi todo su cuerpo:
`crear(Cotizacion $bloqueada, array $datosCliente, bool $conOrden): Pedido`.

**No abre transacción ni bloquea**: lo llama el controlador del pago, que ya tiene la cotización
bloqueada (ver "Registrar el pago").

1. Folio con `Pedido::siguienteFolio()`.
2. Venta en `pendiente` con `cotizacion_id`, `cliente_id`, `cobro_en_cotizacion = true`, los datos de
   contacto de `$datosCliente` y el descuento global de la cotización.
3. Copia **todas** las líneas, de suministro y de producción, tal cual. Toma `costo_unitario` del
   artículo vigente y recalcula los totales con `CalculadoraTotalesDocumento`, como en 021.
4. Inventario: `salidaPorDocumento($venta, …, VentaPedido, creaFila: true)`, **sin bloquear** por
   existencia (regla de 021).
5. `$bloqueada->marcarAceptada()`.
6. Si `$conOrden`: crea la `OrdenTrabajo` en `en_dibujo`, **sin renglones de color y sin imagen**,
   con `user_id` y `pedido_id`.

### Editar la cotización con venta (supuesto 21)

La venta de cotización no tiene botón "Editar". Lo que cambia se corrige **en la cotización**, y al
guardar, `CotizacionController::update` sincroniza la venta en la misma transacción. Bloquea primero
la cotización y después la venta.

1. **Regla del total**: el total nuevo no puede ser menor a lo ya pagado. Motivo: "El total no puede
   quedar por debajo de lo pagado ($500.00)."
2. Guarda la cotización como hoy.
3. Recuerda los colores de la orden con `ConservadorColores::recordar()` (022).
4. Revierte el inventario de la venta (`revertirDocumento(…, CorreccionPedido)`).
5. Reescribe las líneas, el descuento global y los totales de la venta con los de la cotización.
   Reusa el copiado de `CreadorVentaDeCotizacion`, extraído a `copiarLineas()`.
6. Vuelve a descontar (`salidaPorDocumento(…, creaFila: true)`), sin bloquear.
7. `ConservadorColores::reaplicar()`: las líneas nuevas de producción quedan sin color y aparece
   el aviso de 022.
8. `recalcularEstado()` de la venta. Si el total subió, una venta `pagado` regresa a `anticipo`.

Los datos de contacto de la venta (nombre, teléfono y correo) no cambian al editar la cotización.

El cliente de la cotización **no se puede cambiar** una vez que tiene venta, porque el tipo de
cliente decidió la venta. El selector se muestra deshabilitado con la ayuda "El cliente ya no se
cambia: la cotización tiene venta."

Si al editar desaparecen todas las líneas de producción, la venta y la orden **se quedan**, porque la
regla solo se aplica con el primer pago (supuesto 17). La orden queda sin líneas y con el aviso
"Esta orden ya no tiene artículos de producción."

### Registrar el pago (`CotizacionPagoController::store`)

Dentro de la transacción que ya existe, con la cotización bloqueada:

1. Revisa `motivoRechazoPago()`, como hoy.
2. Calcula `$destino = $bloqueada->destinoAlCobrar()` **antes** de crear el pago.
3. Crea el pago y su ingreso en Tesorería, como hoy.
4. Si `$destino?->creaVenta()`:
   `CreadorVentaDeCotizacion::crear($bloqueada, $request->datosVenta(), $destino->creaOrden())`.
   Después llama a `recalcularEstado()` en la venta y la guarda, para que quede en `anticipo` o en
   `pagado`.
5. Si no hay venta:
   - una cotización en `borrador` pasa a `enviada`;
   - si el saldo llega a cero, pasa a `pagada`, como hoy.
6. Si ya había venta (pagos siguientes): `recalcularEstado()` de la venta y guardar. La cotización
   **no** pasa a `pagada`, se queda en `aceptada`.

Dos clics seguidos no crean dos ventas. El segundo encuentra la cotización con pago y con venta, y
`destinoAlCobrar()` devuelve `null`. El índice único de `pedidos.cotizacion_id` (021) es la última
red.

**Mensaje después de cobrar (adición 3).** El flash `exito` lleva el texto, y el flash nuevo
`exito_enlaces` lleva la lista `[texto => url]`. El parcial de mensajes de cotizaciones y el
dashboard pintan los enlaces dentro del mismo `x-alerta`:

- "Anticipo de $500.00 registrado. Se creó la venta PED-0050 y su orden de trabajo." → enlaces "Ver
  venta" y "Ver orden de trabajo".
- "Anticipo de $500.00 registrado. Se creó la venta PED-0050." → "Ver venta".
- Sin venta, el mensaje de hoy, con la razón: "Pago total de $1,200.00 registrado. Cliente
  distribuidor: no se creó venta."

Redirige a donde redirige hoy (`destinoCotizacion()`), no a la venta: el usuario ve el mensaje con
los enlaces y decide a dónde ir.

### Eliminar un pago (`CotizacionPagoController::destroy`)

Se conservan las reglas de 011: solo el último pago, y no en `producto_entregado`. Se agregan dos
motivos cuando la cotización tiene venta:

- "Los pagos de una venta entregada no se eliminan." (venta `entregado`);
- "La venta PED-0050 ya tiene la factura FAC-0012: cancélala antes de quitar el pago." (factura
  timbrada de la venta o de la cotización).

Después de borrar, `recalcularEstado()` de la venta. **La venta y su orden se conservan** aunque la
cotización quede sin pagos (supuesto 16): la venta vuelve a `pendiente`. Para deshacer todo se borra
la venta (ver abajo).

### Borrar la venta (019, 021)

Sigue la regla de 021: `pendiente` y sin pagos, que con la venta de cotización significa **sin
pagos en la cotización**. En la misma transacción se borran la orden (con Eloquent, como manda 022) y
las existencias, y `revertirAceptacion()` regresa la cotización a `enviada`. La siguiente vez que se
cobre, ese pago vuelve a ser "el primero" y la regla se aplica otra vez.

Mensaje de confirmación: "Se borrará la venta PED-0050 y su orden de trabajo. La cotización COT-0012
volverá a Enviada."

### Entregar la venta (022, corrección 1)

El botón "Entregado" se queda igual. Cambia solo **dónde se registra el cobro del saldo** cuando la
venta tiene `cobro_en_cotizacion`:

- en `cotizacion_pagos`, con tipo `saldo` si la cotización tiene anticipo o `pago_total` si no, la
  fecha de hoy, la cuenta elegida y `registrado_al_entregar = true`;
- el ingreso de Tesorería es el del pago de cotización ("Saldo de Cotización COT-0012").

La regla "una venta con orden se entrega con la orden terminada" no cambia.

### La orden de trabajo de una venta de cotización (022)

- `OrdenTrabajo::lineasDeTrabajo(): Collection` (nuevo). Con `pedido->cobro_en_cotizacion`, las líneas
  de la venta que son de producción: libres o de un artículo de un catálogo con
  `requiere_produccion`. Sin la marca, **todas** las líneas, como hoy en mostrador.
- `lineasSinColor()`, `OrdenTrabajoRequest` (una entrada por línea), el formulario, el detalle, la
  impresión y la hoja de producción usan `lineasDeTrabajo()` en lugar de `pedido->lineas`.
- La orden nace sin colores: el `x-alerta` "Falta el color de tinta de: …" de 022 aparece de
  inmediato, y `motivoNoAvanza()` impide pasar a "En proceso" hasta capturarlos. No hace falta regla
  nueva.
- El botón manual "Orden de trabajo" de 022 **se conserva** en la venta de cotización, con las
  reglas de 022: la venta tiene pagos (los de la cotización), no tiene orden y no está entregada
  (supuesto 22). Con la regla del supuesto 20, la venta de cotización siempre nace con su orden, así
  que el botón solo sirve como respaldo. La orden creada a mano también trabaja con
  `lineasDeTrabajo()`.
- Si la casilla del catálogo cambia después, `lineasDeTrabajo()` se lee en vivo y la orden puede
  ganar o perder líneas, igual que cuando se editan las líneas de la venta en 022. Las que se agregan
  quedan sin color.

### Rutas

- **Se borra** `POST /cotizaciones/{cotizacion}/aceptar` (`cotizaciones.aceptar`), con
  `CotizacionAceptacionController`, `AceptarCotizacionRequest` y `AceptadorCotizacion`.
- Ninguna ruta nueva. El pago sigue en `POST /cotizaciones/{cotizacion}/pagos`.

### Validaciones (`CotizacionPagoRequest`)

Cuando `destinoAlCobrar()?->creaVenta()`, el request también exige los datos de contacto de la venta,
con las mismas reglas que tenía `AceptarCotizacionRequest` (021):

- `cliente_nombre`: requerido, máx. 150;
- `cliente_telefono`: requerido, normalizado con `NormalizaTelefono`;
- `cliente_correo`: nullable, `email`.

En cualquier otro caso se excluyen (`exclude_unless`, decidido en `prepareForValidation()`).
`datosVenta(): array` los devuelve. No se escriben en el catálogo de Clientes.

## Vistas (Blade)

### Ventana "Registrar pago" de la cotización (detalle, vista previa, dashboard)

- **Aviso (adición 1)**: un `x-alerta tipo="info"` arriba del formulario con
  `destinoAlCobrar()->aviso()`. Solo aparece cuando el pago es el primero.
- Cuando el destino crea venta, un bloque **"Datos de la venta"** con nombre, teléfono y correo
  precargados, igual que la antigua ventana "Aceptar". La precarga es el `nombre_contacto` o, si está
  vacío, la `razon_social`, y el `telefono` y el `correo` del cliente.
- Si la venta va a nacer con faltantes, el aviso de 021 se queda: "Quedará faltante de: Sello 4912
  (faltan 2)."
- Con `data-enviar-una-vez`, como hoy.

### Cotización: detalle, vista previa y fila

- Sale el botón **"Aceptar"** y el parcial `cotizaciones/_dialogo-aceptar`.
- En una cotización con venta: la línea "Venta: PED-0050" y, si tiene, "Orden de trabajo: En
  dibujo", con sus enlaces. El botón "Registrar pago" sigue mientras haya saldo y la venta no esté
  entregada. El botón **"Editar"** sigue mientras `esEditable()`, y el formulario avisa: "Los cambios
  se copian a la venta PED-0050."


### Catálogos (008) y artículos (007): insignia (adición 2)

- Formulario de catálogo: casilla **"Requiere producción"** con la ayuda *"Sus artículos generan
  orden de trabajo cuando un cliente normal paga una cotización."*
- Listado de catálogos: insignia **"Producción"** (`etiqueta-produccion`, nueva en `app.css` con
  los tokens de 003) o un guion.
- Ficha y formulario del artículo: la línea de solo lectura "Producción (por su catálogo)" o
  "Suministro (por su catálogo)". No es un campo: se cambia en el catálogo.

### Venta: detalle (`pedidos/show`)

Con `cobro_en_cotizacion`:

- en lugar de "Agregar pago": "Los pagos se registran en la cotización COT-0012." con su enlace;
- la tabla de pagos muestra los de la cotización, solo para consulta;
- en lugar de "Editar": "Los artículos se corrigen en la cotización COT-0012." con su enlace.

## JavaScript

Ninguno nuevo. El bloque "Datos de la venta" lo decide el servidor al pintar la ventana: no depende
de lo que se elija en el formulario.

## Pruebas (Pest)

`tests/Feature/PagoCotizacionCreaVentaTest.php`, más ajustes en `CotizacionPagoTest`,
`OrdenTrabajoTest`, `PedidoEntregaTest`, `PedidosTest`, `CatalogoTest`, `DashboardTest` y
`AutofacturaTest`. Se borra `AceptarCotizacionTest`, y sus casos de inventario, facturación y borrado
pasan a la prueba nueva.

1. Un anticipo de $0 se rechaza y no crea nada. Una cotización con total $0 no admite pagos.
2. **Todo suministros** (cliente normal o distribuidor): el pago no crea venta ni orden; la
   cotización sigue el flujo de 011 hasta `producto_entregado`, que descuenta inventario.
3. **Distribuidor** con artículos de producción: no crea venta ni orden.
4. **Cliente normal con producción**: crea la venta con **todas** las líneas y el mismo total, y la
   orden en `en_dibujo`, sin colores y con solo las líneas de producción. La cotización queda
   `aceptada`.
5. Una línea libre cuenta como producción.
6. Un cliente normal con solo suministros no crea venta (supuesto 20).
7. Solo el primer pago aplica la regla: el saldo no crea una segunda venta ni una segunda orden. Dos
   peticiones seguidas crean **una** venta.
8. El primer pago se admite en `borrador`. Una cotización sin venta pasa a `enviada`.
9. Tesorería registra **un** ingreso por pago, el de la cotización. La venta no tiene `pedido_pagos`,
   y su saldo, su estado y su token de autofactura salen de los pagos de la cotización.
10. Agregar pago y editar están cerrados en una venta con `cobro_en_cotizacion`, también por petición
    directa.
11. Editar la cotización con venta copia líneas y totales a la venta, corrige el inventario, conserva
    los colores de la orden y recalcula el estado. Se rechaza un total menor a lo pagado, un cambio de
    cliente, una venta entregada o una cotización facturada.
12. En una venta de cotización sin orden, el botón manual de 022 crea la orden con solo las líneas de
    producción.
13. Entregar con saldo cobra en `cotizacion_pagos` con `registrado_al_entregar`. "Deshacer entrega"
    lo respeta.
14. Borrar el último pago conserva la venta y la orden, y la venta vuelve a `pendiente`. Borrar la
    venta borra la orden, devuelve existencias y regresa la cotización a `enviada`. El siguiente pago
    vuelve a aplicar la regla.
15. Cambiar la casilla del catálogo después del pago no crea ni borra ventas u órdenes; la orden
    muestra las líneas de producción vigentes.
16. Los datos de la venta son obligatorios solo cuando nace una venta, y no modifican al `Cliente`.
17. La ventana del pago muestra el aviso correcto en cada caso; el mensaje después de cobrar trae los
    enlaces a la venta y a la orden.
18. La ruta `cotizaciones.aceptar` ya no existe y no aparece ningún botón "Aceptar".
19. Las ventas creadas con "Aceptar" (021) conservan sus pagos propios y sus reglas.
20. Las suites de cotizaciones, ventas, órdenes de trabajo, Tesorería, inventario y facturación
    siguen pasando con los ajustes de arriba.

## Fuera de alcance

- Marcar producción **por artículo**: la casilla es del catálogo, sin excepciones (decidido por el
  usuario).
- Migrar las ventas creadas con "Aceptar" (021) para que cobren en la cotización.
- Aplicar la regla a la venta de mostrador (019): su orden sigue siendo manual y con todas las líneas.
- Crear una venta a mano para una cotización que la regla dejó sin venta (distribuidor o solo
  suministros).
- Orden de trabajo para distribuidores.
- Avisar o recalcular cuando cambia la casilla de un catálogo.

## Criterios de aceptación

1. Un catálogo se marca "Requiere producción" y todos sus artículos lo muestran; la insignia aparece
   en el listado.
2. El primer pago de una cotización de un cliente normal con algo de producción crea la venta (todas
   las líneas, mismo total) y su orden de trabajo (solo producción, En dibujo, sin colores).
3. El primer pago de un distribuidor, o de una cotización de puros suministros, no crea nada; esa
   cotización se cobra, entrega y factura desde ella misma.
4. La ventana del pago avisa antes qué va a pasar, y el mensaje de después trae los enlaces.
5. Los pagos siguientes se registran en la cotización y la venta refleja su saldo sin duplicar
   ingresos en Tesorería.
6. Editar la cotización con venta corrige también la venta, sin bajar el total de lo pagado.
7. Ya no existe el botón "Aceptar".
8. `php artisan test`, `pint` y `node --test "tests/js/*.test.js"` en verde.

## Estado de implementación

Implementada el 2026-10-04.

- **Archivos nuevos**:
  - migración `2026_10_13_100000_pago_cotizacion_crea_venta` (revisada con `migrate --pretend`
    contra MySQL y **aplicada en la base local**),
  - enum `DestinoCobro`, servicio `App\Services\Ventas\CreadorVentaDeCotizacion` (`crear()` y
    `sincronizar()`),
  - vistas `cotizaciones/_primer-pago` y `documentos/_enlaces-exito`,
  - pruebas `PagoCotizacionCreaVentaTest` (33).
- **Archivos borrados**: `AceptadorCotizacion`, `CotizacionAceptacionController`,
  `AceptarCotizacionRequest`, `cotizaciones/_dialogo-aceptar`, la ruta `cotizaciones.aceptar` y
  `AceptarCotizacionTest`. Sus casos de inventario, borrado, facturación y vistas pasaron a la
  prueba nueva.
- **Archivos modificados**:
  - modelos `Catalogo` (`requiere_produccion`), `Articulo` (`requiereProduccion()`),
    `CotizacionLinea` y `PedidoLinea` (`esProduccion()`), `CotizacionPago`
    (`registrado_al_entregar`), `Cotizacion` (`destinoAlCobrar()`, `lineasDeProduccion()`,
    `ventaQueCobraAqui()`, `faltantesAlCrearVenta()`; cambian `esEditable()`, `puedeEliminarse()`,
    `puedeRegistrarPago()` y `motivoRechazoPago()`), `Pedido` (`cobraEnCotizacion()`,
    `lineasDeTrabajo()`, `cobroAlEntregar()`; cambian `totalPagado()`, `tienePagos()`,
    `esEditable()`, `puedeRegistrarPago()`, `puedeEliminarPago()` y `puedeDeshacerEntrega()`) y
    `OrdenTrabajo` (`lineasSinColor()` sobre las líneas de trabajo),
  - `CotizacionPagoController`, `CotizacionPagoRequest`, `CotizacionController::update`,
    `CotizacionRequest`, `PedidoEntregaController`, `PedidoPagoController`, `PedidoPagoRequest`,
    `PedidoController` (listado sin consultas por fila, detalle con los pagos de la cotización),
    `OrdenTrabajoRequest`, `CatalogoRequest`, `ArticuloController`, `HojaProduccionController`,
    `DashboardController`, `OrdenTrabajoController` (precarga del catálogo), `Autofacturador`,
    `CotizacionPolicy`, `PedidoPolicy`,
  - vistas de cotizaciones (detalle, vista previa, ventanas de pago, formulario), ventas (detalle),
    órdenes de trabajo (formulario, detalle, vista previa, impresión, fila), catálogos (formulario y
    listado), artículos (formulario, filas y ficha), `documentos/_mensajes`, `dashboard`, `app.css`
    (`etiqueta-produccion`, `enlaces-exito`, `datos-venta`) y `ficha-articulo.js`,
  - fábricas `ClienteFactory::distribuidor()` y `CatalogoFactory::deProduccion()`,
  - pruebas `CotizacionPagosTest` y `TesoreriaUtilidadTest` (su cliente ahora es distribuidor:
    prueban el flujo sin venta), `DescuentoClienteTest` (la venta nace del pago y no de
    "Aceptar"). En `CotizacionPagosTest`, "rechaza pagos en borrador" pasó a "admite el primer pago
    en borrador".
- **Decisiones al implementar**:
  - **Orden de bloqueo único: cotización → venta.** Lo siguen el pago, la edición de la cotización,
    la entrega, deshacer la entrega y, desde aquí, también `Autofacturador`, que antes bloqueaba la
    venta y después la cotización (021).
  - **Error encontrado y corregido:** `Autofacturador` tomaba la forma de pago del último pago **de
    la venta**. Una venta que cobra en la cotización no tiene pagos propios y la autofactura fallaba.
    Ahora lo toma de la cotización.
  - Los botones "Registrar" de las ventanas de pago de la cotización ganaron `data-enviar-una-vez`.
  - El catálogo conserva lo capturado en la casilla durante el paso de confirmación del recálculo
    (`session()->hasOldInput()`).
- **Verificación**: `php artisan test` con 1223 pruebas en verde, `node --test "tests/js/*.test.js"`
  (50) y Pint sin cambios.

  **No se revisó la UI en un navegador real.** Falta probar en vivo la ventana del primer pago (aviso
  y datos de la venta), el mensaje con enlaces, la casilla del catálogo, la línea "Producción (por su
  catálogo)" del artículo y su ficha, y la edición de una cotización aceptada.
- **Producción**: falta desplegar. Después de desplegar hay que **marcar los catálogos de
  producción**: mientras ninguno esté marcado, solo las líneas libres cuentan como producción.

## Supuestos asumidos (registro completo)

Revisados con el usuario el 2026-10-04, incluidos los supuestos 20 a 24.

1. "Pago = 0" es un pago de $0; el sistema ya lo rechaza y no crea nada.
2. Solo el **primer pago** aplica la regla.
3. La regla aplica **solo a cotizaciones**; la venta de mostrador no cambia.
4. **Se quita "Aceptar"**: la cotización pasa a `aceptada` cuando el primer pago crea su venta.
5. La marca va en el **catálogo** y todos sus artículos la heredan, sin excepción por artículo
   (decidido por el usuario). Por omisión, suministro.
6. *(Eliminado: no hay valor propio por artículo.)*
7. Una **línea libre** cuenta como producción.
8. La venta lleva **todas** las líneas de la cotización.
9. Los **pagos se quedan en la cotización**; la venta los lee de ahí.
10. Los pagos siguientes **se registran en la cotización**.
11. La orden lleva solo las líneas de **producción**.
12. La orden nace en `en_dibujo` **sin colores ni imagen**.
13. Sin venta (distribuidor o solo suministros), se entrega y se factura **desde la cotización** (011).
14. Un distribuidor con artículos de producción **no** genera orden.
15. Con venta, el inventario se descuenta al nacer la venta sin bloquear; sin venta, al entregar la
    cotización.
16. Si se borra el pago que creó la venta, la venta y la orden **se conservan**; se borran a mano.
17. Tipo de cliente y de artículos se evalúan **en el momento del primer pago**.
18. Las ventas creadas con "Aceptar" **no se migran**: conservan sus pagos propios
    (`cobro_en_cotizacion = false`).
19. Una sola factura vigente entre la cotización y su venta (021).
20. Una cotización de puros suministros **no crea nada**, sea quien sea el cliente (decidido por el
    usuario). A un cliente normal la venta solo se le crea con algo de producción, siempre con orden.
21. La venta de cotización **no se edita**: los cambios se hacen **en la cotización**, que sigue
    editable mientras la venta no esté entregada ni facturada, y se copian a la venta (decidido por el
    usuario). El total no baja de lo pagado y el cliente no se cambia (aceptado al autorizar la
    implementación).
22. En una venta de cotización sin orden **sí** se permite crear la orden a mano (decidido por el
    usuario).
23. El primer pago se admite también en `borrador` (decidido por el usuario).
24. El cobro del saldo al entregar se registra **en la cotización** (decidido por el usuario).

**Adiciones técnicas aceptadas:**

1. Aviso en la ventana del pago con lo que va a pasar.
2. Insignia "Producción" en catálogos y la línea "Producción (por su catálogo)" en el artículo.
3. Mensaje después de cobrar con enlaces a la venta y a la orden.
