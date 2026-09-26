# Spec: Total del documento al peso cerrado (ajuste al peso)

## Historia de usuario

Como usuario único del sistema, quiero que el **total** de un ticket, una cotización o una factura
sea un número cerrado en pesos, igual que ya lo es el precio de cada artículo, para que el cliente
de mostrador no lea "$611.99" cuando compró tres sellos de $204.00 y su cuenta mental dice $612.00.
Los noventa y nueve centavos se leen como un precio calculado con calculadora —justo lo que
[024](024-precios-sin-centavos.md) vino a quitar del precio de lista— y el cliente los detesta
tanto en el total como los detestaba en la ficha del artículo.

## Objetivo / Alcance

Agregar un eslabón final al cálculo de totales de [007](007-facturacion.md)
(`FacturaTotalesCalculator` / `totalesDocumento.ts`): **el total de una factura, una cotización o un
pedido sube al peso cerrado**, y la diferencia se materializa en un campo propio del documento,
`ajuste_al_peso`.

Es la continuación de [024](024-precios-sin-centavos.md) en el documento. 024 dejó cerrado el
precio de una pieza y aceptó explícitamente que el total de varias piezas quedara unos centavos por
debajo del múltiplo; este documento reemplaza esa decisión: **el total también queda cerrado**.

**No** cambia el precio de lista, ni la cadena de precios de [011](011-precio-proveedor-utilidad.md)
/ [024](024-precios-sin-centavos.md), ni el cálculo del IVA por línea, ni el prorrateo del descuento
global. **No** aplica a [Órdenes de compra](012-ordenes-compra.md) ni al reabastecimiento de
[017](017-inventario.md): una orden paga lo que cobra el proveedor.

### De dónde salen los centavos

El ticket que originó esta historia, tres sellos autoentintables de $204.00:

```
precio_unitario (sin IVA)        $175.86      × 1.16 = $204.00 cerrado (024)
importe   3 × 175.86           = $527.58
IVA       16% de 527.58        =  $84.4128 → $84.41   ← aquí se pierde el centavo
total                            $611.99      pero 3 × $204.00 = $612.00
```

El IVA se calcula sobre el **importe del renglón completo**, no pieza por pieza, porque el CFDI
exige que el importe del traslado sea el 16% de la base del concepto (ver
[024](024-precios-sin-centavos.md), supuesto 27: calcularlo por pieza desfasa el traslado hasta 5
centavos con 10 piezas y expone el timbrado a rechazo). La fracción de centavo que absorbe el
redondeo de cada pieza se acumula, y el total cae por debajo del múltiplo exacto: hasta
`cantidad ÷ 2` centavos. Por eso los tickets terminan en `.99`, `.98`, `.97` y nunca en `.01`.

### La regla del ajuste

Después de calcular `total` exactamente como hoy (dos pasadas, prorrateo del descuento global antes
del IVA, ver [007](007-facturacion.md)):

1. **Objetivo**: `objetivo = ceil(total − TOLERANCIA)`, con `TOLERANCIA = 0.05`.
2. **Ajuste**: `ajuste_al_peso = max(0, redondeo2(objetivo − total))`.
3. **Total final**: `total = redondeo2(total + ajuste_al_peso)`.

El ajuste **nunca es negativo**: el total no baja jamás, igual que el precio de lista de 024 nunca
baja por redondeo. Su valor máximo es **$0.99**.

```
611.99  → objetivo 612.00 → ajuste +0.01 → total 612.00
467.99  → objetivo 468.00 → ajuste +0.01 → total 468.00     (2 × $234.00, el caso de 024)
1403.97 → objetivo 1404.00 → ajuste +0.03 → total 1404.00   (6 × $234.00)
234.00  → objetivo 234.00 → ajuste  0.00 → total 234.00     (ya cerrado, no se mueve)
183.59  → objetivo 184.00 → ajuste +0.41 → total 184.00     (una pieza con 10% de descuento)
600.01  → objetivo 600.00 → ajuste  0.00 → total 600.01     (dentro de la tolerancia, ver abajo)
```

#### Por qué la tolerancia de cinco centavos

El desfase del IVA por renglón casi siempre deja el total **por debajo** del múltiplo, pero no
siempre: el redondeo del precio con IVA de 024 puede caer del otro lado y, con cantidades altas,
empujar el total uno o dos centavos **por encima**. Un artículo de $20.69 sin IVA vale $24.00 con
IVA, y 25 piezas dan importe $517.25, IVA $82.76 exacto y total **$600.01**, cuando la cuenta mental
del cliente es 25 × $24.00 = $600.00.

Sin tolerancia, `ceil` llevaría ese total a **$601.00**: un peso completo que el cliente no pidió y
que no se puede explicar en el mostrador. La tolerancia de cinco centavos —el ruido máximo que el
redondeo del IVA puede meter en un documento de tamaño normal— absorbe exactamente ese caso y deja
el total en $600.01.

Es el escenario que el usuario planteó al pedir esta historia: *"mejor que suba a .01 cuando no sea
posible el redondeo"*. Un total uno o dos centavos **arriba** del peso cerrado se acepta; uno
noventa y nueve centavos **abajo**, no.

#### Por qué el ajuste no lleva IVA

El ajuste viaja al CFDI como un concepto propio **no objeto de impuesto** (`ObjetoImp 01`, sin
traslados). Esa decisión es lo que hace que la regla funcione siempre:

- Si el ajuste pagara IVA, un centavo de ajuste movería el total 1.16 centavos, y **el 13.8% de los
  pesos cerrados sería inalcanzable** —el mismo fenómeno que 024 documentó para el precio de lista,
  con sus enteros huérfanos ($7, $12, $17, $22, $36, $41…)—. El total aterrizaría en `.00` unas
  veces y en `.01` otras, sin regla legible.
- Sin IVA, el ajuste es dinero puro y **cualquier peso cerrado es alcanzable al centavo exacto**.

Fiscalmente el documento queda íntegro: cada concepto conserva `importe = cantidad × valor unitario`
y `traslado = 16% de la base`, que es lo que valida el SAT. El concepto de ajuste simplemente no
traslada impuesto.

```
CFDI:  SubTotal $527.59  (527.58 de la línea + 0.01 del ajuste)
       Traslados $84.41  (16% de 527.58, exacto)
       Total    $612.00
```

### Efecto sobre el múltiplo del precio de lista

Como el desfase por renglón es de centavos y el ajuste lo cierra hacia arriba, el total vuelve a ser
exactamente `cantidad × precio con IVA` en el caso normal de mostrador: tres sellos de $204.00 dan
$612.00, seis dan $1,404.00. La propiedad se sostiene mientras la suma de los desfases de todos los
renglones sea menor a un peso, es decir hasta unas **190 piezas** en un mismo documento. Por arriba
de eso el total sigue quedando en peso cerrado, pero puede no coincidir con el múltiplo mental; se
acepta, porque el mostrador no vende en ese volumen.

## Backend (Laravel)

### `FacturaTotalesCalculator`

- **`calcular(...)`** recibe un parámetro nuevo `bool $redondearAlPeso = false`, aplicado al final,
  después de todo lo demás. Devuelve una clave nueva **`ajuste_al_peso`** y un `total` que ya la
  incluye. Con `false` —el default— devuelve `ajuste_al_peso => 0.0` y el mismo total de hoy, byte
  por byte.
- **`ajusteAlPeso(float $total): float`** — implementa los tres pasos de la regla. Con `total`
  `0.00` devuelve `0.00`: un documento vacío no se eleva a $1.
- `prorratear`, `calcularDescuento` y las dos pasadas **no cambian**. El subtotal, los importes por
  línea, el IVA por tasa y el descuento total conservan su definición y su valor.

El default `false` es deliberado: quien redondea tiene que decirlo. Así
`OrdenCompraController::calcularYValidarTotal` y el reabastecimiento de
[`InventarioController`](../backend/app/Http/Controllers/InventarioController.php) siguen dando
exactamente los mismos números sin tocar una sola línea.

Pasan `true`: `FacturaController`, `CotizacionController` y `PedidoController`, en sus tres
`calcularYValidarTotal`.

### Esquema

Una columna nueva en `facturas`, `cotizaciones` y `pedidos`:

```php
$table->decimal('ajuste_al_peso', 4, 2)->default(0)->after('total_exento');
```

Se persiste junto con los demás totales y viaja en `FacturaResource`, `CotizacionResource` y
`PedidoResource`.

**Los documentos existentes no se recalculan.** La migración solo agrega la columna con default
`0.00`: una factura timbrada tiene su total estampado ante el SAT y no se toca, y una cotización o
un pedido viejos recuperan el ajuste la próxima vez que se guarden. No hay migración de datos.

### `FacturapiService`

`construirPayloadFactura()` agrega, **como último ítem** y solo cuando `ajuste_al_peso > 0`:

```php
[
    'quantity' => 1,
    'discount' => 0,
    'product' => [
        'description' => 'Ajuste al peso',
        'product_key' => '01010101',   // No existe en el catálogo
        'unit_key' => 'ACT',           // Actividad
        'price' => (float) $factura->ajuste_al_peso,
        'tax_included' => false,
        'taxes' => [],                 // sin traslados → ObjetoImp 01
    ],
]
```

`taxes` vacío es lo que hace que el concepto quede como no objeto de impuesto. **Hay que
verificarlo en el sandbox de facturapi.io antes de dar la historia por terminada** (ver criterio de
aceptación 11): es el único punto del diseño que no se puede confirmar leyendo el código, y ya hubo
un precedente caro con `tax_included` en [007](007-facturacion.md). Si facturapi.io exigiera
declarar la no objeción de otra forma (campo `taxability`), se agrega ese campo; lo que no cambia es
que el ajuste **no traslada IVA**.

El complemento de pago no se ve afectado: el monto pagado ya es el total del documento.

### `AutofacturaService`

Copia `ajuste_al_peso` del pedido a la factura, junto con los demás totales que ya copia
([AutofacturaService.php:127](../backend/app/Services/AutofacturaService.php#L127)). El ticket que
se llevó el cliente y el CFDI que él mismo se genera muestran **el mismo total**, que es la razón
por la que esta historia aplica a los tres documentos y no solo al ticket.

### `TicketPedidoService`

Dos cambios en la tira de 80 mm:

- El renglón de totales gana **`Ajuste al peso`** entre el IVA y el TOTAL, visible solo cuando es
  mayor a cero.
- La columna izquierda de cada línea pasa a imprimir el **precio unitario con IVA**
  (`redondeo2(precio_unitario × (1 + tasa de la línea))`), no el precio sin IVA. Hoy imprime
  `3 x $175.86` junto a un importe de `$611.99` que no es el producto de esos dos números y que el
  cliente no puede verificar; con el cambio imprime `3 x $204.00`, que multiplicado da el total que
  está leyendo abajo. El importe del renglón sigue siendo `importe + iva_importe`, como hoy.

### Tests

- **Fixture compartido ampliado** (`shared/fixtures/totales-documento.json`): cada caso gana
  `redondear_al_peso` y su `esperado` gana `ajuste_al_peso`. Los ocho casos actuales lo llevan en
  `false` y **no cambian de valores esperados** —es la prueba de que el eslabón no toca lo que ya
  funcionaba—. Casos nuevos obligatorios, todos con `redondear_al_peso: true`:
  1. 3 piezas a $175.86 al 16%: ajuste $0.01, total $612.00.
  2. 6 piezas a $201.72 al 16%: ajuste $0.03, total $1,404.00.
  3. 1 pieza a $201.72: ajuste $0.00, total $234.00 (ya cerrado, no se mueve).
  4. 1 pieza a $175.86 con 10% de descuento de línea: ajuste $0.41, total $184.00.
  5. 25 piezas a $20.69: ajuste $0.00, total $600.01 (dentro de la tolerancia).
  6. Una línea exenta de $100.50: ajuste $0.50, total $101.00.
  7. Documento sin líneas: total $0.00, ajuste $0.00.
  8. El caso 1 repetido con `redondear_al_peso: false`: total $611.99, ajuste $0.00.
- **Prueba de barrido**, en PHPUnit y en Vitest: para un artículo de precio con IVA cerrado y toda
  cantidad de 1 a 190, el total redondeado es exactamente `cantidad × precio con IVA`, el ajuste es
  menor a $1.00 y nunca negativo.
- **Cadena completa**: factura, cotización y pedido con las mismas líneas producen el mismo total; y
  una orden de compra con esas mismas líneas produce el total **sin** ajuste.
- **Autofactura**: la factura que genera el portal a partir de un pedido tiene el mismo `total` y el
  mismo `ajuste_al_peso` que el pedido.
- **Payload de facturapi.io**: con ajuste mayor a cero el payload trae el ítem extra sin impuestos y
  la suma de sus importes coincide con el `total` persistido; con ajuste cero, el payload no trae
  ítem extra.

## Frontend (Vue 3)

### `lib/totalesDocumento.ts`

Espejo exacto del backend: `calcularTotales` recibe `redondearAlPeso` (default `false`) y
`TotalesDocumento` gana `ajuste_al_peso`. El fixture compartido sigue siendo la definición
ejecutable que ata las dos implementaciones; el desincronizado entre PHP y TypeScript ya rompió el
timbrado una vez ([007](007-facturacion.md), bug del 2026-07-31) y aquí vuelve a ser crítico, porque
el backend rechaza con `422` el `total` que no coincide con el suyo.

Pasan `true`: `FacturaFormView`, `CotizacionFormView`, `PedidoFormView`, las vistas de mostrador de
[029](029-pwa-mostrador.md) (`MostradorVentaView`, `MostradorCotizacionView`, `MostradorFacturaView`
y `CarritoMostrador`). Pasa `false` —por omisión— `OrdenCompraFormView`.

### Bloques de totales

`DocumentoLineas.vue` gana el renglón **"Ajuste al peso"** entre el IVA y el Total, visible solo
cuando es mayor a cero, con el mismo tratamiento visual que el renglón de "Redondeo" del formulario
de artículo de [024](024-precios-sin-centavos.md): existe para que el centavo no parezca un error de
cálculo. Lo mismo en el detalle de factura, el detalle de cotización, el detalle de pedido, la barra
de totales del carrito de mostrador y el PDF de [019](019-formato-pdf-documentos.md).

## Fuera de alcance

- **Redondeo a múltiplos de $5, $10 o $50**, y cualquier granularidad configurable. La regla es el
  peso cerrado, global y única, sin pantalla de ajustes ni campo por cliente.
- **Redondeo hacia abajo**, y cualquier variante que pueda dejar el total por debajo de lo que suman
  las líneas.
- **Precios psicológicos** en el total: nada de .99, .90 ni .50. Sigue vigente lo de 024.
- **Cambios al precio de lista** ni a ninguna parte de la cadena de 011/014/024.
- **Cambios al cálculo del IVA por línea**, al prorrateo del descuento global o al importe que se
  persiste por línea. El eslabón es estrictamente el último.
- **Órdenes de compra** ([012](012-ordenes-compra.md)) y reabastecimiento de inventario
  ([017](017-inventario.md)): el proveedor cobra lo que cobra.
- **Complementos de pago y Tesorería** ([010](010-tesoreria.md)): reciben el total ya ajustado y no
  necesitan lógica propia. No hay cuenta de "ajustes por redondeo".
- **Documentos ya emitidos**: no se recalculan. Ninguna factura timbrada cambia de total.
- **Recalcular cotizaciones y pedidos viejos** con una migración de datos.
- **Historial** del total previo al ajuste.

## Estado de implementación

Implementada el 2026-08-17, salvo la verificación en el sandbox de facturapi.io.

- **El eslabón vive en un solo lugar por lado**: `FacturaTotalesCalculator::ajusteAlPeso()` y su
  espejo `ajusteAlPeso` de `totalesDocumento.ts`. Los cuatro llamadores del calculador que sí
  redondean lo piden con `redondearAlPeso: true`; `OrdenCompraController` e `InventarioController`
  no se tocaron y siguen dando los mismos números.
- **`FacturaFormView`, `CotizacionFormView` y `PedidoFormView` llaman `calcularTotales(...,
  redondearAlPeso: true)`** para el total que de verdad se envía al guardar, igual que
  `DocumentoLineas` lo pide para el renglón "Ajuste al peso" que muestran en pantalla: los dos
  cálculos tienen que coincidir entre sí y con el que hace el backend, o el criterio de aceptación 9
  se rompe y el guardado se rechaza con 422 por diferencia de centavos.
- **Un solo test existente cambió de números**: `DescuentoClienteTest` mandaba totales escritos a
  mano ($985.99 y $887.39) que ahora son $986.00 y $888.00. Ninguna otra prueba de las 566 se movió,
  que es la señal de que el eslabón no alteró subtotales, importes por línea ni IVA.
- **El barrido corre en las dos suites** sobre las 190 cantidades y sobre los 200,000 totales de
  $0.01 a $2,000.00. Es de donde salieron las 800 mil aserciones de la suite de PHPUnit.
- **Falta timbrar en el sandbox** una factura con ajuste para confirmar que facturapi.io acepta el
  concepto con `taxes: []` (criterio 11). Hasta entonces, el riesgo del supuesto 20 sigue abierto:
  el código está escrito y probado contra el payload, no contra la respuesta real del PAC.

## Criterios de aceptación

1. El total de una factura, una cotización o un pedido guardados por el sistema es un peso cerrado
   —termina en `.00`— o, cuando el cálculo cae dentro de los cinco centavos por encima de un peso
   cerrado, se queda ahí. Nunca termina en `.99`, `.98` ni `.97`.
2. Tres piezas de un artículo de $204.00 con IVA dan un total de **$612.00**, con `ajuste_al_peso`
   de $0.01, en lugar de los $611.99 de hoy.
3. El ajuste nunca es negativo y nunca supera $0.99: el total no baja jamás por redondeo.
4. Un total que ya es un peso cerrado no se mueve, y su `ajuste_al_peso` es $0.00.
5. Para un artículo de precio con IVA cerrado y cualquier cantidad de 1 a 190, el total es
   exactamente `cantidad × precio con IVA`, verificado por barrido en ambas suites.
6. Una orden de compra y un reabastecimiento de inventario calculan exactamente los mismos totales
   que antes de esta historia, con `ajuste_al_peso` en $0.00.
7. El subtotal, el importe de cada línea, el IVA por tasa y el descuento total conservan sus valores
   de hoy en todos los casos: lo único que cambia es el `total`.
8. El mismo juego de casos produce resultados idénticos en `FacturaTotalesCalculator` y en
   `totalesDocumento.ts`, verificado por ambas suites contra el fixture compartido.
9. El formulario muestra el renglón "Ajuste al peso" en vivo cuando es mayor a cero, y lo oculta
   cuando es cero; el total que envía el frontend coincide con el que recalcula el backend y ninguna
   factura, cotización o pedido se rechaza con `422` por diferencia de centavos.
10. El ticket de mostrador imprime el precio unitario **con IVA**, el renglón de ajuste cuando lo
    hay, y un TOTAL que es el múltiplo exacto del precio unitario por la cantidad.
11. Una factura con ajuste mayor a cero **se timbra correctamente en el sandbox de facturapi.io**, y
    el total que confirma facturapi.io coincide al centavo con el total guardado en la base. Es
    verificación en vivo, no un test con mock.
12. La factura que genera el portal de autofacturación a partir de un pedido tiene el mismo total
    que el ticket que se llevó el cliente.
13. Pint, ESLint/Prettier y las suites de PHPUnit y Vitest corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

Confirmados uno por uno con el usuario el 2026-08-17, los nueve funcionales y las once adiciones
técnicas, ninguno modificado.

1. El número que hay que cerrar es el **total del documento**, que es el que el cliente paga y
   compara contra su cuenta mental. El subtotal y el IVA se quedan como están.
2. El ajuste es **siempre hacia arriba**, nunca hacia abajo, igual que el redondeo del precio de
   lista de [024](024-precios-sin-centavos.md).
3. La granularidad es el **peso cerrado**, no múltiplos de $5 ni de $10.
4. Aplica a **factura, cotización y pedido/ticket**, para que los tres documentos de una misma venta
   digan el mismo número. No aplica a órdenes de compra ni a inventario.
5. El ajuste es **dinero real**: se cobra, entra a Tesorería con el pago y viaja en el CFDI. No es
   maquillaje de pantalla.
6. Se aplica **siempre**, no solo cuando faltan centavos para el múltiplo. Un documento con
   descuento o con líneas libres puede llevar un ajuste de hasta $0.99, y así se queda: la regla es
   "el total va en pesos cerrados", no "se corrige el desfase del IVA".
7. **Reemplaza los supuestos 10, 11 y 12 de [024](024-precios-sin-centavos.md)**, que declaraban que
   el total del documento no se redondeaba y que $467.99 se aceptaba tal cual. 024 se corrige en
   consecuencia; su redondeo del precio de lista sigue intacto.
8. Un documento vacío o de $0.00 no se eleva a $1.00.
9. Los documentos existentes no se recalculan. Las facturas timbradas conservan su total y las
   cotizaciones y pedidos viejos toman el ajuste al volverse a guardar.
10. **(Adición técnica)** El ajuste va al CFDI como un concepto **no objeto de impuesto**, sin
    traslados. Es lo que hace que todo peso cerrado sea alcanzable: con IVA encima, un centavo de
    ajuste movería el total 1.16 centavos y el 13.8% de los enteros quedaría fuera de alcance, el
    mismo fenómeno que 024 documentó en el precio de lista.
11. **(Adición técnica)** Se descartó absorber el ajuste **subiendo el precio unitario de la última
    línea**: se mueve en pasos de `cantidad` centavos —en el ticket de la foto aterrizaría en
    $612.03— y rompería la garantía de 024 de que el precio unitario con IVA es un peso cerrado.
12. **(Adición técnica)** Se descartó **redondear solo el cobro** en Tesorería dejando el documento
    en $611.99: el ticket seguiría imprimiendo el número que el cliente no quiere ver, y la
    autofactura diría un total distinto del cobrado.
13. **(Adición técnica)** Se descartó **calcular el IVA pieza por pieza**, por lo mismo que 024 en su
    supuesto 27: el traslado dejaría de ser el 16% exacto de la base del concepto y expondría el
    timbrado a rechazo.
14. **(Adición técnica)** La **tolerancia de cinco centavos** existe porque el redondeo del precio
    con IVA puede caer del otro lado y dejar el total uno o dos centavos **arriba** del múltiplo (25
    piezas de un artículo de $24.00 dan $600.01). Sin ella, `ceil` cobraría $601.00: un peso completo
    inexplicable en el mostrador. Cinco centavos es el ruido máximo que el redondeo del IVA mete en
    un documento de tamaño normal.
15. **(Adición técnica)** La consecuencia de la tolerancia es que un documento con descuento puede,
    ocasionalmente, quedar uno a cinco centavos por encima de un peso cerrado en vez de exactamente
    en él. Es el caso que el usuario aceptó de entrada al plantear la historia: mejor un total
    un centavo arriba que uno noventa y nueve centavos abajo.
16. **(Adición técnica)** El parámetro del calculador es **explícito y por default `false`**: quien
    redondea lo dice. Así órdenes de compra e inventario quedan intactos sin tocar sus llamadores, y
    un módulo futuro no hereda el redondeo por accidente.
17. **(Adición técnica)** El fixture compartido de totales se **amplía**, no se duplica: los ocho
    casos actuales se conservan con `redondear_al_peso: false` y valores esperados idénticos, como
    prueba de que el eslabón nuevo no movió nada de lo anterior.
18. **(Adición técnica)** El ticket de mostrador pasa a imprimir el **precio unitario con IVA**. Hoy
    imprime `3 x $175.86` al lado de un importe de `$611.99` que no es el producto de esos dos
    números: el cliente no puede verificar su propia cuenta. Con el precio con IVA, la línea, el
    total y la cuenta mental coinciden.
19. **(Adición técnica)** La propiedad "el total es el múltiplo exacto del precio de lista" se
    sostiene hasta unas 190 piezas por documento; por arriba de eso el total sigue en peso cerrado
    pero puede separarse del múltiplo. El mostrador no vende en ese volumen.
20. **(Riesgo registrado)** Que facturapi.io acepte un concepto con `taxes: []` se verifica en el
    sandbox **antes** de dar la historia por terminada. Si lo rechazara, el plan B es que el ajuste
    lleve IVA del 16%, y entonces el total aterriza en el peso cerrado la mayoría de las veces y un
    centavo arriba el resto —comportamiento aceptable, regla menos limpia—.
