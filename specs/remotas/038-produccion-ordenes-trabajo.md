# Spec: Producción (órdenes de trabajo, imagen de diseño y envío a domicilio)

## Historia de usuario

Como usuario único del sistema, fabrico productos personalizados (principalmente sellos de goma) a
partir de lo que ya vendo por mostrador ([027](027-venta-mostrador-ticket.md)) o coticé
([008](008-cotizaciones.md)). Hoy, en cuanto un cliente paga, no tengo dónde anotar "esto hay que
fabricarlo" ni forma de ver de un vistazo qué me falta por hacer, qué ya está listo y qué debo
entregar — lo cargo todo de memoria.

Quiero, desde el ticket o la cotización ya con algún pago registrado, poder abrir una **Orden de
Trabajo** que herede el cliente, el producto y el saldo sin volver a capturarlos, adjuntarle la
imagen del diseño que se va a fabricar, y llevarla por un puñado de estados mínimos: **Pendiente →
En producción → Listo para entregar → Entregado**. Cuando el cliente recoge en persona, seguirá
escaneando el mismo código QR del ticket o de la cotización que ya cierra la venta hoy. Cuando pide
que se lo envíen, quiero un botón "Enviar a domicilio" que capture a quién y cuándo se le entrega, la
tarifa de envío, y si el envío ya está pagado o lo cobra el repartidor — y una ficha que pueda
compartir con el repartidor con el botón nativo de compartir de Windows.

Es un **tablero de seguimiento de trabajos pendientes**, no un ERP de manufactura: no controla
máquinas, materias primas, tiempos por operación, empleados, lotes ni costos industriales. La
pregunta que debe responder en segundos es *"¿qué tengo pendiente, qué estoy fabricando, qué ya está
listo, qué debo entregar y qué ya fue entregado?"*.

## Objetivo / Alcance

Módulo nuevo, **Producción**, que agrega:

1. Una entidad **Orden de Trabajo**, colgada de un `Pedido` ([027](027-venta-mostrador-ticket.md)) o
   de una `Cotizacion` ([008](008-cotizaciones.md)) que ya tenga al menos un pago — nunca de una
   `Factura` ni de una cotización sin pagos. Reutiliza cliente, producto, saldo y pagos del
   documento origen: no los vuelve a capturar ni los duplica.
2. Cinco estados y nada más: `pendiente`, `en_produccion`, `listo_para_entregar`, `a_domicilio`,
   `entregado`. Las transiciones son manuales, con un botón cada una, salvo la entrada a
   `a_domicilio` (automática al capturar el envío) y la entrada a `entregado` por la vía de
   mostrador (automática al cerrar el QR del documento origen).
3. Una imagen de diseño por orden, con el mismo mecanismo de subida/reemplazo que ya existe para las
   fotos de artículo ([020](020-imagenes-articulos.md)).
4. Un código QR de entrega también para `Cotizacion` — hoy solo lo tiene `Pedido`. Se extiende el
   mismo flujo (pantalla de escaneo, etiqueta imprimible, cobro del saldo restante en el momento de
   la entrega) y se relaja la regla de 008 que hoy exige que la cotización esté 100% pagada antes de
   poder entregarse.
5. Un flujo de **envío a domicilio**: ficha de envío con tarifa fija (A/B/C, configurable, mismo
   mecanismo de [014](014-costo-elaboracion-goma.md)) y forma de pago (prepagado o por cobrar), botón
   "Compartir" reutilizando `lib/compartir.ts` ya existente.
6. Una pantalla nueva "Producción" en la navegación principal ([013](013-navegacion-principal.md)).

**No** incluye: control de materias primas, máquinas, empleados, tiempos por estación, lotes, costos
de producción, MRP, inventario de producción, control de calidad, flujo de aprobación de diseño, GPS
o seguimiento del repartidor, ni liquidación de lo prepagado con el repartidor. Ver "Fuera de
alcance".

## Backend (Laravel)

### `App\Enums\EstadoOrdenTrabajo`

`enum` backed por `string`, cinco casos:

| Caso | Valor persistido | Texto |
| --- | --- | --- |
| `Pendiente` | `pendiente` | Pendiente |
| `EnProduccion` | `en_produccion` | En producción |
| `ListoParaEntregar` | `listo_para_entregar` | Listo para entregar |
| `ADomicilio` | `a_domicilio` | A domicilio |
| `Entregado` | `entregado` | Entregado |

Los cinco son secuenciales y no hay retroceso: no existe endpoint para volver un estado hacia atrás
(mismo criterio que `EstadoCotizacion`, 008-supuesto-5). `Entregado` es terminal.

### Modelo `OrdenTrabajo` (tabla `orden_trabajos`)

- `user_id`: obligatorio, mismo patrón mono-usuario que el resto del sistema.
- `folio`: unsignedInteger, consecutivo por usuario, único por `(user_id, folio)` — mismo mecanismo
  que `Pedido`/`Cotizacion`. `folioFormateado()` imprime `OT-00001`.
- `documentable_type` / `documentable_id`: relación polimórfica hacia `Pedido` o `Cotizacion`. Es la
  misma técnica que ya usa `Movimiento::documentable()` (010-tesoreria) para no repetir dos columnas
  casi siempre vacías. Índice único compuesto `(documentable_type, documentable_id)`: **un documento
  admite como máximo una Orden de Trabajo**.
- `estado`: string(20), default `pendiente`, cast a `EstadoOrdenTrabajo`.
- `imagen_ruta`: string, nullable, **fuera de `#[Fillable]`** — solo la escribe
  `ImagenOrdenTrabajoService`, mismo criterio que `Articulo::imagen_ruta` (020).
- `observaciones`: text, nullable.
- `timestamps`.

```php
public function documentable(): MorphTo
{
    return $this->morphTo();
}

public function envio(): HasOne
{
    return $this->hasOne(Envio::class);
}

public function folioFormateado(): string
{
    return 'OT-'.str_pad((string) $this->folio, 5, '0', STR_PAD_LEFT);
}
```

`Pedido` y `Cotizacion` ganan la relación inversa:

```php
public function ordenTrabajo(): MorphOne
{
    return $this->morphOne(OrdenTrabajo::class, 'documentable');
}
```

**Cliente, producto y saldo no se guardan en `OrdenTrabajo`.** El Resource carga
`documentable.cliente` (o `cliente_nombre`/`cliente_telefono` si el origen es `Pedido`, que no tiene
`cliente_id` — ver supuesto 4), `documentable.lineas` y `documentable.saldoPendiente()` en cada
respuesta, exactamente los datos que ya calculan `PedidoResource`/`CotizacionResource` hoy.

**Quién puede crear una orden:** el documento origen debe tener al menos un pago
(`$documento->tienePagos()`); si no, `422`. Un `Pedido`/`Cotizacion` sin ninguna orden ya creada
para él es requisito adicional (índice único de arriba, con `422` legible si se intenta duplicar).

### `ImagenOrdenTrabajoService`

Copia del patrón de `ImagenArticuloService` (020), mismo `ProcesadorImagen`, mismo límite de 1200 px
de lado largo, mismo formato WEBP, mismo criterio de "se borra la anterior después de guardar la
nueva". Directorio propio (`OrdenTrabajo::DIRECTORIO_IMAGENES`), nombre de archivo
`{id}-{random(8)}.webp`. `SubirImagenOrdenTrabajoRequest` idéntico a `SubirImagenArticuloRequest`
(mismos mimes, mismo tope de 10 MB de subida).

### Endpoints `OrdenTrabajo`

```
POST   /api/v1/ordenes-trabajo                    { documentable_type, documentable_id }
GET    /api/v1/ordenes-trabajo                     ?estado[]=pendiente&estado[]=en_produccion...
GET    /api/v1/ordenes-trabajo/{orden}
POST   /api/v1/ordenes-trabajo/{orden}/imagen       (multipart, campo "archivo")
DELETE /api/v1/ordenes-trabajo/{orden}/imagen
POST   /api/v1/ordenes-trabajo/{orden}/iniciar-produccion
POST   /api/v1/ordenes-trabajo/{orden}/marcar-listo
POST   /api/v1/ordenes-trabajo/{orden}/envio        (ver modelo Envio abajo)
POST   /api/v1/ordenes-trabajo/{orden}/entregar     (solo alcanzable desde a_domicilio)
```

- `documentable_type` en el `store` acepta únicamente los alias `pedido` / `cotizacion` (nunca el
  FQCN del modelo, mismo criterio de no exponer nombres de clase por HTTP que ya sigue el resto de
  la API); el controlador los traduce al modelo real.
- `iniciar-produccion` exige estado actual `pendiente`; `marcar-listo` exige `en_produccion`. Ambos
  responden `422` fuera de esos estados — no hay "saltar" un paso.
- `entregar` (el de la Orden de Trabajo, distinto del `entregar` de Pedido/Cotización) exige estado
  `a_domicilio`. Es la acción que dispara el repartidor al confirmar que ya entregó.
- Listado por defecto (`GET /ordenes-trabajo` sin `estado[]`) excluye `entregado`: es el tablero de
  "lo que falta", no un historial. `estado[]=entregado` lo trae explícitamente para consultar el
  historial aparte.

### Cambios en `Cotizacion` (relaja 008-supuesto-7)

- **Nueva columna `entregado_en`**: timestamp, nullable — mismo campo que ya tiene `Pedido`, hoy
  ausente en `Cotizacion` porque su entrega era un botón manual sin cobro de por medio.
- **`CotizacionController::entregar` se reescribe** siguiendo el mismo esqueleto de
  `PedidoController::entregar` (3 caminos):
  - Camino 1 — ya `producto_entregado`: no hace nada, informa cuándo se entregó.
  - Camino 2 — saldo en cero: cierra sola, sin pedir cuenta. Con "Deshacer" en la misma ventana de
    `Pedido::MINUTOS_PARA_DESHACER_ENTREGA`, expuesto en un `deshacerEntrega` nuevo, simétrico al de
    `Pedido` y con la misma condición (no revierte si ya hubo cobro).
  - Camino 3 — saldo pendiente: exige `cuenta_id`, registra el pago (mismo mecanismo que
    `CotizacionController::pagos`, con `tipo` de pago "saldo") y **entonces** cierra.
  - **Precondición de estado**: cambia de "solo desde `pagada`" a "desde `enviada` o `pagada`" —
    `borrador` sigue bloqueado (una cotización no enviada no tiene ticket que escanear) y
    `producto_entregado` sigue siendo terminal.
  - Se conserva intacta la llamada a `$this->inventario->salidaPorDocumento(...)` (017) dentro de la
    misma transacción bloqueada, en el mismo punto donde ya ocurre hoy — el inventario baja al
    entregarse, no cambia de momento.
  - Al cerrar (cualquier camino que sí cierra), si la cotización tiene `ordenTrabajo` cargada y su
    estado no es ya `entregado`, se marca `entregado`.
- **QR y etiqueta**: mismo mecanismo que `Pedido::urlEntrega()` — URL absoluta por `id` (no por
  token; el `id` no es adivinable con provecho porque entregar sin saldo no mueve dinero, y el QR
  vive en un papel físico igual que el de Pedido), apuntando a `/cotizaciones/{id}/entregar`.
  Endpoint `GET /cotizaciones/{cotizacion}/etiqueta` que genera la imagen imprimible, calcado de
  `PedidoController::ticket`/la etiqueta de Pedido.

### Modelo `Envio` (tabla `envios`)

- `orden_trabajo_id`: `foreignId` único (1 a 1) — una orden admite como máximo un envío.
- `nombre_receptor`, `telefono_receptor`: string, obligatorios.
- `fecha_recepcion`: date, obligatorio. `hora_recepcion`: string (formato `HH:MM`), obligatorio.
- `tarifa`: string(1), cast a `App\Enums\TarifaEnvio` (`a`/`b`/`c`).
- `monto`: decimal(10,2) — **copia congelada** del valor configurado de la tarifa elegida en el
  momento de crear el envío, mismo criterio que `costo_goma` en `Articulo` (014): si después cambias
  el precio de la Tarifa B en Configuración, los envíos ya creados no cambian de monto retroactivo.
- `forma_pago`: string(12), cast a `App\Enums\FormaPagoEnvio` (`prepagado`/`por_cobrar`).
- `timestamps`.

```php
public function ordenTrabajo(): BelongsTo
{
    return $this->belongsTo(OrdenTrabajo::class);
}

public function movimiento(): MorphOne
{
    return $this->morphOne(Movimiento::class, 'documentable');
}
```

**No tiene endpoint de edición ni de borrado** — un envío, una vez creado, no se corrige (fuera de
alcance; ver abajo).

### `App\Enums\TarifaEnvio` y `App\Enums\FormaPagoEnvio`

`TarifaEnvio` sigue el mismo patrón que `TamanoGoma` (014): cada caso sabe su propia clave de
configuración.

| Caso | Valor persistido | Clave de configuración |
| --- | --- | --- |
| `A` | `a` | `envio_tarifa_a` |
| `B` | `b` | `envio_tarifa_b` |
| `C` | `c` | `envio_tarifa_c` |

`FormaPagoEnvio`: `Prepagado` (`prepagado`) / `PorCobrar` (`por_cobrar`). Sin tercer caso — la
sección 7 original hablaba de tres combinaciones de pago, pero el estado del producto ya lo sabe el
sistema por el saldo del documento origen; esto solo describe el envío en sí (ver supuesto 12).

### Extensión de `App\Enums\ClaveConfiguracion` (014)

Tres claves nuevas, mismo almacén clave→valor ya existente, mismo `ConfiguracionService`:

| Clave | Valor por defecto | Validación |
| --- | --- | --- |
| `envio_tarifa_a` | `50.00` | numérico, ≥ 0, máx. 2 decimales |
| `envio_tarifa_b` | `80.00` | numérico, ≥ 0, máx. 2 decimales |
| `envio_tarifa_c` | `120.00` | numérico, ≥ 0, máx. 2 decimales |

Editables desde la misma pantalla de Configuración donde hoy viven los costos de goma — no se crea
una pantalla nueva.

### Endpoint `Envio` y su efecto en Tesorería

```
POST /api/v1/ordenes-trabajo/{orden}/envio
  { nombre_receptor, telefono_receptor, fecha_recepcion, hora_recepcion, tarifa, forma_pago, cuenta_id? }
```

- Exige estado actual de la orden `listo_para_entregar` (`422` en cualquier otro estado — "Enviar a
  domicilio" solo aparece ahí, sección 6 de la historia).
- `cuenta_id` es **obligatorio solo si `forma_pago = prepagado`** (a qué cuenta entra el dinero);
  se rechaza si viene con `forma_pago = por_cobrar` (mismo criterio que Pedido: recibirla ahí solo
  podría significar que alguien entendió mal).
- Dentro de una transacción:
  1. Se lee el monto vigente de la tarifa elegida (`ConfiguracionService::obtener`) y se congela en
     `Envio::monto`.
  2. Se crea el `Envio`.
  3. La `OrdenTrabajo` pasa a `a_domicilio`.
  4. **Solo si `forma_pago = prepagado`**: se llama a
     `TesoreriaService::registrarDesdeDocumento($user, $envio, $cuentaId, TipoMovimiento::Ingreso, $monto, hoy, "Envío de Orden {folio}")`
     — el mismo método que ya usan `PedidoPago`/`CotizacionPago`, aplicado al `Envio` como
     documento origen. El movimiento se genera **en este momento** (al capturar el formulario), no
     al marcar la entrega — porque el dinero ya entró a caja cuando el cliente pagó en el mostrador.
  5. Si `forma_pago = por_cobrar`: no se toca Tesorería en absoluto. Ese dinero nunca pasa por el
     negocio.

### Saldo mostrado en la ficha de envío

`importe_pendiente` = saldo pendiente del `Pedido`/`Cotizacion` origen (el mismo cálculo de siempre)
**+** el monto del envío, **únicamente si `forma_pago = por_cobrar`**. Si el envío es `prepagado`, su
monto no suma al pendiente: ya se cobró.

### Tests

- `OrdenTrabajoTest`: creación solo con pago previo, unicidad por documento, transiciones válidas e
  inválidas, aislamiento por usuario.
- `CotizacionEntregaTest`: los 3 caminos con la precondición relajada (`enviada`/`pagada`), deshacer,
  inventario descontado en el mismo punto de siempre, sincronía con `OrdenTrabajo`.
- `EnvioTest`: tarifa congelada, `prepagado` genera movimiento en Tesorería y `por_cobrar` no genera
  ninguno, transición de la orden a `a_domicilio`, `importe_pendiente` correcto en ambos casos.

## Frontend (Vue 3)

### Store `ordenesTrabajo.ts`

Mismo patrón que `pedidos.ts`/`cotizaciones.ts`: `fetchAll(filtroEstado)`, `fetchOne`, `crear`,
`subirImagen`, `iniciarProduccion`, `marcarListo`, `crearEnvio`, `entregar`.

### Pantallas

- **`ProduccionListView.vue`** (`/produccion`): tarjetas con imagen, cliente, producto, ticket
  relacionado, estado, fecha y saldo pendiente (sección 4 de la historia). Filtro de estado con
  "Entregado" excluido por defecto. Cada tarjeta trae el botón de acción que corresponde a su
  estado (`Iniciar producción` / `Marcar como listo` / `Enviar a domicilio` / `Marcar entregado`).
- **`OrdenTrabajoDetalleView.vue`** (`/produccion/:id`): imagen (subir/reemplazar), observaciones,
  datos leídos del documento origen, historial de estado, acceso a la ficha de envío si ya existe.
- **Botón "Crear Orden de Trabajo"** en `PedidoDetalleView.vue` y `CotizacionDetalleView.vue`,
  visible solo si el documento tiene algún pago y no tiene ya una orden asociada.
- **Formulario de envío** (diálogo o vista dedicada): nombre/teléfono de quien recibe, fecha/hora,
  selector de tarifa (A/B/C, mostrando el monto vigente de cada una), forma de pago, y cuenta cuando
  es prepagado.
- **Ficha de envío**: se muestra al terminar el formulario y también queda accesible desde el
  detalle de la orden. Botón **Compartir** conectado a `lib/compartir.ts` (ya existente, mismo
  componente que usan hoy cotizaciones y facturas) — sin lógica nueva de compartir.
- **`CotizacionEntregaView.vue`** (`/cotizaciones/:id/entregar`) y **`CotizacionEtiquetaView.vue`**
  (`/cotizaciones/:id/etiqueta`): copias de `PedidoEntregaView.vue`/`PedidoEtiquetaView.vue`
  apuntando al store de cotizaciones. `CotizacionEntregaView.vue` también gana el mismo pie de
  "Escanear otra"/"Inicio" que ya tenía la de Pedido cuando se abre en modo mostrador (ver punto
  siguiente): el mostrador entrega varios trabajos seguidos, y sin ese pie habría que salir a mano
  entre uno y otro.

### El escáner de etiquetas del modo mostrador (029) reconoce también Cotización

El escáner interno (`MostradorEscanearView.vue`) validaba el código leído contra una expresión
regular exclusiva de Pedido (`/^\/pedidos\/(\d+)\/entregar\/?$/`). Se generaliza:

- `lectorQr.ts`: `pedidoDeCodigoEtiqueta()` se reemplaza por
  `documentoDeCodigoEtiqueta(codigo, origen): { tipo: 'pedido' | 'cotizacion'; id: number } | null`,
  que prueba las dos rutas de entrega (`/pedidos/:id/entregar` y `/cotizaciones/:id/entregar`) y
  devuelve a cuál pertenece el código. Sigue exigiendo el mismo origen que la aplicación — un QR
  pegado en cualquier caja podría llevar a donde sea, y el escáner de un punto de venta no es un
  navegador.
- `MostradorEscanearView.vue` navega a `pedidos-entregar` o `cotizaciones-entregar` según el
  `tipo` devuelto, en vez de siempre a `pedidos-entregar`.
- `modoMostrador.ts`: `cotizaciones-entregar` se suma a `RUTAS_PERMITIDAS`, el candado que decide
  qué pantallas son alcanzables en modo mostrador — sin este cambio el escáner habría calculado la
  ruta correcta y el guard la habría bloqueado igual.

### Configuración

La pantalla de Configuración existente gana tres campos más (Tarifa A/B/C de envío), junto a los
costos de goma que ya tiene.

### Navegación (`config/navegacion.ts`)

Nuevo grupo `produccion` — no entra al grupo "Ventas" porque una orden puede colgar tanto de Pedidos
como de Cotizaciones, y forzarla dentro de uno de los dos grupos existentes escondería la mitad de
los casos:

```ts
{
  id: 'produccion',
  etiqueta: 'Producción',
  icono: WrenchScrewdriverIcon,
  opciones: [
    {
      name: 'produccion',
      etiqueta: 'Producción',
      icono: WrenchScrewdriverIcon,
      rutasRelacionadas: ['produccion-detalle'],
    },
  ],
}
```

## Fuera de alcance

- Control de materias primas, máquinas, empleados, tiempos por operación, lotes de producción,
  costos industriales, MRP, inventario de producción, control de calidad, flujo de aprobación de
  diseño — cualquier estado más allá de los cinco definidos.
- GPS, rutas o seguimiento en tiempo real del repartidor.
- Liquidación de lo prepagado con el repartidor: el sistema no lleva ningún registro de esa deuda
  interna (asunción confirmada en esta conversación).
- Edición o eliminación de una Orden de Trabajo o de un Envío una vez creados.
- Más de una imagen o historial de versiones de la imagen de diseño.
- Más de un envío por Orden de Trabajo.
- Cálculo automático de la tarifa de envío por zona, colonia, peso o distancia — siempre es
  selección manual.
- Crear una Orden de Trabajo desde una `Factura` o desde una `Cotizacion`/`Pedido` sin pagos.
- Un segundo QR o etiqueta propios de la Orden de Trabajo: la entrega en mostrador sigue pasando por
  el QR del `Pedido`/`Cotizacion` que ya existe (o que se agrega en esta misma spec para
  Cotización).

## Criterios de aceptación

1. Un `Pedido` o `Cotizacion` sin ningún pago registrado no puede generar una Orden de Trabajo
   (`422`).
2. Un documento que ya tiene una Orden de Trabajo no puede generar una segunda (`422`).
3. Una Orden de Trabajo nace en `pendiente`, sin imagen, y expone el cliente/producto/saldo leídos
   de su documento origen sin duplicarlos.
4. `iniciar-produccion` solo funciona desde `pendiente`; `marcar-listo` solo desde `en_produccion`;
   ambos rechazan (`422`) cualquier otro estado de partida.
5. Subir una imagen nueva reemplaza la anterior y borra el archivo viejo del disco.
6. Escanear el QR de un `Pedido`/`Cotizacion` con Orden de Trabajo asociada, al cerrarse la entrega,
   también marca esa Orden de Trabajo como `entregado`.
7. Una `Cotizacion` en estado `enviada` con saldo pendiente puede entregarse desde su QR cobrando el
   saldo en el mismo paso, quedando `pagada` y `producto_entregado` a la vez; sigue descontando
   inventario en ese momento, igual que hoy.
8. Una `Cotizacion` en `borrador` no puede entregarse; una ya `producto_entregado` no puede
   entregarse de nuevo.
9. "Enviar a domicilio" solo está disponible con la orden en `listo_para_entregar`; al capturarse el
   envío, la orden pasa a `a_domicilio` automáticamente.
10. Un envío `prepagado` genera un movimiento de Tesorería por el monto congelado de la tarifa, en el
    momento de guardar el formulario; un envío `por_cobrar` no genera ningún movimiento.
11. El monto del envío se suma al "Importe pendiente" de la ficha solo cuando es `por_cobrar`.
12. Cambiar el monto configurado de una tarifa no altera los envíos ya creados con esa tarifa.
13. "Marcar entregado" desde `a_domicilio` cierra la orden sin exigir que el saldo esté en cero.
14. El listado de Producción excluye `entregado` por defecto y lo muestra al filtrar explícitamente.
15. El botón "Compartir" de la ficha de envío usa el mismo mecanismo ya existente en el sistema.
16. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.
17. El escáner de etiquetas del modo mostrador (029) reconoce tanto una etiqueta de `Pedido` como
    una de `Cotizacion`, y navega a la pantalla de entrega que corresponde a cada una.
18. Un `Pedido` o `Cotizacion` con Orden de Trabajo no puede eliminarse (`422`, aunque ya no le
    queden pagos), su `puede_eliminarse` vale `false` y la purga automática de cotizaciones vencidas
    (008) no lo toca.

## Supuestos asumidos (registro completo)

1. Una Orden de Trabajo puede crearse desde un `Pedido` o desde una `Cotizacion` con al menos un
   pago registrado — no desde una cotización sin pagos ni desde una `Factura`.
2. Relación 1 a 1: cada `Pedido`/`Cotizacion` admite como máximo una Orden de Trabajo.
3. El producto de la orden se lee de las líneas del documento origen; varias líneas conviven en la
   misma orden, no se crea una orden por línea.
4. Si el origen es `Cotizacion`, la orden referencia su `cliente_id`. Si el origen es `Pedido` (sin
   `cliente_id`), la orden muestra el nombre/teléfono ya capturados en ese pedido, sin crear ni
   buscar un registro de `Cliente`.
5. `pendiente → en_produccion` es una acción manual del usuario, no ocurre al crear la orden.
6. La orden nace en `pendiente` incluso si el documento origen ya está pagado al 100%.
7. Folio propio y consecutivo por usuario (`OT-00001`), independiente del folio del documento
   origen.
8. La imagen de diseño es única (se reemplaza, no se versiona) y opcional al crear la orden.
9. La `Cotizacion` obtiene su propio QR desde que se crea (etiqueta imprimible igual que `Pedido`).
   Al escanearlo, el comportamiento es idéntico al de `Pedido`: cierre automático sin saldo (con
   "Deshacer"), confirmación de cobro con saldo. Esto exige relajar la regla de 008 que hoy pide
   `pagada` antes de poder entregarse.
10. "Enviar a domicilio" solo aparece disponible con la orden en `listo_para_entregar`.
11. El envío es 1 a 1 con la Orden de Trabajo — no hay envíos parciales ni múltiples intentos.
12. El envío tiene dos estados de pago, no tres: **prepagado** (dinero real, genera movimiento de
    Tesorería en el momento de capturar el formulario) y **por cobrar** (el repartidor lo cobra
    directo al cliente final; nunca toca la caja ni Tesorería del negocio). El estado de pago del
    producto ya se conoce por separado (saldo del documento origen), así que no hace falta
    combinarlos en tres casos como planteaba la historia original.
13. Liquidar al repartidor lo que se le prepagó queda fuera del sistema — no hay campo ni pantalla
    para registrar esa liquidación.
14. Las tarifas A/B/C se eligen a mano (mismo mecanismo que el tamaño de goma en 014), nunca se
    calculan automáticamente por zona, peso o distancia.
15. "Marcar entregado" desde `a_domicilio` no exige saldo en cero; puede cerrarse con saldo
    `por_cobrar` pendiente, porque ese cobro lo resuelve el repartidor aparte.
16. La pantalla de Producción vive en la aplicación principal (no en el modo mostrador/PWA
    restringido de 029), con su propio grupo en la navegación principal.
17. El listado de Producción excluye `entregado` por defecto; un filtro aparte lo muestra.
18. No se contempla cancelar ni eliminar una Orden de Trabajo ni un Envío una vez creados.
19. El escáner de etiquetas del modo mostrador (029), pensado originalmente solo para Pedido, se
    extiende para reconocer también las etiquetas de Cotización — decisión tomada después de
    entregar la primera versión de esta spec, al notar que el escáner interno rechazaba una
    etiqueta de Cotización aunque el escaneo con la cámara normal del celular sí funcionaba (la URL
    del QR es absoluta y no depende de este escáner).
20. Como una Orden de Trabajo no se elimina (supuesto 18) y no guarda copia de su documento origen,
    tener una Orden de Trabajo bloquea el borrado del `Pedido`/`Cotizacion` de origen, igual que un
    pago o una factura. Sin esta regla, borrar los pagos regresaba el documento a un estado
    eliminable y al borrarlo la orden quedaba huérfana, rompiendo el listado de Producción (`500`).
    La regla vive en `puedeEliminarse()` de cada modelo y en el scope `vencidas` de `Cotizacion`.
