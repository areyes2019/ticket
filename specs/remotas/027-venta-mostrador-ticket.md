# Spec: Venta de mostrador (pedido, ticket, etiqueta con QR y autofactura)

## Historia de usuario

Como usuario único del sistema, quiero atender al cliente que entra al local y compra directo —sin
cotización de por medio—, cobrarle un anticipo o el total, entregarle un ticket de compra estilo
punto de venta por WhatsApp, pegarle al trabajo una etiqueta con un código QR que al escanearlo dé
por entregado el pedido, y mandarle un enlace para que él mismo se haga su factura.

El flujo completo, tal como lo describió el usuario:

1. El cliente paga el anticipo.
2. Se levanta un pedido: nombre, teléfono y correo.
3. Se seleccionan los artículos y se agregan a la lista.
4. Se registra el anticipo o el pago completo, según sea el caso.
5. Se genera un `.jpg` estilo ticket y con un botón compartir —como el de las imágenes de
   artículos— se le manda al cliente. Al mismo tiempo se genera una etiqueta para imprimir con
   nombre, no. de ticket, teléfono y un código QR.
6. Junto con la imagen viaja un mensaje explicando los siguientes pasos.
7. Cuando el sello se entrega, se escanea con el celular el QR de la etiqueta y el pedido queda
   cobrado y entregado.
8. Se genera un enlace para que el cliente se autofacture y se le manda por WhatsApp con un botón
   compartir.

### Revisión del 2026-08-16

Al replantear el seguimiento de la producción, el usuario redujo el flujo a cuatro pasos:

1. El ticket se genera cuando el cliente hace un primer pago, parcial o total.
2. El ticket lleva el QR y los datos de la venta.
3. **El sello se elabora fuera del sistema.**
4. Se entrega el sello y se escanea el QR para cerrar la compra, con saldo pendiente o sin él.

De ahí salen los cambios que este documento ya incorpora: el **QR dentro del ticket**, el **saldo en
la etiqueta**, la **entrega que pide confirmación cuando hay dinero de por medio**, el aviso de "ya
está listo" y el **ticket que no se guarda en disco**. Las reglas que quedaron superadas están
reescritas al comportamiento nuevo, y el registro de supuestos dice cuáles cambiaron.

El sistema **no da seguimiento a la fabricación**: no hay órdenes de trabajo, ni dibujos, ni colores
de tinta, ni estados de producción. Se evaluó un módulo para eso y se descartó por sobredimensionado
para un taller de una persona que hace unos diez sellos diarios.

## Objetivo / Alcance

Un módulo nuevo, **Pedidos**, con su propio documento, su propio folio y su propio listado
`/pedidos`. Incluye: captura del cliente de mostrador dentro del propio pedido (sin RFC), líneas de
artículo del catálogo **y líneas libres a mano**, registro de pagos contra Tesorería, ticket JPG con
QR dibujado en el servidor, etiqueta imprimible de 5 × 2.5 cm con QR y saldo, cierre del pedido al
escanear ese QR, aviso de "ya está listo", y un **portal público de autofacturación** que timbra la
factura del pedido con Facturapi.

Se apoya en lo que ya existe y **no lo modifica salvo donde se diga explícitamente**: el cálculo de
totales de [007](007-facturacion.md) (`FacturaTotalesCalculator`), el componente de líneas
`DocumentoLineas` de [008](008-cotizaciones.md), la escritura de movimientos de
[010](010-tesoreria.md) (`TesoreriaService`), el descuento de existencias de
[017](017-inventario.md) (`InventarioService`), el patrón del botón "Compartir" de
[020](020-imagenes-articulos.md), la generación de QR de [019](019-formato-pdf-documentos.md)
(`chillerlan/php-qrcode`), y el timbrado de [007](007-facturacion.md) (`FacturapiService`).

### Por qué un documento nuevo y no una cotización

La Cotización de [008](008-cotizaciones.md) parece cubrir esto —tiene líneas, pagos con anticipo,
estados y envío— pero choca en tres puntos que no son de forma:

- **Exige un `Cliente` fiscal.** `cotizaciones.cliente_id` es obligatorio y `Cliente` exige `rfc`,
  `razon_social`, `regimen_fiscal` y `codigo_postal_fiscal` ([004](004-gestion-clientes.md)). El
  cliente de mostrador entrega nombre, teléfono y correo, y muchas veces nunca pedirá factura.
  Forzarlo al catálogo fiscal lo llenaría de registros con RFC genérico que después estorban en el
  buscador de facturas.
- **El dinero llega en el orden inverso.** Una cotización nace sin pago y espera aprobación; aquí el
  cliente ya pagó antes de que exista el documento. Los estados `borrador → enviada` no describen
  nada de esta venta.
- **La entrega se cierra distinto.** En cotización, `producto_entregado` se marca a mano desde el
  detalle. Aquí se cierra escaneando una etiqueta, cobrando el saldo de paso.

Meter todo eso en `Cotizacion` significaría hacer `cliente_id` nullable, agregar dos estados que no
aplican al flujo viejo y ramificar cada pantalla según el tipo. Sale más caro que un documento
propio que reusa los mismos servicios.

## Backend (Laravel)

### Modelo `Pedido`

`$table = 'pedidos'` explícito, misma lección ya pagada en 005, 008, 012 y 017.

- Pertenece a un `User` (`user_id`).
- `folio`: entero autoincremental **por usuario**, independiente de `Factura` y de `Cotizacion`. Se
  calcula igual que en 008 (`max('folio') + 1` dentro de la transacción de creación). **Este folio
  es el "No. de ticket"** que se imprime en la etiqueta y en el ticket.
- **Datos del cliente, dentro del propio pedido** (no hay FK a `Cliente`):
  - `cliente_nombre`: string, **obligatorio**.
  - `cliente_telefono`: string, **obligatorio**.
  - `cliente_correo`: string, **opcional**.

  El teléfono es obligatorio para identificar al cliente cuando vuelve y para imprimirlo en la
  etiqueta. **No se usa para enviar nada**: el envío lo resuelve el botón compartir del sistema
  operativo, donde el usuario elige el contacto. Por eso aquí no hay integración con Twilio, a
  diferencia de 008.
- **Descuento global y totales**: `descuento_global_tipo`, `descuento_global_valor`, `subtotal`,
  `total_descuento`, `total_iva_16`, `total_iva_0`, `total_exento`, `total`. Mismo esquema y mismo
  `FacturaTotalesCalculator` de 007, recalculados siempre en backend, `422` si el `total` enviado no
  coincide con el recalculado. **No** aplica el descuento permanente de cliente de
  [015](015-descuento-permanente-cliente.md): ese vive en `Cliente` y aquí no hay `Cliente`.
- `estado`: enum `EstadoPedido` (abajo).
- `entregado_en`: `timestamp` nullable. Momento exacto en que el escaneo cerró el pedido; es lo que
  hace posible la ventana de "Deshacer".
- **No hay columna para el ticket.** La imagen no se guarda en ningún lado: se dibuja cada vez que
  se pide y se desecha (ver "El ticket `.jpg`"). La columna `ticket_ruta` que tuvo la primera
  versión se elimina con una migración, junto con los archivos que dejó en el disco.
- `autofactura_token`: string(64) nullable, único, **fuera de `#[Fillable]`**. Ver "Portal de
  autofacturación".
- `autofactura_error`: text nullable, **fuera de `#[Fillable]`**. Último motivo por el que falló un
  intento de timbrado desde el portal público.
- `factura_id`: FK nullable a `Factura`. Se llena cuando el cliente se autofactura. Un pedido con
  `factura_id` no nulo ya no puede generar otra factura.
- Sin soft delete: el `DELETE` es físico (se lleva sus líneas). Se permite solo en estado
  `pendiente` y **sin ningún pago registrado** — mismo criterio que 008: los pagos tienen un
  movimiento de Tesorería colgado y hay que borrarlos con el endpoint de pagos, que es quien sabe
  revertir el movimiento y recalcular el saldo de la cuenta. Tampoco se permite si el pedido tiene
  Orden de Trabajo de Producción (038, criterio 18).

Campos derivados que expone el recurso (no columnas): `pagado` (suma de `pedido_pagos.monto`) y
`saldo` (`total - pagado`).

### Modelo `PedidoLinea`

Mismo esquema que `CotizacionLinea`: `pedido_id`, `articulo_id` (**nullable**), `cantidad` (entero,
mínimo 1), `descripcion`, `modelo`, `precio_unitario` (mayor a 0), `descuento_tipo`,
`descuento_valor`, `tasa_iva`, `importe`, `iva_importe`.

**`articulo_id` nullable es la línea libre.** El usuario puede agregar una línea escribiendo
descripción, cantidad y precio a mano, sin que el producto exista en el catálogo. Una línea libre:

- **No mueve inventario.** `InventarioService::cantidadesPorArticulo` ya descarta las líneas sin
  `articulo_id` —el texto libre existe desde 012— así que esto funciona sin tocar el servicio.
- **No se da de alta en el catálogo.** Es una venta suelta, no un producto nuevo.
- **Sí se puede facturar**, con claves SAT genéricas (ver "Portal de autofacturación").
- **No tiene costo conocido**, así que tampoco participa en el cálculo de utilidad de venta (ver
  abajo, "Costo capturado por línea").

#### Costo capturado por línea ([010](010-tesoreria.md))

Cada línea con `articulo_id` gana `costo_unitario`: decimal(10,2) nullable, sin IVA, una copia del
`costo_con_descuento` del artículo tomada en el momento en que la línea se guarda, no un valor
recalculado después — mismo criterio que `precio_unitario`, que ya es una copia desacoplada del
catálogo.

- Se llena solo cuando hay `articulo_id`; una línea libre queda con `costo_unitario = null` siempre,
  no solo mientras no se conoce el costo.
- No se acepta desde el frontend: el backend lo toma directamente del artículo al crear o editar la
  línea.
- Al editar un pedido editable (`pendiente`/`anticipo`) se recalcula junto con el resto de la línea,
  tomando el costo vigente del artículo en ese instante.
- Es la base de la utilidad de venta que Tesorería expone en `/tesoreria/movimientos` (ver
  [010](010-tesoreria.md), "Utilidad de venta en movimientos automáticos"). Un pedido con al menos
  una línea libre queda marcado ahí como utilidad **parcial**, porque esa línea no aporta costo a la
  suma.
- Los pedidos ya existentes en base de datos antes de esta ampliación quedan con
  `costo_unitario = null` en todas sus líneas: no hay migración que intente reconstruirlo con el
  costo actual del artículo.

Los precios de las líneas con artículo se precargan del catálogo con el `precio_unitario_sin_iva`
del artículo —el que produce el **precio con IVA en peso cerrado** de
[024](024-precios-sin-centavos.md)—, y cantidad y precio quedan editables en la línea, igual que en
008. El IVA se suma encima por renglón, como en cualquier documento del sistema; el ticket es el que
imprime el precio con IVA de vuelta, para que el cliente pueda verificar su cuenta (ver "El ticket
JPG" y [030](030-total-al-peso-cerrado.md)).

### Modelo `PedidoPago`

`$table = 'pedido_pagos'`. Campos: `pedido_id`, `fecha_pago`, `monto`, `cuenta_id`.

Sigue la decisión de 008: **`cuenta_id`, no `forma_pago`**. Un pedido no es un CFDI, así que la
forma de pago del catálogo del SAT no se timbra nunca desde aquí; lo que importa es a qué cuenta de
Tesorería entra el dinero. La forma de pago fiscal se deriva después, solo si el cliente se
autofactura (ver más abajo).

- Se pueden registrar **varios pagos** en el mismo pedido (anticipo hoy, saldo al entregar), sin
  porcentaje mínimo: el usuario captura el monto que recibió.
- Cada pago genera su `Movimiento` de ingreso vía `TesoreriaService`, con concepto no editable
  `Pago de Pedido PED-00042` (mismo formato de `CotizacionPago::conceptoMovimiento`).
- **Todos los pagos son iguales**, incluido el que se registra al entregar: mismo monto capturado,
  misma cuenta elegida por el usuario, mismo movimiento de Tesorería. No hay pagos "automáticos"
  (ver "El escaneo: la entrega").

### Estados

`EstadoPedido`: `pendiente` → `anticipo` → `pagado` → `entregado`.

- `pendiente`: recién creado, sin ningún pago.
- `anticipo`: hay pagos pero la suma no alcanza el `total`.
- `pagado`: la suma de pagos alcanza o supera el `total`.
- `entregado`: cerrado por el escaneo del QR.

Los tres primeros **se derivan solos** de la suma de pagos, cada vez que se agrega o se borra un
pago; no son un campo que el usuario mueva. Solo `entregado` es un estado que se escribe
explícitamente.

`esEditable()` devuelve `true` en `pendiente` y `anticipo`. En `pagado` y `entregado` el pedido
queda congelado: el ticket ya salió hacia el cliente y las líneas ya no deben cambiar debajo de él.

### Inventario

Al **crear** el pedido —no al entregarlo— se descuentan existencias con
`InventarioService::salidaPorDocumento`, con un motivo nuevo
`MotivoMovimientoInventario::VentaPedido` (`venta_pedido`).

**Revisión del 2026-08-26 ([017](017-inventario.md)):** Pedido es la **única** excepción a "la salida
nunca bloquea la operación". Antes de descontar, por cada línea con `articulo_id` se valida que el
artículo tenga fila en la tabla `existencias` de 017 y que su existencia sea mayor a `0`; si no, el
pedido completo se rechaza con `422` señalando la línea. Vender por arriba de lo disponible (existe
`3`, se piden `5`) sigue sin bloquear: se descuentan las `3` y las otras `2` quedan como faltante
pendiente, igual que factura y cotización. Las líneas libres (sin `articulo_id`) nunca bloquean.

Al **editar** un pedido editable se revierte el movimiento anterior y se aplica el nuevo, igual que
hace hoy `CotizacionController` con sus líneas. Al **borrar** un pedido (solo posible en
`pendiente`) se devuelven las existencias.

**La factura nacida de una autofactura no descuenta nada**, porque el pedido ya descontó. Se extiende
`Factura::mueveInventario()` para que devuelva `false` también cuando existe un `Pedido` que la
apunta, exactamente como ya hace con `Cotizacion`.

### El ticket `.jpg`

**Lo dibuja el servidor, no el navegador.** El motivo es concreto: el mismo pedido se comparte desde
la computadora del mostrador y desde el celular del usuario, y si cada aparato dibujara la imagen
con sus propias fuentes —y su propio código QR— al cliente le llegarían tickets de distinto aspecto
según desde dónde se mandó.

`TicketPedidoService`, con **GD** (`imagecreatetruecolor`, `imagettftext`, `imagejpeg`), ya
confirmada disponible en local y verificada como requisito en [020](020-imagenes-articulos.md).

- **Ancho fijo 576 px**, el ancho útil de una impresora térmica de 80 mm a 203 dpi. Alto variable,
  calculado a partir del número de líneas.
- **Fuente**: `DejaVuSansMono.ttf` y `DejaVuSansMono-Bold.ttf`, **copiadas a
  `backend/resources/fonts/`**. Vienen con dompdf, pero leerlas desde `vendor/` ataría el ticket a
  la estructura interna de una dependencia que `composer update` puede reacomodar. Monoespaciada
  porque un ticket alinea importes en columna y una tipografía proporcional los deja disparejos.
- **Contenido**, en este orden:
  1. Logo del emisor centrado, reducido a 240 px de ancho máximo con `ProcesadorImagen` (se omite si
     no hay logo).
  2. Nombre, teléfono y domicilio del emisor.
  3. `TICKET No. 00042` y la fecha y hora de la venta.
  4. Nombre del cliente.
  5. Las líneas: cantidad, descripción, **precio unitario con IVA** e importe. El precio va con IVA
     porque es el número que el cliente conoce y el único con el que puede comprobar el total que
     lee abajo: `3 x $204.00` cuadra contra un total de $612.00, mientras que el precio sin IVA
     imprimiría `3 x $175.86` al lado de un importe que no es el producto de esos dos números.
  6. Subtotal, descuento (si lo hay), IVA, **ajuste al peso** (si lo hay,
     [030](030-total-al-peso-cerrado.md)) y **Total**.
  7. **Pagado** y **Saldo pendiente**.
  8. **El código QR**, centrado, de **300 × 300 px** sobre fondo blanco, con `No. 00042` impreso
     debajo.
- **El QR va al pie y va grande.** Al pie porque es donde el ojo termina de leer la tira y donde no
  compite con nada; grande porque el peor escenario de ese código es que se lea **desde la pantalla
  de un celular**, que brilla y refleja. 300 px sobre un ancho de 576 es poco más de la mitad del
  ticket: sobrado para leerlo de una pantalla o de un papel maltratado. El número impreso debajo es
  el respaldo para cuando el código no lee y hay que buscar el pedido a mano.

  Lo dibuja `chillerlan/php-qrcode` —el mismo del timbre fiscal de
  [019](019-formato-pdf-documentos.md)— y se pega dentro de la imagen con GD. Su contenido es la
  misma URL de la etiqueta (ver "La etiqueta con QR"): **un solo código por pedido**, idéntico en
  los dos papeles. Que el cliente se lleve ese código en su ticket no le da acceso a nada, porque el
  destino exige sesión; le sirve al mostrador, que puede escanearlo **de la pantalla del cliente**
  cuando llega sin la caja etiquetada, que pasa seguido.
- **Salida JPEG calidad 85**, servida por una ruta de Laravel que primero valida la sesión.
- **No se guarda en ningún lado.** El ticket se dibuja en el momento en que se pide —para verlo o
  para compartirlo— y se desecha. La imagen no es un registro: el folio, el cliente, las líneas, los
  totales y los pagos viven en la base de datos y la imagen solo los pinta, así que se puede volver
  a dibujar idéntica cuando haga falta. Guardarla sería guardar una copia por comodidad, y a diez
  tickets diarios eso son unos 200 MB al año que **nunca dejan de crecer** en un plan compartido
  donde el espacio se paga.

  Tiene dos consecuencias buenas y una mala, todas asumidas. **Es imposible que el ticket muestre un
  saldo viejo**, porque siempre se dibuja con los datos del momento: desaparece toda la lógica de
  invalidarlo al registrar o borrar un pago. **No hay nada que limpiar** después ni carpeta que
  vigilar. Y a cambio, **compartirlo dos veces lo dibuja dos veces** — milésimas de segundo, un
  precio irrelevante para este volumen.

Se comparte tal cual en JPEG, sin la conversión desde WEBP que hace la ficha de artículo — aquí el
archivo ya nace en el formato que WhatsApp trata como foto y no como calcomanía.

### El mensaje que acompaña al ticket

Vive en el almacén clave→valor de Configuración ([014](014-costo-elaboracion-goma.md)), como clave
nueva `ClaveConfiguracion::MensajeTicket` (`mensaje_ticket`), con reglas `['string', 'max:2000']`.
Es la primera clave de texto del almacén; las tres existentes son numéricas, así que `reglas()`
gana un `match` que ya no devuelve lo mismo para todos los casos.

Su `valorPorDefecto()` es el texto que dictó el usuario:

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

**Admite huecos que el sistema rellena solo** al momento de compartir: `{nombre}`, `{folio}`,
`{total}`, `{pagado}` y `{saldo}`. El usuario escribe la plantilla una vez y cada cliente recibe su
mensaje personalizado sin teclear nada. Un hueco que no exista se deja tal cual, sin reventar: el
texto es de captura libre y un `{}` mal escrito no debe romper el envío. El reemplazo se hace en
backend y el recurso del pedido expone el mensaje **ya resuelto**, para que el frontend no tenga que
conocer la lista de huecos.

Debajo del campo, en Configuración, se muestra la lista de huecos disponibles.

### La etiqueta con QR

Documento aparte del ticket, pensado para etiqueta adhesiva. Se imprime **desde el navegador**, no
se genera archivo: una vista dedicada con `@page { size: 50mm 25mm; margin: 0 }` y `@media print`,
que llama a `window.print()` al montarse.

Contenido, y nada más: **nombre del cliente, teléfono, `No. 00042`, el saldo y el QR**. Sin
artículos y sin desglose: la etiqueta identifica el trabajo en el estante, no lo detalla.

**El saldo sí se imprime**: `SALDO: $250.00` cuando queda algo por cobrar, `PAGADO` cuando no. Al
ver la caja se sabe si hay que cobrar, sin abrir el sistema. La primera versión de esta spec lo
prohibía —"pasa por manos que no tienen por qué ver lo que se cobró"—, y ese argumento no aplica a
un taller de una persona: no hay manos ajenas por las que pase.

**Distribución de los 50 × 25 mm.** El QR necesita ser cuadrado y tener margen blanco, y con 25 mm
de alto no puede pasar de unos 20 mm sin comerse los márgenes. Así que va **a la izquierda en un
cuadro de 20 mm**, y los cuatro renglones de texto ocupan los ~28 mm restantes, aprovechando que la
etiqueta es más ancha que alta:

```
|<---------- 50 mm ---------->|
+-----------------------------+ ---
| +---------+  Juan Perez     |  ^
| |         |  55 1234 5678   |  |
| |   QR    |  No. 00042      | 25 mm
| |  20mm   |  SALDO: $250.00 |  |
| +---------+                 |  v
+-----------------------------+ ---
  |<-20mm->| |<---- 28mm ---->|
```

El saldo va en el último renglón, en negritas: es el único dato que obliga a actuar y es donde el
ojo lo busca. El nombre se recorta con puntos suspensivos si no cabe — antes de romper el renglón,
porque un renglón que se parte empuja al saldo fuera de la etiqueta.

El QR se dibuja en el servidor con `chillerlan/php-qrcode`, igual que el del timbre fiscal de
[019](019-formato-pdf-documentos.md), y viaja en el recurso del pedido como data URI (`qr_entrega`),
solo en el `show`. Su contenido es la **URL absoluta** de la pantalla de entrega:

```
{APP_URL}/pedidos/{id}/entregar
```

Una URL absoluta y no un dato suelto, por dos razones: la app de cámara del celular la abre sola sin
que nadie copie ni pegue nada, y **el escáner de la aplicación instalada de
[029](029-pwa-mostrador.md) lee ese mismo QR con su propio lector** y actúa sobre el `id` que trae
la ruta, sin reimprimir una sola etiqueta.

**Los dos caminos llegan a la misma pantalla y hacen exactamente lo mismo.** No hay una entrega "por
cámara" y otra "por escáner": el escáner de 029 solo cambia cómo se llega, no lo que pasa al llegar.

### Avisar que está listo

El sello se elabora fuera del sistema, así que **el sistema no sabe cuándo está listo** y no puede
avisar solo. Lo que sí puede es redactar el aviso: el detalle del pedido ofrece **"Avisar que está
listo"**, que el usuario aprieta cuando efectivamente lo está.

Comparte un texto por el menú del sistema en celular y por WhatsApp Desktop/Web en escritorio
(`compartirTexto` de `lib/compartir.ts`, igual que el enlace de autofactura). **No cambia ningún
estado**: el pedido sigue donde estaba y el botón se puede apretar las veces que haga falta, porque
al cliente que no contesta se le insiste.

El texto vive en el almacén clave→valor de Configuración, como clave nueva
`ClaveConfiguracion::MensajeListo` (`mensaje_listo`), con las mismas reglas del mensaje del ticket
(`['present', 'nullable', 'string', 'max:2000']`). Editable y no fijo en el código: es un mensaje
que se le manda al cliente y el tono lo pone el negocio, no el sistema.

Su `valorPorDefecto()`:

```
Hola {nombre} 👋
Tu pedido con No. de ticket {folio} ya está listo. 🎉
Puedes pasar por él cuando gustes. ¡Gracias por tu preferencia!
```

Admite **los mismos huecos que el mensaje del ticket** —`{nombre}`, `{folio}`, `{total}`,
`{pagado}` y `{saldo}`— resueltos por el mismo camino y expuestos ya resueltos en el recurso del
pedido, para que el frontend no conozca la lista. `{saldo}` es el útil aquí: "tu pedido ya está
listo y quedan $250.00 por cubrir" ahorra la sorpresa en el mostrador.

### El escaneo: la entrega

Se escanea con la **app de cámara del propio celular**, que abre la URL en el navegador, o con el
escáner de la aplicación instalada de [029](029-pwa-mostrador.md). Esta spec no construye un lector
de QR.

La pantalla `/pedidos/:id/entregar` **requiere sesión**. Sin ella manda al login y regresa sola al
pedido al autenticarse. Es una acción que mueve dinero: nadie que encuentre la etiqueta tirada en la
calle —o que reciba el ticket reenviado por WhatsApp— debe poder cerrarla.

**La pantalla se resuelve sola según el saldo**, en tres caminos. Al montarse consulta el pedido y
no actúa hasta saber en cuál está.

#### Camino 1 — El pedido ya está entregado

No ofrece ninguna acción. Muestra de quién era y **cuándo se entregó**, y ahí termina. Es el candado
contra el doble escaneo —dos escaneos seguidos no pueden cobrar el saldo dos veces— y de paso sirve
como consulta: se escanea una etiqueta vieja y se ve qué fue de ese pedido, sin poder tocar nada.

#### Camino 2 — Saldo en cero: se cierra solo

El pedido ya estaba pagado por completo, así que **no hay nada que decidir**: la pantalla dispara
`POST /api/v1/pedidos/{pedido}/entregar` al montarse, sin preguntar, y muestra el resultado. No se
registra ningún pago porque no queda nada por cobrar; solo se marca `estado = entregado` y
`entregado_en = now()`.

Preguntar aquí sería pedir permiso para lo único que se puede hacer.

**El "Deshacer" vive únicamente en este camino**, durante **10 segundos**, porque es el único donde
el sistema actúa sin preguntar: escanear la etiqueta equivocada daría por entregado el pedido de
otro cliente sin que nadie lo haya confirmado. Llama a
`POST /api/v1/pedidos/{pedido}/deshacer-entrega`, que deja `entregado_en = null` y recalcula el
estado desde los pagos. El backend lo acepta mientras el pedido esté `entregado`, **no haya
registrado ningún cobro al entregarse** y `entregado_en` sea de hace **menos de 5 minutos**.

La ventana del backend es más ancha que los 10 segundos del botón a propósito: el botón mide
impaciencia del usuario, el límite del servidor evita que un "Deshacer" disparado desde una pestaña
olvidada revierta mañana una entrega legítima.

#### Camino 3 — Saldo pendiente: pide confirmación

Aquí sí hay dinero de por medio, así que **la pantalla no toca nada hasta que el usuario confirma**.
Muestra, en grande:

- Nombre del cliente y no. de ticket.
- **Total**, **Pagado** y **Saldo**.
- El selector de cuenta a la que entra el dinero.
- Un solo botón: **"Cobrar y entregar"**.

Ver a quién se le está cobrando **antes** de cobrarle atrapa la etiqueta equivocada cuando
corregirlo todavía es gratis, en vez de revertir un movimiento de Tesorería después. Es el cambio de
fondo respecto de la primera versión de esta spec, donde el escaneo cobraba y entregaba solo.

**Un botón y no dos.** Cobrar y entregar son la misma decisión con el cliente enfrente; partirla en
dos confirmaciones no protege de nada, porque nadie va a decir que sí al pago y que no a la entrega.

**Se cobra el saldo completo y el monto no se edita.** Entregar el trabajo con el cliente todavía
debiendo dejaría una cuenta abierta sin nada que la respalde — el sello ya se fue. Quien quiera
cobrar de menos tiene el detalle del pedido para registrar el abono que quiera; la entrega cierra.

**La cuenta no viene preseleccionada** y el botón queda deshabilitado hasta elegir una. La primera
versión asumía la caja de efectivo, que es lo más común, pero elegir siempre evita el error que esa
comodidad invita: confirmar por inercia y mandar a la caja un dinero que entró por transferencia.
Es un toque por entrega a cambio de no tener que corregir pagos después. Si el usuario no tuviera
ninguna cuenta dada de alta, la pantalla lo dice y manda a Tesorería.

Al confirmar, `POST /api/v1/pedidos/{pedido}/entregar` con `cuenta_id`, que en una sola transacción:

1. Bloquea la fila del pedido con `lockForUpdate()`.
2. Si el pedido ya está `entregado`, **no toca nada** y responde con la fecha en que se entregó.
3. Registra un `PedidoPago` por el saldo exacto en la cuenta elegida, con su movimiento de ingreso
   vía `TesoreriaService`.
4. Marca `estado = entregado` y `entregado_en = now()`.

**Sin "Deshacer"**: ya hubo una confirmación con los datos del cliente a la vista. Un pago mal
capturado se corrige desde el detalle del pedido, borrándolo y recapturándolo, que es el mismo
camino de todos los demás pagos del sistema.

Como ninguna entrega registra ya un cobro sin que el usuario lo confirme, la columna
`pedido_pagos.automatico` deja de nombrar algo que exista y **se renombra a
`registrado_al_entregar`**. El dato sigue siendo útil por dos motivos, pero ninguno es el de antes:
nombra el movimiento en Tesorería (`Saldo al entregar de Pedido PED-00042`) y es lo que le permite a
`deshacer-entrega` saber que esa entrega ya pasó por una confirmación.

### Portal de autofacturación

Es funcionalidad enteramente nueva: el sistema no tenía nada parecido.

**Cuándo existe el enlace.** El `autofactura_token` se genera —`Str::random(64)`— en el momento en
que el pedido alcanza el estado `pagado`. Antes de eso no hay enlace: no se factura lo que no se ha
cobrado por completo.

**Por qué el token y no el `id`.** El enlace es público, sin contraseña, porque el cliente no tiene
cuenta en el sistema. Si fuera `/autofactura/1043`, cualquiera podría cambiar el número a mano y
meterse a facturar el pedido de otro, o ir probando números hasta encontrar pedidos sin facturar.
Con 64 caracteres al azar no hay nada que adivinar. Es el mismo criterio del PDF público firmado que
008 usa para que Twilio descargue la cotización sin sesión.

**Vigencia.** El enlace deja de funcionar el **último día del mes de la venta**, a las 23:59:59. Un
CFDI se emite en el periodo de la operación; pasado el mes, timbrar esa venta ya no procede y es
mejor que el cliente se tope con un aviso claro que con un timbrado rechazado por el SAT. Vencido,
la página explica que debe contactar al negocio.

**Un pedido, una factura.** Con `factura_id` no nulo el enlace deja de funcionar y el detalle del
pedido muestra el folio de la factura.

**Qué captura el cliente** (`GET /api/v1/autofactura/{token}` devuelve folio, fecha y total para que
sepa qué está facturando):

- RFC, razón social y régimen fiscal — reusando `RegimenFiscalSelect`.
- Código postal fiscal — reusando `CodigoPostalCombobox`.
- Uso de CFDI — reusando `UsoCfdiCombobox`.
- Correo, precargado con `cliente_correo` del pedido si lo hay.

**Qué pasa al enviar** (`POST /api/v1/autofactura/{token}`), en `AutofacturaService`:

1. Se crea el `Cliente` fiscal con esos datos (o se reusa el existente si el RFC ya está dado de
   alta para ese usuario). Aquí sí entra al catálogo: ya trae RFC y régimen, que es justo lo que le
   faltaba al de mostrador.
2. Se crea la `Factura` con sus `FacturaLinea`, copiadas del pedido con los mismos importes.
   - `metodo_pago`: **PUE** siempre. El pedido está totalmente pagado antes de que exista el enlace.
   - `forma_pago`: se deriva del tipo de la cuenta que recibió el **último** pago —`efectivo` → `01`
     (efectivo), `banco` → `03` (transferencia), `digital` → `03`, `otro` → `99` (por definir). El
     cliente no tiene por qué saber a qué cuenta entró su dinero, y el usuario ya lo capturó al
     registrar el pago.
   - **Líneas libres**: se timbran con `clave_prod_serv` `01010101` ("no existe en el catálogo") y
     `clave_unidad` `H87` (pieza). `FacturapiService::construirPayloadFactura` toma hoy esas dos
     claves de `linea->articulo`, que en una línea libre es `null`; sin este respaldo, Facturapi
     rechazaría el timbrado.
3. Se timbra con `FacturapiService::timbrarFactura`.
4. Se escribe `pedidos.factura_id` **dentro de la transacción, antes de timbrar** — mismo orden que
   017 impuso al vínculo factura → cotización, para que la factura no se vea como venta directa y
   descuente existencias que el pedido ya descontó.
5. Se le manda la factura por correo con el mecanismo que ya existe
   (`EnvioDocumentoService`/`FacturaController::enviarCorreo`).

**Cuando el timbrado falla** —RFC inexistente, código postal que no coincide con el que el SAT tiene
registrado, servicio caído—:

- **El cliente ve el motivo en español** y puede corregir y reintentar ahí mismo. Los códigos de
  Facturapi se traducen a frases entendibles; lo que no esté mapeado cae en un mensaje genérico
  seguro. Sin esto, el cliente lee "error 500", cierra la pestaña y se queda sin factura.
- **El pedido queda marcado para el usuario**: `autofactura_error` guarda el motivo y el listado de
  `/pedidos` muestra una señal en esa fila. Es la única forma de enterarse de que un cliente intentó
  facturar y no pudo — ese cliente ya se fue y no va a insistir.

Un intento fallido **no** consume el enlace ni deja factura a medias: todo corre dentro de una
transacción que se revierte, y el token sigue vivo hasta su vencimiento.

### Endpoints

Bajo `auth:sanctum`, scopeados al usuario autenticado:

- `Route::apiResource('pedidos', PedidoController::class)` — listado con filtros por columna
  combinables (folio, nombre, teléfono, estado, rango de fechas), en el mismo estilo de
  [025](025-filtros-columna-listado-articulos.md).
- `POST pedidos/{pedido}/pagos` — registra un pago.
- `DELETE pedidos/{pedido}/pagos/{pago}` — lo borra y revierte su movimiento.
- `GET pedidos/{pedido}/ticket` — dibuja el JPG en el momento y lo devuelve. No guarda nada.
- `POST pedidos/{pedido}/entregar` — cierra el pedido. Con saldo pendiente exige `cuenta_id` y
  registra el cobro; sin saldo solo marca la entrega. Idempotente.
- `POST pedidos/{pedido}/deshacer-entrega` — revierte una entrega **que no cobró nada**, dentro de
  la ventana de 5 minutos.

**Fuera** de `auth:sanctum`, junto al `cotizaciones/{cotizacion}/pdf-publico` que ya vive ahí:

- `GET autofactura/{token}` — folio, fecha y total del pedido, o el motivo por el que el enlace ya
  no sirve (vencido, ya facturado, inexistente).
- `POST autofactura/{token}` — datos fiscales; crea cliente, factura y timbra.

Ambas con throttling explícito (`throttle:20,1`), porque son las únicas rutas del sistema que
cualquiera en internet puede llamar.

### Validaciones (Form Requests)

- **Alta/edición**: `cliente_nombre` requerido, string, máx 150. `cliente_telefono` requerido,
  string, máx 30. `cliente_correo` opcional, email. Al menos una línea. Por línea: `articulo_id`
  nullable y, si viene, debe pertenecer al usuario; `descripcion` requerida siempre —también en la
  línea libre, que es lo único que la identifica—; `cantidad` entero ≥ 1; `precio_unitario` > 0;
  `tasa_iva` del enum. `total` enviado debe coincidir con el recalculado (`422`).
- **Edición bloqueada** con `422` si el estado no es editable.
- **Pago**: `monto` > 0 y no mayor al saldo pendiente; `cuenta_id` propia del usuario; `fecha_pago`
  válida.
- **Entrega**: `cuenta_id` **requerido cuando el pedido tiene saldo pendiente** y propio del
  usuario; prohibido cuando el saldo es cero, porque ahí no entra dinero. El monto no viaja en la
  petición: lo calcula el backend como el saldo exacto, para que un frontend manipulado no pueda
  cerrar un pedido cobrando de menos.
- **Autofactura**: `rfc` válido (`phpcfdi/rfc`, ya instalado), `razon_social`, `regimen_fiscal` del
  catálogo, `codigo_postal_fiscal` de 5 dígitos, `uso_cfdi` del catálogo, `correo` email válido.

### Tests

Feature, con Facturapi mockeado (Mockery), en el estilo del resto del proyecto:

1. Alta de pedido con líneas de catálogo y líneas libres; totales recalculados en backend.
2. Alta con `total` manipulado desde el frontend → `422`.
3. El folio es consecutivo por usuario e independiente de facturas y cotizaciones.
4. Crear un pedido descuenta existencias solo de las líneas con `articulo_id`.
4bis. Crear un pedido con una línea de un artículo sin existencia (o sin fila en `existencias`) se
   rechaza con `422` y no crea el pedido; con existencia insuficiente pero mayor a cero, sí se crea y
   deja faltante pendiente.
5. Registrar un anticipo deja el pedido en `anticipo`; completar el saldo lo deja en `pagado` y
   genera el `autofactura_token`.
6. Cada pago genera su movimiento de ingreso y mueve el saldo de la cuenta.
7. Editar un pedido `pagado` → `422`.
8. Borrar un pedido con pagos → `422`.
9. `POST entregar` con saldo pendiente y `cuenta_id` cobra **el saldo exacto** a esa cuenta, marca
   `entregado` y genera su movimiento de ingreso.
10. `POST entregar` con saldo pendiente y **sin** `cuenta_id` → `422`, sin tocar nada.
11. `POST entregar` con saldo cero marca `entregado` **sin registrar ningún pago** ni movimiento.
12. `POST entregar` dos veces seguidas cobra **una sola vez** (candado contra el doble escaneo).
13. `deshacer-entrega` de una entrega que no cobró nada limpia `entregado_en` y regresa el estado.
14. `deshacer-entrega` de una entrega **que sí cobró** → `422`, sin tocar el pago ni el movimiento.
15. `deshacer-entrega` pasados 5 minutos → `422`, sin tocar nada.
16. El ticket es un JPEG válido, **contiene el QR**, y **no deja ningún archivo en el disco**.
17. El mensaje del ticket y el de "ya está listo" resuelven los huecos y dejan intactos los que no
    existen.
18. `GET autofactura/{token}` inexistente, vencido o ya facturado responde con su motivo.
19. `POST autofactura/{token}` crea el cliente fiscal, timbra, escribe `factura_id` y **no**
    descuenta inventario por segunda vez.
20. Timbrado fallido: la transacción se revierte, `autofactura_error` queda escrito, el token sigue
    vivo y no hay factura huérfana.
21. Un pedido ya facturado rechaza un segundo intento.
22. Todas las rutas de `/pedidos` responden `401` sin sesión; las de autofactura responden sin ella.

## Frontend (Vue 3)

### Pantallas

- **`/pedidos`** — listado con folio, cliente, teléfono, fecha, total, pagado, saldo y estado, con
  filtros por columna combinables. Una **señal en la fila** cuando `autofactura_error` no está
  vacío.
- **`/pedidos/crear`** y **`/pedidos/:id/editar`** — datos del cliente arriba (nombre, teléfono,
  correo) y `DocumentoLineas` abajo, reusando `ArticuloBuscador`. El componente ya soporta líneas de
  texto libre desde 012; aquí se habilita esa opción con un botón "Agregar línea libre".
  - Al capturar un teléfono que ya existe en otro pedido, se ofrece **rellenar nombre y correo** con
    los de la venta anterior. Es una sugerencia que se acepta o se ignora, no un autocompletado que
    pise lo que el usuario esté escribiendo.
- **`/pedidos/:id`** — detalle: datos del cliente, líneas, totales, historial de pagos con su
  cuenta, vista previa del ticket, y los botones **"Imprimir etiqueta"**, **"Agregar pago"**, —en
  cuanto hay un primer pago— **"Compartir ticket"** y **"Avisar que está listo"**, y —cuando está
  pagado— **"Compartir enlace de autofactura"**. Si ya se facturó, el folio de la factura con enlace
  a `/facturas/:id`.
- **`/pedidos/:id/etiqueta`** — vista de impresión de 5 × 2.5 cm, sin layout de la aplicación, que
  llama a `window.print()` al montarse.
- **`/pedidos/:id/entregar`** — destino del QR. Se resuelve sola según el saldo, en los tres caminos
  de "El escaneo: la entrega":
  - **Ya entregado**: dice de quién era y cuándo, y no ofrece nada.
  - **Saldo cero**: cierra el pedido al montarse y muestra el resultado con "Deshacer" y su cuenta
    regresiva de 10 segundos.
  - **Saldo pendiente**: muestra cliente, no. de ticket, total, pagado y saldo en grande, el
    selector de cuenta y el botón **"Cobrar y entregar"**, deshabilitado hasta elegir cuenta. Al
    confirmar muestra el resultado, sin "Deshacer".

  Está pensada para el pulgar y para leerse de lejos: es la única pantalla del sistema que se usa
  de pie, con el cliente enfrente y una caja en la otra mano.
- **`/autofactura/:token`** — **pública**, fuera del guard de sesión y sin el layout de la
  aplicación: la ve un cliente, no el usuario. Muestra folio, fecha y total, el formulario fiscal, y
  al terminar el acuse con el folio de la factura timbrada.

### El botón "Compartir"

**Solo aparece a partir del primer pago**, sea anticipo o pago completo. Un ticket es el comprobante
de un dinero que ya entró; mandarlo con el pedido en `pendiente` le entregaría al cliente un
documento que dice "Pagado $0.00" y que él puede leer como que ya quedó todo hecho. Mientras no haya
ningún pago, en el detalle queda la vista previa del ticket —para que el usuario revise cómo va
quedando— pero no el botón. En cuanto se registra el primer pago aparece, y desde ahí se puede
recompartir cuantas veces haga falta.

Mismo patrón que la ficha de artículo de [020](020-imagenes-articulos.md), decidiendo en tiempo real
con `navigator.canShare({ files })` y no adivinando por el tamaño de la pantalla:

- **En celular**: abre el menú de compartir del sistema con **la imagen del ticket y el mensaje**.
  Es el único camino por el que una imagen puede salir hacia WhatsApp desde una página web.
- **En escritorio**: **descarga el `.jpg`, copia el mensaje y abre WhatsApp** con el texto ya escrito
  (ver [029](029-pwa-mostrador.md)). El usuario elige el contacto y arrastra la imagen descargada.
  Aquí sí se descarga la imagen, a diferencia de la ficha de artículo, porque el ticket no existe en
  ningún otro lado y sin el archivo el botón no sirve de nada en la computadora del mostrador.

Los botones de **enlace de autofactura** y de **"Avisar que está listo"** son distintos porque
comparten texto, no imagen: en Windows abren **WhatsApp Desktop** con el mensaje ya escrito
(`https://wa.me/?text=…`, que el sistema enruta a la aplicación instalada), y dejan al usuario
elegir el contacto. Si no hay WhatsApp, el mismo enlace abre WhatsApp Web. En celular usan
`navigator.share` con el texto.

### Configuración

Dos secciones nuevas, hermanas de las que ya existen ([014](014-costo-elaboracion-goma.md),
[019](019-formato-pdf-documentos.md), [026](026-datos-bancarios-cotizacion.md)):

- **"Mensaje del ticket"** — un `textarea` con el mensaje y, debajo, la lista de huecos disponibles
  con un ejemplo de cómo se ven resueltos.
- **"Mensaje de pedido listo"** — igual, para el aviso de "ya está listo". Comparte la misma lista
  de huecos, así que el componente es el mismo con otra clave y otro título.

### Navegación

Se agrega **Pedidos** al grupo **Ventas** de [013](013-navegacion-principal.md), en primer lugar
—Pedidos · Facturas · Cotizaciones · Clientes—, siguiendo el flujo comercial: es la venta que entra
por la puerta. Ícono `TicketIcon` de Heroicons. Se declara en `navegacion.ts`, como manda 013.

Las rutas `/pedidos/:id/etiqueta`, `/pedidos/:id/entregar` y `/autofactura/:token` **no** aparecen en
el menú: a las dos primeras se llega desde el detalle o desde el QR, y la tercera es del cliente.

## Fuera de alcance

- **Todo seguimiento de la fabricación**: órdenes de trabajo, dibujos del diseño, colores de tinta,
  aprobación del cliente, impresión de hojas de taller y estados de producción. El sello se elabora
  fuera del sistema. Se construyó un módulo para esto y se descartó por sobredimensionado para el
  volumen real del taller.
- **Que el sistema sepa cuándo un pedido está listo.** El aviso al cliente lo dispara el usuario,
  que es quien lo sabe.
- **La aplicación instalada y su lector de QR**, que son [029](029-pwa-mostrador.md). El formato del
  QR se elige aquí para que esa aplicación pueda leerlo sin reimprimir etiquetas.
- **Envío automático por WhatsApp vía Twilio.** Todos los envíos los dispara el usuario con un botón
  y pasan por el menú de compartir del sistema. Nada se manda solo.
- **Convertir un pedido en cotización**, o al revés.
- **Cancelar la factura generada por autofactura** desde el pedido: se cancela desde
  `/facturas/:id`, con el flujo de 007 que ya existe.
- **Refacturar**: un pedido genera una sola factura.
- **Devoluciones o reembolsos** del pedido.
- **Impresión térmica directa** del ticket. El ticket se comparte como imagen; imprimirlo en una
  térmica requiere driver o app intermedia y no se pidió.
- **Complemento de pago** para pedidos: como el timbrado ocurre con el pedido ya pagado (PUE), no
  hay saldo fiscal pendiente que complementar.

## Estado de implementación

**Primera versión implementada el 2026-08-14.** Backend, frontend y pruebas completos; `php artisan
test` en verde (538 pruebas), `pint` sin cambios, `npm run build`, `npm run lint` y `vitest` en
verde.

**Revisión del 2026-08-16 implementada el 2026-08-16.** `php artisan test` en verde (543 pruebas),
`pint` sin cambios, `npm run build`, `npm run lint` y `vitest` en verde. El ticket con su QR se
revisó como imagen, no solo por sus medidas.

**El bloqueo de venta sin existencia (revisión del 2026-08-26, sección "Inventario" de arriba) está
implementado**, junto con la tabla `existencias` de [017](017-inventario.md).

### Decisiones tomadas durante la implementación de la revisión

- **`pedido_pagos.automatico` se renombró en vez de eliminarse.** La spec decía borrarla, y al
  implementar el `422` de `deshacer-entrega` quedó claro que sin ella no hay forma exacta de saber
  si una entrega cobró: compararla contra `entregado_en` es frágil porque las dos marcas de tiempo
  caen en el mismo segundo. `registrado_al_entregar` dice lo que el dato significa ahora.
- **`deshacer-entrega` ya no toca Tesorería en absoluto.** Al rechazar las entregas que cobraron, el
  único caso que queda es el que no movió dinero, así que el endpoint se reduce a limpiar
  `entregado_en` y recalcular el estado. El riesgo de dejar pesos fantasma en la caja desaparece de
  raíz en vez de resolverse con cuidado.
- **Los dos mensajes salieron de `TicketPedidoService` a `MensajePedidoService`.** Rellenar una
  plantilla con los datos de un pedido no tiene que ver con dibujar una imagen, y con el aviso de
  "ya está listo" pasaron a ser dos.
- **Un solo componente de mensaje en el frontend** (`MensajePedidoForm`), con la clave por
  propiedad, en lugar de dos casi idénticos: los dos guardan un `textarea` contra el mismo almacén
  y comparten la lista de huecos.
- **`QrTimbreFiscal` ganó `imagenPng()`**, que devuelve los bytes crudos. El ticket no incrusta el
  código en un HTML sino que lo copia sobre su lienzo con GD, y un data URI habría que deshacerlo
  antes de poder leerlo.
- **El QR se reduce con vecino más cercano** (`IMG_NEAREST_NEIGHBOUR`). El escalado normal
  interpola, y un código con los bordes suavizados es un código que el lector rechaza.
- **El ticket nunca falla por su QR**, mismo criterio que el logo: si algo sale mal se dibuja sin
  código y con el número de ticket, que es con lo que se busca el pedido a mano.

### Decisiones tomadas durante la implementación de la primera versión

- **`MotivoMovimientoInventario::CorreccionPedido`**, un motivo automático que la spec no había
  previsto. Editar o borrar un pedido tiene que devolver al inventario lo que descontó el alta, y
  reusar `VentaPedido` para la entrada dejaría un historial donde el mismo motivo significa cosas
  opuestas según el tipo de movimiento. Sin él, un pedido editado tres veces habría descontado tres
  veces la misma mercancía.
- **`ClaveConfiguracion::reglas()` ahora declara también la obligatoriedad.** El almacén de 014 solo
  tenía claves numéricas y `ConfiguracionController` anteponía `required` a todas. El mensaje del
  ticket es la primera clave de texto y **sí puede quedar vacío** —el usuario puede querer mandar
  solo la imagen—, así que la regla se mudó al enum, donde depende de la clave y no del endpoint.
  Lleva `present` + `nullable`, porque Laravel convierte la cadena vacía en `null` antes de validar.
- **`regimenes-fiscales` y `usos-cfdi` salieron del grupo autenticado.** El portal público necesita
  esas dos listas para que el cliente llene sus datos fiscales. No revelan nada del negocio: son las
  mismas listas que el SAT publica, servidas desde `phpcfdi/sat-catalogos` sin tocar la base de
  datos. Los otros siete catálogos siguen detrás del login. Van con `throttle:60,1`, más holgado que
  el `20,1` de autofactura, porque el buscador de uso de CFDI hace una petición por búsqueda
  mientras el cliente escribe.
- **El portal público pide la cookie CSRF antes de enviar** (`ensureCsrfCookie()`). El grupo `api`
  lleva `EnsureFrontendRequestsAreStateful`, y quien abre el enlace llega sin cookie.
- **`DocumentoLineas` admite `articulo_id: null`** y gana la bandera `permiteLineaLibre`. Los
  payloads de factura, cotización y orden de compra se ampliaron a `number | null` por el tipo
  compartido; en esos tres documentos el buscador siempre pone un id y el backend lo exige, así que
  el comportamiento no cambia.
- **La lógica de compartir se extrajo a `src/lib/compartir.ts`**, que ahora sostiene los tres casos:
  imagen + texto por el menú del sistema, descarga + portapapeles en escritorio, y texto suelto por
  `wa.me`. La ficha de artículo de 020 conserva su propia versión, que hace algo distinto en
  escritorio (no descarga) y convierte WEBP a JPEG.
- **El guard del router recuerda el destino** (`?redirect=`) y el login regresa ahí, aceptando solo
  rutas internas. Sin esto, escanear el QR sin sesión abierta dejaba al usuario en el dashboard
  teniendo que buscar a mano el pedido que acababa de escanear.
- **`eliminarPago` no lleva la regla LIFO de 008.** Allá el monto de "saldo" se autocalcula a partir
  de los pagos previos y borrar uno intermedio dejaría a los posteriores describiendo un saldo que
  ya no existía; aquí cada pago lleva su propio monto capturado y ninguno depende de otro. Es además
  la vía por la que se corrige un cobro mal capturado al entregar.

**Costo capturado por línea, implementado el 2026-08-25** (ver
[010](010-tesoreria.md)). `costo_unitario` se agregó por migración a `pedido_lineas` (nullable) y
`PedidoController::guardarLineas()` lo llena de una sola consulta por artículo, igual que en
Cotización (008); una línea libre (`articulo_id = null`) siempre queda con `costo_unitario = null`.
No hay migración de datos para los pedidos ya existentes. Cubierto por tests nuevos en
`TesoreriaTest.php` (668 tests del backend, todos en verde); Pint limpio.

### Lo que no se pudo verificar en vivo

- **El timbrado real de una autofactura**: no hay credenciales de Facturapi en este entorno (misma
  situación que 007 y 008). Las pruebas mockean `FacturapiService`.
- **El menú de compartir del sistema operativo** y la impresión de la etiqueta en una impresora de
  etiquetas: requieren un aparato real.
- **Las claves SAT genéricas de las líneas libres** (`01010101` / `H87`) no se han probado contra el
  SAT en un timbrado real.

## Criterios de aceptación

1. Se levanta un pedido capturando nombre, teléfono y correo, sin RFC, sin tocar el catálogo de
   Clientes.
2. Al pedido se le agregan artículos del catálogo y líneas libres escritas a mano, con cantidad y
   precio editables.
3. Al guardarlo se descuentan existencias solo de las líneas con artículo, y el folio es consecutivo
   e independiente de facturas y cotizaciones. Si algún artículo no tiene existencia (o nunca se marcó
   "en existencias" en [017](017-inventario.md)), el pedido no se guarda y se señala la línea; vender
   por arriba de lo disponible sigue permitido y deja faltante pendiente.
4. Se registra un anticipo con su cuenta y el pedido pasa a `anticipo`; el movimiento aparece en
   Tesorería y mueve el saldo de la cuenta.
5. El detalle muestra el ticket dibujado por el servidor, con el saldo pendiente correcto y **el QR
   al pie**, grande y con el no. de ticket debajo. Sin ningún pago registrado no se ofrece el botón
   "Compartir ticket"; en cuanto entra el primer pago aparece y manda imagen y mensaje en celular, y
   descarga la imagen y copia el mensaje en escritorio.
6. El mensaje sale de Configuración con los huecos ya resueltos.
7. **Pedir el ticket dos veces no deja ningún archivo en el servidor**, y el ticket de un pedido al
   que se le acaba de registrar un abono sale con el saldo nuevo sin que nadie lo regenere.
8. "Imprimir etiqueta" abre una etiqueta de 5 × 2.5 cm con el QR a la izquierda y, a la derecha,
   nombre, teléfono, no. de ticket y `SALDO: $250.00` o `PAGADO`.
9. Escanear el QR —el de la etiqueta o el del ticket en la pantalla del cliente— con un pedido
   **totalmente pagado** lo cierra solo, sin preguntar, y ofrece "Deshacer" durante 10 segundos.
10. Escanear un pedido **con saldo pendiente** no cobra nada hasta confirmar: muestra cliente,
    total, pagado y saldo, exige elegir cuenta, y un solo botón "Cobrar y entregar" registra el
    saldo completo y cierra el pedido. Después no ofrece "Deshacer".
11. Escanear dos veces no cobra dos veces, y escanear un pedido ya entregado solo dice cuándo se
    entregó.
12. Con al menos un pago, "Avisar que está listo" abre WhatsApp con el mensaje de Configuración ya
    resuelto con el nombre, el folio y el saldo, y **no cambia el estado del pedido**.
13. Al quedar pagado, el detalle ofrece el enlace de autofactura y el botón que abre WhatsApp
    Desktop con el mensaje listo.
14. Abriendo ese enlace sin sesión, el cliente captura sus datos fiscales y obtiene su factura
    timbrada, que le llega por correo; el pedido queda ligado a ella y no descuenta inventario otra
    vez.
15. Un timbrado fallido le explica el motivo al cliente en español, le deja reintentar, y marca el
    pedido en el listado del usuario.
16. Cambiar el número del enlace de autofactura a mano no lleva a ningún otro pedido.
17. El enlace deja de funcionar al terminar el mes de la venta y cuando el pedido ya se facturó.
18. El total del pedido queda en peso cerrado ([030](030-total-al-peso-cerrado.md)): tres piezas de
    un artículo de $204.00 con IVA dan $612.00, el ticket imprime `3 x $204.00` arriba de ese total,
    y la factura que el cliente se genera en el portal de autofacturación dice exactamente el mismo
    número.
19. Ninguna pantalla del sistema pide datos de producción, y `php artisan test`, `pint`,
    `npm run build`, `npm run lint` y `vitest` corren en verde.
20. Un pedido cuyas líneas tienen todas artículo de catálogo expone en Tesorería ([010](010-tesoreria.md))
    la utilidad de venta completa; un pedido con alguna línea libre la expone marcada como parcial,
    porque esa línea no tiene costo conocido.

## Supuestos asumidos (registro completo)

Confirmados uno por uno con el usuario antes de redactar. Los que cambiaron en la revisión del
2026-08-16 van marcados como **(Redefinido)**, con la regla vieja anotada para que se entienda qué
se movió y por qué.

**Qué es este documento**

1. La venta de mostrador es un documento nuevo, "Pedido", con listado y folio propios — no un estado
   ni una variante de Cotización.
2. El "No. de ticket" de la etiqueta es el folio del pedido.
3. En el sistema se captura primero el pedido y al final el pago; que el cliente pague físicamente
   antes no cambia el orden de las pantallas.

**El cliente de mostrador**

4. Nombre, teléfono y correo se capturan dentro del pedido, sin RFC, sin dar de alta nada en el
   catálogo de Clientes.
5. Nombre y teléfono obligatorios, correo opcional. **El teléfono no se usa para mandar WhatsApp** —
   eso lo resuelve el botón compartir; sirve para identificar al cliente y para la etiqueta.
6. Si el teléfono coincide con el de una venta anterior, el sistema ofrece rellenar nombre y correo.

**Los artículos**

7. Además de los artículos del catálogo, se pueden agregar **líneas libres a mano** (descripción,
   cantidad y precio). No tocan inventario ni se guardan en el catálogo.
8. Cantidad y precio son editables línea por línea.
9. Aplica el descuento global; no aplica el descuento permanente de cliente.
10. La venta descuenta existencias al crear el pedido, no al entregarlo.
11. Un artículo sin existencia (existencia `0` o sin fila en `existencias`) no se puede vender en un
    Pedido: el sistema lo rechaza al guardar, a diferencia de Factura y Cotización, que nunca
    bloquean.

**El pago**

11. Los pagos usan la misma mecánica de Tesorería que los de cotización: monto, cuenta destino y su
    movimiento.
12. No hay porcentaje mínimo de anticipo.
13. Se pueden registrar varios pagos en el mismo pedido.
14. Estados: `pendiente` → `anticipo` → `pagado` → `entregado`.

**El ticket**

15. Imagen JPG vertical estilo tira de punto de venta, no PDF.
16. **(Redefinido)** Contiene datos del negocio, no. de ticket, fecha y hora, cliente, líneas con su
    precio unitario **con IVA**, total en peso cerrado ([030](030-total-al-peso-cerrado.md)),
    pagado, saldo **y el código QR**, al pie, centrado y grande. (La versión original decía
    "sin QR": el código vivía solo en la etiqueta, con el argumento de que el cliente no cierra su
    propio pedido. Lo que ese argumento pasaba por alto es que el cliente **llega con el ticket en
    el celular y sin la caja**, y ahí el QR de su pantalla es lo único que hay para cerrar la
    venta.)
17. **(Redefinido)** El ticket **no se guarda en ningún lado**: se dibuja cada vez que se pide y se
    desecha. Úsese y tírese. (La versión original lo guardaba en el disco y lo invalidaba cuando
    cambiaba el saldo. A diez tickets diarios eso son ~200 MB al año que nunca dejan de crecer, para
    almacenar algo que se puede volver a dibujar idéntico en milésimas de segundo.)
18. El botón compartir sigue el patrón de la ficha de artículo: celular, menú del sistema con imagen
    y texto; escritorio, descarga del JPG y copia del mensaje. **Solo se ofrece a partir del primer
    pago**, sea anticipo o pago completo: el ticket sale hacia el cliente cuando hay dinero que
    comprobar.
19. El mensaje que acompaña al ticket es editable desde Configuración.

**La etiqueta**

20. Documento aparte del ticket, se imprime desde el navegador.
21. **(Redefinido)** Lleva nombre, teléfono, no. de ticket, QR **y el saldo** (`SALDO: $250.00` o
    `PAGADO`). El QR ocupa un cuadro de 20 mm a la izquierda y el texto los ~28 mm restantes. (La
    versión original prohibía importes porque "la etiqueta pasa por manos que no tienen por qué ver
    lo que se cobró". En un taller de una persona no hay manos ajenas, y ver la caja y saber si hay
    que cobrar sin abrir el sistema vale más que ese pudor.)
22. Tamaño fijo de 5 × 2.5 cm.
23. El QR apunta a la pantalla del pedido dentro del sistema, protegida por login.
24. Se escanea con la cámara nativa del celular; no se construye un lector propio. **El formato del
    QR se elige para que el escáner de la aplicación instalada de [029](029-pwa-mostrador.md) pueda
    leerlo y cerrar el pedido en vivo** — esa aplicación está fuera de alcance aquí, y los dos
    caminos llegan a la misma pantalla.

**La entrega**

25. **(Redefinido)** El escaneo se resuelve **según el saldo**: con el pedido totalmente pagado
    cierra solo, sin preguntar, y ofrece "Deshacer" 10 segundos; **con saldo pendiente pide
    confirmación**, mostrando cliente, total, pagado y saldo, y no toca nada hasta que el usuario
    aprieta "Cobrar y entregar". (La versión original cobraba y entregaba sola en los dos casos.)
26. **(Redefinido)** La cuenta a la que entra el dinero **se elige siempre, sin preselección**, y el
    botón queda deshabilitado hasta escogerla. (La versión original asumía la cuenta de efectivo más
    antigua. Elegir siempre evita justo el error que esa comodidad invita: confirmar por inercia y
    mandar a la caja un dinero que llegó por transferencia.)
27. **(Redefinido)** Se cobra **el saldo completo** y el monto no se edita: la entrega cierra el
    pedido y no puede quedar debiendo. Quien quiera cobrar de menos registra el abono desde el
    detalle.
28. **(Redefinido)** El "Deshacer" existe **solo en el camino que cierra sin preguntar**. En el que
    pide confirmación no hace falta, y un pago mal capturado se corrige desde el detalle del pedido
    como cualquier otro.
29. Reescanear un pedido ya entregado **solo informa** de quién era y cuándo se entregó; no cobra ni
    cierra por segunda vez.
30. El enlace del QR requiere sesión; sin ella manda al login y regresa al pedido.

**La fabricación**

31. **El sello se elabora fuera del sistema.** No hay órdenes de trabajo, dibujos del diseño,
    colores de tinta, aprobación del cliente ni estados de producción. Se construyó un módulo para
    esto y se descartó por sobredimensionado para un taller de una persona que hace unos diez sellos
    diarios.
32. **El sistema no sabe cuándo un pedido está listo**, así que no avisa solo.
33. Lo que sí hace es **redactar el aviso**: el botón "Avisar que está listo" abre WhatsApp con el
    texto ya resuelto, lo dispara el usuario cuando el trabajo lo está, y **no cambia ningún
    estado**.
34. Ese texto es **editable desde Configuración**, con los mismos huecos que el mensaje del ticket.
    Editable y no fijo en el código porque es un mensaje al cliente y el tono lo pone el negocio.

**La autofactura**

35. Enlace público, sin login, donde el cliente captura sus datos fiscales y el sistema timbra la
    factura del pedido con los mismos importes.
36. El enlace solo se habilita con el pedido totalmente pagado.
37. Vence el último día del mes de la venta.
38. Un pedido genera una sola factura; timbrada, el enlace deja de funcionar.
39. Al facturar, el cliente sí queda dado de alta en el catálogo de Clientes y se le manda la
    factura por correo.
40. El enlace se comparte con un botón que en Windows abre WhatsApp Desktop con el texto y el enlace
    prellenados.

**Transversal**

41. Nada se envía automáticamente: ticket, mensaje, aviso y enlace siempre los dispara el usuario.

**Adiciones técnicas aceptadas**

42. El ticket lo dibuja el **servidor**, para que salga idéntico desde cualquier aparato — incluido
    su código QR.
43. El mensaje admite **huecos que se rellenan solos**: `{nombre}`, `{folio}`, `{total}`,
    `{pagado}`, `{saldo}`.
44. El enlace de autofactura lleva una **clave larga al azar** de 64 caracteres, imposible de
    adivinar.
45. **Candado contra el doble escaneo.**
46. Si falla el timbrado: **motivo en español al cliente con reintento**, y **señal del fallo en el
    listado** del usuario.
47. El **QR del ticket mide 300 px** sobre un ancho de 576, porque el peor escenario de ese código
    es leerse desde la pantalla de un celular, que brilla y refleja.
48. En la etiqueta, **el QR va a la izquierda en 20 mm** y el texto a la derecha, aprovechando que
    50 × 25 mm es más ancho que alto.
49. **La pantalla de entrega funciona igual** por la cámara del celular que por el escáner de 029.

**Decisiones de detalle tomadas al redactar** (no se consultaron una por una; se documentan para que
puedan corregirse antes de implementar)

50. **La ventana del "Deshacer"** son 10 segundos en el botón y 5 minutos en el backend, para que un
    "Deshacer" disparado desde una pestaña olvidada no revierta mañana una entrega legítima.
51. **Las líneas libres se timbran** con `clave_prod_serv` `01010101` y `clave_unidad` `H87`, porque
    el payload de Facturapi toma esas claves del artículo y una línea libre no tiene.
52. **La forma de pago del CFDI** se deriva del tipo de cuenta del último pago (`efectivo` → 01,
    `banco` y `digital` → 03, `otro` → 99); el cliente no la captura.
53. **El método de pago del CFDI es siempre PUE**, porque el enlace solo existe con el pedido
    totalmente pagado.
54. **La fuente del ticket** (`DejaVuSansMono`) se copia a `backend/resources/fonts/` en vez de
    leerse desde `vendor/dompdf`, para no atarse a la estructura interna de una dependencia.
55. **Las rutas públicas** (`autofactura/{token}`) llevan `throttle:20,1`: son las únicas del
    sistema que cualquiera en internet puede llamar.
56. **La columna `pedido_pagos.automatico` se renombra a `registrado_al_entregar`.** Nombraba un
    cobro que el sistema hacía sin preguntar y ese cobro ya no existe, pero el dato sí: distingue el
    pago que cerró la venta del que se capturó en el mostrador. Es lo que nombra el movimiento en
    Tesorería y lo que le permite a `deshacer-entrega` rechazar una entrega que cobró.
57. **`deshacer-entrega` rechaza las entregas que cobraron.** Así el endpoint nunca tiene que
    revertir un movimiento de Tesorería, que es donde estaba el riesgo de dejar dinero fantasma en
    la caja.
58. **El monto de la entrega no viaja en la petición**: lo calcula el backend como el saldo exacto,
    para que un frontend manipulado no pueda cerrar un pedido cobrando de menos.

**La utilidad de venta ([010](010-tesoreria.md))**

59. Cada línea con artículo guarda su `costo_unitario` (copia del costo con descuento del artículo al
    momento de guardarse la línea); una línea libre nunca lo tiene, por no tener artículo del que
    tomarlo.
60. **(Adición técnica)** Los pedidos creados antes de esta ampliación quedan con `costo_unitario`
    nulo en todas sus líneas: no hay migración que lo reconstruya con el costo actual del artículo,
    porque ya no sería el mismo costo que tenía el día de la venta.
59. **El nombre se recorta con puntos suspensivos en la etiqueta** antes que romper el renglón: un
    renglón partido empuja al saldo fuera de los 25 mm.
60. **"Avisar que está listo" aparece a partir del primer pago**, igual que "Compartir ticket". No
    es una regla que el usuario pidiera: sale de que un pedido sin ningún pago todavía no es un
    trabajo en curso, y avisar que está listo algo que nadie ha confirmado con dinero invita a
    entregarlo sin cobrar. Si estorba, quitar la condición no rompe nada.
