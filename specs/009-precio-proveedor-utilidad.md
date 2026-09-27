# Spec: Precio del proveedor y utilidad (precio de venta calculado por markup)

**Referencia:** reescritura de [remotas/011-precio-proveedor-utilidad.md](remotas/011-precio-proveedor-utilidad.md),
que se diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan las reglas de
negocio (cadena de cálculo, markup sobre el costo, herencia viva del porcentaje, techo a 2 decimales,
recálculo en bloque confirmado) y las lecciones de la implementación remota (trampas de punto
flotante, fixture compartido entre las dos copias de la fórmula). La parte del navegador y el
protocolo entre navegador y servidor se rehicieron para Laravel + Blade + JavaScript nativo. Extiende
[007-gestion-articulos.md](007-gestion-articulos.md) y [008-catalogos.md](008-catalogos.md).

## Historia de usuario

Como usuario del sistema de facturación, quiero registrar el precio que me cobra el proveedor y el
porcentaje de utilidad que quiero ganar, para que el sistema calcule solo el precio de venta de cada
artículo y me muestre cuánto dinero me queda de utilidad por pieza, sin tener que sacar la cuenta a
mano cada vez que un proveedor me cambia un precio o me mejora un descuento.

## Objetivo / Alcance

Separar **costo** de **precio de venta** en `Articulo` (007) y `Catalogo` (008). El usuario captura
el **precio de lista del proveedor** y, opcionalmente, un **porcentaje de utilidad**; el precio de
venta pasa a ser un valor **calculado** por el servidor. La utilidad en pesos se ve en el formulario
de artículo.

- Laravel resuelve todo: modelo, validación, recálculo, confirmación del recálculo en bloque,
  importación y exportación. No se crea API REST ni endpoints JSON nuevos.
- JavaScript nativo solo para el **resumen de la cadena en vivo** del formulario de artículo y el
  **aviso de porcentaje alto** en los formularios de artículo y catálogo. Todo tiene respaldo sin
  JavaScript: el precio que cuenta siempre lo calcula el servidor.

**No** incluye reportes de rentabilidad ni cambios a facturación, cotizaciones o tesorería (no
existen todavía en esta arquitectura).

### Cadena de cálculo

Con precio de lista $200.00, descuento de catálogo 10% y utilidad 25%:

```
precio_proveedor          (capturado)              $200.00
  ↓ × (1 − descuento / 100)            redondeo2   descuento del catálogo (10%)
costo_con_descuento       (calculado, persistido)  $180.00
  ↓ × (1 + utilidad_efectiva / 100)    techo2      markup sobre el costo (25%)
precio_unitario_sin_iva   (calculado, persistido)  $225.00
  ↓ × (1 + Articulo::TASA_IVA)                     IVA general (007)
precio_unitario_con_iva   (calculado al leer)      $261.00

utilidad = precio_unitario_sin_iva − costo_con_descuento = $45.00
utilidad_efectiva = articulo.utilidad_porcentaje ?? catalogo.utilidad_porcentaje
```

El porcentaje es **markup sobre el costo**: 25% significa "quiero ganar el 25% de lo que me costó".
Con costo $100.00 y 25%, el precio de venta es $125.00 y la utilidad $25.00.

## Backend (Laravel)

### Calculadora única (`App\Services\Articulos\CalculadoraPrecioArticulo`)

Clase sin estado con métodos estáticos puros sobre números (`float`), sin tocar la base de datos:
`redondeo2()`, `techo2()`, `costoConDescuento($precioProveedor, $descuento)`,
`precioVentaSinIva($costo, $utilidadPorcentaje)` y `utilidad($precioVenta, $costo)`. Es el **único**
lugar de PHP que conoce la fórmula: la usan el evento `saving` de `Articulo`, el recálculo en bloque
de `Catalogo`, el conteo de impacto y la migración de datos. `Catalogo::precioConDescuento()` de 008
desaparece (su lógica pasa a `costoConDescuento()`).

Queda preparada para que las historias futuras de la cadena ([remotas/014](remotas/014-costo-elaboracion-goma.md)
costo de goma, [remotas/024](remotas/024-precios-sin-centavos.md) precio sin centavos) agreguen su
eslabón en un solo lugar.

#### Redondeo

Dos redondeos distintos y deliberados, ambos definidos **sobre el valor ya expresado en centavos**:

```
redondeo2(v) = round(round(v × 100, 6)) / 100      (mitad hacia arriba)
techo2(v)    = ceil(round(v × 100, 6)) / 100
```

- `costo_con_descuento` usa `redondeo2`.
- `precio_unitario_sin_iva` usa `techo2`, para que el precio de venta **nunca** quede por debajo del
  markup pedido: costo $100.01 al 33% da $133.0133… → **$133.02**, no $133.01.
- El redondeo intermedio a 6 decimales va **después** de multiplicar por 100. Un techo ingenuo
  `ceil(v × 100)` cobra un centavo de más por el error de representación del producto; absorber el
  error antes de escalar (`ceil(round(v, 4) × 100)`) tampoco sirve, porque la multiplicación por 100
  lo reintroduce (`0.07 × 100 = 7.000000000000001`): costo $15.40 al 5% debe dar **$16.17**, no
  $16.18 (lección de la spec remota, verificada allá contra aritmética entera sobre 4.2 millones de
  combinaciones).
- `redondeo2` cambia de forma respecto a 008 (`round(round(v, 6), 2)`) para ser simétrico con
  `techo2` y reproducible en JavaScript. Como el recálculo en bloque deja de hacerse en SQL (ver
  abajo), ya no tiene que coincidir con el `ROUND` de MySQL; los casos de empate de 008
  (`10.05 × 0.9 = 9.05`) pasan al fixture compartido.

### Cambios sobre `Catalogo` (extiende 008)

- **Nueva columna `utilidad_porcentaje`**: `decimal(5,2)`, obligatoria, **default 0** (mismo patrón
  que `descuento`). Es el porcentaje que heredan los artículos del catálogo sin porcentaje propio.
- **Recálculo en bloque** (evento `updated`, reemplaza el `UPDATE` masivo de 008):
  - Si cambió `descuento`: se recalculan **todos** los artículos del catálogo (incluidos los
    eliminados, como en 008 y con porcentaje propio o no), porque cambia el costo del que parten.
  - Si solo cambió `utilidad_porcentaje`: se recalculan **solo los que heredan**
    (`articulos.utilidad_porcentaje IS NULL`).
  - Se hace **en PHP**, recorriendo los artículos afectados con `lazyById()` y la calculadora, dentro
    de una transacción. No se usa un `UPDATE … SET … = ROUND(…)`: el techo no es portable entre
    MySQL y SQLite y sería una tercera copia de la fórmula.
- **`Catalogo::articulosAfectados(float $descuento, float $utilidad): int`**: con los valores que el
  usuario está por guardar, cuenta **exactamente** cuántos artículos **no eliminados** cambiarían su
  `precio_unitario_sin_iva`, aplicando la regla de arriba y comparando el precio nuevo con el
  guardado (un cambio de 10% a 10.00% o uno que no mueve ningún centavo da 0). Alimenta la
  confirmación del formulario.
- `descuento_texto` se generaliza en un helper de formato de porcentaje sin ceros sobrantes, y se
  agrega `utilidad_texto` ("25%", "122.5%").

### Cambios sobre `Articulo` (extiende 007 y 008)

- **Nueva columna `precio_proveedor`**: `decimal(10,2)`, obligatoria, mayor a 0, MXN, **sin IVA**.
  Es el **precio de lista** del proveedor, *antes* del descuento del catálogo.
- **Nueva columna `utilidad_porcentaje`**: `decimal(5,2)`, **nullable**. `NULL` = hereda del
  catálogo (herencia viva); un valor = porcentaje propio.
- **`precio_con_descuento` se renombra a `costo_con_descuento`** y pasa a significar el **costo
  real**: `redondeo2(precio_proveedor × (1 − catalogo.descuento / 100))`. Se sigue persistiendo.
- **`precio_unitario_sin_iva` deja de ser un campo de entrada**: se calcula y persiste como
  `techo2(costo_con_descuento × (1 + utilidad_efectiva / 100))`. No cambia de nombre ni de tipo, así
  que el listado, el orden por precio y el accessor `precio_unitario_con_iva` de 007 siguen igual, y
  facturación la leerá tal cual cuando exista.
- **Accessors sin columna**: `utilidad_porcentaje_efectivo` (el porcentaje que realmente se aplicó) y
  `utilidad` (monto por pieza, sin IVA: `precio_unitario_sin_iva − costo_con_descuento`).
- **`fillable`**: sale `precio_unitario_sin_iva`; entran `precio_proveedor` y `utilidad_porcentaje`.
  `costo_con_descuento` y `precio_unitario_sin_iva` solo los escribe el modelo, así que cualquier
  valor que envíe el navegador para ellos (o para `utilidad`) se **ignora en silencio**, igual que
  `precio_con_descuento` en 008.
- **Evento `saving`** (el mismo de 008): recalcula `proveedor_id`, `costo_con_descuento` y
  `precio_unitario_sin_iva` cuando cambia `catalogo_id`, `precio_proveedor` o `utilidad_porcentaje`,
  leyendo el catálogo con una consulta nueva (lección de 008). El cálculo vive en
  `Articulo::recalcularPrecio(Catalogo $catalogo)`, que también usa el recálculo en bloque.
- Mover un artículo de catálogo **conserva su porcentaje propio** y recalcula con el descuento del
  catálogo destino.
- Casts: `precio_proveedor`, `costo_con_descuento` → `decimal:2`; `utilidad_porcentaje` →
  `decimal:2`.
- Constante `Articulo::UMBRAL_UTILIDAD_ALTA = 400`, único lugar donde vive el umbral del aviso
  (ver [remotas/032](remotas/032-umbral-aviso-utilidad-alta.md)); las vistas lo pasan al JavaScript
  en un atributo `data-`, igual que `TASA_IVA`.
- `ORDENES` agrega `costo` (`costo_con_descuento`).
- `COLUMNAS_CSV` pasa a
  `nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje`.

### Migración de esquema y de datos (una sola migración)

1. `catalogos` gana `utilidad_porcentaje` `decimal(5,2)` default 0: todos los catálogos existentes
   quedan en **0%**.
2. `articulos` gana `precio_proveedor` `decimal(10,2)` (anulable al inicio) y `utilidad_porcentaje`
   `decimal(5,2)` nullable; `precio_con_descuento` se renombra a `costo_con_descuento`.
3. Cada artículo (incluidos los eliminados) toma su `precio_unitario_sin_iva` actual como
   `precio_proveedor`, queda con `utilidad_porcentaje` en `NULL`, y se recalcula su cadena completa
   **en PHP con la calculadora**, igual que cualquier otro camino. `precio_proveedor` se vuelve
   obligatoria.
4. `down()`: devuelve `precio_unitario_sin_iva := precio_proveedor`, renombra la columna de vuelta,
   recalcula `precio_con_descuento` y borra las columnas nuevas.

**Consecuencia con los datos reales** (ver supuesto 11): hoy la base tiene 278 artículos en el
catálogo "Sellos Colop - Autoentintable" con **55% de descuento**. Con el paso 3 y 0% de utilidad,
su precio de venta pasa a ser su costo (45% del precio actual) hasta que el usuario capture la
utilidad del catálogo, lo cual recalcula los 278 de una vez (con la confirmación de abajo).

### Rutas

Sin rutas nuevas. Cambios en las existentes:

- `PUT /catalogos/{catalogo}` acepta el campo `confirmar` (ver "Confirmación del recálculo").
- `GET /articulos` y `/articulos/buscar` aceptan `orden=costo`.
- `POST /articulos/importar` y `GET /articulos/exportar` usan las 7 columnas nuevas.

### Confirmación del recálculo (`CatalogoController::update`)

En lugar del endpoint JSON `impacto-precios` y el diálogo Vue de la spec remota, la confirmación es
un **paso del mismo formulario**, sin JavaScript:

1. Si el `PUT` cambia `descuento` o `utilidad_porcentaje`, no trae `confirmar=1` y
   `articulosAfectados()` es mayor a 0, **no se guarda nada**: se redirige de vuelta al formulario de
   edición con los valores capturados (`withInput()`) y el conteo en la sesión flash.
2. El formulario muestra, arriba de los botones, un `<x-alerta tipo="advertencia">` "Se recalculará
   el precio de venta de **N** artículos." con los botones "Confirmar y guardar" (`bi-check-lg`,
   `name="confirmar" value="1"`) y "Cancelar" (vuelve al listado sin guardar).
3. Con `confirmar=1` se guarda y el modelo recalcula; el flash de éxito dice "Catálogo actualizado.
   Se recalculó el precio de N artículos."
4. Si el conteo es 0 (cambia solo el nombre, o el cambio no mueve ningún precio), se guarda directo,
   sin paso de confirmación. En el alta nunca aplica.

`confirmar` no está en las reglas de validación: se lee con `$request->boolean('confirmar')`.

### Validaciones

- **`CatalogoRequest`**: `prepareForValidation()` toma `utilidad_porcentaje` vacío o ausente como
  `0`. Regla: requerido, numérico, `between:0,999.99`, `decimal:0,2`.
- **`ArticuloRequest::reglas()`** (compartidas con la importación, como en 007):
  - Sale `precio_unitario_sin_iva`.
  - `precio_proveedor`: requerido, numérico, `gt:0`, `decimal:0,2`, **`max:9000000`**. El tope
    garantiza que el precio de venta más alto posible (×10.9999 con 999.99%) quepa en
    `decimal(10,2)`; la spec remota no lo acotaba y un precio grande con markup alto desbordaba la
    columna.
  - `utilidad_porcentaje`: `nullable`, numérico, `between:0,999.99`, `decimal:0,2`.
  - Mensajes: "El precio del proveedor debe ser mayor a 0.", "La utilidad debe estar entre 0 y
    999.99%.", y los de decimales.
- Se permite 0% (vender a costo). **No** se aceptan porcentajes negativos. El tope de 999.99 es el
  de `decimal(5,2)`; por encima de 400% solo hay aviso visual, nunca bloqueo.
- `costo_con_descuento`, `precio_unitario_sin_iva` y `utilidad` no están en las reglas: si llegan,
  se ignoran.

### Importación y exportación CSV (extiende 007)

- Columnas idénticas en ambos sentidos (un CSV exportado, editado, se reimporta tal cual):

  ```
  nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje
  ```

- El encabezado exige las 7 columnas; un archivo con el formato anterior
  (`precio_unitario_sin_iva`) se rechaza completo con el mensaje de columnas faltantes de 007.
- `utilidad_porcentaje` vacío = hereda del **catálogo destino**; con valor = porcentaje propio,
  validado con las mismas reglas del alta. El importador convierte la celda vacía en `null` antes de
  validar (en HTTP lo hace `ConvertEmptyStringsToNull`; en el importador no).
- Los valores calculados (costo, precio de venta, utilidad, precio con IVA) **no viajan** en el CSV.
- Exportación: `precio_proveedor` con punto decimal y dos decimales; `utilidad_porcentaje` vacío si
  hereda, con dos decimales si es propio.

### Controladores

- **`ArticuloController`**: `create`/`edit` pasan a la vista, por catálogo disponible, su descuento y
  su utilidad (`data-catalogos` del resumen). `store`/`update` redirigen con el flash
  "Artículo guardado. Precio de venta con IVA: $X." (el valor calculado por el servidor, ver
  supuesto 19).
- **`CatalogoController`**: `update` con la confirmación descrita; `index` muestra la utilidad.

## Vistas (Blade)

Solo componentes de 003 (lo verifica `EstiloUniformeTest`).

- **`articulos/_formulario.blade.php`**:
  - "Precio unitario sin IVA" se reemplaza por **"Precio de lista del proveedor (sin IVA)"**
    (`<x-campo tipo="number" step="0.01" min="0.01" max="9000000">`, obligatorio).
  - **"Utilidad (%)"** (`<x-campo tipo="number" step="0.01" min="0" max="999.99">`, opcional). Su
    `placeholder` es el porcentaje del catálogo elegido ("Hereda 25% del catálogo"); lo pinta el
    servidor para el catálogo actual y lo actualiza el JavaScript al cambiar de catálogo. Ayuda:
    "Déjalo vacío para usar el del catálogo."
  - Aviso de porcentaje alto: `<p class="aviso-utilidad" hidden>` bajo el campo ("Más de 400%: el
    costo se multiplica por N. Revisa que no sobre un cero."), ámbar, **no bloquea**. Sin JavaScript
    lo pinta el servidor si el valor devuelto por un error de validación supera el umbral.
  - Los dos `<p class="precio-con-iva">` de 007/008 se reemplazan por el **resumen de la cadena**, un
    `<dl class="resumen-precio">` dentro del `<x-card>`, siempre visible:

    ```
    Precio de lista del proveedor      $200.00
    Descuento del catálogo (10%)      −$20.00
    Costo                              $180.00
    Utilidad (25%)                     +$45.00
    Precio de venta sin IVA            $225.00
    IVA (16%)                          +$36.00
    Precio de venta con IVA            $261.00
    ```

    Cada valor es un `<output>`. En edición el servidor los pinta con lo guardado; en el alta
    muestran "—" hasta que el JavaScript tiene datos.
- **`articulos/_titulos` y `_filas`**: nueva columna **"Costo"** (`costo_con_descuento`, sin IVA,
  ordenable con `orden=costo`) entre "Catálogo" y "Precio con IVA". La utilidad en pesos, el precio
  de lista y el porcentaje quedan solo en el formulario, para no revivir el desborde que resolvió
  `.celda-truncada` en 007 (criterio 19 de 007 debe seguir cumpliéndose). `colspan` pasa a 7.
- **`articulos/importar.blade.php`**: el bloque de columnas lista las 7 nuevas y explica que
  `utilidad_porcentaje` vacío hereda del catálogo destino.
- **`catalogos/_formulario.blade.php`**: campo **"Utilidad (%)"** (`number`, `step="0.01"`,
  `min="0"`, `max="999.99"`, precargado en `0`) junto al descuento, con el mismo aviso de 400%, y el
  bloque de confirmación del recálculo cuando hay conteo en la sesión.
- **`catalogos/index.blade.php`**: columna **"Utilidad"** (`25%`) junto a "Descuento".
- **`app.css`**: estilos de `.resumen-precio` (etiqueta a la izquierda, monto alineado a la derecha
  con números tabulares, línea separadora antes de "Costo", "Precio de venta sin IVA" y "Precio de
  venta con IVA") y `.aviso-utilidad` (color de advertencia de 003). `/estilos` muestra ambos y el
  icono `bi-check-lg` si no estaba.

## JavaScript

### Fórmula en el navegador (`public/js/precio-articulo.js`)

Reemplaza a `precio-con-iva.js` y `precio-con-descuento.js` (se borran). Dos partes en un archivo:

- **Funciones puras**, espejo exacto de `CalculadoraPrecioArticulo`: `redondeo2`, `techo2`,
  `costoConDescuento`, `precioVentaSinIva`, `utilidad`, `precioConIva` y `porcentajeAlto(valor,
  umbral)` (un valor no numérico devuelve `false`, para no parpadear mientras se escribe). Se
  exponen en `window.PrecioArticulo` y, si existe `module`, en `module.exports`, para probarlas con
  Node sin navegador.
- **Enlace con la página**:
  - `dl[data-resumen-precio]`: lee `data-catalogos` (`{ id: { descuento, utilidad } }`),
    `data-tasa-iva` y los ids de los campos; recalcula todo el resumen al escribir precio o
    utilidad y al cambiar de catálogo, y actualiza el `placeholder` de la utilidad.
  - `input[data-aviso-utilidad]`: muestra u oculta el aviso con el umbral de `data-umbral`. Lo usan
    los formularios de artículo y de catálogo (el de catálogo carga el script solo para esto).
- Es informativo: el precio que cuenta lo calcula y lo guarda el servidor.

### Fuente de verdad única de la fórmula

La cadena existe en PHP (persiste) y en JavaScript (resumen en vivo sin ir al servidor). Que existan
dos copias es aceptable; que diverjan en silencio, no.

- **Fixture compartido** `tests/Fixtures/precios-articulos.json`: cada caso trae precio de lista,
  descuento, porcentaje y los tres resultados esperados (costo, precio de venta, utilidad). Incluye
  como mínimo: $15.40 al 5% → $16.17; costo $100.01 al 33% → $133.02; 0%; porcentajes de tres
  dígitos y 999.99%; el empate de 008 (`10.05` con 10% → `9.05`); descuentos que dejan costos no
  redondos; y el ejemplo de aceptación ($347.27, 55%, 99% → $156.27 / $310.98 / $154.71).
- **Pest** (`tests/Unit/CalculadoraPrecioArticuloTest.php`) lo recorre con un dataset.
- **Node** (`tests/js/precio-articulo.test.js`) lo recorre con el ejecutor de pruebas que ya trae
  Node (`node --test "tests/js/*.test.js"`), cargando `public/js/precio-articulo.js` con `require`. **Sin npm, sin
  `package.json` y sin Vitest**: el proyecto no tiene cadena de construcción de frontend y no se
  crea una para probar aritmética.
- Cambiar una fórmula sin la otra rompe la suite del lado no tocado.

## Pruebas (Pest y Node)

- **Calculadora**: el fixture completo (unitaria, sin base de datos).
- **Artículo**: alta con precio de lista y sin porcentaje (hereda), con porcentaje propio, con 0%;
  validaciones de `precio_proveedor` (≤ 0, más de 2 decimales, más de 9,000,000) y de
  `utilidad_porcentaje` (negativo, > 999.99, más de 2 decimales; 0 y 350 aceptados); enviar
  `precio_unitario_sin_iva`, `costo_con_descuento` o `utilidad` no da error y se ignora; editar el
  precio o el porcentaje recalcula; mover de catálogo conserva el porcentaje propio y recalcula con
  el descuento del destino; el flash muestra el precio con IVA calculado; orden por `costo` en ambas
  direcciones.
- **Catálogo**: `utilidad_porcentaje` vacío → 0, fuera de rango, más de 2 decimales; cambiar el
  descuento recalcula **todos** (incluidos los de porcentaje propio y los eliminados); cambiar la
  utilidad recalcula **solo los que heredan**; `articulosAfectados()` exacto (descuento, utilidad,
  ambos, sin cambio real = 0, excluye eliminados); sin `confirmar` y con afectados no guarda y
  devuelve el conteo con los valores capturados; con `confirmar=1` guarda y recalcula; con 0
  afectados guarda directo; el listado muestra la utilidad.
- **Importación/exportación**: fila con `utilidad_porcentaje` vacío hereda del catálogo destino;
  con valor lo guarda propio; valor inválido rechaza la fila; encabezado viejo rechazado; la
  exportación trae las 7 columnas (vacío si hereda) y se reimporta sin perder el porcentaje propio.
- **Migración**: `down()`/`up()` con artículos y catálogos (incluidos eliminados) deja
  `precio_proveedor` = precio anterior, porcentaje `NULL` y la cadena recalculada; se verifica
  además contra MySQL real dentro de una transacción revertida, como en 008.
- `ArticuloFactory` recibe `precio_proveedor` (y `utilidad_porcentaje` `null`) y deja que el modelo
  derive el resto; los tests existentes de 007/008 que usaban `precio_unitario_sin_iva` o
  `precio_con_descuento` se reescriben en términos de precio de lista y markup.
- `node --test "tests/js/*.test.js"` pasa y `node --check` valida `precio-articulo.js`.
- `EstiloUniformeTest`, `ProveedorTest` y `ClienteTest` siguen pasando.

## Fuera de alcance

- Reportes de rentabilidad (utilidad por día, semana, catálogo o proveedor) y tesorería.
- Mostrar la utilidad al capturar una cotización o factura (no existen todavía).
- Precio por **margen sobre la venta** (`costo ÷ (1 − %)`) o selector markup/margen.
- **Modo manual** de precio de venta (congelar un precio capturado a mano).
- Utilidad negativa (vender por debajo del costo).
- Calcular el porcentaje a partir de un precio de venta objetivo.
- Historial de cambios de precio, costo o porcentaje; deshacer un recálculo.
- Multimoneda y tipo de cambio: todo en MXN.
- Descuento distinto por artículo dentro de un catálogo (sigue siendo uniforme, 008).
- Costo de goma ([remotas/014](remotas/014-costo-elaboracion-goma.md)), precios sin centavos
  ([remotas/024](remotas/024-precios-sin-centavos.md)) y precio distribuidor
  ([remotas/033](remotas/033-precio-distribuidor.md)): cada uno con su propia spec, sobre la
  calculadora de esta.
- Orden por utilidad en el listado (la spec remota lo tenía solo en el servidor, sin forma de pedirlo
  desde la pantalla).
- Cabeceras de caché de `index.html` y recarga ante `vite:preloadError` de la spec remota: no hay
  SPA ni chunks de Vite; las páginas las arma el servidor en cada petición.
- Vitest, npm o cualquier cadena de construcción de frontend.

## Estado de implementación

Implementada el 2026-09-26.

- **Fórmula verificada contra aritmética entera**: además del fixture, un barrido de 300,000
  combinaciones aleatorias de precio de lista, descuento y utilidad dio **cero** diferencias entre
  PHP, JavaScript y una referencia en centavos enteros (`BigInt`). El fixture se generó con esa
  referencia, no con ninguna de las dos implementaciones.
- **Sin `bcmath`**: `articulosAfectados()` compara precios redondeados a 2 decimales en lugar de usar
  `bccomp`, para no depender de una extensión que el hosting podría no tener.
- **Aviso de utilidad**: parcial `articulos/_aviso-utilidad.blade.php`, compartido por los dos
  formularios. `Catalogo::porcentajeTexto()` formatea descuento y utilidad sin ceros sobrantes.
- **Migración contra MySQL real**: 278 artículos en "Sellos Colop - Autoentintable" (55%). Tras
  migrar, la suma de precios de lista es la suma de precios de venta anterior (106,900.67) y la suma
  de costos coincide al centavo con la de `precio_con_descuento` de 008 (48,105.32): el nuevo
  `redondeo2` da lo mismo que el `ROUND` de MySQL en los 278. El precio de venta quedó igual al costo
  (0% de utilidad), como se decidió. Se probó `migrate:rollback --step=1` y volver a migrar. Dentro
  de una transacción revertida, poner 99% al catálogo recalculó los 278 idéntico a la calculadora, y
  el conteo de impacto con 122.22% dio 278.
- **Verificación**: la suite Pest pasa (379 tests), `node --test "tests/js/*.test.js"` pasa (17),
  Pint no reporta cambios y `node --check` valida `precio-articulo.js`. **No se revisó la UI en un
  navegador real**: conviene abrir `/articulos/crear` (resumen en vivo al escribir y al cambiar de
  catálogo, placeholder de utilidad heredada, aviso arriba de 400%) y editar el catálogo para
  capturar su utilidad (paso de confirmación con el conteo).

## Criterios de aceptación

1. Un usuario autenticado puede crear un artículo capturando el precio de lista del proveedor
   (obligatorio, mayor a 0) y, opcionalmente, un porcentaje de utilidad propio; el precio de venta
   no se captura.
2. Un precio del proveedor ≤ 0, con más de 2 decimales o mayor a 9,000,000 muestra un error y no
   guarda.
3. Un porcentaje negativo o mayor a 999.99 muestra un error y no guarda; 0 y porcentajes de tres
   dígitos dentro del rango sí se aceptan.
4. Al guardar, el sistema calcula y persiste el costo con descuento y el precio de venta sin IVA
   (costo × (1 + %)), y el formulario muestra la utilidad en pesos. Precio de lista $347.27 en un
   catálogo con 55% de descuento y 99% de utilidad produce costo $156.27, precio de venta $310.98 y
   utilidad $154.71.
5. El precio de venta se redondea hacia arriba: costo $100.01 al 33% → $133.02.
6. Costo $15.40 al 5% → exactamente $16.17.
7. Los casos del fixture compartido dan el mismo resultado en PHP (Pest) y en JavaScript
   (`node --test`).
8. Un artículo sin porcentaje propio hereda el del catálogo; el campo vacío muestra el porcentaje
   heredado como `placeholder`.
9. Cambiar el descuento de un catálogo recalcula costo y precio de venta de **todos** sus artículos.
10. Cambiar la utilidad de un catálogo recalcula **solo** los artículos que heredan.
11. Antes de cualquiera de esos recálculos, el formulario pide confirmación mostrando el número
    exacto de artículos cuyo precio va a cambiar; cancelar no guarda nada. Funciona sin JavaScript.
12. Mover un artículo con porcentaje propio a otro catálogo conserva su porcentaje y recalcula con
    el descuento del destino.
13. Enviar `precio_unitario_sin_iva`, `costo_con_descuento` o `utilidad` no produce error y se
    ignora.
14. `/articulos` muestra la columna "Costo", ordenable en ambas direcciones, sin desplazamiento
    horizontal a ≥1280px.
15. El formulario de artículo muestra en vivo la cadena completa (lista → descuento → costo →
    utilidad → venta sin IVA → IVA → venta con IVA) al escribir y al cambiar de catálogo.
16. Un porcentaje mayor a 400 muestra un aviso visual que **no** impide guardar, en artículo y en
    catálogo.
17. Tras guardar un artículo, el mensaje de éxito muestra el precio con IVA calculado por el
    servidor.
18. Importar un CSV con las 7 columnas da de alta los artículos con su precio calculado; la celda de
    porcentaje vacía hereda del catálogo destino y la que trae valor queda como propia.
19. Exportar genera las mismas 7 columnas, sin columnas calculadas, y el archivo se reimporta sin
    perder el porcentaje propio.
20. Tras la migración, todos los artículos tienen `precio_proveedor` igual a su precio anterior,
    porcentaje heredado y la cadena recalculada; los catálogos quedan en 0% de utilidad.
21. `/catalogos` muestra la utilidad de cada catálogo; su formulario permite capturarla (0% si se
    deja vacía).
22. Pint corre sin cambios, la suite Pest pasa y `node --test "tests/js/*.test.js"` pasa.

## Supuestos asumidos (registro completo)

1. No se crea una entidad nueva: se agregan campos y cálculos a `Articulo` y `Catalogo`, en sus
   pantallas existentes.
2. `precio_proveedor` es el **precio de lista**, *antes* del descuento del catálogo; obligatorio,
   mayor a 0, sin IVA, MXN, 2 decimales.
3. El precio de venta no se captura: se calcula. El usuario captura el porcentaje.
4. El porcentaje vive en el catálogo como valor por defecto y cada artículo puede sobrescribirlo;
   `NULL` en el artículo = hereda, y la herencia es viva.
5. El porcentaje es **markup sobre el costo**, no margen sobre la venta.
6. El descuento del catálogo es un beneficio de compra: se aplica al precio de lista, y el precio de
   venta se calcula sobre el costo ya rebajado.
7. `precio_con_descuento` (008) se renombra a `costo_con_descuento`; ningún otro módulo la usa.
8. Precio de venta con `techo2`; costo con `redondeo2`; ambos sobre centavos.
9. Utilidad en pesos = precio de venta sin IVA − costo con descuento, por pieza; no se persiste.
10. Porcentaje de 0 a 999.99; aviso no bloqueante arriba de 400% (umbral de
    [remotas/032](remotas/032-umbral-aviso-utilidad-alta.md), no el 200% original de 011).
11. **Migración**: el precio actual de cada artículo pasa a ser su precio de lista y los catálogos
    arrancan en 0% de utilidad (igual que la spec remota). Con el catálogo real de 55% de descuento,
    los precios de venta bajan al costo hasta que el usuario capture la utilidad del catálogo. Se
    asume aceptable porque todavía no hay facturación ni cotizaciones que lean esos precios.
12. El recálculo en bloque se dispara con `descuento` y con `utilidad_porcentaje` del catálogo, se
    hace en PHP, incluye los artículos eliminados (como 008) y va precedido de confirmación.
13. El conteo de la confirmación es **exacto** y cuenta solo artículos no eliminados.
14. Mover un artículo de catálogo conserva su porcentaje propio.
15. Valores derivados de solo lectura; lo que el navegador envíe para ellos se ignora en silencio.
16. **(Cambio de arquitectura)** La confirmación del recálculo es un paso del formulario Blade
    (redirección con el conteo y botón "Confirmar y guardar"), no un endpoint JSON más un diálogo.
17. **(Cambio de arquitectura)** La fórmula del navegador vive en `public/js/precio-articulo.js`
    (JavaScript nativo) en lugar de `src/lib/precioArticulo.ts`, y se prueba con `node --test`
    en lugar de Vitest.
18. **(Cambio de arquitectura)** El fixture compartido vive en `tests/Fixtures/` (junto a `constancias/`), no en
    `shared/fixtures/` (ya no hay `backend/` y `frontend/` separados).
19. **(Cambio de arquitectura)** La "verificación del valor autoritativo" de la spec remota (comparar
    en el navegador lo mostrado contra la respuesta JSON) se sustituye por el mensaje de éxito con
    el precio calculado por el servidor: tras guardar hay una redirección y la página siguiente la
    arma el servidor, así que no existe un frontend desplegado desactualizado que detectar.
20. **(Decisión nueva)** El listado agrega solo la columna "Costo" junto al "Precio con IVA" de 007;
    no se agrega utilidad ni se ordena por ella.
21. **(Decisión nueva)** `precio_proveedor` tiene tope de 9,000,000 para que el precio de venta con
    999.99% quepa en `decimal(10,2)`.
22. **(Decisión nueva)** `redondeo2` se redefine sobre centavos (`round(round(v × 100, 6)) / 100`),
    simétrico con `techo2`, porque el recálculo ya no depende del `ROUND` de MySQL.
23. **(Decisión nueva)** Un CSV con el formato anterior (`precio_unitario_sin_iva`) se rechaza por
    columnas faltantes; no se interpreta como precio de lista.
24. Todo en MXN; sin historial de precios ni reportes.
25. Iconos nuevos: `bi-check-lg` ("Confirmar y guardar").
