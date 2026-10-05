# Spec: Inventario (existencias, faltantes, mínimos de reposición y movimientos)

**Referencia:** reescritura de [remotas/017-inventario.md](remotas/017-inventario.md), que se diseñó
para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan sus reglas de negocio: bodega
curada a mano (tabla `existencias` con una fila por artículo marcado), existencia y faltante
pendiente como dos números positivos, las tres reglas de movimiento, la cotización vinculada como
única responsable de descontar, la devolución solo con la cancelación aceptada, la valuación al
costo de hoy, el "por pedir" con `<` estricto, la generación de órdenes de compra en borrador por
proveedor, los totales sobre el conjunto filtrado y la auditoría que solo reporta. La parte del
navegador y el protocolo entre navegador y servidor se rehicieron para Laravel + Blade + JavaScript
nativo.

**Modifica:** [017-ordenes-compra.md](017-ordenes-compra.md) (recibir una orden ahora **sí** suma
existencias; la 017 decía lo contrario), [012-facturacion.md](012-facturacion.md) (timbrar descuenta
y la cancelación aceptada devuelve, solo en facturas sin cotización),
[011-cotizaciones.md](011-cotizaciones.md) (marcar "producto entregado" descuenta) y
[007-gestion-articulos.md](007-gestion-articulos.md) (columna "En existencias" en el listado).

Se retiró de la remota lo que pertenecía a la arquitectura anterior o a historias que aquí no
existen:

- API REST, Sanctum, API Resources, pantallas Vue, el `Dialog` de shadcn, el menú "⋮" por renglón y
  todas las correcciones de `vue-tsc` y de desborde de la tabla.
- La **revisión del 2026-08-26** y la **migración de rescate**: aquí `articulos` nunca tuvo columnas
  de inventario y no hay datos previos. La tabla `existencias` nace como diseño original.
- El **bloqueo de venta en Pedido de mostrador** ([remotas/027](remotas/027-venta-mostrador-ticket.md)):
  el módulo no existe. Ninguna venta se bloquea por inventario (ver "Fuera de alcance").
- El **costo de goma** ([remotas/014](remotas/014-costo-elaboracion-goma.md)): no existe. El costo es
  solo `costo_con_descuento`.
- La "corrección de orden" del vínculo factura → cotización: aquí el vínculo es
  `facturas.cotizacion_id` ([015](015-cotizacion-a-factura-y-duplicar.md)) y `FacturaController::store`
  ya lo escribe dentro de la transacción de alta, antes de timbrar.
- La auditoría como endpoint: aquí es un comando de consola.

> **Desde [029](029-pago-cotizacion-pedido-orden-trabajo.md)** (implementada el 2026-10-04): una cotización cuyo primer pago crea su
> venta descuenta al nacer la venta, sin bloquear (como en 021). Las cotizaciones sin venta
> (distribuidor o solo suministros) siguen descontando al marcarse "producto entregado".

## Historia de usuario

Como usuario registrado, quiero sentir que tengo una **bodega aparte** de mi catálogo general de
artículos: el catálogo puede tener miles de artículos, pero solo yo decido cuáles de ellos se
almacenan físicamente y "pasan a existencias". Quiero responder cuántas piezas tengo de un modelo,
cuánto dinero tengo invertido, cuál es el mínimo de un producto para volver a pedir, y cuánto
beneficio en potencia tengo. Quiero que las órdenes de compra ya pagadas, al marcarse "Recibidas",
entren al inventario, poder modificar las cantidades manualmente, y poder meter manualmente
productos de la lista de artículos a mi inventario.

## Objetivo / Alcance

Implementar el módulo de **Existencias** sobre la arquitectura monolítica Laravel + Blade +
JavaScript nativo de [001-inicio-proyecto.md](001-inicio-proyecto.md), con la sesión web de
[002-login.md](002-login.md), los componentes Blade de [003-estilo-uniforme.md](003-estilo-uniforme.md),
los [Artículos](007-gestion-articulos.md) con sus [catálogos](008-catalogos.md) y su
[costo](009-precio-proveedor-utilidad.md), conectado a las [Órdenes de compra](017-ordenes-compra.md)
por el lado de las entradas y a [Facturación](012-facturacion.md) y
[Cotizaciones](011-cotizaciones.md) por el lado de las salidas.

- Laravel resuelve el módulo completo: rutas web, controladores, Form Requests, un servicio único de
  movimientos, un comando de consola y las vistas. No se crea API, no hay stores ni estado en el
  frontend.
- Los modales son `<dialog>` nativos con formularios normales (`data-abrir-dialogo`,
  `data-abrir-al-cargar`), con `data-confirmar` y `data-enviar-una-vez`. Todo funciona sin
  JavaScript.
- **No se escribe JavaScript nuevo.** El listado usa la búsqueda dinámica que ya existe
  (`busqueda-dinamica.js`).

Incluye: tabla `existencias` con una fila por artículo **marcado a mano**; existencia, faltante
pendiente, mínimo y máximo por fila; historial de movimientos; entrada automática al recibir una
orden de compra; salida automática al timbrar una factura sin cotización o al marcar una cotización
como entregada; devolución al aceptarse la cancelación de una factura sin cotización; ajustes
manuales; sugerencia de reposición y generación de órdenes de compra en borrador; los totales de
negocio; y la auditoría por consola.

**No** incluye: almacenes o ubicaciones múltiples, números de serie, lotes, caducidades, recepción
parcial por línea, costo promedio ponderado o PEPS, bloqueo de ventas, alertas, exportación, ni
multiempresa.

### Las cuatro preguntas que este módulo responde

| Pregunta del usuario | Cómo se calcula |
|---|---|
| ¿Cuántos artículos tengo de este modelo? | `existencia` del artículo |
| ¿Cuánto dinero tengo invertido en inventario? | Σ `existencia × costo_con_descuento` |
| ¿Cuál es el mínimo de un producto para volver a pedir? | `minimo` capturado por artículo, más la cantidad sugerida |
| ¿Cuánto beneficio en potencia tengo? | Σ `existencia × (precio_unitario_sin_iva − costo_con_descuento)` |

`costo_con_descuento` es el precio de lista del proveedor menos el descuento de su catálogo
([009](009-precio-proveedor-utilidad.md)); el precio de venta es `precio_unitario_sin_iva`. Todas
las cifras son **sin IVA** y en pesos mexicanos. Cuando exista el costo de elaboración de goma, se
sumará al costo en las dos fórmulas.

### Existencia y faltante pendiente: dos números positivos

La existencia **nunca es negativa**. Cuando una salida excede lo disponible, la existencia toca fondo
en `0` y el sobrante se acumula en un segundo contador, el **faltante pendiente**.

> Tienes 2 piezas y timbras una factura de 5 → `existencia = 0`, `faltante_pendiente = 3`.

Un faltante **no es una deuda con un cliente**: la mercancía ya salió físicamente. Es un **descuadre
de registro**, la constancia de que el sistema tenía menos piezas anotadas de las que realmente
había. Por eso un ajuste manual lo borra (ver "Las tres reglas de movimiento").

"Tengo 0 y me faltan 3" se lee sin pensar; un `-3` obliga a interpretar un signo. El estado interno es
equivalente; la lectura no.

### Valuación al costo de hoy

El inventario se valúa con el **costo actual** del artículo, no con el costo al que entró cada pieza.
Cambiar el `precio_proveedor` de un artículo o el descuento de su catálogo **revalúa el inventario
completo**. El número responde "cuánto me costaría reponer lo que tengo hoy". Se descartaron el costo
por entrada y el costo promedio ponderado; es la decisión a revisar primero el día que se necesite
valuación contable real.

## Backend (Laravel)

### Enums (`app/Enums`)

- **`TipoMovimientoInventario`**: `entrada` | `salida` | `ajuste`, con `etiqueta()`. El nombre
  completo evita chocar con `TipoMovimiento` de Tesorería.
- **`MotivoMovimientoInventario`**: `recepcion_orden`, `venta_factura`, `venta_cotizacion`,
  `cancelacion_factura`, `conteo_fisico`, `merma`, `devolucion`, `entrada_inicial`, `otro`, con
  `etiqueta()` y `manuales(): array` → `conteo_fisico`, `merma`, `devolucion`, `entrada_inicial`,
  `otro`. Los otros cuatro solo los escribe el sistema.

### Migración `..._create_inventario_tables.php`

**`existencias`** — una fila por artículo **marcado** como "en existencias". El resto del catálogo no
tiene fila: esa ausencia **es** la marca de "no almacenable", no una fila en ceros.

| Columna | Definición |
|---|---|
| `id` | |
| `articulo_id` | `foreignId` → `articulos`, **único** (a lo más una fila por artículo, viva o borrada) |
| `existencia` | `unsignedInteger`, default `0` |
| `faltante_pendiente` | `unsignedInteger`, default `0` |
| `minimo` | `unsignedInteger`, default `0`. `0` = "no me avises de este artículo" |
| `maximo` | `unsignedInteger`, nullable. Techo de la sugerencia; `null` → el techo es `minimo` |
| `timestamps`, `softDeletes` | |

**`movimientos_inventario`**

| Columna | Definición |
|---|---|
| `id` | |
| `user_id` | `foreignId` → `users` |
| `articulo_id` | `foreignId` → `articulos` (normal: el artículo usa soft delete y el historial sobrevive) |
| `tipo` | `string` (`TipoMovimientoInventario`) |
| `motivo` | `string` (`MotivoMovimientoInventario`) |
| `cantidad` | `unsignedInteger`. Magnitud; la dirección la da `tipo`. En un `ajuste`, la cantidad final |
| `existencia_resultante` | `unsignedInteger`. Estado **después** del movimiento |
| `faltante_resultante` | `unsignedInteger`. Ídem |
| `nota` | `text`, nullable |
| `documentable_type` / `documentable_id` | `nullableMorphs`. Orden de compra, factura o cotización; `null` en ajustes |
| `timestamps` | |

Índice compuesto `(articulo_id, id)`: el historial siempre se lee por artículo y en orden.

`down()` borra las dos tablas.

### Morph map

`AppServiceProvider` agrega `'factura' => Factura::class` y `'cotizacion' => Cotizacion::class` a
`Relation::enforceMorphMap()` (`orden_compra` ya existe). Sin ellos, `enforceMorphMap` rechaza el
movimiento de una factura o una cotización.

### Modelo `Existencia` (tabla `existencias`)

- `protected $table = 'existencias'` explícito, como el resto de los modelos en español.
- `SoftDeletes`. "Quitar de existencias" borra la fila **lógicamente**; volver a marcar el artículo
  **restaura la misma fila** con sus números previos. El índice único sobre `articulo_id` obliga a
  buscar siempre con `withTrashed()` antes de crear.
- **Ninguna columna es asignable.** Solo el servicio de inventario las escribe.
- Relación `articulo()`. En `Articulo`: `existencia(): HasOne` (excluye borradas por el scope de
  soft delete) y `movimientosInventario(): HasMany`.
- Métodos de regla (nadie reimplementa la condición):
  - `porPedir(): bool` → `(minimo > 0 && existencia < minimo) || faltante_pendiente > 0`.
  - `cantidadSugerida(): int` → `max((maximo ?? minimo) − existencia, 0) + faltante_pendiente`.
  - Scope `porPedir()` con la misma condición en SQL, para el filtro y la generación de órdenes.

**Estrictamente menor que, no "menor o igual".** Sin máximo, el techo es el propio mínimo: con `<=`,
un artículo reabastecido justo hasta su mínimo quedaría "por pedir" con sugerencia `0` y generaría
órdenes vacías para siempre. Con `<`, "está por pedir" y "hay algo que sugerir" son la misma
condición.

> Mínimo 5, máximo 20, existencia 3, faltante 0 → sugiere 17. Con faltante 4 → sugiere 21: además de
> rellenar hay que cubrir el descuadre.

### Modelo `MovimientoInventario` (tabla `movimientos_inventario`)

- `protected $table = 'movimientos_inventario'`: `Str::plural` inferiría `movimiento_inventarios`.
- Casts: `tipo` → `TipoMovimientoInventario`, `motivo` → `MotivoMovimientoInventario`.
- Relaciones: `user()`, `articulo()` (`withTrashed()`), `documentable()` (`morphTo`).
- `documentoOrigen(): ?array` → `['etiqueta' => 'OC-0015' | folio de factura | 'COT-0012', 'url' => ...]`
  según el tipo, o `null` en un ajuste. Las tres rutas de detalle ya existen.
- **Solo lectura.** No hay rutas de edición ni borrado. Un error se corrige con un ajuste nuevo.

**El movimiento se liga al `articulo_id`, no a la fila de `existencias`.** Si el usuario quita un
artículo y lo vuelve a marcar meses después, sigue viendo su historial completo.

### Las tres reglas de movimiento

Todo el módulo se reduce a tres operaciones sobre el par (`existencia`, `faltante_pendiente`).

**1. Entrada de N piezas** (recepción de orden, devolución por cancelación de factura):

```
saldado     = min(N, faltante_pendiente)
faltante   -= saldado
existencia += N − saldado
```

Primero salda el faltante. Faltan 3 y entran 10 → existencia 7, faltante 0. Faltan 3 y entran 2 →
existencia 0, faltante 1.

**2. Salida de N piezas** (factura sin cotización timbrada, cotización entregada):

```
descontado  = min(N, existencia)
existencia -= descontado
faltante   += N − descontado
```

Nunca bloquea la operación y nunca produce un número negativo.

**3. Ajuste manual: fijar la cantidad final en N** (conteo físico, merma, devolución, entrada
inicial, alta manual):

```
existencia = N
faltante   = 0
```

El ajuste **fija**, no suma: el usuario captura cuántas piezas hay. Y borra el faltante porque el
usuario acaba de medir la realidad con sus manos. El alta manual es este mismo ajuste con punto de
partida en cero.

### Servicio `App\Services\Inventario\RegistradorInventario`

El **único** que escribe `existencias` y `movimientos_inventario`, igual que `RegistradorMovimientos`
en Tesorería. Cada método:

1. Abre (o se une a) una `DB::transaction`.
2. **Bloquea el artículo** (`Articulo::whereKey(...)->lockForUpdate()`) antes de leer o crear su
   fila. Bloquear el artículo y no la fila resuelve también el caso "la fila todavía no existe": dos
   operaciones simultáneas no pueden crear dos filas.
3. Lee la fila con `withTrashed()`, aplica la regla, guarda la fila y crea el `MovimientoInventario`
   con los resultantes. **Fila e historial se escriben siempre juntos**: nunca puede quedar una
   existencia que el historial no explique.

Métodos:

- `ajustar(Articulo $articulo, int $cantidad, MotivoMovimientoInventario $motivo, ?string $nota): MovimientoInventario`
  — regla 3. Crea la fila si no existe y la **restaura** si estaba borrada.
- `entradaPorDocumento(Model $documento, Collection $lineas, MotivoMovimientoInventario $motivo): void`
  — regla 1 por artículo. Crea o restaura la fila si hace falta.
- `salidaPorDocumento(Model $documento, Collection $lineas, MotivoMovimientoInventario $motivo, bool $creaFila): void`
  — regla 2 por artículo. Con `$creaFila = false`, un artículo sin fila viva **no genera
  movimiento** y no se le crea fila.
- `devolverFactura(Factura $factura): void` — ver "Cancelación de una factura".
- `quitar(Articulo $articulo): void` — borrado lógico de la fila. No genera movimiento.

**Preparación de las líneas** (`entradaPorDocumento` y `salidaPorDocumento`):

- Se descartan las líneas con `articulo_id` en `null` (líneas libres de cotización y orden de
  compra; en factura la columna no es nullable).
- Se descartan las líneas cuyo artículo está borrado lógicamente.
- Se **agrupan por artículo y se suman** las cantidades, y se escribe **un** movimiento por artículo,
  no por línea. Es una red defensiva: los Form Requests aceptan `articulo_id` repetido en algunos
  documentos, y sin agrupar dos líneas del mismo artículo podrían pisarse.
- Los artículos se bloquean en orden de `id`, para que dos documentos con los mismos artículos no se
  esperen mutuamente.

### Entradas: recepción de órdenes de compra

`OrdenCompraController::recibir` ([017](017-ordenes-compra.md)) cambia:

1. `DB::transaction`, bloquea la orden con `lockForUpdate()` y **vuelve a comprobar**
   `puedeRecibirse()` dentro de la transacción (hoy se comprueba antes). Si ya no está `pagada`, no
   hace nada y responde con el mismo aviso de hoy.
2. Pasa la orden a `recibida`.
3. `entradaPorDocumento($orden, $orden->lineas, RecepcionOrden)`. Un artículo sin fila se da de alta
   en `0` antes de sumar: comprar algo es, de por sí, decidir que ese artículo se almacena.

La recepción sigue siendo **total, irreversible y una sola vez**. Se corrige después con un ajuste.
Un doble clic o un `F5` encuentran la orden ya `recibida` y no suman otra vez.

### Salidas: facturas y cotizaciones

La mercancía sale del inventario cuando sale **físicamente**:

- **Factura**: al pasar a `timbrada`.
- **Cotización**: al marcarse `producto_entregado`.

**Regla: si hay cotización vinculada, la cotización manda siempre.**

| Caso | Quién descuenta |
|---|---|
| Factura sin cotización (`cotizacion_id` nulo) | La factura, al timbrarse |
| Factura con cotización (`cotizacion_id` no nulo) | **Nadie** al timbrar. La cotización, al marcarse entregada |
| Cotización que nunca se factura | La cotización, al marcarse entregada |

Una factura con `cotizacion_id` **nunca** mueve inventario: ni al timbrarse, ni al cancelarse, ni la
sustituta que se emita después para la misma cotización. Facturar por adelantado algo que aún no se
entrega no mueve nada, y es correcto: la mercancía sigue en la bodega. Los dos caminos de venta son
reales (clientes con cotización que no piden factura, ventas de mostrador facturadas sin
cotización), y esta regla cabe en una línea.

**Timbrado** (`TimbradorFacturas::timbrar`). El timbrado es una llamada HTTP a facturapi.io bajo
`Cache::lock` y no puede vivir dentro de una transacción de base de datos. Por eso:

- La llamada al PAC queda **fuera** de la transacción, como hoy.
- Con la respuesta exitosa, en una `DB::transaction` corta se hacen juntos
  `aplicarRespuestaTimbrado(...)` y, si `cotizacion_id` es nulo,
  `salidaPorDocumento($factura, $factura->lineas, VentaFactura, creaFila: false)`.
- La idempotencia la da la comprobación de estado que `timbrar()` ya hace dentro del candado: un
  reintento sobre una factura ya `timbrada` responde `SinCambio` y no llega a la salida.
- Si el PAC falla, no hay salida.
- `timbrarComplemento()` no cambia: el complemento de pago no mueve mercancía.

Una factura suelta con un artículo sin fila en `existencias` **no genera movimiento** para esa línea y
no le crea fila: una factura nunca da de alta artículos por su cuenta.

**Cotización entregada** (`CotizacionController::entregar`):

1. `DB::transaction`, bloquea la cotización y vuelve a comprobar `puedeEntregarse()` dentro de la
   transacción. Si ya no está `pagada`, no hace nada.
2. Pasa a `producto_entregado`.
3. `salidaPorDocumento($cotizacion, $cotizacion->lineas, VentaCotizacion, creaFila: true)`.

La cotización es la **única** salida que da de alta un artículo por su cuenta: se crea la fila en
`0` y en el mismo acto se le aplica la salida, dejando faltante pendiente. Así la cotización deja
constancia de lo que vendió aunque el artículo no estuviera marcado. Una cotización entregada no
puede volver a otro estado ni eliminarse, así que no hay salida que revertir.

### Cancelación de una factura

La devolución ocurre cuando la cancelación queda **aceptada** por el SAT, no cuando se solicita:
mientras esté `pending`, la factura sigue vigente y la mercancía sigue fuera.

`CanceladorFacturas` tiene dos caminos que terminan en `Factura::aplicarEstadoCancelacion()`:
`cancelar()` y `refrescar()` (este corre al abrir el detalle de una cancelación en curso). Los dos
pasan por un método privado nuevo que, en una `DB::transaction` con la factura bloqueada, aplica el
estado y, si la factura acaba de quedar `cancelada`, llama a `RegistradorInventario::devolverFactura()`.

`devolverFactura()`:

- No hace nada si la factura tiene `cotizacion_id`: no se devuelve lo que nunca salió.
- **Guardia contra la doble devolución**: no hace nada si ya existe un movimiento
  `cancelacion_factura` de esa factura. Un refresco repetido no devuelve dos veces.
- Devuelve **lo que esa factura sacó**, no sus líneas: lee sus movimientos `venta_factura` y aplica
  una entrada (regla 1) por cada artículo, con esa cantidad. Una línea que no generó salida porque
  el artículo no tenía fila al timbrar tampoco genera devolución, y nunca se crea una fila al
  cancelar. Si la fila se quitó de existencias desde entonces, se restaura.
- Cada movimiento: `tipo = entrada`, `motivo = cancelacion_factura`, `documentable` = la factura.

Se descartó reponer exactamente lo que la factura quitó de existencia y de faltante, porque produce
estados imposibles:

> Tienes 10. Facturas 5 → existencia 5. Facturas otras 8 → existencia 0, faltante 3. Cancelas la
> **primera**. Reponiendo "lo que hizo": existencia 5 **con faltante 3** (piezas en la mano y piezas
> faltantes a la vez). Como entrada normal: entran 5, saldan los 3, quedan **existencia 2, faltante
> 0**. Que es la verdad: tenías 10, vendiste 8.

### Ajustes manuales

`POST /existencias/{articulo}/ajuste` con `cantidad`, `motivo` y `nota`:

- `cantidad` es la **cantidad final**, no la diferencia. Contaste 10, escribes 10.
- `motivo` es obligatorio, de `MotivoMovimientoInventario::manuales()`. Los motivos automáticos se
  rechazan como error de validación: no se puede falsificar el origen de un movimiento.
- **Meter un artículo al inventario por primera vez usa este mismo formulario.** No hay alta aparte.

### Sugerencia de reposición y generación de órdenes de compra

`POST /existencias/generar-ordenes-compra` toma **todos** los artículos por pedir del usuario (filas
vivas, artículo sin borrar), los agrupa por **`articulos.proveedor_id`** (la copia del proveedor del
catálogo que ya escribe el modelo) y crea **una orden de compra en `borrador` por proveedor**, en una
sola transacción:

- Una línea por artículo: `articulo_id`, `descripcion` = nombre y `modelo` copiados del artículo,
  `cantidad` = `cantidadSugerida()`, `precio_unitario` = `costo_con_descuento`, y `tasa_iva` con la
  misma precarga que el buscador de la orden (`16` si `objeto_imp = 02`, si no `exento`). Sin
  descuentos.
- Totales con `CalculadoraTotalesDocumento::calcular()`, sin excepción.
- Folio propio por usuario, como cualquier orden. El cálculo de folio de `OrdenCompraController` se
  mueve a `OrdenCompra::siguienteFolio(User $user): int` para usarlo desde los dos lugares.
- Se **omiten** los artículos cuyo catálogo o proveedor está borrado lógicamente, y el mensaje flash
  los lista ("Sin orden: Sello X (catálogo eliminado)").
- **No se envía nada al proveedor.** Las órdenes quedan en `borrador`, para revisar, corregir y
  enviar a mano.
- Destino: con **una** orden creada, su detalle (`ordenes-compra.show`); con **varias**, la bandeja
  de órdenes de compra. Sin artículos por pedir, regresa a `/existencias` con un aviso.

### Totales del listado

Cuatro cifras, calculadas sobre el **conjunto filtrado completo**, nunca sobre la página visible, en
una consulta agregada aparte resuelta en la base de datos:

- **Unidades totales**: `SUM(existencia)`.
- **Dinero invertido**: `SUM(existencia × costo_con_descuento)`.
- **Beneficio potencial**: `SUM(existencia × (precio_unitario_sin_iva − costo_con_descuento))`.
- **Total general**: dinero invertido + beneficio potencial, el valor de venta de todo lo que hay hoy
  en la bodega, sin IVA.

Los alias de los agregados llevan prefijo `suma_` (`suma_invertido`, `suma_beneficio`), para que
nunca los eclipse un accesor de `Articulo` con el mismo nombre.

Además, un **contador de artículos por pedir** del conjunto filtrado sin el filtro `por_pedir`, que
se muestra junto al interruptor "Solo por pedir". No es una tarjeta de total.

El ordenamiento por dinero invertido o beneficio se traduce a la misma aritmética dentro del
`ORDER BY` (no son columnas), con `id` como desempate para una paginación estable, igual que
`Articulo::ordenar()`.

### Auditoría (`php artisan inventario:auditar`)

Comando de consola `App\Console\Commands\AuditarInventario`, con `{--usuario=}` opcional (id). Para
cada artículo con movimientos reaplica las tres reglas desde (0, 0) en orden de `id` y compara el
resultado contra la fila guardada (incluidas las borradas) y contra el `existencia_resultante` /
`faltante_resultante` de cada movimiento. Imprime una tabla con los artículos que no coinciden (id,
modelo, guardado, reconstruido) y termina con código `1` si hay alguno, `0` si no.

**Solo reporta; no corrige nada.** Un descuadre se corrige con un ajuste manual, que queda
registrado. Una reparación silenciosa borraría la evidencia.

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]`. Las rutas estáticas
van **antes** de las que llevan `{articulo}`, o Laravel las captura como si fueran un artículo:

```php
Route::prefix('existencias')->name('existencias.')->group(function () {
    Route::get('/', [ExistenciaController::class, 'index'])->name('index');
    Route::get('buscar', [ExistenciaController::class, 'buscar'])->name('buscar');
    Route::get('agregar', [ExistenciaController::class, 'agregar'])->name('agregar');
    Route::post('generar-ordenes-compra', GenerarOrdenesCompraController::class)->name('generar-ordenes-compra');
    Route::get('{articulo}', [ExistenciaController::class, 'show'])->name('show');
    Route::post('{articulo}/ajuste', [ExistenciaController::class, 'ajuste'])->name('ajuste');
    Route::put('{articulo}/parametros', [ExistenciaController::class, 'parametros'])->name('parametros');
    Route::delete('{articulo}', [ExistenciaController::class, 'destroy'])->name('destroy');
});
```

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/existencias` | listado con filtros, totales y página en la URL | `existencias.index` |
| GET | `/existencias/buscar` | fragmento HTML para la búsqueda dinámica | `existencias.buscar` |
| GET | `/existencias/agregar` | buscador del catálogo general para pasar artículos a existencias | `existencias.agregar` |
| POST | `/existencias/generar-ordenes-compra` | crea los borradores y redirige | `existencias.generar-ordenes-compra` |
| GET | `/existencias/{articulo}` | ficha del artículo en existencias con su historial | `existencias.show` |
| POST | `/existencias/{articulo}/ajuste` | ajuste o alta manual | `existencias.ajuste` |
| PUT | `/existencias/{articulo}/parametros` | mínimo y máximo | `existencias.parametros` |
| DELETE | `/existencias/{articulo}` | quitar de existencias | `existencias.destroy` |

`{articulo}` no resuelve artículos borrados (404).

### Controladores

- **`ExistenciaController`**
  - `index`: artículos del usuario **con fila viva en `existencias`** (join, sin borrados), con
    `catalogo` precargado, filtrados con `ListadoExistenciasRequest`, ordenados y paginados de 15 en
    15 con `withQueryString()`, como `ArticuloController`. Pasa además `totales`, `porPedir` (el
    contador), los catálogos y proveedores del usuario para los filtros, y el `resumenOrdenes` para el
    diálogo de "Generar órdenes de compra" (proveedor → número de artículos, más los que se
    omitirían).
  - `buscar`: la misma consulta; devuelve `existencias/_resultados` (totales, contador, filas y
    paginación) con `->withPath(route('existencias.index'))`.
  - `agregar`: con `?q=`, hasta 20 artículos del usuario sin fila viva en `existencias` cuyo nombre o
    modelo contiene el texto. Cada resultado enlaza a `existencias.show`, donde se captura la
    cantidad.
  - `show`: `Gate::authorize('view', $articulo)` (ajeno → 404). Funciona para **cualquier** artículo
    propio: si tiene fila viva muestra sus números y acciones; si no, muestra "Este artículo no está
    en existencias" con el diálogo de ajuste **abierto al cargar**. Debajo, el historial paginado (15,
    más reciente primero) con `documentable` precargado, que se ve aunque el artículo ya no esté en
    existencias.
  - `ajuste`: `AjusteExistenciaRequest` → `RegistradorInventario::ajustar()`. Redirige a
    `existencias.show` con "Existencia de {modelo} ajustada a {N}." o, si fue el alta, "{modelo}
    pasó a existencias con {N} piezas."
  - `parametros`: `ParametrosExistenciaRequest`. Exige fila viva (404 si no). Escribe `minimo` y
    `maximo` **sin generar movimiento**: cambiar un umbral no mueve piezas.
  - `destroy`: `RegistradorInventario::quitar()`. No se bloquea aunque tenga existencia o faltante.
    Redirige al listado con "{modelo} ya no está en existencias. Su historial se conserva."
- **`GenerarOrdenesCompraController`** (invocable): ver "Sugerencia de reposición".
- **`OrdenCompraController::recibir`**, **`CotizacionController::entregar`**, **`TimbradorFacturas`** y
  **`CanceladorFacturas`** cambian como se describe arriba.
- **`ArticuloController::index` / `buscar`**: la consulta agrega `withExists('existencia')` para la
  columna nueva, sin una consulta por fila.

Todos filtran por `user_id`; lo ajeno responde 404.

### Autorización

Se reutiliza **`ArticuloPolicy`**: `view` (ficha y historial) y `update` (ajuste, parámetros,
quitar) → dueño; ajeno → `denyAsNotFound()`. El inventario es una faceta del artículo, no un recurso
con dueño propio.

### Validaciones (Form Requests)

**`AjusteExistenciaRequest`** (bolsa `ajuste`):

- `cantidad`: requerida, entero, `min:0`, máximo el mismo tope de cantidad de las líneas de
  documento. Cero es válido: "no me queda ninguno".
- `motivo`: requerido, `Rule::enum(MotivoMovimientoInventario::class)->only(MotivoMovimientoInventario::manuales())`.
- `nota`: nullable, string, máximo 500.

**`ParametrosExistenciaRequest`** (bolsa `parametros`):

- `minimo`: requerido, entero, `min:0`.
- `maximo`: nullable, entero, `min:0`, `gte:minimo`.

**`ListadoExistenciasRequest`**: los parámetros del listado. No rechaza nada: un valor inválido se
ignora.

| Parámetro | Valores | Por defecto |
|---|---|---|
| `q` | texto: nombre o modelo | vacío |
| `catalogo` | id de un catálogo del usuario | ninguno |
| `proveedor` | id de un proveedor del usuario | ninguno |
| `por_pedir` | `1` | apagado |
| `orden` | `modelo`, `catalogo`, `existencia`, `faltante`, `minimo`, `invertido`, `beneficio` | `modelo` |
| `dir` | `asc`, `desc` | `asc` |
| `page` | página | 1 |

`attributes()` en español en todos.

## Vistas (Blade)

Todas con `layouts/app`, `x-card`, `x-campo`, `x-boton`, `x-alerta`, `x-icono`, `x-celda` y
`x-paginacion`.

### `existencias/index.blade.php` — listado

La bodega curada: **solo** los artículos marcados.

- **Cuatro tarjetas** arriba: unidades, dinero invertido, beneficio potencial y total general. Se
  recalculan con los filtros: filtrar por un proveedor muestra el dinero invertido en ese proveedor.
- **Filtros** en un `<form method="GET">` con búsqueda dinámica: texto, catálogo, proveedor e
  interruptor "Solo por pedir (N)".
- **Tabla** (parcial `_filas`): modelo (con el nombre completo como `title`, y enlace a la ficha) ·
  catálogo · existencia · faltante (solo si es mayor que cero) · mínimo · máximo · invertido ·
  beneficio, con encabezados ordenables (parcial de orden como el de Artículos). Los renglones por
  pedir llevan la clase `fila-por-pedir`. Sin columna de nombre y sin botones por renglón: las
  acciones viven en la ficha, a un clic.
- **Acciones de pantalla**:
  - **"Agregar artículo a existencias"**: enlace a `existencias.agregar`.
  - **"Generar órdenes de compra"**: abre un `<dialog>` con el resumen precalculado ("Se crearán 2
    órdenes en borrador: Proveedor A, 5 artículos; Proveedor B, 2 artículos", y los omitidos con su
    motivo). El botón de confirmar envía el POST con `data-enviar-una-vez`. Sin artículos por pedir,
    el botón aparece deshabilitado con "No hay artículos por pedir".
- Vacío: "Todavía no tienes artículos en existencias. Agrega uno o recibe una orden de compra."

### `existencias/agregar.blade.php` — buscador del catálogo general

Formulario GET con el texto y una lista de resultados (modelo, nombre, catálogo) con el botón
"Pasar a existencias", que enlaza a la ficha del artículo. Sin texto, solo el formulario.

### `existencias/show.blade.php` — ficha y movimientos

- Encabezado: modelo, nombre, catálogo, proveedor, y enlace a la ficha del artículo.
- **Con fila viva**: existencia, faltante (si es mayor que cero), mínimo, máximo, cantidad sugerida
  si está por pedir, invertido y beneficio del artículo. Acciones:
  - **"Ajustar"**: `<dialog>` con cantidad final (prellenada con la existencia), motivo (`select` con
    los cinco manuales) y nota. Reabierto al cargar si hay errores de la bolsa `ajuste`.
  - **"Mínimo y máximo"**: `<dialog>` con los dos umbrales. Ídem con la bolsa `parametros`.
  - **"Quitar de existencias"**: formulario DELETE con `data-confirmar="Dejarás de llevar el
    inventario de {modelo}. Su historial se conserva."`, y si la existencia no es cero, además "Todavía
    tiene {N} piezas registradas."
- **Sin fila viva**: "Este artículo no está en existencias" y el mismo diálogo de ajuste con
  `data-abrir-al-cargar` y el motivo `entrada_inicial` preseleccionado. Si tuvo fila antes, aviso:
  "Al agregarlo se recuperan sus mínimos anteriores."
- **Historial**: fecha · tipo · motivo · cantidad · existencia y faltante resultantes · nota · origen
  (enlace al documento, o "Manual"). Paginado, solo lectura.

### Cambios en pantallas existentes

- **Listado de artículos** (`articulos/_titulos` y `_filas`, [007](007-gestion-articulos.md)):
  columna **"En existencias"**. "Sí" es un enlace a la ficha de existencias; "No" es el enlace "Pasar
  a existencias" a la misma ficha, que abre el diálogo de alta. Mismo mecanismo, alcanzable desde el
  catálogo general sin cambiar de pantalla a mano.
- **Formulario de artículo**: **sin cambios**. Artículos son datos maestros; Existencias es la
  operación diaria.
- **Formulario de factura** (`facturas/_formulario`): si la factura viene de una cotización (alta con
  `cotizacionOrigen`, o edición con `cotizacion_id`), un `x-alerta` informativo: *"El inventario se
  descontará al marcar la cotización como entregada, no al timbrar esta factura."* Sin ese aviso la
  regla es invisible y parece un error.
- **Orden de compra** (detalle, visor y bandeja de [017](017-ordenes-compra.md)): el `data-confirmar`
  de "Marcar como recibida" pasa a *"¿Marcar la orden como recibida? Su mercancía entrará a
  existencias y ya no podrás cancelar su pago ni editarla."*
- **Cotización** (detalle): el `data-confirmar` de "Marcar como entregada" agrega *"Sus artículos
  saldrán de existencias."*

### Navegación

En `layouts/app.blade.php`, un grupo **Inventario** con el mismo patrón `<details class="menu-grupo"
data-menu-grupo>` de Compras y Ventas: **Artículos · Catálogos · Existencias**, activo con
`articulos.*`, `catalogos.*` o `existencias.*`. Sustituye los enlaces sueltos de Artículos y
Catálogos.

La entrada se llama "Existencias" y no "Inventario" a propósito: *Inventario → Inventario* no le dice
nada a nadie.

## JavaScript

**Sin cambios y sin archivos nuevos.** `busqueda-dinamica.js` atiende el listado de existencias;
`app.js` atiende diálogos, `data-confirmar`, `data-enviar-una-vez` y los grupos del menú.

## Pruebas (Pest)

Pest con `RefreshDatabase` sobre SQLite en memoria, como el resto del proyecto. SQLite ignora
`lockForUpdate`: el bloqueo se conserva en el código, pero ninguna prueba lo verifica.

- **`InventarioReglasTest`** (servicio):
  1. Entrada con faltante salda primero el faltante (faltan 3, entran 10 → 7/0; entran 2 → 0/1).
  2. Salida mayor a lo disponible deja existencia en 0 y acumula el resto, sin fallar.
  3. Ajuste fija la cantidad final y pone el faltante en 0.
  4. Dos líneas del mismo artículo suman su total y generan **un** movimiento.
  5. Las líneas sin `articulo_id` y las de artículos borrados se ignoran.
  6. Cada movimiento guarda los resultantes correctos.
- **`InventarioDocumentosTest`** (enganches):
  7. Recibir una orden pagada suma; un artículo sin fila la crea en 0 antes de sumar.
  8. Recibir dos veces suma una sola vez.
  9. Timbrar una factura sin cotización descuenta; con cotización, no.
  10. Timbrar una factura suelta con un artículo sin fila no genera movimiento ni crea fila.
  11. Un timbrado que falla (error de datos o del PAC) no descuenta; el reintento exitoso descuenta
      una vez y un reintento sobre una ya timbrada no descuenta otra vez.
  12. Entregar una cotización descuenta; con un artículo sin fila la crea en 0 y deja faltante.
  13. Cancelar una factura sin cotización devuelve saldando primero el faltante, solo lo que esa
      factura sacó.
  14. Una cancelación `pending` no devuelve; el refresco que la deja `accepted` devuelve, y un
      segundo refresco no devuelve otra vez.
  15. Cancelar una factura con cotización no devuelve nada.
- **`ExistenciasTest`** (pantallas):
  16. El listado muestra solo artículos con fila viva; uno nunca marcado no aparece con ningún
      filtro.
  17. Los totales corresponden al conjunto filtrado completo y no cambian al pasar de página.
  18. Ordenar por invertido ordena el conjunto completo, no solo la página.
  19. "Por pedir" con `<` estricto (en el mínimo exacto no aparece) y la cantidad sugerida con y sin
      máximo, con faltante.
  20. Ajuste con motivo automático → error de validación; `maximo < minimo` → error.
  21. Alta manual desde la ficha crea la fila; parámetros sin fila → 404.
  22. Quitar oculta del listado y conserva el historial; volver a marcar restaura la misma fila con
      sus mínimos.
  23. Generar órdenes crea un borrador por proveedor con cantidades sugeridas, totales calculados y
      folio propio; omite y reporta los de catálogo o proveedor borrado; con una orden redirige a su
      detalle y con varias a la bandeja.
  24. Todo lo ajeno → 404 (ficha, ajuste, parámetros, quitar).
- **`AuditarInventarioTest`**: un descuadre introducido a mano se reporta con código 1 y la fila no
  cambia; sin descuadres, código 0.
- **`ArticuloTest`** (se agrega): la columna "En existencias" se resuelve sin una consulta por fila.
- `OrdenesCompraTest`, `FacturasTest`, `CotizacionesTest` y `EstiloUniformeTest` siguen pasando.

## Fuera de alcance

- **Bloqueo de venta sin existencia.** Ninguna venta se bloquea por inventario. El bloqueo de Pedido
  de mostrador ([remotas/027](remotas/027-venta-mostrador-ticket.md)) se agregará con ese módulo.
- **Costo de goma** en la valuación: se sumará cuando exista
  ([remotas/014](remotas/014-costo-elaboracion-goma.md)).
- Almacenes o ubicaciones múltiples, traspasos y mercancía en tránsito.
- Números de serie, lotes y caducidades.
- Recepción parcial o con cantidades editables, y deshacer una recepción: se corrige con un ajuste.
- Costo promedio ponderado, PEPS o costo histórico por entrada.
- Inventario de insumos de goma.
- Alertas por correo, WhatsApp o notificación: la señal es visual, en Existencias.
- Sugerencia automática del mínimo a partir del historial de ventas.
- Envío automático de las órdenes generadas: nacen en `borrador`. Generar dos veces seguidas crea
  borradores duplicados, que se eliminan a mano.
- Bloqueo del borrado de un artículo con existencia: sigue siendo borrado lógico, deja de contar y
  su historial se conserva.
- Efecto en Tesorería: el dinero salió al pagar la orden.
- Exportación del inventario.
- Validación de artículo duplicado en las líneas de los documentos: el servicio agrupa como red
  defensiva, pero cerrar el hueco cambia el contrato de tres formularios.

## Estado de implementación

Implementada el 2026-10-01.

- **Archivos nuevos**:
  - migración `2026_10_03_100000_create_inventario_tables` (revisada con `migrate --pretend` contra
    MySQL y **aplicada en la base local**),
  - enums `TipoMovimientoInventario` y `MotivoMovimientoInventario`; modelos `Existencia` y
    `MovimientoInventario`,
  - servicios `Inventario\RegistradorInventario` y `Inventario\GeneradorOrdenesReposicion`,
  - `ExistenciaController`, `GenerarOrdenesCompraController` (invocable), `AjusteExistenciaRequest`,
    `ParametrosExistenciaRequest`, `ListadoExistenciasRequest`,
  - comando `inventario:auditar` (`AuditarInventario`),
  - vistas `existencias/*`,
  - pruebas `InventarioReglasTest`, `InventarioDocumentosTest`, `ExistenciasTest`,
    `AuditarInventarioTest`, y los helpers `agregarLinea()` y `marcarExistencia()` en `tests/Pest.php`.
- **Archivos modificados**: `Articulo` (`existencia()`, `movimientosInventario()`), `OrdenCompra`
  (`siguienteFolio()` estático, antes privado en el controlador), `OrdenCompraController::recibir`,
  `CotizacionController::entregar`, `TimbradorFacturas`, `CanceladorFacturas`,
  `ArticuloController` (`withExists`), `AppServiceProvider` (alias `factura` y `cotizacion`),
  `routes/web.php`, `layouts/app` (grupo Inventario), `articulos/index`, `_titulos` y `_filas`,
  `facturas/_formulario`, `cotizaciones/show`, `ordenes-compra/show` y `_vista-previa`, y `app.css`.
- **Decisiones al implementar**:
  - Los alias de la consulta de totales llevan prefijo `suma_`, como pide la spec.
  - El listado parte de `Existencia` (no de `Articulo`) con el scope `delUsuario()`, que une
    `articulos` y excluye los borrados: así la columna `existencia` no choca con la relación
    `Articulo::existencia()`.
  - El filtro "Solo por pedir" es un `select` (Todos / Solo por pedir), no una casilla:
    `busqueda-dinamica.js` envía el valor de un checkbox aunque no esté marcado.
  - `devolverFactura()` bloquea la factura antes de revisar la guardia, para que dos refrescos
    simultáneos no devuelvan los dos.
- **Verificación**: las 71 pruebas nuevas pasan y la suite completa pasa 865 de 866; la única falla
  es anterior a esta historia (`EstiloUniformeTest` por el `<button>` de `dashboard.blade.php`).
  Pint no reporta cambios. No hubo cambios de JavaScript.

  **No se revisó la UI en un navegador real.** Falta probar `/existencias` (tarjetas, filtros con la
  búsqueda dinámica, ordenar, el diálogo de generar órdenes), la ficha (alta abierta al cargar,
  ajuste, mínimo y máximo, quitar), la columna "En existencias" de Artículos, el grupo Inventario del
  menú en celular, y el ciclo real con facturapi.io: timbrar, cancelar y ver la devolución.

## Criterios de aceptación

1. `/existencias` lista **solo** los artículos marcados, con existencia, faltante, mínimo, máximo,
   invertido y beneficio, y muestra los cuatro totales del conjunto filtrado.
2. Un artículo nunca marcado no aparece en `/existencias` con ningún filtro.
3. Los totales cambian al aplicar un filtro y **no** cambian al pasar de página.
4. Ordenar por dinero invertido ordena todo el conjunto filtrado, no solo los quince visibles.
5. Marcar como recibida una orden pagada suma sus cantidades al inventario y deja un movimiento por
   artículo con enlace a la orden; un artículo que no estaba en existencias entra solo.
6. Volver a recibir una orden ya recibida no suma otra vez.
7. Una orden con dos líneas del mismo artículo suma el total y deja un solo movimiento.
8. Timbrar una factura sin cotización descuenta; si no alcanza, se timbra igual, la existencia queda
   en 0 y el resto se registra como faltante pendiente.
9. Timbrar una factura con cotización no mueve el inventario; el descuento ocurre al marcar esa
   cotización como "producto entregado". El formulario de esa factura lo avisa.
10. Cancelar una factura sin cotización devuelve sus piezas cuando el SAT acepta la cancelación,
    saldando primero el faltante, y nunca dos veces; una con cotización no devuelve nada.
11. Un ajuste manual captura la cantidad final, exige motivo, deja el faltante en cero y queda en el
    historial con su motivo y su nota.
12. Pasar un artículo a existencias se hace desde el buscador de Existencias **o** desde la columna
    "En existencias" del listado de Artículos, y los dos llevan al mismo formulario de ajuste.
13. Quitar un artículo lo oculta de `/existencias` y conserva su historial; volver a marcarlo
    restaura su misma fila.
14. Un artículo está "por pedir" con existencia **estrictamente menor** que su mínimo (mínimo mayor
    que cero) o con faltante pendiente.
15. La cantidad sugerida es (máximo, o mínimo si no hay máximo, − existencia, sin bajar de cero) +
    faltante pendiente.
16. "Generar órdenes de compra" muestra antes un resumen, crea un borrador por proveedor con las
    cantidades sugeridas sin enviar nada, reporta los omitidos, y lleva al detalle si se creó una
    sola orden o a la bandeja si fueron varias.
17. El historial muestra cada movimiento con tipo, motivo, cantidad, resultantes y enlace al
    documento origen; no se edita ni se borra.
18. `php artisan inventario:auditar` reporta los artículos cuya existencia guardada no coincide con
    la reconstruida y no los modifica.
19. El menú muestra el grupo Inventario con Artículos, Catálogos y Existencias.
20. Nada de esto funciona con artículos, documentos o existencias ajenos (404).
21. Pint no reporta cambios y la suite Pest pasa completa.

## Supuestos asumidos (registro completo)

**Asunciones de negocio (de la remota, vigentes):**

1. La unidad que se cuenta es el **Artículo**. Dos artículos de catálogos distintos con el mismo
   modelo son existencias separadas.
2. Existencia, faltante, mínimo y máximo viven en una **tabla propia**, `existencias`, con una fila
   por artículo marcado.
3. El inventario es **opt-in**: un artículo entra cuando el usuario lo marca, cuando se recibe una
   orden que lo trae o cuando se entrega una cotización que lo vende.
4. La existencia nunca es negativa; el excedente se acumula como faltante pendiente.
5. Un faltante es un descuadre de registro, no una deuda con un cliente.
6. Toda entrada que suma (recepción, devolución) salda primero el faltante.
7. Todo ajuste manual fija la cantidad final y pone el faltante en cero; el alta manual es ese
   ajuste.
8. Valuación al costo actual; cambiar precios revalúa todo.
9. Cifras sin IVA, en MXN.
10. El beneficio usa el precio de lista del artículo, sin descuentos de cliente.
11. El mínimo se captura a mano; `0` significa "no me avises".
12. El máximo es opcional; sin él, el techo es el mínimo.
13. El sistema sugiere y arma borradores por proveedor, pero nunca envía nada.
14. Factura y cotización descuentan, cada una en su momento; si hay cotización, la cotización manda
    siempre.
15. La cancelación devuelve solo cuando el SAT la acepta.
16. La recepción es total, irreversible y una sola vez.
17. Las líneas sin artículo se ignoran; las del mismo artículo se agrupan y suman.
18. No hay carga retroactiva: las órdenes ya `recibida` antes de esta historia no suman nada.
19. El historial es solo de consulta.
20. La existencia se guarda como número y se escribe en la misma transacción que su movimiento, con
    el artículo bloqueado; la auditoría solo reporta.
21. Totales y ordenamiento sobre el conjunto filtrado completo, en la base de datos.
22. Un solo almacén; sin series, lotes ni caducidades.
23. Inventario por usuario.
24. No toca Tesorería.
25. Borrar un artículo no se bloquea por tener existencia.
26. Quitar un artículo de existencias es un borrado lógico que no se bloquea; volver a marcarlo
    restaura la misma fila. El historial se liga al artículo, no a la fila.

**Asunciones de la auditoría arquitectónica (aprobadas):**

1. Rutas web, Blade, `<dialog>` nativo y formularios con redirect; sin API, Sanctum, Resources ni
   JavaScript nuevo.
2. Recibir una orden de compra pagada suma existencias y da de alta en 0 lo que no estaba.
3. "Generar órdenes de compra" se conserva: borrador por proveedor, al detalle si es una, a la
   bandeja si son varias, omitidos reportados.
4. El bloqueo de Pedido de mostrador se retira hasta que exista ese módulo.
5. Sin costo de goma: el costo es `costo_con_descuento`.
6. Una factura con `cotizacion_id` nunca mueve inventario, tampoco la sustituta de la misma
   cotización.
7. Una cotización entregada no vuelve atrás ni se elimina; no hay salida que revertir.
8. La devolución por cancelación ocurre con `accepted` y nunca dos veces.
9. Sin datos previos: no hay migración de rescate.
10. Grupo de menú Inventario: Artículos · Catálogos · Existencias.
11. Columna "En existencias" en Artículos, con enlace a la ficha de existencias (alta si es "No").
12. La auditoría es un comando de consola.
13. La spec es la 018 y anota la 017.

**Adiciones técnicas (aprobadas):**

1. `ArticuloPolicy` con `denyAsNotFound` para todo lo ajeno.
2. Form Requests `AjusteExistenciaRequest` y `ParametrosExistenciaRequest`, con los motivos manuales
   vía `Rule::enum(...)->only(...)`.
3. Índice único en `existencias.articulo_id` y búsqueda con `withTrashed()` para restaurar.
4. Artículos borrados fuera del listado, los totales y los movimientos nuevos.
5. Un solo servicio, `RegistradorInventario`, para entradas, salidas y ajustes de cualquier documento.
6. Pruebas de timbrado fallido, reintento, cancelación pendiente y refresco repetido.

**Decisiones de esta reescritura:**

1. Las acciones por artículo (ajustar, mínimo y máximo, quitar) viven en la ficha
   `existencias/{articulo}` junto con el historial, no en cada renglón: sin el menú "⋮" de la remota,
   un diálogo por renglón y por acción sería pesado, y la ficha queda a un clic.
2. La devolución por cancelación se calcula con los movimientos `venta_factura` de la factura, no con
   sus líneas: así nunca devuelve algo que no salió ni crea filas al cancelar.
3. Se bloquea el artículo, no la fila de existencias, para cubrir también la creación de la fila.
4. El folio de la orden de compra se mueve a `OrdenCompra::siguienteFolio()` para compartirlo con la
   generación automática.
