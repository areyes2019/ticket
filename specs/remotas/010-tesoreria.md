# Spec: Tesorería (cuentas, movimientos financieros y saldos)

## Historia de usuario

Como usuario registrado, quiero administrar el flujo de dinero del negocio en un módulo propio,
para registrar y consultar todos los movimientos financieros que afectan mis cuentas —vengan de
otro módulo del sistema o los capture yo a mano— y conocer en todo momento el saldo real de cada
cuenta.

## Objetivo / Alcance

Implementar el módulo de Tesorería sobre la base ya existente de Laravel API + Vue 3 SPA + Sanctum
(ver [001](001-inicio-proyecto.md), [002](002-login-auth.md)), el design system de
[003](003-design-system-tailwind.md), y conectado al módulo de [Cotizaciones](008-cotizaciones.md).
Incluye: administración de cuentas financieras, registro manual de ingresos, egresos,
transferencias entre cuentas y ajustes de saldo, consulta de movimientos con filtros, consulta de
saldos por cuenta, la integración automática que convierte cada pago de cotización o de pedido en un
movimiento de ingreso, y — sobre esos dos orígenes — la utilidad de venta que le queda al usuario en
cada uno.

Tesorería **no** administra ventas, compras ni facturación; únicamente registra el efecto
financiero que dichos módulos producen. Ese desacoplamiento es deliberado: el módulo debe poder
reutilizarse tal cual en futuros sistemas (talleres, clínicas, puntos de venta) que necesiten
administrar ingresos, egresos y saldos.

**No** incluye: modificación alguna al módulo de [Facturación](007-facturacion.md), Órdenes de
Compra, pantallas de reportes financieros propias, múltiples divisas, ni multiempresa.

### Relación con Facturación: quién es la fuente de verdad de los pagos

Una decisión de arquitectura central de esta historia: **la Cotización es la fuente de verdad de
los pagos recibidos; la Factura solo registra el movimiento fiscal (el CFDI)**.

En consecuencia, el módulo de Facturación (007) **no se modifica en absoluto** en esta historia:
timbrar una factura no genera ningún movimiento financiero (RN-003), no se agrega ningún campo de
cuenta a `Factura` ni a `ComplementoPago`, no se crea ninguna acción de "registrar pago" sobre una
factura, y el `forma_pago` (catálogo SAT `c_FormaPago`) de `ComplementoPago` se conserva intacto
porque es un dato fiscal real exigido por el CFDI. La única fuente de ingresos automáticos en
Tesorería es `CotizacionPago` (008).

## Backend (Laravel)

### Modelo `Cuenta`

Representa el lugar donde se almacena el dinero del negocio (Caja General, BBVA, Banamex, Mercado
Pago, PayPal…).

- Pertenece a un `User` (`user_id`); mono-usuario, sin multiempresa (mismo patrón que
  004/005/006/007/008/009).
- `nombre`: string, obligatorio.
- `tipo`: enum fijo de backend (`efectivo`|`banco`|`digital`|`otro`), obligatorio. Sin catálogo
  externo ni endpoint propio: son 4 valores embebidos también en el frontend, mismo patrón que
  `metodo_pago` (PUE/PPD) en 007 y `objeto_imp` en 006.
- `saldo_inicial`: decimal(12,2), obligatorio (puede ser 0). **Inmutable tras la creación**: no se
  acepta en el Form Request de edición, y si se envía en el `PUT` se ignora (mismo patrón que
  `proveedor_id` de `Catalogo` en 009). Para corregirlo se registra un Ajuste, que deja rastro en
  el historial de movimientos.
- `saldo_actual`: decimal(12,2), **columna persistida y cacheada** (no calculada al vuelo en cada
  consulta). Se recalcula dentro de la misma transacción de base de datos cada vez que se crea o
  elimina un movimiento que afecte a esa cuenta, como
  `saldo_inicial + Σ(movimientos de la cuenta)`. Ver "Cálculo del saldo y concurrencia" abajo.
- `activa`: booleano, por defecto `true`. Una cuenta inactiva **no admite movimientos nuevos** de
  ningún tipo (ni manuales ni automáticos), pero conserva su historial y sigue apareciendo en el
  listado y en la consulta de saldos.
- Sin soft delete: solo se permite `DELETE` físico si la cuenta **nunca tuvo ningún movimiento**;
  si ya tiene historial, la petición se rechaza y la única vía es desactivarla.

### Modelo `Movimiento`

Representa cualquier operación que modifica el saldo de una cuenta.

- Pertenece a un `User` (`user_id`) y a una `Cuenta` (`cuenta_id`, obligatoria, debe pertenecer al
  mismo usuario y estar activa al momento de registrarse).
- `tipo` (enum): `ingreso`|`egreso`|`transferencia`|`ajuste`.
- `monto`: decimal(12,2). Positivo para `ingreso`; positivo para `egreso` (el signo lo determina el
  `tipo`, no el valor capturado); **positivo o negativo** para `ajuste`, según si corrige de más o
  de menos.
- `fecha`: date, obligatoria — la fecha **real** en que ocurrió el movimiento (RN-008), no la fecha
  de captura. Se interpreta como día calendario en la zona horaria del negocio
  (`America/Mexico_City`, fija — mismo criterio que el filtro de fechas de 008).
- `concepto`: string, obligatorio. De captura libre para movimientos manuales (RN-010); generado
  por el sistema y **no editable** para movimientos automáticos (RN-009).
- `es_automatico`: booleano derivado de si tiene documento origen. Los movimientos automáticos son
  de **solo lectura** desde Tesorería: no se pueden editar ni eliminar (RN-011); su corrección se
  hace desde el documento origen.
- **Documento origen** (`documentable_type`/`documentable_id`, relación polimórfica `morphTo`,
  nullable): apunta al registro que originó el movimiento. Hoy solo se llena con `CotizacionPago`;
  queda lista para que futuros módulos (Órdenes de Compra, Ventas, etc.) se enganchen sin
  necesitar una migración nueva. `null` para movimientos manuales.
- `transferencia_id`: uuid/string nullable — identificador compartido por las **dos** filas que
  componen una transferencia (ver abajo).
- Sin soft delete.

#### Transferencias: dos movimientos vinculados

Una transferencia traslada dinero entre dos cuentas propias y **no representa un ingreso ni un
egreso del negocio**. De cara al usuario es una sola operación (cuenta origen, cuenta destino,
monto, fecha), pero se persiste como **dos filas de `Movimiento`** con `tipo = transferencia`,
vinculadas por un mismo `transferencia_id`:

- una fila sobre la cuenta origen, que resta del saldo;
- una fila sobre la cuenta destino, que suma al saldo.

Así cada `Movimiento` mantiene una sola `cuenta_id` y un solo efecto sobre el saldo, lo que
simplifica los filtros por cuenta y el cálculo de saldos; el `transferencia_id` las mantiene
correlacionadas para editarlas o eliminarlas siempre juntas, como una unidad.

Como las dos filas se compensan entre sí, una transferencia **no altera el total global de dinero
del negocio**, solo su distribución entre cuentas.

### Cálculo del saldo y concurrencia

`Cuenta.saldo_actual` considera saldo inicial, ingresos, egresos, transferencias y ajustes
(RN-007), y se afecta **inmediatamente** al registrar el movimiento (RN-005).

Toda operación que cree, edite o elimine un movimiento corre dentro de una transacción de base de
datos que:

1. bloquea la(s) fila(s) de `Cuenta` involucrada(s) con `lockForUpdate()`;
2. valida la regla de saldo no negativo (ver Validaciones);
3. persiste el/los `Movimiento`(s);
4. recalcula y guarda `saldo_actual`.

El bloqueo explícito evita que dos movimientos simultáneos sobre la misma cuenta compitan y dejen
el saldo mal calculado. Es el mismo tipo de riesgo que el folio de `Factura` documentado en 007
como riesgo menor conocido, pero aquí **sí se mitiga**, porque afecta el saldo real de dinero y no
solo un identificador interno. En una transferencia se bloquean ambas cuentas.

### Integración con Cotizaciones (008)

#### Cambio sobre `CotizacionPago`: `cuenta_id` reemplaza a `forma_pago`

Hoy `CotizacionPago` guarda `forma_pago` (catálogo SAT `c_FormaPago`), que en 008 quedó como un
dato **meramente informativo**: una cotización no es un CFDI, así que ese valor nunca se timbra ni
se envía a facturapi.io. Esta historia lo reemplaza por algo que sí tiene efecto real:

- Se agrega `cuenta_id` (FK a `Cuenta`, **obligatoria**, debe pertenecer al usuario y estar
  activa).
- Se elimina la columna `forma_pago` de `cotizacion_pagos` y su regla de validación en
  `CotizacionPagoRequest`.
- En el frontend, el `Select` de forma de pago SAT de los modales de "Registrar anticipo" / "Pago
  total" / "Registrar saldo" (008) se reemplaza por un `Select` de cuentas del usuario.

Esto **no toca** el `forma_pago` de `ComplementoPago` (007), que sí es un dato fiscal real del
CFDI y se conserva sin cambios.

#### Generación del movimiento automático

Al registrarse un `CotizacionPago` (cualquiera de sus tres tipos: `anticipo`, `saldo` o
`pago_total`), se crea **inmediatamente** un `Movimiento` de `tipo = ingreso` (RN-002) sobre la
cuenta elegida, con:

- `monto` = el monto del pago;
- `fecha` = la `fecha_pago` capturada;
- `documentable` = ese `CotizacionPago`;
- `concepto` generado por el sistema a partir del documento origen (RN-009), con el formato
  `"<Tipo de pago> de Cotización COT-<folio>"`:
  - `anticipo` → `"Anticipo de Cotización COT-00015"`
  - `saldo` → `"Saldo de Cotización COT-00015"`
  - `pago_total` → `"Pago total de Cotización COT-00015"`

Crear la cotización (RN-001) o timbrar la factura resultante (RN-003) **no** generan ningún
movimiento; solo el pago lo hace.

#### Corrección de un pago mal capturado: `DELETE` del último pago

008 no expone hoy ninguna forma de editar o eliminar un `CotizacionPago` ya registrado, lo que
dejaría a RN-011 ("la corrección se hace desde el documento origen") sin una vía real de
ejecución. Se agrega:

- `DELETE /api/v1/cotizaciones/{cotizacion}/pagos/{pago}` — permitido **solo sobre el pago más
  reciente** de esa cotización (criterio LIFO: el de `created_at` mayor). Intentar eliminar
  cualquier otro se rechaza con `422`.
- Al eliminarlo, dentro de la misma transacción: se elimina también su `Movimiento` asociado y se
  recalcula el `saldo_actual` de la cuenta afectada.
- Si la cotización estaba en estado `pagada` y, tras la eliminación, la suma acumulada de pagos ya
  no alcanza el `total`, su estado regresa a `enviada` (revierte la transición automática descrita
  en 008). Una cotización en `producto_entregado` no admite eliminación de pagos (`422`): primero
  tendría que revertirse esa entrega, lo cual queda fuera del alcance de esta historia.

El criterio LIFO mantiene coherente el historial de pagos: como el `monto` de `saldo`/`pago_total`
se autocalcula en 008 a partir de los pagos previos, eliminar un pago intermedio dejaría a los
posteriores con montos que ya no corresponden al saldo pendiente que tenían al registrarse.

#### RN-013: documento con movimientos no se elimina

Se cumple de forma natural con las reglas ya vigentes de 008, sin validación adicional: una
cotización solo puede eliminarse en estado `borrador`, y en `borrador` todavía no puede tener
ningún pago registrado (los botones de pago solo aparecen en `enviada`). Por lo tanto, ninguna
cotización con movimientos financieros asociados es elegible para borrado.

### Integración con Pedidos (027)

Los pagos de un `Pedido` (venta de mostrador, [027](027-venta-mostrador-ticket.md)) generan
movimientos de ingreso por el mismo mecanismo que los de Cotización: al registrarse un `PedidoPago`
—el anticipo, un abono o el saldo cobrado al entregar— `TesoreriaService` crea de inmediato un
`Movimiento` de `tipo = ingreso` sobre la cuenta elegida, con `documentable` apuntando a ese
`PedidoPago` y con el concepto generado por el sistema que ya define 027 (`"Pago de Pedido
PED-00042"` o `"Saldo al entregar de Pedido PED-00042"`, según el caso).

Lo que faltaba —y que esta historia corrige— es que `MovimientoResource::documentoOrigen()` solo
reconocía `CotizacionPago` y `OrdenCompra`. Un movimiento generado por un `PedidoPago` ya era
automático internamente (`es_automatico = true`, acciones de editar/eliminar deshabilitadas por esa
misma bandera), pero al no tener documento origen expuesto aparecía en `/tesoreria/movimientos` con
Origen "Manual" y sin enlace, indistinguible de un movimiento capturado a mano. Se agrega el tercer
caso:

```php
if ($documento instanceof PedidoPago) {
    $pedido = $documento->pedido;

    return [
        'tipo' => 'pedido',
        'etiqueta' => 'PED-'.str_pad((string) $pedido->folio, 5, '0', STR_PAD_LEFT),
        'ruta' => 'pedidos-detalle',
        'id' => $pedido->id,
    ];
}
```

Con esto, un movimiento originado por un Pedido enlaza a `/pedidos/{id}` igual que uno de Cotización
enlaza a `/cotizaciones/{id}`.

### RN-014 y RN-015, cerradas

Cuando se escribió esta historia, ni Órdenes de Compra ni un módulo de Ventas propio existían
todavía: el mecanismo quedó genérico (la relación polimórfica `documentable` de `Movimiento`, más el
servicio que crea el movimiento y recalcula el saldo) a la espera de que alguno los usara. Hoy los
dos existen y ya lo hacen:

- **Órdenes de Compra ([012](012-ordenes-compra.md))**: pagar una orden de compra genera un
  `Movimiento` de `tipo = egreso` sobre la cuenta elegida, con `documentable` apuntando a la
  `OrdenCompra`. `MovimientoResource` ya lo reconoce.
- **Pedidos ([027](027-venta-mostrador-ticket.md))**: cubre RN-015 ("los ingresos generados por
  ventas producen movimientos automáticos"), tal como se anticipaba. Ver "Integración con Pedidos"
  arriba para el detalle y la corrección que faltaba.

Esta especificación no repite la lógica de negocio propia de esos dos módulos (folios, estados,
validaciones): esa vive en 012 y 027. Aquí solo importa su efecto sobre Tesorería.

### Utilidad de venta en movimientos automáticos

Un movimiento que viene de una venta cobrada —Cotización o Pedido— expone además cuánto le quedó de
utilidad al usuario por esa venta, para responder justo lo que un movimiento manual como "Ventas
Varias" no puede: de dónde salió ese ingreso y qué tan rentable fue.

**Solo aplica a estos dos orígenes.** Un movimiento manual, uno de transferencia, uno de ajuste o uno
que viene de una Orden de Compra (egreso) no tiene utilidad que mostrar: el dato queda vacío. La
utilidad tampoco existe para Facturación, que —como ya establece esta especificación— nunca genera
movimientos en Tesorería.

**Es la utilidad del documento completo, no la del pago.** Si una cotización se cobra en dos partes
(anticipo y saldo), los dos movimientos muestran el mismo monto: la utilidad total de esa cotización,
no una fracción proporcional al pago que generó cada uno. Se calcula sin importar si el documento ya
está pagado al 100% o apenas tiene un anticipo — es una propiedad del documento vendido, no del corte
de caja.

**De dónde sale el número.** Requiere que cada línea del documento sepa cuánto costó el artículo en
el momento en que se vendió (ver "Costo capturado por línea" en [008](008-cotizaciones.md) y
[027](027-venta-mostrador-ticket.md)). Con eso:

```
utilidad(documento) = Σ ( importe_línea − costo_unitario_línea × cantidad_línea )
```

sumando solo las líneas que tienen `costo_unitario` (líneas con artículo del catálogo).
`importe_línea` ya es el neto sin IVA de esa línea **después** de su propio descuento y de la
porción que le tocó del descuento global (el prorrateo que 007/008 ya resuelven), así que la
utilidad se mide contra lo que realmente se cobró, no contra el precio de lista — mismo criterio que
[011](011-precio-proveedor-utilidad.md) usa para la utilidad de un artículo.

**Dos casos en los que el número no es un total limpio:**

- **Líneas libres (solo en Pedido, [027](027-venta-mostrador-ticket.md))**: una línea sin
  `articulo_id` no tiene costo conocido y se excluye de la suma. Si el pedido mezcla líneas de
  catálogo con líneas libres, la utilidad mostrada cubre solo las primeras y el documento queda
  marcado como **parcial** (`utilidad_parcial = true`), en vez de dar a entender un total que no lo
  es.
- **Documentos de antes de este cambio**: una cotización o pedido creado antes de que existiera
  `costo_unitario` no tiene ese dato en ninguna de sus líneas. Ahí no hay nada que sumar y el
  movimiento expone `utilidad = null` (**"utilidad no disponible"**, distinto de $0.00): reconstruir
  ese costo con el `costo_con_descuento` *actual* del artículo daría un número falso, porque el costo
  del artículo pudo cambiar desde entonces.

**Exposición.** `MovimientoResource::documentoOrigen()` agrega, solo para los tipos `cotizacion` y
`pedido`:

```php
'utilidad' => float|null,       // null = no disponible (documento sin costo capturado)
'utilidad_parcial' => bool,     // true = hay líneas sin costo excluidas de la suma
```

El cálculo vive en un método propio de `Cotizacion`/`Pedido` (`utilidadVenta()`, compartido por un
trait ya que la fórmula es idéntica en ambos modelos), no en `MovimientoResource`, para que cualquier
otra pantalla que en el futuro necesite este número no tenga que ir a buscarlo a Tesorería.

### Endpoints (bajo `auth:sanctum`, scopeados al usuario autenticado)

**Cuentas (UC-01, UC-07)**

- `GET /api/v1/cuentas` — listado paginado (nombre, tipo, saldo inicial, saldo actual, activa), con
  `?search=` por nombre y `?activa=` opcional. Alimenta también los selectores de cuenta del
  frontend.
- `POST /api/v1/cuentas` — alta (nombre, tipo, saldo inicial; `saldo_actual` arranca igual al
  inicial).
- `GET /api/v1/cuentas/{id}` — detalle.
- `PUT /api/v1/cuentas/{id}` — edición de `nombre`, `tipo` y `activa` únicamente; `saldo_inicial`
  es inmutable y se ignora si se envía.
- `DELETE /api/v1/cuentas/{id}` — borrado físico, solo si la cuenta no tiene ningún movimiento;
  `409 Conflict` con mensaje específico ("No se puede eliminar: la cuenta tiene movimientos
  registrados") en caso contrario, mismo patrón que el `409` de `Catalogo`/`Proveedor` en 009/005.
- `GET /api/v1/cuentas/saldos` — **UC-07**: saldo actual de todas las cuentas del usuario (activas
  e inactivas), sin paginar y sin desglose de movimientos, más el total global sumado.

**Movimientos (UC-02, UC-03, UC-05, UC-06)**

- `GET /api/v1/movimientos` — **UC-06**: listado paginado, ordenado por `fecha` descendente, con
  filtros **combinables entre sí**: `?fecha_desde=`/`?fecha_hasta=` (rango), `?cuenta_id=`,
  `?tipo=` (`ingreso`|`egreso`|`transferencia`|`ajuste`) y `?concepto=` (búsqueda de texto libre).
  Cada movimiento expone su documento origen cuando lo tiene. Cuando ese documento origen es una
  Cotización o un Pedido, además expone la utilidad de venta calculada (ver "Utilidad de venta en
  movimientos automáticos").
- `POST /api/v1/movimientos` — **UC-02/UC-03/UC-05**: registra un movimiento manual de una sola
  cuenta; body `{ tipo: ingreso|egreso|ajuste, cuenta_id, monto, fecha, concepto }`.
- `GET /api/v1/movimientos/{id}` — detalle.
- `PUT /api/v1/movimientos/{id}` — edición; **solo si el movimiento es manual** (`422` si es
  automático, RN-011/RN-012). Recalcula el saldo de la cuenta afectada.
- `DELETE /api/v1/movimientos/{id}` — eliminación; solo si es manual (`422` si es automático).
  Recalcula el saldo. Eliminar cualquiera de las dos filas de una transferencia elimina ambas.

**Transferencias (UC-04)**

- `POST /api/v1/transferencias` — body `{ cuenta_origen_id, cuenta_destino_id, monto, fecha,
  concepto }`; crea las dos filas de `Movimiento` vinculadas por un `transferencia_id` compartido,
  dentro de una sola transacción, y recalcula el saldo de ambas cuentas.

Se usa un endpoint dedicado (en vez de meter la transferencia en `POST /api/v1/movimientos`) porque
sus validaciones y su forma son distintas: dos cuentas en vez de una, y dos filas persistidas en
vez de una.

### Validaciones (Form Requests)

**Cuenta**

- `nombre`: requerido, string.
- `tipo`: requerido, uno de `efectivo`/`banco`/`digital`/`otro`.
- `saldo_inicial`: requerido en el alta, numérico, ≥ 0. **No aceptado en la edición** (se ignora si
  se envía).
- `activa`: booleano, opcional (default `true`).

**Movimiento manual**

- `tipo`: requerido, uno de `ingreso`/`egreso`/`ajuste` (`transferencia` no se acepta por este
  endpoint).
- `cuenta_id`: requerido, existe, pertenece al usuario autenticado y está **activa** (`422` si está
  inactiva).
- `monto`: requerido, numérico. Mayor a 0 para `ingreso`/`egreso`; distinto de 0 para `ajuste`
  (acepta valores negativos).
- `fecha`: requerida, formato fecha.
- `concepto`: requerido, string.
- **Saldo no negativo**: si el movimiento dejaría el `saldo_actual` de la cuenta por debajo de 0,
  la petición se rechaza con `422` y un mensaje específico ("El movimiento dejaría la cuenta con
  saldo negativo"). Aplica a `egreso`, a `ajuste` negativo y a la cuenta origen de una
  transferencia. La validación se evalúa **dentro de la transacción, con la cuenta ya bloqueada**,
  para que el saldo contra el que se compara no pueda cambiar entre la validación y la escritura.

**Transferencia**

- `cuenta_origen_id` / `cuenta_destino_id`: ambos requeridos, existen, pertenecen al usuario, están
  activos y son **distintos entre sí** (`422` si son iguales).
- `monto`: requerido, numérico, mayor a 0.
- `fecha`: requerida.
- `concepto`: requerido, string.
- Misma validación de saldo no negativo sobre la cuenta origen.

**Pago de cotización (modificación a 008)**

- `cuenta_id`: requerido, existe, pertenece al usuario y está activa. Reemplaza a la regla de
  `forma_pago` (que se elimina).
- El resto de las reglas de `CotizacionPagoRequest` (un solo anticipo por cotización, monto
  autocalculado para `saldo`/`pago_total`, sin sobrepago) se conservan sin cambios.

Respuestas mediante Laravel API Resources (`CuentaResource`, `MovimientoResource`), consistente con
la convención de 001/004/005/006/007/008/009. `CotizacionPagoResource` cambia: expone la cuenta en
lugar de `forma_pago`.

## Frontend (Vue 3)

- **`/tesoreria/cuentas`** (protegida) — **UC-01**: listado paginado de cuentas (nombre, tipo,
  saldo inicial, saldo actual, estado activa/inactiva), con buscador por nombre y filtro por
  estado. Botones de crear, editar, activar/desactivar y eliminar.
- **`/tesoreria/cuentas/crear`** y **`/tesoreria/cuentas/{id}/editar`**: formulario con `nombre`
  (`Input`), `tipo` (`Select` de 4 opciones) y `saldo_inicial` (`Input` numérico). En la edición,
  el saldo inicial se muestra **de solo lectura**, con una nota indicando que para corregirlo se
  registre un ajuste. Confirmación (modal `Dialog`) antes de eliminar; si el backend responde `409`
  por tener movimientos, el mensaje se muestra dentro del propio diálogo (mismo patrón que 005/009)
  y se ofrece desactivar la cuenta como alternativa.
- **`/tesoreria/movimientos`** (protegida) — **UC-06**: listado paginado de movimientos (fecha,
  cuenta, tipo, concepto, monto, **utilidad**, documento origen), con los 4 filtros combinables
  (rango de fecha, cuenta, tipo, concepto). Los montos se muestran con signo y color según su efecto
  sobre el saldo. Los movimientos automáticos se distinguen visualmente y muestran un enlace a su
  documento origen (la cotización o el pedido); sus acciones de editar/eliminar aparecen
  **deshabilitadas**, con la indicación de que deben corregirse desde el documento origen.
  - **Columna Utilidad**: solo tiene contenido en los movimientos que vienen de una Cotización o de
    un Pedido. Muestra el monto en verde cuando hay un número; un texto discreto **"Parcial"**, con
    `title` explicando que el documento tiene líneas libres sin costo conocido, cuando
    `utilidad_parcial` es verdadero; y un guion con **"No disponible"** cuando el documento es
    anterior a este cambio y ninguna de sus líneas tiene costo capturado. El resto de los
    movimientos (manuales, transferencias, ajustes, egresos de Orden de Compra) deja la celda vacía.
  - Botones de acción: **"Registrar ingreso"**, **"Registrar egreso"**, **"Registrar
    transferencia"** y **"Registrar ajuste"**, cada uno abriendo su propio modal.
  - Modal de ingreso/egreso (**UC-02/UC-03**): cuenta, monto, fecha y concepto.
  - Modal de transferencia (**UC-04**): cuenta origen, cuenta destino, monto, fecha y concepto.
  - Modal de ajuste (**UC-05**): cuenta, monto (admite negativo), fecha y motivo.
- **`/tesoreria/saldos`** (protegida) — **UC-07**: lista de todas las cuentas (activas e inactivas)
  con su saldo actual y el total global, sin desglose de movimientos; cada renglón enlaza al
  listado de movimientos ya filtrado por esa cuenta.
- **Cambio en el detalle de cotización** (`/cotizaciones/{id}`, de 008): en los modales de
  "Registrar anticipo", "Pago total" y "Registrar saldo", el `Select` de forma de pago (catálogo
  SAT) se reemplaza por un `Select` de cuentas activas del usuario. El historial de pagos muestra la
  cuenta en lugar de la forma de pago, y cada pago ofrece **"Eliminar"** únicamente en el más
  reciente (con modal de confirmación que advierte que se revertirá el movimiento en Tesorería y,
  si aplica, el estado de la cotización).
- **Navegación**: se agrega al `AppLayout` una entrada de menú etiquetada **"Contabilidad"**, con
  acceso a Cuentas, Movimientos y Saldos. El nombre visible de cara al usuario es "Contabilidad";
  el nombre técnico del módulo, sus rutas (`/tesoreria/...`), modelos y clases siguen siendo
  "Tesorería"/`tesoreria`, consistentes con esta especificación y con las reglas de negocio.

## Fuera de alcance

- **Cualquier modificación al módulo de Facturación (007)**: no se agrega cuenta a `Factura` ni a
  `ComplementoPago`, no se crea una acción de "registrar pago" sobre facturas, y timbrar nunca
  genera movimientos financieros.
- Órdenes de Compra y un módulo de Ventas propio (RN-014/RN-015): el enganche genérico queda listo,
  pero no hay implementación ni caso de uso real en esta historia.
- Pantallas o endpoints de reportes financieros dedicados (totales por periodo, gráficas,
  exportación a Excel/PDF): UC-06 y UC-07 exponen los datos base, y sobre ellos se construirán en
  una historia futura.
- Múltiples divisas y tipo de cambio: todas las cuentas y movimientos son en MXN.
- Sobregiro o saldo negativo permitido en cualquier cuenta.
- Edición del `saldo_inicial` de una cuenta ya creada (la vía es un Ajuste).
- Conciliación bancaria, importación de estados de cuenta, o categorías/centros de costo sobre los
  movimientos.
- Adjuntar comprobantes (archivos) a un movimiento.
- **Utilidad en Facturación**: como Facturación no genera movimientos en Tesorería, tampoco expone
  utilidad en ningún punto de esta historia.
- **Reportes de rentabilidad agregados** (utilidad total del día/semana/mes, por cliente o por
  catálogo): la utilidad aquí es un dato por movimiento individual, visible al leer el listado; no
  hay pantalla ni endpoint que la sume ni la grafique. Sigue siendo una historia futura, tal como ya
  lo anticipaba [011](011-precio-proveedor-utilidad.md).
- **Recalcular el costo de documentos ya existentes**: ninguna cotización ni pedido creado antes de
  este cambio recibe un `costo_unitario` retroactivo. Sus movimientos muestran "utilidad no
  disponible" de forma permanente.
- **Desglose de utilidad por línea en la pantalla de Movimientos**: se muestra el total del
  documento, no un desglose línea por línea (ese detalle, si hace falta, se consulta en el propio
  detalle de la cotización o del pedido).
- Edición de un `CotizacionPago` ya registrado: solo se permite eliminar el más reciente y volver a
  capturarlo.
- Eliminación de pagos de una cotización en estado `producto_entregado`.
- Auditoría/bitácora de quién modificó o eliminó un movimiento manual.
- Roles/permisos diferenciados y multiempresa (mismo patrón que 004/005/006/007/008/009).

## Estado de implementación

Esta spec no traía originalmente una sección de estado; el módulo base de Tesorería (cuentas,
movimientos manuales, transferencias, ajustes y la integración con pagos de Cotización) ya estaba en
producción antes de esta nota. Lo que sigue documenta específicamente la ampliación de **utilidad de
venta en movimientos automáticos**, implementada el 2026-08-25:

- **`CalculaUtilidadVenta`** (`backend/app/Models/Concerns/CalculaUtilidadVenta.php`): trait
  compartido por `Cotizacion` y `Pedido` con el método `utilidadVenta()`. Suma
  `importe − costo_unitario × cantidad` sobre las líneas con `articulo_id`; si alguna de esas líneas
  no tiene `costo_unitario` capturado, devuelve `utilidad = null` en vez de sumar un total a medias.
- **`MovimientoResource::documentoOrigen()`** gana el caso `PedidoPago` (antes ausente por completo)
  y, para `CotizacionPago`/`PedidoPago`, agrega `utilidad` y `utilidad_parcial` llamando a
  `utilidadVenta()` sobre el documento.
- **`MovimientoController::index()`/`show()`** precargan `cotizacion.lineas`/`pedido.lineas` dentro
  del `morphWith` del documento origen, para no disparar una consulta por fila al calcular la
  utilidad de cada movimiento del listado.
- **`CotizacionController`/`PedidoController::guardarLineas()`** llenan `costo_unitario` con una
  sola consulta (`Articulo::whereIn('id', ...)->pluck('costo_con_descuento', 'id')`) en vez de una
  por línea; `CotizacionController::duplicar()` copia el `costo_unitario` de la línea original.
- Frontend: `DocumentoOrigen` (`frontend/src/stores/movimientos.ts`) gana `utilidad`/
  `utilidad_parcial`, y `MovimientosListView.vue` agrega la columna Utilidad (monto en verde/rojo
  según el signo, "Parcial" cuando hay líneas libres excluidas, "No disponible" cuando el documento
  no tiene costo capturado, vacía en el resto de los movimientos).
- Cubierto por 5 tests nuevos en `TesoreriaTest.php`; suite completa del backend en verde (668
  tests, 825 371 aserciones), Pint limpio. Frontend: `npm run build` (incluye `vue-tsc`), ESLint y
  Prettier limpios, Vitest en verde (96 tests).
- **No se pudo verificar visualmente en un navegador real** — se recomienda registrar el pago de una
  cotización y de un pedido con artículos de costo conocido y confirmar en pantalla que la columna
  Utilidad muestra el monto esperado, y que un pedido con una línea libre lo marca como "Parcial".

## Criterios de aceptación

1. Un usuario autenticado puede crear una cuenta capturando nombre, tipo (efectivo/banco/digital/
   otro) y saldo inicial; su saldo actual arranca igual al saldo inicial.
2. El saldo inicial de una cuenta no puede modificarse después de creada: el formulario de edición
   lo muestra de solo lectura y cualquier valor enviado en el `PUT` se ignora.
3. Una cuenta desactivada no admite movimientos nuevos de ningún tipo (`422`), pero su historial
   sigue visible y su saldo sigue apareciendo en la consulta de saldos.
4. Una cuenta sin movimientos puede eliminarse físicamente; una cuenta con movimientos responde
   `409` con un mensaje específico y no se elimina.
5. Registrar un ingreso, egreso o ajuste manual afecta de inmediato el saldo actual de la cuenta:
   el ingreso lo aumenta, el egreso lo disminuye, y el ajuste lo corrige según el signo del monto.
6. Un movimiento que dejaría el saldo de la cuenta por debajo de cero se rechaza con error de
   validación y no se persiste — aplica a egresos, ajustes negativos y a la cuenta origen de una
   transferencia.
7. Registrar una transferencia entre dos cuentas distintas disminuye el saldo de la cuenta origen y
   aumenta el de la destino por el mismo monto, dejando el total global de dinero sin cambio;
   intentar transferir entre una cuenta y ella misma se rechaza con error de validación.
8. Un movimiento manual puede editarse y eliminarse desde Tesorería, y el saldo de la cuenta
   afectada se recalcula correctamente en ambos casos.
9. Un movimiento automático no puede editarse ni eliminarse desde Tesorería: la API responde `422`
   y en la interfaz sus acciones aparecen deshabilitadas, indicando que la corrección se hace desde
   el documento origen.
10. Registrar un pago de cotización (anticipo, saldo o pago total) crea automáticamente un
    movimiento de ingreso en la cuenta elegida, con el monto y la fecha del pago, y con un concepto
    generado por el sistema con el formato "<Tipo de pago> de Cotización COT-<folio>".
11. El modal de registro de pago de una cotización pide una **cuenta** (no una forma de pago del
    catálogo SAT), y el historial de pagos muestra la cuenta con la que se registró cada uno.
12. Crear una cotización no genera ningún movimiento financiero, y timbrar una factura tampoco: el
    módulo de Facturación no produce movimientos en Tesorería en ningún punto de su flujo.
13. El `forma_pago` (catálogo SAT) del complemento de pago de una factura PPD sigue funcionando
    exactamente igual que antes de esta historia, sin campo de cuenta ni cambios en su timbrado.
14. Puede eliminarse el pago más reciente de una cotización; al hacerlo se elimina también su
    movimiento en Tesorería, se recalcula el saldo de la cuenta, y si la cotización estaba `pagada`
    y ya no alcanza su total, regresa a `enviada`.
15. Intentar eliminar un pago que no es el más reciente de la cotización se rechaza con error de
    validación.
16. El listado de movimientos filtra de forma combinable por rango de fecha, cuenta, tipo y
    concepto, y cada movimiento automático muestra su documento origen.
17. La consulta de saldos muestra el saldo actual de todas las cuentas (activas e inactivas) y el
    total global, sin desglose de movimientos.
18. El saldo actual de una cuenta siempre corresponde a su saldo inicial más la suma de todos sus
    movimientos (ingresos, egresos, transferencias y ajustes).
19. La navegación principal muestra la entrada "Contabilidad", desde la cual se accede a Cuentas,
    Movimientos y Saldos.
20. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.
21. Un movimiento cuyo documento origen es un `CotizacionPago` o un `PedidoPago` expone la utilidad
    de venta del documento completo; cualquier otro tipo de movimiento no expone utilidad.
22. Un documento cobrado en varias partes muestra la misma utilidad en cada uno de sus movimientos
    asociados, sin importar el monto de cada pago.
23. La utilidad se calcula como la suma, por cada línea con artículo de catálogo, del importe de
    venta (ya neto de descuentos) menos el costo capturado al momento de la venta; las líneas libres
    de un pedido quedan fuera de la suma.
24. Un pedido que mezcla líneas de catálogo con líneas libres muestra su utilidad marcada como
    parcial.
25. Una cotización o pedido creado antes de este cambio muestra "utilidad no disponible" en vez de
    un monto, porque no tiene costo capturado en sus líneas.
26. Un movimiento generado por el pago de un Pedido enlaza a su pedido en la columna Origen, igual
    que ya hacía uno de Cotización.
27. Pint y ESLint/Prettier corren sin errores sobre el código de esta ampliación.

## Supuestos asumidos (registro completo)

1. Tesorería es un módulo del usuario dueño de la cuenta (mono-usuario, sin multiempresa), mismo
   patrón que 004/005/006/007/008/009.
2. Una `Cuenta` tiene nombre, tipo (catálogo fijo `efectivo`/`banco`/`digital`/`otro`), saldo
   inicial y estado activa/inactiva.
3. Una cuenta inactiva no puede recibir movimientos nuevos, pero conserva su historial y sigue
   siendo consultable.
4. Una cuenta solo puede eliminarse (borrado físico) si nunca tuvo ningún movimiento asociado; si
   ya tiene movimientos, solo puede desactivarse.
5. El saldo inicial solo se define al crear la cuenta; para corregirlo después se usa un Ajuste, no
   una edición directa del campo (deja rastro en el historial).
6. El saldo actual de una cuenta nunca puede quedar en negativo: un egreso, transferencia o ajuste
   que lo dejaría por debajo de cero se rechaza.
7. Un ingreso o egreso manual requiere cuenta, monto positivo, fecha real del movimiento y concepto
   de captura libre.
8. Una transferencia requiere cuenta origen, cuenta destino (distintas entre sí), monto positivo y
   fecha; de cara al usuario es una sola operación, aunque internamente afecte dos cuentas.
9. Un ajuste requiere cuenta, monto (positivo o negativo, según si corrige de más o de menos),
   fecha y motivo de captura libre.
10. Un movimiento manual puede editarse o eliminarse libremente desde Tesorería, sin restricción de
    fecha ni estado.
11. Cada `CotizacionPago` (anticipo, saldo o pago total, ya existente en 008) genera
    automáticamente un movimiento de ingreso en el momento en que se registra.
12. **(Redefinido)** El modal de registro de pago de cotización (008) ya tenía un `Select`, pero de
    formas de pago del catálogo SAT — un dato meramente informativo, ya que una cotización no es un
    CFDI. Ese `Select` se **reemplaza** por uno de cuentas: `cuenta_id` sustituye a `forma_pago` en
    `CotizacionPago`, no se agrega junto a él.
13. **(Redefinido)** El módulo de Facturación (007) **no se modifica en absoluto**: no se le agrega
    campo de cuenta, no se crea ninguna acción de "registrar pago", y nunca genera movimientos
    financieros en Tesorería. **La Cotización es la fuente de verdad de los pagos; la Factura solo
    registra el movimiento fiscal (el CFDI).** El `forma_pago` SAT de `ComplementoPago` se conserva
    intacto por ser un dato fiscal real exigido por el CFDI.
14. Las Órdenes de Compra (RN-014) y un módulo de Ventas propio (RN-015) no existen todavía; el
    diseño deja el mecanismo de integración genérico, pero no hay caso de uso real de Órdenes de
    Compra en esta historia. RN-015 queda cubierta, en el estado actual del sistema, por los pagos
    de cotización.
15. Un movimiento automático no puede editarse ni eliminarse desde Tesorería, solo consultarse; su
    corrección requiere actuar sobre el documento origen.
16. RN-013 (un documento con movimientos no puede eliminarse) ya se cumple de forma natural con las
    reglas vigentes de 008 —una cotización solo se elimina en `borrador`, estado en el que todavía
    no puede tener pagos— sin necesidad de una validación adicional.
17. El concepto de un movimiento automático lo genera el sistema con texto fijo según el documento
    origen (`"<Tipo de pago> de Cotización COT-<folio>"`), no editable por el usuario.
18. La consulta de movimientos (UC-06) filtra de forma combinable por fecha (rango), cuenta, tipo y
    concepto (texto libre), mismo patrón de filtros que 007/008.
19. La consulta de saldos (UC-07) muestra el saldo actual de cada cuenta (activa e inactiva) en una
    sola pantalla, sin desglose de movimientos ahí mismo.
20. "Generar la información necesaria para reportes financieros" se cubre con los datos ya
    expuestos por UC-06/UC-07; no hay pantalla de reportes propia en esta historia.
21. La moneda de todas las cuentas y movimientos es siempre MXN, sin múltiples divisas ni tipo de
    cambio (mismo criterio que 007/008).
22. No hay roles ni permisos diferenciados: cualquier usuario autenticado administra únicamente sus
    propias cuentas y movimientos.
23. **(Redefinido)** La navegación principal muestra la entrada **"Contabilidad"**; el nombre
    técnico del módulo, rutas, modelos y clases sigue siendo "Tesorería"/`tesoreria`, sin afectar la
    lógica ni el funcionamiento del módulo (ver supuesto 31).
24. **(Adición técnica)** Se agrega `cuenta_id` (FK obligatoria a `Cuenta`) a `CotizacionPago` y se
    elimina su columna `forma_pago`, en una sola migración.
25. **(Adición técnica)** `Movimiento` guarda una referencia polimórfica al documento origen
    (`documentable_type`/`documentable_id`, `morphTo`), nullable para movimientos manuales — hoy
    apunta a `CotizacionPago` y queda lista para futuros módulos sin requerir otra migración.
26. **(Adición técnica)** `Cuenta.saldo_actual` es una columna persistida y cacheada, recalculada
    dentro de la misma transacción cada vez que se crea, edita o elimina un movimiento que la
    afecte, en vez de calcularse al vuelo con un `SUM` en cada consulta (UC-07 queda como una
    lectura simple).
27. **(Adición técnica)** Se agrega `DELETE /api/v1/cotizaciones/{cotizacion}/pagos/{pago}`,
    permitido solo sobre el pago más reciente de la cotización (LIFO), que revierte su movimiento
    en Tesorería y, si aplica, regresa el estado de la cotización de `pagada` a `enviada`. Es la
    única vía de corrección de un pago mal capturado, ya que 008 no exponía ninguna. El criterio
    LIFO es necesario porque el monto de `saldo`/`pago_total` se autocalcula a partir de los pagos
    previos: eliminar un pago intermedio dejaría a los posteriores con montos que ya no
    corresponden al saldo pendiente que tenían al registrarse.
28. **(Adición técnica)** Una transferencia se persiste como dos filas de `Movimiento` vinculadas
    por un `transferencia_id` compartido (una que resta en la cuenta origen y otra que suma en la
    destino), en vez de una sola fila con dos columnas de cuenta: así cada movimiento mantiene una
    sola cuenta y un solo efecto sobre el saldo, simplificando filtros y cálculo, y las dos filas se
    editan o eliminan siempre juntas.
29. **(Adición técnica)** Toda operación sobre movimientos bloquea la(s) fila(s) de `Cuenta`
    involucrada(s) con `lockForUpdate()` dentro de la transacción, evitando condiciones de carrera
    entre movimientos simultáneos sobre la misma cuenta. A diferencia del folio de `Factura` en 007
    (riesgo menor conocido y aceptado), aquí sí se mitiga porque afecta el saldo real de dinero.
30. **(Adición técnica)** Los endpoints se dividen en `POST /api/v1/movimientos` (tipo
    `ingreso`/`egreso`/`ajuste`, una sola cuenta) y `POST /api/v1/transferencias` (dos cuentas,
    validaciones y persistencia propias), en vez de un endpoint único o de uno por cada tipo.
31. **(Adición técnica)** El nombre de negocio interno y el nombre visible se desacoplan: modelos,
    rutas (`/tesoreria/...`), controladores y clases usan "Tesorería"/`tesoreria`; solo la etiqueta
    del menú de navegación dice "Contabilidad".
32. La utilidad en Movimientos solo aplica a los dos orígenes de ingreso que sí se pueden rastrear a
    una venta: pagos de Cotización y pagos de Pedido. El resto de los movimientos (manuales,
    transferencias, ajustes, egresos de Orden de Compra) no tiene una venta detrás de la cual
    calcular utilidad.
33. La utilidad mostrada es la del documento completo, no la del pago individual que generó ese
    movimiento en particular: dos movimientos del mismo documento muestran el mismo número.
34. La utilidad se calcula sin IVA y ya con los descuentos (de línea y global) aplicados — es lo que
    realmente queda, no la utilidad de lista.
35. **(Adición técnica)** Se agrega `costo_unitario` a `CotizacionLinea` y a `PedidoLinea` (ver 008 y
    027): una fotografía del costo del artículo tomada al capturar la línea, no un valor recalculado
    con el costo actual. Sin esto no hay manera de saber cuánto costaba el artículo el día que se
    vendió si su costo cambió después.
36. **(Adición técnica)** Las líneas libres de un Pedido no tienen `costo_unitario` por no tener
    artículo; se excluyen de la suma y el documento queda marcado como utilidad parcial.
37. **(Adición técnica)** Los documentos creados antes de esta historia no reciben `costo_unitario`
    retroactivo: no hay migración de datos que lo intente, porque el costo histórico ya no es
    reconstruible con exactitud. Sus movimientos muestran utilidad no disponible de forma
    permanente.
38. **(Adición técnica)** `MovimientoResource::documentoOrigen()` gana el caso `PedidoPago`, que
    hasta ahora no reconocía: los movimientos generados por un Pedido aparecían con Origen "Manual"
    y sin enlace en `/tesoreria/movimientos`, pese a ya ser movimientos automáticos de solo lectura.
    Se corrige para que el pedido enlace igual que ya enlaza la cotización.
39. **(Adición técnica)** La sección de integración con futuros módulos quedó desactualizada:
    Órdenes de Compra (012) y Pedidos (027) ya existen y ya integran con Tesorería. Se reescribe
    para reflejar el estado real en vez del supuesto original de que ninguno de los dos existía
    todavía.
