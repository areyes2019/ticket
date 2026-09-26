# Spec: Precio del proveedor y utilidad (precio de venta calculado por markup)

## Historia de usuario

Como usuario único del sistema de facturación, quiero registrar el precio que me cobra el proveedor
y el porcentaje de utilidad que quiero ganar, para que el sistema calcule solo el precio de venta de
cada artículo y me muestre cuánto dinero me queda de utilidad por pieza, sin tener que sacar la
cuenta a mano cada vez que un proveedor me cambia un precio o me mejora un descuento.

## Objetivo / Alcance

Ampliar el modelo de precios de `Articulo` ([006-gestion-articulos.md](006-gestion-articulos.md)) y
de `Catalogo` ([009-catalogos.md](009-catalogos.md)) para separar **costo** de **precio de venta**.

El usuario captura el **precio de lista del proveedor** y un **porcentaje de utilidad**, y el precio
de venta pasa a ser un valor **calculado** por el sistema. La utilidad en pesos queda visible en el
listado y en el formulario de artículos.

Se implementa sobre la base ya existente de Laravel API + Vue 3 SPA + Sanctum (ver
[001](001-inicio-proyecto.md), [002](002-login-auth.md)) y el design system de
[003](003-design-system-tailwind.md), siguiendo el patrón de 006/009.

**No** incluye reportes de rentabilidad ni ninguna modificación a
[Facturación](007-facturacion.md), [Cotizaciones](008-cotizaciones.md) o
[Tesorería](010-tesoreria.md).

### Cadena de cálculo

Con precio de lista $200.00, descuento de catálogo 10% y utilidad 25%:

```
precio_proveedor          (capturado)              $200.00
  ↓ × (1 − descuento / 100)                        descuento del catálogo (10%)
costo_con_descuento       (calculado, persistido)  $180.00
  ↓ × (1 + utilidad_porcentaje / 100)              markup sobre el costo (25%)
precio_unitario_sin_iva   (calculado, persistido)  $225.00
  ↓ × 1.16                                         IVA general (006)
precio_unitario_con_iva   (calculado al leer)      $261.00

utilidad = precio_unitario_sin_iva − costo_con_descuento = $45.00
```

El porcentaje se interpreta como **markup sobre el costo**: un 25% significa "quiero ganar el 25%
de lo que me costó", de ahí la multiplicación. Con costo $100.00 y 25% el precio de venta es
$125.00 y la utilidad $25.00.

## Backend (Laravel)

### Cambios sobre `Catalogo` (extiende 009)

- **Nueva columna `utilidad_porcentaje`**: decimal(5,2), **obligatoria**, con **valor por defecto de
  0** si no se especifica (mismo patrón que `descuento` en 009). Es el porcentaje de utilidad que
  heredan por defecto todos los artículos del catálogo.
- **Disparadores de recálculo en bloque**: al editar un catálogo, **tanto `descuento` como
  `utilidad_porcentaje`** disparan el recálculo de sus artículos.
  - Un cambio de `descuento` mueve el precio de **todos** los artículos del catálogo, incluidos los
    que tienen porcentaje propio, porque cambia el costo del que parten.
  - Un cambio de `utilidad_porcentaje` mueve el precio **solo de los artículos que heredan** el
    porcentaje (los que tienen `utilidad_porcentaje` en `NULL`).
  - El recálculo **se hace en PHP**, recorriendo los artículos afectados y llamando a
    `PrecioArticuloCalculator`, **no** mediante una actualización masiva vía query. El techo a 2
    decimales no es portable entre MySQL y SQLite, y duplicar la fórmula en SQL abriría una tercera
    copia de la lógica de precios (ver "Fuente de verdad única de la fórmula").
- **Endpoint de previsualización de impacto**:
  `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios?descuento=&utilidad_porcentaje=`
  — recibe los valores que el usuario está por guardar (ambos opcionales; los ausentes se toman de
  los valores actuales del catálogo) y responde `{ "articulos_afectados": <int> }` con el conteo
  **exacto** de artículos cuyo precio de venta cambiaría, aplicando la regla de arriba. Alimenta el
  diálogo de confirmación del frontend.

### Cambios sobre `Articulo` (extiende 006 y 009)

- **Nueva columna `precio_proveedor`**: decimal(10,2), **obligatoria**, mayor a 0, en pesos
  mexicanos (MXN), **sin IVA**. Es el **precio de lista** del proveedor, es decir, el precio *antes*
  de aplicar el descuento del catálogo.
- **Nueva columna `utilidad_porcentaje`**: decimal(5,2), **nullable**. `NULL` significa "hereda el
  porcentaje del catálogo"; un valor significa "este artículo usa su propio porcentaje". La herencia
  es **viva**: cambiar el porcentaje del catálogo mueve a todos sus artículos en `NULL` y respeta a
  los que tienen valor propio.
- **`precio_con_descuento` se renombra a `costo_con_descuento`** y cambia de significado: deja de
  ser "precio de venta con descuento" y pasa a ser el **costo real** del artículo. Se calcula como
  `redondeo2(precio_proveedor × (1 − catalogo.descuento / 100))` y **se sigue persistiendo**.
- **`precio_unitario_sin_iva` no es un campo de entrada**: es **calculado y persistido** como
  `techo2(costo_con_descuento × (1 + utilidad_efectiva / 100))`, donde
  `utilidad_efectiva = articulo.utilidad_porcentaje ?? catalogo.utilidad_porcentaje`. La columna
  **no cambia de nombre ni de tipo**, para no tocar a Facturación ni a Cotizaciones, que la leen al
  precargar líneas.
- **`utilidad` (monto)**: `precio_unitario_sin_iva − costo_con_descuento`, siempre sin IVA. **No se
  persiste**: es una resta de dos columnas y se expone como atributo calculado en el Resource,
  igual que `precio_unitario_con_iva`.
- **`precio_unitario_con_iva`** no cambia: sigue siendo el accessor de 006 sobre
  `precio_unitario_sin_iva`, a la tasa general del 16%.

#### Redondeo

Dos redondeos distintos y deliberados:

- **`costo_con_descuento`**: redondeo matemático estándar a 2 decimales (`redondeo2`), igual que en
  009.
- **`precio_unitario_sin_iva`**: redondeo **hacia arriba** a 2 decimales (`techo2`), para que el
  precio de venta nunca quede por debajo del markup solicitado. Con costo $100.01 y 33%, el valor
  crudo es $133.0133 y el precio de venta es **$133.02**, no $133.01.

`techo2` se implementa como:

```
techo2(v) = ceil(round(v × 100, 6)) / 100
```

El redondeo intermedio va **después** de multiplicar por 100, no antes. Ese orden es la parte
sustancial de la definición y no es intercambiable:

- Un techo ingenuo `ceil(v × 100) / 100` falla porque el producto `costo × (1 + % / 100)` arrastra
  error de representación en punto flotante.
- Absorber el error antes de escalar, `ceil(round(v, 4) × 100) / 100`, tampoco sirve: corrige `v`
  pero vuelve a introducir error en la multiplicación por 100 (`0.07 × 100 = 7.000000000000001`),
  y termina cobrando un centavo de más. Con costo $15.40 y 5% el valor exacto es **$16.17**, y esa
  variante devuelve $16.18.
- Redondear después de escalar elimina ambas fuentes de error, porque el redondeo a 6 decimales
  actúa sobre el valor ya expresado en centavos.

La definición está verificada contra una referencia en **aritmética entera de centavos** sobre 4.2
millones de combinaciones de costo y porcentaje, con cero desviaciones. Es la misma familia de
trampa que el bug de división entera en SQLite documentado en [009](009-catalogos.md)
(`descuento / 100` truncaba a cero), por lo que se cubre con una suite de tests de casos frontera
dedicada (ver "Tests").

#### Migración de esquema y de datos

En un solo cambio:

1. `catalogos` gana `utilidad_porcentaje` decimal(5,2) con default 0. Todos los catálogos existentes
   (incluido el "General" que generó 009) quedan en **0%**.
2. `articulos` gana `precio_proveedor` decimal(10,2) y `utilidad_porcentaje` decimal(5,2) nullable.
3. `articulos.precio_con_descuento` se renombra a `costo_con_descuento`.
4. Cada artículo existente toma su `precio_unitario_sin_iva` actual como `precio_proveedor` (es
   decir, su precio actual pasa a interpretarse como precio de lista del proveedor) y queda con
   `utilidad_porcentaje` en `NULL`.
5. Se recalcula la cadena completa hacia adelante para todos los artículos existentes, en PHP y con
   `PrecioArticuloCalculator`, de modo que el resultado sea idéntico al de cualquier otro camino de
   la aplicación. El recálculo es **determinista y sin pérdida**: `precio_proveedor` y
   `utilidad_porcentaje` son entradas capturadas que no se tocan, y todo lo demás se deriva de
   ellas, por lo que la migración es idempotente y puede volver a ejecutarse tras cualquier ajuste
   de la fórmula o del redondeo.

Los artículos actualmente en base de datos son datos de ejemplo, por lo que ese recálculo no
representa una pérdida de información de negocio.

### Endpoints

Sin rutas nuevas de `Articulo`. Cambios sobre las existentes:

- `GET /api/v1/articulos` — acepta los parámetros de ordenación `?sort=` y `?direction=` (ver
  Frontend). `?sort=` acepta `costo_con_descuento`, `precio_unitario_sin_iva` y `utilidad`;
  `?direction=` acepta `asc` y `desc` (default `asc`). Ordenar por `utilidad` se traduce a un
  `ORDER BY` sobre la expresión `precio_unitario_sin_iva - costo_con_descuento`, ya que la utilidad
  no se persiste. Un `sort` no reconocido se ignora y se cae al orden por defecto por `nombre`.
- `POST` / `PUT /api/v1/articulos[/{id}]` — aceptan `precio_proveedor` (obligatorio) y
  `utilidad_porcentaje` (opcional); **no aceptan** `precio_unitario_sin_iva`. La respuesta devuelve
  el `ArticuloResource` con la cadena ya calculada por el servidor (ver "Verificación del valor
  autoritativo").
- `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv` — mismas columnas del CSV
  (ver abajo), mismo formato de reporte por fila que 006/009.
- `GET /api/v1/articulos/exportar-csv` — mismas columnas del CSV.
- `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios` — descrito arriba.

### Columnas CSV

Idénticas en importación y exportación (se mantiene el principio de 006: un CSV exportado, editado,
es directamente reimportable). `precio_unitario_sin_iva` **no** figura por ser un valor calculado:

```
nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje
```

- `precio_proveedor` es obligatorio en cada fila.
- `utilidad_porcentaje` es **opcional**: celda vacía significa "hereda el porcentaje del catálogo
  destino". Una celda con valor se guarda como porcentaje propio del artículo, sujeta a las mismas
  reglas de validación que el alta individual.
- Los valores calculados (costo con descuento, precio de venta, utilidad, precio con IVA) **no
  viajan** en el CSV en ninguna dirección.

### Validaciones (Form Requests)

- `precio_proveedor`: requerido, numérico, **mayor a 0**, máximo 2 decimales.
- `utilidad_porcentaje` en `Articulo`: **nullable**, numérico, **mayor o igual a 0 y menor o igual a
  999.99**, máximo 2 decimales.
- `utilidad_porcentaje` en `Catalogo`: requerido (con default `0` si se omite en la petición),
  numérico, **mayor o igual a 0 y menor o igual a 999.99**, máximo 2 decimales.
- El tope de 999.99 es el que permite la columna `decimal(5,2)`. No hay límite artificial por debajo
  de ese valor: el markup no tiene singularidad matemática y un porcentaje de tres dígitos es
  legítimo en compra-venta.
- Se permite 0% (vender exactamente a costo, sin ganancia). **No** se aceptan porcentajes negativos:
  la utilidad nunca puede ser negativa por captura.
- `precio_unitario_sin_iva`, `costo_con_descuento` y `utilidad` **no forman parte de las reglas de
  validación**: cualquier valor que un cliente envíe para ellos se **ignora en silencio**, mismo
  patrón que ya usa `precio_con_descuento` en 009.
- Fila de importación CSV: mismas reglas que el alta individual, aplicadas por fila.
- El cálculo de la cadena vive en `PrecioArticuloCalculator`, compartido por `store`, `update`,
  `importarCsv` y el recálculo de `Catalogo`, no en los Form Requests, siguiendo la decisión ya
  tomada en 009.

### `ArticuloResource`

Expone `precio_proveedor`, `utilidad_porcentaje` (el propio del artículo, `null` si hereda),
`utilidad_porcentaje_efectivo` (el que se usó realmente para calcular), `utilidad` (monto) y
`costo_con_descuento`. Conserva `precio_unitario_sin_iva` y `precio_unitario_con_iva` sin cambios de
nombre.

### Fuente de verdad única de la fórmula

La cadena de cálculo se ejecuta en dos lugares por necesidad: en PHP, que es quien persiste, y en
TypeScript, que alimenta el resumen en vivo del formulario sin depender de la red. Que existan dos
implementaciones es aceptable; que puedan divergir en silencio, no.

- **Fixture compartido**: los casos de la cadena viven en un único archivo
  `shared/fixtures/precios-articulos.json` en la raíz del repositorio, fuera de `backend/` y de
  `frontend/`. Cada caso declara precio de lista, descuento, porcentaje de utilidad y los tres
  resultados esperados (costo, precio de venta, utilidad).
- **Ambas suites lo consumen**: PHPUnit y Vitest leen ese mismo archivo por ruta relativa y recorren
  todos sus casos. Cambiar una fórmula sin cambiar la otra rompe la suite del lado que no se tocó,
  que es la señal que hoy no existe.
- El fixture es la definición ejecutable de la cadena; esta spec es su descripción.

### Verificación del valor autoritativo

El formulario compara el resultado de su cálculo local contra los valores que devuelve la respuesta
del `POST`/`PUT`. Si `costo_con_descuento`, `precio_unitario_sin_iva` o `utilidad` no coinciden con
lo que mostró en pantalla, **no navega en silencio**: presenta un `Alert` con el valor real que
quedó guardado.

No se agrega un endpoint de previsualización de precio: la respuesta del guardado ya trae la cadena
calculada por el servidor, y el fixture compartido cubre la divergencia en tiempo de test. Esta
verificación es la red para el único caso que los tests no pueden ver — un frontend desplegado
desactualizado contra un backend ya actualizado — y en operación normal nunca debe activarse.

### Tests

- Suite dedicada de **casos frontera del redondeo**, alimentada por el fixture compartido y
  corriendo tanto en **SQLite** (tests) como en **MySQL** (entorno real): valores que deben dar un
  resultado exacto (costo $15.40 al 5% → $16.17, no $16.18), el techo real frente al redondeo
  estándar (costo $100.01 al 33% → $133.02, no $133.01), 0% de utilidad, porcentajes de tres
  dígitos, costos con muchos decimales, y descuentos que producen costos no redondos.
- Batería de la **cadena completa**: herencia del porcentaje desde el catálogo, sobrescritura por
  artículo, conservación del porcentaje propio al mover el artículo de catálogo, recálculo por
  cambio de `descuento`, recálculo por cambio de `utilidad_porcentaje` (que debe respetar a los
  artículos con porcentaje propio), y el endpoint de previsualización de impacto.
- Cobertura del **CSV**: importar una fila con `utilidad_porcentaje` vacío hereda del catálogo
  destino; importar una fila con valor lo guarda como porcentaje propio; exportar produce las 7
  columnas y el archivo resultante es reimportable sin pérdida.
- Cobertura de la **ordenación** de `GET /api/v1/articulos` por las tres columnas numéricas, en
  ambas direcciones, incluyendo el caso de `sort` no reconocido.
- `ArticuloFactory` recibe `precio_proveedor` y `utilidad_porcentaje`, y **deriva** el resto de la
  cadena.
- Los tests de [007](007-facturacion.md), [008](008-cotizaciones.md) y [009](009-catalogos.md) que
  crean artículos expresan su intención en términos de costo y markup, no de un precio de venta
  capturado.

## Frontend (Vue 3)

### Módulo de cálculo compartido

`src/lib/precioArticulo.ts` implementa `redondeo2`, `techo2`, `costoConDescuento`,
`precioVentaSinIva` y `utilidad` como funciones puras sobre números, espejo exacto de
`PrecioArticuloCalculator`. Ninguna vista calcula precios por su cuenta: todas pasan por este
módulo.

Se introduce **Vitest** como primera capa de pruebas del frontend, con un script `npm test`. El
alcance es deliberadamente mínimo: sin `jsdom` y sin `@vue/test-utils`, porque el módulo es
aritmética pura y no necesita DOM ni montar componentes. El único archivo de test recorre el fixture
compartido descrito arriba.

### `/articulos` (listado)

- **Columnas**: nombre, modelo, **costo con descuento** y **precio de venta**. El precio de lista
  del proveedor, el porcentaje y la **utilidad en pesos** quedan solo en el formulario, donde salen
  con la cadena de cálculo completa que los explica. Es lo que evita revivir el desborde de tabla
  corregido en 006 el 2026-08-03, y por lo que la tabla volvió a acortarse en
  [025](025-filtros-columna-listado-articulos.md) el 2026-08-19, cuando el catálogo y la utilidad
  perdieron su columna.
- **Ordenación por columna numérica**: costo con descuento y precio de venta son ordenables
  (ascendente/descendente) haciendo clic en su encabezado, alimentando `?sort=` y `?direction=`. Las
  columnas de texto no son ordenables. El servidor también sabe ordenar por utilidad, pero sin
  columna no hay dónde pedirlo desde la pantalla.
- Las celdas de texto mantienen el truncado con elipsis y `title` de 006.
- El buscador `?search=` no cambia.

### `/articulos/crear` y `/articulos/:id/editar`

- Se captura **`precio_proveedor`** (`Input` numérico, obligatorio) en lugar del precio de venta.
- Se captura **`utilidad_porcentaje`** (`Input` numérico, opcional). Cuando está vacío, el campo
  muestra como *placeholder* el porcentaje heredado del catálogo seleccionado, dejando claro qué
  valor se va a aplicar.
- **`precio_unitario_sin_iva` no es editable**: es un valor mostrado de solo lectura dentro del
  bloque de resumen.
- **Bloque de resumen de la cadena de cálculo**, siempre visible y actualizándose en vivo conforme
  se captura (y también al cambiar de catálogo, porque cambian descuento y porcentaje heredado):

  ```
  Precio de lista del proveedor      $200.00
  Descuento del catálogo (10%)      −$20.00
  Costo                              $180.00
  Utilidad (25%)                     +$45.00
  Precio de venta sin IVA            $225.00
  IVA (16%)                          +$36.00
  Precio de venta con IVA            $261.00
  ```

- **Advertencia de porcentaje alto**: al superar el 400% el formulario muestra un aviso visual **no
  bloqueante** junto al campo, para que un dedazo del tipo "1000" en vez de "100" salte a la vista
  sin impedir un markup legítimamente alto. El umbral vive en `lib/precioArticulo.ts` (ver
  [032](032-umbral-aviso-utilidad-alta.md)).
- Los mensajes de error de validación por campo siguen el patrón de 006 (`Input`/`Alert`),
  incluyendo el rango del porcentaje (0 a 999.99) y el precio del proveedor mayor a 0.

### `/catalogos/crear` y `/catalogos/:id/editar`

- Campo **`utilidad_porcentaje`** (`Input` numérico, precargado en `0`, editable), junto al
  `descuento` ya existente, con la misma advertencia no bloqueante sobre 400%.
- **Diálogo de confirmación antes de guardar** cuando cambia `descuento` o `utilidad_porcentaje` en
  una edición: antes de enviar el `PUT`, el formulario consulta
  `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios` con los valores nuevos y muestra el
  conteo exacto ("Se recalculará el precio de venta de N artículos"). Confirmar envía el `PUT`;
  cancelar regresa al formulario sin guardar. En el alta de un catálogo nuevo no aplica (no tiene
  artículos).
- `/catalogos` (listado) muestra el porcentaje de utilidad junto al descuento.

### Importar CSV

El modal de importación de `/articulos` no cambia de flujo (seleccionar catálogo destino + archivo,
con reporte de errores por fila); su descripción lista las 7 columnas, respetando la regla de
`Dialog` con contenido dinámico de [003](003-design-system-tailwind.md) (bloque `<code>` propio con
`overflow-x-auto`).

### Coherencia entre despliegues

Los assets ya salen con hash de contenido y `dist/` se vacía en cada build, así que un chunk viejo
no puede cargarse en silencio; lo que sí puede quedar pegado es `index.html`, apuntando a chunks que
ya no existen. Se cierra por dos vías:

- **Cabeceras de caché**: `no-cache, must-revalidate` para `index.html`, e `immutable` con caducidad
  larga para `/assets/*` (seguro, porque van con hash de contenido).
- **Recarga ante chunk faltante**: un manejador del evento `vite:preloadError` fuerza
  `location.reload()` cuando falla la carga de un chunk diferido, de modo que una pestaña abierta
  durante un despliegue se recupera sola en vez de quedar rota.

## Fuera de alcance

- **Reportes de rentabilidad agregados** (cuánto gané/perdí hoy, esta semana, este mes; rentabilidad
  por catálogo o proveedor). Sigue sin existir una pantalla ni un endpoint que sume utilidad por
  periodo: [010](010-tesoreria.md) sí resuelve el caso más acotado de "cuánto gané en esta venta",
  mostrando la utilidad de cada cotización o pedido cobrado en su propio movimiento de Tesorería, con
  el costo que sus líneas guardan desde esa historia — pero no la agrega ni la grafica.
- Cualquier modificación a [Facturación](007-facturacion.md). Las líneas de factura siguen guardando
  su propia copia de `precio_unitario` (desacoplada del catálogo) y **no** guardan costo ni utilidad,
  porque Facturación no genera movimientos en Tesorería y no lo necesita.
- Mostrar la utilidad al armar una cotización, un pedido o una factura mientras se captura — esta
  historia solo calcula el precio de venta en vivo; la utilidad de la venta ya cobrada se consulta
  después, en Tesorería ([010](010-tesoreria.md)).
- **Precio calculado por margen sobre la venta** (`costo ÷ (1 − % / 100)`), y cualquier selector que
  permita elegir entre markup y margen por catálogo o por artículo. El porcentaje siempre es markup
  sobre el costo.
- **Campos personalizados definibles por el usuario** (un constructor de atributos dinámicos por
  giro de negocio). Los campos de esta historia son fijos para todos los artículos.
- **Modo manual de precio**: no existe una casilla que congele un precio de venta capturado a mano
  ignorando el porcentaje. El precio de venta siempre es calculado.
- **Utilidad negativa** (vender por debajo del costo): el porcentaje no admite valores negativos.
- **Historial** de cambios de precio, costo o porcentaje, ni registro de los valores previos a un
  recálculo. `updated_at` es la única referencia temporal.
- **Multi-moneda y tipo de cambio**: todo en MXN. Un costo en dólares se captura ya convertido a
  pesos por el usuario.
- Cálculo del porcentaje a partir de un precio de venta objetivo (el sentido inverso al de esta
  historia).
- Márgenes mínimos obligatorios, umbrales de bloqueo de guardado por margen bajo, o cualquier
  validación que impida guardar por el valor del porcentaje dentro del rango permitido.
- Descuentos variables por artículo dentro de un mismo catálogo (sigue vigente lo definido en 009:
  el descuento es uniforme por catálogo; lo que sí varía por artículo es la **utilidad**).
- Ordenación por columnas de texto en `/articulos`, y ordenación en el resto de los listados de la
  app (Clientes, Proveedores, Catálogos, Facturas, Cotizaciones).
- Tests de componentes o de punta a punta en el frontend. Vitest entra solo para el módulo de
  cálculo puro.
- Inventario/existencias, y por lo tanto utilidad total por unidades en stock.

## Criterios de aceptación

1. Un usuario autenticado puede crear un artículo capturando el precio de lista del proveedor
   (obligatorio, mayor a 0) y, opcionalmente, un porcentaje de utilidad propio; el precio de venta
   no se captura.
2. Capturar un precio del proveedor menor o igual a 0 muestra un error de validación y no permite
   guardar.
3. Capturar un porcentaje de utilidad negativo o mayor a 999.99 muestra un error de validación y no
   permite guardar; 0 y los porcentajes de tres dígitos dentro del rango sí se aceptan.
4. Al guardar un artículo, el sistema calcula y persiste el costo con descuento (precio de lista
   menos el descuento del catálogo) y el precio de venta sin IVA (costo **multiplicado** por uno más
   el porcentaje de utilidad), y muestra la utilidad en pesos como la diferencia entre ambos. Un
   precio de lista de $347.27 en un catálogo con 55% de descuento y 99% de utilidad produce costo
   $156.27, precio de venta $310.98 y utilidad $154.71.
5. El precio de venta se redondea **hacia arriba** a 2 decimales; un costo de $100.01 con 33% de
   utilidad produce exactamente $133.02, no $133.01.
6. Un costo de $15.40 con 5% de utilidad produce exactamente **$16.17**, no $16.18 (caso frontera de
   punto flotante), tanto en SQLite como en MySQL.
7. El mismo juego de casos frontera produce resultados idénticos en el backend y en el módulo de
   cálculo del frontend, verificado por ambas suites contra el fixture compartido.
8. Un artículo sin porcentaje propio hereda el del catálogo; el formulario muestra el valor heredado
   como referencia mientras el campo está vacío.
9. Cambiar el descuento de un catálogo recalcula el costo y el precio de venta de **todos** sus
   artículos, incluidos los que tienen porcentaje propio.
10. Cambiar el porcentaje de utilidad de un catálogo recalcula el precio de venta **solo** de los
    artículos que heredan el porcentaje; los que tienen porcentaje propio conservan su precio.
11. Antes de aplicar cualquiera de esos dos recálculos, el sistema muestra un diálogo de
    confirmación con el número **exacto** de artículos cuyo precio de venta va a cambiar; cancelar
    no guarda nada.
12. Mover un artículo que tiene porcentaje propio a otro catálogo conserva ese porcentaje propio y
    recalcula su precio con el descuento del catálogo destino.
13. Enviar `precio_unitario_sin_iva`, `costo_con_descuento` o `utilidad` en un `POST`/`PUT` de
    artículo no produce error, pero el valor enviado se ignora por completo.
14. El listado `/articulos` muestra nombre, modelo, catálogo, costo con descuento, precio de venta y
    utilidad en pesos, y permite ordenar ascendente y descendentemente por cada una de las tres
    columnas numéricas.
15. El formulario de artículo muestra en vivo la cadena completa de cálculo (precio de lista →
    descuento → costo → utilidad → precio de venta → IVA → precio final), actualizándose al cambiar
    cualquier campo capturado y también al cambiar de catálogo.
16. Capturar un porcentaje mayor a 400 muestra una advertencia visual que **no** impide guardar.
17. Si el precio que devuelve el servidor al guardar no coincide con el que mostró el formulario, la
    aplicación lo advierte con el valor real en vez de navegar en silencio.
18. Importar un CSV con las columnas
    `nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje` da
    de alta los artículos con su precio de venta ya calculado; las filas con la celda de porcentaje
    vacía heredan el del catálogo destino y las que traen valor lo guardan como porcentaje propio.
19. Exportar el listado genera un CSV con esas mismas 7 columnas, sin columnas calculadas, y ese
    archivo es directamente reimportable sin pérdida del porcentaje por artículo.
20. Tras la migración, los artículos existentes conservan su precio anterior como precio de lista
    del proveedor, quedan con porcentaje heredado y con toda su cadena recalculada; ningún artículo
    queda sin `precio_proveedor`.
21. Facturación y Cotizaciones siguen precargando líneas con el precio de venta del artículo, sin
    cambios de comportamiento respecto a 007/008.
22. Pint, ESLint/Prettier y las suites de PHPUnit y Vitest corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. La ampliación no crea una entidad nueva: agrega campos y cálculos a `Articulo` y `Catalogo`, y
   todo vive en las pantallas de Artículos y Catálogos que ya existen. No hay pantalla nueva de
   "Lista de precios": la lista de precios es el listado `/articulos`.
2. "Campos personalizados" significa **campos fijos nuevos** propios de un negocio de compra-venta
   (precio del proveedor y utilidad), **no** un motor de campos dinámicos definibles por el usuario.
3. El mecanismo de descuento por catálogo de 009 ya funciona; lo que falta es el precio del
   proveedor, la utilidad, y volver a mostrar el precio con descuento en el listado (se había
   quitado de la tabla en 006 el 2026-08-03 al corregir el desborde).
4. `precio_proveedor` es obligatorio y mayor a 0, sin IVA, en MXN, con 2 decimales. No se admite dar
   de alta un artículo sin conocer su costo.
5. `precio_proveedor` es el **precio de lista** del proveedor, *antes* del descuento del catálogo.
   El sistema deriva el costo real aplicándole ese descuento.
6. `precio_unitario_sin_iva` no se captura: se **calcula** a partir del costo con descuento y un
   porcentaje de utilidad. El dato que el usuario captura es el porcentaje; el precio es el
   resultado.
7. El porcentaje de utilidad vive en el `Catalogo` como valor por defecto, y cada `Articulo` puede
   sobrescribirlo con el suyo propio. Si el artículo no define ninguno, hereda el del catálogo, y la
   herencia es viva.
8. El porcentaje se interpreta como **markup sobre el costo** (`venta = costo × (1 + % / 100)`), no
   como margen sobre la venta. Con costo $100 y 25%, el precio de venta es $125.00 y la utilidad
   $25.00. Es la lectura que corresponde a cómo el usuario razona sus precios: "cuánto le gano a lo
   que me costó".
9. El descuento del catálogo se aplica sobre el precio del proveedor, no sobre el precio de venta:
   es un beneficio de compra. El precio de venta se calcula sobre el costo ya rebajado, por lo que
   un mejor descuento se traduce en un precio de venta más bajo al mismo porcentaje de utilidad.
10. La columna `precio_con_descuento` de 009 cambia de significado (pasa a ser el costo con
    descuento) y por eso se **renombra a `costo_con_descuento`**. El rename está contenido: ningún
    archivo de Facturación ni de Cotizaciones la usa.
11. Los artículos existentes toman su `precio_unitario_sin_iva` actual como `precio_proveedor` y se
    recalcula toda la cadena hacia adelante; sus precios de venta cambiarán. Los datos actuales en
    base son de ejemplo, por lo que no hay pérdida real de información.
12. El precio de venta se redondea **hacia arriba** a 2 decimales, para no quedar nunca por debajo
    del markup solicitado. El costo con descuento usa redondeo estándar a 2 decimales, como en 009.
13. La utilidad en pesos es `precio de venta − costo con descuento`, por unidad y sin IVA: se mide
    contra lo que efectivamente pagas, no contra el precio de lista.
14. En pantalla se muestra el **porcentaje capturado** tal cual; no se muestra el porcentaje
    efectivo recalculado desde los montos redondeados ni el margen equivalente sobre la venta.
15. Todos los valores derivados son de solo lectura y cualquier valor que se envíe para ellos se
    **ignora en silencio**, mismo patrón que `precio_con_descuento` en 009. No existe un "modo
    manual" que congele el precio de venta.
16. El porcentaje de utilidad va de 0 a 999.99, el tope natural de una columna `decimal(5,2)`. Se
    permite vender exactamente a costo (0%); no se permiten porcentajes negativos, por lo que la
    utilidad nunca es negativa por captura. Por encima de 400% hay una advertencia visual, pero no
    un bloqueo: el límite es de captura errónea, no de negocio.
17. El recálculo en bloque se dispara con `descuento` **y** con `utilidad_porcentaje` del catálogo,
    y va **precedido de un diálogo de confirmación** que indica cuántos artículos van a cambiar de
    precio.
18. El listado `/articulos` muestra nombre, modelo, costo con descuento y precio de venta. El precio
    de lista, el porcentaje y la utilidad en pesos quedan solo en el formulario, para no revivir el
    desborde de tabla corregido en 006.
19. El formulario de artículo muestra la **cadena de cálculo completa**, siempre visible y en vivo.
20. Al mover un artículo de catálogo, **conserva su porcentaje propio** si lo tiene; solo cambia su
    costo, porque cambia el descuento aplicable.
21. El CSV usa `precio_proveedor` en lugar de `precio_unitario_sin_iva` y agrega
    `utilidad_porcentaje` **opcional** (celda vacía = hereda del catálogo destino). Importación y
    exportación usan exactamente las mismas 7 columnas, y los valores calculados no viajan en el
    archivo.
22. **(Redefinido por [010](010-tesoreria.md))** Facturación y Cotizaciones no se tocan en esta
    historia (011); no se guarda utilidad ni costo por documento emitido aquí. 010 sí extiende
    Cotizaciones y Pedidos para guardar el costo por línea y mostrar la utilidad de la venta ya
    cobrada en Tesorería — Facturación queda fuera también de ese alcance, porque no genera
    movimientos.
23. **(Redefinido por [010](010-tesoreria.md))** No hay reportes agregados en esta historia.
    Responder "¿cuánto gané en esta cotización o pedido?" ya se resuelve en 010, movimiento por
    movimiento; "¿cuánto gané hoy/esta semana/este mes en total?" y "¿de cuánto dinero puedo
    disponer?" siguen sin pantalla propia — quedan como una historia futura si hace falta agregarlos
    o graficarlos.
24. No hay historial de cambios de precio, costo ni porcentaje, ni posibilidad de revertir un
    recálculo.
25. Todo en MXN; no hay moneda del proveedor ni tipo de cambio.
26. Los catálogos existentes arrancan con 0% de utilidad tras la migración (mismo patrón que el
    `descuento` con default 0 en 009); los porcentajes reales se capturan manualmente después.
27. **(Adición técnica)** Se persisten `costo_con_descuento` y `precio_unitario_sin_iva` en
    columnas; la utilidad en pesos se calcula al leer, por ser una resta de dos columnas. Esto
    mantiene funcionando sin cambios lo que 007/008 ya leen de la columna del precio de venta.
28. **(Adición técnica)** "Hereda el porcentaje del catálogo" se representa con
    `articulos.utilidad_porcentaje` **nullable**, donde `NULL` = hereda, sin columna booleana
    adicional.
29. **(Adición técnica)** El techo a 2 decimales redondea **después** de escalar a centavos
    (`ceil(round(v × 100, 6)) / 100`). Absorber el error antes de escalar no basta, porque la propia
    multiplicación por 100 reintroduce error de punto flotante. La definición está verificada contra
    aritmética entera de centavos sobre 4.2 millones de combinaciones, con cero desviaciones, y se
    cubre con una suite de casos frontera corriendo en SQLite y en MySQL.
30. **(Adición técnica)** El recálculo por cambio de `descuento` o `utilidad_porcentaje` se hace en
    PHP recorriendo los artículos afectados, no con una actualización masiva vía query: el techo a 2
    decimales no es portable entre MySQL y SQLite, y una versión SQL de la fórmula sería una tercera
    copia de la lógica de precios que mantener sincronizada.
31. **(Adición técnica)** El conteo del diálogo de confirmación viene de un **endpoint de
    previsualización** (`GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios`) que recibe los
    valores por guardar y devuelve el conteo exacto, en vez de reusar el total de artículos del
    catálogo (que sería un número inflado cuando solo cambia el porcentaje).
32. **(Adición técnica)** La cadena se implementa dos veces, en PHP y en TypeScript, atadas por un
    **fixture de casos compartido** (`shared/fixtures/precios-articulos.json`) que consumen PHPUnit y
    Vitest. Cambiar una implementación sin la otra rompe la suite del lado no tocado. El frontend
    calcula en local para que el resumen en vivo no dependa de la red.
33. **(Adición técnica)** Vitest entra como primera capa de pruebas del frontend, con alcance
    mínimo: solo el módulo de cálculo puro, sin `jsdom` ni `@vue/test-utils`, porque es aritmética
    sobre números y no necesita DOM.
34. **(Adición técnica)** No se agrega un endpoint de previsualización de precio: la respuesta del
    `POST`/`PUT` ya trae la cadena calculada por el servidor, y el formulario la compara contra lo
    que mostró. Esa comparación es la red para el frontend desactualizado, escenario que los tests no
    pueden observar.
35. **(Adición técnica)** Ese escenario se previene además en la capa de despliegue: `index.html` se
    sirve con `no-cache, must-revalidate` y `/assets/*` con `immutable`, y un manejador de
    `vite:preloadError` recarga la página cuando un chunk diferido ya no existe. La comparación del
    supuesto 34 queda como última red, no como mecanismo operativo.
36. **(Adición técnica)** `ArticuloFactory` recibe precio de lista y porcentaje y deriva el resto de
    la cadena; los tests de 007/008/009 que crean artículos se expresan en términos de costo y
    markup, y existe una batería que cubre la cadena completa.
37. **(Adición técnica)** El listado `/articulos` ordena por sus columnas de dinero vía `?sort=` y
    `?direction=`. El servidor entiende las tres —costo con descuento, precio de venta y utilidad—,
    y ordenar por utilidad se traduce a un `ORDER BY` sobre la expresión
    `precio_unitario_sin_iva - costo_con_descuento`, ya que no está persistida; desde
    [025](025-filtros-columna-listado-articulos.md) la pantalla solo expone las dos primeras. No se
    extiende la ordenación al resto de los listados de la app.
