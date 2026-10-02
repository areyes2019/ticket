# Spec: Pedidos de mostrador (ticket, etiqueta con QR, entrega por escaneo y autofactura)

**Referencia:** reescritura de [remotas/027-venta-mostrador-ticket.md](remotas/027-venta-mostrador-ticket.md),
que se diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan sus reglas de
negocio: documento propio con folio propio, cliente de mostrador sin RFC, líneas de catálogo y
líneas libres, pagos libres contra Tesorería con estados derivados, venta que descuenta existencias
al crearse y se bloquea sin existencia, ticket JPG dibujado en el servidor y nunca guardado, un solo
QR por pedido (en el ticket y en la etiqueta), etiqueta de 50 × 25 mm con saldo, entrega por escaneo
en tres caminos, aviso de "ya está listo" y portal público de autofacturación con enlace de 64
caracteres que vence al terminar el mes. La parte del navegador y el protocolo entre navegador y
servidor se rehicieron para Laravel + Blade + JavaScript nativo.

**Modifica:**

- [012-facturacion.md](012-facturacion.md): `factura_lineas.articulo_id` pasa a nullable (solo la
  autofactura escribe líneas sin artículo; la captura manual lo sigue exigiendo) y `facturas` gana
  `pedido_id`. Una factura nacida de un pedido no se edita desde Facturación.
- [018-inventario.md](018-inventario.md): el Pedido es la **única** venta que se bloquea por
  inventario (018 decía "ninguna venta se bloquea"); nacen los motivos `venta_pedido` y
  `correccion_pedido`; la factura de un pedido no descuenta al timbrarse ni devuelve al cancelarse.
- [016-tesoreria.md](016-tesoreria.md): el pago de pedido es un segundo origen de ingresos
  automáticos, con su utilidad de venta; `utilidadVenta()` se extrae a un trait, como 016 ya
  anticipaba.

Se retiró de la remota lo que pertenecía a la arquitectura anterior o a historias que aquí no
existen:

- API REST `/api/v1`, Sanctum, API Resources, pantallas y componentes Vue (`DocumentoLineas`,
  `ArticuloBuscador`, `MensajePedidoForm`, `RegimenFiscalSelect`, `CodigoPostalCombobox`,
  `UsoCfdiCombobox`), `navegacion.ts`, `lib/compartir.ts`, el guard del router con `?redirect=`
  (aquí lo hace `redirect()->intended()`), `ensureCsrfCookie()` y la salida de los catálogos
  `regimenes-fiscales` y `usos-cfdi` del grupo autenticado (aquí el portal pinta las opciones desde
  los enums y no expone ningún catálogo).
- El **ajuste al peso cerrado** ([remotas/030](remotas/030-total-al-peso-cerrado.md)) y los precios
  sin centavos ([remotas/024](remotas/024-precios-sin-centavos.md)): no existen. Ver "Precio con IVA
  en el ticket".
- El bloqueo de borrado por **Orden de Trabajo** ([remotas/038](remotas/038-produccion-ordenes-trabajo.md))
  y el **escáner de la aplicación instalada** ([remotas/029](remotas/029-pwa-mostrador.md)): no
  existen. El formato del QR se conserva para que 029 lo lea cuando llegue.
- El **descuento de mostrador** ([remotas/036](remotas/036-descuento-venta-mostrador.md)) y el costo
  de goma ([remotas/014](remotas/014-costo-elaboracion-goma.md)).
- La regla "la transacción se revierte y no queda factura" del timbrado fallido: aquí el timbrado
  ocurre **fuera** de la transacción (012, 018). Se reemplaza por "una sola factura pendiente,
  reutilizada en cada reintento" (ver "Portal de autofacturación").
- Las secciones "Estado de implementación" y "Decisiones tomadas durante la implementación" de la
  remota. De ellas se heredan solo las lecciones que aplican: `registrado_al_entregar` en lugar de
  `automatico`, el QR escalado sin interpolación, el ticket que nunca falla por su QR o su logo, el
  pago que se borra sin regla LIFO y la regla de mensajes con `present` + `nullable`.

Dos piezas que la remota daba por existentes **nacen aquí**, en versión mínima (decisiones 1 y 2 del
registro de supuestos, sujetas a corrección):

- Un **almacén de Configuración** clave→valor por usuario, con una sola pantalla y solo las dos
  claves de esta historia.
- Los **datos del negocio** que imprime el ticket (`config/negocio.php`, desde `.env`).

> **Desde [021](021-cotizacion-aceptada-a-venta.md)**: el Pedido se muestra
> como **Venta** (el folio sigue siendo `PED-0042`) y también nace de una cotización aceptada (`cotizacion_id`,
> `cliente_id`). Esa venta no se bloquea por existencia. Tablas, clases y rutas `pedidos` no cambian.

> **Desde [022, corrección 1](022-ordenes-trabajo.md#corrección-1-estado-entregado-y-fin-de-la-entrega-por-escaneo)**
> (pendiente): se elimina la **entrega por escaneo** (pantalla de tres caminos y QR del ticket y la
> etiqueta). La venta se entrega con un botón **"Entregado"**, y una venta con orden de trabajo solo
> se entrega con la orden terminada. `POST pedidos/{pedido}/entregar` y "Deshacer entrega" siguen.

## Historia de usuario

Como usuario registrado, quiero atender al cliente que entra al local y compra directo —sin
cotización de por medio—, cobrarle un anticipo o el total, mandarle por WhatsApp un ticket de compra
estilo punto de venta con un código QR, pegarle al trabajo una etiqueta con ese mismo código para que
al escanearla se cobre lo que falte y se dé por entregado, avisarle cuando su pedido esté listo, y
mandarle un enlace para que él mismo se haga su factura.

El flujo, tal como quedó tras la revisión de la remota:

1. El ticket se genera cuando el cliente hace un primer pago, parcial o total.
2. El ticket lleva el QR y los datos de la venta.
3. **El sello se elabora fuera del sistema.**
4. Se entrega el sello y se escanea el QR para cerrar la compra, con saldo pendiente o sin él.

El sistema **no da seguimiento a la fabricación**: no hay órdenes de trabajo, dibujos, colores de
tinta ni estados de producción.

> **Desde [022](022-ordenes-trabajo.md)**: la venta con al menos un pago admite una orden de trabajo
> con color de tinta por línea, imagen del diseño y estados En dibujo → En proceso → Terminado.

## Objetivo / Alcance

Un módulo nuevo, **Pedidos**, sobre la arquitectura monolítica Laravel + Blade + JavaScript nativo de
[001-inicio-proyecto.md](001-inicio-proyecto.md), con la sesión web de [002-login.md](002-login.md),
los componentes Blade de [003-estilo-uniforme.md](003-estilo-uniforme.md) y las convenciones de
cotizaciones ([011](011-cotizaciones.md)), Tesorería ([016](016-tesoreria.md)) e Inventario
([018](018-inventario.md)).

- Laravel resuelve el módulo completo: rutas web, controladores, Form Requests, Policy, totales,
  ticket JPG, QR, mensajes, entrega, autofactura y correo.
- No se crea API REST, no se usa Sanctum ni API Resources, y no hay stores ni estado en el
  frontend. Lo que decide qué botones se pintan vive en métodos del modelo que Blade consulta.
- Se reutiliza sin cambios de fórmula: `CalculadoraTotalesDocumento` (011),
  `RegistradorMovimientos` (016), `RegistradorInventario` (018), `TimbradorFacturas` y
  `ConstructorPayloadFacturapi` (012), `chillerlan/php-qrcode` y GD.
- **JavaScript solo donde hay interacción real**, todo en archivos de `public/js`:
  - la tabla de líneas, que reutiliza `documento-lineas.js` y `totales-documento.js` tal cual;
  - compartir el ticket, extendiendo `compartir-pdf.js` para aceptar JPEG;
  - la sugerencia por teléfono, que es la **única** llamada AJAX nueva del módulo;
  - el envío automático de la entrega sin saldo y la cuenta regresiva del "Deshacer";
  - `window.print()` en la etiqueta.

  "Avisar que está listo" y "Compartir enlace de autofactura" son enlaces `wa.me` armados en Blade,
  **sin JavaScript**. Los diálogos son `<dialog>` nativos con el manejador de `app.js`.
- Dos rutas **públicas**, fuera de la sesión: el portal de autofacturación.

## Requisitos del entorno

- **GD** con FreeType (`imagettftext`) y soporte JPEG. Ya está presente en Laragon (010, 011).
- **Fuentes**: `DejaVuSansMono.ttf` y `DejaVuSansMono-Bold.ttf` se **copian** de
  `vendor/dompdf/dompdf/lib/fonts/` a `resources/fonts/`, junto con su licencia
  (`resources/fonts/LICENSE-DejaVu.txt`). Leerlas desde `vendor/` ataría el ticket a la estructura
  interna de una dependencia que `composer update` puede reacomodar. Son monoespaciadas porque un
  ticket alinea importes en columna.
- **`APP_URL`** debe ser la URL real con la que se abre el sistema desde el celular. El QR lleva
  una URL absoluta (`route(..., absolute: true)`); con `http://localhost` el celular no llega a nada.
  Se documenta en el `README.md`.
- **`config/negocio.php`** (nuevo), leído siempre con `config()`:

  ```php
  return [
      'nombre' => env('NEGOCIO_NOMBRE', env('APP_NAME')),
      'telefono' => env('NEGOCIO_TELEFONO'),
      'domicilio' => env('NEGOCIO_DOMICILIO'),
      'logo' => env('NEGOCIO_LOGO'), // ruta relativa a storage/app/public, p. ej. "negocio/logo.png"
  ];
  ```

  Cada dato vacío se omite del ticket. Las claves se agregan comentadas a `.env.example`.
- **Correo**: el mismo SMTP síncrono de 011/012. Si falla, la factura queda timbrada igual (ver
  "Portal de autofacturación").

## Backend (Laravel)

### Enums (`app/Enums`)

- `EstadoPedido`: `pendiente` (Pendiente), `anticipo` (Anticipo), `pagado` (Pagado), `entregado`
  (Entregado), con `etiqueta()`, `claseEtiqueta()`, `opciones()` y `esEditable()` (verdadero en
  `pendiente` y `anticipo`).
- `ClaveConfiguracion` (nuevo, ver "Configuración"): `mensaje_ticket`, `mensaje_listo`.
- `MotivoMovimientoInventario` gana `VentaPedido` (`venta_pedido`, "Venta de mostrador") y
  `CorreccionPedido` (`correccion_pedido`, "Corrección de pedido"). Los dos son automáticos: **no**
  entran en `manuales()`.

### Migración `..._create_pedidos_tables.php`

**Tabla `users`**: gana `ultimo_folio_pedido` (`unsignedInteger`, default `0`, después de
`ultimo_folio_orden_compra`).

**Tabla `pedidos`**

| Columna | Definición |
|---|---|
| `id` | |
| `user_id` | `foreignId` → `users` |
| `folio` | `unsignedInteger` |
| `estado` | `string(15)` (`EstadoPedido`), default `pendiente` |
| `cliente_nombre` | `string(150)` |
| `cliente_telefono` | `string(15)`, normalizado a `+52` y 10 dígitos (ver "Validaciones") |
| `cliente_correo` | `string(255)`, nullable |
| `descuento_global_tipo` | `string(10)`, nullable (`TipoDescuento`) |
| `descuento_global_valor` | `decimal(10,2)`, nullable |
| `subtotal`, `total_descuento`, `base_iva_16`, `total_iva_16`, `base_iva_0`, `base_exento`, `total` | `decimal(14,2)` |
| `entregado_en` | `timestamp`, nullable |
| `autofactura_token` | `string(64)`, nullable, **único** |
| `autofactura_error` | `text`, nullable |
| `timestamps` | |

Índices: `(user_id, folio)` único, `(user_id, created_at)`, `(user_id, estado)` y
`(user_id, cliente_telefono)` para la sugerencia por teléfono.

**Tabla `pedido_lineas`**: misma forma que `cotizacion_lineas` (011):

| Columna | Definición |
|---|---|
| `id` | |
| `pedido_id` | `foreignId` → `pedidos`, `cascadeOnDelete` |
| `orden` | `unsignedSmallInteger` |
| `articulo_id` | `foreignId` nullable → `articulos` (sin cascada; los artículos usan soft delete) |
| `cantidad` | `unsignedInteger` |
| `descripcion` | `string(255)` |
| `modelo` | `string(255)`, nullable |
| `precio_unitario` | `decimal(10,2)` |
| `descuento_tipo` | `string(10)`, nullable |
| `descuento_valor` | `decimal(12,2)`, nullable |
| `tasa_iva` | `string(10)` (`TasaIva`) |
| `importe`, `iva_importe` | `decimal(14,2)` |
| `costo_unitario` | `decimal(10,2)`, nullable |
| `timestamps` | |

**Tabla `pedido_pagos`**

| Columna | Definición |
|---|---|
| `id` | |
| `pedido_id` | `foreignId` → `pedidos`, **`restrictOnDelete`** |
| `cuenta_id` | `foreignId` → `cuentas`, **`restrictOnDelete`** |
| `fecha_pago` | `date` |
| `monto` | `decimal(14,2)` |
| `registrado_al_entregar` | `boolean`, default `false` |
| `timestamps` | |

**Tabla `configuraciones`**

| Columna | Definición |
|---|---|
| `id` | |
| `user_id` | `foreignId` → `users`, `cascadeOnDelete` |
| `clave` | `string(50)` (`ClaveConfiguracion`) |
| `valor` | `text`, nullable |
| `timestamps` | |

Índice único `(user_id, clave)`.

**Tabla `facturas`**: gana `pedido_id` (`foreignId` nullable → `pedidos`, `nullOnDelete`, índice,
después de `cotizacion_id`).

**Tabla `factura_lineas`**: `articulo_id` pasa a **nullable** (`->nullable()->change()`), y la llave
foránea se conserva.

`down()` borra las tablas nuevas y quita `ultimo_folio_pedido` y `facturas.pedido_id`.
`factura_lineas.articulo_id` solo vuelve a ser obligatorio si no hay filas con `null`. Si las hay,
`down()` falla con un mensaje claro en lugar de borrar líneas de facturas timbradas.

### Morph map

`AppServiceProvider` agrega `'pedido' => Pedido::class` (documento de los movimientos de inventario)
y `'pedido_pago' => PedidoPago::class` (documento de los movimientos de Tesorería) a
`Relation::enforceMorphMap()`.

### Modelo `Pedido` (tabla `pedidos`)

- `protected $table = 'pedidos'` explícito, la misma lección de 011 y 017.
- Pertenece a un `User` (`user_id` **no** asignable; el alta usa
  `$request->user()->pedidos()->create(...)`). En `User`: `pedidos()`, `configuraciones()`.
- **Asignables** (`#[Fillable]`): `cliente_nombre`, `cliente_telefono`, `cliente_correo`,
  `descuento_global_tipo`, `descuento_global_valor`. **No asignables**: `folio`, `estado`, los
  totales, `entregado_en`, `autofactura_token` y `autofactura_error`. Los escriben el controlador y
  los métodos del modelo.
- **Sin soft delete**: el borrado es físico y se lleva las líneas. Los pagos tienen
  `restrictOnDelete` como red de seguridad.
- Casts: `estado` → `EstadoPedido`, `descuento_global_tipo` → `TipoDescuento`, totales y
  `descuento_global_valor` → `decimal:2`, `entregado_en` → `immutable_datetime`.
- Relaciones: `lineas()` (`hasMany`, por `orden`), `pagos()` (`hasMany`, por `fecha_pago` e `id`),
  `facturas()` (`hasMany` por `facturas.pedido_id`) y `facturaVigente()` (`hasOne`, la factura con
  `estado ≠ cancelada`, igual que `Cotizacion::facturaVigente()` de 015).
- `aplicarTotales(array $totales)`: igual que `Cotizacion`.
- Accessors: `folio_formateado` → `PED-0042` (4 dígitos, como `COT-0012`) y `numero_ticket` →
  `0042`, el "No. de ticket" que imprimen el ticket y la etiqueta.
- **Métodos de regla** (los usan controladores, Policy y Blade; nadie reimplementa la condición):
  - `totalPagado(): string` (usa `pagos_sum_monto` si el listado lo precargó) y
    `saldoPendiente(): string` (`total − totalPagado`, nunca negativo).
  - `tienePagos(): bool`.
  - `esEditable(): bool` → `estado->esEditable()`.
  - `puedeEliminarse(): bool` → `pendiente` **y** sin pagos.
  - `puedeRegistrarPago(): bool` → saldo pendiente > 0, en cualquier estado. En `entregado` sirve
    para recapturar un pago que se borró por error (ver "Pagos").
  - `puedeEliminarPago(): bool` → no tiene factura timbrada (`facturaTimbrada()`): un CFDI PUE ya
    dice que el pedido se pagó.
  - `puedeCompartirTicket(): bool` → `tienePagos()`. Lo mismo vale para "Avisar que está listo"
    (supuesto 60 de la remota).
  - `estaEntregado(): bool`.
  - `puedeDeshacerEntrega(): bool` → `entregado`, ningún pago con `registrado_al_entregar`, y
    `entregado_en` hace menos de `MINUTOS_DESHACER_ENTREGA = 5` minutos.
  - `recalcularEstado(): void` → si no está `entregado`, deriva `pendiente` / `anticipo` / `pagado`
    de la suma de pagos. Si queda `pagado` y no tiene token, genera `autofactura_token`
    (`Str::random(64)`). **Nunca** toca `entregado`; solo `deshacerEntrega()` lo quita.
  - `marcarEntregado(): void` → `estado = entregado`, `entregado_en = now()`.
  - `deshacerEntrega(): void` → `entregado_en = null` y `recalcularEstado()`.
  - `facturaTimbrada(): ?Factura` → la factura del pedido en `timbrada` o `cancelada`, si existe.
  - `autofacturaVenceEl(): CarbonImmutable` → último instante del mes de `created_at`, en la zona
    del negocio (`config('app.zona_negocio')`): `endOfMonth()` a las 23:59:59.
  - `motivoAutofacturaNoDisponible(): ?string` → `null` si el enlace sirve. Si no, el motivo en
    español, revisado en este orden: "Este pedido ya se facturó." (`facturaTimbrada()`), "El enlace
    para facturar venció el {fecha}. Comunícate con el negocio." (pasó `autofacturaVenceEl()`), y
    "Este pedido todavía no está pagado por completo." (saldo > 0 o sin token).
  - `urlAutofactura(): ?string` → `route('autofactura.show', $token)` si hay token.
- Scope `filtrar(array $filtros)` para el listado (ver "Validaciones").
- Usa el trait `CalculaUtilidadVenta` (ver "Integración con Tesorería").

### Folio

Mismo mecanismo que cotización (011) y orden de compra (017): numeración propia por usuario,
independiente de cotizaciones, facturas y órdenes de compra, que **nunca se reutiliza**. Al crear,
dentro de la transacción, `User::whereKey(...)->lockForUpdate()->first()`. El folio nuevo es
`max(ultimo_folio_pedido, folio máximo del usuario) + 1` y se guarda como contador. El índice único
`(user_id, folio)` es la última red. **El folio es el "No. de ticket".**

### Modelo `PedidoLinea` (tabla `pedido_lineas`)

Mismas reglas que `CotizacionLinea` (011):

- **`articulo_id` nulo es la línea libre**: descripción, cantidad y precio escritos a mano, sin
  producto en el catálogo. No mueve inventario (`RegistradorInventario` ya descarta las líneas sin
  artículo), no se da de alta en el catálogo, sí se puede facturar (ver "Portal de
  autofacturación") y no tiene costo.
- `descripcion`, `modelo` y `precio_unitario` son copias desacopladas del artículo y se pueden
  editar. El precio se precarga con `precio_unitario_sin_iva`. La tasa de IVA se precarga como en
  011.
- `costo_unitario`: copia del `costo_con_descuento` del artículo al guardar la línea, nunca aceptada
  del formulario, y `null` en una línea libre. Se lee con una sola consulta, como en
  `CotizacionController`. Cada edición borra y recrea las líneas, así que toma el costo vigente en
  ese momento. Nunca se muestra en el pedido ni en el ticket.

### Modelo `PedidoPago` (tabla `pedido_pagos`)

- Asignables: `cuenta_id`, `fecha_pago`, `monto`. `registrado_al_entregar` lo escribe solo
  `PedidoEntregaController`.
- Casts: `fecha_pago` → `date`, `monto` → `decimal:2`, `registrado_al_entregar` → `boolean`.
- `$touches = ['pedido']`.
- Relaciones: `pedido()`, `cuenta()`, `movimiento()` (`morphOne` `documentable`).
- `conceptoMovimiento(): string` → `"Pago de Pedido PED-0042"` o, con `registrado_al_entregar`,
  `"Saldo al entregar de Pedido PED-0042"`. Este concepto no se edita.

**No hay `TipoPago`**, a diferencia de la cotización: aquí no hay "un anticipo y luego el saldo".
Cada pago lleva el monto que el usuario recibió, sin porcentaje mínimo, y puede haber varios.

### Totales

`CalculadoraTotalesDocumento::calcular()` tal cual. El servidor recalcula en cada alta y edición, y
ningún total, importe, folio, estado, costo o dato de entrega se acepta del formulario: lo que llegue
se ignora, como en 011. El resumen del navegador es informativo. **No** aplica el descuento
permanente de cliente, porque aquí no hay `Cliente`.

> La remota pedía `422` si el total enviado no coincidía con el recalculado. Aquí, igual que en 011 y
> 017, el formulario no manda totales y el servidor siempre manda: no hay nada que comparar.

### Inventario (018)

**Al crear** el pedido —no al entregarlo— se descuentan existencias. **El pedido es la única venta
que se bloquea por inventario** (cambio sobre 018):

1. Dentro de la transacción del alta, por cada línea con `articulo_id` se exige una fila **viva** en
   `existencias` con `existencia > 0`. Si alguna falla, se lanza un error de validación en esa
   línea (`lineas.{i}.articulo_id`): "{modelo} no tiene existencia en bodega." o "{modelo} no está
   en existencias." Así no se crea nada.
2. Vender por arriba de lo disponible **no** bloquea: con existencia 3 y una venta de 5, se
   descuentan las 3 y las otras 2 quedan como faltante pendiente, igual que factura y cotización.
3. `RegistradorInventario::salidaPorDocumento($pedido, $pedido->lineas, VentaPedido, creaFila: false)`.

Las líneas libres nunca bloquean ni mueven nada.

**Al editar** un pedido editable, dentro de la transacción y en este orden:

1. `RegistradorInventario::revertirDocumento($pedido, CorreccionPedido)` (nuevo, ver abajo)
   devuelve lo que el pedido sacó.
2. Se reemplazan las líneas.
3. Se repite la validación del paso 1 del alta. Como ya se devolvieron las piezas del propio pedido,
   un pedido que se llevó las últimas 2 piezas puede editarse sin que su propio descuento lo
   bloquee.
4. Se aplica la salida nueva con `VentaPedido`.

Si el paso 3 falla, la transacción se deshace entera.

**Al borrar** (solo `pendiente` y sin pagos): `revertirDocumento($pedido, CorreccionPedido)` antes
del `delete()`.

**`RegistradorInventario::revertirDocumento(Model $documento, MotivoMovimientoInventario $motivo): void`**
(nuevo, genérico). Calcula por artículo el **neto que salió** con los movimientos de ese documento
(salidas menos entradas) y aplica una entrada por ese neto con `entradaPorDocumento`. Igual que
`devolverFactura()`, parte de los movimientos y no de las líneas: no devuelve lo que no salió (un
artículo sin fila no generó movimiento) y no da de alta artículos. Un pedido editado tres veces no
descuenta tres veces.

**La factura nacida de un pedido no mueve inventario**, porque el pedido ya descontó. Nace
`Factura::mueveInventario(): bool` → `cotizacion_id === null && pedido_id === null`, que reemplaza
la condición `cotizacion_id === null` en **los dos** lugares donde hoy vive:

- `TimbradorFacturas::timbrar()`: no hace la salida `VentaFactura`.
- `RegistradorInventario::devolverFactura()`: no hace la entrada `CancelacionFactura`. Sin este
  segundo cambio, cancelar una autofactura devolvería a la bodega mercancía que el pedido ya
  entregó.

### Integración con Tesorería (016)

**Registrar un pago** (`PedidoPagoController::store`, bolsa `pago`), en una transacción:

1. Bloquea el pedido con `lockForUpdate()` y vuelve a comprobar `puedeRegistrarPago()` y que
   `monto ≤ saldoPendiente()` con la fila bloqueada.
2. Crea el `PedidoPago`.
3. Llama a `RegistradorMovimientos::registrar($cuenta, Ingreso, $monto, $fecha_pago, $pago->conceptoMovimiento(), $pago)`.
   El orden de bloqueo es **pedido → cuenta**, el mismo de cotización y orden de compra.
4. `recalcularEstado()`. Si quedó `pagado`, nace el token de autofactura.

`OperacionTesoreriaRechazada` (cuenta inactiva) deshace la transacción y vuelve como error del
campo `cuenta_id` en la bolsa `pago`.

**Eliminar un pago** (`PedidoPagoController::destroy`): cualquier pago, sin la regla LIFO de 011,
porque aquí cada pago lleva su propio monto y ninguno depende de otro. Solo si
`puedeEliminarPago()`. En una transacción: bloquea el pedido,
`RegistradorMovimientos::eliminarDeDocumento($pago)`, borra el pago y `recalcularEstado()`. Si salta
`SaldoNegativoException`, no se borra nada y se muestra el aviso: "No se puede eliminar el pago: la
cuenta {nombre} quedaría con saldo negativo." Es también la vía para corregir un cobro mal capturado
al entregar: se borra y se recaptura con "Agregar pago" (en `entregado` el estado no cambia).

Si un pago borrado deja un pedido `pagado` por debajo del total, regresa a `anticipo` o
`pendiente`. El token se conserva, pero el portal deja de servir porque el saldo ya no es cero.

**Utilidad de venta.** `Cotizacion::utilidadVenta()` se mueve al trait
`App\Models\Concerns\CalculaUtilidadVenta`, que opera sobre `$this->lineas` y lo usan `Cotizacion` y
`Pedido`. El comportamiento de la cotización no cambia: `TesoreriaUtilidadTest` es la red que lo
comprueba. `Movimiento::documentoOrigen()` agrega el caso `PedidoPago`:

```php
[
    'etiqueta' => 'PED-0042',
    'url' => route('pedidos.show', $pedido),
    'utilidad' => ..., 'utilidad_parcial' => ...,   // del trait
    'muestra_utilidad' => true,
]
```

`MovimientoController::index` agrega `PedidoPago::class => ['pedido.lineas']` al `morphWith`. El
ingreso aparece como **automático** (no se edita ni se elimina desde Tesorería) con "Se corrige
desde PED-0042". Un pedido con alguna línea libre muestra su utilidad marcada como **parcial**.

### Configuración (`ClaveConfiguracion`, modelo `Configuracion`) — nace aquí

Almacén clave→valor **por usuario**, con lo mínimo para los dos mensajes de esta historia. Las
claves futuras se agregan al enum.

- `ClaveConfiguracion`: `etiqueta()`, `ayuda()`, `reglas()` →
  `['present', 'nullable', 'string', 'max:2000']` en las dos (`present` + `nullable` porque Laravel
  convierte la cadena vacía en `null` antes de validar, lección de la remota) y `valorPorDefecto()`.
- `Configuracion` (`$table = 'configuraciones'`), asignables `clave` y `valor`, cast `clave` →
  `ClaveConfiguracion`. Método estático `Configuracion::valor(User $user, ClaveConfiguracion $clave): ?string`:
  - si **no hay fila**, devuelve `valorPorDefecto()`;
  - si la fila existe con `valor = null`, devuelve `null`. El usuario vació el mensaje a propósito
    para mandar solo la imagen.
- `ConfiguracionController`: `edit` (GET `/configuracion`) muestra los dos `textarea` y `update`
  (PUT) los guarda con `updateOrCreate` por `(user_id, clave)`. Usa `ConfiguracionRequest`, cuyas
  reglas salen de `ClaveConfiguracion::reglas()`.

`MensajeTicket`, valor por defecto (el texto que dictó el usuario):

```
¿Qué sigue ahora?
😊 Te explico los siguientes pasos:

1️⃣ Primero trabajaremos en el diseño de tu sello. ✍️ Te enviaremos una propuesta para que la
revises y nos confirmes si estás de acuerdo. 👀

2️⃣ Una vez aprobado, comenzamos la producción. El tiempo estimado de entrega es de 24 a 48 horas
hábiles. ⏳

Por ejemplo, si apruebas el diseño un viernes, tu sello estará listo para el lunes. 📅

Cuando esté terminado, podrás elegir cómo recibirlo:
📍 Recogerlo personalmente o
🚚 Recibirlo por paquetería, directo a tu domicilio.

¿Te parece bien? Quedo atento(a) a tu confirmación. 😉
```

`MensajeListo`, valor por defecto:

```
Hola {nombre} 👋
Tu pedido con No. de ticket {folio} ya está listo. 🎉
Puedes pasar por él cuando gustes. ¡Gracias por tu preferencia!
```

### Mensajes del pedido (`App\Services\Pedidos\MensajePedido`)

`resolver(Pedido $pedido, ClaveConfiguracion $clave): ?string`:

- Toma `Configuracion::valor(...)`. Con `null` devuelve `null`.
- Reemplaza con `strtr` los huecos `{nombre}` (`cliente_nombre`), `{folio}` (`numero_ticket`),
  `{total}`, `{pagado}` y `{saldo}` (montos con formato `$1,148.40`).
- **Un hueco que no exista se deja tal cual**: el texto es de captura libre, y un `{}` mal escrito no
  debe romper el envío.
- `MensajePedido::HUECOS` (constante con hueco → descripción) es la única lista. La usan el servicio
  y la pantalla de Configuración, que la muestra con un ejemplo resuelto.

Blade recibe los mensajes ya resueltos. El navegador no conoce la lista de huecos.

### Código QR del pedido (`App\Services\Pedidos\CodigoQrPedido`)

- Contenido: la **URL absoluta** `route('pedidos.entregar', $pedido)` →
  `{APP_URL}/pedidos/{id}/entregar`. La cámara del celular la abre sola, y el escáner de
  [remotas/029](remotas/029-pwa-mostrador.md), cuando llegue, actúa sobre el `id` de la ruta sin
  reimprimir etiquetas. **Un solo código por pedido**, idéntico en el ticket y en la etiqueta.
- `png(Pedido $pedido): string` → bytes PNG (`QRGdImagePNG`, `outputBase64 => false`, ECC `M`,
  margen de 4 módulos).
- `dataUri(Pedido $pedido): string` → para la etiqueta.
- El QR fiscal de `GeneradorPdfFactura` no se toca: es otro contenido con otro uso.

Que el cliente se lleve el código en su ticket no le da acceso a nada, porque el destino exige
sesión.

### El ticket JPG (`App\Services\Pedidos\GeneradorTicketPedido`)

**Lo dibuja el servidor**, con GD (`imagecreatetruecolor`, `imagettftext`, `imagejpeg`), para que
salga idéntico desde la computadora del mostrador y desde el celular, incluido su QR.

`generar(Pedido $pedido): string` devuelve los bytes JPEG (calidad 85):

- **Ancho fijo 576 px**, el ancho útil de una térmica de 80 mm a 203 dpi. El alto se calcula a
  partir de las líneas: primero se arma la lista de renglones y después se crea el lienzo.
- Fuentes de `resources/fonts/`.
- **Contenido**, en este orden:
  1. Logo de `config('negocio.logo')`, centrado y reducido a 240 px de ancho máximo con
     `imagecopyresampled`. Se omite si no está configurado, si no existe o si GD no lo lee.
  2. Nombre, teléfono y domicilio del negocio. Cada dato vacío se omite.
  3. `TICKET No. 0042` y la fecha y hora de creación en la zona del negocio.
  4. Nombre del cliente.
  5. Las líneas: cantidad × **precio unitario con IVA** en un renglón y la descripción (recortada a
     lo que cabe) con el importe con IVA de la línea (`importe + iva_importe`) alineado a la
     derecha. Si la línea tiene descuento, va un renglón "Desc." debajo.
  6. Subtotal, descuento (si lo hay), IVA y **Total**.
  7. **Pagado** y **Saldo pendiente**.
  8. **El QR**, centrado, de **300 × 300 px** sobre fondo blanco, con `No. 0042` impreso debajo.
- **El QR va al pie y va grande**: es donde el ojo termina de leer y donde no compite con nada, y su
  peor escenario es leerse desde la pantalla de un celular. **No se reescala**: se genera una vez
  a 1 pixel por módulo para contar los módulos, y otra a la escala entera que cabe en 300 px; se
  centra en el cuadro blanco con `imagecopy`. Un código con los bordes suavizados es un código que
  el lector rechaza.
- **El ticket nunca falla por su QR ni por su logo**. Si algo de eso falla, se dibuja sin esa pieza
  y con el número de ticket, que es con lo que se busca el pedido a mano.
- **No se guarda en ningún lado.** Se dibuja cada vez que se pide y se desecha. No hay columna ni
  carpeta ni nada que limpiar, y **es imposible que muestre un saldo viejo**. Compartirlo dos veces
  lo dibuja dos veces, en milésimas de segundo.

**Precio con IVA en el ticket.** La remota garantizaba con el peso cerrado (030) que
`3 x $204.00` cuadrara exactamente contra un total de $612.00. Aquí 030 no existe: el precio
unitario con IVA impreso (`precio_unitario × (1 + tasa)`, redondeado al centavo) es una referencia
para el cliente, y lo que se suma es el importe con IVA de cada línea, que sale de la misma
calculadora que el total. **El total del ticket es siempre exactamente el total del pedido y el de
su factura.** Cuando llegue 030, el ajuste al peso aparecerá como un renglón más del desglose.

Se sirve tal cual en JPEG, el formato que WhatsApp trata como foto.

### La etiqueta (`pedidos/etiqueta.blade.php`)

Documento aparte del ticket, para una etiqueta adhesiva de **50 × 25 mm**. Se imprime desde el
navegador y no genera archivo. Es una vista Blade **sin el layout de la aplicación**, con
`@page { size: 50mm 25mm; margin: 0 }` y estilos de impresión propios dentro de la vista, que llama
a `window.print()` al cargarse.

Contenido, y nada más:

```
|<---------- 50 mm ---------->|
+-----------------------------+ ---
| +---------+  Juan Pérez     |  ^
| |         |  55 1234 5678   |  |
| |   QR    |  No. 0042       | 25 mm
| |  20mm   |  SALDO: $250.00 |  |
| +---------+                 |  v
+-----------------------------+ ---
  |<-20mm->| |<---- 28mm ---->|
```

- El QR (`CodigoQrPedido::dataUri`) va a la izquierda en un cuadro de 20 mm.
- A la derecha van nombre, teléfono, `No. 0042` y, en negritas en el último renglón,
  `SALDO: $250.00` o `PAGADO`. Ver la caja dice si hay que cobrar sin abrir el sistema.
- El nombre se recorta con puntos suspensivos (`text-overflow: ellipsis`, `white-space: nowrap`)
  antes de partirse. Un renglón partido empuja el saldo fuera de la etiqueta.
- Sin artículos ni desglose: la etiqueta identifica el trabajo en el estante, no lo detalla.

### La entrega por escaneo

Se escanea con la **app de cámara del celular**, que abre la URL del QR. Esta spec no construye un
lector.

`GET /pedidos/{pedido}/entregar` **requiere sesión**. Sin ella, el middleware `auth` guarda la URL
de destino y manda al login, y `redirect()->intended()` (ya en `AuthenticatedSessionController`)
regresa al pedido. Es una acción que mueve dinero: nadie que encuentre la etiqueta tirada o reciba
el ticket reenviado debe poder cerrarla.

**La vista se resuelve en el servidor según el estado**, en tres caminos, sin consultar nada por
AJAX:

**Camino 1 — Ya entregado.** No ofrece ninguna acción. Muestra de quién era, el número de ticket y
**cuándo se entregó**. Es el candado contra el doble escaneo y de paso sirve como consulta. La única
excepción es que la entrega se acabe de hacer en esta sesión (flash `entrega_sin_cobro`) y
`puedeDeshacerEntrega()` siga siendo verdadero: entonces muestra el botón **"Deshacer"** (ver
camino 2).

**Camino 2 — Saldo en cero: se cierra solo.** La vista pinta un formulario `POST` a
`pedidos.entregar` **sin campos de dinero**, con `data-enviar-al-cargar`. `app.js` lo envía en
cuanto carga la página. Sin JavaScript se ve el botón "Entregar" del mismo formulario. Preguntar
aquí sería pedir permiso para lo único que se puede hacer. El `POST` redirige de vuelta a
`GET .../entregar` con el flash `entrega_sin_cobro`, así que la recarga cae en el camino 1 y no
reenvía nada.

- **"Deshacer"** es un formulario `POST` a `pedidos.deshacer-entrega`, con una cuenta regresiva de
  **10 segundos** (`data-cuenta-regresiva="10"`): al llegar a 0, el script oculta el formulario.
  Existe **solo en este camino**, porque es el único donde el sistema actúa sin preguntar:
  escanear la etiqueta equivocada daría por entregado el pedido de otro cliente.
- La ventana del servidor es más ancha a propósito (**5 minutos**, `puedeDeshacerEntrega()`). El
  botón mide la impaciencia del usuario, y el límite del servidor evita que un "Deshacer" desde una
  pestaña olvidada revierta mañana una entrega legítima.

**Camino 3 — Saldo pendiente: pide confirmación.** No toca nada hasta que el usuario confirma. En
grande:

- Nombre del cliente y número de ticket.
- **Total**, **Pagado** y **Saldo**.
- El `select` de cuentas **activas**, con una primera opción vacía "Elige la cuenta…" y
  **sin preselección**, marcado `required`.
- Un solo botón: **"Cobrar y entregar $250.00"**, con `data-enviar-una-vez`. Con JavaScript
  (`data-habilitar-con`) queda deshabilitado hasta elegir cuenta. Sin él, `required` impide enviar
  vacío.
- Si el usuario no tiene cuentas activas, en lugar del formulario aparece el aviso "Da de alta una
  cuenta en Contabilidad para poder cobrar." con enlace a `tesoreria.cuentas.index`.

Ver a quién se le cobra **antes** de cobrarle atrapa la etiqueta equivocada mientras corregirlo
todavía es gratis. **Un botón y no dos**: cobrar y entregar son la misma decisión con el cliente
enfrente. **Se cobra el saldo completo y el monto no se edita**: quien quiera cobrar de menos
registra un abono desde el detalle, y la entrega cierra. Al confirmar, la redirección cae en el
camino 1 con el flash "Cobro de $250.00 registrado en {cuenta}. Pedido entregado.", **sin
"Deshacer"**.

La vista está pensada para el pulgar y para leerse de lejos: es la única pantalla que se usa de pie,
con el cliente enfrente y la caja en la otra mano. Usa el layout de la aplicación con la clase de
contenido `contenido-entrega` (tipografía grande, botones a todo lo ancho).

**`POST /pedidos/{pedido}/entregar`** (`PedidoEntregaController::store`, `EntregarPedidoRequest`),
en una transacción:

1. Bloquea la fila del pedido con `lockForUpdate()`.
2. Si ya está `entregado`, **no toca nada** y redirige al camino 1 con "Este pedido ya se entregó el
   {fecha}." Esto lo hace idempotente: dos escaneos o dos clics no cobran dos veces.
3. Si el saldo (recalculado con la fila bloqueada) es mayor a 0: exige `cuenta_id`. Si falta, se
   lanza un error de validación y no se toca nada. Crea un `PedidoPago` por el **saldo exacto**,
   con `fecha_pago` = hoy, `registrado_al_entregar = true`, y su ingreso con
   `RegistradorMovimientos::registrar()` (bloqueo pedido → cuenta).
4. `marcarEntregado()`.

El monto **no viaja en la petición**: lo calcula el servidor, para que un formulario manipulado no
pueda cerrar un pedido cobrando de menos.

**`POST /pedidos/{pedido}/deshacer-entrega`** (`PedidoEntregaController::destroy`): en una
transacción con el pedido bloqueado, exige `puedeDeshacerEntrega()`. Si no se cumple, redirige con
el motivo: "Esta entrega registró un cobro: corrígelo desde el detalle del pedido." o "Ya pasaron
más de 5 minutos: la entrega no se puede deshacer." Si se cumple, `deshacerEntrega()`. **No toca
Tesorería en absoluto**: solo se deshacen las entregas que no cobraron, así que nunca hay un
movimiento que revertir.

`registrado_al_entregar` nombra el movimiento en Tesorería y es lo que le permite a
`deshacer-entrega` saber que esa entrega ya pasó por una confirmación.

### Avisar que está listo

El sistema no sabe cuándo el sello está listo, así que no avisa solo. Lo que hace es redactar el
aviso: el detalle ofrece **"Avisar que está listo"** cuando `puedeCompartirTicket()`.

Es un enlace `<a href="https://wa.me/?text={mensaje}" target="_blank" rel="noopener">` armado en
Blade con `MensajePedido::resolver($pedido, MensajeListo)` codificado con `rawurlencode`. En Windows
el sistema lo abre en WhatsApp Desktop, si no hay WhatsApp lo abre WhatsApp Web, y en el celular lo
abre la app. El usuario elige el contacto: **el teléfono del pedido no se usa para enviar nada**.
**No cambia ningún estado** y se puede usar las veces que haga falta. Si el mensaje está vacío en
Configuración, el botón no se muestra.

### Portal de autofacturación

Funcionalidad nueva: el sistema no tenía nada público.

**El enlace.** `GET /autofactura/{token}` con el `autofactura_token` del pedido. El token nace
(`Str::random(64)`) la primera vez que el pedido llega a `pagado`. No es el `id` porque el enlace es
público, sin contraseña: con `/autofactura/1043` cualquiera podría probar números. Con 64
caracteres al azar no hay nada que adivinar.

**Cuándo sirve** (`motivoAutofacturaNoDisponible()`): con saldo cero, sin factura timbrada (ni
cancelada: **un pedido, una factura**; refacturar queda fuera de alcance) y hasta el **último día
del mes de la venta a las 23:59:59** en la zona del negocio. Un CFDI se emite en el periodo de la
operación, y es mejor un aviso claro que un timbrado rechazado. Un token inexistente responde 404
con la misma página pública y el mensaje "Este enlace no es válido."

**Rutas públicas**, fuera del grupo `auth`, con el limitador nombrado `autofactura`
(`RateLimiter::for('autofactura', fn ($r) => Limit::perMinute(20)->by($r->ip()))` en
`AppServiceProvider`). Son las únicas rutas que cualquiera en internet puede llamar.

**La página** (`autofactura/show.blade.php`) usa `layouts/publico.blade.php` (nuevo: marca del
negocio, sin menú, sin "Iniciar sesión" ni "Crear cuenta"). La ve un cliente, no el usuario.

- Encabezado con el nombre del negocio, `Pedido No. 0042`, fecha y **total**, para que el cliente
  sepa qué está facturando.
- Si hay motivo de no disponible, solo el motivo.
- Si no, un formulario normal (`@csrf`, `POST` a `autofactura.store`):
  - RFC, razón social, régimen fiscal (`select` desde `RegimenFiscal::opciones()`), código postal
    fiscal (`input` de 5 dígitos), uso de CFDI (`select` desde `UsoCfdi`) y correo, precargado con
    `cliente_correo` si lo hay.
  - Si el pedido tiene `autofactura_error`, arriba aparece el aviso en español del intento anterior.
- Al timbrar, la misma página muestra el **acuse**: "Tu factura {serie y folio fiscal} quedó
  timbrada y la enviamos a {correo}." Con `?descargar=pdf` y `?descargar=xml` sobre la misma URL se
  bajan los archivos mientras la factura sea del pedido de ese token.

**`POST /autofactura/{token}`** (`AutofacturaController::store`, `AutofacturaRequest`), con el
servicio `App\Services\Pedidos\Autofacturador::facturar(Pedido $pedido, array $datos): ResultadoTimbrado`:

1. **Transacción** con el pedido bloqueado (`lockForUpdate()`). Vuelve a comprobar
   `motivoAutofacturaNoDisponible()`. Si ya no sirve, regresa con el motivo.
2. **Cliente fiscal**: busca un `Cliente` del usuario dueño del pedido con ese RFC, sin eliminados.
   Si existe, **actualiza** su razón social, régimen, código postal y correo con lo capturado (el
   cliente es dueño de sus datos fiscales, y sin esto no podría corregir un código postal que el SAT
   rechazó). Si no existe, lo crea con `nombre_contacto` = `cliente_nombre` y `telefono` =
   `cliente_telefono` del pedido. Aquí sí entra al catálogo: ya trae RFC y régimen.
3. **Factura**: si el pedido ya tiene una factura vigente en `borrador` o `pendiente` (un intento
   anterior que falló), **la reutiliza**: le cambia `cliente_id` y `uso_cfdi`. Si no, la crea:
   - folio con el mismo mecanismo de `FacturaController` (se extrae a
     `Factura::siguienteFolio(User)` para que lo usen los dos);
   - `pedido_id`, `uso_cfdi`, `metodo_pago = PUE` siempre (el pedido está totalmente pagado antes de
     que exista el enlace);
   - `forma_pago` derivada de `TipoCuenta` de la cuenta del **último** pago: `efectivo` → `01`,
     `banco` → `03`, `digital` → `03`, `otro` → `99` (`TipoCuenta::formaPagoSat()`, nuevo). El
     cliente no tiene por qué saber a qué cuenta entró su dinero;
   - líneas copiadas del pedido con los **mismos importes**, descuentos y totales (no se recalculan:
     la factura dice exactamente lo que se cobró). Claves SAT: las del artículo; en una **línea
     libre**, `clave_prod_serv = 01010101` ("No existe en el catálogo"), `clave_unidad = H87`
     (Pieza) y `objeto_imp = 02`. `ConstructorPayloadFacturapi` ya lee las claves de la línea, así
     que **no cambia**.

   `pedido_id` queda escrito **dentro de la transacción, antes de timbrar**, para que la factura
   nunca se vea como venta directa y descuente existencias que el pedido ya descontó.
4. **Timbrado** fuera de la transacción, con `TimbradorFacturas::timbrar($factura)`, bajo su
   `Cache::lock`, como en 012.
5. Según el resultado:
   - `Timbrada`: limpia `autofactura_error` y envía la factura por correo al correo capturado con
     `App\Services\Facturacion\EnviadorCorreoFactura` (se extrae de `EnvioFacturaController::correo`,
     que pasa a usarlo). Si el correo falla, la factura queda timbrada igual: el acuse lo dice ("No
     pudimos enviarla por correo; descárgala aquí") y el error queda en el log.
   - `ErrorDatos`: guarda el motivo en `autofactura_error` y regresa al formulario con los datos
     capturados y el motivo, para corregir y reintentar ahí mismo. facturapi.io responde en
     español; el mensaje se muestra con el prefijo "No se pudo timbrar tu factura:".
   - `ErrorPac`: guarda el motivo técnico en `autofactura_error`, lo registra en el log y al cliente
     le muestra un mensaje genérico seguro: "El servicio de facturación no responde. Intenta de
     nuevo en unos minutos."
   - `EnCurso`: "Tu factura se está procesando. Recarga la página en un momento."

**Un intento fallido no consume el enlace ni deja facturas sueltas**: queda **una** factura
`pendiente` ligada al pedido, que se reutiliza en cada reintento y también se puede reintentar o
eliminar desde `/facturas/{id}` con el flujo de 012. El usuario se entera porque el listado de
`/pedidos` marca la fila (ver "Vistas"): ese cliente ya se fue y no va a insistir.

**Cambios en Facturación (012/015)** que esto exige:

- `Factura::pedido()` (`belongsTo`) y `Factura::mueveInventario()` (ver "Inventario").
- `Factura::esEditable()` devuelve `false` si `pedido_id` no es nulo: sus líneas pueden ser libres y
  `FacturaRequest` las rechazaría. La corrección de datos se hace desde el portal o reintentando.
- "Duplicar" (015) no cambia: ya omite las líneas sin artículo vigente, así que la copia de una
  autofactura sale solo con sus líneas de catálogo.
- El detalle de la factura muestra "Pedido PED-0042" con enlace, igual que muestra la cotización de
  origen.

### Rutas (web)

Dentro del grupo `['auth', AsegurarUsuarioActivo::class]`, **antes** del `Route::resource`:

```php
Route::get('pedidos/buscar', [PedidoController::class, 'buscar'])->name('pedidos.buscar');
Route::get('pedidos/cliente-por-telefono', [PedidoController::class, 'clientePorTelefono'])->name('pedidos.cliente-por-telefono');
Route::get('pedidos/{pedido}/ticket', [PedidoController::class, 'ticket'])->name('pedidos.ticket');
Route::get('pedidos/{pedido}/etiqueta', [PedidoController::class, 'etiqueta'])->name('pedidos.etiqueta');
Route::get('pedidos/{pedido}/entregar', [PedidoEntregaController::class, 'show'])->name('pedidos.entregar');
Route::post('pedidos/{pedido}/entregar', [PedidoEntregaController::class, 'store'])->name('pedidos.entregar.store');
Route::post('pedidos/{pedido}/deshacer-entrega', [PedidoEntregaController::class, 'destroy'])->name('pedidos.deshacer-entrega');
Route::post('pedidos/{pedido}/pagos', [PedidoPagoController::class, 'store'])->name('pedidos.pagos.store');
Route::delete('pedidos/{pedido}/pagos/{pago}', [PedidoPagoController::class, 'destroy'])
    ->scopeBindings()->name('pedidos.pagos.destroy');

Route::resource('pedidos', PedidoController::class)
    ->parameters(['pedidos' => 'pedido']);

Route::get('configuracion', [ConfiguracionController::class, 'edit'])->name('configuracion.edit');
Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
```

Fuera de todo grupo de sesión:

```php
Route::middleware('throttle:autofactura')->group(function () {
    Route::get('autofactura/{token}', [AutofacturaController::class, 'show'])->name('autofactura.show');
    Route::post('autofactura/{token}', [AutofacturaController::class, 'store'])->name('autofactura.store');
});
```

`{token}` lleva `->where('token', '[A-Za-z0-9]{64}')`: cualquier otra cosa es 404 sin tocar la base
de datos.

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/pedidos` | listado, filtros y página en la URL | `pedidos.index` |
| GET | `/pedidos/buscar` | fragmento HTML para la búsqueda dinámica | `pedidos.buscar` |
| GET | `/pedidos/cliente-por-telefono?telefono=` | datos de la venta anterior con ese teléfono (JSON) | `pedidos.cliente-por-telefono` |
| GET | `/pedidos/crear` | formulario de alta | `pedidos.create` |
| POST | `/pedidos` | alta | `pedidos.store` |
| GET | `/pedidos/{pedido}` | detalle con ticket, pagos y acciones | `pedidos.show` |
| GET | `/pedidos/{pedido}/editar` | formulario de edición | `pedidos.edit` |
| PUT | `/pedidos/{pedido}` | edición | `pedidos.update` |
| DELETE | `/pedidos/{pedido}` | borrado físico | `pedidos.destroy` |
| GET | `/pedidos/{pedido}/ticket` | JPG al vuelo (`?descargar=1` fuerza descarga) | `pedidos.ticket` |
| GET | `/pedidos/{pedido}/etiqueta` | vista de impresión 50 × 25 mm | `pedidos.etiqueta` |
| GET | `/pedidos/{pedido}/entregar` | destino del QR, tres caminos | `pedidos.entregar` |
| POST | `/pedidos/{pedido}/entregar` | cierra el pedido (cobra el saldo si lo hay) | `pedidos.entregar.store` |
| POST | `/pedidos/{pedido}/deshacer-entrega` | revierte una entrega sin cobro (≤ 5 min) | `pedidos.deshacer-entrega` |
| POST | `/pedidos/{pedido}/pagos` | registra un pago | `pedidos.pagos.store` |
| DELETE | `/pedidos/{pedido}/pagos/{pago}` | elimina un pago y su ingreso | `pedidos.pagos.destroy` |
| GET | `/configuracion` | mensajes del ticket y de pedido listo | `configuracion.edit` |
| PUT | `/configuracion` | guarda los mensajes | `configuracion.update` |
| GET | `/autofactura/{token}` | **pública**: datos del pedido, formulario o acuse | `autofactura.show` |
| POST | `/autofactura/{token}` | **pública**: crea cliente y factura y timbra | `autofactura.store` |

### Controladores

- **`PedidoController`**
  - `index`: pedidos del usuario con `withSum('pagos', 'monto')`, filtrados con
    `ListadoPedidosRequest`, orden `created_at desc, id desc`, 25 por página con
    `withQueryString()`. Sin fechas en la URL se aplica "Este mes", como en 011.
  - `buscar`: la misma consulta; devuelve `pedidos/_resultados` con
    `->withPath(route('pedidos.index'))`.
  - `clientePorTelefono`: normaliza `telefono` a dígitos y busca el pedido **más reciente** del
    usuario con ese teléfono. Responde `{ nombre, correo }` o `{}`. Con menos de 7 dígitos responde
    `{}` sin consultar.
  - `create` / `store`: en una transacción asigna folio, crea el pedido en `pendiente`, guarda
    líneas (con `costo_unitario`) y totales, valida existencias y aplica la salida (ver
    "Inventario"). Redirige al detalle: "Pedido PED-0042 creado. Registra el pago para compartir el
    ticket."
  - `show`: con `lineas`, `pagos.cuenta` y `facturaVigente`. Pasa `$cuentas` (activas, `id → nombre`)
    para el diálogo de pago, los dos mensajes ya resueltos y el texto del enlace de autofactura.
  - `edit` / `update`: solo si `esEditable()` (la Policy lo niega con mensaje). `update` revierte el
    inventario, reemplaza las líneas, recalcula totales, valida existencias, aplica la salida nueva
    y llama a `recalcularEstado()`. Con pagos, el total nuevo no puede quedar por debajo de lo
    pagado (ver "Validaciones").
  - `destroy`: solo si `puedeEliminarse()`. Revierte el inventario y borra. Redirige al listado.
  - `ticket`: `GeneradorTicketPedido::generar()`, `Content-Type: image/jpeg`, `inline` (o
    `attachment` con `?descargar=1`), nombre `ticket-PED-0042.jpg`, `Cache-Control: no-store`.
    No escribe nada en disco.
  - `etiqueta`: la vista sin layout.
- **`PedidoPagoController`**: ver "Integración con Tesorería".
- **`PedidoEntregaController`**: `show`, `store` y `destroy`; ver "La entrega por escaneo".
- **`ConfiguracionController`**: ver "Configuración".
- **`AutofacturaController`**: `show` y `store`; ver "Portal de autofacturación". Busca el pedido
  con `Pedido::where('autofactura_token', $token)->firstOrFail()`. No hay sesión, Policy ni usuario
  autenticado: el token **es** la autorización.
- **`MovimientoController::index`**, **`TimbradorFacturas`**, **`RegistradorInventario`**,
  **`EnvioFacturaController`** y **`FacturaController`** cambian según lo descrito arriba.

Todo lo autenticado filtra por `user_id`; lo ajeno responde 404.

### Autorización (`PedidoPolicy`)

- `view`, `update`, `delete`, `operar` → dueño; si es ajeno, `Response::denyAsNotFound()` (patrón
  `esDueno` de `CotizacionPolicy`). `operar` cubre ticket, etiqueta, pagos, entrega y deshacer; la
  regla de estado de cada acción la revisa su controlador con los métodos del modelo.
- `update` → `Response::deny('Un pedido pagado ya no se edita: el ticket ya salió con esas líneas.')`
  en `pagado` y `'Un pedido entregado ya no se edita.'` en `entregado`.
- `delete` → `Response::deny(...)` con el motivo si tiene pagos o no está `pendiente`.
- Blade usa `@can` para editar y eliminar. Los Form Requests usan `Gate::inspect(...)` en
  `authorize()`, como en 011.

### Validaciones (Form Requests)

- **`PedidoRequest`** (alta y edición):
  - `cliente_nombre`: requerido, string, máx. 150.
  - `cliente_telefono`: requerido. `prepareForValidation()` lo normaliza con el trait existente
    `Requests\Concerns\NormalizaTelefono` (`+52` y 10 dígitos, como el teléfono del cliente en
    005) y se valida con `regex:/^\+52\d{10}$/`.
  - `cliente_correo`: nullable, `email:rfc`, máx. 255.
  - `lineas` y `lineas.*`: **las mismas reglas que `CotizacionRequest`** (mínimo 1, máximo 100,
    `articulo_id` nullable, propio, sin eliminar y `distinct`; `descripcion` requerida siempre,
    porque es lo único que identifica una línea libre; `cantidad` entera de 1 a 9999;
    `precio_unitario` > 0; descuentos; `tasa_iva` del enum). Se extraen a un trait
    `Requests\Concerns\ReglasLineasDocumento` que usan los dos, sin cambiar las de cotización.
  - Descuento global: mismas reglas que 011.
  - En edición con pagos, el total nuevo no puede ser menor a lo pagado ("El total no puede ser
    menor a lo ya pagado ($X).").
  - La validación de existencias **no** vive aquí sino en la transacción (ver "Inventario"), porque
    en edición depende de lo que el propio pedido devuelve.
- **`PedidoPagoRequest`** (bolsa `pago`): `cuenta_id` requerido, cuenta activa del usuario (trait
  `ValidaCuentas` de 016); `fecha_pago` requerida, fecha, no futura; `monto` requerido, numérico,
  `gt:0`, `decimal:0,2`, no mayor al saldo pendiente. El controlador lo vuelve a comprobar con la
  fila bloqueada.
- **`EntregarPedidoRequest`**: si el pedido ya está `entregado`, no exige nada (el controlador
  responde idempotente). Si tiene saldo, `cuenta_id` es requerido y debe ser una cuenta activa del
  usuario. Sin saldo, `cuenta_id` está `prohibited`. Sin campo de monto.
- **`ConfiguracionRequest`**: las reglas de cada clave salen de `ClaveConfiguracion::reglas()`.
- **`AutofacturaRequest`** (`authorize()` → `true`; el token ya se resolvió): `rfc` con la regla
  existente `RfcValido` (`phpcfdi/rfc`) y en mayúsculas; `razon_social` requerido, máx. 255;
  `regimen_fiscal` `Rule::enum(RegimenFiscal::class)`; `codigo_postal_fiscal` `regex:/^\d{5}$/`;
  `uso_cfdi` `Rule::enum(UsoCfdi::class)`; `correo` requerido, `email:rfc`. Mismos mensajes que
  `ClienteRequest`.
- **`ListadoPedidosRequest`**: como `ListadoCotizacionesRequest`, no valida nada; sanea `folio`
  (acepta `PED-0042`, `0042` o `42`), `cliente` (nombre parcial), `telefono` (dígitos, parcial),
  `estado` (lista blanca), `fecha_desde`/`fecha_hasta`, `periodo` (`hoy`, `semana`, `mes`) y
  `pagina`, con las fechas en la zona del negocio.
- `attributes()` en español en todos.

## Vistas (Blade)

Todas, salvo la etiqueta y el portal, extienden `layouts/app.blade.php` y usan los componentes de
003.

### Navegación

- **Pedidos** entra al grupo **Ventas**, en **primer lugar**: Pedidos · Facturas · Cotizaciones ·
  Clientes. Es la venta que entra por la puerta. Icono `ticket-perforated`, descripción "Ventas de
  mostrador y tickets". El grupo se marca activo también con `pedidos.*`.
- **Configuración** se agrega como enlace directo (icono `gear`) antes del nombre del usuario.
- La etiqueta, la entrega y el portal **no** aparecen en el menú.

### `pedidos/index.blade.php` — listado

- Tabla: folio, cliente, teléfono, fecha, total, pagado, saldo, estado (etiqueta de color) y
  acciones (ver).
- **Señal en la fila** cuando `autofactura_error` no está vacío: icono `exclamation-triangle` con
  `title="El cliente intentó facturar y no pudo: {motivo}"`.
- Filtros por columna combinables bajo los títulos (folio, cliente, teléfono, estado) y un selector
  de periodo ("Hoy", "Esta semana", "Este mes" —por defecto— y "Todas"), con
  `data-busqueda-dinamica` hacia `pedidos.buscar`, como el listado de clientes.
- Parciales `_resultados`, `_filas` y `_paginacion`.
- Botón "Nuevo pedido".

### `pedidos/crear.blade.php` y `editar.blade.php` (`_formulario.blade.php`)

- **Cliente** arriba: nombre, teléfono y correo. Bajo el teléfono hay un `x-alerta` informativo
  oculto (`data-sugerencia-cliente`) que el script llena: "Este teléfono compró antes como **Juan
  Pérez** (juan@correo.com). [Usar esos datos]". Es una sugerencia, no un autocompletado: solo
  rellena si el usuario pulsa el botón, y **nunca** pisa un campo que ya tenga texto escrito.
- **Líneas**: el mismo parcial de líneas de cotización (`data-documento-lineas`, buscador de
  artículos, `#plantilla-linea`, botón **"Agregar línea libre"**, aviso de duplicado y resumen en
  vivo), con la misma reconstrucción desde `old('lineas')` y el mismo respaldo sin JavaScript.
- Un error de existencia aparece en la celda de la línea que lo causó.
- En edición con pagos, un aviso arriba: "Este pedido ya tiene pagos por $X: el total no puede
  quedar por debajo."

### `pedidos/show.blade.php` — detalle

- Encabezado: `PED-0042`, estado, cliente (nombre, teléfono, correo), fechas, y las líneas y totales
  con el mismo formato de la hoja de cotización.
- **Pagos**: fecha, cuenta, monto y "Al entregar" si `registrado_al_entregar`; total pagado y saldo.
  Cada pago tiene "Eliminar" con `data-confirmar` si `puedeEliminarPago()`.
- **Vista previa del ticket**: `<img src="{{ route('pedidos.ticket', $pedido) }}" loading="lazy">`
  a ancho de columna. Se muestra **siempre**, también sin pagos, para que el usuario revise cómo
  queda.
- Si se facturó: "Factura {folio}" con enlace a `facturas.show`. Si `autofactura_error`: `x-alerta`
  de advertencia con el motivo.
- **Acciones**, cada una visible según los métodos del modelo:
  - "Editar" (`@can('update')`).
  - "Agregar pago" si `puedeRegistrarPago()`: `<dialog>` con cuenta (sin preselección), fecha (hoy)
    y monto (precargado con el saldo, editable).
  - "Compartir ticket" si `puedeCompartirTicket()` (ver "JavaScript").
  - "Imprimir etiqueta": enlace a `pedidos.etiqueta` en pestaña nueva.
  - "Avisar que está listo" si `puedeCompartirTicket()` y el mensaje no está vacío (enlace `wa.me`).
  - "Compartir enlace de autofactura" si `motivoAutofacturaNoDisponible() === null`: enlace
    `https://wa.me/?text=` con "Para generar tu factura del pedido No. 0042 entra a: {url}. El
    enlace vence el {fecha}." También se muestra la URL en un campo de solo lectura, para copiarla a
    mano.
  - "Entregar" si no está entregado: enlace a `pedidos.entregar` (la misma pantalla del QR).
  - "Eliminar" (`@can('delete')`), con confirmación que advierte que se devuelven las existencias.

### `pedidos/entregar.blade.php`

Los tres caminos descritos en "La entrega por escaneo", con la clase `contenido-entrega`.

### `pedidos/etiqueta.blade.php`

Ver "La etiqueta". Documento HTML completo, sin layout, con su `<script src>` de impresión.

### `configuracion/edit.blade.php`

Dos tarjetas hermanas, **"Mensaje del ticket"** y **"Mensaje de pedido listo"**, con el mismo
parcial (`_mensaje.blade.php`, que recibe la clave y el título). Cada una tiene un `textarea`, la
lista de huecos (`MensajePedido::HUECOS`) con un ejemplo resuelto debajo, y la nota "Déjalo vacío
para compartir solo la imagen" o "Déjalo vacío para ocultar el botón". Un solo botón "Guardar".

### `autofactura/show.blade.php` y `layouts/publico.blade.php`

Ver "Portal de autofacturación". El layout público carga `app.css` y `app.js` pero no Axios.

### `facturas/show.blade.php`

Gana la línea "Origen: Pedido PED-0042 (autofactura)" con enlace cuando `pedido_id` no es nulo.
"Corregir datos" desaparece solo, porque la Policy niega `update`.

### Tesorería: listado de movimientos

Sin cambios de vista: `documentoOrigen()` ya trae la etiqueta, la URL y la utilidad del pedido.

## JavaScript

Solo lo indispensable, en archivos de `public/js`:

- **`documento-lineas.js` y `totales-documento.js`**: sin cambios. Ya admiten `articulo_id` vacío y
  la línea libre.
- **`compartir-pdf.js`** (se extiende, sin cambiar el comportamiento actual):
  - `data-tipo` (MIME, por defecto `application/pdf`) para crear el `File` y para la comprobación de
    `canShare`, que deja de estar fija en PDF.
  - `data-sufijo` (por defecto `". Te adjunto el PDF."`) para el texto de `wa.me`. En el ticket va
    vacío.
  - `data-descargar-sin-menu`: sin texto y sin menú de compartir, **descarga** en lugar de ocultar
    el botón. El ticket no existe en ningún otro lado y sin el archivo el botón no sirve en la
    computadora del mostrador.

  El botón del ticket: `data-compartir-pdf`, `data-pdf` → `pedidos.ticket`, `data-tipo="image/jpeg"`,
  `data-archivo="ticket-PED-0042.jpg"`, `data-texto` = mensaje del ticket resuelto (si no está
  vacío), `data-sufijo=""`, `data-precargar="al-apuntar"` y `data-descargar-sin-menu`. En el
  celular abre el menú del sistema con la imagen y el mensaje. En el escritorio descarga el JPG y
  abre `wa.me` con el mensaje; el usuario elige el contacto y arrastra la imagen. No lleva
  `data-marcar`, porque compartir no cambia el estado.
- **`pedido-cliente.js`** (nuevo): al salir del campo teléfono (o 500 ms después de dejar de
  escribir), con 7 dígitos o más, consulta `pedidos.cliente-por-telefono` con Axios y llena el aviso
  de sugerencia. El botón "Usar esos datos" rellena solo los campos vacíos. Si hay error de red no
  pasa nada (es una comodidad), y con 401 recarga la página.
- **`app.js`** gana tres manejadores genéricos, sin mencionar pedidos:
  - `data-enviar-al-cargar`: envía el formulario al cargar la página, una sola vez.
  - `data-cuenta-regresiva="N"`: muestra los segundos restantes dentro del elemento
    `[data-cuenta-regresiva-numero]` y oculta el formulario al llegar a 0.
  - `data-habilitar-con="#id"`: el botón queda deshabilitado mientras el `select` indicado esté
    vacío.
- **`etiqueta-pedido.js`** (nuevo, una línea): `window.addEventListener('load', () => window.print())`.
  Se espera a `load` para que el QR ya esté pintado.

## Pruebas (Pest y Node)

`tests/Feature/PedidosTest.php`, `PedidoPagosTest.php`, `PedidoInventarioTest.php`,
`PedidoEntregaTest.php`, `PedidoTicketTest.php`, `ConfiguracionTest.php`, `AutofacturaTest.php`, y
los ajustes a `TesoreriaUtilidadTest.php`, `InventarioDocumentosTest.php` y `FacturasTest.php`.
facturapi.io con `Http::fake()`, como en 012.

1. Invitado redirigido al login en todas las rutas de `/pedidos` y `/configuracion`. Usuario
   suspendido no entra. **Las dos rutas de autofactura responden sin sesión.**
2. Aislamiento: ver, editar, eliminar, pagar, entregar, deshacer, ticket o etiqueta de un pedido
   ajeno → 404. `cliente-por-telefono` no devuelve datos de otro usuario. Cuenta o artículo ajeno es
   error de validación.
3. Alta con líneas de catálogo y líneas libres: estado `pendiente`, totales y `costo_unitario`
   calculados en el servidor (nulo en la libre), y los totales que lleguen en la petición se
   ignoran.
4. El folio es consecutivo por usuario, independiente de facturas, cotizaciones y órdenes de
   compra, y no se reutiliza al borrar.
5. Crear descuenta existencias solo de las líneas con artículo, con motivo `venta_pedido`.
6. Un artículo sin fila viva o con existencia 0 rechaza el alta con el error en su línea y **no**
   crea pedido ni movimientos. Con existencia insuficiente pero mayor a cero sí se crea y deja
   faltante pendiente.
7. Editar revierte con `correccion_pedido` y vuelve a descontar: tres ediciones dejan la existencia
   como si hubiera una sola venta. Un pedido que se llevó las últimas piezas se puede editar.
   Borrar un pedido `pendiente` devuelve las existencias.
8. Un anticipo deja `anticipo`; completar el saldo deja `pagado` y genera un token de 64
   caracteres; varios pagos se suman; un monto mayor al saldo se rechaza.
9. Cada pago genera su ingreso con el concepto "Pago de Pedido PED-0042" y mueve el saldo de la
   cuenta. Eliminar un pago borra su ingreso y regresa el estado. Si eso dejaría la cuenta negativa,
   no se borra nada.
10. Editar un pedido `pagado` o `entregado` → 403 con mensaje. Borrar uno con pagos → rechazado.
    Editar con pagos a un total menor a lo pagado → error.
11. `POST entregar` con saldo y `cuenta_id` cobra **el saldo exacto** a esa cuenta
    (`registrado_al_entregar`, concepto "Saldo al entregar de…"), marca `entregado` y fija
    `entregado_en`. Un `monto` en la petición se ignora.
12. `POST entregar` con saldo y **sin** `cuenta_id` → error de validación, sin pago, sin movimiento
    y sin cambio de estado.
13. `POST entregar` con saldo cero marca `entregado` **sin** pago ni movimiento. Mandar `cuenta_id`
    ahí es error.
14. `POST entregar` dos veces seguidas cobra **una sola vez**. La segunda responde "ya se entregó".
15. `deshacer-entrega` de una entrega sin cobro limpia `entregado_en` y regresa el estado derivado
    de los pagos.
16. `deshacer-entrega` de una entrega **con cobro** → rechazado, sin tocar el pago ni el movimiento.
17. `deshacer-entrega` pasados 5 minutos (`travelTo`) → rechazado, sin tocar nada.
18. `GET entregar` pinta el camino que corresponde: ya entregado (sin formularios), saldo cero
    (formulario `data-enviar-al-cargar` sin cuenta) y saldo pendiente (`select` sin opción
    seleccionada y botón con el saldo). Sin cuentas activas aparece el aviso.
19. El ticket responde `image/jpeg`, `imagecreatefromstring` lo abre, mide 576 px de ancho, tiene
    `Cache-Control: no-store`, y pedirlo dos veces **no crea ningún archivo** en `storage/`
    (`Storage::fake` + comparación del directorio). Sin logo configurado también se genera.
20. El QR del ticket se lee: se recorta la región del pie y se decodifica con el lector de
    `chillerlan/php-qrcode` (`GDLuminanceSource`) que ya usa `QrLector` (006) para comprobar que contiene la URL absoluta de `pedidos.entregar`.
21. Los dos mensajes resuelven `{nombre}`, `{folio}`, `{total}`, `{pagado}` y `{saldo}`, y dejan
    intacto `{otro}`. Sin fila en `configuraciones` sale el texto por defecto; con valor vacío sale
    `null` (y el botón de aviso no aparece).
22. Configuración guarda los dos mensajes por usuario, acepta vacío, rechaza más de 2000
    caracteres y no ve los de otro usuario.
23. `GET autofactura/{token}`: inexistente → 404 con mensaje; con saldo pendiente, vencido
    (`travelTo` al mes siguiente) o ya facturado → página con su motivo y sin formulario.
24. `POST autofactura/{token}` crea el cliente fiscal (o actualiza el existente con ese RFC), crea
    la factura con `pedido_id`, PUE, forma de pago derivada de la cuenta del último pago, mismos
    importes y total, claves genéricas en la línea libre; timbra, envía el correo (`Mail::fake`) y
    **no** genera movimientos de inventario.
25. Timbrado fallido (`ErrorDatos`): `autofactura_error` queda escrito, la factura queda
    `pendiente`, el token sigue sirviendo, y el reintento con datos corregidos **reutiliza la misma
    factura**. Nunca hay dos facturas para un pedido.
26. Un pedido ya facturado rechaza un segundo intento. Cancelar esa factura (aceptada) **no**
    devuelve existencias.
27. El limitador `autofactura` responde 429 al intento 21 en un minuto.
28. La factura de un pedido no se edita desde Facturación.
29. Tesorería: el ingreso de un pedido muestra la utilidad completa con líneas de catálogo, y
    parcial con alguna línea libre. La utilidad de las cotizaciones no cambia.
30. Listado: filtros por folio (`42`, `0042`, `PED-0042`), cliente, teléfono y estado combinados;
    atajos de fecha en la zona del negocio; `/pedidos/buscar` devuelve el fragmento; la fila con
    `autofactura_error` lleva la señal.
31. Node: `tests/js/compartir-pdf.test.js` cubre `data-tipo="image/jpeg"` en
    `puedeCompartirArchivos`. `node --check` sobre los scripts nuevos.
32. `EstiloUniformeTest`, las pruebas de cotizaciones, facturas, Tesorería e inventario siguen
    pasando.

El comportamiento en el navegador (sugerencia por teléfono, compartir en celular y escritorio,
impresión de la etiqueta, envío automático y cuenta regresiva) se verifica a mano.

## Fuera de alcance

- **Todo seguimiento de la fabricación**: órdenes de trabajo, dibujos, colores de tinta, aprobación
  del diseño, hojas de taller y estados de producción.
- **Que el sistema sepa cuándo un pedido está listo.** El aviso lo dispara el usuario.
- **La aplicación instalada y su lector de QR** ([remotas/029](remotas/029-pwa-mostrador.md)).
- **Ajuste al peso cerrado** ([remotas/030](remotas/030-total-al-peso-cerrado.md)) y descuento de
  mostrador ([remotas/036](remotas/036-descuento-venta-mostrador.md)).
- **Envío automático por WhatsApp** (Twilio u otro). Todo envío lo dispara el usuario.
- Convertir un pedido en cotización, o al revés.
- **Cancelar la factura** desde el pedido (se cancela en `/facturas/{id}`, flujo de 012) y
  **refacturar**: un pedido genera una sola factura, aunque se cancele.
- Devoluciones o reembolsos del pedido.
- **Impresión térmica directa** del ticket.
- **Complemento de pago** para pedidos: la factura es PUE.
- Más claves en Configuración que las dos de esta historia, y configuración del negocio editable
  desde pantalla (por ahora vive en `.env`).
- Bitácora de quién entregó o deshizo una entrega.

## Estado de implementación

Implementada el 2026-10-01.

- **Archivos nuevos**:
  - migración `2026_10_04_100000_create_pedidos_tables` (revisada con `migrate --pretend` contra
    MySQL y **aplicada en la base local**),
  - enums `EstadoPedido`, `ClaveConfiguracion`; modelos `Pedido`, `PedidoLinea`, `PedidoPago`,
    `Configuracion`; trait `Models\Concerns\CalculaUtilidadVenta`; `PedidoFactory`,
  - servicios `Pedidos\MensajePedido`, `Pedidos\CodigoQrPedido`, `Pedidos\GeneradorTicketPedido`,
    `Pedidos\Autofacturador` (con `ResultadoAutofactura`) y `Facturacion\EnviadorCorreoFactura`,
  - `PedidoController`, `PedidoPagoController`, `PedidoEntregaController`,
    `ConfiguracionController`, `AutofacturaController`,
  - `PedidoRequest` (extiende `CotizacionRequest`), `PedidoPagoRequest`, `EntregarPedidoRequest`,
    `ConfiguracionRequest`, `AutofacturaRequest`, `ListadoPedidosRequest`; `PedidoPolicy`,
  - vistas `pedidos/*`, `configuracion/*`, `autofactura/show`, `layouts/publico`,
  - `config/negocio.php`, `resources/fonts/` (DejaVu Sans Mono y su nota de licencia),
    `public/js/pedido-cliente.js`, `public/js/etiqueta-pedido.js`,
  - pruebas `PedidosTest`, `PedidoInventarioTest`, `PedidoPagosTest`, `PedidoEntregaTest`,
    `PedidoTicketTest`, `AutofacturaTest`.
- **Archivos modificados**: `Cotizacion` (usa el trait), `Factura` (`pedido()`,
  `mueveInventario()`, `siguienteFolio()` estático, `esEditable()`), `FacturaController`,
  `FacturaPolicy`, `EnvioFacturaController` (usa `EnviadorCorreoFactura`), `TimbradorFacturas` y
  `RegistradorInventario` (`mueveInventario()` y `revertirDocumento()`), `Movimiento`,
  `MovimientoController`, `MotivoMovimientoInventario`, `TipoCuenta` (`formaPagoSat()`),
  `CotizacionRequest` (`documento()` admite `Pedido`), `User`, `AppServiceProvider` (morph map y
  limitador), `routes/web.php`, `layouts/app` (menú), `facturas/show`, `app.css`, `app.js`,
  `compartir-pdf.js`, `.env.example`, `tests/Pest.php`, `tests/js/compartir-pdf.test.js`.
- **Decisiones al implementar**:
  - Al cobrar el saldo en la entrega se llama a `recalcularEstado()` antes de `marcarEntregado()`:
    sin eso, un pedido cobrado y entregado en el mismo acto nunca generaba el enlace de
    autofactura.
  - El QR del ticket no se reescala (ver "El ticket JPG"): se genera directamente a la escala
    entera que cabe en 300 px.
  - El listado usa un selector de periodo en lugar de rango de fechas libre.
  - El portal público carga Axios y `app.js` (por `data-enviar-una-vez`); `app.js` necesita Axios
    para su configuración inicial.
  - El texto del enlace de autofactura deja un espacio antes del punto final, para que WhatsApp no
    pegue el punto a la URL.
- **Verificación**: `php artisan test` con 948 pruebas; la única falla es la anterior a esta
  historia (`EstiloUniformeTest` por un `<button>` en `dashboard.blade.php`). Las 82 pruebas nuevas
  pasan, y `node --test "tests/js/*.test.js"` (37) también. Pint sin cambios. El ticket se revisó
  como imagen, y una prueba lee su QR con `chillerlan/php-qrcode`.

  **No se revisó la UI en un navegador real.** Falta probar la sugerencia por teléfono, compartir
  el ticket en celular y escritorio, la impresión de la etiqueta en una impresora de etiquetas, el
  envío automático y la cuenta regresiva de la entrega, y el escaneo con la cámara de un celular
  (requiere que `APP_URL` sea una dirección que el celular alcance). Tampoco hay timbrado real de
  una autofactura: no hay credenciales de facturapi.io en este entorno, y las claves genéricas
  `01010101` / `H87` no se han probado contra el SAT.

## Criterios de aceptación

1. Se levanta un pedido con nombre, teléfono y correo, sin RFC y sin tocar el catálogo de Clientes.
   Un teléfono que ya compró antes ofrece rellenar nombre y correo, sin pisar lo escrito.
2. Al pedido se le agregan artículos del catálogo y líneas libres, con cantidad y precio editables.
3. Al guardarlo se descuentan existencias solo de las líneas con artículo, y el folio es
   consecutivo e independiente. Un artículo sin existencia (o fuera de existencias) impide guardar
   y se señala en su línea. Vender por arriba de lo disponible sí se permite y deja faltante.
4. Se registra un anticipo con su cuenta y el pedido pasa a `anticipo`. El ingreso aparece en
   Contabilidad con su utilidad y mueve el saldo de la cuenta.
5. El detalle muestra el ticket dibujado por el servidor, con el saldo correcto y **el QR al pie**,
   grande y con el número de ticket debajo. Sin pagos no se ofrece "Compartir ticket". Con el
   primer pago aparece, y manda imagen y mensaje en el celular, o descarga la imagen y abre WhatsApp
   con el mensaje en el escritorio.
6. El mensaje sale de Configuración con los huecos ya resueltos. Vacío, se comparte solo la imagen.
7. Pedir el ticket dos veces no deja ningún archivo en el servidor, y tras un abono el ticket sale
   con el saldo nuevo sin que nadie lo regenere.
8. "Imprimir etiqueta" abre una etiqueta de 5 × 2.5 cm con el QR a la izquierda y, a la derecha,
   nombre, teléfono, número de ticket y `SALDO: $250.00` o `PAGADO`.
9. Escanear el QR (de la etiqueta o del ticket en la pantalla del cliente) con el pedido
   **totalmente pagado** lo cierra solo y ofrece "Deshacer" durante 10 segundos.
10. Escanear un pedido **con saldo** no cobra nada hasta confirmar: muestra cliente, total, pagado y
    saldo, exige elegir cuenta, y "Cobrar y entregar" registra el saldo completo y cierra. No
    ofrece "Deshacer".
11. Escanear dos veces no cobra dos veces, y escanear un pedido entregado solo dice cuándo se
    entregó. Sin sesión, el escaneo pide login y regresa al pedido.
12. Con al menos un pago, "Avisar que está listo" abre WhatsApp con el mensaje resuelto y **no
    cambia el estado**.
13. Al quedar pagado, el detalle ofrece el enlace de autofactura y el botón que abre WhatsApp con el
    texto y el enlace.
14. Abriendo el enlace sin sesión, el cliente captura sus datos fiscales y obtiene su factura
    timbrada, que le llega por correo. El pedido queda ligado a ella, el cliente queda en el
    catálogo y no se descuenta inventario otra vez.
15. Un timbrado fallido le explica el motivo al cliente en español y le deja reintentar sin duplicar
    la factura, y marca el pedido en el listado.
16. Cambiar el enlace de autofactura a mano no lleva a ningún otro pedido.
17. El enlace deja de funcionar al terminar el mes de la venta y cuando el pedido ya se facturó.
18. El total del ticket, el del pedido y el de su factura son exactamente el mismo número.
19. Ninguna pantalla pide datos de producción, y `php artisan test`, `pint` y
    `node --test "tests/js/*.test.js"` corren en verde.
20. En Contabilidad, un pedido con todas sus líneas de catálogo muestra la utilidad completa, y uno
    con alguna línea libre la muestra como parcial.

## Supuestos asumidos (registro completo)

Los de la remota que se conservan sin cambio no se repiten aquí. Ver
[remotas/027](remotas/027-venta-mostrador-ticket.md), supuestos 1–60. Estos son los que **cambian o
nacen** en esta reescritura.

**Decisiones de esta reescritura (sujetas a corrección)**

1. **Configuración nace aquí, mínima**: tabla clave→valor **por usuario** (el sistema es
   multiusuario), con solo las dos claves de esta historia y una pantalla. Sin fila sale el texto
   por defecto; con fila vacía no sale mensaje.
2. **Los datos del negocio del ticket** (nombre, teléfono, domicilio, logo) viven en
   `config/negocio.php` desde `.env`, no en pantalla. El nombre, si falta, es `APP_NAME`.
3. **El vínculo con la factura es `facturas.pedido_id`**, no `pedidos.factura_id` como en la
   remota, para seguir el patrón de `facturas.cotizacion_id` (015) y poder ligar la factura
   pendiente de un intento fallido.
4. **El timbrado fallido deja una factura `pendiente`** ligada al pedido y reutilizada en cada
   reintento, en lugar de revertirlo todo: aquí el PAC se llama fuera de la transacción (012, 018).
5. **Si el RFC ya existe como cliente, sus datos fiscales se actualizan** con lo que captura el
   cliente en el portal. Sin esto no podría corregir un código postal que el SAT rechazó.
6. **El mensaje de error al cliente** es el texto de facturapi.io (que ya viene en español) para
   errores de datos, y uno genérico para fallas del servicio. No hay tabla propia de traducción de
   códigos.
7. **El precio con IVA del ticket es una referencia** mientras no exista el peso cerrado: lo que se
   suma es el importe con IVA de cada línea, y el total siempre cuadra con el del pedido.
8. **Pagos libres sin `TipoPago`**, cualquiera se puede borrar (sin LIFO) mientras no haya factura
   timbrada, y se pueden registrar pagos aun en `entregado` (para recapturar un cobro corregido).
9. **El teléfono se guarda normalizado** (`+52` y 10 dígitos) con `NormalizaTelefono`, igual que
   el de los clientes, para que la sugerencia lo encuentre aunque se capture con espacios o guiones.
10. **El texto del enlace de autofactura es fijo** en el código ("Para generar tu factura del
    pedido No. 0042 entra a: … El enlace vence el …"): la remota no lo hacía configurable.
11. **En el escritorio, compartir el ticket descarga el JPG y abre `wa.me` con el mensaje**, sin
    copiarlo además al portapapeles: `wa.me` ya lo lleva escrito.
12. **Folio con 4 dígitos** (`PED-0042`, ticket `No. 0042`), como `COT-0012` y `OC-0015`, en lugar
    de los 5 de la remota.
13. **La factura nacida de un pedido no se edita** desde Facturación: sus líneas pueden ser libres
    y `FacturaRequest` las rechazaría. Duplicarla sí se puede (omite las líneas libres, como ya hacía).
14. **El bloqueo por existencia se valida dentro de la transacción**, después de revertir lo que el
    propio pedido había sacado, para que un pedido que se llevó las últimas piezas se pueda editar.
