# Spec: Órdenes de compra (generación, envío al proveedor, pago de contado y recepción)

## Historia de usuario

Como usuario registrado, quiero generar órdenes de compra a mis proveedores, con el mismo estilo de
una cotización, enviarlas por correo y tener la opción de enviarlas por WhatsApp.

## Objetivo / Alcance

Implementar el módulo de Órdenes de compra sobre la base ya existente de Laravel API + Vue 3 SPA +
Sanctum (ver [001](001-inicio-proyecto.md), [002](002-login-auth.md)), el design system de
[003](003-design-system-tailwind.md), el catálogo de [Proveedor](005-gestion-proveedores.md), los
[Artículos](006-gestion-articulos.md) con su [costo](011-precio-proveedor-utilidad.md) y sus
[catálogos por proveedor](009-catalogos.md), y conectado a [Tesorería](010-tesoreria.md).

Incluye: captura de la orden (proveedor + líneas de artículo, mismo esquema de línea que
[Cotizaciones](008-cotizaciones.md)), envío al proveedor por correo o WhatsApp, un ciclo de estados
propio (`borrador` → `enviada` → `pagada` → `recibida`), pago **de contado** que genera un egreso
automático en Tesorería, duplicación de órdenes, y la activación real de la validación de borrado de
proveedores que 005 dejó preparada.

La orden de compra es el documento espejo de la cotización: donde la cotización le dice a un cliente
"esto te vendo, a este precio de venta", la orden le dice a un proveedor "esto te compro, a este
costo". La aritmética es idéntica; lo que cambia es de qué lado del negocio está el dinero.

**No** incluye: inventario o existencias, recepción parcial por línea, timbrado de ningún tipo,
captura del CFDI que el proveedor te emite a ti, cuentas por pagar formales, ni multiempresa.

## Backend (Laravel)

### Modelo `OrdenCompra`

- Pertenece a un `User` (`user_id`) y a un `Proveedor` (`proveedor_id`, obligatorio, debe pertenecer
  al mismo usuario).
- `folio`: entero autoincremental **por usuario**, numeración propia e independiente del `folio` de
  `Factura` y de `Cotizacion`. Se presenta al usuario como `OC-00015`.
- **Estado** (`estado`, string/enum): `borrador` → `enviada` → `pagada` → `recibida`. Transición
  estrictamente secuencial, sin retroceso automático salvo los dos casos descritos abajo.
  - `borrador`: recién creada o recién editada.
  - `enviada`: se envió al menos una vez al proveedor por correo o WhatsApp.
  - `pagada`: se registró el pago de contado (ver "Pago de contado").
  - `recibida`: marcado manualmente por el usuario, solo alcanzable desde `pagada`.
  - Editar una orden que está en `enviada` la regresa a `borrador` (obliga a reenviarla
    explícitamente para notificar el cambio al proveedor).
  - Cancelar el pago de una orden `pagada` la regresa a `enviada`.
- `fecha_entrega_esperada`: date, **opcional**. Informativa, se imprime en el PDF; no dispara
  ninguna alerta, recordatorio ni cambio de estado.
- `observaciones`: text, **opcional**. Texto libre dirigido al proveedor (condiciones de entrega,
  referencias, instrucciones), impreso en el PDF.
- **Descuento global** y **totales** (`subtotal`, `total_descuento`, `total_iva_16`, `total_iva_0`,
  `total_exento`, `total`): mismo modelo y mismo algoritmo de cálculo (dos pasadas, prorrateo del
  descuento global entre líneas antes del IVA) que `Factura`/`Cotizacion`, reutilizando
  `FacturaTotalesCalculator` (ya opera sobre arreglos genéricos de líneas, según el registro de
  implementación de 008). **Una orden de compra no redondea su total**: paga lo que cobra el
  proveedor, así que llama al calculador sin el ajuste al peso de
  [030](030-total-al-peso-cerrado.md) —el default— y no tiene columna `ajuste_al_peso`. Lo mismo
  aplica a la orden de reabastecimiento que genera Inventario ([017](017-inventario.md)). Recalculados siempre en backend, nunca persistidos tal cual
  los mande el frontend: `422` si el `total` enviado no coincide con el recalculado, mismo criterio
  que 007/008.
- **Pago** (ver "Pago de contado"): `cuenta_id` (FK nullable a `Cuenta` de 010) y `fecha_pago` (date,
  nullable). Ambos en `null` significa que la orden no está pagada; ambos con valor, que sí lo está.
  No hay tabla de pagos: el pago es único y por el total de la orden.
- Sin soft delete: solo se permite `DELETE` físico mientras el estado es `borrador`.

#### El IVA de una orden de compra

El IVA de la orden es el que **te cobra el proveedor** (IVA acreditable), no el que trasladas a un
cliente. Como el sistema no lleva contabilidad fiscal de IVA por pagar contra IVA acreditable, esa
distinción no cambia ninguna fórmula: la aritmética es idéntica a la de 007/008 y lo único que
cambia son las etiquetas del PDF. Queda registrado aquí para que no se interprete como un descuido.

### Modelo `OrdenCompraLinea`

Mismo esquema que `CotizacionLinea` de 008: `orden_compra_id`, `articulo_id` (propio del usuario),
`cantidad` (entero, mínimo 1), `descripcion` y `modelo` (precargados del artículo, editables
in-place, copias desacopladas del catálogo), `precio_unitario` (mayor a 0),
`descuento_tipo`/`descuento_valor` (opcional, `porcentaje`|`monto`), `tasa_iva` (`16`|`0`|`exento`),
`importe` e `iva_importe` (calculados en backend, `importe` es el neto de línea sin IVA).

La única diferencia con la línea de cotización está en **de dónde se precarga el precio**:

- `precio_unitario` se precarga con el **`costo_con_descuento`** del artículo ([011](011-precio-proveedor-utilidad.md)),
  es decir, el precio de lista del proveedor ya con el descuento de su catálogo aplicado — lo que
  efectivamente le pagas. **No** se precarga con `precio_unitario_sin_iva`, que es el precio de venta
  al cliente.
- El valor precargado es **editable**, porque el proveedor puede cotizarte distinto ese día.
- Editar ese precio **no modifica** el `precio_proveedor` del artículo ni dispara el recálculo de
  precios de venta de 011. La orden es un documento independiente del catálogo; el catálogo se
  actualiza desde la pantalla de Artículos, no como efecto colateral de una compra.

### Filtrado de artículos por proveedor

Al elegir el proveedor de la orden, el selector de artículos ofrece **únicamente los artículos que
pertenecen a catálogos de ese proveedor** (009 ya liga `Catalogo` → `Proveedor`). Cambiar el
proveedor de una orden que ya tiene líneas obliga a confirmar el vaciado de las líneas existentes,
porque dejarían de corresponder al proveedor seleccionado.

`GET /api/v1/articulos` acepta para esto un parámetro `?proveedor_id=` que filtra por el proveedor
del catálogo del artículo. Es el único cambio de esta historia sobre el módulo de Artículos.

### Envío al proveedor

El mecanismo de envío por correo y WhatsApp que 008 escribió acoplado a `Cotizacion` se
**generaliza** para servir a los dos documentos, en vez de duplicarse (ver "Generalizaciones sobre
módulos existentes"):

- **Correo**: genera el PDF de la orden al vuelo (sin persistirlo, mismo criterio que 007/008) y lo
  adjunta a un mailable enviado vía Mailpit en desarrollo, con el correo del proveedor
  (`Proveedor.correo`) prellenado y editable.
- **WhatsApp (vía Twilio)**: mensaje de WhatsApp al teléfono del proveedor (prellenado desde
  `Proveedor.telefono`, que 005 ya almacena normalizado en E.164, editable) con un resumen de la
  orden y el PDF adjunto como media. Igual que en 008, Twilio requiere una URL **pública** para
  descargar el adjunto, así que se expone una ruta de PDF firmada y temporal, fuera de
  `auth:sanctum`, exclusivamente para que Twilio la consuma al enviar.
- Cualquiera de los dos canales, al enviarse con éxito, dispara la transición `borrador` → `enviada`
  (o confirma que sigue `enviada` si ya lo estaba).
- **Las credenciales de Twilio siguen sin configurarse en este entorno** (`TWILIO_ACCOUNT_SID`,
  `TWILIO_AUTH_TOKEN`, `TWILIO_WHATSAPP_FROM` vacías desde 008). El envío por WhatsApp lanzará una
  excepción en tiempo de ejecución hasta que se configuren; el envío por correo no se ve afectado.
  Los tests mockean el servicio de Twilio, así que la suite no depende de esas credenciales.

### PDF de la orden

Reutiliza la plantilla y los tokens de diseño del PDF de cotización (003/007/008), con tres
diferencias: encabezado "Orden de compra", el bloque de datos del cliente se sustituye por el del
proveedor, y se imprimen la fecha esperada de entrega y las observaciones cuando existen. Se genera
al vuelo, sin persistir copia.

### Pago de contado

El pago a proveedores es **siempre de contado**: un solo pago, por el total de la orden. No existen
anticipos, parcialidades, saldo pendiente, historial de pagos ni sobrepago. Por eso el pago vive en
las columnas `cuenta_id`/`fecha_pago` de la propia orden y no en una tabla aparte — a diferencia de
`CotizacionPago` (008), que sí necesita historial porque un cliente paga en tiempos.

- `POST /api/v1/ordenes-compra/{id}/pagar` — body `{ cuenta_id, fecha_pago }`. Solo permitido desde
  el estado `enviada`.
- El **monto es siempre el `total` de la orden**, tomado del servidor. No se acepta un monto en el
  body; si se envía, se ignora en silencio (mismo patrón que los valores derivados de 011).
- Dentro de una sola transacción: se registra el pago en la orden, se crea un `Movimiento` de
  **`tipo = egreso`** en Tesorería sobre la cuenta elegida, y la orden pasa a `pagada`.
- El `Movimiento` se crea con `fecha` = la `fecha_pago` capturada, `documentable` = la propia
  `OrdenCompra` (la relación polimórfica que 010 dejó lista para esto), y `concepto` generado por el
  sistema y no editable: **`"Pago de Orden de compra OC-00015"`**.
- **Regla de saldo no negativo**: si el egreso dejaría la cuenta por debajo de 0, la petición se
  rechaza con `422` y el mensaje de 010 ("El movimiento dejaría la cuenta con saldo negativo"), y la
  orden se queda en `enviada`. Esta regla existía desde 010 pero nunca se activaba, porque los pagos
  de cotización son ingresos; con las órdenes de compra pasa a ser una condición real de la
  operación diaria.
- La cuenta debe pertenecer al usuario y estar **activa** (`422` si está inactiva), igual que
  cualquier otro movimiento de 010.

#### Cancelar el pago

`DELETE /api/v1/ordenes-compra/{id}/pago` — revierte el pago de una orden `pagada`. Dentro de una
sola transacción: elimina el `Movimiento` de egreso, recalcula el `saldo_actual` de la cuenta, limpia
`cuenta_id`/`fecha_pago` y regresa la orden a `enviada` (con lo que vuelve a ser editable).

No se permite si la orden ya está `recibida` (`422`): primero tendría que revertirse la recepción, lo
cual queda fuera del alcance de esta historia. Es el equivalente, para el pago único de contado, de
la eliminación LIFO del último `CotizacionPago` que 010 definió para las cotizaciones.

### Recepción de la mercancía

> **Superado por [017](017-inventario.md).** Desde esa historia el sistema sí lleva inventario y
> recibir una orden **suma sus cantidades a las existencias**. Lo que sigue describe el
> comportamiento original; la recepción sigue siendo total, manual, irreversible y solo desde
> `pagada`, pero ya no es inocua.

`POST /api/v1/ordenes-compra/{id}/recibir` — marca la orden como `recibida`. Solo permitido desde
`pagada`. Es una acción manual del usuario, sin validación de cantidades, sin recepción parcial y sin
ningún efecto sobre existencias: el sistema no lleva inventario.

### Duplicar orden

`POST /api/v1/ordenes-compra/{id}/duplicar` — crea una copia nueva con el mismo `proveedor_id`,
líneas, descuento global y observaciones, con `folio` nuevo, `estado = borrador`, sin pago
(`cuenta_id`/`fecha_pago` en `null`) y sin `fecha_entrega_esperada`.

### Edición y borrado

- La orden es **libremente editable mientras no esté `pagada`**: en `borrador` y en `enviada` se
  pueden modificar proveedor, líneas, precios unitarios, descuentos, fecha esperada y observaciones.
  Editar una orden `enviada` la regresa a `borrador`.
- En `pagada` y `recibida` la orden queda bloqueada (`422` en el `PUT`). La vía para corregir una
  orden pagada es cancelar el pago, lo que la regresa a `enviada` y la vuelve editable.
- El `DELETE` físico solo se permite en `borrador`.

### Endpoints (bajo `auth:sanctum`, scopeados al usuario autenticado, salvo el de PDF público)

- `GET /api/v1/ordenes-compra` — listado paginado. Filtros combinables por columna: `?proveedor=`,
  `?rfc=`, `?folio=`, `?estado=`, y por fecha (`?fecha_desde=`/`?fecha_hasta=`, con atajos de UI para
  "Hoy"/"Esta semana"/"Este mes"; valor por defecto al cargar la pantalla: "Este mes"). El rango de
  fecha se interpreta como el día calendario completo en la zona horaria del negocio
  (`America/Mexico_City`, fija), convertido a UTC antes de comparar contra `created_at`, mismo
  criterio que 008/010.
- `POST /api/v1/ordenes-compra` — crea la orden en `borrador` (recalcula totales en backend, `422` si
  no coincide con lo enviado por el frontend).
- `GET /api/v1/ordenes-compra/{id}` — detalle (incluye líneas, proveedor y cuenta de pago si existe).
- `PUT /api/v1/ordenes-compra/{id}` — edición; permitida solo si el estado es `borrador` o `enviada`
  (`422` si `pagada`/`recibida`); si estaba `enviada`, la deja en `borrador`.
- `DELETE /api/v1/ordenes-compra/{id}` — solo si `borrador`; borrado físico.
- `POST /api/v1/ordenes-compra/{id}/enviar` — body `{ canal: correo|whatsapp, destinatarios? o
  telefono? }`; dispara el envío y la transición de estado.
- `GET /api/v1/ordenes-compra/{id}/pdf-publico` — sin autenticación, middleware `signed` con URL
  temporal de vida corta, exclusivamente para que Twilio descargue el PDF al enviar el WhatsApp.
- `POST /api/v1/ordenes-compra/{id}/pagar` — ver "Pago de contado".
- `DELETE /api/v1/ordenes-compra/{id}/pago` — ver "Cancelar el pago".
- `POST /api/v1/ordenes-compra/{id}/recibir` — marca `recibida`; solo si el estado actual es
  `pagada`.
- `POST /api/v1/ordenes-compra/{id}/duplicar` — ver arriba.
- `GET /api/v1/ordenes-compra/{id}/pdf` — genera el PDF al vuelo, sin persistir copia.

**Pluralización española**: el modelo declara `protected $table = 'ordenes_compra'` y la ruta usa
`->parameters(['ordenes-compra' => 'orden_compra'])` desde el primer commit. Sin eso, Eloquent
infiere la tabla `orden_compras` y Laravel genera un parámetro de ruta mal singularizado que rompe el
binding implícito de modelo (404 donde debería haber 200 o 422). Es la lección ya pagada dos veces en
005 y 008; aquí se aplica de entrada en vez de esperar a que falle.

### Validaciones (Form Requests)

- `proveedor_id`: requerido, existe y pertenece al usuario autenticado.
- `lineas`: array, mínimo 1 elemento; mismas reglas de `articulo_id`/`cantidad`/`descripcion`/
  `modelo`/`precio_unitario`/`descuento_tipo`/`descuento_valor`/`tasa_iva` que en 008. Además, cada
  `articulo_id` debe pertenecer a un catálogo del proveedor seleccionado (`422` si no).
- `descuento_global_tipo`/`descuento_global_valor`: opcionales, mismas reglas que a nivel línea.
- `fecha_entrega_esperada`: opcional, formato fecha.
- `observaciones`: opcional, string.
- Envío: `canal` requerido (`correo`|`whatsapp`); si `correo`, `destinatarios` array con al menos 1
  email válido; si `whatsapp`, `telefono` requerido.
- Pago: `cuenta_id` requerido, existe, pertenece al usuario y está **activa**; `fecha_pago`
  requerida. Cualquier `monto` enviado se ignora. La regla de saldo no negativo se evalúa **dentro de
  la transacción, con la cuenta ya bloqueada**, siguiendo el protocolo de 010.
- Respuestas mediante Laravel API Resources (`OrdenCompraResource`, `OrdenCompraLineaResource`),
  consistente con la convención de 001/004/005/006/007/008/009/010.

### Generalizaciones sobre módulos existentes

Esta historia toca código que ya funciona en tres puntos. En los tres, el criterio es el mismo:
generalizar una vez en lugar de crear una tercera o segunda copia que mantener sincronizada.

#### Servicio de envío de documentos (extiende 008)

Se extrae el mecanismo de envío que hoy está acoplado a `Cotizacion` —el mailable, el
`TwilioWhatsAppService`, y la ruta de PDF público firmada— a una pieza común: un contrato que
implementan `Cotizacion` y `OrdenCompra` (produce un PDF, expone un destinatario por defecto de
correo y de teléfono, y un asunto/resumen), un mailable parametrizado, y una ruta de PDF público
equivalente para la orden. El comportamiento observable de 008 no cambia; sus tests existentes son la
red que lo verifica.

#### Servicio de movimientos de Tesorería (extiende 010)

010 dejó genérica la relación polimórfica `documentable` de `Movimiento`, pero la lógica que crea el
movimiento y recalcula el saldo se escribió para un solo caso: el pago de cotización, que siempre es
un **ingreso**. Se extrae a un servicio único que recibe tipo (`ingreso`|`egreso`), cuenta, monto,
fecha, concepto y documento origen, y ejecuta el protocolo completo que 010 definió: abrir la
transacción, bloquear la fila de `Cuenta` con `lockForUpdate()`, validar la regla de saldo no
negativo, persistir el movimiento y recalcular `saldo_actual`. El reverso (cancelar el pago) recorre
el mismo camino a la inversa.

Con esto, `Movimiento.documentable` pasa a apuntar a dos tipos: `CotizacionPago` (ingresos, 010) y
`OrdenCompra` (egresos, esta historia). El listado de movimientos de 010 muestra el documento origen
de ambos, enlazando al detalle correspondiente.

#### `tiene_ordenes_activas` del proveedor (extiende 005)

005 sembró la columna `tiene_ordenes_activas` en `proveedores` (default `false`, nunca editable desde
la UI) esperando este módulo. Ahora que existe, **se elimina la columna** y el dato se **deriva por
consulta**: un proveedor tiene órdenes activas si existe al menos una `OrdenCompra` suya en estado
distinto de `recibida`. Mantener una columna booleana sincronizada a mano en cada alta, cambio de
estado y borrado de orden es exactamente la clase de dato que se desincroniza en silencio.

`ProveedorResource` sigue exponiendo el mismo campo y el `409 Conflict` del `DELETE` conserva su
mensaje específico ("No se puede eliminar: tiene órdenes de compra activas"), así que el diálogo de
confirmación del frontend de 005 no cambia — solo cambia de dónde sale el booleano.

Una orden en `borrador` **también** bloquea el borrado del proveedor. Es deliberado: si hay un
borrador colgando, borrarlo es un clic, y definir "activa" con excepciones produce una regla que
nadie recuerda seis meses después.

### Fuente de verdad única del cálculo de totales

El algoritmo de totales (dos pasadas, prorrateo del descuento global antes del IVA) vive en dos
implementaciones por necesidad: en PHP (`FacturaTotalesCalculator`, que es quien persiste y quien
manda) y en TypeScript, para que el desglose del formulario se actualice en vivo sin depender de la
red. 007 y 008 documentaron esa duplicación como riesgo asumido, confiando en que alguien se acuerde
de tocar los dos lados.

Se aplica el mismo remedio que 011 usó para la cadena de precios:

- **Fixture compartido**: `shared/fixtures/totales-documento.json`, en la raíz del repositorio, junto
  al de precios que ya existe. Cada caso declara líneas (cantidad, precio, descuento de línea, tasa
  de IVA), descuento global, y los totales esperados.
- **Ambas suites lo consumen**: PHPUnit y Vitest lo recorren completo. Cambiar una implementación sin
  la otra rompe la suite del lado que no se tocó, que es la señal que hoy no existe.
- Casos que debe cubrir: descuento por línea en porcentaje y en monto, descuento global prorrateado,
  mezcla de tasas 16/0/exento en el mismo documento, y casos frontera de redondeo.

Con el componente compartido de líneas (ver Frontend), esta red pasa a ser más importante todavía: el
cálculo en vivo queda en un solo lugar para los tres documentos, así que un error ahí se propaga a
factura, cotización y orden de compra a la vez.

## Frontend (Vue 3)

### Componente compartido de líneas

`FacturaFormView.vue` y `CotizacionFormView.vue` tienen hoy dos copias de la misma tabla de líneas
(cantidad | descripción | modelo | precio unitario | descuento | tasa IVA | total, con su selector de
artículo y su desglose de totales en vivo). La orden de compra sería la tercera.

Se **extrae a un componente compartido** que las tres vistas consumen, parametrizando lo poco que
difiere:

- **de dónde se precarga el precio** del artículo: `precio_unitario_sin_iva` (precio de venta) en
  factura y cotización, `costo_con_descuento` (costo) en la orden de compra;
- **qué artículos ofrece el selector**: todos los del usuario en factura y cotización, solo los del
  proveedor seleccionado en la orden de compra;
- las etiquetas de la columna de precio y del desglose.

Lo que **no** se parametriza es la regla de artículo duplicado: en los tres documentos, elegir en el
buscador un artículo que ya está capturado avisa del duplicado y ofrece sumar unidades a la línea
existente o cancelar, nunca agregar una segunda línea del mismo artículo (regla completa en
[008](008-cotizaciones.md), sección Frontend).

El frontend **no tiene pruebas de componentes** —011 introdujo Vitest deliberadamente solo para
aritmética pura, sin `jsdom` ni `@vue/test-utils`—, así que la red de este refactor es `vue-tsc`, el
fixture compartido de totales, y revisión manual de las tres pantallas en el navegador. Queda
registrado como el riesgo conocido de esta historia.

### Pantallas

- **`/ordenes-compra`** (protegida): listado paginado (folio, proveedor, estado, total, fecha), con
  filtros por columna (proveedor, RFC, folio, estado) combinables entre sí, más filtro de fecha con
  botones rápidos ("Hoy", "Esta semana", "Este mes" — por defecto "Este mes") y dos campos de fecha
  para rango personalizado. Mismo patrón que `/cotizaciones`.
- **`/ordenes-compra/crear`** y **`/ordenes-compra/{id}/editar`**: selector de proveedor, el
  componente compartido de líneas con precios precargados al costo, descuento global, fecha esperada
  de entrega y observaciones. Desglose de totales en tiempo real. Cambiar el proveedor con líneas ya
  capturadas pide confirmación antes de vaciarlas. Editable solo si `borrador`/`enviada`; bloqueado
  si `pagada`/`recibida`.
- **`/ordenes-compra/{id}`** (detalle): representación de la orden con sus datos de pago cuando
  existe, y botones:
  - **"Enviar"**: modal con selector de canal (correo/WhatsApp); si correo, destinatarios editables
    prellenados con el correo del proveedor; si WhatsApp, teléfono editable prellenado con el suyo.
  - **"Registrar pago"**: visible solo si `estado = enviada`. Modal con selector de **cuenta** (solo
    cuentas activas) y fecha de pago; el total de la orden se muestra como texto de confirmación, sin
    campo de monto editable. Si el backend responde `422` por saldo insuficiente, el mensaje se
    muestra dentro del propio modal, sin cerrarlo (mismo patrón que el `409` de 005/009/010).
  - **"Cancelar pago"**: visible solo si `estado = pagada`. Modal de confirmación que advierte que se
    eliminará el egreso en Tesorería, se recalculará el saldo de la cuenta y la orden volverá a
    `enviada` (editable).
  - **"Marcar como recibida"**: visible solo si `estado = pagada`.
  - **"Duplicar"**: crea la copia y redirige a su detalle.
  - **"Descargar PDF"**.
  - **"Compartir PDF"**: entrega el PDF de la orden al **menú de compartir del sistema
    operativo** —en Windows 11, el catálogo de envío con Drive, WhatsApp, Correo, Bluetooth y lo
    que el usuario tenga instalado— con `compartirArchivo` de `lib/compartir.ts`, el mismo
    mecanismo ya usado por Factura ([007](007-facturacion.md)). No hay modal propio ni selector de
    canal: el catálogo del sistema ya es el selector.
    - Va **únicamente el PDF, sin texto de acompañamiento**, con el nombre
      `orden-compra-{folio}.pdf`, el mismo que usa "Descargar PDF".
    - A diferencia de Factura, aparece en **cualquier estado** de la orden
      (`borrador`/`enviada`/`pagada`/`recibida`): la orden siempre puede generar su PDF al vuelo,
      no depende de un estado tipo "timbrada".
    - **Si el navegador no puede compartir archivos, el botón no se muestra.** Quedan "Descargar
      PDF" y "Enviar" por correo/WhatsApp, que siempre funcionan.
    - El PDF **se baja al entrar a la pantalla**, no al apretar el botón (que mientras tanto dice
      "Preparando..."): el menú del sistema solo se abre mientras el gesto del usuario sigue vivo
      (ver [029](029-pwa-mostrador.md)). Si aun así el menú se rechaza, el PDF queda descargado y
      la pantalla lo dice; no se abre WhatsApp, porque aquí nadie eligió ese canal.
    - **Compartir no cambia el estado de la orden** ni registra un envío: es un atajo de escritorio
      independiente de "Enviar", que sigue siendo el camino para notificar al proveedor.
- **Navegación**: se agrega "Órdenes de compra" al `AppLayout`, junto a Proveedores.

### Cambio en Tesorería (010)

El listado `/tesoreria/movimientos` muestra los egresos generados por órdenes de compra igual que
muestra hoy los ingresos de cotizaciones: marcados como automáticos, con sus acciones de
editar/eliminar deshabilitadas y un enlace a su documento origen — en este caso, el detalle de la
orden. La corrección se hace desde ahí, cancelando el pago.

## Fuera de alcance

- **Inventario y existencias** *(superado por [017](017-inventario.md))*: marcar una orden como
  `recibida` no suma stock en ningún lado, porque el sistema no lleva inventario. Desde 017 sí lo
  suma.
- **Recepción parcial por línea**: se recibe la orden completa o no se recibe. Tampoco hay registro
  de faltantes, mermas o devoluciones al proveedor.
- **Pago en parcialidades, anticipos a proveedor o crédito**: el pago es siempre de contado, único y
  por el total. No hay saldo pendiente, saldo a favor ni cuentas por pagar.
- **Cancelación de una orden de compra**: no existe una transición de estado para "ya no la quiero"
  (mismo criterio que 008 para cotizaciones). La vía en `borrador` es eliminarla.
- **Timbrado y CFDI**: la orden de compra no es un CFDI y no se timbra. Tampoco se captura ni se
  almacena el CFDI que el proveedor te emite a ti (no hay CFDI de gastos ni deducciones).
- **Conversión a otro documento**: la orden no se convierte en nada; no existe el espejo del botón
  "Facturar" de 008.
- **Actualización del catálogo desde la orden**: capturar un precio distinto al costo del artículo no
  modifica `precio_proveedor` ni recalcula precios de venta (011). Ese flujo, si se quisiera, es una
  historia propia.
- **Flujo de aprobación o autorización** de órdenes por monto o por rol.
- **Fletes, envíos, aduanas o gastos adicionales** como concepto propio de la orden; si aplican, se
  capturan como una línea más.
- **Comparativo de cotizaciones entre proveedores** para el mismo artículo.
- **Recordatorios o alertas** por fecha esperada de entrega vencida.
- Múltiples divisas y tipo de cambio: todo en MXN, mismo criterio que 007/008/010/011.
- Roles/permisos diferenciados y multiempresa (mismo patrón que 004/005/006/007/008/009/010).

## Estado de implementación

Implementada el 2026-08-05.

- **El pago sin tabla funcionó como se diseñó**: `cuenta_id`/`fecha_pago` en `ordenes_compra` y el
  `Movimiento` apuntando con `documentable` a la `OrdenCompra` misma. `TesoreriaService::registrarDesdeDocumento()`
  **ya recibía el `TipoMovimiento` como parámetro** desde 010, así que la adición técnica 38 no
  requirió refactorizar el servicio: bastó con llamarlo con `TipoMovimiento::Egreso`. Solo se
  actualizó su documentación para reflejar que ahora atiende dos tipos de documento origen.
- **La generalización del envío conservó los mailables por documento**: en vez de un mailable
  genérico, `CotizacionEnviadaMail` y `OrdenCompraEnviadaMail` extienden un
  `DocumentoEnviadoMail` abstracto que arma el adjunto. Así el `Mail::assertSent(CotizacionEnviadaMail::class)`
  de 008 siguió siendo válido y el asunto de cada correo sigue siendo propio. El contrato
  `DocumentoEnviable` (vista del PDF, datos, nombre de archivo, mailable, resumen de WhatsApp y URL
  pública firmada) lo implementan los dos modelos, y `EnvioDocumentoService` concentra la
  generación del PDF y ambos canales.
- **Parámetro de ruta en camelCase**: se usó `->parameters(['ordenes-compra' => 'ordenCompra'])` en
  lugar del `orden_compra` que anticipaba la spec, para que el argumento del controller sea
  `$ordenCompra` y no `$orden_compra`. El efecto es el mismo —evitar la singularización en inglés
  que rompe el binding implícito— y el código queda en la convención de nombres de PHP.
- **Bug encontrado y corregido durante la verificación por HTTP**: una orden `recibida` deja de
  bloquear el borrado de su proveedor, así que el proveedor puede quedar eliminado (soft delete)
  mientras la orden sigue existiendo como documento histórico. Con la relación normal, el detalle
  devolvía el proveedor en `null` y el PDF respondía `500`
  (*Attempt to read property "nombre_comercial" on null*). Se corrigió declarando
  `belongsTo(Proveedor::class)->withTrashed()` en `OrdenCompra::proveedor()`, cubierto por un test
  propio. **La misma situación existe en 008** (`Cotizacion::cliente()` sin `withTrashed()`): un
  cliente con soft delete rompería el PDF de sus cotizaciones. No se tocó, por quedar fuera del
  alcance de esta historia.
- **El fixture de totales confirmó que ambas implementaciones ya coincidían**: los 8 casos
  (`shared/fixtures/totales-documento.json`) pasaron a la primera en PHPUnit y en Vitest, así que
  no había divergencia acumulada entre PHP y TypeScript; lo que se ganó es la señal para que no
  aparezca en el futuro. Los valores esperados se derivaron a mano y se verificaron primero contra
  el backend, que es la implementación autoritativa.
- **El componente compartido de líneas absorbió también el bloque de totales**: `DocumentoLineas.vue`
  contiene las dos tarjetas (Artículos y Descuento global y totales) y los tres formularios lo
  consumen. `FacturaFormView.vue` y `CotizacionFormView.vue` perdieron ~90 líneas de cálculo
  duplicado cada uno, que ahora viven en `src/lib/totalesDocumento.ts`.
- **Verificación end-to-end**: la suite Pest completa (28 tests nuevos en
  `tests/Feature/OrdenesCompraTest.php`, 8 en `tests/Unit/TotalesDocumentoTest.php`, 222 en total
  del proyecto) pasa contra SQLite en memoria, con `TwilioWhatsAppService` mockeado (Mockery) y
  `Mail::fake()` donde aplica. Pint corre sin cambios pendientes. En el frontend, `vue-tsc -b`,
  ESLint, Prettier y Vitest (30 tests) corren limpios, y `vite build` compila la SPA completa.
- **Verificación por HTTP contra MySQL y Mailpit reales**: con las migraciones aplicadas a la base
  de desarrollo y un usuario/token de Sanctum de prueba (creado y eliminado al terminar), se
  recorrió el flujo completo: alta de proveedor → catálogo (10% de descuento, 25% de utilidad) →
  artículo (lista $200 → costo $180, venta $225) → filtro `?proveedor_id=` → orden de compra con la
  línea al **costo** ($180, no $225) por un total de $417.60 → `409` al intentar borrar el
  proveedor con la orden en borrador → `422` al intentar pagar en borrador → envío por correo
  recibido en Mailpit con el asunto "Orden de compra OC-00001" → **pago rechazado con `422` por
  saldo insuficiente** ($300 en la cuenta contra $417.60), quedando la orden en `enviada` y sin
  movimiento → fondeo de la cuenta → pago con un `monto` manipulado de $1.00 en el body, que se
  ignoró y registró los $417.60 correctos → saldo $800 − $417.60 = **$382.40** → egreso automático
  con concepto "Pago de Orden de compra OC-00001" y su documento origen → `422` al editar una orden
  pagada → cancelación del pago, que devolvió el saldo a $800, borró el movimiento y regresó la
  orden a `enviada` → repago y recepción → `422` al cancelar el pago de una orden recibida → borrado
  del proveedor ya permitido (`204`) → PDF de la orden.
- **No se pudo verificar visualmente la UI en un navegador real** (misma limitación de entorno
  Windows que en 004/005/006/007/008/010/011). Es especialmente recomendable revisarla a mano en
  esta historia, porque el refactor del componente de líneas toca los formularios de **factura y
  cotización** y el frontend no tiene pruebas de componentes: conviene abrir `/facturas/crear`,
  `/cotizaciones/crear` y `/ordenes-compra/crear` y confirmar que la tabla, el buscador de
  artículos y el desglose de totales siguen comportándose igual, además del detalle de la orden
  (envío, registro y cancelación del pago, recepción, duplicar).
- **El envío por WhatsApp sigue sin credenciales de Twilio** (mismo estado que 008): la
  construcción del payload y de la URL firmada se verificaron con el test de Mockery, pero no
  contra la API real.

## Criterios de aceptación

1. Un usuario autenticado puede crear una orden de compra seleccionando un proveedor y una o varias
   líneas de artículo, viendo los totales desglosados en tiempo real, quedando en estado `borrador`.
2. El selector de artículos de la orden ofrece únicamente artículos de catálogos del proveedor
   seleccionado; enviar una línea con un artículo de otro proveedor se rechaza con error de
   validación.
3. El precio unitario de cada línea se precarga con el costo del artículo (precio de lista del
   proveedor menos el descuento de su catálogo), no con su precio de venta.
4. Ese precio es editable mientras la orden no esté pagada, y editarlo no modifica el precio del
   proveedor del artículo ni recalcula ningún precio de venta del catálogo.
5. Enviar una orden por correo o WhatsApp adjunta el PDF generado al vuelo y cambia su estado a
   `enviada`.
6. El PDF de la orden muestra los datos del proveedor, el encabezado "Orden de compra", y la fecha
   esperada de entrega y las observaciones cuando fueron capturadas.
7. Registrar el pago de una orden `enviada` exige elegir una cuenta activa y una fecha; el monto es
   el total de la orden y no es editable. Cualquier monto enviado en la petición se ignora.
8. Registrar el pago crea un movimiento de **egreso** en Tesorería sobre la cuenta elegida, con la
   fecha del pago y el concepto "Pago de Orden de compra OC-<folio>", y deja la orden en `pagada`.
9. Si el pago dejaría la cuenta con saldo negativo, la petición se rechaza con error de validación,
   no se crea ningún movimiento, y la orden permanece en `enviada`.
10. Cancelar el pago de una orden `pagada` elimina su movimiento de egreso, recalcula el saldo de la
    cuenta y regresa la orden a `enviada`, volviéndola editable.
11. Una orden `recibida` no admite cancelación de pago.
12. Una orden `pagada` puede marcarse como `recibida` manualmente; no puede marcarse así desde ningún
    otro estado, y hacerlo no afecta existencias de ningún tipo. *(Superado por
    [017](017-inventario.md): recibir suma las cantidades al inventario.)*
13. Una orden solo es editable en `borrador`/`enviada`; editar una `enviada` la regresa a `borrador`.
    No es editable ni eliminable en `pagada`/`recibida`.
14. Si el `total` recalculado por el backend no coincide con el enviado por el frontend, la petición
    se rechaza con error de validación.
15. El listado `/ordenes-compra` filtra por proveedor, RFC, folio y estado de forma combinable, y por
    rango de fecha (con atajos Hoy/Esta semana/Este mes, mostrando "Este mes" por defecto).
16. "Duplicar" una orden crea una copia nueva en `borrador`, con folio propio, mismos
    proveedor/líneas/descuento global/observaciones, sin pago registrado.
17. Un proveedor con al menos una orden de compra en estado distinto de `recibida` no puede
    eliminarse: la API responde `409` con su mensaje específico y el diálogo de confirmación lo
    muestra. Una orden en `borrador` también bloquea el borrado.
18. Un proveedor cuyas órdenes están todas en `recibida` (o que no tiene ninguna) sí puede eliminarse
    con soft delete, igual que antes de esta historia.
19. El egreso generado por una orden de compra aparece en el listado de movimientos de Tesorería
    marcado como automático, con enlace a la orden y sin poder editarse ni eliminarse desde ahí.
20. El saldo de la cuenta usada para pagar disminuye exactamente por el total de la orden, y vuelve a
    su valor anterior si el pago se cancela.
21. Los pagos de cotización (010) siguen generando movimientos de **ingreso** exactamente como antes,
    sin cambios de comportamiento.
22. El envío por correo y WhatsApp de cotizaciones (008) sigue funcionando exactamente como antes,
    pese a la generalización del servicio de envío.
23. Factura, cotización y orden de compra usan el mismo componente de captura de líneas, y las tres
    calculan sus totales con resultados idénticos a los del backend, verificado por ambas suites
    contra el fixture compartido.
24. Pint, ESLint/Prettier y las suites de PHPUnit y Vitest corren sin errores sobre el código nuevo.
25. Desde el detalle de una orden de compra, en cualquier estado, se comparte **el PDF y solo el
    PDF** por el menú de compartir del sistema —en Windows 11, el catálogo de envío con Drive,
    WhatsApp y los demás destinos instalados—, sin texto de acompañamiento y sin que el estado de
    la orden cambie. Cerrar el menú sin elegir destino no muestra error. En un navegador que no
    puede compartir archivos, el botón no aparece, y el PDF sigue disponible por "Descargar PDF".

## Supuestos asumidos (registro completo)

1. "Orden de compra" es una entidad propia del usuario dueño de la cuenta (mono-usuario, sin
   multiempresa), mismo patrón que 004/005/006/007/008/009/010.
2. Una orden de compra pertenece a un `Proveedor` del catálogo de 005 (obligatorio), igual que una
   cotización pertenece a un `Cliente`.
3. La orden tiene folio propio autoincremental por usuario, independiente del de `Factura` y
   `Cotizacion`, presentado como `OC-00015`.
4. Se compone de una o varias líneas de artículo (006/009/011), con la misma estructura de línea que
   `CotizacionLinea` de 008, más un descuento global.
5. Los totales se calculan siempre en backend con el mismo algoritmo de dos pasadas de 007/008, y se
   rechaza con `422` si el total enviado por el frontend no coincide.
6. La orden captura además una fecha esperada de entrega (opcional) y observaciones para el proveedor
   (texto libre, opcional), ambas impresas en el PDF y sin ningún efecto sobre el flujo.
7. El precio unitario de la línea se precarga con el **costo** del artículo (`costo_con_descuento` de
   011), no con su precio de venta: es lo que le pagas al proveedor.
8. **(Redefinido)** El precio unitario —y en general toda la orden— es **libremente editable mientras
   la orden no esté `pagada`**. Descripción y modelo se copian del artículo como copias desacopladas
   del catálogo, igual que en 007/008.
9. Al elegir el proveedor, el selector de artículos ofrece solo los artículos de catálogos de ese
   proveedor (009 ya liga `Catalogo` → `Proveedor`). Cambiar de proveedor con líneas capturadas
   obliga a confirmar el vaciado.
10. Comprar no modifica el `precio_proveedor` del artículo ni dispara el recálculo de precios de
    venta de 011: la orden es un documento independiente del catálogo.
11. Todo en MXN, sin multi-moneda ni tipo de cambio.
12. **(Redefinido)** El ciclo de estados es `borrador` → `enviada` → `pagada` → `recibida`,
    secuencial, con dos retrocesos explícitos: editar una `enviada` la regresa a `borrador`, y
    cancelar el pago de una `pagada` la regresa a `enviada`.
13. Editar una orden `enviada` la regresa a `borrador`, obligando a reenviarla al proveedor.
14. `recibida` es una marca manual del usuario, solo alcanzable desde `pagada`, sin validación de
    cantidades ni efecto sobre inventario. *(Superado por [017](017-inventario.md) en cuanto al
    efecto sobre inventario; lo demás sigue vigente.)*
15. Solo se puede eliminar (borrado físico) una orden en `borrador`.
16. No existe flujo de cancelación de una orden de compra, mismo criterio que 008.
17. El envío por correo adjunta el PDF generado al vuelo, con el correo del proveedor prellenado y
    editable.
18. El envío por WhatsApp usa el mismo mecanismo de 008 (Twilio + endpoint de PDF público con URL
    firmada temporal), con el teléfono del proveedor prellenado —ya en E.164 desde 005— y editable.
    Las credenciales de Twilio siguen sin configurarse en este entorno, así que ese canal fallará en
    tiempo de ejecución hasta que se provean.
19. Cualquiera de los dos canales, al enviarse con éxito, dispara `borrador` → `enviada`.
20. El PDF reutiliza el estilo y la plantilla del de cotización, con encabezado "Orden de compra" y
    el bloque del proveedor en lugar del cliente.
21. **(Redefinido)** El pago a proveedores es **siempre de contado**: un solo pago, por el total de
    la orden. No hay anticipos, parcialidades, saldo pendiente, historial de pagos ni sobrepago.
22. El pago exige elegir una `Cuenta` de Tesorería (010) activa y genera automáticamente un
    `Movimiento` de **`egreso`**, sujeto a la regla de saldo no negativo, con concepto generado por
    el sistema: `"Pago de Orden de compra OC-00015"`.
23. **(Redefinido)** La eliminación LIFO de pagos de 010 se sustituye por una acción **"Cancelar
    pago"**: al haber un solo pago, revertirlo es eliminar su movimiento, recalcular el saldo y
    regresar la orden a `enviada`. No se permite si la orden ya está `recibida`.
24. **(Redefinido)** No hay acumulación de montos ni transición automática por suma de pagos: la
    orden pasa a `pagada` por una acción explícita "Registrar pago", que muestra el total como texto
    de confirmación sin campo de monto editable.
25. El campo `tiene_ordenes_activas` de 005 entra por fin en operación: un proveedor con al menos una
    orden en estado distinto de `recibida` —incluido `borrador`— no puede eliminarse, respondiendo el
    `409` que 005 ya tenía previsto.
26. El listado `/ordenes-compra` filtra por proveedor, RFC, folio y estado de forma combinable, más
    filtro de fecha con atajos Hoy/Esta semana/Este mes (por defecto "Este mes"), mismo patrón que
    008.
27. Existe "Duplicar orden", que crea una copia en `borrador` con folio nuevo y sin pago.
28. Se agrega una entrada "Órdenes de compra" a la navegación del `AppLayout`.
29. **(Superado por [017](017-inventario.md))** No hay inventario ni existencias: marcar "recibida"
    no suma stock. Desde 017 el sistema lleva inventario y la recepción es su entrada principal.
30. No hay recepción parcial por línea, faltantes, mermas ni devoluciones.
31. La orden de compra no es un CFDI ni se timbra, y no se captura el CFDI que el proveedor emite al
    usuario.
32. No hay flujo de aprobación por roles, ni fletes/gastos adicionales como concepto propio, ni
    comparativo de cotizaciones entre proveedores.
33. **(Adición técnica)** Se reutiliza `FacturaTotalesCalculator`, ya que opera sobre arreglos
    genéricos de líneas, y se le pide explícitamente que **no** redondee al peso
    ([030](030-total-al-peso-cerrado.md)). El IVA de la orden es el que cobra el proveedor (acreditable), pero
    como el sistema no lleva contabilidad fiscal de IVA, esa distinción solo cambia etiquetas del
    PDF, no fórmulas.
34. **(Adición técnica)** Se generaliza el mecanismo de envío de 008 (mailable, `TwilioWhatsAppService`
    y ruta de PDF público firmada) mediante un contrato que implementan `Cotizacion` y `OrdenCompra`,
    en vez de duplicarlo. Los tests existentes de 008 son la red que verifica que su comportamiento
    no cambia.
35. **(Adición técnica)** El pago se persiste en columnas de la propia orden (`cuenta_id` y
    `fecha_pago`, nullable), sin tabla de pagos: con un pago único y por el total, una tabla aparte
    tendría siempre cero o una fila. El `Movimiento` apunta con `documentable` directamente a la
    `OrdenCompra`. Pagar a un proveedor en parcialidades exigiría, en el futuro, migrar a una tabla
    propia; es el precio deliberado de no construir hoy lo que no se ocupa.
36. **(Adición técnica)** El modelo declara `protected $table = 'ordenes_compra'` y la ruta usa
    `->parameters(['ordenes-compra' => 'orden_compra'])` desde el primer commit, aplicando de entrada
    la lección de pluralización española ya pagada en 005 y 008.
37. **(Adición técnica)** Se elimina la columna `tiene_ordenes_activas` de `proveedores` y el dato se
    deriva por consulta (`exists()` sobre órdenes en estado distinto de `recibida`), en vez de
    mantener un booleano sincronizado a mano. `ProveedorResource` y el `409` del `DELETE` no cambian
    de forma observable.
38. **(Adición técnica)** Se extrae a un servicio único la lógica de Tesorería que crea el movimiento
    y recalcula el saldo, parametrizada por tipo (`ingreso`/`egreso`) y ejecutando el protocolo
    completo de 010 (transacción, `lockForUpdate()` sobre la cuenta, validación de saldo no negativo,
    recálculo de `saldo_actual`). `Movimiento.documentable` pasa a apuntar a dos tipos:
    `CotizacionPago` y `OrdenCompra`.
39. **(Adición técnica)** La regla de saldo no negativo de 010, que nunca se activaba con ingresos,
    pasa a ser una condición real de la operación diaria: no se puede pagar una orden desde una
    cuenta sin fondos suficientes.
40. **(Adición técnica)** Se extrae la tabla de captura de líneas de `FacturaFormView.vue` y
    `CotizacionFormView.vue` a un componente compartido que consumen las tres vistas, parametrizando
    el origen del precio precargado, el conjunto de artículos ofrecido y las etiquetas. El frontend
    no tiene pruebas de componentes, así que la red del refactor es `vue-tsc`, el fixture compartido
    de totales y revisión manual; queda registrado como el riesgo conocido de esta historia.
41. **(Adición técnica)** Se agrega `?proveedor_id=` a `GET /api/v1/articulos` para alimentar el
    selector filtrado por proveedor. Es el único cambio de esta historia sobre el módulo de
    Artículos.
42. **(Adición técnica)** El algoritmo de totales, duplicado en PHP y TypeScript desde 007, se ata a
    un fixture compartido `shared/fixtures/totales-documento.json` que consumen PHPUnit y Vitest,
    siguiendo el patrón que 011 estableció para la cadena de precios. Cambiar una implementación sin
    la otra rompe la suite del lado no tocado. Con el componente compartido de líneas, esa red cubre
    a los tres documentos a la vez.
43. **(Adición)** El detalle de la orden de compra agrega un botón **"Compartir PDF"** que entrega
    el PDF al menú de compartir nativo del sistema operativo (en Windows 11, el catálogo de envío
    con Drive, WhatsApp, Correo y demás destinos instalados), usando el mismo `compartirArchivo` de
    `lib/compartir.ts` que ya usan Factura ([007](007-facturacion.md)) y el mostrador. No se escribe
    un mecanismo de compartir aparte: sería una tercera copia del mismo código.
44. **(Adición)** El botón se muestra u oculta según `puedeCompartirArchivos()` —si este navegador
    tiene el menú del sistema, no según el ancho de pantalla ni el sistema operativo detectado— y,
    a diferencia de Factura, aparece **sin importar el estado** de la orden, porque el PDF siempre
    puede generarse al vuelo.
45. **(Adición)** El PDF se descarga por adelantado al entrar a la pantalla del detalle, no al
    apretar el botón (que mientras tanto dice "Preparando..."), porque el menú del sistema solo se
    abre mientras el gesto del usuario sigue vivo (ver [029](029-pwa-mostrador.md)). Se comparte
    **solo el archivo, sin texto**, con el nombre `orden-compra-{folio}.pdf`, el mismo que
    "Descargar PDF"; si el menú del sistema rechaza la llamada, el respaldo es dejar el PDF
    descargado y avisarlo en pantalla, sin abrir WhatsApp.
46. **(Adición)** El almacén de datos (`stores/ordenesCompra.ts`) gana dos funciones, copiadas del
    mismo patrón que ya tiene `stores/facturas.ts`: una que baja el PDF y lo deja listo en memoria
    (`archivoPdf`) y otra que se lo entrega al menú de compartir (`compartirPdf`). El backend no
    cambia: usan el mismo `GET /api/v1/ordenes-compra/{id}/pdf` que ya alimenta "Descargar PDF".
