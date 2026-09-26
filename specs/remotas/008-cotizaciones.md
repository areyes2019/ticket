# Spec: Cotizaciones (generación, envío, seguimiento de pago y conversión a factura)

## Historia de usuario

Como usuario registrado, quiero poder generar una cotización para enviarla a los clientes y luego
poder convertirla en factura timbrada.

## Objetivo / Alcance

Implementar el módulo de cotizaciones sobre la base ya existente de Laravel API + Vue 3 SPA +
Sanctum (ver [001](001-inicio-proyecto.md), [002](002-login-auth.md)), el design system de
[003](003-design-system-tailwind.md), y los catálogos de [Cliente](004-gestion-clientes.md) y
[Artículo](006-gestion-articulos.md). Incluye: captura de la cotización (cliente + líneas de
artículo, mismo esquema que las líneas de factura de [007](007-facturacion.md)), envío al cliente
por correo o WhatsApp (vía Twilio), un ciclo de estados propio (`borrador` → `enviada` → `pagada`
→ `producto_entregado`), registro de pagos (anticipo, saldo, o pago total) con historial,
duplicación de cotizaciones, y conversión a factura reutilizando el flujo de timbrado ya
implementado en 007. **No** incluye timbrado ni XML propio para la cotización (no es un CFDI),
cancelación de cotizaciones, notas de crédito, ni multiempresa.

## Backend (Laravel)

### Modelo `Cotizacion`

- Pertenece a un `User` (`user_id`) y a un `Cliente` (`cliente_id`, obligatorio, debe pertenecer
  al mismo usuario).
- `folio`: entero autoincremental **por usuario** (numeración propia, independiente del `folio` de
  `Factura`).
- **Estado** (`estado`, string/enum): `borrador` → `enviada` → `pagada` → `producto_entregado`.
  Transición estrictamente secuencial, sin retroceso automático salvo el caso descrito abajo.
  - `borrador`: recién creada o recién editada.
  - `enviada`: se envió al menos una vez por correo o WhatsApp.
  - `pagada`: la suma acumulada de los pagos registrados (ver `CotizacionPago`) alcanza o supera
    el `total` de la cotización.
  - `producto_entregado`: marcado manualmente por el usuario, solo alcanzable desde `pagada`.
  - Editar una cotización que está en `enviada` la regresa a `borrador` (obliga a reenviarla
    explícitamente para notificar el cambio al cliente).
- **Descuento global** y **totales** (`subtotal`, `total_descuento`, `total_iva_16`,
  `total_iva_0`, `total_exento`, `ajuste_al_peso`, `total`): mismo modelo y mismo algoritmo de
  cálculo (dos pasadas, prorrateo del descuento global entre líneas antes del IVA, y el ajuste al
  peso cerrado de [030](030-total-al-peso-cerrado.md) al final) que `Factura`/
  `FacturaTotalesCalculator` de 007 — se generaliza/reutiliza esa clase para operar también sobre
  `CotizacionLinea`. Recalculados siempre en backend, nunca persistidos tal cual los mande el
  frontend (mismo criterio de validación que en 007: `422` si el `total` enviado no coincide con
  el recalculado).
- `factura_id` (FK nullable a `Factura`): relación 1:1 opcional. Se llena cuando la cotización se
  convierte exitosamente en una factura (timbrada o pendiente). Una cotización con `factura_id`
  distinto de null ya no puede generar una segunda factura.
- Sin soft delete: el `DELETE` es físico (se lleva sus `CotizacionLinea`) y se permite mientras el
  estado es `borrador` o `enviada` — una cotización enviada que el cliente nunca aprobó es basura
  que el usuario debe poder tirar. Se bloquea (`422`) en `pagada`/`producto_entregado`, y también
  en `borrador`/`enviada` **si tiene algún `CotizacionPago` registrado**: esos pagos tienen un
  movimiento de Tesorería asociado (ver [010](010-tesoreria.md)), así que primero hay que
  eliminarlos con el endpoint de pagos, que es quien sabe revertir el movimiento y recalcular el
  saldo de la cuenta.

### Modelo `CotizacionLinea`

Mismo esquema que `FacturaLinea` de 007: `cotizacion_id`, `articulo_id` (propio del usuario,
nullable — línea libre, sin producto del catálogo, que no mueve inventario y no participa en el
cálculo de utilidad),
`cantidad` (entero, mínimo 1), `descripcion` y `modelo` (precargados del artículo, editables
in-place, copias desacopladas del catálogo), `precio_unitario` (precargado, editable, mayor a 0 —
precargado con el precio distribuidor del artículo en vez del directo cuando el cliente elegido es
distribuidor, ver [033](033-precio-distribuidor.md)),
`descuento_tipo`/`descuento_valor` (opcional, `porcentaje`|`monto`), `tasa_iva`
(`16`|`0`|`exento`), `importe` e `iva_importe` (calculados en backend, mismo criterio que en 007:
`importe` es el neto de línea sin IVA, `iva_importe` se desglosa aparte).

#### Costo capturado por línea ([010](010-tesoreria.md))

Cada línea con `articulo_id` gana `costo_unitario`: decimal(10,2) nullable, sin IVA, **una copia del
`costo_con_descuento` del artículo tomada en el momento en que la línea se guarda**, no un valor que
se recalcule después. Es el mismo criterio ya aplicado a `precio_unitario` y a `descripcion`/`modelo`
en esta misma tabla: una copia desacoplada del catálogo, para que el costo con el que se vendió algo
no cambie por debajo del documento si el precio del proveedor o el catálogo cambian más tarde.

- Se llena solo cuando hay `articulo_id`; una línea libre (si la línea de cotización llega a admitir
  una) queda con `costo_unitario = null`.
- No se acepta desde el frontend: el backend lo toma directamente del artículo al crear o editar la
  línea, igual que ya hace con `importe`/`iva_importe`.
- No se recalcula al editar una cotización que sigue siendo editable (`borrador`/`enviada`) salvo que
  la línea misma se vuelva a guardar (se borra y se reemplaza en cada `PUT`, mismo patrón que hoy
  usan las líneas): en ese caso toma el costo vigente del artículo en ese instante, igual que
  `precio_unitario` toma el precio vigente al precargarse.
- Es la base para la utilidad de venta que expone Tesorería en `/tesoreria/movimientos` (ver
  [010](010-tesoreria.md), "Utilidad de venta en movimientos automáticos"): sin este dato ese cálculo
  no tiene de dónde salir.
- Las cotizaciones ya existentes en base de datos antes de esta ampliación quedan con
  `costo_unitario = null` en todas sus líneas — no hay migración que intente reconstruirlo con el
  costo actual del artículo, porque ya no es el mismo costo que tenía el día de la venta.

### Modelo `CotizacionPago`

Tabla separada (permite historial de múltiples pagos, no solo un par fijo anticipo/saldo):

- Pertenece a una `Cotizacion` (`cotizacion_id`).
- `tipo` (`anticipo`|`saldo`|`pago_total`): clasifica el registro para el historial y determina
  las reglas de negocio siguientes.
- `fecha_pago`, `monto` (decimal, mayor a 0), `forma_pago` (catálogo SAT `c_FormaPago`, reutilizado
  de 007 — sin CFDI asociado, es solo informativo).
- **Un solo anticipo por cotización**: una cotización admite como máximo un `CotizacionPago` con
  `tipo = anticipo`. Una vez registrado, no se puede registrar otro anticipo para esa cotización,
  bajo ningún estado posterior.
- **`monto` de `saldo`/`pago_total` siempre es el saldo pendiente**: para `tipo = saldo` y
  `tipo = pago_total`, `monto` se autocalcula como `total - suma de pagos ya registrados`; no se
  acepta un valor distinto al calculado. Solo `tipo = anticipo` acepta un monto libre elegido por
  el usuario.
- **Ningún pago puede generar sobre-pago**: la suma acumulada de todos los pagos de una cotización
  nunca puede superar su `total` (ver Validaciones).
- **Regla de transición a `pagada`**: al crear un `CotizacionPago`, se suma su monto a los pagos ya
  existentes de esa cotización; en cuanto la suma acumulada alcanza el `total` de la cotización, el
  `estado` de `Cotizacion` pasa automáticamente a `pagada`. Esto cubre tanto el flujo
  anticipo→saldo como el pago del 100% en un solo registro (`tipo = pago_total`).
- No genera ningún documento fiscal (no es CFDI, no pasa por facturapi.io) — distinto del
  `ComplementoPago` de 007, que sí es un CFDI y solo aplica a una `Factura` ya timbrada con
  `metodo_pago = PPD`.

### Envío por correo y WhatsApp

- **Correo**: genera el PDF de la cotización al vuelo (sin persistirlo, mismo criterio que 007) y
  lo adjunta a un mailable enviado vía Mailpit en desarrollo, con el correo del cliente prellenado
  (editable).
- **WhatsApp (redefinido por [029](029-pwa-mostrador.md))**: el mensaje **no sale del servidor**. El
  frontend descarga el PDF con la sesión del usuario y lo entrega al menú de compartir del aparato,
  que lo manda desde el WhatsApp del negocio; en escritorio descarga el archivo y abre `wa.me` con
  el resumen escrito. Al compartir, el frontend llama a `POST
  /api/v1/cotizaciones/{id}/marcar-enviada` para la transición de estado.

  La implementación original mandaba el mensaje vía Twilio, con el PDF colgado de una URL pública
  firmada (`cotizaciones.pdf-publico`) para que Twilio pudiera descargarlo. Nunca envió un solo
  mensaje: las credenciales `TWILIO_ACCOUNT_SID`/`TWILIO_AUTH_TOKEN`/`TWILIO_WHATSAPP_FROM` jamás se
  configuraron y el botón respondía con un error del servidor. Con el canal se retiraron esa ruta
  pública y `Cotizacion::urlPdfPublico()`. `TwilioWhatsAppService` sigue existiendo para las órdenes
  de compra de [012](012-ordenes-compra.md).
- El envío por correo, y el `marcar-enviada` del compartir, disparan la transición `borrador` →
  `enviada` (o simplemente confirman que sigue `enviada` si ya lo estaba).

### Conversión a factura

- No existe un endpoint de "facturar" que timbre directo. En su lugar:
  1. El frontend navega a `/facturas/crear?cotizacion_id={id}`, precargando el formulario de 007
     con el `cliente_id` de la cotización (**fijo, no editable** en ese formulario) y sus líneas
     (cantidad, precio, descuento, IVA — libremente editables, igual que si se creara una factura
     desde cero).
  2. `POST /api/v1/facturas` (007) se extiende para aceptar un `cotizacion_id` opcional en el
     body. Si se envía, valida que esa cotización pertenezca al usuario autenticado y que no
     tenga ya una `factura_id` asociada (`422` si ya existe). Al crear la factura (quede
     `timbrada` o `pendiente`), actualiza `Cotizacion.factura_id` con el id de la nueva factura.
- El botón "Facturar" del detalle de la cotización se comporta según el estado de la factura
  asociada (consultado vía `GET /api/v1/facturas/{factura_id}`):
  - Sin `factura_id`: el botón navega al formulario precargado (paso 1 arriba).
  - `factura_id` presente y la factura está `pendiente`: el botón navega directo al detalle/edición
    de esa factura existente (para reintentar el timbrado), **sin** crear una factura nueva.
  - `factura_id` presente y la factura está `timbrada`: el botón queda **deshabilitado**.
  - `factura_id` presente y la factura está `cancelada`: el botón queda deshabilitado igual que en
    `timbrada` (no hay re-facturación automática); la vía de recuperación es duplicar la
    cotización (ver abajo) y facturar la copia.
- La cotización puede facturarse en cualquiera de sus 4 estados (`borrador`, `enviada`, `pagada`,
  `producto_entregado`) — no se bloquea por pagos pendientes.

### Duplicar cotización

`POST /api/v1/cotizaciones/{id}/duplicar` — crea una copia nueva con el mismo `cliente_id`,
líneas y descuento global, `folio` nuevo, `estado = borrador`, `factura_id = null` y sin copiar el
historial de `CotizacionPago`.

### Caducidad automática (30 días sin movimiento)

Una cotización que el cliente nunca aprobó no se queda para siempre en el listado: **a los 30 días
sin movimiento en estado `borrador` o `enviada`, se elimina automáticamente** (borrado físico, el
mismo que el manual).

- **"Sin movimiento" se mide contra `updated_at`**: crearla, editarla, enviarla o registrarle un
  pago reinicia el conteo. Una cotización tocada ayer nunca se borra hoy, sin importar cuándo se
  creó.
- **Solo aplica a `borrador` y `enviada`**. `pagada` y `producto_entregado` no caducan nunca.
- **Nunca borra una cotización con pagos registrados**, aunque siga en `enviada` (caso real: se
  registró un anticipo y el cliente desapareció). Mismo motivo que en el borrado manual: esos pagos
  tienen movimientos de Tesorería que solo el endpoint de pagos sabe revertir. Quedan visibles en
  el listado hasta que el usuario las resuelva a mano.
- **Tampoco borra una cotización con `factura_id`**: ya generó una factura, que es un documento
  fiscal con vida propia.
- El plazo (30 días) vive en una constante del modelo `Cotizacion`, no repartido por el código.
- `CotizacionResource` expone **`caduca_el`**: la fecha en que la cotización se borraría
  (`updated_at` + 30 días), o `null` cuando no aplica (estado que no caduca, con pagos, o ya
  facturada). Es lo que alimenta el aviso del frontend, y se calcula con la misma constante que usa
  el comando para que aviso y borrado nunca se contradigan.

Implementación: comando artisan **`cotizaciones:purgar-vencidas`**, registrado en el scheduler de
Laravel (`routes/console.php`) con frecuencia **diaria**. El comando reporta cuántas cotizaciones
borró (útil al correrlo a mano) y es idempotente: correrlo dos veces el mismo día no hace nada la
segunda vez.

Como el entorno es Windows/Laragon, **no hay cron que dispare el scheduler**. Para que el borrado
sea realmente automático hay que dar de alta **una sola tarea programada de Windows** que ejecute
`php artisan schedule:run` cada minuto en la carpeta del backend; los pasos quedan documentados en
el README del backend. Sin esa tarea, el comando sigue existiendo y puede correrse a mano, pero la
caducidad no ocurre sola — es el único punto de esta historia que depende de configuración fuera
del repositorio.

### Endpoints (bajo `auth:sanctum`, scopeados al usuario autenticado, salvo el de PDF público)

- `GET /api/v1/cotizaciones` — listado paginado. Filtros combinables por columna: `?cliente=`,
  `?rfc=`, `?folio=`, `?estado=`, y por fecha (`?fecha_desde=`/`?fecha_hasta=`, con atajos de UI
  para "Hoy"/"Esta semana"/"Este mes" que solo fijan esos dos parámetros; valor por defecto al
  cargar la pantalla: "Este mes"). El rango de fecha se interpreta como el día calendario completo
  en la zona horaria del negocio (`America/Mexico_City`, fija — mono-usuario/mono-empresa, sin
  configuración por usuario), convertido a UTC antes de comparar contra `created_at` (que se
  almacena en UTC).
- `POST /api/v1/cotizaciones` — crea la cotización en `borrador` (recalcula totales en backend,
  `422` si no coincide con lo enviado por el frontend).
- `GET /api/v1/cotizaciones/{id}` — detalle (incluye líneas y pagos).
- `PUT /api/v1/cotizaciones/{id}` — edición; permitida solo si el estado es `borrador` o
  `enviada` (`422` si `pagada`/`producto_entregado`); si estaba `enviada`, la deja en `borrador`.
- `DELETE /api/v1/cotizaciones/{id}` — borrado físico; solo si `borrador` o `enviada`, sin pagos
  registrados, sin facturas y sin Orden de Trabajo de Producción (038) (`422` en cualquier otro
  caso, con el motivo en el mensaje). `CotizacionResource`
  expone **`puede_eliminarse`** (booleano) con esa misma regla evaluada en el servidor, para que el
  frontend decida si pinta el botón sin reimplementar la condición en TypeScript.
- `POST /api/v1/cotizaciones/{id}/enviar` — body `{ canal: correo, destinatarios }`; manda el correo
  con el PDF adjunto y dispara la transición de estado descrita arriba.
- `POST /api/v1/cotizaciones/{id}/marcar-enviada` — sin body; pasa `borrador` → `enviada` después de
  que el usuario compartió el PDF desde su aparato (ver [029](029-pwa-mostrador.md)). Sobre una
  cotización ya `enviada` o `pagada` no hace nada y responde igual.
- `POST /api/v1/cotizaciones/{id}/pagos` — body `{ tipo, fecha_pago, monto?, forma_pago }`; para
  `tipo = saldo` o `pago_total`, `monto` es opcional y siempre se autocalcula como el saldo
  pendiente (`total - suma de pagos ya registrados`), ignorando cualquier valor enviado.
- `POST /api/v1/cotizaciones/{id}/entregar` — marca `producto_entregado`; solo si el estado actual
  es `pagada`.
- `POST /api/v1/cotizaciones/{id}/duplicar` — ver arriba.
- `GET /api/v1/cotizaciones/{id}/pdf` — genera el PDF al vuelo (plantilla propia, reutilizando los
  tokens de diseño de 003 y la plantilla de 007 adaptada), sin persistir copia.

### Validaciones (Form Requests)

- `cliente_id`: requerido, existe y pertenece al usuario autenticado.
- `lineas`: array, mínimo 1 elemento; mismas reglas de `articulo_id`/`cantidad`/`descripcion`/
  `modelo`/`precio_unitario`/`descuento_tipo`/`descuento_valor`/`tasa_iva` que en 007.
- `descuento_global_tipo`/`descuento_global_valor`: opcionales, mismas reglas que a nivel línea.
- Envío: `canal` requerido y solo admite `correo`, con `destinatarios` array de al menos 1 email
  válido (ver [029](029-pwa-mostrador.md): el canal `whatsapp` y su `telefono` se retiraron).
- Pago: `tipo` requerido (`anticipo`|`saldo`|`pago_total`); `fecha_pago` requerida; `forma_pago`
  requerida, existe en `c_FormaPago`.
  - `monto`: requerido y numérico (mayor a 0) solo si `tipo = anticipo`; para `saldo`/`pago_total`
    es opcional y, si se envía, se ignora en favor del saldo pendiente autocalculado.
  - Si `tipo = anticipo` y la cotización ya tiene un `CotizacionPago` con `tipo = anticipo`, la
    petición se rechaza (`422`).
  - Si el monto a registrar (el enviado para `anticipo`, o el saldo pendiente autocalculado para
    `saldo`/`pago_total`) haría que la suma acumulada de pagos supere el `total` de la cotización,
    la petición se rechaza (`422`).
- Respuestas mediante Laravel API Resources (`CotizacionResource`, `CotizacionLineaResource`,
  `CotizacionPagoResource`), consistente con la convención de 001/004/006/007.

## Frontend (Vue 3)

- **`/cotizaciones`** (protegida): listado paginado (folio, cliente, estado, total, fecha), con
  un campo de búsqueda/filtro por columna (cliente, RFC, folio, estado) combinables entre sí, más
  un filtro de fecha con botones rápidos ("Hoy", "Esta semana", "Este mes" — por defecto "Este
  mes" al cargar) y dos campos de fecha para rango personalizado.
  - **Botón de eliminar** (papelera, con diálogo de confirmación) visible en las cotizaciones
    `borrador` y `enviada` que no tengan pagos registrados.
  - **Aviso de caducidad**: cuando faltan **7 días o menos** para el borrado automático
    (`caduca_el`), la fila muestra un `Badge` de advertencia con los días restantes ("Se elimina en
    5 días" / "Se elimina hoy").
- **`/cotizaciones/crear`** / **`/cotizaciones/{id}/editar`**: mismo formulario y misma tabla de
  líneas (cantidad | descripción | modelo | precio unitario | descuento | tasa IVA | total) que el
  formulario de factura de 007, sin los campos exclusivos de factura (uso CFDI, forma de pago,
  método de pago). Desglose de totales en tiempo real replicando en TypeScript el mismo algoritmo
  de dos pasadas que el backend (mismo criterio y mismo riesgo de desincronización documentado en
  007 — cualquier cambio al algoritmo debe aplicarse en ambos lados). Editable solo si `borrador`/
  `enviada`; bloqueado si `pagada`/`producto_entregado`.
  - **Un artículo no puede estar en dos líneas del mismo documento.** Al elegir en el buscador un
    artículo que ya está capturado (mismo `articulo_id` en alguna línea), el sistema **no** agrega
    una línea nueva: abre un diálogo que avisa del duplicado, identifica la línea existente
    (descripción, modelo y cantidad actual) y ofrece dos salidas:
    - **"Sumar a la línea existente"**: un campo numérico (entero, mínimo 1, por defecto 1) con las
      unidades a sumar. Al confirmar, esa cantidad se suma a la cantidad de la línea existente y
      los totales se recalculan. **Ningún otro dato de la línea se toca**: precio unitario,
      descripción, modelo, descuento y tasa de IVA se conservan tal cual quedaron, incluidas las
      ediciones manuales del usuario y el descuento permanente del cliente ya aplicado
      ([015](015-descuento-permanente-cliente.md)).
    - **"Cancelar"**: cierra el diálogo sin modificar nada.

    No existe una opción "agregar aparte": si el mismo artículo se necesita con otro precio o con
    otro descuento, se ajusta la línea única. El aviso se dispara solo por la acción de agregar
    desde el buscador; cargar en el formulario las líneas ya guardadas de un documento (edición,
    duplicación, o el precargado de `/facturas/crear?cotizacion_id=...`) nunca lo dispara.

    Esta regla vive en la tabla de líneas compartida por factura, cotización y orden de compra
    (ver [012](012-ordenes-compra.md), "Componente compartido de líneas"), así que aplica igual en
    los tres documentos, sin parametrizarse por tipo.
- **`/cotizaciones/{id}`** (detalle): representación de la cotización con historial de pagos y
  botones:
  - **"Enviar"**: modal con selector de canal (correo/WhatsApp); si correo, destinatarios editables
    (prellenado con el correo del cliente); si WhatsApp, el PDF se comparte desde el propio
    navegador y no hay campo de teléfono que capturar (ver [029](029-pwa-mostrador.md)).
  - **"Registrar anticipo"**: visible solo si `estado = enviada` y la cotización todavía no tiene
    ningún pago `tipo = anticipo` registrado. Modal con fecha de pago, forma de pago y un monto
    libre elegido por el usuario.
  - **"Pago total"**: visible solo si `estado = enviada` y la cotización todavía no tiene ningún
    pago `tipo = anticipo` registrado (mutuamente excluyente con "Registrar saldo": sin anticipo
    previo, el saldo pendiente es el total completo). Modal con fecha de pago y forma de pago; el
    saldo pendiente se muestra como texto de confirmación, sin campo de monto editable.
  - **"Registrar saldo"**: visible solo si `estado = enviada` y el saldo pendiente es mayor a 0
    (condición explícita: no depende únicamente de que el estado ya haya cambiado a `pagada`).
    Mismo modal que "Pago total" (fecha de pago, forma de pago, saldo pendiente como texto sin
    campo de monto editable); mutuamente excluyente con "Pago total" — solo aparece uno de los dos
    según exista o no un anticipo previo.
  - **"Marcar como entregado"**: visible solo si `estado = pagada`.
  - **"Duplicar"**: crea la copia y redirige a su detalle.
  - **"Facturar"**: comportamiento descrito en "Conversión a factura" arriba (navega al formulario
    precargado, navega a la factura pendiente existente, o aparece deshabilitado si ya está
    `timbrada`/`cancelada`).
  - **"Descargar PDF"**.
  - **"Eliminar"**: visible solo si el estado es `borrador` o `enviada` y la cotización no tiene
    pagos registrados. Diálogo de confirmación que advierte que el borrado es definitivo (se lleva
    las líneas y no hay papelera); al confirmar, redirige al listado.
  - **Aviso de caducidad**: cuando faltan 7 días o menos para el borrado automático, un `Alert` de
    advertencia arriba del documento — "Sin movimiento desde el 10/07/2026. Se eliminará
    automáticamente el 09/08/2026 (en 5 días). Editarla o reenviarla reinicia el plazo." —
    explicando así, en el mismo lugar, cómo evitarlo.
- Enlace a `/cotizaciones` agregado a la navegación del `AppLayout`.

## Fuera de alcance

- Timbrado o XML propio de la cotización — no es un CFDI; solo la factura resultante (007) se
  timbra.
- Cancelación de una cotización (no hay transición de estado para "cliente ya no quiere el
  producto" en esta historia).
- Notas de crédito/Egreso, parcialidades múltiples más allá del registro libre de pagos en
  `CotizacionPago`.
- Reenvío automático de correo/WhatsApp al cambiar de estado — siempre es una acción manual desde
  el botón "Enviar".
- Validación de que el teléfono capturado realmente tenga WhatsApp activo (se asume el que
  captura el usuario).
- Roles/permisos diferenciados y multiempresa (mismo patrón que 004/006/007).
- Re-facturación automática cuando la factura asociada queda `cancelada` (la vía es duplicar la
  cotización).

## Estado de implementación

Implementada el 2026-07-31.

- **`Str::plural`/`Str::singular` no reconocen "Cotización" como español**: el nombre de tabla que
  Eloquent infiere por convención para el modelo `Cotizacion` es `cotizacions` (no `cotizaciones`),
  y el parámetro de ruta que `Route::apiResource('cotizaciones', ...)` genera por defecto es
  `{cotizacione}` (no `{cotizacion}`), lo que rompía el binding implícito de modelo en
  `show`/`update`/`destroy` (404 en vez de 200/422). Se corrigió declarando `protected $table =
  'cotizaciones'` en el modelo y `->parameters(['cotizaciones' => 'cotizacion'])` en la ruta
  (mismo patrón ya usado para `proveedores` en 005). `CotizacionLinea`/`CotizacionPago` no
  necesitaron el mismo ajuste: la pluralización en inglés de "Linea"/"Pago" coincide por
  casualidad con el plural en español (`lineas`/`pagos`).
- **`FacturaTotalesCalculator` se reutilizó sin cambios**: ya operaba sobre arreglos genéricos de
  líneas (no acoplado a `Factura`), así que tanto `CotizacionController` como el cálculo espejo en
  `CotizacionFormView.vue` reutilizan exactamente el mismo algoritmo de 007 sin duplicar lógica
  nueva.
- **Relación Cotización↔Factura sin columna nueva en `facturas`**: en vez de agregar
  `cotizacion_id` a la tabla `facturas`, se usa `Cotizacion.factura_id` (FK nullable) y una
  relación `Factura::cotizacion(): HasOne` sobre esa misma columna — evita una migración adicional
  y mantiene la relación 1:1 en un solo lugar.
- **WhatsApp vía Twilio implementado con `Http::asForm()` en vez del SDK oficial** *(retirado por
  [029](029-pwa-mostrador.md): las credenciales nunca se configuraron y el canal se sustituyó por el
  compartir del aparato; el servicio sigue vivo para las órdenes de compra de 012)*: enviar un
  mensaje con un adjunto es una sola llamada POST a `Accounts/{Sid}/Messages.json`
  (`TwilioWhatsAppService`), así que no se justificó agregar la dependencia completa de
  `twilio/sdk`. **No se configuraron credenciales reales de Twilio en este entorno** (a diferencia
  de `FACTURAPI_TEST_KEY` en 007, aquí no se proporcionó una cuenta de prueba durante la
  implementación) — `TWILIO_ACCOUNT_SID`/`TWILIO_AUTH_TOKEN`/`TWILIO_WHATSAPP_FROM` quedan vacías
  en `.env`; el envío por WhatsApp lanzará una excepción en tiempo de ejecución hasta que se
  configuren. El test de envío por WhatsApp mockea `TwilioWhatsAppService` (Mockery), sin llamada
  de red real, así que no depende de esas credenciales.
- **PDF público para Twilio protegido por URL firmada temporal** *(retirado por
  [029](029-pwa-mostrador.md) junto con el canal que lo usaba)*: `GET
  /api/v1/cotizaciones/{cotizacion}/pdf-publico` vive fuera del grupo `auth:sanctum` (Twilio no
  manda cookies de sesión) y usa el middleware `signed` de Laravel
  (`URL::temporarySignedRoute(..., now()->addMinutes(10), ...)`), generado en el momento de
  `enviar()`. No se pudo verificar en vivo contra la API real de Twilio por la falta de
  credenciales; la construcción del payload y la URL firmada se verificaron con el test de
  Mockery y revisión manual del código.
- **Complemento de pago (007) vs. pago de cotización**: se mantienen como dos modelos totalmente
  separados (`ComplementoPago` es un CFDI real vía facturapi.io; `CotizacionPago` es un registro
  interno sin ningún timbrado), sin ningún acoplamiento entre ambos — registrar pagos en una
  cotización no crea ni precarga nada en el complemento de pago de la factura resultante.
- **Verificación end-to-end**: la suite Pest completa (16 tests nuevos en
  `tests/Feature/CotizacionesTest.php`, 87 en total del proyecto) pasa contra SQLite en memoria,
  con `FacturapiService`/`TwilioWhatsAppService` mockeados (Mockery) donde aplica. Pint corre sin
  cambios pendientes. En el frontend, `vue-tsc -b`, ESLint y Prettier corren limpios, y `vite
  build` compila la SPA completa (incluye las 3 vistas nuevas y los cambios en
  `FacturaFormView.vue`) sin errores. **No se pudo verificar visualmente la UI en un navegador
  real** (misma limitación de entorno Windows que en 004/005/006/007) — se recomienda abrir
  `/cotizaciones` manualmente para confirmar el listado con sus filtros por columna y por fecha,
  el formulario, el detalle (envío, registro de pagos, duplicar) y el flujo completo de
  "Facturar" desde una cotización antes de dar la funcionalidad por completamente probada
  visualmente. Tampoco se pudo probar el envío real por WhatsApp (requiere credenciales de
  Twilio no disponibles en este entorno) ni por correo contra Mailpit en vivo (aunque reutiliza
  el mismo mecanismo ya verificado en 002/007).
- **Reglas de pago ajustadas (2026-08-03)**: se detectó que la primera versión permitía registrar
  más de un `CotizacionPago` con `tipo = anticipo` para la misma cotización, y que el `monto`
  enviado por el cliente para `saldo`/`pago_total` se usaba tal cual en vez de autocalcularse
  siempre — ambas cosas podían generar un total pagado mayor al `total` de la cotización. Se
  corrigió en `CotizacionPagoRequest` (dos reglas nuevas vía `Closure`: `sinAnticipoPrevio`
  rechaza un segundo `tipo = anticipo`; `sinSobrepago` rechaza un anticipo cuyo monto exceda el
  saldo pendiente) y en `CotizacionController::pagos()` (el `monto` de `saldo`/`pago_total` ahora
  siempre se recalcula server-side, ignorando cualquier valor recibido). En el frontend,
  `CotizacionDetalleView.vue` deriva `tieneAnticipo` de `cotizacion.pagos` (sin cambios en el
  backend/Resource, que ya exponía `pagos` y `saldo_pendiente`): "Registrar anticipo" y "Pago
  total" solo se muestran si no hay anticipo previo, "Registrar saldo" solo si hay anticipo previo
  y saldo pendiente `> 0`, y los modales de "Pago total"/"Registrar saldo" dejaron de tener un
  campo de monto editable (solo texto de confirmación con el saldo pendiente). Cubierto por 4
  tests nuevos en `CotizacionesTest.php` (104 tests totales del proyecto backend). Verificado
  además por HTTP contra un usuario y token de Sanctum de prueba (creado y eliminado al terminar)
  reproduciendo el escenario reportado: un anticipo de $500 seguido de un segundo intento de
  anticipo de $800 sobre una cotización de total $1148.40 se rechaza con `422` (por segundo
  anticipo y por exceder el saldo pendiente); registrar después `saldo` con un monto manipulado
  ($1.00) ignora ese valor y registra correctamente $648.40, dejando `total_pagado = 1148.40` y
  `estado = pagada`. `vue-tsc`, ESLint, Prettier y `vite build` corren limpios. **No se pudo
  verificar visualmente en un navegador real** (misma limitación de entorno) — se recomienda
  abrir el detalle de una cotización enviada, registrar un anticipo y confirmar que los botones
  "Registrar anticipo"/"Pago total" desaparecen y solo queda "Registrar saldo".

- **Artículo duplicado en la tabla de líneas (2026-08-10)**: hasta esta fecha,
  `DocumentoLineas.vue` siempre hacía `push` al recibir el evento `seleccionar` del buscador, así
  que capturar N veces el mismo artículo dejaba N líneas idénticas en el documento. Ahora
  `onArticuloSeleccionado` busca primero el `articulo_id` entre las líneas ya capturadas: si lo
  encuentra, no agrega nada y abre un `Dialog` de 003 con la descripción, el modelo, el número y la
  cantidad actual de esa línea, más un campo "Cantidad a sumar" (entero, mínimo 1, por defecto 1);
  confirmar solo hace `linea.cantidad += cantidad`, sin tocar ningún otro campo, y cancelar cierra
  sin cambios. Todo vive en el componente compartido, así que aplica a factura (007), cotización
  (008) y orden de compra (012) sin cambios en las tres vistas consumidoras ni en el backend.
  `vue-tsc`, ESLint, Prettier y `vite build` corren limpios. **No se pudo verificar visualmente en
  un navegador real** (misma limitación de entorno; el frontend además no tiene pruebas de
  componentes, ver 012) — se recomienda abrir `/cotizaciones/crear`, agregar el mismo artículo dos
  veces y confirmar que aparece el aviso, que sumar unidades actualiza la cantidad y los totales, y
  que el documento sigue con una sola línea de ese artículo.

- **Borrado ampliado y caducidad automática (2026-08-10)**: `destroy()` aceptaba únicamente
  `borrador`, y el botón de la papelera solo se pintaba en ese estado, así que una cotización
  enviada y nunca aprobada no había forma de tirarla. Ahora la regla vive en
  `Cotizacion::puedeEliminarse()` (estado editable + sin `factura_id` + sin pagos), que usan tanto
  el controlador como el `puede_eliminarse` del Resource, y el `caduca_el` del mismo Resource sale
  de `Cotizacion::caducaEl()`. La purga es el comando `cotizaciones:purgar-vencidas` (scope
  `#[Scope] vencidas`, borrado masivo; las líneas se van por FK en cascada), agendado con
  `Schedule::command(...)->dailyAt('03:00')` en `routes/console.php` — el primer uso del scheduler
  en este proyecto, que hasta ahora no tenía ninguno. Los pasos para dar de alta la tarea
  programada de Windows que ejecuta `php artisan schedule:run` quedaron en `backend/README.md`; sin
  ella el comando existe pero nada lo dispara. En el frontend, el listado usa `puede_eliminarse`
  para la papelera y un `Badge` de caducidad, el detalle estrena botón "Eliminar" con confirmación
  y un `Alert` de aviso, y el cálculo de días restantes vive en `lib/caducidadCotizacion.ts`
  (umbral de 7 días), no repartido entre vistas. `index()` agrega `withCount('pagos')` para que
  `puede_eliminarse` no dispare una consulta por fila. Cubierto por 5 tests nuevos en
  `CotizacionesTest.php` (318 tests del backend, todos en verde); Pint, ESLint, Prettier, `vue-tsc`,
  Vitest y `vite build` corren limpios. **No se pudo verificar visualmente en un navegador real ni
  con la tarea de Windows dada de alta** — se recomienda eliminar una cotización enviada desde el
  detalle y correr `php artisan cotizaciones:purgar-vencidas` a mano para confirmar el conteo antes
  de dar la caducidad por probada en vivo.

**Costo capturado por línea, implementado el 2026-08-25** (ver
[010](010-tesoreria.md)). `costo_unitario` se agregó por migración a `cotizacion_lineas`
(nullable) y `CotizacionController::guardarLineas()` lo llena de una sola consulta
(`Articulo::whereIn('id', ...)->pluck('costo_con_descuento', 'id')`) en vez de una por línea; queda
`null` cuando no hay `articulo_id`. `duplicar()` copia el `costo_unitario` de la línea original,
igual que ya hacía con `precio_unitario`. No hay migración de datos: las cotizaciones ya existentes
quedan con `costo_unitario` nulo en todas sus líneas, tal como anticipaba la spec. Cubierto por 5
tests nuevos en `TesoreriaTest.php` (668 tests del backend, todos en verde); Pint limpio.

## Criterios de aceptación

1. Un usuario autenticado puede crear una cotización seleccionando un cliente y una o varias
   líneas de artículo, viendo los totales desglosados en tiempo real, quedando en estado
   `borrador`.
2. Enviar una cotización por correo adjunta el PDF generado al vuelo y cambia su estado a `enviada`;
   compartirla por WhatsApp entrega ese mismo PDF al menú del aparato y la deja igualmente
   `enviada` (ver [029](029-pwa-mostrador.md)).
3. Registrar un pago (anticipo, saldo o pago total) que, sumado a los pagos previos, alcance el
   total de la cotización, cambia automáticamente su estado a `pagada`; un pago parcial no lo
   hace. Ningún pago puede hacer que la suma acumulada supere el total: si el monto resultante lo
   excede, la petición se rechaza con error de validación.
4. Una cotización admite como máximo un pago `tipo = anticipo`; intentar registrar un segundo
   anticipo para la misma cotización se rechaza con error de validación.
5. Los botones de pago del detalle de la cotización dependen del historial de pagos y del saldo
   pendiente, no solo del estado: "Registrar anticipo" y "Pago total" solo son visibles si la
   cotización todavía no tiene ningún pago `tipo = anticipo`; "Registrar saldo" solo es visible si
   el saldo pendiente es mayor a 0. Ninguno de los tres aparece si el estado no es `enviada`.
6. Los modales de "Pago total" y "Registrar saldo" muestran el saldo pendiente (total menos pagos
   ya registrados) como texto de confirmación, sin un campo de monto editable.
7. Una cotización `pagada` puede marcarse como `producto_entregado` manualmente; no puede
   marcarse así desde ningún otro estado.
8. Una cotización solo es editable en `borrador`/`enviada`; editar una `enviada` la regresa a
   `borrador`. No es editable ni eliminable en `pagada`/`producto_entregado`.
9. Una cotización `borrador` o `enviada` sin pagos registrados puede eliminarse (borrado físico,
   se lleva sus líneas), tanto desde el listado como desde el detalle. Si tiene algún pago
   registrado o una Orden de Trabajo (038), el borrado se rechaza con error de validación y el botón
   no se muestra; la purga automática tampoco la toca.
10. Una cotización puede facturarse desde cualquiera de sus 4 estados. Al hacerlo sin factura
    asociada, se navega al formulario de crear factura precargado (cliente fijo, líneas editables).
11. Si la factura asociada queda `pendiente` (timbrado fallido), el botón "Facturar" de la
    cotización redirige a esa factura existente en vez de crear una nueva.
12. Una vez que la factura asociada llega a `timbrada`, el botón "Facturar" de la cotización queda
    deshabilitado.
13. Si el `total` recalculado por el backend no coincide con el enviado por el frontend (al crear
    o editar la cotización), la petición se rechaza con error de validación.
14. El listado `/cotizaciones` filtra por cliente, RFC, folio y estado de forma combinable, y por
    rango de fecha (con atajos Hoy/Esta semana/Este mes, mostrando "Este mes" por defecto).
15. "Duplicar" una cotización crea una copia nueva en `borrador`, con folio propio, mismos
    cliente/líneas/descuento global, sin pagos ni factura asociada.
16. Elegir en el buscador un artículo que ya está capturado en el documento no agrega una segunda
    línea: abre un aviso de duplicado que muestra la línea existente con su cantidad actual y ofrece
    sumar unidades a esa línea o cancelar. Después de N repeticiones del mismo artículo, el
    documento sigue teniendo una sola línea de ese artículo.
17. Al confirmar "sumar", la cantidad indicada (por defecto 1) se suma a la cantidad de la línea
    existente y los totales se recalculan; el precio unitario, la descripción, el modelo, el
    descuento y la tasa de IVA de esa línea quedan intactos. Al cancelar, no cambia nada.
18. `cotizaciones:purgar-vencidas` elimina las cotizaciones `borrador`/`enviada` cuyo `updated_at`
    tiene más de 30 días, y deja intactas las de cualquier otro estado, las que tienen pagos
    registrados, las ya facturadas y las tocadas dentro de los últimos 30 días. Correrlo dos veces
    seguidas no borra nada la segunda vez.
19. Cuando faltan 7 días o menos para el borrado automático, el listado marca la fila y el detalle
    muestra el aviso con la fecha de eliminación y los días restantes. Editar o reenviar la
    cotización reinicia el plazo y el aviso desaparece.
20. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.
21. Cada línea de cotización con `articulo_id` guarda, al crearse o al volver a guardarse en una
    edición, el costo con descuento del artículo vigente en ese momento (`costo_unitario`); ese valor
    no cambia después aunque el costo del artículo cambie. Las cotizaciones creadas antes de este
    cambio quedan con `costo_unitario = null` en todas sus líneas.

## Supuestos asumidos (registro completo)

1. "Cotización" es una entidad propia del usuario dueño de la cuenta (mono-usuario, sin
   multiempresa), mismo patrón que 004/006/007.
2. Una cotización pertenece a un `Cliente` del catálogo de 004 (obligatorio).
3. Una cotización se compone de una o varias líneas de artículo (del catálogo de 006), con la
   misma estructura que `FacturaLinea` de 007.
4. Los totales de la cotización se calculan con el mismo algoritmo de dos pasadas que
   `FacturaTotalesCalculator` (007), incluyendo un descuento global opcional y el ajuste al peso
   cerrado de [030](030-total-al-peso-cerrado.md): el total de la cotización y el de la factura que
   sale de ella son el mismo número.
5. Los 4 estados (`borrador`, `enviada`, `pagada`, `producto_entregado`) son secuenciales y no hay
   retroceso automático entre ellos, salvo el caso del supuesto 18.
6. **(Redefinido dos veces)** El paso `borrador` → `enviada` ocurre al enviar la cotización al
   cliente por **correo o WhatsApp**, ambos dentro del alcance de esta historia. El WhatsApp salía
   por Twilio; desde [029](029-pwa-mostrador.md) se comparte desde el aparato del usuario y el
   estado lo mueve `marcar-enviada`.
7. El envío por correo de la cotización adjunta un PDF generado al vuelo (sin persistirse), con el
   correo del cliente prellenado; la cotización no tiene XML (no es CFDI).
8. El "anticipo" y el "saldo" son registros de pago capturados manualmente sobre la cotización
   (fecha, monto, forma de pago) — no son CFDI ni pasan por facturapi.io; distintos del
   `ComplementoPago` fiscal de 007.
9. **(Redefinido/precisado)** El paso `enviada` → `pagada` ocurre automáticamente en cuanto la
   suma acumulada de los pagos registrados alcanza o supera el total de la cotización — cubre
   tanto el flujo anticipo+saldo como el pago del 100% en un solo registro. Existe un botón "Pago
   total" que autocalcula el saldo pendiente (total menos pagos ya registrados).
10. El paso `pagada` → `producto_entregado` es una acción manual del usuario ("Marcar como
    entregado"), sin validación de inventario/logística.
11. La cotización puede facturarse en cualquier estado del ciclo, sin bloquear por pagos
    pendientes.
12. **(Adición)** Una cotización tiene, como máximo, una factura asociada (relación 1:1 opcional).
    Existe además una función "Duplicar cotización" que crea una copia con los mismos valores
    (folio nuevo, `borrador`, sin factura ni pagos asociados).
13. **(Redefinido)** Al presionar "Facturar" sin factura asociada, el sistema navega al formulario
    manual de creación de factura de 007 (`/facturas/crear?cotizacion_id=...`), precargado con el
    cliente (fijo, no editable) y las líneas de la cotización (editables) — no se timbra directo.
14. Si el timbrado desde ese formulario precargado falla y la factura queda `pendiente`, el
    usuario es igualmente redirigido a la vista de esa factura (para reintentar).
15. Mientras la factura asociada esté `pendiente`, el botón "Facturar" de la cotización redirige a
    esa factura existente en vez de crear una nueva.
16. El botón "Facturar" se deshabilita únicamente cuando la factura asociada llega a `timbrada`.
17. **(Adición)** Si la factura asociada termina `cancelada`, no hay re-facturación automática
    para esa cotización; la vía de recuperación es duplicarla (supuesto 12) y facturar la copia.
18. **(Redefinido)** La cotización es editable en `borrador` y `enviada` (incluso con anticipo ya
    pagado); se bloquea totalmente al llegar a `pagada` (y por extensión `producto_entregado`).
    Editar una cotización `enviada` la regresa a `borrador`, obligando a reenviarla para notificar
    el cambio al cliente.
19. Solo se permite eliminar (borrado físico) una cotización en estado `borrador`.
20. La cotización tiene su propio folio autoincremental por usuario, numeración independiente del
    folio de `Factura`.
21. No existe un flujo de cancelación propio de la cotización.
22. **(Redefinido)** El listado `/cotizaciones` tiene búsqueda/filtro por columna (cliente, RFC,
    folio, estado), combinables entre sí, más un filtro de fecha con atajos "Hoy"/"Esta
    semana"/"Este mes" (por defecto "Este mes") y rango personalizado — no un único buscador
    global como en 007.
23. **(Adición técnica)** Nuevos modelos `Cotizacion` + `CotizacionLinea`, estructura espejo de
    `Factura`/`FacturaLinea` de 007 pero sin campos de timbrado.
24. **(Adición técnica)** Los pagos se registran en una tabla separada `CotizacionPago` (no dos
    columnas fijas), permitiendo historial de múltiples pagos por cotización.
25. **(Adición técnica)** FK nullable `factura_id` en `Cotizacion` para la relación 1:1 opcional.
26. **(Adición técnica, redefinida)** No se crea un endpoint especial de "facturar" que timbre
    directo; se reutiliza `POST /api/v1/facturas` de 007, extendido para aceptar un
    `cotizacion_id` opcional que vincula la factura resultante de vuelta a la cotización.
27. **(Adición técnica)** Se reutiliza la plantilla PDF de 003/007 (adaptada) para el PDF de
    cotización, y el mecanismo de envío por correo (mailable) de 007 con una plantilla propia.
28. **(Adición técnica, redefinida por [029](029-pwa-mostrador.md))** El envío por WhatsApp lo hace
    el propio aparato del usuario, compartiendo el PDF que el sistema descarga con su sesión. La
    versión original lo mandaba con Twilio y, para que pudiera descargar el adjunto, exponía un
    endpoint de descarga temporal firmado sin autenticación de sesión; ese endpoint se retiró con el
    canal.
29. **(Adición)** Un artículo aparece como máximo en una línea por documento. Capturar dos veces el
    mismo artículo se resuelve sumando unidades a la línea que ya existe, nunca creando una segunda
    línea, porque repetir el mismo modelo en varios renglones es siempre un error de captura en este
    negocio (no hay precios distintos para el mismo artículo dentro de un documento).
30. **(Adición técnica)** La detección de duplicados y su diálogo viven en el componente compartido
    de líneas (`DocumentoLineas`), no en cada formulario, y por eso aplican por igual a factura
    (007), cotización (008) y orden de compra (012). El componente ya conoce las líneas capturadas y
    es el único que agrega líneas desde el buscador, así que no necesita datos ni props nuevas para
    decidir si hay duplicado.
31. **(Adición técnica)** La regla se aplica en el frontend, sin validación nueva en el backend: los
    Form Requests siguen aceptando un arreglo de líneas con `articulo_id` repetido. Rechazarlo con
    `422` cambiaría el contrato de los tres endpoints de documentos y bloquearía la edición de
    documentos guardados antes de esta regla, sin ganancia real mientras el buscador sea la única
    vía de captura.
32. **(Redefinido)** Se puede eliminar una cotización en `borrador` **y** en `enviada`: una
    cotización enviada que el cliente nunca aprobó (pidió otra, o la dejó en visto) es basura que
    el usuario debe poder tirar sin pasar por un estado intermedio. El borrado sigue siendo físico
    y definitivo, sin papelera ni soft delete.
33. **(Adición)** Ninguna cotización con pagos registrados se elimina, ni a mano ni por caducidad,
    aunque siga en `borrador`/`enviada`: sus pagos tienen movimientos de Tesorería (010) y solo el
    endpoint de pagos sabe revertirlos. Para borrarla hay que eliminar antes sus pagos. Lo mismo
    aplica a una cotización ya facturada (`factura_id` no nulo).
34. **(Adición)** Una cotización en `borrador` o `enviada` que pasa 30 días sin ningún movimiento
    se elimina automáticamente. "Sin movimiento" se mide contra `updated_at`, así que crearla,
    editarla, enviarla o registrarle un pago reinicia el plazo. `pagada` y `producto_entregado` no
    caducan nunca.
35. **(Adición)** El borrado automático se avisa antes: faltando 7 días o menos, el listado y el
    detalle muestran la fecha de eliminación y los días restantes, junto con la forma de evitarlo
    (tocar la cotización). Es la única protección contra la pérdida de una cotización que sí
    importaba, porque el borrado es físico.
36. **(Adición técnica)** La caducidad se implementa como comando artisan
    (`cotizaciones:purgar-vencidas`) registrado en el scheduler diario de Laravel, no como un
    barrido dentro de las peticiones de listado: el borrado es una tarea de mantenimiento y no
    tiene por qué colgarse de que alguien abra una pantalla.
37. **(Adición técnica)** Como el entorno es Windows/Laragon y no hay cron, el scheduler depende de
    una tarea programada de Windows que ejecute `php artisan schedule:run` cada minuto. Es
    configuración fuera del repositorio: se documenta en el README del backend, y mientras no
    exista, la caducidad solo ocurre al correr el comando a mano.
38. **(Adición técnica, [010](010-tesoreria.md))** `CotizacionLinea` gana `costo_unitario`, una
    copia del `costo_con_descuento` del artículo tomada al guardar la línea, igual que
    `precio_unitario` ya es una copia desacoplada del catálogo. Es la base de la utilidad de venta
    que Tesorería expone en sus movimientos automáticos; sin este dato ese cálculo no existiría.
39. **(Adición técnica, [010](010-tesoreria.md))** No hay migración de datos que reconstruya
    `costo_unitario` para cotizaciones ya existentes: el costo histórico del artículo ya no es el
    mismo que el actual, así que inventar el valor con datos de hoy produciría una utilidad falsa
    para ventas pasadas. Esas cotizaciones quedan sin utilidad calculable de forma permanente.
