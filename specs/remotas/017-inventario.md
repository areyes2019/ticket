# Spec: Inventario (existencias, faltantes, mínimos de reposición y movimientos)

## Historia de usuario

Como usuario registrado, quiero sentir que tengo una **bodega aparte** de mi catálogo general de
artículos: el catálogo puede tener miles de artículos, pero solo yo decido cuáles de ellos se
almacenan físicamente y "pasan a existencias". Quiero responder cuántas piezas tengo de un modelo,
cuánto dinero tengo invertido, cuál es el mínimo de un producto para volver a pedir, y cuánto
beneficio en potencia tengo. Quiero que las órdenes de compra ya pagadas puedan marcarse como
"Recibidas" y entrar al inventario, poder modificar las cantidades manualmente, y poder meter
manualmente productos de la lista de artículos a mi inventario. Y quiero que, al vender de
mostrador, el sistema no me deje vender un artículo del que no tengo ni una pieza.

### Revisión del 2026-08-26

La primera versión de esta spec (implementada el 2026-08-10) guardaba existencia, faltante, mínimo y
máximo como **columnas de la tabla `articulos`**, y **todo** el catálogo era inventario desde el día
uno. Con producción real ya en marcha desde el 18 de agosto (ver
[[project_sistema_sin_produccion]]), el catálogo general creció a un tamaño donde eso deja de tener
sentido: la mayoría de los artículos nunca se van a guardar en un anaquel. Esta revisión cambia tres
cosas, reescritas ya en el cuerpo de la spec:

1. **Los cuatro números se mudan a una tabla propia, `existencias`**, con una fila por cada artículo
   que el usuario decidió "pasar a existencias". Un artículo sin fila ahí simplemente no es
   inventario.
2. **El inventario deja de ser automático para todo el catálogo.** El usuario marca a mano qué
   artículos se almacenan; los demás quedan fuera hasta que se marquen.
3. **Pedido (venta de mostrador, [027](027-venta-mostrador-ticket.md)) bloquea vender un artículo sin
   existencia.** Es la primera excepción a la regla "una salida nunca bloquea la venta": aplica solo
   ahí, y solo cuando la existencia es exactamente `0`.

Los artículos que ya tenían movimientos reales antes de esta revisión (existencia, faltante o mínimo
distinto de su valor por defecto) se marcan "en existencias" automáticamente al aplicar la migración,
para no perder de vista inventario que ya existía. El resto del catálogo queda sin marcar.

## Objetivo / Alcance

Implementar el módulo de **Existencias** sobre los [Artículos](006-gestion-articulos.md) con su
[cadena de costos](011-precio-proveedor-utilidad.md), su [costo de goma](014-costo-elaboracion-goma.md)
y sus [catálogos por proveedor](009-catalogos.md), conectado a las
[Órdenes de compra](012-ordenes-compra.md) por el lado de las entradas y a
[Facturación](007-facturacion.md) y [Cotizaciones](008-cotizaciones.md) por el lado de las salidas.

Incluye: una tabla propia `existencias` con una fila por artículo **marcado a mano** por el usuario
("pasar a existencias"); existencia, faltante pendiente, mínimo y máximo por fila; historial de
movimientos; entrada automática al recibir una orden de compra pagada; salida automática al timbrar
una factura o marcar una cotización como entregada; bloqueo de venta sin existencia en Pedido de
mostrador; vínculo opcional factura → cotización para que una misma venta no descuente dos veces;
ajustes manuales; sugerencia de reposición y generación de órdenes de compra en borrador; y los
totales de negocio.

**No** incluye: almacenes o ubicaciones múltiples, números de serie, lotes, caducidades, recepción
parcial por línea, costo promedio ponderado o PEPS, inventario de insumos de goma (explícitamente
fuera desde [014](014-costo-elaboracion-goma.md)), alertas por correo o WhatsApp, exportación, ni
multiempresa.

### Esta spec supera a la 012

[012-ordenes-compra.md](012-ordenes-compra.md) declara por escrito que el sistema no lleva inventario
y que marcar una orden como `recibida` no suma stock (secciones "Recepción de la mercancía", "Fuera
de alcance" y supuesto 29). **Esta spec revierte esa decisión.** Se agrega una nota al inicio de esas
secciones de la 012 remitiendo aquí, en lugar de dejar dos specs afirmando cosas opuestas.

### Las cuatro preguntas que este módulo responde

| Pregunta del usuario | Cómo se calcula |
|---|---|
| ¿Cuántos artículos tengo de este modelo? | `existencia` del artículo |
| ¿Cuánto dinero tengo invertido en inventario? | Σ `existencia` × costo total, sin IVA |
| ¿Cuál es el mínimo de un producto para volver a pedir? | `minimo` capturado por artículo, más la cantidad sugerida |
| ¿Cuánto beneficio en potencia tengo? | Σ `existencia` × (precio de venta sin IVA − costo total) |

Donde **costo total** es `costo_con_descuento + costo_goma` (el atributo calculado `costo_total` de
`Articulo`, ver [011](011-precio-proveedor-utilidad.md) y [014](014-costo-elaboracion-goma.md)) y el
precio de venta es `precio_unitario_sin_iva`. Todas las cifras del módulo son **sin IVA** y en pesos
mexicanos.

### Existencia y faltante pendiente: dos números positivos

La existencia **nunca es negativa**. Cuando una salida excede lo disponible, la existencia toca fondo
en `0` y el sobrante se acumula en un segundo contador, el **faltante pendiente**.

> Tienes 2 piezas y timbras una factura de 5 → `existencia = 0`, `faltante_pendiente = 3`.

Un faltante **no es una deuda con un cliente**: la mercancía ya salió físicamente (la salida ocurre
al entregar o al vender de mostrador). Es un **descuadre de registro** — la constancia de que el
sistema tenía menos piezas anotadas de las que realmente había. Esa distinción es la que justifica
que un ajuste manual lo borre: ver "Las tres reglas de movimiento".

Se eligió esto sobre una existencia negativa porque un número negativo obliga al usuario a
interpretar un signo, mientras que "tengo 0 y me faltan 3" se lee sin pensar. El estado interno es
equivalente; la lectura no.

### Valuación al costo de hoy

El inventario se valúa con el **costo actual** del artículo, no con el costo al que entró cada pieza.
Consecuencia deliberada: cambiar el `precio_proveedor` de un artículo o el `descuento` de su catálogo
**revalúa el inventario completo**, incluidas las piezas compradas antes a otro precio. El número
responde "cuánto me costaría reponer lo que tengo hoy".

Se descartó guardar el costo de cada entrada y se descartó el costo promedio ponderado. Es la
decisión que más maquinaria ahorra de toda la historia, y la que habría que revisar primero el día
que el negocio necesite valuación contable real.

## Backend (Laravel)

### Tabla nueva `existencias`

Una fila por artículo **marcado a mano** por el usuario como "en existencias". No hay fila para el
resto del catálogo — esa ausencia **es** la marca de "no almacenable", no una fila en ceros.

- `articulo_id`: FK a `articulos`, **único** (a lo más una fila por artículo).
- `existencia`: `unsignedInteger`, default `0`. Nunca negativa.
- `faltante_pendiente`: `unsignedInteger`, default `0`.
- `minimo`: `unsignedInteger`, default `0`. `0` significa "no me avises de este artículo".
- `maximo`: `unsignedInteger`, **nullable**. Techo al que se quiere rellenar. Si es `null`, el techo
  para la sugerencia es el propio `minimo`.
- `SoftDeletes`. "Quitar de existencias" borra la fila lógicamente, no físicamente: si el artículo se
  vuelve a marcar después, se **restaura** la misma fila en vez de crear una nueva, y no se pierden
  sus números. Mismo patrón que ya usa `Articulo`.

Ninguna de las cuatro columnas es editable desde el formulario de artículo: se mueven por los
endpoints de este módulo, que son los únicos que escriben el historial en el mismo acto.

### Migración inicial: qué artículos nacen ya marcados

Al aplicar esta spec, cualquier artículo que **ya** tuviera movimiento real bajo el diseño anterior
(existencia > 0, faltante pendiente > 0, o mínimo configurado — hay producción real desde el 18 de
agosto, ver [[project_sistema_sin_produccion]]) recibe automáticamente su fila en `existencias` con
esos mismos valores. El resto del catálogo —la gran mayoría— **no** recibe fila: queda fuera hasta
que el usuario lo marque a mano. No es carga retroactiva de historial nuevo, es preservar visibilidad
de lo que ya estaba en marcha.

### Cómo se marca y se quita un artículo de existencias

**Marcar** es capturar su cantidad inicial: el mismo endpoint de "Ajustes manuales" de más abajo
(`POST /api/v1/inventario/{articulo}/ajuste`) crea la fila si no existe y aplica la cantidad
capturada. No hay un endpoint de alta separado — buscar el artículo y capturar cuánto hay **es** la
acción de pasarlo a existencias, tanto desde la pantalla de Existencias como desde un acceso directo
en la lista general de Artículos (ver "Cambios en pantallas existentes").

**Quitar** un artículo de existencias es `DELETE /api/v1/inventario/{articulo}` — borra lógicamente
su fila. No se bloquea aunque tenga existencia o faltante distintos de cero: el usuario puede decidir
dejar de rastrear un artículo aunque le queden piezas contadas a mano fuera del sistema. El diálogo de
confirmación en frontend avisa cuando la existencia no está en cero.

### Modelo `MovimientoInventario`

Tabla `movimientos_inventario`, declarada con `protected $table` explícito — Eloquent pluraliza en
inglés y de `MovimientoInventario` inferiría `movimiento_inventarios`. Es la lección ya pagada en
005, 008 y 012; aquí se aplica de entrada.

- `user_id`, `articulo_id` (FK, `cascadeOnDelete` no: `restrictOnDelete` no aplica porque el artículo
  usa soft delete, así que la FK es normal y el historial sobrevive al borrado lógico).
- `tipo` (enum `TipoMovimientoInventario`): `entrada` | `salida` | `ajuste`.
- `motivo` (enum `MotivoMovimientoInventario`): `recepcion_orden`, `venta_factura`,
  `venta_cotizacion`, `cancelacion_factura`, `conteo_fisico`, `merma`, `devolucion`,
  `entrada_inicial`, `otro`.
- `cantidad`: `unsignedInteger`. Es la **magnitud** del movimiento; la dirección la da `tipo`. En un
  `ajuste` es la cantidad final capturada.
- `existencia_resultante` y `faltante_resultante`: `unsignedInteger`. El estado en que quedó el
  artículo **después** de aplicar el movimiento. Son los que hacen auditable el historial sin
  reconstruir nada.
- `nota`: `text`, nullable. Texto libre del usuario.
- `documentable_type` / `documentable_id`: `nullableMorphs`. Apunta a la `OrdenCompra`, `Factura` o
  `Cotizacion` que originó el movimiento; `null` en los ajustes manuales. Mismo patrón que el
  `Movimiento` de [Tesorería](010-tesoreria.md), que ya usa `morphOne` con `documentable`.
- Índice compuesto por `(articulo_id, id)`: el historial siempre se lee por artículo y en orden.

**El movimiento se relaciona con el `articulo_id`, no con la fila de `existencias`.** Es deliberado:
si el usuario quita un artículo de existencias y lo vuelve a marcar meses después, quiere seguir
viendo el historial completo de ese artículo, no uno que empieza de cero cada vez que la fila de
existencias va y viene.

El historial es **solo de consulta**. No hay endpoints de edición ni borrado de movimientos; la
corrección de un error se hace con un ajuste manual nuevo, que queda registrado como tal.

### Las tres reglas de movimiento

Todo el módulo se reduce a tres operaciones sobre el par (`existencia`, `faltante_pendiente`) de la
fila de `existencias` del artículo. Están concentradas en un único servicio, `InventarioService`, y
**nadie más escribe esas columnas**.

**Si el artículo no tiene fila en `existencias` todavía**, qué pasa depende de si el movimiento es
una entrada o una salida — ver el detalle en cada sección de abajo. En resumen: toda **entrada** real
(recepción de orden, ajuste manual, devolución) crea la fila en `0` antes de aplicarse. Las
**salidas** solo crean la fila para Cotización (ver "El vínculo factura → cotización"); una Factura
suelta sin fila simplemente no genera movimiento para esa línea, y un Pedido sin fila no se deja
guardar (ver "Bloqueo de venta sin existencia en Pedido").

**1. Entrada de N piezas** — recepción de orden de compra, devolución por cancelación de factura.

```
saldado    = min(N, faltante_pendiente)
faltante  -= saldado
existencia += (N − saldado)
```

Primero salda el faltante y solo el resto sube la existencia. Debes 3 y entran 10 → existencia 7,
faltante 0. Debes 3 y entran 2 → existencia 0, faltante 1.

**2. Salida de N piezas** — timbrado de factura sin cotización vinculada, cotización entregada.

```
descontado = min(N, existencia)
existencia -= descontado
faltante   += (N − descontado)
```

Nunca se bloquea la venta y nunca se produce un número negativo.

**3. Ajuste manual: fijar la cantidad final en N** — conteo físico, merma, devolución, entrada
inicial, alta manual.

```
existencia = N
faltante   = 0
```

El ajuste **fija**, no suma: el usuario captura cuántas piezas hay, no cuántas cambiaron. Y borra el
faltante porque un faltante es un descuadre de registro y el usuario acaba de medir la realidad con
sus manos; arrastrarlo después de contar sería conservar un error ya corregido.

> **Por qué el alta manual fija en lugar de sumar.** Meter un artículo al inventario por primera vez
> y ajustar uno existente son la misma operación con distinto punto de partida, así que ambas usan
> la regla 3. La "regla única de entrada" de la sección anterior gobierna las entradas **que suman
> una cantidad** (recepción y devolución), no las que declaran un total.

### Entradas: recepción de órdenes de compra

`POST /api/v1/ordenes-compra/{id}/recibir` ya existe y hoy solo cambia el estado
([OrdenCompraController](../backend/app/Http/Controllers/OrdenCompraController.php)). Ahora, además:

- Recorre las líneas de la orden, **descarta las que tienen `articulo_id` en `null`** (la columna es
  nullable desde 012 y una línea de texto libre no tiene existencia a la que sumar), **agrupa por
  artículo y suma las cantidades**, y aplica la regla de entrada a cada artículo resultante. Si un
  artículo **no tiene fila en `existencias`**, se crea en `0` antes de aplicar la entrada: comprar
  algo es, de por sí, decidir que ese artículo se almacena.
- Registra un `MovimientoInventario` por artículo —no por línea— con `tipo = entrada`,
  `motivo = recepcion_orden` y `documentable` apuntando a la orden.
- Sigue siendo **total** (todas las líneas completas), **irreversible** y **una sola vez**.

La agrupación por artículo es una **red defensiva**, no una funcionalidad visible: la regla de "un
artículo por línea" de [008](008-cotizaciones.md) vive únicamente en el componente `DocumentoLineas`
del frontend, y su propia adición técnica 31 deja constancia de que los Form Requests siguen
aceptando `articulo_id` repetido. Sin agrupar, dos líneas del mismo artículo podrían pisarse y perder
piezas en silencio.

Si la orden ya no está en `pagada` al momento de ejecutarse, la operación no hace nada y responde
con la orden tal como está (ver "Atomicidad").

### Salidas: facturas y cotizaciones

La mercancía sale del inventario cuando sale **físicamente**:

- **Factura**: al pasar a `timbrada` (`POST /api/v1/facturas/{factura}/timbrar`).
- **Cotización**: al marcarse `producto_entregado` (`POST /api/v1/cotizaciones/{cotizacion}/entregar`).

En ambos casos se agrupan las líneas por artículo, se descartan las que no tienen `articulo_id`, y se
aplica la regla de salida. El movimiento queda con `tipo = salida`, `motivo = venta_factura` o
`venta_cotizacion`, y `documentable` apuntando al documento.

**Qué pasa si el artículo no tiene fila en `existencias` es distinto en cada documento:**

- **Cotización**: se crea la fila en `0` y, en el mismo instante, se le aplica la salida encima
  (dejando faltante pendiente si no alcanza). Es la única salida que da de alta un artículo por su
  cuenta — ver el porqué en "El vínculo factura → cotización".
- **Factura** (con o sin cotización vinculada): si no hay fila, esa línea **no genera movimiento**,
  igual que una línea sin `articulo_id`. Una Factura suelta nunca marca artículos como existencias
  por su cuenta.

**Una salida nunca bloquea la operación en Factura ni en Cotización.** Timbrar no puede fallar por
inventario: si no alcanza, se genera faltante. Esa decisión es deliberada — el inventario es opcional
por artículo y bloquear las ventas hasta terminar de marcar existencias dejaría el sistema
inutilizable. La única excepción a esta regla es Pedido de mostrador, ver más abajo.

### El vínculo factura → cotización

**El vínculo ya existe y no se crea nada nuevo.** Desde [008](008-cotizaciones.md), el botón
"Facturar" del detalle de cotización lleva al formulario de factura con la cotización en la URL, y
`FacturaController::store` escribe `cotizaciones.factura_id`. Agregar un `cotizacion_id` a `facturas`
duplicaría el dato en dos columnas que pueden contradecirse, así que la 017 **lee la relación que ya
está ahí**: `Factura::cotizacion()` es un `hasOne` inverso sobre `cotizaciones.factura_id`.

Lo único que cambia en ese flujo es el **orden**: hoy el vínculo se escribe *después* de timbrar, y
el timbrado es justo el momento en que se decide si hay que descontar existencias. Se mueve dentro de
la transacción de creación, antes de timbrar; si no, toda factura nacida de una cotización se vería
como venta de mostrador y descontaría mercancía que la cotización va a descontar otra vez al
entregarse.

Los dos caminos de venta son reales — hay clientes con cotización que no piden factura, y hay ventas
de mostrador facturadas sin cotización previa.

**Regla: si hay cotización vinculada, la cotización manda siempre.**

| Caso | Quién descuenta |
|---|---|
| Factura sin cotización vinculada (mostrador) | La factura, al timbrarse |
| Factura con cotización vinculada | **Nadie** al timbrar. La cotización, al marcarse entregada |
| Cotización que nunca se factura | La cotización, al marcarse entregada |

La factura vinculada **nunca** mueve inventario, sin importar el estado de la cotización. Efecto
secundario aceptado: facturar por adelantado algo que aún no entregas no mueve nada — que es
correcto, porque la mercancía sigue en tu bodega.

Se eligió esta regla sobre una que rastreara documento por documento quién descontó, porque cabe en
una línea y no tiene excepciones que recordar seis meses después.

### Cancelación de una factura

`POST /api/v1/facturas/{factura}/cancelar` devuelve las piezas al inventario **solo si la factura no
tiene cotización vinculada**. Si la tiene, no devuelve nada: no se devuelve lo que nunca salió.

La devolución ocurre cuando la cancelación queda **aceptada** por el SAT, no cuando se solicita:
mientras el estado de cancelación esté `pending`, la factura sigue vigente y la mercancía sigue
fuera. Por eso el mismo control corre también en el refresco del estado de cancelación que 007 hace
al abrir el detalle, protegido para no devolver dos veces.

La devolución es una **entrada normal** (regla 1): salda primero el faltante y el resto sube la
existencia. Movimiento con `tipo = entrada`, `motivo = cancelacion_factura`, `documentable` la
factura.

Se descartó que cada documento recordara exactamente cuánto quitó de existencia y cuánto de faltante
para reponerlo al revés, porque produce estados imposibles:

> Tienes 10. Facturas 5 → existencia 5, faltante 0. Facturas otras 8 → existencia 0, faltante 3.
> Cancelas la **primera** factura.
>
> Reponiendo "lo que esa factura hizo": +5 a existencia → **existencia 5 con faltante 3**, es decir,
> tienes piezas en la mano y simultáneamente debes piezas.
>
> Como entrada normal: entran 5, saldan el faltante de 3, quedan 2 → **existencia 2, faltante 0**.
> Que es la verdad: tenías 10, vendiste 8, la otra venta se canceló.

La regla simple no solo es más barata: es más correcta.

### Bloqueo de venta sin existencia en Pedido

[Pedido](027-venta-mostrador-ticket.md) (venta de mostrador) es la **única** excepción a "una salida
nunca bloquea la venta". Al guardar un pedido (creación o edición), por cada línea con `articulo_id`:

```
si el artículo no tiene fila en existencias, o su existencia == 0 → rechazar con 422
```

El pedido no se guarda y la respuesta señala qué artículo es el problema. Vender **por arriba** de lo
disponible sí se permite igual que en Factura y Cotización: con existencia `3` y un pedido de `5`
piezas, se guarda, se descuentan las `3`, y las otras `2` quedan como faltante pendiente — el bloqueo
es únicamente para "no tengo ni una pieza", no para "no tengo suficientes".

Se eligió este documento y no Factura o Cotización porque es la única venta que un cliente de
mostrador se lleva **de inmediato**: no hay margen para descubrir después que no había mercancía. Las
líneas libres (sin `articulo_id`) nunca bloquean, igual que hoy.

### Ajustes manuales

`POST /api/v1/inventario/{articulo}/ajuste`, body `{ cantidad, motivo, nota? }`.

- `cantidad` es la **cantidad final** que queda, no la diferencia. Contaste 10, escribes 10.
- `motivo` es **obligatorio**, de la lista cerrada `conteo_fisico` | `merma` | `devolucion` |
  `entrada_inicial` | `otro`. Los motivos automáticos (`recepcion_orden`, `venta_*`,
  `cancelacion_factura`) se rechazan con `422`: no se puede falsificar el origen de un movimiento.
- `nota` es opcional, texto libre.
- **Meter un artículo al inventario por primera vez usa este mismo endpoint.** No hay un alta
  aparte: eliges el artículo en el buscador y capturas su cantidad.

Aplica la regla 3 y registra un movimiento con `tipo = ajuste` y `documentable` en `null`.

### Sugerencia de reposición y generación de órdenes de compra

Un artículo está **por pedir** cuando:

```
(minimo > 0 AND existencia < minimo) OR faltante_pendiente > 0
```

**Estrictamente menor que, no "menor o igual"** — corrección del 2026-08-26. Sin máximo capturado el
techo de la sugerencia es el propio mínimo (ver fórmula abajo), así que con `<=` el artículo que
acaba de reabastecerse **hasta su mínimo exacto** quedaba marcado "por pedir" para siempre, con una
cantidad sugerida de `0`: se generaban órdenes de compra vacías en un ciclo sin salida. Con `<`,
"está por pedir" y "hay algo que sugerir pedir" son siempre la misma condición.

La **cantidad sugerida** es:

```
techo = maximo ?? minimo
sugerida = max(techo − existencia, 0) + faltante_pendiente
```

Ejemplo: mínimo 5, máximo 20, existencia 3, faltante 0 → sugiere 17. Con faltante 4 → sugiere 21,
porque además de rellenar hay que cubrir el descuadre.

`POST /api/v1/inventario/generar-ordenes-compra` toma **todos** los artículos por pedir del usuario,
los agrupa **por proveedor** (vía `articulo.catalogo.proveedor`) y crea **una orden de compra en
`borrador` por proveedor**, con una línea por artículo y la cantidad sugerida.

- Las líneas se precargan igual que cualquier orden de 012: `precio_unitario` con
  `costo_con_descuento`, `descripcion` y `modelo` copiados del artículo, `tasa_iva` la del artículo.
- Los totales se calculan en backend con `FacturaTotalesCalculator`, sin excepción.
- Cada orden toma su folio por usuario, como cualquier otra.
- Los artículos cuyo catálogo o proveedor está borrado lógicamente **se omiten**, y la respuesta los
  lista aparte para que el usuario sepa qué quedó fuera y por qué.
- **No se envía nada al proveedor.** Quedan en `borrador`, para revisar, corregir y enviar a mano.

La respuesta devuelve las órdenes creadas y los artículos omitidos.

### Totales del inventario

Cuatro cifras, calculadas **sobre el conjunto filtrado completo**, nunca sobre la página visible:

- **Unidades totales**: `SUM(existencia)`.
- **Dinero invertido**: `SUM(existencia × (costo_con_descuento + costo_goma))`.
- **Beneficio potencial**: `SUM(existencia × (precio_unitario_sin_iva − costo_con_descuento − costo_goma))`.
- **Total general**: dinero invertido + beneficio potencial — el valor de venta completo de todo lo
  que hay hoy en la bodega, sin IVA.

"Artículos por pedir" **deja de ser una tarjeta de total** y pasa a ser solo el filtro `?por_pedir=1`
de la tabla (con un contador junto al switch, no una tarjeta aparte): con el inventario ahora
curado a mano, el usuario ya sabe de entrada qué tan grande es su bodega, y la cifra que le interesa
de un vistazo es cuánto vale, no cuántos artículos están bajos.

Si se sumaran los 15 registros de la página, el "dinero invertido" cambiaría al pasar de página: sería
un número bonito y falso. Por eso los totales son una consulta agregada aparte, resuelta en la base
de datos, y viajan en `meta.totales` de la misma respuesta del listado.

El **ordenamiento** por dinero invertido o beneficio potencial se traduce a la misma aritmética dentro
del `ORDER BY`, porque `costo_total` y `utilidad` son atributos calculados y no columnas. Es el
camino que ya tomaron [011](011-precio-proveedor-utilidad.md) y
[014](014-costo-elaboracion-goma.md) para ordenar por costo y utilidad, así que no es terreno nuevo.

### Auditoría de existencias

`GET /api/v1/inventario/auditoria` recorre el historial de cada artículo en orden y reaplica las tres
reglas desde cero, comparando el resultado contra las columnas guardadas. Devuelve la lista de
artículos donde no coinciden, con ambos valores.

**Solo reporta; no corrige nada.** Si aparece un descuadre, se corrige con un ajuste manual, que
queda registrado como tal. Una reparación silenciosa borraría la evidencia de que algo estuvo mal.

No es una pantalla de uso diario: es la red de seguridad para el día que se sospeche de un número.

### Atomicidad y bloqueo

Dos protecciones, ambas dentro de la misma transacción:

**Idempotencia.** Cada operación verifica el estado de origen **dentro** de la transacción, no antes:
recibir exige que la orden siga en `pagada`, timbrar que la factura siga sin timbrar, entregar que la
cotización no esté ya en `producto_entregado`. El segundo intento —doble clic, `F5` a media
operación, reintento de red— encuentra el estado ya cambiado y **no hace nada**, sin error visible
para el usuario. Sin esto, un doble clic en "Recibir" sumaría la mercancía dos veces y además
saldaría faltantes que sí existían.

**Bloqueo de fila.** El artículo se bloquea (`lockForUpdate`) antes de leer sus contadores y hasta
terminar de escribirlos. Sin eso, dos salidas simultáneas del mismo artículo leen ambas `12`,
calculan `7` y `8`, y la segunda pisa a la primera: salieron nueve piezas y el sistema registró
cuatro. El riesgo real hoy es bajo —el inventario es por usuario y la idempotencia ya cubre el doble
clic—, pero el costo es una línea dentro de una transacción que de todos modos existe.

**El marcador y el historial se escriben siempre juntos.** Las columnas del artículo y el
`MovimientoInventario` se guardan en la misma transacción: si una falla, no se hace ninguna. Nunca
puede quedar una existencia que el historial no explique.

### Endpoints (bajo `auth:sanctum`, scopeados al usuario autenticado)

- `GET /api/v1/inventario` — listado paginado (15 por página, como
  [ArticuloController](../backend/app/Http/Controllers/ArticuloController.php)) de los artículos
  **con fila en `existencias`**. Filtros combinables: `?q=` (nombre o modelo), `?catalogo=`,
  `?proveedor=`, `?por_pedir=1`. Orden por `?orden=` (`nombre`, `modelo`, `existencia`, `invertido`,
  `beneficio`) y `?dir=`. Respuesta con `meta.totales`.
- `PUT /api/v1/inventario/{articulo}/parametros` — body `{ minimo, maximo? }`. No genera movimiento:
  cambiar un umbral no mueve piezas. Requiere que el artículo ya tenga fila (`404` si no).
- `POST /api/v1/inventario/{articulo}/ajuste` — ver "Ajustes manuales". Crea la fila si no existe.
- `DELETE /api/v1/inventario/{articulo}` — quita el artículo de existencias (borrado lógico de su
  fila). Ver "Cómo se marca y se quita un artículo de existencias".
- `GET /api/v1/inventario/{articulo}/movimientos` — historial paginado, más reciente primero. No
  requiere fila vigente en `existencias` (el historial sobrevive a quitar el artículo).
- `POST /api/v1/inventario/generar-ordenes-compra` — ver arriba.
- `GET /api/v1/inventario/auditoria` — ver arriba.

Las rutas estáticas (`generar-ordenes-compra`, `auditoria`) se declaran **antes** del binding
`{articulo}`, o Laravel las captura como si fueran un artículo — mismo cuidado que ya se tomó con
`articulos/exportar-csv` en `routes/api.php`.

Respuestas mediante API Resources (`InventarioResource`, `MovimientoInventarioResource`), consistente
con la convención de 001/004/005/006/007/008/009/010/012.

### Validaciones (Form Requests)

- `cantidad` (ajuste): requerida, entero, `min:0`. Cero es válido: "no me queda ninguno".
- `motivo` (ajuste): requerido, dentro de la lista manual; los motivos automáticos se rechazan.
- `nota`: opcional, string, `max:500`.
- `minimo`: requerido, entero, `min:0`. `maximo`: opcional, entero, `min:0`, `gte:minimo`.
- `{articulo}` debe pertenecer al usuario autenticado en los tres endpoints por artículo (`404` si
  no, no `403`, mismo criterio que el resto del sistema).
- `cotizacion_id` (factura): **sin cambios**. `StoreFacturaRequest` ya lo valida como opcional, del
  usuario y sin `factura_id` previo — es decir, ya garantiza que una cotización se factura una sola
  vez.

### Tests

Feature tests sobre la base MySQL de trabajo mediante `php artisan test` (nunca `migrate:fresh`, ver
[[feedback_nunca_migrate_fresh_en_dev]]):

1. Recibir una orden pagada suma las cantidades de sus líneas a la existencia de cada artículo.
2. Recibir dos veces la misma orden suma una sola vez.
3. Las líneas sin `articulo_id` se ignoran al recibir.
4. Dos líneas del mismo artículo en una orden suman su total y generan **un** movimiento.
5. Recibir con faltante pendiente salda primero el faltante y luego sube la existencia.
6. Timbrar una factura sin cotización vinculada descuenta; con cotización vinculada, no.
7. Marcar una cotización como entregada descuenta.
8. Vender más de lo disponible deja existencia en 0 y acumula el resto en faltante, sin fallar.
9. Cancelar una factura sin cotización vinculada devuelve las piezas saldando faltante primero.
10. Cancelar una factura con cotización vinculada no devuelve nada.
11. Un ajuste manual fija la cantidad final y pone el faltante en 0.
12. Un ajuste con motivo automático se rechaza con `422`.
13. La condición de "por pedir" y la cantidad sugerida, incluyendo el caso con faltante.
14. Generar órdenes de compra crea una orden en `borrador` por proveedor, con totales correctos.
15. Los artículos de catálogos o proveedores borrados se omiten y se reportan.
16. Los totales del listado corresponden al conjunto filtrado completo, no a la página.
17. La auditoría detecta un descuadre introducido a mano y no lo corrige.
18. Todos los endpoints devuelven `404` para artículos de otro usuario.
19. Un artículo sin fila en `existencias` no aparece en `/api/v1/inventario`.
20. Recibir una orden con un artículo sin fila en `existencias` la crea en `0` antes de sumar.
21. Marcar una cotización como entregada con un artículo sin fila en `existencias` la crea en `0` y
    le aplica la salida en el mismo acto.
22. Timbrar una factura suelta (sin cotización) con un artículo sin fila en `existencias` no genera
    movimiento para esa línea y no crea la fila.
23. Guardar un Pedido con una línea de un artículo sin fila en `existencias`, o con existencia `0`,
    se rechaza con `422` y no se guarda ninguna línea del pedido.
24. Guardar un Pedido pidiendo más piezas de las que hay en existencia (pero más de `0`) sí se
    permite y genera faltante pendiente.
25. Quitar un artículo de existencias lo oculta del listado pero conserva su historial; volverlo a
    marcar restaura la misma fila con sus números previos, no una fila nueva en ceros.

## Frontend (Vue 3)

### Pantallas

- **`/existencias`** (`ExistenciasListView.vue`, protegida): la pantalla principal del módulo, y
  lista **solo** los artículos que el usuario marcó "en existencias" — es su bodega curada, no el
  catálogo completo.
  - **Cuatro tarjetas de totales** arriba: unidades, dinero invertido, beneficio potencial y **total
    general** (invertido + beneficio). Se recalculan con los filtros aplicados; filtrar por un
    proveedor muestra el dinero invertido en ese proveedor.
  - **Tabla**: modelo · catálogo · existencia · faltante · mínimo · máximo · invertido · beneficio,
    ordenable por las columnas numéricas. **No lleva columna de nombre del artículo** (corrección
    del 2026-08-26): con nueve columnas la tabla desbordaba en scroll horizontal, y modelo ya
    identifica al artículo sin ambigüedad dentro de un catálogo — el nombre completo queda como
    `title` (tooltip) sobre la celda de modelo, para quien lo necesite sin gastar ancho. Los
    renglones por pedir se destacan visualmente; el faltante se muestra solo cuando es mayor a cero.
  - **Filtros**: búsqueda por nombre o modelo, catálogo, proveedor y switch "Solo por pedir" (con
    contador). No hay switch "Ver todos": al ser una lista curada a mano, por defecto ya se muestra
    completa.
  - **Acciones por renglón**: un botón "⋮" abre un menú con "Ajustar" (diálogo con cantidad final,
    motivo y nota), "Mínimo/máximo" (diálogo con los dos umbrales), "Ver movimientos" y
    **"Quitar de existencias"** (confirma antes de borrar la fila; si la existencia no está en cero,
    el diálogo lo advierte explícitamente). Van agrupadas en un menú y no como botones sueltos
    porque con nueve columnas en la tabla, cuatro botones por renglón desbordaban el ancho y
    forzaban scroll horizontal (corrección del 2026-08-26).
  - **Acción de pantalla**: "Agregar artículo a existencias" — buscador de artículos del catálogo
    general, que abre el mismo diálogo de ajuste; y **"Generar órdenes de compra"**, que muestra antes
    un resumen de qué se va a crear y para qué proveedores. Al confirmar, si se creó **una sola**
    orden (un solo proveedor con artículos por pedir), lleva directo al **detalle de esa orden**
    para revisarla y corregirla antes de enviarla — no tiene sentido obligar a un clic más para
    llegar a lo único que se acaba de crear (corrección del 2026-08-26: antes siempre mandaba al
    listado). Si se crearon **varias** órdenes (varios proveedores), no hay una sola orden a la que
    ir, así que lleva al listado de órdenes de compra, con los borradores recién creados a la vista.
- **`/existencias/{id}/movimientos`** (`ExistenciaMovimientosView.vue`): historial paginado del
  artículo — fecha, tipo, motivo, cantidad, existencia y faltante resultantes, nota, y enlace al
  documento origen cuando lo hay. Solo lectura.

Los diálogos usan el `Dialog` del design system de [003](003-design-system-tailwind.md), igual que el
diálogo de artículo duplicado de 008.

### Cambios en pantallas existentes

- **Formulario de factura** (`FacturaFormView.vue`): el vínculo con la cotización ya llega por la
  URL desde el detalle de cotización, así que **no se agrega ningún campo**. Lo que se agrega es un
  aviso visible cuando la factura viene de una cotización: *"El inventario se descontará al marcar
  la cotización como entregada, no al timbrar esta factura."* Sin ese aviso la regla es invisible y
  parece un error.
- **Detalle de orden de compra** (`OrdenCompraDetalleView.vue`): el botón "Marcar como recibida"
  advierte en su confirmación que la mercancía entrará al inventario y que la acción no se puede
  deshacer.
- **Formulario de artículo** (`ArticuloFormView.vue`): **sin cambios**. Existencia, faltante, mínimo
  y máximo no se editan ahí. Artículos son datos maestros; Existencias es la operación diaria.
- **Lista general de artículos** (`ArticuloListView.vue`, [006](006-gestion-articulos.md)): gana una
  columna **"En existencias"** con una insignia Sí/No por artículo. Sobre "No" hay una acción rápida
  "Pasar a existencias" que abre el mismo diálogo de ajuste que usa `/existencias`; sobre "Sí" la
  acción lleva directo a ese artículo dentro de `/existencias`. Es el mismo mecanismo de siempre, solo
  alcanzable desde el catálogo general para no obligar a cambiar de pantalla.
- **Formulario de Pedido** (`PedidoFormView.vue`, [027](027-venta-mostrador-ticket.md)): al intentar
  agregar o guardar una línea de un artículo sin existencia, el formulario muestra el error que
  devuelve el backend junto a esa línea, sin dejar guardar el pedido.

### Navegación

Se agrega **Existencias** al grupo **Inventario** de [013](013-navegacion-principal.md), que queda:
Artículos · Catálogos · **Existencias**.

La entrada se llama "Existencias" y no "Inventario" a propósito: el grupo del menú ya se llama
Inventario, y *Inventario → Inventario* no le dice nada a nadie.

## Fuera de alcance

- **Almacenes o ubicaciones múltiples**, traspasos entre ellos, y mercancía "en tránsito".
- **Números de serie, lotes y caducidades**: diez piezas de un artículo son diez piezas
  indistinguibles.
- **Recepción parcial** de una orden de compra, línea por línea, o con cantidades editables al
  recibir. La recepción es total y se corrige después con un ajuste.
- **Deshacer una recepción**. Se corrige con un ajuste manual.
- **Costo promedio ponderado, PEPS, o costo histórico por entrada.** Ver "Valuación al costo de hoy".
- **Inventario de insumos de goma** (polímero, negativos, tinta, mangos), explícitamente fuera desde
  [014](014-costo-elaboracion-goma.md).
- **Alertas** por correo, WhatsApp o notificación por artículos bajo mínimo: la señal es visual,
  dentro de la pantalla de Existencias.
- **Sugerencia automática del mínimo** a partir del historial de ventas. El mínimo se captura a mano.
- **Envío automático** de las órdenes de compra generadas: nacen en `borrador`.
- **Bloqueo del borrado de un artículo** con existencia. Sigue siendo borrado lógico; deja de contar
  en los totales y su historial se conserva.
- **Efecto en Tesorería.** El dinero salió al pagar la orden; recibir mercancía no genera ningún
  movimiento de dinero.
- **Exportación** del inventario a Excel o PDF.
- **Validación `422` de artículo duplicado en el backend** de factura, cotización y orden de compra.
  El hueco existe (ver [008](008-cotizaciones.md), adición 31) y esta spec lo cubre defensivamente
  agrupando, pero cerrarlo cambia el contrato de tres endpoints y merece spec propia.

## Estado de implementación

**La revisión del 2026-08-26 (tabla `existencias`, inventario opt-in, bloqueo en Pedido) está
implementada.** Migración aplicada sobre la base de trabajo (9 artículos con movimiento real
migraron su fila automáticamente). `php artisan test` corre limpio (687 tests, 25 nuevos o
reescritos en `InventarioTest.php`); Pint, ESLint, `vue-tsc`/`vite build` y Vitest también.
**No se pudo verificar visualmente en un navegador real** (limitación de entorno): se recomienda
abrir `/existencias`, marcar un artículo desde la lista de Artículos, y confirmar el total general
y el bloqueo de Pedido con un artículo en cero.

### Implementación original (2026-08-10, superada por la revisión de arriba)

`php artisan test` corría limpio (349 tests, 31 de ellos nuevos en
`tests/Feature/InventarioTest.php`); ESLint, Prettier y Vitest también.
**No se pudo verificar visualmente en un navegador real** (limitación de entorno).

La versión original de esta sección afirmaba además que `vue-tsc` y `vite build` corrían limpios.
No era cierto — ver la corrección de abajo.

### Dos correcciones sobre lo que esta spec afirmaba

- **El vínculo factura → cotización ya existía**, en sentido inverso: `cotizaciones.factura_id`, que
  el flujo "Facturar" del detalle de cotización escribe desde 008, y `Factura::cotizacion()` ya
  estaba declarado como `hasOne`. La versión original de esta spec proponía una columna
  `facturas.cotizacion_id` nueva; se descartó al encontrarlo, porque habría dejado dos fuentes de
  verdad contradictorias. La migración correspondiente se eliminó antes de aplicarse.
- **El orden de escritura de ese vínculo estaba mal para inventario.** `FacturaController::store`
  lo escribía *después* de `intentarTimbrar()`, y el timbrado es justo el momento en que se decide
  si descontar existencias: toda factura nacida de una cotización se habría visto como venta de
  mostrador y habría descontado mercancía que la cotización descuenta otra vez al entregarse. Se
  movió dentro de la transacción de creación, antes de timbrar.

### Detalles resueltos durante la implementación

- **La devolución por cancelación se ata al estado `accepted`**, no a la solicitud de cancelación.
  El SAT puede dejarla `pending`, y mientras tanto la factura sigue vigente. El mismo control corre
  en `refrescarEstadoCancelacion()`, con un guardia que impide devolver dos veces.
- **Los alias de los agregados llevan prefijo `suma_`.** `Articulo` tiene accesores llamados
  `dinero_invertido` y `beneficio_potencial`; un alias SQL con ese nombre queda eclipsado por el
  accesor, que recalcula sobre atributos no seleccionados y devuelve 0. Costó un test en rojo.
- **La línea generada usa la tasa general de IVA.** El artículo no guarda una tasa propia, así que
  la línea nace en `16` —igual que cuando se captura a mano en `DocumentoLineas`— y es editable en
  el borrador.
- **La recepción ahora se confirma.** Antes era un clic directo; al volverse irreversible *y*
  mover existencias, el botón abre un diálogo que lo advierte.

### Corrección del 2026-08-11: los campos numéricos vacíos de `/existencias`

`npm run build` no compilaba. Los diálogos de ajuste de existencia y de umbrales ataban
`cantidadAjuste` y `maximo` —ambos `ref<number | null>`, porque un campo sin capturar no tiene
valor— a `<Input>` con `v-model.number`, y el componente declara `modelValue?: string | number`.
`vue-tsc` lo rechazaba con dos `TS2322`, así que no se generaba `dist/`.

Se corrigió en `ExistenciasListView.vue` adoptando el patrón que el proyecto ya usaba en el
diálogo de pago de `CotizacionDetalleView.vue` y en el de complemento de `FacturaDetalleView.vue`:
la coerción se hace en el sitio de uso, con `:model-value="x ?? undefined"` y un
`@update:model-value` que traduce la cadena vacía de vuelta a `null`. `minimo` no necesitó cambio:
es `ref<number>(0)`, nunca nulo.

La alternativa —ampliar `Input.vue` a `string | number | null`— se descartó: es un componente
generado por shadcn, y divergirlo significa perder el cambio en la siguiente regeneración a cambio
de nada que el patrón existente no resuelva ya.

El defecto se coló porque `npm run dev` no hace comprobación de tipos y `npm run build` sí
(`vue-tsc -b && vite build`). Se detectó al preparar el despliegue (ver
`018-despliegue-hostinger.md`), que es el primer momento en que el build de producción se corrió
de verdad.

### Correcciones del 2026-08-26, encontradas en uso real

Cuatro defectos, ya corregidos arriba:

- **La tabla de `/existencias` desbordaba con scroll horizontal.** Cuatro botones de acción por
  renglón (Ajustar, Mínimo/Máximo, Movimientos, Quitar) no cabían junto a las demás columnas. Se
  reemplazaron por un único botón "⋮" con un menú desplegable — ver "Pantallas" arriba.
- **Seguía desbordando incluso con el menú "⋮".** La columna del nombre del artículo, además del
  modelo, era demasiado para nueve columnas. Se quitó: modelo ya identifica al artículo sin
  ambigüedad, y el nombre completo quedó como `title` (tooltip) sobre la celda de modelo — ver
  "Pantallas" arriba.
- **Un artículo reabastecido justo hasta su mínimo se quedaba marcado "por pedir" para siempre, y
  "Generar órdenes de compra" le sugería 0 piezas.** Causa: sin máximo capturado, el techo de la
  sugerencia es el propio mínimo, y "por pedir" comparaba con `<=`; en `existencia == mínimo` ambas
  cosas convivían de forma contradictoria. Se corrigió a `<` estricto — ver "Sugerencia de
  reposición" arriba, donde queda la explicación completa.
- **"Generar órdenes de compra" siempre mandaba al listado de órdenes**, incluso cuando solo se
  creó una. El usuario tenía que dar un clic más para llegar a la propia orden y revisarla. Con una
  sola orden creada, ahora lleva directo a su detalle; con varias, sigue yendo al listado — ver
  "Pantallas" arriba.

## Criterios de aceptación

1. La pantalla `/existencias` lista **solo** los artículos marcados "en existencias", con existencia,
   faltante, mínimo, máximo, dinero invertido y beneficio potencial, y muestra los cuatro totales
   (unidades, invertido, beneficio potencial y total general) del conjunto filtrado.
2. Un artículo nunca marcado "en existencias" no aparece en `/existencias` bajo ningún filtro.
3. Los totales cambian al aplicar un filtro y **no** cambian al pasar de página.
4. Ordenar por dinero invertido ordena todos los artículos del conjunto filtrado, no solo los quince
   visibles.
5. Marcar como recibida una orden de compra pagada suma las cantidades de sus líneas al inventario y
   deja un movimiento por artículo, con enlace a la orden.
6. Volver a ejecutar la recepción de una orden ya recibida no vuelve a sumar.
7. Una orden con dos líneas del mismo artículo suma la cantidad total y deja un solo movimiento.
8. Timbrar una factura sin cotización vinculada descuenta las piezas; si la existencia no alcanza, la
   factura se timbra igual, la existencia queda en 0 y el resto se registra como faltante pendiente.
9. Timbrar una factura con cotización vinculada no mueve el inventario; el descuento ocurre al marcar
   esa cotización como "producto entregado".
10. Cancelar una factura sin cotización vinculada devuelve las piezas, saldando primero el faltante
    pendiente; cancelar una factura con cotización vinculada no devuelve nada.
11. Un ajuste manual captura la cantidad final, exige motivo, deja el faltante en cero y queda en el
    historial con su motivo y su nota.
12. Pasar un artículo a existencias por primera vez se hace desde el buscador de la pantalla
    `/existencias` **o** desde la acción rápida de la lista general de Artículos, y ambos usan el
    mismo diálogo de ajuste.
13. Quitar un artículo de existencias lo oculta de `/existencias`, pero su historial y sus números
    se conservan; volverlo a marcar restaura los mismos valores.
14. Guardar un Pedido con una línea de un artículo sin fila en existencias, o con existencia en
    cero, se rechaza y no se guarda; con existencia insuficiente pero mayor a cero, sí se guarda y
    genera faltante pendiente, igual que Factura y Cotización.
15. Un artículo que hoy ya tiene existencia, faltante pendiente o mínimo distintos de cero queda
    marcado "en existencias" automáticamente al aplicar esta revisión, sin que el usuario tenga que
    volver a darlo de alta.
16. Un artículo aparece como "por pedir" cuando su existencia es **estrictamente menor** que su
    mínimo (con mínimo mayor a cero) o cuando tiene faltante pendiente; en el mínimo exacto ya no
    se marca (corrección del 2026-08-26, ver "Sugerencia de reposición").
17. La cantidad sugerida es (máximo − existencia) + faltante pendiente, sin bajar de cero.
18. "Generar órdenes de compra" crea una orden en `borrador` por proveedor con los artículos por
    pedir y sus cantidades sugeridas, sin enviar nada, y reporta los artículos omitidos por tener el
    catálogo o el proveedor borrado.
19. El historial de un artículo muestra cada movimiento con su tipo, motivo, cantidad, existencia y
    faltante resultantes, y enlace al documento origen cuando aplica; no se puede editar ni borrar.
20. La auditoría reporta cualquier artículo cuya existencia guardada no coincida con la reconstruida
    desde su historial, y no la modifica.
21. Ningún endpoint del módulo permite ver ni mover artículos de otro usuario.

## Supuestos asumidos (registro completo)

1. La unidad que se cuenta es el **Artículo**, no el catálogo ni el modelo como texto. Dos artículos
   de catálogos distintos con el mismo modelo son existencias separadas y no se suman.
2. La existencia, el faltante, el mínimo y el máximo son columnas de una **tabla propia,
   `existencias`**, con una fila por artículo marcado a mano. No son columnas de `articulos`: el
   catálogo general puede tener miles de artículos que nunca se almacenan, y meterles cuatro columnas
   más a todos ellos no reflejaría eso.
3. El inventario es **opt-in**: un artículo entra a existencias solo cuando el usuario lo marca a
   mano ("pasar a existencias") o cuando una entrada real lo da de alta por su cuenta (recepción de
   orden de compra, o una cotización que se entrega con un artículo que no tenía fila). No hay
   "todos los artículos desde el día uno"; un artículo nunca marcado no aparece en `/existencias` bajo
   ningún filtro.
4. La existencia **nunca es negativa**: toca fondo en `0` y el excedente se acumula como faltante
   pendiente.
5. Un faltante pendiente es un **descuadre de registro**, no una deuda con un cliente: la mercancía
   ya salió físicamente.
6. Toda entrada que suma una cantidad —recepción y devolución por cancelación— **salda primero el
   faltante**. Es una sola regla para todo el sistema.
7. Todo ajuste manual **fija** la cantidad final y **pone el faltante en cero**. El alta manual es
   ese mismo ajuste con punto de partida en cero, por eso también fija en lugar de sumar.
8. El inventario se valúa con el **costo actual** del artículo; cambiar precios revalúa el inventario
   completo. No hay costo promedio, PEPS ni costo histórico por entrada.
9. Todas las cifras del módulo son **sin IVA**, en pesos mexicanos.
10. El beneficio potencial usa el precio de lista del artículo, sin descuentos de cliente (015).
11. El mínimo se captura **a mano** por artículo; el sistema no lo deduce del historial. Un mínimo en
    `0` significa "no me avises de este artículo".
12. El máximo es opcional; si no se captura, el techo de la sugerencia es el propio mínimo.
13. El sistema **sugiere** cuánto pedir y **arma borradores** de orden de compra agrupados por
    proveedor, pero nunca envía nada al proveedor por su cuenta.
14. Las ventas descuentan inventario: la factura al timbrarse, la cotización al marcarse
    `producto_entregado`. Ambos caminos existen en el negocio y ninguno cubre al otro.
15. Si una factura tiene cotización vinculada, **la cotización manda siempre**: esa factura nunca
    descuenta ni devuelve, aunque la cotización aún no se haya entregado. Facturar antes de entregar
    no mueve inventario porque la mercancía sigue en bodega.
16. El vínculo es el `cotizaciones.factura_id` que 008 ya escribe; no se crea una columna nueva en
    `facturas`, para no tener dos fuentes de verdad. La única corrección al flujo existente es
    escribirlo **antes** de timbrar, porque el timbrado es el momento en que se decide si descontar.
17. Una cotización se factura una sola vez; `StoreFacturaRequest` ya lo garantiza desde 008.
18. La cancelación devuelve piezas solo cuando el SAT la **acepta**; una cancelación `pending` deja
    la mercancía fuera, porque la factura sigue vigente.
19. Una salida **nunca bloquea** la venta en Factura ni en Cotización. La única excepción es Pedido de
    mostrador, que sí bloquea cuando la existencia es exactamente `0` (ver 33).
20. La recepción de una orden es **total, irreversible y una sola vez**; se corrige con un ajuste.
21. Las líneas de documento sin `articulo_id` se ignoran en todos los movimientos.
22. Las líneas del mismo artículo dentro de un documento se **agrupan y suman** antes de mover
    existencias, como red defensiva ante el hueco de validación del backend.
23. No hay carga retroactiva: las órdenes ya `recibida` antes de esta historia no suman nada.
24. El historial es **solo de consulta**; los errores se corrigen con movimientos nuevos, no
    borrando los viejos.
25. La existencia se guarda como número (no se recalcula en cada consulta) y se escribe **siempre en
    la misma transacción** que su movimiento, con el artículo bloqueado. La auditoría es la red de
    seguridad, y solo reporta.
26. Los totales y el ordenamiento se resuelven sobre el **conjunto filtrado completo** en la base de
    datos, nunca sobre la página visible.
27. Un solo almacén; sin series, lotes ni caducidades.
28. El inventario es **por usuario**, mismo patrón que 004/005/006/007/008/009/010/012.
29. El inventario **no toca Tesorería**.
30. El módulo vive en una pantalla propia llamada **Existencias**, dentro del grupo Inventario del
    menú. Artículos sigue siendo datos maestros y no gana columnas de existencia.
31. Borrar un artículo no se bloquea por tener existencia: deja de contar en los totales y su
    historial se conserva.
32. Esta spec **supera** la decisión de 012 de no llevar inventario; la 012 se anota en consecuencia.
33. Pedido de mostrador ([027](027-venta-mostrador-ticket.md)) bloquea guardar una línea cuyo artículo
    no tiene fila en existencias o cuya existencia es `0`. Vender por arriba de lo disponible (pero
    más de `0`) sigue permitido y genera faltante, igual que Factura y Cotización.
34. Toda **entrada** real (recepción de orden de compra, ajuste manual) crea la fila en `existencias`
    si no existe. Entre las **salidas**, solo Cotización crea la fila por su cuenta; Factura suelta
    simplemente no mueve nada si el artículo no tiene fila, y Pedido lo bloquea.
35. Quitar un artículo de existencias es un **borrado lógico** de su fila: no bloquea aunque tenga
    existencia distinta de cero, y volver a marcarlo restaura la misma fila con sus números previos
    en vez de crear una nueva en ceros. El historial de movimientos sigue ligado al artículo, no a la
    fila, así que nunca se pierde por quitar y volver a marcar.
36. Al aplicar esta revisión, los artículos que ya tenían existencia, faltante o mínimo distintos de
    su valor por defecto bajo el diseño anterior quedan marcados "en existencias" automáticamente; el
    resto del catálogo queda sin marcar hasta que el usuario lo decida.
