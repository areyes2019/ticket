# Spec: Costo de elaboración de goma por tamaño

**Alcance:** Nicho — taller de sellos de goma. Extiende [011](011-precio-proveedor-utilidad.md).

## Historia de usuario

Como usuario único del sistema de facturación, quiero que el costo de la goma que elaboro yo mismo
se sume automáticamente al costo del aparato que le compro al proveedor, eligiendo un tamaño de una
lista corta, para que el costo y la utilidad que me muestra el sistema sean los reales y no se me
escape dinero por un insumo que hoy no aparece en ninguna cuenta.

## Objetivo / Alcance

Ampliar la cadena de precios de [011](011-precio-proveedor-utilidad.md) con un **costo de insumo
fijo por categoría de tamaño**, elegido en la ficha de cada artículo y sumado al costo antes de
aplicar el markup.

Para sostenerlo nace la **primera pantalla de configuración global** del sistema: un almacén de
ajustes clave→valor por usuario, donde viven los costos de los cuatro tamaños y donde caben los
ajustes generales que hoy están escritos a mano en el código (la tasa de IVA del 16%, los datos
fiscales del emisor, el folio inicial de facturación) cuando les toque su propia historia.

Se implementa sobre la base ya existente de Laravel API + Vue 3 SPA + Sanctum (ver
[001](001-inicio-proyecto.md), [002](002-login-auth.md)) y el design system de
[003](003-design-system-tailwind.md), siguiendo el patrón de 006/009/011.

**No** incluye inventario de insumos, ni modificación alguna a [Facturación](007-facturacion.md),
[Cotizaciones](008-cotizaciones.md), [Tesorería](010-tesoreria.md) u
[Órdenes de compra](012-ordenes-compra.md).

### Sobre el vocabulario de esta spec

El mecanismo que se define aquí es genérico: *un costo de insumo fijo, elegido de una lista cerrada
de categorías, sumado al costo del artículo antes del markup*. Lo específico del taller de sellos
son tres cosas y solo tres: la palabra "goma", los nombres Chica/Mediana/Grande/Jumbo, y los montos
$6.00 / $10.00 / $20.00 / $40.00.

La spec se redacta con el vocabulario del taller porque es el que el usuario habla y el que va a
leer en pantalla. Quien quiera levantar esta funcionalidad para otro giro reemplaza esas tres cosas
y no toca la cadena de cálculo, el almacén de configuración ni el recálculo en bloque.

### Cadena de cálculo

La cadena de 011 gana un eslabón. Con precio de lista $200.00, descuento de catálogo 0%, goma
mediana y utilidad 25%:

```
precio_proveedor          (capturado)              $200.00
  ↓ × (1 − descuento / 100)                        descuento del catálogo (0%)
costo_con_descuento       (calculado, persistido)  $200.00   ← costo del aparato
  ↓ + costo_goma                                   goma mediana
costo_total               (calculado al leer)      $210.00
  ↓ × (1 + utilidad_efectiva / 100)                markup sobre el costo (25%)
precio_unitario_sin_iva   (calculado, persistido)  $262.50
  ↓ × 1.16                                         IVA general (006)
precio_unitario_con_iva   (calculado al leer)      $304.50

utilidad = precio_unitario_sin_iva − costo_total = $52.50
```

**El costo de goma entra después del descuento y antes del markup.** Las dos posiciones son
deliberadas:

- **Después del descuento**, porque el descuento del catálogo es un beneficio de compra sobre lo
  que le pagas al proveedor. La goma la elaboras tú: no hay proveedor a quien descontarle nada.
  Un artículo con 55% de descuento y goma grande no paga $9.00 de goma, paga $20.00.
- **Antes del markup**, porque el precio de venta en este sistema siempre es calculado (011 no
  admite un precio capturado a mano). Si la goma no entrara al markup, el único efecto de marcarla
  sería reducir la utilidad mostrada, y el sistema estaría recomendando un precio que ya sabe que
  es insuficiente.

Un artículo sin goma tiene `costo_goma = 0.00`, con lo que `costo_total = costo_con_descuento` y la
cadena queda **aritméticamente idéntica** a la de 011.

## Backend (Laravel)

### Configuración global (entidad nueva)

- **Modelo `Configuracion`** (tabla `configuraciones`), perteneciente a un `User` (`user_id`,
  obligatorio). **Sin soft deletes**: un ajuste se edita, no se elimina.
- **Campos**:
  - `clave`: string, **obligatoria**, única por usuario (índice único compuesto
    `(user_id, clave)`).
  - `valor`: string, **obligatorio**. Se persiste como texto; la interpretación (número, texto,
    booleano) la impone quien la lee y la valida quien la escribe. Es el precio de tener un solo
    almacén para ajustes de naturaleza distinta.
- **Es un almacén clave→valor, no un catálogo abierto**: las claves admitidas están declaradas en un
  `enum` de PHP `App\Enums\ClaveConfiguracion` (backed por `string`), y cualquier clave fuera de esa
  lista se rechaza con `422`. El pizarrón no se llena de renglones que nadie lee.
- Cada caso del enum declara su **valor por defecto** y su **tipo de validación**:

  | Clave | Valor por defecto | Validación |
  | --- | --- | --- |
  | `costo_goma_chica` | `6.00` | numérico, ≥ 0, máx. 2 decimales |
  | `costo_goma_mediana` | `10.00` | numérico, ≥ 0, máx. 2 decimales |
  | `costo_goma_grande` | `20.00` | numérico, ≥ 0, máx. 2 decimales |
  | `costo_goma_jumbo` | `40.00` | numérico, ≥ 0, máx. 2 decimales |

- **Lectura**: `ConfiguracionService::obtener(ClaveConfiguracion $clave): string` devuelve el valor
  guardado del usuario autenticado o, si la fila no existe, el valor por defecto del enum. El
  servicio **memoiza todos los ajustes del usuario en la primera lectura de cada petición**, de modo
  que un recálculo en bloque sobre 500 artículos hace una consulta, no 500.
- **Escritura**: `upsert` por `(user_id, clave)`. Una clave que nunca se ha guardado y se deja en su
  valor por defecto simplemente no tiene fila; eso es correcto y no requiere corrección.

El valor por defecto del enum cumple tres papeles a la vez y por eso no hay un cuarto mecanismo:
es el valor con el que arranca un usuario nuevo, es la red para una fila borrada a mano en base de
datos, y es la única definición de "cuánto cuesta una goma chica de fábrica".

### `App\Enums\TamanoGoma`

`enum` de PHP backed por `string`, con cuatro casos y **ningún caso para "sin goma"**:

| Caso | Valor persistido | Clave de configuración |
| --- | --- | --- |
| `Chica` | `chica` | `costo_goma_chica` |
| `Mediana` | `mediana` | `costo_goma_mediana` |
| `Grande` | `grande` | `costo_goma_grande` |
| `Jumbo` | `jumbo` | `costo_goma_jumbo` |

- La ausencia de goma se representa con **`NULL`**, no con un cuarto caso. Mismo criterio que
  `utilidad_porcentaje` en 011, donde `NULL` significa "no aplica lo propio".
- **El enum es dueño de la correspondencia tamaño → clave de configuración**
  (`TamanoGoma::Chica->claveConfiguracion()`). El artículo guarda el tamaño, nunca el nombre de la
  clave: así renombrar un ajuste no deja artículos apuntando a un renglón inexistente.
- Se usa como cast del atributo `tamano_goma` en el modelo `Articulo` y se valida en los Form
  Requests con `Rule::enum(TamanoGoma::class)`, mismo patrón que `ObjetoImpuesto` en
  [006](006-gestion-articulos.md).
- **No tiene endpoint de catálogo**: sus cuatro opciones, sus etiquetas y sus medidas de referencia
  se sirven embebidas en el frontend, igual que las cuatro opciones de `objeto_imp` en 006. El SAT
  no publica un catálogo de tamaños de goma.

### Cambios sobre `Articulo` (extiende 006, 009 y 011)

- **Nueva columna `tamano_goma`**: string(10), **nullable**. `NULL` = el artículo no lleva goma.
  Valores admitidos: `chica`, `mediana`, `grande`, `jumbo`.
- **Nueva columna `costo_goma`**: decimal(10,2), **obligatoria**, con **default 0**. Es la **copia
  congelada** del costo vigente del tamaño en el momento del último cálculo. Un artículo con
  `tamano_goma` en `NULL` tiene siempre `costo_goma = 0.00`.
- **`costo_total`**: `costo_con_descuento + costo_goma`. **No se persiste**: es la suma de dos
  columnas y se expone como atributo calculado en el Resource, exactamente por la misma razón por
  la que `utilidad` no se persiste en 011.
- **`utilidad` cambia de base**: pasa a ser `precio_unitario_sin_iva − costo_total`. Sigue sin
  persistirse. Es el cambio que da sentido a toda la historia: la utilidad deja de medirse contra un
  costo incompleto.
- **`precio_unitario_sin_iva`** sigue siendo calculado y persistido, ahora como
  `techo2(costo_total × (1 + utilidad_efectiva / 100))`.
- **`costo_con_descuento` no cambia de nombre ni de significado**: sigue siendo el costo del aparato
  después del descuento del catálogo. En pantalla se le llama "costo del aparato" cuando hay goma
  de por medio, pero la columna se queda como está — renombrarla otra vez, un cambio después de
  011, sería puro movimiento.

#### Por qué se congela el costo de goma en el artículo

`costo_goma` duplica un dato que ya está en `configuraciones`, y eso es a propósito:

- **Coherencia interna de la ficha.** `precio_unitario_sin_iva` está persistido. Si el costo de la
  goma se leyera en vivo, subir el costo de $10 a $12 dejaría al artículo mostrando costo $212 y
  precio $262.50 — dos números que ya no se explican entre sí.
- **Ordenación del listado.** `/articulos` ordena por costo desde 011, y ordenar exige una
  expresión sobre columnas reales (`ORDER BY costo_con_descuento + costo_goma`).
- **Confirmación antes de mover precios.** Sin un valor guardado no hay nada que recalcular, y por
  tanto nada que confirmar: los precios cambiarían en silencio al tocar el ajuste.

La contrapartida —que las dos copias se separen— se cierra con el recálculo en bloque descrito
abajo, envuelto en una transacción.

### Recálculo en bloque

Cambiar cualquiera de los cuatro `costo_goma_*` dispara el recálculo de los artículos cuyo
`tamano_goma` corresponde a la clave modificada. Para cada uno se actualiza `costo_goma` con el
valor nuevo y se recalcula `precio_unitario_sin_iva`.

- **Se hace en PHP**, recorriendo los artículos afectados y llamando a `PrecioArticuloCalculator`,
  no con una actualización masiva vía query. Misma razón que en 011: el techo a 2 decimales no es
  portable entre MySQL y SQLite, y una versión SQL de la fórmula sería otra copia que mantener
  sincronizada.
- **Dentro de una transacción**: o se actualizan todos los artículos afectados, o ninguno. Es lo que
  impide que una interrupción a media pasada deje la mitad del catálogo con la copia vieja.
- Los recálculos que ya existen en 011 —cambio de `descuento` y de `utilidad_porcentaje` del
  catálogo— **no cambian de disparador**, pero ahora pasan por la cadena con goma incluida, porque
  toda la aritmética vive en `PrecioArticuloCalculator`.

### `PrecioArticuloCalculator`

Gana una función y una firma:

```
costoConDescuento(precioProveedor, descuento)          → sin cambios
costoTotal(costoConDescuento, costoGoma)               → nueva
precioVentaSinIva(costoTotal, utilidadPorcentaje)      → recibe el costo total, antes el con descuento
utilidad(precioVentaSinIva, costoTotal)                → recibe el costo total, antes el con descuento
```

`costoTotal` aplica `redondeo2` a la suma. Los dos sumandos ya vienen con 2 decimales, así que el
redondeo no cambia ningún valor de negocio; está para que la suma en punto flotante no arrastre una
cola de error hacia el `techo2` del markup, que es sensible al último centavo (ver 011).

### Endpoints

**Nuevos** (bajo `auth:sanctum`, scopeados al usuario autenticado):

- `GET /api/v1/configuracion` — devuelve **todas** las claves declaradas en el enum con su valor
  efectivo (el guardado o el de fábrica), como un objeto plano:
  `{ "costo_goma_chica": "6.00", "costo_goma_mediana": "10.00", "costo_goma_grande": "20.00",
  "costo_goma_jumbo": "40.00" }`. Nunca devuelve un objeto incompleto: el frontend no tiene que saber
  qué es un valor por defecto.
- `PUT /api/v1/configuracion` — recibe un objeto con **una o más** claves a actualizar. Las claves
  ausentes se dejan como están. Una clave desconocida devuelve `422`. Si alguna clave modificada es
  un `costo_goma_*` con valor distinto al vigente, dispara el recálculo en bloque.
- `GET /api/v1/configuracion/impacto-precios?costo_goma_chica=&costo_goma_mediana=&costo_goma_grande=&costo_goma_jumbo=`
  — recibe los valores que el usuario está por guardar (todos opcionales; los ausentes se toman de
  los vigentes) y responde `{ "articulos_afectados": <int> }` con el conteo **exacto** de artículos
  cuyo precio de venta cambiaría. Un costo que se envía sin cambio aporta cero. Alimenta el diálogo
  de confirmación del frontend, mismo patrón que el endpoint de impacto de 011.

**Modificados**:

- `GET /api/v1/articulos` — el parámetro `?sort=` deja de aceptar `costo_con_descuento` y pasa a
  aceptar **`costo_total`**, traducido a `ORDER BY costo_con_descuento + costo_goma`. Siguen
  válidos `precio_unitario_sin_iva` y `utilidad`, este último ahora sobre la expresión
  `precio_unitario_sin_iva - (costo_con_descuento + costo_goma)`. Un `sort` no reconocido se sigue
  ignorando y cayendo al orden por `nombre`, así que la clave retirada degrada sin error.
- `POST` / `PUT /api/v1/articulos[/{id}]` — aceptan `tamano_goma` (opcional, nullable). **No
  aceptan** `costo_goma`, `costo_total` ni `utilidad`.
- `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv` y
  `GET /api/v1/articulos/exportar-csv` — 8 columnas (ver abajo).

### Validaciones (Form Requests)

- `tamano_goma` en `Articulo`: **nullable**, y si viene, uno de `chica`, `mediana`, `grande`,
  `jumbo` (`Rule::enum(TamanoGoma::class)`). Cadena vacía se normaliza a `NULL` antes de validar,
  para que un `<select>` sin selección y una ausencia de campo se comporten igual.
- `PUT /api/v1/configuracion`: cada clave enviada se valida con la regla que declara su caso del
  enum. Los cuatro costos de goma son numéricos, **mayores o iguales a 0** y de máximo 2 decimales.
  Se permite `0.00` (una categoría que no te cuesta nada); **no** se aceptan negativos.
- `costo_goma`, `costo_total` y `utilidad` **no forman parte de las reglas de validación**:
  cualquier valor que un cliente envíe para ellos se **ignora en silencio**, mismo patrón que
  `costo_con_descuento` en 011.
- Fila de importación CSV: mismas reglas que el alta individual, aplicadas por fila.

### Columnas CSV

Se agrega una octava columna al final, para que un CSV de 7 columnas de 011 siga siendo importable
sin cambios:

```
nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,tamano_goma
```

- `tamano_goma` es **opcional**: celda vacía = el artículo no lleva goma.
- Los valores admitidos son `chica`, `mediana`, `grande` y `jumbo`, **insensibles a mayúsculas y a
  espacios alrededor** (`Grande `, `GRANDE` y `grande` son la misma cosa). Cualquier otro texto
  rechaza la fila con su motivo, sin abortar el archivo, igual que el resto de errores de 006.
- **Los costos no viajan en el CSV** en ninguna dirección: son configuración global, no un dato del
  artículo. Tampoco viajan `costo_goma`, `costo_total` ni `utilidad`.

### `ArticuloResource`

Suma `tamano_goma` (el valor del enum o `null`), `costo_goma` y `costo_total`. Conserva
`costo_con_descuento`, `precio_unitario_sin_iva`, `precio_unitario_con_iva`, `utilidad`,
`utilidad_porcentaje` y `utilidad_porcentaje_efectivo` sin cambios de nombre.

### Fuente de verdad única de la fórmula

Se mantiene el mecanismo de 011 —la cadena vive en PHP y en TypeScript, atadas por un fixture
compartido— y se extiende al eslabón nuevo:

- **`shared/fixtures/precios-articulos.json` gana `costo_goma` en cada caso y `costo_total` entre
  los resultados esperados.** Los casos que ya existen quedan con `costo_goma: 0` y
  `costo_total = costo_con_descuento`, y sus resultados esperados **no cambian ni un centavo**.
- Ese detalle es la mitad del valor de ampliar el fixture existente en vez de crear uno nuevo: los
  casos viejos pasan a ser la prueba de que meter la goma no movió nada de lo que ya funcionaba.
- Ambas suites (PHPUnit y Vitest) siguen leyendo ese mismo archivo por ruta relativa y recorriendo
  todos sus casos.

### Migración de esquema y de datos

En un solo cambio:

1. Se crea la tabla `configuraciones` (`user_id`, `clave`, `valor`, timestamps, único
   `(user_id, clave)`).
2. Se siembran los tres costos (`6.00` / `10.00` / `20.00`) para cada usuario existente, de modo que
   la pantalla de Configuración muestre valores reales desde el primer día en vez de campos que
   parecen vacíos. `costo_goma_jumbo` no forma parte de esta migración: se agregó después (ver
   "Estado de implementación") y no necesita sembrado propio, porque
   `ConfiguracionService::todos()` ya devuelve el valor de fábrica de cualquier clave sin fila
   guardada — el mismo mecanismo que hace innecesaria una migración de datos por cada categoría
   nueva que se agregue en el futuro.
3. `articulos` gana `tamano_goma` string(10) nullable y `costo_goma` decimal(10,2) not null
   default 0.
4. Todos los artículos existentes quedan con `tamano_goma = NULL` y `costo_goma = 0.00`. **No se
   asigna ningún tamaño por omisión**: un artículo que no es un sello no debe cargar un costo de
   goma inventado, y la categoría se elige a ojo (no hay medidas capturadas de las que deducirla).
5. Se recalcula la cadena completa de todos los artículos en PHP, con `PrecioArticuloCalculator`,
   por el mismo criterio de 011: que el resultado sea idéntico al de cualquier otro camino de la
   aplicación.

**El resultado esperado del paso 5 es cero cambios de precio.** Como todos los artículos quedan con
`costo_goma = 0.00`, la fórmula nueva y la vieja son la misma operación. Eso convierte la migración
en una verificación de que el eslabón nuevo es transparente cuando no se usa, y esa verificación se
codifica como test (ver abajo).

El sistema no está en producción y los artículos en base son datos de ejemplo, así que la migración
es sembrado inicial y no rescate de información. La verificación de arriba vale como prueba de la
fórmula, no como resguardo de datos.

### Tests

- **Invariante de la migración**: una batería de artículos con precios y porcentajes variados,
  calculada con la cadena de 011, produce **exactamente** los mismos valores tras aplicar la cadena
  de 014 con `costo_goma = 0`. Es el test que respalda el "cero cambios de precio".
- **Casos frontera con goma**, alimentados por el fixture compartido y corriendo en **SQLite**
  (tests) y en **MySQL** (entorno real):
  - Aparato $5.40 + goma mediana $10.00 = $15.40 al 5% → **$16.17**, no $16.18. Es el caso frontera
    de 011 alcanzado por otra ruta: el mismo costo total debe dar el mismo precio, venga de donde
    venga.
  - Aparato $80.01 + goma grande $20.00 = $100.01 al 33% → **$133.02**, no $133.01.
  - Goma con costo $0.00 (categoría gratis) vs. artículo sin goma: mismo resultado, distinto
    significado.
- **El descuento no toca la goma**: precio de lista $347.27 en un catálogo con 55% de descuento,
  goma grande y 99% de utilidad → costo del aparato $156.27, costo total $176.27, precio de venta
  $350.78 y utilidad $174.51. Si el descuento se aplicara sobre el total, el costo sería $158.52 y
  el test falla.
- **Recálculo en bloque**: cambiar `costo_goma_mediana` mueve el precio de los artículos medianos y
  **no** el de los chicos, los grandes ni los que no llevan goma; el endpoint de impacto devuelve
  ese mismo conteo; una interrupción a media pasada no deja artículos a medio actualizar.
- **Configuración**: `GET` devuelve las cuatro claves aunque no exista ninguna fila (incluida
  `costo_goma_jumbo` en $40.00 para un usuario que nunca la guardó); `PUT` con una clave desconocida
  devuelve `422`; `PUT` parcial deja intactas las claves ausentes; un costo negativo se rechaza;
  `0.00` se acepta; los ajustes de un usuario no son visibles ni modificables por otro.
- **CSV**: importar con la celda de tamaño vacía deja el artículo sin goma; con `Grande ` (con
  espacio y mayúscula) lo deja en grande; con `Jumbo` lo deja en jumbo; con `enorme` rechaza la fila
  reportando número y motivo (el motivo lista las cuatro categorías válidas); exportar produce las 8
  columnas y el archivo resultante es reimportable sin pérdida del tamaño.
- **Ordenación** de `GET /api/v1/articulos` por `costo_total` en ambas direcciones, verificando que
  un artículo con goma se ordena por su costo total y no por el del aparato; y que el valor retirado
  `costo_con_descuento` cae al orden por defecto sin error.
- `ArticuloFactory` recibe `tamano_goma` (opcional) y **deriva** `costo_goma` y el resto de la
  cadena.

## Frontend (Vue 3)

### Módulo de cálculo compartido

`src/lib/precioArticulo.ts` gana `costoTotal` y ajusta las firmas de `precioVentaSinIva` y
`utilidad` para recibir el costo total, espejo exacto de `PrecioArticuloCalculator`. Su archivo de
test sigue recorriendo el fixture compartido, ahora con el campo de goma.

### `/configuracion` (pantalla nueva)

Primera pantalla de configuración del sistema. Una sección, **Costos de elaboración**, con los
cuatro `Input` numéricos:

```
Chica      $ [  6.00 ]   Hasta 38 × 14 mm — bolsillo, fechador
Mediana    $ [ 10.00 ]   Hasta 58 × 22 mm — sello de datos estándar
Grande     $ [ 20.00 ]   Hasta 75 × 38 mm o superior
Jumbo      $ [ 40.00 ]   Sellos redondos de 20 × 20 mm — usan hasta 1/4 de bote de primer
```

- Las medidas son **texto de ayuda**, no un dato capturado ni validado. El sistema no conoce las
  dimensiones de ningún sello y no deduce la categoría: la elige el usuario a ojo.
- **Diálogo de confirmación antes de guardar** cuando cambia alguno de los cuatro costos: antes de
  enviar el `PUT`, la pantalla consulta `GET /api/v1/configuracion/impacto-precios` con los valores
  nuevos y muestra el conteo exacto ("Se recalculará el precio de venta de N artículos"). Confirmar
  envía el `PUT`; cancelar regresa sin guardar. Si el conteo es 0, se guarda sin diálogo.
- Los mensajes de error de validación siguen el patrón de 006 (`Input`/`Alert`).
- La pantalla se construye pensando en que va a crecer: la sección es un bloque, no la pantalla
  entera, para que la tasa de IVA y los datos fiscales entren como secciones hermanas sin rehacerla.

### `/articulos/crear` y `/articulos/:id/editar`

- **Selector `Tamaño de goma`** (`Select` simple, opcional), con cinco opciones donde la primera es
  la ausencia de goma y es el valor por defecto al crear:

  ```
  Sin goma
  Chica — $6.00 (hasta 38 × 14 mm)
  Mediana — $10.00 (hasta 58 × 22 mm)
  Grande — $20.00 (hasta 75 × 38 mm o superior)
  Jumbo — $40.00 (sellos redondos de 20 × 20 mm)
  ```

  El costo vigente se muestra **dentro de cada opción**, tomado de `GET /api/v1/configuracion`. Es
  donde el dato hace falta: al elegir, no después.

  "Sin goma" es una **opción real de la lista**, no solo el texto del `placeholder`: quien eligió un
  tamaño puede volver a dejar el artículo sin goma sin recargar la pantalla. Se declara con el valor
  centinela que exige la regla de [003](003-design-system-tailwind.md) y se traduce a `NULL` al
  guardar; `tamano_goma` en el estado del formulario nunca contiene el centinela.

- **El bloque de resumen gana dos renglones cuando hay goma seleccionada**:

  ```
  Precio de lista del proveedor      $200.00
  Descuento del catálogo (0%)         −$0.00
  Costo del aparato                  $200.00
  Goma mediana                       +$10.00
  Costo total                        $210.00
  Utilidad (25%)                     +$52.50
  Precio de venta sin IVA            $262.50
  IVA (16%)                          +$36.75
  Precio de venta con IVA            $304.50
  ```

  Con "Sin goma" el bloque se ve **exactamente como en 011**: sin renglón de goma, sin renglón de
  costo total, y el tercer renglón se titula "Costo". No se muestran renglones de $0.00 que solo
  agregan ruido a la mayoría de los artículos.

- El resumen sigue actualizándose en vivo y sin consultar al servidor, ahora también al cambiar el
  tamaño de goma.
- La **verificación del valor autoritativo** de 011 se extiende a `costo_goma` y `costo_total`: si
  la respuesta del `POST`/`PUT` no coincide con lo mostrado, se presenta un `Alert` con el valor
  real en vez de navegar en silencio.

### `/articulos` (listado)

- La columna **"Costo con descuento" pasa a llamarse "Costo"** y muestra el **costo total**.
- **No se agrega ninguna columna**, para no revivir el desborde de tabla corregido en
  [006](006-gestion-articulos.md) el 2026-08-03. El tamaño de goma y el desglose viven en el
  formulario.
- La columna "Costo" sigue siendo ordenable, ahora alimentando `?sort=costo_total`.

### Importar CSV

El modal de importación no cambia de flujo; su descripción lista las **8 columnas**, respetando la
regla de `Dialog` con contenido dinámico de [003](003-design-system-tailwind.md) (bloque `<code>`
propio con `overflow-x-auto`).

### Navegación

`/configuracion` se alcanza desde el **menú de usuario** de
[013](013-navegacion-principal.md), no desde los cuatro grupos de la barra: por su regla de
reparto, las pantallas de configuración del sistema van ahí y los grupos quedan para el flujo
comercial.

Esa entrada de menú y el menú de usuario que la contiene se definen en 013, que es la dueña única
de la navegación. Esta spec no toca `src/config/navegacion.ts` por su cuenta: solo declara que su
pantalla vive ahí.

## Fuera de alcance

- **Inventario de insumos**: existencias de polímero, negativos, tinta o mangos; movimientos de
  almacén; merma. El costo por categoría es un promedio asumido, no un consumo medido.
- **Cálculo por área o por consumo real** (media placa, centímetros cuadrados de polímero). No se
  capturan ancho ni alto del sello y el sistema no deduce la categoría de ninguna medida.
- **Categorías de tamaño administrables por el usuario**: son cuatro, fijas, definidas en el código.
  No se pueden crear, renombrar, reordenar ni eliminar desde la aplicación — agregar o quitar una
  categoría sigue siendo un cambio de código, como el que agregó Jumbo a las tres originales.
- **Herencia del tamaño desde el catálogo o el proveedor**: el tamaño es siempre propio del
  artículo, y su valor por defecto al crear es "sin goma". No existe un `tamano_goma` de catálogo.
- **Costos de goma distintos por proveedor, catálogo o cliente**: los cuatro costos son globales
  del usuario.
- **La casilla `[X] Requiere elaboración de goma`** como campo independiente: el desplegable con su
  opción "Sin goma" la sustituye por completo. No hay booleano que mantener sincronizado con una
  categoría.
- **La variable `Costo_Base_Goma_Estandar`** como ajuste separado: queda absorbida por
  `costo_goma_mediana`.
- **Desglose del costo de goma hacia el cliente**: no aparece como concepto, línea ni nota en
  cotizaciones, facturas u órdenes de compra. Es un componente interno de costo.
- **Recálculo de documentos ya emitidos**: facturas, cotizaciones y órdenes de compra conservan la
  copia del precio que guardaron ([007](007-facturacion.md), [008](008-cotizaciones.md),
  [012](012-ordenes-compra.md) no se tocan).
- **Historial** de cambios de los costos de goma o del tamaño de un artículo, y reversión de un
  recálculo. `updated_at` es la única referencia temporal.
- **Reportes** de consumo de goma, de placas gastadas o de rentabilidad por categoría de tamaño.
- **Importación CSV que actualice artículos existentes**: sigue vigente la regla de 006 — el CSV
  solo da de alta. Se evaluó como forma de marcar el tamaño en bloque y se descartó: existía para
  resolver una migración masiva que este sistema no tiene.
- **Costo de goma negativo** y, por tanto, una goma que abarate el artículo.
- **Migrar el resto de los ajustes escritos en el código** (tasa de IVA del 16%, datos fiscales,
  folios) al almacén de configuración. La tabla queda lista; moverlos es su propia historia.
- **Multi-moneda**: los cuatro costos son en MXN, sin IVA.
- Renombrar `costo_con_descuento`, y cualquier otro cambio de nombre de columna heredado de 011.

## Estado de implementación

Implementada el 2026-08-07.

- **El clobber de `style.css` volvió a ocurrir**, como en 013 y como anticipa
  [003](003-design-system-tailwind.md). `npx shadcn-vue add dropdown-menu` reemplazó el `@import` de
  Google Fonts por uno que **solo pedía Roboto** (otra vez sin Open Sans) y anexó un `@layer base`
  duplicado. Se revirtió el archivo completo con `git checkout`; ambas incorporaciones del CLI eran
  duplicados de bloques que ya existían. `@lucide/vue` volvió a subir de versión en `package.json`
  (`^1.29.0` → `^1.30.0`) como efecto colateral y se dejó, mismo criterio que en 013.
- **Los tres componentes del dropdown que importan `@lucide/vue`** (`DropdownMenuCheckboxItem`,
  `DropdownMenuRadioItem`, `DropdownMenuSubTrigger`) se dejaron como los genera el CLI, a diferencia
  de lo que se hizo con `NavigationMenuTrigger` en 013: `select`, `dialog` y `combobox` ya importan
  lucide desde 003, así que lucide no es una dependencia nueva del render, y esos tres componentes
  no los usa el menú de usuario.
- **`?sort=costo_con_descuento` se retiró en favor de `?sort=costo_total`.** No es un cambio
  incompatible: un `sort` no reconocido ya caía al orden por `nombre` desde 011, así que la clave
  vieja degrada sin error. Cubierto por un test propio.
- **La migración de 011 (`recalcular_cadena_de_precios_de_articulos`) se ajustó**: pasaba el
  resultado completo de `calcularCadena` a un `update`, y ese arreglo ahora incluye `costo_total`,
  que no tiene columna. Se cambió a nombrar las dos columnas persistidas explícitamente.
- **`DocumentoLineas.vue` y `ArticuloBuscador.vue` siguen leyendo `costo_con_descuento`**, no el
  total, y es lo correcto: alimentan las líneas de una orden de compra, donde pagas el aparato al
  proveedor y no la goma que elaboras tú.
- **Los valores enteros en las aserciones JSON van sin decimal** (`210`, no `210.0`): PHP serializa
  un float redondo como entero (`json_encode(10.0)` da `10`) y `assertJsonPath` compara con
  identidad. Es la convención que ya seguían los tests de 011.
- Verificado: **252 tests de Pest en verde** (24 nuevos entre `ConfiguracionTest` y los de goma en
  `ArticulosTest`), **39 de Vitest**, `vue-tsc --noEmit` sin errores, `npm run build` exitoso, Pint
  y ESLint limpios.
- **Pendiente**: la verificación visual en un navegador real (misma limitación de entorno que el
  resto de las historias). Falta confirmar a ojo el menú de usuario en 375 / 768 / 1440 px, el
  diálogo de confirmación de `/configuracion`, el selector de goma con los costos en sus opciones y
  el bloque de resumen con y sin goma.
- **El selector de goma se quedaba sin su opción "Sin goma"** (detectado el 2026-08-07 en la primera
  verificación en navegador, corregido el mismo día): se había declarado como `<SelectItem
  value="">`, y Reka UI reserva la cadena vacía para limpiar la selección. El error no se veía al
  abrir el desplegable sino al entrar a `/articulos/crear` y `/articulos/:id/editar`, porque
  `SelectContent` monta sus opciones en un `DocumentFragment` oculto aun estando cerrado. Como el
  `placeholder` dice "Sin goma", el alta se veía correcta y el hueco solo aparecía al querer quitarle
  la goma a un artículo. Se pasó a un valor centinela y la restricción quedó escrita como regla
  general del design system en [003](003-design-system-tailwind.md), con la regla de ESLint que la
  hace cumplir. Ni `vue-tsc` ni `npm run build` podían atraparlo, y las suites de Vitest solo cubren
  `src/lib/`: era exactamente lo que la verificación visual pendiente tenía que encontrar.
- **Pendiente**: `Prettier --check` reporta 28 archivos, pero ya reportaba 32 antes de esta historia
  (los componentes vendored de `ui/` y otras vistas). Se formatearon solo los archivos tocados aquí.
  Dejar el repo entero limpio de Prettier es un paso aparte.

### Ampliada el 2026-08-21: cuarta categoría "Jumbo"

- **Archivos modificados**: `app/Enums/TamanoGoma.php` (caso `Jumbo`), `app/Enums/ClaveConfiguracion.php`
  (caso `CostoGomaJumbo`, valor de fábrica `40.00`), `tests/Feature/ArticulosTest.php` y
  `tests/Feature/ConfiguracionTest.php` (casos nuevos para jumbo), y en el frontend
  `frontend/src/lib/tamanoGoma.ts` (cuarto elemento de `TAMANOS_GOMA`),
  `frontend/src/stores/configuracion.ts` (`costo_goma_jumbo` en la interfaz `Configuracion`) y
  `frontend/src/views/ArticulosListView.vue` (texto de ayuda del modal de importar CSV).
- **Sin migración de esquema ni de datos**: `tamano_goma` ya admitía hasta 10 caracteres y
  `configuraciones` ya es un almacén abierto a cualquier clave del enum (ver supuesto 36). El
  costo de fábrica de Jumbo ($40.00) llega a todos los usuarios, existentes y nuevos, sin sembrado.
- **`ArticuloFormView.vue`, `ConfiguracionView.vue` y el bloque de resumen de la cadena de cálculo
  no se tocaron**: los tres ya recorrían `TAMANOS_GOMA` de forma genérica desde que se implementó
  esta historia, así que agregar Jumbo al arreglo bastó para que aparezca en las tres pantallas.
- **Verificación**: Pint limpio; la suite de Pest completa pasa (597 tests); ESLint y Prettier
  limpios sobre los archivos tocados; Vitest en verde (95 tests); `npm run build` compila.
  **Verificado visualmente en un navegador real** (Playwright/Chromium contra `php artisan serve` y
  `npm run dev` levantados para la ocasión, con un usuario, un catálogo y un artículo de prueba
  creados y eliminados al terminar): el selector de tamaño de goma del formulario de artículo
  muestra "Jumbo — $40.00 (sellos redondos de 20 × 20 mm — usan hasta 1/4 de bote de primer)" junto
  a las otras tres opciones con sus costos correctos; `/configuracion` muestra el cuarto campo
  "Jumbo" con $40.00 editable, debajo de "Grande"; y elegir "Jumbo" en el formulario agrega el
  renglón "Goma jumbo +$40.00" al resumen en vivo, con costo total y precio de venta recalculados
  correctamente ($100 de aparato + $40 de goma = $140 de costo total).

## Criterios de aceptación

1. Un usuario autenticado puede asignar a un artículo un tamaño de goma (Chica, Mediana, Grande o
   Jumbo) o dejarlo sin goma; sin goma es el valor por defecto al crear. "Sin goma" está disponible
   como opción del selector en todo momento, de modo que un artículo con goma puede volver a
   quedarse sin ella en la misma sesión de edición.
2. Al guardar, el sistema suma el costo vigente del tamaño elegido al costo del artículo y calcula
   el precio de venta sobre ese costo total. Un aparato de $200.00 en un catálogo sin descuento, con
   goma mediana a $10.00 y 25% de utilidad, produce costo total $210.00, precio de venta sin IVA
   $262.50, utilidad $52.50 y precio con IVA $304.50.
3. El costo de goma se suma **después** del descuento del catálogo: un precio de lista de $347.27 en
   un catálogo con 55% de descuento, con goma grande a $20.00 y 99% de utilidad, produce costo del
   aparato $156.27, costo total $176.27, precio de venta $350.78 y utilidad $174.51.
4. Un artículo sin goma produce **exactamente** los mismos valores que produciría con la cadena de
   011: el eslabón nuevo es transparente cuando no se usa, verificado sobre toda la batería de casos
   existente.
5. El redondeo hacia arriba de 011 se conserva: un aparato de $5.40 con goma mediana a $10.00 y 5%
   de utilidad produce exactamente $16.17, no $16.18, tanto en SQLite como en MySQL.
6. El mismo juego de casos, ahora con costo de goma, produce resultados idénticos en el backend y en
   el módulo de cálculo del frontend, verificado por ambas suites contra el fixture compartido.
7. La utilidad en pesos que muestra el sistema es la diferencia contra el costo **total**; un
   artículo con goma nunca muestra una utilidad que ignore ese costo.
8. `/configuracion` muestra los cuatro costos con valores reales desde el primer día, aun sin haber
   guardado nunca la pantalla, y permite editarlos.
9. Guardar un costo de goma negativo muestra un error de validación y no permite guardar; `0.00` sí
   se acepta.
10. Antes de aplicar un cambio de costo, el sistema muestra un diálogo de confirmación con el número
    **exacto** de artículos cuyo precio de venta va a cambiar; cancelar no guarda nada. Si el conteo
    es 0, guarda sin preguntar.
11. Cambiar `costo_goma_mediana` recalcula el costo y el precio de venta **solo** de los artículos
    con goma mediana; los chicos, los grandes, los jumbo y los que no llevan goma conservan su
    precio.
12. Enviar una clave de configuración no declarada devuelve `422` y no crea ninguna fila.
13. Los ajustes de un usuario no son visibles ni modificables por otro usuario.
14. Enviar `costo_goma`, `costo_total` o `utilidad` en un `POST`/`PUT` de artículo no produce error,
    pero el valor enviado se ignora por completo.
15. El listado `/articulos` muestra en su columna "Costo" el costo total (aparato + goma) y permite
    ordenar ascendente y descendentemente por ella, colocando un artículo con goma según su costo
    total y no según el del aparato.
16. El formulario de artículo muestra en vivo la cadena completa incluyendo los renglones de goma y
    costo total cuando hay un tamaño seleccionado, y los omite cuando el artículo no lleva goma.
17. El selector de tamaño muestra el costo vigente de cada categoría dentro de su opción.
18. Importar un CSV con las 8 columnas da de alta los artículos con su goma y su precio ya
    calculados; la celda vacía deja el artículo sin goma, `Grande ` (con espacio y mayúscula) lo
    deja en grande, `Jumbo` lo deja en jumbo, y un valor no reconocido rechaza esa fila reportando
    número y motivo sin abortar el archivo.
19. Exportar el listado genera un CSV con esas mismas 8 columnas, sin columnas calculadas y sin los
    costos de goma, y ese archivo es directamente reimportable sin pérdida del tamaño.
20. Tras la migración, ningún artículo existente cambia de precio, todos quedan sin goma y con costo
    de goma $0.00, y cada usuario tiene sus tres costos sembrados.
21. `/configuracion` se alcanza desde la opción "Configuración" del menú de usuario definido en
    [013](013-navegacion-principal.md); no se agrega ningún grupo ni enlace suelto a la barra.
22. Facturación, Cotizaciones, Tesorería y Órdenes de compra siguen comportándose igual que antes,
    sin costo ni utilidad por documento emitido.
23. Pint, ESLint/Prettier y las suites de PHPUnit y Vitest corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. No se crea inventario de insumos: no hay existencias de polímero, negativos ni mermas. El costo
   de la goma es un monto fijo asignado por categoría de tamaño.
2. La casilla `[X] Requiere elaboración de goma` **no se implementa** como campo aparte: la historia
   narra una evolución y el estado final es únicamente el desplegable de tamaño, cuya opción "Sin
   goma" (valor por defecto) equivale a la casilla desmarcada.
3. Los tamaños son cuatro valores fijos del sistema (Chica, Mediana, Grande, Jumbo). El usuario no
   puede crear, renombrar ni eliminar categorías desde la aplicación.
4. La variable `Costo_Base_Goma_Estandar` de $10.00 no sobrevive como ajuste independiente: queda
   absorbida por el costo de la categoría Mediana.
5. Las medidas de la tabla son texto de ayuda en pantalla. El sistema no captura ancho ni alto y no
   deduce la categoría automáticamente; el usuario la elige a ojo.
6. El costo de goma es por pieza, en MXN, sin IVA, con 2 decimales.
7. Nace una pantalla de Configuración —la primera del sistema— donde se editan los cuatro costos,
   precargados en $6.00 / $10.00 / $20.00 / $40.00.
8. Esos costos son globales del usuario, no por proveedor ni por catálogo: la misma goma cuesta lo
   mismo venga el aparato de donde venga.
9. Se permite $0.00 como costo de una categoría; no se permiten negativos.
10. El costo de goma se suma después del descuento del catálogo: el descuento es un beneficio de
    compra sobre el aparato, y la goma la elabora el usuario.
11. El costo de goma entra al markup: el precio de venta se calcula sobre el costo total. Con
    aparato $200, goma $10 y 25%, el precio de venta es $262.50, no $250.00. La alternativa —que la
    goma solo erosione la utilidad sin mover el precio— choca con 011, donde el precio siempre es
    calculado y no existe modo manual.
12. La utilidad en pesos pasa a ser `precio de venta − costo total`. Un artículo con goma deja de
    mostrar una utilidad inflada.
13. El tamaño de goma es propio de cada artículo y no se hereda del catálogo, a diferencia del
    porcentaje de utilidad de 011. El valor por defecto al crear es "sin goma".
14. Cambiar un costo global recalcula en bloque el costo total y el precio de venta de todos los
    artículos de esa categoría, precedido de un diálogo de confirmación con el conteo exacto.
15. Cambiar el tamaño de goma de un artículo recalcula su costo y su precio de venta al guardar.
16. El formulario de artículo muestra el costo de goma como un renglón más del bloque de resumen ya
    existente en 011.
17. El listado `/articulos` no gana columna nueva: la columna de costo pasa a mostrar el costo
    total, para no revivir el desborde de tabla corregido en 006.
18. El costo de goma es interno: no aparece como concepto, línea ni desglose en cotizaciones,
    facturas ni órdenes de compra.
19. El CSV de artículos gana una columna opcional de tamaño de goma (vacía = sin goma). Los costos
    no viajan en el archivo, por ser configuración global.
20. Los documentos ya emitidos no se tocan ni se recalculan: conservan la copia del precio que
    guardaron.
21. No hay historial de cambios de los costos de goma ni de la categoría de un artículo.
22. No hay reporte de consumo de goma ni conteo de placas gastadas.
23. **(Adición técnica)** Los costos viven en un almacén de configuración clave→valor por usuario,
    no en una tabla dedicada de tamaños ni en columnas fijas ni en un archivo del servidor. Es la
    única opción que sirve además para los ajustes que ya se ven venir (tasa de IVA, datos fiscales,
    folios) sin rehacer nada, y la lista de claves admitidas es cerrada para que el almacén no se
    llene de renglones que nadie lee.
24. **(Adición técnica)** El artículo guarda una palabra corta (`chica`/`mediana`/`grande`/`jumbo`)
    validada contra una lista cerrada, y `NULL` significa "sin goma". Mismo patrón que `objeto_imp` en 006 y
    que `utilidad_porcentaje` nulo en 011. El enum es dueño de la correspondencia tamaño → clave de
    configuración, de modo que el artículo nunca guarda el nombre de un ajuste.
25. **(Adición técnica)** El artículo guarda una **copia congelada** del costo de goma y se recalcula
    en bloque al cambiar el ajuste, en vez de consultar la configuración en cada lectura. Es lo
    mismo que ya hacen `costo_con_descuento` y `precio_unitario_sin_iva` en 011, es lo único
    compatible con el diálogo de confirmación del supuesto 14, y es lo que permite ordenar el
    listado por costo. El recálculo va dentro de una transacción.
26. **(Adición técnica)** Se amplía el fixture compartido de 011 en vez de crear uno nuevo: cada
    caso gana el costo de goma y los casos existentes quedan en $0 con resultados intactos, con lo
    que pasan a servir de prueba de que el eslabón nuevo no movió nada.
27. **(Adición técnica)** Los artículos existentes quedan sin goma y con costo $0, y se recalcula la
    cadena completa; el resultado esperado es cero cambios de precio, y esa expectativa se codifica
    como test. No se marca nada como "Mediana" por omisión: inventaría un costo en artículos que no
    son sellos y perdería esa verificación.
28. **(Adición técnica)** `costo_total` **no se persiste**: es la suma de dos columnas, exactamente
    el mismo criterio por el que `utilidad` no se persiste en 011. Ordenar por él se traduce a un
    `ORDER BY` sobre la expresión, y `?sort=costo_con_descuento` se retira en favor de
    `?sort=costo_total`.
29. **(Adición técnica)** El valor por defecto de cada clave vive en el enum y se devuelve cuando no
    hay fila guardada, de modo que `GET /api/v1/configuracion` nunca responde incompleto y un
    usuario no puede quedarse sin configuración. La siembra de la migración existe para que los
    valores sean visibles y editables, no para evitar un hueco.
30. **(Adición técnica)** El servicio de configuración memoiza los ajustes del usuario por petición,
    para que un recálculo en bloque haga una consulta y no una por artículo.
31. **(Adición técnica)** `costo_con_descuento` **no se renombra**, aunque en pantalla se le llame
    "costo del aparato" cuando hay goma. Un segundo cambio de nombre de columna, una spec después
    del de 011, sería movimiento sin ganancia.
32. **(Adición técnica)** La pantalla de Configuración no cabía en ninguno de los cuatro grupos de
    navegación, y eso se resolvió en [013](013-navegacion-principal.md) —su dueña— sustituyendo el
    botón "Cerrar sesión" por un **menú de usuario** con el nombre del usuario, Configuración y
    Cerrar sesión. Se prefirió a un quinto grupo "Configuración" porque los cuatro grupos son el
    flujo comercial y un grupo de ajustes habría diluido ese criterio; de paso, el menú de usuario
    es la convención universal y deja visible quién está dentro del sistema. La ruta queda como
    `/configuracion` con secciones, no como subpágina: el desglose en subpáginas solo hacía falta
    cuando la entrada era un grupo de menú que necesitaba hijos.
33. **(Adición técnica)** La importación CSV que actualiza artículos existentes queda descartada: se
    evaluó como forma de marcar tamaños en bloque y solo resolvía un problema de migración masiva
    que este sistema, sin datos de producción, no tiene.
34. **Cuarta categoría: Jumbo.** El taller también trabaja sellos redondos de 20 × 20 mm que
    consumen hasta un cuarto de bote de primer, muy por encima de lo que consume "Grande" — no es
    una categoría más grande en superficie, es una que usa mucho más insumo por pieza. Se agrega
    como cuarta categoría fija, con el mismo mecanismo que las tres originales: nuevo caso de
    `TamanoGoma`, nueva clave `costo_goma_jumbo` en `ClaveConfiguracion`, sin tabla ni columna
    nueva.
35. El costo de fábrica de `costo_goma_jumbo` es **$40.00** — el doble de "Grande" ($20.00) —,
    elegido por el usuario para reflejar el salto de costo del primer, no una fórmula derivada de
    las otras tres. Como los demás costos de goma, es editable en `/configuracion` desde el primer
    día.
36. **(Adición técnica)** Agregar Jumbo no requiere migración de esquema ni de datos: `tamano_goma`
    ya es `string(10)` (le sobra espacio para `jumbo`), y `configuraciones` ya es un almacén
    clave→valor abierto a cualquier clave que declare el enum. El valor de fábrica de
    `costo_goma_jumbo` llega a todo usuario, existente o nuevo, por el mismo mecanismo de
    `ConfiguracionService::todos()` que ya cubre las otras tres — no hace falta sembrar una fila.
37. **(Adición técnica)** `TamanoGoma::porClaveConfiguracion()`, la validación con
    `Rule::enum(TamanoGoma::class)`, la ordenación en bloque por clave modificada y el ciclo
    `foreach (self::cases() as ...)` de ambos enums ya eran genéricos sobre el número de casos: no
    necesitaron ningún cambio de lógica, solo el caso nuevo. Lo mismo del lado del frontend:
    `TAMANOS_GOMA` en `src/lib/tamanoGoma.ts` alimenta por sí solo el selector del formulario, la
    pantalla de Configuración y el resumen de la cadena de cálculo, así que agregar un elemento al
    arreglo basta para que las tres pantallas muestren Jumbo sin tocarlas.
