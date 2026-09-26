# Spec: Precio distribuidor (segundo precio de venta, sin goma) y cliente distribuidor

## Historia de usuario

Como usuario único del sistema de facturación, quiero que cada artículo tenga, además del precio de
venta que ya calcula el sistema, un segundo precio para mis clientes distribuidores —que no pagan el
costo de la goma y llevan su propia utilidad—, para poder cotizar a cualquiera de los dos tipos de
cliente sin sacar la cuenta a mano cada vez.

Además, quiero poder marcar en la ficha de cada cliente si es distribuidor, para que al armar una
cotización o una factura para ese cliente el sistema elija automáticamente el precio distribuidor en
cada línea, sin tener que acordarme de cambiarlo a mano artículo por artículo.

## Objetivo / Alcance

Ampliar la cadena de precios de `Articulo` y `Catalogo` ([006](006-gestion-articulos.md),
[009](009-catalogos.md), [011](011-precio-proveedor-utilidad.md),
[014](014-costo-elaboracion-goma.md), [024](024-precios-sin-centavos.md)) para que cada artículo
tenga **dos** precios de venta calculados a partir del mismo costo:

- **Precio directo**: el que el sistema ya calcula hoy (`precio_unitario_sin_iva`). No cambia de
  comportamiento; esta historia solo le pone nombre.
- **Precio distribuidor**: nuevo, calculado sobre el costo **sin goma** y con su **propia** utilidad
  configurable, con la misma regla de redondeo al peso entero (024) y la misma regla de IVA por
  `objeto_imp` (024) que ya rigen al directo.

Esta historia cubre **calcular y mostrar** el precio distribuidor en el artículo (alta, edición,
listado, catálogo, ficha de compartir) **y** clasificar clientes como distribuidores para que ese
precio se use automáticamente, en vez del directo, al capturar líneas de artículo en Cotizaciones
([008](008-cotizaciones.md)) y en Facturación ([007](007-facturacion.md)) — incluida una factura
creada desde cero, sin pasar por una cotización. **No** incluye Venta de mostrador
([027](027-venta-mostrador-ticket.md)) ni Órdenes de compra ([012](012-ordenes-compra.md)): la
primera no tiene un `Cliente` con RFC al que consultarle el estatus (ver "Fuera de alcance"), y la
segunda es el precio que se le paga al proveedor, no el de venta.

## Cadena de cálculo

Con costo con descuento (el "costo neto") $200.00, costo de goma $20.00, utilidad directo 50%,
utilidad distribuidor 25%, IVA general (artículo con `objeto_imp` = "02"):

```
                              costo_con_descuento              $200.00
                                        │
                    ┌───────────────────┴───────────────────┐
                    │                                        │
              + costo_goma                              (sin goma)
                    │                                        │
         costo_total = $220.00                    costo_con_descuento = $200.00
                    │                                        │
       × (1 + utilidad_directo / 100)          × (1 + utilidad_distribuidor / 100)
         = techo2(220 × 1.50)                        = techo2(200 × 1.25)
         = $330.00 (sin IVA, crudo)                  = $250.00 (sin IVA, crudo)
                    │                                        │
        redondeo al peso entero (024)             redondeo al peso entero (024)
      con el mismo factorIva del artículo        con el mismo factorIva del artículo
                    │                                        │
    precio_unitario_sin_iva = $330.17           precio_distribuidor_sin_iva = $250.00
      → con IVA: $383.00                          → con IVA: $290.00
```

El redondeo al peso entero es el que hace que el precio directo con IVA salga en **$383.00** y no en
los $382.80 de una fórmula sin redondear: $330.00 × 1.16 = $382.80, que no es peso entero, así que
024 sube al siguiente ($383.00) exactamente igual que ya lo hace hoy con cualquier artículo. El
precio distribuidor sí cae en peso entero de forma exacta en este ejemplo ($250.00 × 1.16 =
$290.00), pero no siempre será así: si la utilidad distribuidor sube a 30%, $200 × 1.30 = $260.00 sin
IVA crudo, $260 × 1.16 = $301.60, que **no** es peso entero, y el sistema entrega
`precio_distribuidor_sin_iva = $261.21` → con IVA **$303.00**, no $301.60 (el primer candidato,
$260.34, da $301.99 con IVA — no alcanza el objetivo de $302, así que 024 sube un peso más). Es la
misma regla 024 que ya gobierna al precio directo; el precio distribuidor la hereda sin excepción.
Estos cuatro valores están verificados contra la implementación real, no son aritmética de mano —
viven además como casos del fixture compartido (ver "Fuente de verdad única de la fórmula").

En un artículo cuyo `objeto_imp` no causa IVA, ninguno de los dos precios lleva el 16% encima, igual
que ocurre hoy con el directo.

## Backend (Laravel)

### `Catalogo`

- **Nueva columna `utilidad_distribuidor_porcentaje`**: `decimal(5,2)`, obligatoria, con **valor por
  defecto 0**, mismo patrón que `utilidad_porcentaje` (011). Es la utilidad distribuidor por defecto
  de todos los artículos del catálogo.
- **`Catalogo::booted()`** (recálculo en bloque, `app/Models/Catalogo.php:59-114`) se amplía:
  - El bloque que ya se dispara con `wasChanged('descuento')` recalcula **ambos** precios de todos
    los artículos del catálogo (incluidos los que tienen utilidad propia, de cualquiera de los dos
    tipos), porque un cambio de descuento mueve el costo del que parten los dos.
  - Se agrega un bloque nuevo, paralelo al de `utilidad_porcentaje`, que se dispara con
    `wasChanged('utilidad_distribuidor_porcentaje')` y recalcula `precio_distribuidor_sin_iva`
    **solo** de los artículos cuyo `utilidad_distribuidor_porcentaje` es `NULL` (los que heredan).
- **Endpoint de previsualización de impacto** (`impacto-precios`, ver abajo) acepta también
  `utilidad_distribuidor_porcentaje` como parámetro opcional.

### `Articulo`

- **Nueva columna `utilidad_distribuidor_porcentaje`**: `decimal(5,2)`, `nullable`. `NULL` = hereda
  la del catálogo; un valor = utilidad distribuidor propia del artículo. Misma herencia viva que
  `utilidad_porcentaje` (011).
- **Nueva columna `precio_distribuidor_sin_iva`**: `decimal(10,2)`, calculada y **persistida**, mismo
  criterio que `precio_unitario_sin_iva`: se recalcula en el alta, la edición, la importación CSV, el
  aumento de costos y los dos recálculos en bloque de `Catalogo::booted()`.
- **Nuevo accessor `precioDistribuidorConIva`**: espejo exacto de `precioUnitarioConIva`
  (`app/Models/Articulo.php:132-140`) — mismo `PrecioArticuloCalculator::factorIva($this->objeto_imp)`,
  no persistido.
- El precio distribuidor **no** entra en `costoTotal`, `utilidad` ni `dineroInvertido` /
  `beneficioPotencial`: esos accessors siguen midiéndose contra el costo con goma y el precio directo,
  porque son los que ya reflejan lo que de verdad se vende y se tiene en existencia hoy.

### `Cliente`

- **Nueva columna `es_distribuidor`**: `boolean`, `NOT NULL`, con **valor por defecto `false`**,
  mismo patrón de columna simple que `descuento_permanente`
  ([015](015-descuento-permanente-cliente.md)). No es nullable: no existe un tercer estado, un
  cliente es distribuidor o no lo es.
- **Sin historial**: cambiar la marca sobrescribe el valor anterior, sin registro de cuándo cambió
  (mismo criterio que `descuento_permanente`).
- `ClienteResource` agrega `es_distribuidor` (boolean). `ClienteFactory` lo acepta y por defecto lo
  deja en `false`, para que los tests existentes de 004/007/008/015 no cambien de resultado.
- `StoreClienteRequest`/`UpdateClienteRequest`: `es_distribuidor` es **opcional**, `boolean`; ausente
  o cadena vacía se normaliza a `false` antes de validar (`prepareForValidation`), mismo patrón que
  `descuento_permanente` en 015.
- **No conviven en un solo campo con `descuento_permanente`**: un cliente puede ser distribuidor,
  tener descuento permanente, ambos o ninguno — son dos columnas independientes. Ver "Convivencia con
  el descuento permanente" en la sección de frontend para cómo interactúan al calcular una línea.

### `PrecioArticuloCalculator`

- **`utilidadDistribuidorEfectiva(Articulo $articulo, Catalogo $catalogo): float`**: espejo exacto de
  `utilidadEfectiva` (líneas 180-183), leyendo `utilidad_distribuidor_porcentaje` en vez de
  `utilidad_porcentaje`.
- **`calcularCadena()`** (líneas 193-210) gana un parámetro `float $utilidadDistribuidorPorcentaje` y
  el resultado gana la clave `precio_distribuidor_sin_iva`, calculada reutilizando las mismas
  funciones puras ya existentes (`precioVentaSinIva`, `redondearAPesoEntero`, `factorIva`) sobre
  `costo_con_descuento` **sin sumarle `costoGoma`**:

  ```php
  $precioDistribuidorCrudo = self::precioVentaSinIva($costo, $utilidadDistribuidorPorcentaje);
  $precioDistribuidor = self::redondearAPesoEntero($precioDistribuidorCrudo, self::factorIva($objetoImp));
  ```

  No se agrega ninguna función nueva de redondeo o de IVA: el precio distribuidor pasa por las mismas
  `techo2` y `redondearAPesoEntero` ya verificadas en 011 y 024, solo que partiendo de un costo base
  distinto (sin goma) y un porcentaje distinto.
- Todos los llamadores de `calcularCadena()` (`ArticuloController::store/update/importarCsv`,
  `Catalogo::booted()`, `CatalogoProveedorController::proyectar()`) pasan el nuevo parámetro y
  persisten `precio_distribuidor_sin_iva` junto a `precio_unitario_sin_iva`.

### Endpoints

Sin rutas nuevas. Cambios sobre las existentes:

- `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios`
  (`CatalogoProveedorController::impactoPrecios`) acepta `utilidad_distribuidor_porcentaje` opcional
  (cae al valor actual del catálogo si no se manda) y la vista previa por artículo
  (`proyectar()`, líneas 166-183) incluye `precio_distribuidor_sin_iva`.
- `POST /api/v1/catalogos-proveedor/{catalogo}/aumentar-costos`
  (`CatalogoProveedorController::aumentarCostos`) persiste también `precio_distribuidor_sin_iva` al
  recorrer los artículos, porque `proyectar()` ya lo trae calculado.
- `POST` / `PUT /api/v1/articulos[/{id}]` aceptan `utilidad_distribuidor_porcentaje` opcional; no
  aceptan `precio_distribuidor_sin_iva` (se ignora en silencio, mismo patrón que
  `precio_unitario_sin_iva` en 011).
- `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv` y
  `GET /api/v1/articulos/exportar-csv`: ver columnas CSV abajo.
- `GET /api/v1/articulos` gana `precio_distribuidor_sin_iva` como cuarta columna ordenable de
  `?sort=` (ver "Frontend", listado).
- `POST` / `PUT /api/v1/clientes[/{id}]` ([004](004-gestion-clientes.md)) aceptan `es_distribuidor`
  opcional.
- `GET /api/v1/clientes` y `GET /api/v1/clientes/{id}` devuelven `es_distribuidor` en
  `ClienteResource`, para que `ClienteCombobox` lo traiga en la misma respuesta con la que ya trae
  `descuento_permanente` (015).

### Columnas CSV

`utilidad_distribuidor_porcentaje` se agrega **al final**, después de `tamano_goma`, por la misma
razón por la que `tamano_goma` se agregó al final en 014: un CSV de 8 columnas de esta historia deja
de ser el de antes, pero uno de 7 columnas (sin esta) sigue siendo importable, porque la celda
faltante se trata igual que una celda vacía (hereda del catálogo):

```
nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,tamano_goma,utilidad_distribuidor_porcentaje
```

- Opcional, igual que `utilidad_porcentaje`: celda vacía = hereda la del catálogo destino; con valor
  se guarda como utilidad distribuidor propia del artículo.
- `precio_distribuidor_sin_iva` no viaja en el CSV en ninguna dirección, mismo criterio que
  `precio_unitario_sin_iva`.

### Validaciones

`utilidad_distribuidor_porcentaje` sigue exactamente las mismas reglas que `utilidad_porcentaje` en
cada contexto:

- En `Articulo` (`StoreArticuloRequest`, `UpdateArticuloRequest`, fila de CSV): `nullable`, numérico,
  `gte:0`, `lte:999.99`, máximo 2 decimales.
- En `Catalogo` (`StoreCatalogoRequest`, `UpdateCatalogoRequest`): igual que `utilidad_porcentaje` —
  obligatorio, con `prepareForValidation` normalizando `null`/`''` a `0`, mismas cotas.
- En `impacto-precios`: `nullable`, mismas cotas.
- `es_distribuidor` en `Cliente` (`StoreClienteRequest`, `UpdateClienteRequest`): opcional, `boolean`;
  ausente o vacío se normaliza a `false`.

### `ArticuloResource` y `CatalogoResource`

`ArticuloResource` (`app/Http/Resources/ArticuloResource.php`) agrega:
`utilidad_distribuidor_porcentaje`, `utilidad_distribuidor_porcentaje_efectivo` (vía
`utilidadDistribuidorEfectiva`), `precio_distribuidor_sin_iva`, `precio_distribuidor_con_iva`.

`CatalogoResource` agrega `utilidad_distribuidor_porcentaje`.

### Migración de esquema y de datos

Una sola migración, siguiendo el patrón de 011 (schema + backfill en el mismo archivo):

1. `catalogos` gana `utilidad_distribuidor_porcentaje` `decimal(5,2)` default `0`. Los catálogos
   existentes quedan en 0%, igual que arrancó `utilidad_porcentaje` en 011.
2. `articulos` gana `utilidad_distribuidor_porcentaje` `decimal(5,2)` nullable y
   `precio_distribuidor_sin_iva` `decimal(10,2)`.
3. Se recorre cada artículo existente y se recalcula `precio_distribuidor_sin_iva` con
   `PrecioArticuloCalculator::calcularCadena()`, con `utilidad_distribuidor_porcentaje` en `NULL`
   (hereda el 0% del catálogo, así que todos los artículos arrancan con precio distribuidor igual a
   su costo sin goma, sin markup, hasta que se capture la utilidad real por catálogo o por artículo).

**Migración separada** (tabla distinta, no forma parte de la anterior): `clientes` gana
`es_distribuidor` `boolean` `NOT NULL` `default false`. Todos los clientes existentes quedan
marcados como "no distribuidor"; no se recalcula ninguna cotización ni factura ya guardada.

### Fuente de verdad única de la fórmula

`shared/fixtures/precios-articulos.json` (011) gana `utilidad_distribuidor_porcentaje` como entrada y
`precio_distribuidor_sin_iva` como resultado esperado en cada caso existente y en los casos nuevos que
hagan falta para cubrir el redondeo del distribuidor por separado (un caso donde el directo cae en
peso entero exacto y el distribuidor no, y viceversa). PHPUnit y Vitest lo siguen leyendo del mismo
archivo.

## Frontend (Vue 3)

### Módulo de cálculo compartido

`src/lib/precioArticulo.ts` — `calcularCadena()` gana el parámetro `utilidadDistribuidorPorcentaje` y
el resultado gana `precio_distribuidor_sin_iva`, mismo cambio que en `PrecioArticuloCalculator`,
reutilizando `precioVentaSinIva`, `redondearAPesoEntero` y `factorIva` ya existentes. El fixture
compartido cubre ambas implementaciones.

### `/articulos` (listado)

- Nueva columna **"Precio distribuidor"**, después de "Precio de venta". `colspan` del renglón vacío
  pasa de 6 a 7.
- Se agrega a `columnasNumericas` (`ArticulosListView.vue:177-179`), por lo que hereda gratis la
  ordenación por encabezado y el filtro de rango de 025 (`ORDENACIONES['precio_distribuidor_sin_iva']`
  y `RANGOS['distribuidor']` nuevos en `ArticuloController`).

### `/articulos/crear` y `/articulos/:id/editar`

- Se captura **`utilidad_distribuidor_porcentaje`** (`Input` numérico, opcional), mismo patrón que
  `utilidad_porcentaje`: placeholder con la utilidad distribuidor heredada del catálogo cuando está
  vacío.
- El bloque de "Cadena de cálculo" se amplía con la rama distribuidor: costo sin goma → utilidad
  distribuidor → IVA (si aplica) → precio final distribuidor, con el mismo criterio de no mostrar
  renglones de $0.00 ni el de "Redondeo" cuando no aplica.
- El aviso de utilidad alta (032) también vigila la utilidad distribuidor efectiva.
- La comparación de discrepancia contra el valor guardado por el servidor (011) incluye
  `precio_distribuidor_sin_iva`.

### `/catalogos/crear` y `/catalogos/:id/editar`

- Campo **`utilidad_distribuidor_porcentaje`** (`Input` numérico, precargado en `0`), junto al de
  `utilidad_porcentaje`, con el mismo aviso no bloqueante de 032.
- El diálogo de confirmación de impacto antes de guardar (011) incluye el nuevo campo entre los que
  disparan la vista previa.
- La tabla de "Ver impacto" (`CatalogoFormView.vue:330-357`) gana la columna "Precio distribuidor".

### Ficha del artículo que se comparte al cliente

Dos pantallas muestran hoy la ficha del artículo al cliente y **nunca** el costo, el proveedor ni la
utilidad (`ArticuloDetalleDialog.vue` en escritorio, `MostradorArticuloView.vue` en el mostrador —
misma regla, documentada en ambos archivos desde 020 y 031). Las dos reciben el mismo tratamiento,
para no dejar una desincronizada de la otra:

- Se muestran **ambos precios con IVA** (`precio_unitario_con_iva` y `precio_distribuidor_con_iva`),
  cada uno con su etiqueta ("Precio" / "Precio distribuidor", con el mismo condicional de "con IVA"
  que ya existe según `objeto_imp`).
- El botón único "Compartir" se reemplaza por **dos botones**, uno por precio ("Compartir precio" /
  "Compartir precio distribuidor"), cada uno arma su propio texto compartible
  (`{{nombre}} — Modelo {{modelo}} — {{precio}}`) y usa el mismo mecanismo de compartir que ya tiene
  cada pantalla (el de `ArticuloDetalleDialog` con `navigator.share`/portapapeles directo, el de
  `MostradorArticuloView` vía `compartirArchivo`/`compartirTexto` de `lib/compartir.ts`).

### Cliente distribuidor y selección automática del precio (Cotizaciones y Facturación)

#### `/clientes/crear` y `/clientes/:id/editar` ([004](004-gestion-clientes.md))

- Casilla **"Es distribuidor"** (`Checkbox`/`Switch`), en la sección de datos comerciales, junto al
  campo de descuento permanente (015). Valor por defecto sin marcar.
- Texto de ayuda debajo, mismo criterio que 015:

  > Sus cotizaciones y facturas usarán el precio distribuidor de cada artículo en vez del precio de
  > lista.

#### `/clientes` (listado)

Nueva columna/insignia **"Distribuidor"**, junto a la de "Descuento" (015), mostrando un `Badge` (o
un guion cuando no aplica).

#### `ClienteCombobox`

`ClienteResultado` suma `es_distribuidor` (boolean), en el mismo objeto que ya trae
`descuento_permanente` (015) — no se agrega ninguna consulta nueva, el dato ya viaja en la respuesta
de búsqueda de `GET /clientes`.

#### `ArticuloBuscador`

`ArticuloResultado` suma `precio_distribuidor_sin_iva` (number), junto a `precio_unitario_sin_iva` y
`costo_con_descuento` que ya trae. El artículo ya viene completo en la respuesta de
`GET /articulos`; no se agrega ninguna consulta nueva.

#### `DocumentoLineas`

- Nueva prop opcional **`precioDistribuidor?: boolean`** (default `false`), paralela a
  `descuentoPorDefectoPorcentaje` (015). Con la prop en `false` —orden de compra y venta de
  mostrador, que no la pasan— el componente se comporta **exactamente como hoy**.
- `onArticuloSeleccionado` (`DocumentoLineas.vue:118-144`) elige el precio con el que nace la línea
  nueva según la prop, en vez de usar siempre `precio_unitario_sin_iva`:

  ```
  precio_unitario = props.precioDistribuidor
    ? articulo.precio_distribuidor_sin_iva
    : articulo.precio_unitario_sin_iva
  ```

  (Cuando `origenPrecio === 'costo'`, orden de compra, la prop no aplica: sigue precargando
  `costo_con_descuento` como hoy.)
- **`LineaEditable` gana dos campos internos, no persistidos**: `precio_directo_sin_iva` y
  `precio_distribuidor_sin_iva`, con los dos precios del artículo en el momento en que se agregó la
  línea (o se refrescaron, ver abajo). Sirven **solo** para poder reemplazar el precio de la línea
  sin volver a preguntarle al servidor cuando el usuario cambia de cliente; no viajan al backend
  (`FacturaPayload`/`CotizacionPayload` los ignoran, mismo criterio que cualquier otro campo de UI
  que no es parte del documento).
- **Reemplazo de precio al cambiar de cliente**: vive en la vista que contiene `DocumentoLineas`
  (`CotizacionFormView.vue`/`FacturaFormView.vue`), igual que hoy vive ahí el reemplazo del
  descuento (015) — no dentro del componente compartido. Al elegir un cliente nuevo, **todas** las
  líneas ya capturadas actualizan su `precio_unitario` al que corresponda (distribuidor o directo)
  según el cliente nuevo, usando los campos cacheados de cada línea. Es el mismo criterio agresivo
  que ya usa 015 para el descuento: se reemplaza aunque el usuario haya editado el precio a mano, y
  aunque el cliente nuevo deje la línea en su precio de lista.
- **Líneas sin los campos cacheados** (las que llegaron de un documento ya guardado al abrir
  `/cotizaciones/:id/editar` o `/facturas/:id/editar`, que no pasaron por el buscador en esta
  sesión): al momento de cambiar de cliente, la vista consulta `GET /api/v1/articulos/{articulo_id}`
  para cada línea que le falte el dato, en paralelo, antes de aplicar el reemplazo. Es una consulta
  puntual que solo ocurre si de verdad se cambia de cliente en un documento con líneas precargadas —
  no se dispara al simplemente abrir el formulario.

#### Convivencia con el descuento permanente (015)

Los dos mecanismos son independientes y se combinan sin caso especial: el precio base de la línea lo
decide `es_distribuidor` (directo o distribuidor); el descuento de línea, si el cliente lo tiene, se
sigue aplicando **encima** de ese precio base exactamente igual que hoy se aplica sobre el directo.
Un cliente distribuidor con 15% de descuento permanente cotiza sobre el precio distribuidor menos ese
15%, con el mismo motor de cálculo de siempre.

#### `/cotizaciones/crear` y `/cotizaciones/:id/editar`

- `onClienteSeleccionado` (`CotizacionFormView.vue:86-96`), que ya reemplaza el descuento de todas
  las líneas (015), se amplía para **también** reemplazar el precio unitario según `es_distribuidor`
  del cliente elegido, con el mecanismo de caché/consulta descrito arriba.
- `DocumentoLineas` recibe `:precio-distribuidor="esClienteDistribuidorActual"`, un nuevo `ref` que
  se actualiza junto a `descuentoClienteActual` en el mismo manejador.
- Al editar, `esClienteDistribuidorActual` arranca del cliente **vigente** de la cotización (no hay
  una copia congelada de este dato: a diferencia del descuento permanente, que se congela para poder
  explicar de dónde salió el porcentaje de cada línea ya capturada, el precio de un artículo no se
  "explica" con un renglón informativo — si el usuario quiere el precio de lista en una excepción, lo
  edita línea por línea, como ya puede hacer con cualquier precio).
- **Aviso** sobre la tabla de líneas: se amplía el `Alert` que ya existe para el descuento (015) con
  un segundo mensaje, visible cuando el cliente es distribuidor (pueden mostrarse ambos a la vez):

  > ⓘ **Ferretería López** es distribuidor: cada línea usa el precio distribuidor. Podés cambiarlo
  > línea por línea si esta cotización es una excepción.

#### `/facturas/crear` (con o sin `cotizacion_id`) y `/facturas/:id/editar`

- **Factura creada desde cero o en edición** (`ClienteCombobox` visible, es decir
  `!clienteFijoNombre`): gana un manejador `@seleccion` — hoy no tiene ninguno— que replica el de
  cotización: guarda si el cliente elegido es distribuidor y reemplaza el precio de las líneas ya
  capturadas. **A diferencia del descuento permanente (015), que nunca se aplica en una factura
  creada desde cero**, el precio distribuidor **sí** se aplica ahí: es la lectura literal de la
  historia ("en la cotización o facturación"), y no hay un documento intermedio que "explique" de
  dónde salió el precio como sí existe para el descuento.
- `DocumentoLineas` recibe `:precio-distribuidor="esClienteDistribuidorActual"` igual que en
  cotización.
- **Factura que viene de una cotización** (`clienteFijoNombre` presente, combobox oculto): **no**
  cambia. El precio que llega a cada línea es `precio_unitario_facturacion` (015), que ya es el
  precio con el que se cotizó (distribuidor o directo, según lo que aplicaba en ese momento) neto de
  cualquier descuento de línea; no se vuelve a decidir aquí qué precio de artículo usar.
- **Aviso**: nuevo `Alert` (independiente del de "los precios ya incluyen el descuento de..." que
  solo aplica cuando viene de una cotización), visible cuando el cliente elegido es distribuidor:

  > ⓘ **Ferretería López** es distribuidor: cada línea usa el precio distribuidor.

### Tests

- El fixture compartido cubre la cadena distribuidor en ambas suites (PHPUnit, Vitest), igual que 011.
- `ArticuloFactory` recibe (opcionalmente) `utilidad_distribuidor_porcentaje` y deriva
  `precio_distribuidor_sin_iva` junto con el resto de la cadena.
- Cobertura de `Catalogo::booted()`: cambiar `utilidad_distribuidor_porcentaje` del catálogo mueve
  solo a los artículos que la heredan; cambiar `descuento` mueve ambos precios de todos los artículos.
- Cobertura del CSV: fila con la nueva columna vacía hereda, con valor guarda propio; exportar e
  reimportar no pierde el dato.
- Cobertura de la ordenación de `GET /api/v1/articulos` por la nueva columna, en ambas direcciones.
- **`Cliente`**: guardar `es_distribuidor` en `true`/`false`; el campo ausente deja `false`; un
  cliente marcado por un usuario no es visible ni modificable por otro.
- **`DocumentoLineas`**: con la prop `precioDistribuidor` en `true`, una línea nueva nace con
  `precio_distribuidor_sin_iva` del artículo; en `false` (o ausente), nace con
  `precio_unitario_sin_iva`, igual que antes de esta historia.
- **Cotización**: elegir un cliente distribuidor con líneas ya capturadas reemplaza el precio de
  todas; volver a elegir un cliente no distribuidor las regresa al precio directo; el descuento
  permanente del cliente se sigue aplicando sobre el precio que corresponda.
- **Factura sin `cotizacion_id`**: elegir un cliente distribuidor aplica el precio distribuidor a las
  líneas, a diferencia del descuento permanente (015), que ahí no aplica.
- **Factura con `cotizacion_id`**: el precio que llega a cada línea es el que ya traía la cotización
  (`precio_unitario_facturacion`), sin importar si el cliente es distribuidor o no; cambiar el
  estatus de distribuidor del cliente después de cotizar no mueve la factura.
- Cobertura del reemplazo de precio en una línea cargada de un documento existente (sin los campos
  cacheados), verificando que se consulta el artículo antes de aplicar el precio nuevo.

## Fuera de alcance

- **Venta de mostrador** ([027](027-venta-mostrador-ticket.md), [029](029-pwa-mostrador.md),
  [031](031-mostrador-consulta.md)): esas pantallas no tienen un `Cliente` con FK (el cliente de
  mostrador es texto libre, sin RFC, ver 027), así que no hay a quién consultarle
  `es_distribuidor`. Siguen mostrando y cobrando siempre el precio directo, sin cambios.
- **Órdenes de compra** ([012](012-ordenes-compra.md)): ese precio es lo que se le paga al
  proveedor, no el de venta; `es_distribuidor` no lo toca.
- **Una utilidad o un precio distribuidor distinto por cliente individual**: el precio distribuidor
  sigue siendo el mismo para todos los clientes distribuidores, calculado a nivel catálogo/artículo
  (esta misma historia); `Cliente` solo guarda si lo usa o no, no un porcentaje propio.
- **Más de dos tipos de cliente** o niveles de distribuidor: `es_distribuidor` es booleano, sin
  escalas intermedias.
- **Reportes de rentabilidad por tipo de cliente.**
- **Historial de cambios** de `es_distribuidor`, de la utilidad distribuidor o del precio
  distribuidor: cambiar la marca es inmediato y sin registro de cuándo cambió.
- **Recálculo de cotizaciones o facturas ya guardadas** al marcar o desmarcar un cliente como
  distribuidor: solo afecta a las líneas que se agreguen o reemplacen de ahí en adelante.
- Cambiar la regla de redondeo al peso entero (024) o la regla de IVA por `objeto_imp`: el precio
  distribuidor las hereda tal cual, no se revisan ni se hacen opcionales.
- Inventario/existencias valuadas al precio o utilidad distribuidor: `dineroInvertido` y
  `beneficioPotencial` (017) siguen midiéndose contra el precio directo.

## Estado de implementación

Cálculo y visualización del precio distribuidor (Artículos/Catálogos) implementados previamente.
Cliente distribuidor y selección automática del precio implementados el 2026-08-25.

- **`CotizacionResource` y `FacturaResource` ganaron `cliente_es_distribuidor`** (vigente, junto a
  `cliente_razon_social` que ya exponían): no estaba detallado como cambio de backend al escribir
  esta spec, pero es necesario para que `/cotizaciones/:id/editar` y `/facturas/:id/editar` sepan
  con qué estatus de cliente arrancar `esClienteDistribuidorActual` sin una consulta aparte.
- **La casilla "Es distribuidor" se implementó como `<input type="checkbox">` plano**, no con un
  componente `Checkbox`/`Switch` del design system: ese componente no existe en el registro
  shadcn-vue del proyecto (ver 003), y `ClienteFormView.vue` ya tenía el mismo patrón para "Revisé
  estos datos y son correctos" (016). Agregar un componente nuevo por un solo campo no se justificaba.
- **`aplicarPrecioCliente()`** (`frontend/src/lib/precioClienteLinea.ts`) se extrajo como función
  compartida entre `CotizacionFormView.vue` y `FacturaFormView.vue`, en vez de duplicar el
  mecanismo de caché/consulta en cada vista: es la única pieza de esta historia con lógica no
  trivial (decidir si hace falta consultar el artículo), y las dos vistas la necesitan igual.
- **Cobertura de backend**: `ClienteDistribuidorTest.php` (nuevo) cubre solo la ficha del cliente
  (guardar `true`/`false`, ausente/blanco, aislamiento por usuario) — la selección automática del
  precio en Cotizaciones/Facturación es un mecanismo enteramente del frontend, sin lógica de negocio
  nueva en el backend más allá de exponer `es_distribuidor`.
- **Verificado**: 624 tests de Pest en verde (backend completo), 95 de Vitest, `npm run build`
  (type-check), ESLint y Prettier limpios.
- **Pendiente**: verificación visual en un navegador real (misma limitación de entorno que el resto
  de las historias de este proyecto). Falta confirmar a ojo: la casilla y el aviso de ayuda en la
  ficha del cliente, la insignia "Distribuidor" en `/clientes`, el precargado del precio distribuidor
  al agregar un artículo en cotización y factura, el reemplazo de precios de líneas ya capturadas al
  cambiar de cliente (incluida una línea cargada de un documento existente, que dispara la consulta
  al artículo), y los dos avisos nuevos.

## Criterios de aceptación

1. Un artículo con costo con descuento $200.00, costo de goma $20.00, utilidad directo 50%, utilidad
   distribuidor 25% y `objeto_imp` "02" produce `precio_unitario_sin_iva = $330.17` (con IVA
   $383.00) y `precio_distribuidor_sin_iva = $250.00` (con IVA $290.00).
2. El mismo artículo con utilidad distribuidor 30% en vez de 25% produce
   `precio_distribuidor_sin_iva = $261.21` (con IVA $303.00), no $301.60: el redondeo al peso entero
   de 024 se aplica al distribuidor exactamente igual que al directo.
3. Un artículo sin utilidad distribuidor propia hereda la del catálogo; el formulario muestra el
   valor heredado como referencia mientras el campo está vacío.
4. Cambiar el descuento de un catálogo recalcula **ambos** precios de todos sus artículos, incluidos
   los que tienen utilidad propia de cualquiera de los dos tipos.
5. Cambiar la utilidad distribuidor de un catálogo recalcula el precio distribuidor **solo** de los
   artículos que la heredan; los que tienen utilidad distribuidor propia conservan su precio
   distribuidor. Cambiar la utilidad directo del catálogo no toca el precio distribuidor de ningún
   artículo, y viceversa.
6. Capturar una utilidad distribuidor negativa o mayor a 999.99 muestra un error de validación y no
   permite guardar; 0 y valores de tres dígitos dentro del rango sí se aceptan.
7. El listado `/articulos` muestra "Precio distribuidor" como columna adicional, ordenable
   ascendente y descendentemente, y filtrable por rango igual que las demás columnas de dinero.
8. El formulario de artículo muestra en vivo la cadena de cálculo del distribuidor (costo sin goma →
   utilidad distribuidor → IVA si aplica → precio final distribuidor), junto a la del directo.
9. El formulario de catálogo captura la utilidad distribuidor con el mismo aviso no bloqueante por
   encima de 400% que ya tiene la utilidad directo, y la vista previa de impacto muestra el precio
   distribuidor resultante.
10. Importar un CSV con la columna `utilidad_distribuidor_porcentaje` vacía hereda la del catálogo
    destino; con valor la guarda como propia del artículo. Un CSV de 7 columnas (sin esa columna)
    sigue siendo importable.
11. Exportar el listado genera un CSV con las 8 columnas, y ese archivo es reimportable sin pérdida.
12. La ficha del artículo que se comparte al cliente (escritorio y mostrador) muestra los dos precios
    con IVA, cada uno con su etiqueta, y ofrece un botón de compartir independiente por cada uno; el
    texto o archivo compartido por cada botón trae únicamente el precio que le corresponde.
13. Venta de mostrador no cambia de comportamiento: sigue precargando siempre `precio_unitario_sin_iva`
    (el precio directo), porque no tiene un `Cliente` con RFC al que consultarle `es_distribuidor`.
14. Tras la migración, todos los artículos existentes tienen `precio_distribuidor_sin_iva` calculado
    (a partir de una utilidad distribuidor heredada de 0%) y ninguno queda con la columna vacía.
15. Pint, ESLint/Prettier y las suites de PHPUnit y Vitest corren sin errores sobre el código nuevo.
16. Un usuario puede marcar o desmarcar "Es distribuidor" en la ficha de un cliente; los clientes que
    ya existían quedan sin marcar y se comportan exactamente como antes de esta historia.
17. El listado `/clientes` muestra qué clientes son distribuidores.
18. En una cotización, elegir un cliente distribuidor precarga cada línea de artículo nueva con el
    precio distribuidor, en vez del directo; el precio sigue siendo editable línea por línea.
19. En una factura creada **desde cero** (sin `cotizacion_id`), elegir un cliente distribuidor tiene el
    mismo efecto que en una cotización: las líneas se precargan con el precio distribuidor.
20. Cambiar el cliente de una cotización o factura que ya tiene líneas capturadas reemplaza el precio
    unitario de **todas** esas líneas por el que corresponda al cliente nuevo (distribuidor o
    directo), incluidas las líneas que el usuario ya había editado a mano.
21. Un cliente distribuidor con descuento permanente (015) cotiza cada línea sobre el precio
    distribuidor menos su descuento, con el mismo motor de cálculo que ya existe.
22. Facturar una cotización (`/facturas/crear?cotizacion_id=...`) usa el precio que ya quedó grabado en
    cada línea de la cotización (`precio_unitario_facturacion`), sin volver a decidir ahí si el cliente
    es distribuidor.
23. La pantalla de captura de cotización y la de factura muestran un aviso cuando el cliente elegido es
    distribuidor, y no lo muestran cuando no lo es.
24. Marcar o desmarcar a un cliente como distribuidor no modifica ninguna cotización ni factura ya
    guardada.

## Supuestos asumidos (registro completo)

1. El "costo neto" de la historia es `costo_con_descuento`: el costo del artículo ya con el
   descuento del proveedor aplicado, **sin** la goma. No se modifica la importación de precios de
   proveedor ([011](011-precio-proveedor-utilidad.md)): el precio distribuidor se calcula a partir
   del costo que esa cadena ya produce.
2. La utilidad "directo" de la historia **es** `utilidad_porcentaje`, el campo que ya existe hoy en
   `Catalogo` y `Articulo` (011); no se crea ni se renombra nada para representarla.
3. El precio que el sistema ya calcula y muestra hoy (`precio_unitario_sin_iva`, con goma, con
   redondeo al peso entero de 024 y con IVA condicionado por `objeto_imp`) se conserva exactamente
   igual y pasa a entenderse como "precio directo"; no hay un cálculo paralelo para él.
4. El precio distribuidor se redondea al peso entero con la misma función `redondearAPesoEntero`
   (024) que el directo, aunque eso lo aleje del centavo exacto de una fórmula sin redondear (ver
   criterio de aceptación 2). Es una consecuencia directa de reutilizar la regla existente, no una
   excepción para el distribuidor.
5. El precio distribuidor lleva IVA únicamente en los artículos cuyo `objeto_imp` ya lo causa hoy en
   el precio directo (024); no hay una tasa fija de 16% aplicada a todos los artículos sin
   excepción.
6. **(Redefinido)** Esta historia cubre el cálculo y la visualización del precio distribuidor en
   Artículos y Catálogos **y** marcar clientes como distribuidores para que ese precio se use
   automáticamente al capturar líneas en Cotizaciones y en Facturación, incluida una factura creada
   desde cero. Venta de mostrador y Órdenes de compra quedan fuera (ver "Fuera de alcance").
7. La utilidad distribuidor se configura con la misma estructura de dos niveles que la utilidad
   directo: un valor por catálogo (`utilidad_distribuidor_porcentaje`, default 0) y un valor opcional
   por artículo que la sobrescribe, con herencia viva idéntica a 011.
8. La utilidad distribuidor se edita en el mismo formulario donde hoy se edita la utilidad directo
   (catálogo y artículo), como un campo hermano; no se agrega una pantalla nueva ni se mete en
   Configuración (que hoy solo maneja costo de goma y mensajes de ticket, ver 014).
9. `precio_distribuidor_sin_iva` se persiste en columna, igual que `precio_unitario_sin_iva`, y se
   recalcula en cascada en los mismos puntos donde hoy se recalcula el directo (alta, edición, CSV,
   aumento de costos, y los dos recálculos en bloque de `Catalogo::booted()`), en vez de calcularse al
   vuelo en cada lectura.
10. No se modifica la importación de listas de precios del proveedor: el precio distribuidor se
    calcula después, en el mismo paso donde hoy se calcula el precio directo, a partir del costo que
    esa importación ya produce.
11. La ficha de artículo que se comparte al cliente (`ArticuloDetalleDialog.vue`, el modal de
    escritorio confirmado explícitamente) y su equivalente de pantalla completa en el mostrador
    (`MostradorArticuloView.vue`, que documenta desde 031 seguir "la misma regla que la ficha del
    escritorio") reciben el mismo tratamiento: ambas ganan el segundo precio y el segundo botón de
    compartir, para no dejar una pantalla mostrando un solo precio mientras la otra muestra dos.
12. El texto o archivo que sale de cada botón de compartir lleva **un solo** precio (el que
    corresponde a ese botón), no los dos juntos: quien comparte elige antes de enviar cuál de los dos
    precios le corresponde a la conversación.
13. **(Adición técnica)** `PrecioArticuloCalculator::calcularCadena()` (backend) y `calcularCadena()`
    de `precioArticulo.ts` (frontend) se amplían en el mismo lugar en vez de crear una segunda función
    paralela: reciben la utilidad distribuidor como parámetro adicional y devuelven
    `precio_distribuidor_sin_iva` calculado con las mismas primitivas de redondeo y de IVA ya
    existentes (`techo2`, `redondearAPesoEntero`, `factorIva`), aplicadas sobre `costo_con_descuento`
    sin sumarle `costo_goma`.
14. **(Adición técnica)** `utilidadDistribuidorEfectiva()` se agrega como función espejo de
    `utilidadEfectiva()` en vez de generalizar ambas en una sola función parametrizada por tipo: son
    dos líneas de código cada una y una abstracción compartida no ahorraría nada.
15. **(Adición técnica)** La columna CSV nueva va al final (después de `tamano_goma`), replicando el
    criterio ya usado en 014 al agregar la columna de goma: un archivo exportado antes de esta
    historia sigue siendo importable sin cambios.
16. **(Adición técnica)** El recálculo en bloque de `Catalogo::booted()` para
    `utilidad_distribuidor_porcentaje` es un bloque `if` nuevo, paralelo al de `utilidad_porcentaje`
    (011), no una fusión de ambos: los dos disparadores son independientes (cambiar uno no debe mover
    los artículos que solo heredan el otro).
17. **(Adición técnica)** La migración agrega columnas y hace el backfill en el mismo archivo,
    siguiendo el patrón de la migración de 011: todos los artículos existentes recalculan su
    `precio_distribuidor_sin_iva` con `utilidad_distribuidor_porcentaje` en `NULL` (heredan el 0% con
    el que arrancan los catálogos existentes).
18. **(Adición técnica)** El fixture compartido `shared/fixtures/precios-articulos.json` (011) se
    amplía con las columnas del distribuidor en vez de crear un segundo archivo, para que un cambio
    en la fórmula común (`techo2`, `redondearAPesoEntero`) siga verificándose contra los mismos casos
    para los dos precios.
19. "Ser distribuidor" es un dato **booleano** de la ficha del cliente (`es_distribuidor`); no hay
    niveles intermedios ni una utilidad distribuidor propia por cliente — esa utilidad sigue viviendo
    en `Catálogo`/`Articulo` (supuesto 7).
20. Aplica en **Cotizaciones y en Facturación**, incluida una factura creada **desde cero** — a
    diferencia del descuento permanente (015), que ahí no aplica. No aplica en Venta de mostrador ni
    en Órdenes de compra (ver "Fuera de alcance").
21. El efecto es sobre el **precio con el que nace cada línea nueva** al elegir un artículo: cliente
    distribuidor precarga `precio_distribuidor_sin_iva`; cliente no distribuidor precarga
    `precio_unitario_sin_iva`, exactamente como hoy. El precio precargado sigue siendo editable línea
    por línea.
22. **Cambiar de cliente** en una cotización o factura con líneas ya capturadas **reemplaza** el
    precio unitario de todas ellas por el que corresponda al cliente nuevo, incluidas las líneas que
    el usuario ya había editado a mano — mismo criterio agresivo que 015 usa para el descuento.
23. El descuento permanente (015) y el precio distribuidor son mecanismos **independientes que
    conviven**: el descuento se sigue aplicando encima del precio base que corresponda (directo o
    distribuidor).
24. Al facturar desde una cotización, el precio que viaja a cada línea es el que ya quedó grabado en
    la cotización (`precio_unitario_facturacion`, 015); no se vuelve a consultar si el cliente es
    distribuidor en ese paso.
25. Los clientes existentes quedan marcados como "no distribuidor" al activarse la funcionalidad; no
    hay recálculo de cotizaciones ni facturas ya guardadas.
26. Sin historial de cambios de `es_distribuidor`: activarlo o desactivarlo es inmediato.
27. **(Adición técnica)** `LineaEditable` (`DocumentoLineas.vue`) gana dos campos internos no
    persistidos —`precio_directo_sin_iva` y `precio_distribuidor_sin_iva`— para poder reemplazar el
    precio de una línea sin volver a consultar el artículo cuando ambos ya se conocen (líneas
    agregadas en la sesión actual desde el buscador). Ninguno de los dos viaja al backend.
28. **(Adición técnica)** Para una línea que llegó de un documento ya guardado (edición) y no tiene
    esos dos campos cacheados, el reemplazo de precio al cambiar de cliente **consulta**
    `GET /api/v1/articulos/{articulo_id}` en ese momento, no al abrir el formulario — evita N
    consultas innecesarias en documentos que nunca cambian de cliente.
29. **(Adición técnica)** El reemplazo de precio al cambiar de cliente vive en la vista que contiene
    `DocumentoLineas` (`CotizacionFormView.vue`/`FacturaFormView.vue`), igual que ya vive ahí el
    reemplazo de descuento (015), y no dentro del componente compartido: ese componente también lo
    usa Orden de compra, donde ninguno de los dos mecanismos aplica.
30. **(Adición técnica)** `FacturaFormView.vue` gana un manejador `@seleccion` en `ClienteCombobox`
    que hoy no tiene, paralelo al que ya existe en `CotizacionFormView.vue` desde 015 — necesario
    porque, a diferencia del descuento, el precio distribuidor sí debe aplicarse en una factura
    creada desde cero.
