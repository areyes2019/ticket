# Spec: Precio fijo de un artículo (capturado a mano, sin porcentaje)

> **Estado: implementada** el 2026-10-12 (ver "Estado de implementación" al final). Las 14 asunciones funcionales y las tres adiciones técnicas
> las aprobó el usuario el 2026-10-12, junto con las cinco decisiones tomadas al redactar.

**Modifica:** [009-precio-proveedor-utilidad.md](009-precio-proveedor-utilidad.md) (el precio de
venta deja de ser siempre calculado), [025-precios-sin-centavos.md](025-precios-sin-centavos.md) (el
precio fijo no se lleva al peso entero), [007-gestion-articulos.md](007-gestion-articulos.md)
(listado, formulario y CSV), [008-catalogos.md](008-catalogos.md) (confirmación del recálculo) y
[035-clonar-articulo.md](035-clonar-articulo.md) (el clon copia el precio fijo).

## Historia de usuario

Como usuario tengo la opción de poner un porcentaje de utilidad al artículo, pero a veces este no se
ajusta al precio que necesito. Quiero una opción para capturar los precios de manera literal, sin
recurrir al porcentaje: por ejemplo, escribir 330.00 con el teclado.

## Objetivo / Alcance

Cada artículo puede tener su precio de venta de dos maneras: **por utilidad (%)**, como hoy, o con un
**precio fijo** que el usuario escribe tal como lo paga el cliente. Con precio fijo, el sistema sigue
calculando el costo, pero ya no el precio. Lo que ahora se calcula es la utilidad que deja ese
precio.

- Dos columnas nuevas en `articulos` y ninguna tabla nueva.
- La fórmula nueva vive en `CalculadoraPrecioArticulo` y en su espejo `public/js/precio-articulo.js`.
  Las dos recorren el mismo fixture, como en 009.
- Los demás módulos (cotizaciones, facturas, pedidos de mostrador, consulta de mostrador, descuento
  permanente del cliente, órdenes de trabajo) **no cambian**. Siguen leyendo
  `precio_unitario_sin_iva`, que ahora puede salir de un precio fijo.

**Fuera de alcance:** precio fijo para el **precio distribuidor** (sigue siempre por porcentaje),
precio fijo a nivel de **catálogo**, cambiar el precio fijo de varios artículos a la vez, historial
de precios fijos anteriores, y re-precio de documentos ya emitidos.

## Reglas de negocio

### Los dos modos

1. El formulario del artículo tiene un selector de modo para el precio de venta: **"Por utilidad
   (%)"** o **"Precio fijo"**. Solo uno está activo a la vez. Por defecto, en altas y en todos los
   artículos que ya existen, está activo "Por utilidad (%)".
2. El modo no se guarda aparte: un artículo está en **precio fijo** si y solo si `precio_fijo` no es
   `null`.
3. Solo lo tiene el **artículo**. El catálogo no ofrece precio fijo.
4. Solo aplica al **precio directo**. El precio distribuidor (028) se sigue calculando con su
   utilidad, en los dos modos.

### Qué significa el número que se escribe

5. El precio fijo es el **precio que lee el cliente**: con IVA si el artículo es objeto de impuesto
   `02`, y a secas con `01`, `03` y `04` (mismo criterio que `precio_unitario_con_iva`). Se acepta con
   centavos y **no se lleva al peso entero**: la regla de 025 no se aplica a precios fijos.
6. **(Decisión tomada al redactar, aprobada)** El sistema guarda el precio **sin IVA**, en
   centavos. Con IVA de 16%, un centavo sin IVA equivale a 1.16 centavos con IVA, y hay precios con
   IVA que ninguna cantidad sin IVA alcanza exacto: es el mismo problema de 025, pero en centavos. En
   ese caso se toma el **siguiente centavo alcanzable**, nunca uno menor, y el formulario lo avisa.
   - $330.00 → sin IVA $284.48 → con IVA $330.00 (exacto).
   - $329.50 → sin IVA $284.05 → con IVA $329.50 (exacto).
   - $7.00 → sin IVA $6.04 → con IVA **$7.01**. Aviso: *"Con IVA de 16%, $7.00 no se puede alcanzar
     exacto; el precio quedará en $7.01."*
   - Sin IVA (`01`, `03`, `04`), el precio fijo se guarda tal cual.

   Lo que se guarda en `precio_fijo` es **lo que escribió el usuario**, aunque el precio que se
   cobre quede un centavo arriba. Así, si después cambia el objeto de impuesto, el precio se vuelve a
   calcular desde el número original.
7. Rango: de **$0.01 a $10,000,000.00** y con máximo dos decimales.

### Qué mueve y qué no mueve un precio fijo

8. Con precio fijo, **el precio de venta no cambia** al cambiar el precio de lista del proveedor, el
   descuento del catálogo, la utilidad del catálogo ni la utilidad propia. El **costo** sí se
   recalcula como siempre, y con él la utilidad en pesos y su porcentaje.
9. Cambiar el **objeto de impuesto** de un artículo con precio fijo conserva el precio que lee el
   cliente y recalcula el precio sin IVA (regla 6).
10. La **utilidad propia** (`utilidad_porcentaje`) se conserva aunque no se use. Con precio fijo, el
    campo se oculta, pero su valor sigue guardado.
11. Al volver a **"Por utilidad (%)"**, `precio_fijo` pasa a `null` y el precio se recalcula como
    siempre: con la utilidad propia si la hay y, si no, con la del catálogo, más el peso entero de
    025. El precio fijo anterior no se recuerda.

### La utilidad que deja el precio fijo

12. El formulario muestra **en vivo**, mientras se escribe, la utilidad en pesos por pieza (sin IVA:
    `precio_unitario_sin_iva − costo_con_descuento`, como en 009) y el **porcentaje equivalente**:
    `redondeo2((precio sin IVA ÷ costo − 1) × 100)`. Ejemplo: costo $180.00 y precio fijo $330.00
    con IVA → $284.48 sin IVA → utilidad $104.48, equivalente a **58.04%**.
13. Si el costo es $0.00 (descuento de catálogo de 100%), el porcentaje se muestra como "—".
14. El porcentaje equivalente puede pasar de 9,999.99% o ser negativo: es solo informativo y no se
    valida.

### Precio fijo por debajo del costo

15. Se permite guardar un precio fijo **menor que el costo**. Mientras se escribe, el formulario
    muestra un aviso no bloqueante: *"Este precio queda $X por debajo del costo."* El aviso compara
    el precio sin IVA con el costo.
16. **(Adición técnica 1)** En el **listado de artículos**, la celda "Precio" de los artículos con
    precio fijo cuyo precio sin IVA quedó **por debajo del costo** se pinta en **rojo**, con el título
    *"Por debajo del costo"*. Solo se marcan los de precio fijo, y no se usa amarillo ni otro nivel.
    Un artículo por utilidad nunca queda por debajo del costo, porque la utilidad mínima es 0%.

### Distintivo y fecha

17. En el listado, la celda "Precio" de todo artículo con precio fijo lleva una etiqueta pequeña
    **"Fijo"**. El orden por precio no cambia: sigue siendo por `precio_unitario_sin_iva`.
18. **(Adición técnica 2)** Se guarda la fecha en que se puso el precio fijo
    (`precio_fijo_desde`) y se muestra **solo dentro del artículo**, bajo el campo:
    *"Precio fijo desde el 12/10/2026"*. Se actualiza **cada vez que cambia** el precio fijo; guardar
    el artículo sin cambiar el precio no la mueve. Al volver a "Por utilidad (%)" la fecha pasa a
    `null`. La ficha (`<dialog>`) y el listado no la muestran.

### Recálculo de un catálogo (adición técnica 3)

19. Cuando cambia el descuento o la utilidad directo de un catálogo, el paso de confirmación de 008
    agrega cuántos artículos con precio fijo **conservarán su precio**:
    *"Se recalculará el precio de venta o el precio distribuidor de **N** artículos, y **M**
    artículos con precio fijo conservarán su precio."*
    - M cuenta los artículos con precio fijo (no eliminados) dentro de los que alcanza el cambio:
      todos los del catálogo si cambia el descuento, y los que heredan la utilidad directo si cambia
      esa. Un cambio que solo toca la utilidad distribuidor no muestra M.
    - Si M es 0, la frase de M no aparece.
20. El paso de confirmación sigue apareciendo **solo cuando N > 0**, igual que hoy. Si el cambio
    solo alcanza artículos con precio fijo (N = 0 y M > 0), no hay confirmación, pero el mensaje de
    éxito lo dice: *"Catálogo actualizado. 3 artículos con precio fijo conservaron su precio."* Si
    hubo confirmación, el mensaje de éxito también suma la frase de M.
21. Un artículo con precio fijo cuenta en N **solo si cambia su precio distribuidor**: su precio
    directo nunca cambia por el catálogo.

### Clonar, CSV y documentos

22. **Clonar** (035) copia `precio_fijo` como cualquier campo asignable. La fecha del clon es la del
    día en que se clona, porque para el clon el precio es nuevo (regla 18).
23. **CSV**: columna opcional nueva **`precio_fijo`**, al final de `COLUMNAS_CSV`. Vacía o ausente =
    por utilidad. Llena = precio fijo, con la misma validación de la regla 7. La exportación la llena
    con el número que escribió el usuario, o la deja vacía.
24. **Documentos**: cotizaciones, facturas, pedidos y órdenes ya guardados conservan sus precios. Las
    líneas nuevas toman `precio_unitario_sin_iva` como siempre, venga de un precio fijo o de un
    porcentaje. El descuento permanente del cliente (023) se aplica encima, igual que hoy.

## Backend (Laravel)

### Migración `2026_10_18_100000_precio_fijo_articulos`

- `articulos.precio_fijo` `decimal(10,2)` nullable, después de `utilidad_porcentaje`. Es el número
  que escribió el usuario (con IVA si es `02`).
- `articulos.precio_fijo_desde` `date` nullable.
- Solo agrega columnas: todo artículo existente queda en `null`, o sea, por utilidad, y ningún
  precio cambia. `down()` las borra.

### `CalculadoraPrecioArticulo`

- **`precioFijoSinIva(float|string $precioFijo, float $factorIva): float`**: el centavo sin IVA más
  chico cuyo `redondeo2(candidato × factor)` es **mayor o igual** al precio fijo (regla 6). Con
  factor 1, devuelve el precio fijo. Mismo cuidado de punto flotante que `redondearAPesoEntero`:
  escala a centavos, `round(…, 6)`, prueba el centavo de abajo y el de arriba.
- **`utilidadPorcentajeEquivalente(float|string $precioSinIva, float|string $costo): ?float`**:
  `redondeo2(($precioSinIva / $costo − 1) × 100)`, o `null` si el costo es 0 (reglas 12 y 13).

### `Articulo`

- `precio_fijo` entra a `#[Fillable]`. `precio_fijo_desde` no: la escribe solo el modelo.
- Casts: `precio_fijo` → `decimal:2`, `precio_fijo_desde` → `date`.
- **`booted()` / `saving`**:
  - Antes del `return` temprano: si `isDirty('precio_fijo')`, escribe
    `precio_fijo_desde = precio_fijo === null ? null : today()` (regla 18). Un cambio de
    `329.5` a `329.50` no cuenta como cambio gracias al cast.
  - `precio_fijo` se suma a la lista de `isDirty([...])` que dispara el recálculo.
- **`calcularPrecio()`**: el segundo elemento pasa a ser
  `precio_fijo !== null ? precioFijoSinIva(precio_fijo, factorIva(objeto_imp)) : precioVentaFinal(...)`.
  Costo y precio distribuidor no cambian. Como `articulosAfectados()` y el recálculo en bloque de
  `Catalogo` ya pasan por `calcularPrecio()`, el precio fijo queda protegido sin tocar esas consultas
  (reglas 8 y 21).
- Accesores nuevos: `tiene_precio_fijo` (bool), `por_debajo_del_costo` (bool: `tiene_precio_fijo` y
  `precio_unitario_sin_iva < costo_con_descuento`) y `utilidad_porcentaje_equivalente` (de la
  calculadora).
- `COLUMNAS_CSV` y `COLUMNAS_CSV_OPCIONALES` ganan `precio_fijo`.

### `Catalogo`

- **`articulosConPrecioFijoAlcanzados(bool $cambiaDescuento, bool $cambiaUtilidad): int`**: cuenta
  los artículos con `precio_fijo` no nulo (sin eliminados) dentro de `articulosPorRecalcular(...)`,
  con la utilidad distribuidor en `false` (regla 19). Si ni el descuento ni la utilidad directo
  cambian, devuelve 0.

### `CatalogoController::update`

- Calcula M con los mismos "¿cambió?" que `articulosAfectados()`.
- En la confirmación, pasa `confirmar_recalculo` (N) y `conservan_precio_fijo` (M).
- En el mensaje de éxito agrega *" N artículos con precio fijo conservaron su precio."* cuando M > 0,
  haya habido confirmación o no (regla 20). Con M = 1: *"1 artículo con precio fijo conservó su
  precio."*

### `ArticuloRequest`

- Campo nuevo **`modo_precio`**: `nullable`, `in:utilidad,fijo`. No se guarda. Ausente vale
  "utilidad", para que un envío sin el radio (como los de antes de 037) siga funcionando.
- **`precio_fijo`**: `required_if:modo_precio,fijo`, `nullable`, `numeric`, `min:0.01`,
  `max:10000000`, `decimal:0,2`. Mensajes: *"Escribe el precio fijo."* y *"El precio fijo debe estar
  entre $0.01 y $10,000,000.00."*
- En `prepareForValidation()`: si `modo_precio` no es `fijo`, `precio_fijo` se fuerza a `null`
  aunque venga lleno, antes de validar (regla 11). Así un valor basura en el campo oculto no
  produce error.
- `utilidad_porcentaje` conserva su validación de 036 en los dos modos (regla 10).

### `ImportadorArticulosCsv` y `ExportacionArticulosController`

- La importación valida `precio_fijo` con la regla 7. Una celda vacía se normaliza a `null`, igual
  que las utilidades.
- La exportación escribe `precio_fijo` con dos decimales, o vacío.

### `ArticuloController`

- `create` / `edit`: pasan a la vista el modo actual (`fijo` si `precio_fijo` no es nulo) y, en la
  edición, `precio_fijo_desde` formateada `d/m/Y`.
- El resumen de la cadena (`resumenPrecio()`) gana los renglones del modo fijo (ver Vistas).

## Vistas (Blade)

### `articulos/_formulario.blade.php`

- Antes del campo de utilidad, un grupo de dos radios **`modo_precio`**: "Por utilidad (%)" y
  "Precio fijo". Con `old()` respeta lo enviado si la validación falla.
- Campo **`precio_fijo`** (`tipo="number"`, `step="0.01"`, `min="0.01"`, `max="10000000"`,
  `inputmode="decimal"`). La etiqueta cambia con el objeto de impuesto: *"Precio fijo con IVA"*
  (`02`) o *"Precio fijo"*. Ayuda: *"El precio que paga el cliente."* Debajo, si hay fecha:
  *"Precio fijo desde el 12/10/2026"*.
- Con un modo activo, los campos del otro se ocultan con `hidden`, pero **se envían** (regla 10).
  Sin JavaScript, los dos campos se ven y manda el radio.
- Dos avisos no bloqueantes bajo `precio_fijo`, con el mismo estilo de `aviso-utilidad`:
  - *"Este precio queda $X por debajo del costo."* (regla 15).
  - *"Con IVA de 16%, $X no se puede alcanzar exacto; el precio quedará en $Y."* (regla 6).

### `articulos/_resumen-precio.blade.php` (cadena del precio de venta)

En modo fijo se usan los mismos renglones: precio de lista → descuento → **costo** → **utilidad $X
(Y%)** con lo que deja el precio fijo (con "−" si es negativa) → precio sin IVA → IVA → precio con
IVA → precio final, que es el precio fijo (o el centavo de arriba, regla 6). El renglón de redondeo
al peso entero queda oculto. La cadena del precio distribuidor no cambia.

### `articulos/_filas.blade.php`

- Celda "Precio": si `tiene_precio_fijo`, agrega `<span class="etiqueta">Fijo</span>` (regla 17).
- Si `por_debajo_del_costo`, la celda gana la clase `precio-bajo-costo` (texto rojo, definido en
  `estilos.blade.php` con el token de color de peligro que ya existe) y
  `title="Por debajo del costo"` (regla 16).
- La ficha no cambia: sigue mostrando el precio con IVA sin decir si es fijo.

### `catalogos/_formulario.blade.php`

La alerta de confirmación agrega la frase de M cuando `session('conservan_precio_fijo') > 0`
(regla 19).

## JavaScript (`public/js/precio-articulo.js`)

- Funciones puras nuevas, espejo exacto de PHP: `precioFijoSinIva(precioFijo, factor)` y
  `utilidadPorcentajeEquivalente(precioSinIva, costo)`.
- El manejador del resumen lee también `modo_precio` y `precio_fijo`. Al cambiar de modo, muestra u
  oculta los campos y los renglones. Recalcula en vivo con `input` sobre precio de lista, catálogo,
  objeto de impuesto, utilidad y precio fijo.
- Cambia la etiqueta del precio fijo según el objeto de impuesto (con IVA o sin IVA).
- Muestra u oculta los dos avisos de la regla 15 y la regla 6.

## Fixture compartido (`tests/Fixtures/precios-articulos.json`)

Los casos ganan el campo opcional `precio_fijo`. Si viene, `precio_venta` y `utilidad` esperados
salen de la regla 6 y se agrega `utilidad_porcentaje_equivalente`. Casos mínimos:

| Caso | Costo | Objeto | Precio fijo | Sin IVA | Con IVA | Utilidad | % equiv. |
|---|---|---|---|---|---|---|---|
| Ejemplo de la historia | 180.00 | 02 | 330.00 | 284.48 | 330.00 | 104.48 | 58.04 |
| Con centavos alcanzable | 180.00 | 02 | 329.50 | 284.05 | 329.50 | 104.05 | 57.81 |
| Centavo inalcanzable | 5.00 | 02 | 7.00 | 6.04 | 7.01 | 1.04 | 20.80 |
| Sin IVA, tal cual | 180.00 | 01 | 329.50 | 329.50 | 329.50 | 149.50 | 83.06 |
| Debajo del costo | 300.00 | 02 | 330.00 | 284.48 | 330.00 | −15.52 | −5.17 |
| Costo cero | 0.00 | 02 | 116.00 | 100.00 | 116.00 | 100.00 | — |
| Tope | 1.00 | 01 | 10000000.00 | 10000000.00 | 10000000.00 | 9999999.00 | 999999900.00 |

`CalculadoraPrecioArticuloTest` (Pest) y la prueba de Node lo recorren completo. Además, un barrido
de todos los precios con IVA de $0.01 a $1,000.00 confirma que PHP y JavaScript dan el mismo centavo
y que nunca queda por debajo de lo capturado.

## Pruebas (Pest)

En `tests/Feature/ArticuloPrecioFijoTest.php`, con `sembrarCatalogosSat()` en `beforeEach`:

1. Alta con `modo_precio=fijo` y `precio_fijo=330` (`02`) guarda `precio_fijo` 330.00,
   `precio_unitario_sin_iva` 284.48 y `precio_fijo_desde` de hoy.
2. `modo_precio` fuera de la lista → error de validación; sin `modo_precio` vale "utilidad".
3. `modo_precio=fijo` sin `precio_fijo` → *"Escribe el precio fijo."*; 0, 10000000.01 y 1.234 → error.
4. `modo_precio=utilidad` con `precio_fijo` lleno → se guarda `null` y el precio sale del porcentaje.
5. Cambiar el precio de lista, el descuento del catálogo o la utilidad del catálogo no mueve el
   precio de un artículo con precio fijo, pero sí su `costo_con_descuento`.
6. Cambiar el objeto de impuesto de `02` a `01` con precio fijo 330 → sin IVA 330.00; de vuelta a
   `02` → 284.48.
7. Volver a utilidad recalcula con la utilidad propia conservada, o con la del catálogo si es
   `null`, y pone `precio_fijo_desde` en `null`.
8. Fecha: guardar sin cambiar el precio no la mueve (`travelTo` a otro día); cambiar el precio la
   pone al día nuevo; `329.5` → `329.50` no la mueve.
9. Precio debajo del costo se guarda. El listado pinta la celda con `precio-bajo-costo` y
   `title="Por debajo del costo"`. Un artículo por utilidad nunca lleva la clase.
10. El listado muestra la etiqueta "Fijo" solo en artículos con precio fijo.
11. Confirmación de catálogo: cambiar el descuento con 2 artículos por utilidad y 1 fijo → N = 3
    (el fijo cuenta porque su precio distribuidor sí cambia, regla 21), M = 1 y la frase aparece. Cambiar solo la utilidad distribuidor → sin frase de M. Catálogo solo
    con artículos fijos → sin confirmación y mensaje de éxito con "conservaron su precio".
12. Un artículo fijo cuyo precio distribuidor cambia cuenta en N; si no cambia, no cuenta.
13. Clonar un artículo con precio fijo → el clon tiene el mismo `precio_fijo` y la fecha de hoy.
14. CSV: importar con `precio_fijo` lleno, vacío y sin la columna. Un valor inválido rechaza la
    fila con mensaje. La exportación trae la columna.
15. Una cotización nueva con el artículo toma `precio_unitario_sin_iva` del precio fijo. Una
    cotización guardada antes no cambia al poner precio fijo.
16. La edición muestra "Precio fijo desde el dd/mm/aaaa", y la ficha no lo muestra.

Las suites existentes de artículos, catálogos, clonar, importación y precios deben seguir pasando sin
cambios, salvo `COLUMNAS_CSV` en las pruebas que comparan el encabezado exacto.

## Criterios de aceptación

1. Puedo escribir $330.00 en un artículo y el cliente lo ve y paga a $330.00 (con IVA si aplica).
2. Cambiar el proveedor, el descuento o la utilidad del catálogo no cambia ese precio.
3. Veo en vivo cuánto gano en pesos y qué porcentaje equivale.
4. Si el precio queda debajo del costo, el formulario me avisa y el listado lo marca en rojo.
5. El listado distingue los precios fijos con la etiqueta "Fijo".
6. Dentro del artículo veo desde cuándo tiene ese precio fijo.
7. Al recalcular un catálogo, el sistema me dice cuántos precios fijos no se tocaron.
8. Puedo volver al porcentaje en cualquier momento, y el precio se recalcula como siempre.
9. Clonar, importar y exportar respetan el precio fijo.
10. Ningún documento ya emitido cambia.

## Supuestos asumidos (registro completo)

Aprobados por el usuario el 2026-10-12:

1. Solo en artículos; el catálogo no tiene precio fijo.
2. Un selector por artículo con dos modos: "Por utilidad (%)" o "Precio fijo".
3. El precio que se escribe es el que paga el cliente (con IVA si aplica).
4. Se respeta tal cual, con centavos; no se lleva al peso entero.
5. El precio fijo no cambia solo: cambios de proveedor, descuento o utilidad del catálogo no lo
   mueven; solo cambia la utilidad.
6. La utilidad (pesos y porcentaje) se muestra en vivo como consecuencia.
7. Se permite vender por debajo del costo, con aviso.
8. Solo el precio directo; el distribuidor sigue por porcentaje.
9. Volver al porcentaje recalcula con el porcentaje propio o el del catálogo y olvida el precio fijo.
10. El listado distingue los artículos con precio fijo.
11. Al clonar se copia el modo y el precio fijo.
12. CSV con columna opcional `precio_fijo`.
13. Los documentos ya emitidos no cambian.
14. El resto del sistema usa el precio fijo como precio de venta normal.

Adiciones técnicas aprobadas:

1. Rojo en el listado para precios fijos por debajo del costo (sin nivel amarillo).
2. Fecha del precio fijo, visible solo dentro del artículo y actualizada en cada cambio de precio.
3. La confirmación del recálculo de catálogo dice cuántos precios fijos se conservan.

Decisiones tomadas al redactar (aprobadas por el usuario el 2026-10-12):

1. Regla 6: con IVA, un precio con centavos inalcanzable sube al siguiente centavo alcanzable y el
   formulario lo avisa.
2. Regla 7: tope de $10,000,000.00.
3. Regla 13: con costo $0.00, el porcentaje equivalente se muestra como "—".
4. Regla 20: si el cambio de catálogo solo alcanza precios fijos, no hay paso de confirmación; lo
   dice el mensaje de éxito.
5. Regla 22: el clon lleva la fecha del día en que se clona.

## Estado de implementación

Implementada el 2026-10-12:

- **Archivos nuevos:** la migración `2026_10_18_100000_precio_fijo_articulos` (**aplicada en la base
  local**) y `tests/Feature/ArticuloPrecioFijoTest.php` (28 pruebas).
- **Cambiados:** `CalculadoraPrecioArticulo` (`precioFijoSinIva`, `utilidadPorcentajeEquivalente`),
  `Articulo`, `Catalogo::articulosConPrecioFijoAlcanzados()`, `CatalogoController::update`,
  `ArticuloRequest`, `ArticuloController`, `ImportadorArticulosCsv`,
  `ExportacionArticulosController`, `public/js/precio-articulo.js`, `public/css/app.css`, y las
  vistas `articulos/_formulario`, `_resumen-precio`, `_filas`, `importar` y
  `catalogos/_formulario`.
- **`<x-campo tipo="radio">`:** el componente de campo ganó el tipo radio, porque la revisión de
  estilo uniforme no permite `<input>` fuera de los componentes.
- **Fixture:** siete casos de precio fijo en `tests/Fixtures/precios-articulos.json`, más un
  barrido de $0.01 a $2,000.00 en PHP y en JavaScript (con y sin IVA): el precio con IVA nunca
  queda debajo de lo capturado ni lo pasa por más de un centavo.
- **Pruebas existentes:** solo cambiaron las dos de exportación que comparan el encabezado exacto
  del CSV. Suite completa: 1,426 pruebas de Pest y 90 de Node, todas pasan.
