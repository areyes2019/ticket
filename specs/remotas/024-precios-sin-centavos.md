# Spec: Precios sin centavos (redondeo del precio con IVA al peso entero)

## Historia de usuario

Como usuario único del sistema de facturación, quiero que el precio que ve el cliente sea siempre un
número cerrado en pesos, sin centavos, para que un particular —una ama de casa, un doctor, alguien
que compra un sello suelto— lea "$234.00" en lugar de "$233.48". Las empresas grandes están
acostumbradas a los centavos; mis clientes de mostrador los detestan, y un precio con decimales se
lee como un precio calculado con calculadora en lugar de un precio de lista.

## Objetivo / Alcance

Agregar un eslabón final a la cadena de precios de [011](011-precio-proveedor-utilidad.md) y
[014](014-costo-elaboracion-goma.md): **el precio con IVA de un artículo siempre es un peso entero**.

El número que se limpia es el **precio con IVA**, porque es el que el cliente lee. Como consecuencia,
`precio_unitario_sin_iva` deja de ser un número redondo y pasa a ser el valor que, al aplicarle el
IVA, produce el entero. El ajuste es **siempre hacia arriba**: el precio nunca baja por redondeo y
la utilidad nunca queda por debajo del porcentaje capturado.

El eslabón vive dentro de `PrecioArticuloCalculator` / `precioArticulo.ts`, de modo que **todos** los
caminos que ya calculan precios lo heredan sin cambios propios: alta y edición de artículo,
importación CSV, recálculo en bloque por cambio de descuento o de utilidad del catálogo
([011](011-precio-proveedor-utilidad.md)) y mantenimiento masivo de catálogos
([021](021-mantenimiento-articulos-catalogos.md)).

**No** modifica [Facturación](007-facturacion.md), [Cotizaciones](008-cotizaciones.md),
[Tesorería](010-tesoreria.md) ni el [formato PDF](019-formato-pdf-documentos.md).

### Cadena de cálculo

Con precio de lista $120.00, sin descuento, goma de $10.00 y 55% de utilidad —un sello autoentintable
del rango de la ficha que originó esta historia:

```
precio_proveedor            (capturado)                $120.00
  ↓ × (1 − descuento / 100)                            descuento del catálogo (0%)
costo_con_descuento         (calculado, persistido)    $120.00
  ↓ + costo_goma                                       goma
costo_total                 (calculado al leer)        $130.00
  ↓ × (1 + utilidad_efectiva / 100)  → techo2          markup sobre el costo (55%)
precio_venta_crudo_sin_iva  (intermedio, no se guarda) $201.50   → con IVA $233.74
  ↓ redondeo al peso entero del precio con IVA         ← ESLABÓN NUEVO
precio_unitario_sin_iva     (calculado, persistido)    $201.72
  ↓ × factor_iva                                       1.16
precio_unitario_con_iva     (calculado al leer)        $234.00

utilidad = precio_unitario_sin_iva − costo_total = $71.72
```

El eslabón nuevo es el **último** de la cadena: entra después del markup y después del IVA, y no
altera ninguno de los eslabones anteriores. `costo_con_descuento` y `costo_total` no cambian de
definición ni de valor.

`precio_venta_crudo_sin_iva` es el valor que hoy se persiste como `precio_unitario_sin_iva`. Pasa a
ser un **intermedio de cálculo** que no se guarda en ninguna columna: la única salida persistida
sigue siendo `precio_unitario_sin_iva`, ahora ya ajustada.

### La regla de redondeo

Dado el precio crudo sin IVA y el factor de IVA del artículo:

1. **Objetivo inicial**: el primer peso entero mayor o igual al precio crudo con IVA,
   `objetivo = ceil(precio_crudo × factor_iva)`. Si el precio crudo con IVA ya es un entero exacto,
   el objetivo es ese mismo entero y no hay ajuste.
2. **Búsqueda del centavo**: se buscan los dos centavos vecinos del cociente `objetivo ÷ factor_iva`
   —es decir, `floor(objetivo ÷ factor_iva × 100) / 100` y ese valor más un centavo— y se toma el
   que cumpla `redondeo2(candidato × factor_iva) = objetivo`.
3. **Objetivo inalcanzable**: si ninguno de los dos lo cumple, se incrementa el objetivo en un peso
   y se repite el paso 2. Un solo incremento basta siempre (ver abajo).
4. El resultado es `precio_unitario_sin_iva`.

Solo puede existir **un** candidato válido, nunca dos: un centavo del precio sin IVA se convierte en
1.16 centavos del precio con IVA, mayor que la ventana de un centavo que define el redondeo.

#### Factor de IVA por artículo

`factor_iva` sale del `objeto_imp` del artículo (catálogo SAT `c_ObjetoImp`, ver
[006](006-gestion-articulos.md)):

| `objeto_imp` | Significado | `factor_iva` | Se redondea |
|---|---|---|---|
| `02` | Sí objeto de impuesto | 1.16 | el precio con IVA |
| `01` | No objeto de impuesto | 1.00 | el precio a secas |
| `03` | Sí objeto, no obligado al desglose | 1.00 | el precio a secas |
| `04` | Sí objeto, no causa impuesto | 1.00 | el precio a secas |

En los tres casos con factor 1.00 no hay un 16% que se sume encima de forma visible para el cliente,
así que el número que él lee es el precio sin IVA y es ése el que debe quedar entero. Con factor
1.00 la regla se degrada a `ceil` al peso: el objetivo siempre es alcanzable y nunca hay brinco.

Esto corrige de paso que hoy la ficha rotula "PRECIO CON IVA" y multiplica por 1.16 incluso en
artículos marcados como no objeto de impuesto.

La `tasa_iva` que se elige renglón por renglón al armar una factura o cotización **no interviene**:
es una decisión del documento ([007](007-facturacion.md)) y sigue siendo independiente del artículo.

#### Por qué no todo peso entero es alcanzable

Al multiplicar por 1.16, los saltos de un centavo del precio sin IVA se vuelven saltos de 1.16
centavos del precio con IVA. La ventana para caer en un peso exacto mide un centavo. Como el salto
es mayor que la ventana, **hay enteros que ningún centavo produce**: $7, $12, $17, $22, $36, $41...

Medido del $1 al $100,000: **13,793 enteros inalcanzables (13.8%)**, y **nunca dos consecutivos**.
Por eso el paso 3 de la regla necesita un solo incremento y termina siempre.

Consecuencia: el ajuste hacia arriba llega hasta **$1.99** sobre el precio con IVA (no hasta $0.99,
como se supondría de un redondeo al peso). Verificado por barrido sobre 500,000 precios crudos de
$0.01 a $5,000.00 en pasos de un centavo: cero fallos, ningún objetivo requirió más de un
incremento, ajuste máximo de **+$1.72** sobre el precio sin IVA.

En artículos caros el ajuste es despreciable en términos relativos (el sello de $233.48 sube 0.2%).
En artículos muy baratos es notorio: un precio crudo con IVA de $6.03 aterriza en $8.00, un 33%
arriba, porque el $7.00 intermedio es inalcanzable. Es el precio de la regla "siempre hacia arriba,
siempre entero" en montos pequeños, y se acepta: el catálogo no vende artículos de ese rango.

### Lo que este redondeo no alcanza: el total del documento

El número entero es una propiedad del **precio de lista del artículo**: la ficha, el listado, el
buscador y lo que se le contesta al cliente cuando pregunta cuánto cuesta.

En una factura o cotización, el IVA se calcula sobre el importe del renglón completo, no pieza por
pieza ([FacturaTotalesCalculator](../backend/app/Services/FacturaTotalesCalculator.php)). Con más de
una pieza, la fracción de centavo que absorbe el redondeo se acumula y el total del documento queda
unos centavos por debajo del múltiplo exacto:

```
1 pieza  →  201.72 + IVA  32.28 = $234.00     (= 1 × 234.00)
2 piezas →  403.44 + IVA  64.55 = $467.99     (2 × 234.00 = 468.00, −$0.01)
3 piezas →  605.16 + IVA  96.83 = $701.99     (−$0.01)
6 piezas → 1210.32 + IVA 193.65 = $1,403.97   (−$0.03)
```

Ese desfase **no se corrige aquí**, porque no es un problema del precio de lista: se cierra en el
total del documento, con el ajuste al peso de [030](030-total-al-peso-cerrado.md), que suma los
centavos faltantes como un concepto propio y deja el total en $468.00 y $1,404.00.

Lo que sí queda descartado para siempre es **calcular el IVA por pieza y multiplicarlo**: cuadra en
todas las cantidades, pero produce un importe de traslado distinto del 16% del importe del renglón
—con 10 piezas, 5 centavos arriba— y expone el timbrado a rechazo.

El PDF sigue imprimiendo el precio unitario **sin IVA**, como hoy
([019](019-formato-pdf-documentos.md)); el ticket de mostrador imprime el precio **con IVA**, por lo
que dice [030](030-total-al-peso-cerrado.md).

## Backend (Laravel)

### `PrecioArticuloCalculator`

Dos funciones nuevas y un cambio de firma:

- **`factorIva(?ObjetoImpuesto $objetoImp): float`** — devuelve `1.16` para `ObjetoImpuesto::SiObjeto`
  y `1.0` para los demás casos y para `null`.
- **`redondearAPesoEntero(float $precioCrudoSinIva, float $factorIva): float`** — implementa los
  cuatro pasos de la regla. Con precio crudo `0.00` devuelve `0.00` sin entrar al ciclo.
- **`calcularCadena(...)`** recibe un parámetro nuevo `?ObjetoImpuesto $objetoImp` y aplica el
  redondeo al final, antes de devolver `precio_unitario_sin_iva`. Sigue devolviendo las mismas tres
  claves; `precio_venta_crudo_sin_iva` no viaja en el resultado.

`precioVentaSinIva`, `costoConDescuento`, `costoTotal`, `utilidad`, `redondeo2`, `techo2`,
`utilidadEfectiva` y `precioProveedorAumentado` **no cambian**.

Todos los llamadores de `calcularCadena` (`ArticuloController::store`, `update` e `importarCsv`, el
recálculo en bloque de `CatalogoController` y `CatalogoProveedorController`, el mantenimiento masivo
de [021](021-mantenimiento-articulos-catalogos.md) y `ArticuloFactory`) tienen el `objeto_imp` a la
mano y lo pasan. Concentrar el eslabón aquí es lo que hace que ninguno de esos caminos necesite
lógica propia.

### Cambios sobre `Articulo`

- El accessor **`precio_unitario_con_iva`** deja de multiplicar siempre por `TASA_IVA_GENERAL` y pasa
  a multiplicar por `PrecioArticuloCalculator::factorIva($this->objeto_imp)`. Devuelve un entero
  exacto para cualquier artículo guardado por la cadena.
- **`utilidad`** no cambia de fórmula (`precio_unitario_sin_iva − costo_total`), pero su valor sube:
  ahora se mide contra el precio ya ajustado. El porcentaje capturado pasa a ser un **mínimo
  garantizado**, no un valor exacto.
- **Ningún cambio de esquema.** `precio_unitario_sin_iva` conserva `decimal(10,2)`, su nombre y su
  significado de "precio de venta unitario sin IVA". Facturación, Cotizaciones y el PDF siguen
  leyendo exactamente la misma columna.

### Migración de datos

Una migración de datos, sin cambios de estructura, que recorre todos los artículos y recalcula la
cadena con `PrecioArticuloCalculator`, mismo patrón que
`2026_08_04_000000_recalcular_cadena_de_precios_de_articulos`.

Es **determinista e idempotente**: `precio_proveedor`, `utilidad_porcentaje`, `tamano_goma` y
`objeto_imp` son entradas capturadas que no se tocan, y todo lo demás se deriva de ellas. Los precios
de venta existentes suben entre $0.00 y $1.99.

### Endpoints

Sin rutas nuevas y sin cambios de contrato. `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios`
sigue contando los artículos cuyo precio de venta cambiaría, y hereda el eslabón nuevo por usar el
calculador: un cambio de porcentaje que antes movía el precio y ahora aterriza en el mismo entero
deja de contarse, que es el conteo correcto.

### Validaciones

Sin cambios. `precio_unitario_sin_iva` sigue fuera de las reglas de validación y cualquier valor que
se envíe para él se ignora en silencio ([011](011-precio-proveedor-utilidad.md)).

### `ArticuloResource`

Sin campos nuevos. `precio_unitario_con_iva` ya viajaba y ahora trae el entero.

### Tests

- **Fixture compartido ampliado** (`shared/fixtures/precios-articulos.json`): cada caso gana
  `objeto_imp`, `precio_venta_crudo_sin_iva` (el valor que hoy figura como
  `precio_unitario_sin_iva`), y valores nuevos de `precio_unitario_sin_iva`, `precio_unitario_con_iva`
  y `utilidad`, derivados con aritmética entera de centavos, independiente de ambas implementaciones.
  Casos nuevos obligatorios: un objetivo inalcanzable que obliga al incremento; un precio crudo cuyo
  producto con IVA ya es entero y no debe moverse; un artículo con cada uno de los cuatro valores de
  `objeto_imp`; precio crudo `0.00`; y un artículo barato con brinco relativo grande.
- **Prueba de barrido**, en PHPUnit y en Vitest, sobre todos los precios crudos de $0.01 a $2,000.00
  en pasos de un centavo, con factor 1.16 y con factor 1.00, verificando en cada uno:
  1. `redondeo2(precio_final × factor_iva)` es un entero exacto;
  2. `precio_final ≥ precio_crudo` (el redondeo nunca baja el precio ni erosiona el markup);
  3. el ajuste sobre el precio con IVA es menor a $2.00.
  Es la prueba que detecta los enteros inalcanzables; una batería de casos sueltos no los habría
  encontrado.
- **Cadena completa**: que el recálculo por cambio de `descuento` y de `utilidad_porcentaje` del
  catálogo, la importación CSV, el mantenimiento masivo de 021 y el alta individual produzcan todos
  el mismo precio entero para las mismas entradas.
- **Migración**: tras correrla, ningún artículo tiene un `precio_unitario_con_iva` con centavos, y
  volver a correrla no cambia ningún valor.
- `ArticuloFactory` deriva la cadena completa incluyendo el redondeo; los tests de
  [007](007-facturacion.md), [008](008-cotizaciones.md) y [009](009-catalogos.md) que crean artículos
  siguen expresándose en términos de costo y markup.

## Frontend (Vue 3)

### Módulo de cálculo compartido

`src/lib/precioArticulo.ts` gana `factorIva` y `redondearAPesoEntero`, espejo exacto de las nuevas
funciones de PHP, y `calcularCadena` recibe el `objeto_imp`. El fixture compartido sigue siendo la
definición ejecutable que ata ambas implementaciones: cambiar una sin la otra rompe la suite del lado
no tocado.

### `/articulos/crear` y `/articulos/:id/editar`

El bloque de resumen de la cadena gana un renglón para que el ajuste no parezca un error de cálculo:

```
Precio de lista del proveedor      $120.00
Descuento del catálogo (0%)         −$0.00
Costo del aparato                  $120.00
Costo de la goma                   +$10.00
Costo                              $130.00
Utilidad (55%)                     +$71.50
Precio de venta sin IVA            $201.50
IVA (16%)                          +$32.24
Precio con IVA                     $233.74
Redondeo                            +$0.26
Precio final con IVA               $234.00
```

- El renglón de **Redondeo** solo aparece cuando el ajuste es mayor a cero.
- **Precio final con IVA** es el renglón destacado del bloque: es el número que ve el cliente.
- En un artículo con `objeto_imp` distinto de `02`, los renglones de IVA no se muestran y el bloque
  cierra en **Precio final**.
- El resumen se actualiza en vivo al cambiar cualquier campo capturado, al cambiar de catálogo y al
  cambiar el `objeto_imp`.
- La verificación del valor autoritativo de [011](011-precio-proveedor-utilidad.md) sigue vigente sin
  cambios: si el precio que devuelve el servidor al guardar no coincide con el que mostró el
  formulario, se advierte con el valor real en vez de navegar en silencio.

### `/articulos` (listado) y ficha del artículo

- El listado no cambia de columnas: sigue mostrando costo, precio de venta (sin IVA) y utilidad, que
  son los números con los que trabaja el usuario, no el cliente. La ordenación por las tres columnas
  numéricas sigue igual.
- La ficha del artículo (`ArticuloDetalleDialog`) ya muestra `precio_unitario_con_iva` y por lo tanto
  muestra el entero sin cambios de código. El rótulo "PRECIO CON IVA" pasa a ser "PRECIO" en
  artículos cuyo `objeto_imp` no es `02`.

## Fuera de alcance

- **Precios psicológicos**: terminaciones en .99, .90, .50 o "$199 en vez de $200". El redondeo es al
  peso entero hacia arriba y nada más.
- **Granularidad configurable**: no hay redondeo a múltiplos de $5, $10 ni $50, ni pantalla de
  ajustes, ni campo en el catálogo, ni interruptor por artículo para desactivar el redondeo. La regla
  es global y única.
- **Redondeo distinto por tipo de cliente**. Aunque las empresas toleren los centavos, el precio del
  artículo es uno solo para todos. Ninguna interacción con el descuento permanente de cliente de
  [015](015-descuento-permanente-cliente.md).
- **Redondeo dentro de los documentos**: ni del total de la línea ni re-redondeo del precio después
  de aplicar un descuento. Un descuento vuelve a producir centavos en ese renglón y así se queda. El
  total del documento sí se cierra al peso, pero eso lo define
  [030](030-total-al-peso-cerrado.md), no esta historia.
- **Cambios a Facturación, Cotizaciones, Tesorería y Órdenes de Compra**, incluido el cálculo de IVA
  por pieza y cualquier modificación a `FacturaTotalesCalculator`.
- **Cambios al PDF** ([019](019-formato-pdf-documentos.md)): no se agrega columna de precio con IVA
  ni se cambia el desglose.
- **Precio mínimo forzado**: un artículo con costo y utilidad en cero queda en $0.00; no se eleva a $1.
- **Redondeo hacia abajo o al más cercano**, y cualquier variante que pueda dejar el precio por
  debajo del markup capturado.
- **Historial** de los precios previos al redondeo, o registro del ajuste aplicado a cada artículo.
- Documentos ya emitidos: facturas y cotizaciones guardan su propia copia del precio y no se tocan.

## Criterios de aceptación

1. El precio con IVA de cualquier artículo guardado por el sistema es un peso entero exacto: termina
   en `.00`, sin centavos.
2. Un artículo con costo total $130.00 y 55% de utilidad tiene precio de venta sin IVA $201.72 y
   precio con IVA $234.00, en lugar de $201.50 y $233.74.
3. El redondeo nunca baja un precio: el precio de venta resultante es siempre mayor o igual al que
   produce el markup capturado, y la utilidad en pesos nunca queda por debajo de la que corresponde a
   ese porcentaje.
4. Cuando el precio con IVA que produce el markup ya es un entero exacto, el redondeo no lo mueve: un
   costo de $180.00 con 25% de utilidad da precio de venta $225.00 y precio con IVA $261.00.
5. Cuando el primer peso entero por encima del precio es inalcanzable, el sistema sube al siguiente:
   un precio crudo con IVA de $6.96 aterriza en $8.00, porque ningún precio sin IVA de dos decimales
   produce $7.00.
6. El ajuste sobre el precio con IVA nunca supera $2.00, y ningún objetivo requiere más de un
   incremento, verificado por barrido sobre todos los precios de $0.01 a $2,000.00 en pasos de un
   centavo.
7. Un artículo marcado como no objeto de impuesto, no obligado al desglose o que no causa impuesto
   tiene su precio sin IVA redondeado directo al peso entero, y su ficha no rotula el precio como
   "con IVA".
8. El mismo juego de casos produce resultados idénticos en el backend y en el módulo de cálculo del
   frontend, verificado por ambas suites contra el fixture compartido, incluidos los casos de entero
   inalcanzable y los cuatro valores de `objeto_imp`.
9. Dar de alta un artículo, editarlo, importarlo por CSV, recalcularlo por cambio de descuento del
   catálogo, por cambio de utilidad del catálogo o por mantenimiento masivo produce en todos los
   casos el mismo precio entero para las mismas entradas.
10. El formulario de artículo muestra en vivo la cadena completa incluyendo el renglón de redondeo y
    el precio final con IVA, y el renglón de redondeo se oculta cuando el ajuste es cero.
11. Cambiar el `objeto_imp` en el formulario actualiza el resumen en vivo, incluida la desaparición
    de los renglones de IVA.
12. Tras la migración, ningún artículo existente tiene un precio con IVA con centavos, ningún
    artículo pierde su `precio_proveedor` ni su `utilidad_porcentaje`, y volver a correr la migración
    no modifica ningún valor.
13. Facturación y Cotizaciones siguen precargando líneas con `precio_unitario_sin_iva` y calculando
    totales exactamente como antes, sin cambios de comportamiento respecto a 007/008.
14. En una factura de 2 o más piezas del mismo artículo, el importe de IVA de cada renglón sigue
    siendo el 16% del importe del renglón, sin excepciones: nunca se calcula IVA pieza por pieza. Los
    centavos que faltan para que el total sea el múltiplo exacto del precio con IVA los cierra el
    ajuste al peso de [030](030-total-al-peso-cerrado.md).
15. Pint, ESLint/Prettier y las suites de PHPUnit y Vitest corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. El número que hay que limpiar es el **precio con IVA**, no el de sin IVA: es el que el cliente lee
   en la ficha y por el que pregunta.
2. Se invierte quién manda en la cadena: el precio con IVA pasa a ser el número redondo elegido, y el
   precio sin IVA pasa a ser el derivado, con los decimales que le toquen.
3. El redondeo es **siempre hacia arriba**, nunca hacia abajo. El precio nunca baja para quedar
   bonito.
4. La granularidad es el **peso entero**, no múltiplos de $5 ni de $10.
5. Aplica a **todos los artículos, siempre**. No hay casilla por artículo ni por catálogo que active
   o desactive el redondeo.
6. El precio redondeado es el **precio de venta real**, no maquillaje de pantalla: es el que se
   precarga en cotizaciones y facturas y el que termina en el CFDI.
7. La utilidad en pesos sube respecto al markup capturado. El porcentaje capturado se vuelve un
   **mínimo garantizado**, no un valor exacto.
8. El resumen en vivo del formulario muestra el ajuste como un renglón propio, para que no se lea
   como un error de cálculo.
9. El listado `/articulos` sigue mostrando costo, precio de venta sin IVA y utilidad: son los números
   del usuario, no los del cliente.
10. El total de una línea de varias piezas **no queda redondo**. El IVA se calcula sobre el importe
    del renglón completo, y la fracción de centavo que absorbe el redondeo se acumula: 2 piezas de
    $234.00 dan $467.99 de importe más IVA. El renglón se queda así; lo que se cierra es el total del
    documento, en [030](030-total-al-peso-cerrado.md).
11. Un descuento —por línea, o el descuento permanente de cliente de
    [015](015-descuento-permanente-cliente.md)— vuelve a producir centavos en ese renglón, y el
    precio no se re-redondea. Este redondeo es una propiedad del precio de lista.
12. El total del documento se cierra al peso, pero con un mecanismo propio y en otra historia
    ([030](030-total-al-peso-cerrado.md)): un ajuste que se suma al final, no un re-redondeo de los
    precios ni de los importes de las líneas.
13. Todos los artículos existentes se recalculan. Sus precios con IVA suben entre **$0.00 y $1.99**:
    el tope no es $0.99 porque el 13.8% de los pesos enteros es inalcanzable y obliga a subir al
    siguiente.
14. El recálculo es determinista y repetible: precio del proveedor, porcentaje de utilidad, tamaño de
    goma y objeto de impuesto siguen siendo las únicas entradas capturadas.
15. El costo de la goma ([014](014-costo-elaboracion-goma.md)) entra donde entra hoy, antes del
    markup. El redondeo es el **último** eslabón, después del IVA.
16. Si el costo y la utilidad dan $0.00, el precio queda en $0.00; no se fuerza un mínimo de $1.
17. No hay precios psicológicos: nada de .99, .90 ni "$199 en vez de $200".
18. No hay redondeo distinto por tipo de cliente. El precio del artículo es uno solo para todos.
19. La regla no es configurable desde la interfaz: no se agrega pantalla de ajustes ni campo nuevo en
    catálogos.
20. Las cotizaciones y facturas ya emitidas no se tocan: guardan su propia copia del precio, como
    define 007/008.
21. **(Adición técnica)** El número redondo **no se persiste**. Se busca el centavo del precio sin IVA
    que lo produce y se guarda ése, en la misma columna `decimal(10,2)` de siempre. No hay cambio de
    esquema, ni columna nueva, ni ampliación de decimales, y por lo tanto Facturación, Cotizaciones y
    el PDF no se enteran. Ampliar la columna a seis decimales no habría servido: $234.00 ÷ 1.16 es un
    decimal que no termina, así que tampoco quedaría exacto.
22. **(Adición técnica)** Solo puede existir un centavo válido por objetivo, porque un centavo del
    precio sin IVA se traduce en 1.16 centavos del precio con IVA, más ancho que la ventana de
    redondeo. Basta probar los dos vecinos del cociente.
23. **(Adición técnica)** El 13.8% de los pesos enteros no es alcanzable por ningún precio sin IVA de
    dos decimales, pero **nunca hay dos inalcanzables consecutivos** (verificado del $1 al $100,000),
    de modo que un solo incremento del objetivo basta y el ciclo siempre termina.
24. **(Adición técnica)** En artículos muy baratos el brinco es relativamente grande —un precio con
    IVA de $6.03 aterriza en $8.00, 33% arriba— y se acepta: es la consecuencia directa de "siempre
    hacia arriba, siempre entero" y el catálogo no vende en ese rango.
25. **(Adición técnica)** El artículo decide qué número se redondea, según su `objeto_imp`: con
    `02` (sí objeto) se redondea el precio con IVA; con `01`, `03` y `04` se redondea el precio sin
    IVA, porque en esos casos no hay un 16% que el cliente vea sumarse encima. Usa el campo que ya
    existe y de paso corrige que hoy la ficha rotule "PRECIO CON IVA" en artículos sin impuesto.
26. **(Adición técnica)** La `tasa_iva` que se elige renglón por renglón en una factura sigue siendo
    independiente del artículo. No se fija la tasa en el artículo ni se hace que el documento
    reacomode precios al cambiarla: eso contradiría el supuesto 11 y sería un cambio grande a
    Facturación, que hoy funciona bien.
27. **(Adición técnica)** No se calcula el IVA pieza por pieza para hacer cuadrar los totales de
    varias piezas. Cuadraría, pero el importe de traslado dejaría de ser el 16% del importe del
    renglón —con 10 piezas, 5 centavos arriba— y expondría el timbrado a rechazo. Tres centavos no
    justifican el riesgo.
28. **(Adición técnica)** No se agrega al PDF una columna de precio con IVA: pondría "$468.00" en la
    fila y "$467.99" en el desglose de la misma hoja, una contradicción visible que es peor que el
    problema original.
29. **(Adición técnica)** Se descartó la única variante que cuadra exacta en cualquier cantidad
    —restringir los precios a los que son exactos por ambos lados, que obliga a que el precio con IVA
    sea múltiplo de $29 ($232, $261, $290...)— porque los saltos de precio serían del 12% y
    distorsionarían el markup capturado.
30. **(Adición técnica)** El eslabón vive dentro de `calcularCadena`, no en cada llamador, de modo
    que alta individual, edición, importación CSV, recálculo en bloque del catálogo y mantenimiento
    masivo de 021 lo heredan sin lógica propia.
31. **(Adición técnica)** El fixture compartido de 011 se **amplía**, no se duplica en un archivo
    nuevo: partir la definición del precio en dos archivos garantiza que con el tiempo uno se quede
    atrás. Cada caso gana el `objeto_imp`, el precio crudo intermedio y los valores finales.
32. **(Adición técnica)** Se agrega una **prueba de barrido** en ambas suites sobre todos los precios
    de $0.01 a $2,000.00 en pasos de un centavo, verificando que el precio con IVA es entero, que
    nunca bajó del markup pedido y que el ajuste es menor a $2.00. Es la prueba que detecta los
    enteros inalcanzables; una batería de casos escogidos a mano no los habría encontrado, y de hecho
    fue así como aparecieron.
