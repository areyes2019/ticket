# Spec: Precios sin centavos (redondeo del precio con IVA al peso entero)

**Referencia:** reescritura de [remotas/024-precios-sin-centavos.md](remotas/024-precios-sin-centavos.md),
que se diseñó para la arquitectura anterior (Vue 3 + API). Se conservan todas las reglas de negocio
(regla de redondeo, factor de IVA por `objeto_imp`, siempre hacia arriba, sin cambio de esquema,
migración de datos, barrido de verificación); la parte del navegador se rehízo para Blade +
JavaScript nativo. Extiende [009-precio-proveedor-utilidad.md](009-precio-proveedor-utilidad.md).

## Historia de usuario

Como usuario único del sistema de facturación, quiero que el precio que ve el cliente sea siempre un
número cerrado en pesos, sin centavos, para que un cliente de mostrador lea "$234.00" en lugar de
"$233.74". Un precio con decimales se lee como un precio sacado con calculadora y no como un precio
de lista.

## Objetivo / Alcance

Agregar un eslabón final a la cadena de [009](009-precio-proveedor-utilidad.md): **el precio que lee
el cliente siempre es un peso entero**. Con objeto de impuesto `02` ese precio es el precio con IVA;
con `01`, `03` y `04` es el precio a secas.

El ajuste es **siempre hacia arriba**: el precio nunca baja y el porcentaje de utilidad capturado se
vuelve un **mínimo garantizado**. `precio_unitario_sin_iva` deja de ser un número redondo y pasa a ser
el valor de dos decimales que, con el IVA, produce el entero.

**No** cambia Facturación, Cotizaciones, Pedidos, Tesorería, Órdenes de compra ni el PDF: todos siguen
leyendo `precio_unitario_sin_iva`. La costura de la goma de la remota (014) no existe en esta
arquitectura y no se agrega.

### Cadena de cálculo

Precio de lista $130.00, sin descuento, 55% de utilidad, objeto de impuesto `02`:

```
precio_proveedor            (capturado)                $130.00
  ↓ × (1 − descuento / 100)            redondeo2
costo_con_descuento         (calculado, persistido)    $130.00
  ↓ × (1 + utilidad_efectiva / 100)    techo2
precio de venta crudo       (intermedio, no se guarda) $201.50   → con IVA $233.74
  ↓ redondeo al peso entero del precio que lee el cliente   ← ESLABÓN NUEVO
precio_unitario_sin_iva     (calculado, persistido)    $201.72
  ↓ × factor_iva                                       1.16
precio_unitario_con_iva     (calculado al leer)        $234.00

utilidad = precio_unitario_sin_iva − costo_con_descuento = $71.72
```

### La regla de redondeo

Dado el precio crudo sin IVA y el factor de IVA del artículo:

1. `objetivo = ceil(precio_crudo × factor)` (quitando antes el ruido de punto flotante a 6 decimales:
   `225 × 1.16` debe dar 261, no 262). Si ya es entero, no hay ajuste.
2. Se prueban los dos centavos vecinos de `objetivo ÷ factor` y se toma el que cumple
   `redondeo2(candidato × factor) = objetivo`. Solo uno puede cumplir.
3. Si ninguno cumple, el objetivo es inalcanzable: se sube un peso y se repite.
4. Precio crudo $0.00 devuelve $0.00 (no se fuerza un mínimo).

Con 1.16, un centavo sin IVA son 1.16 centavos con IVA, más que la ventana de un centavo: el 13.8%
de los pesos enteros no los produce ningún precio de dos decimales ($7, $12, $17…), pero nunca hay
dos seguidos. Por eso el ajuste llega hasta **$1.99** con IVA (máximo +$1.72 sin IVA, medido) y un
artículo muy barato puede brincar mucho: $6.96 aterriza en $8.00.

| `objeto_imp` | factor | Se redondea |
|---|---|---|
| `02` Sí objeto | 1.16 | el precio con IVA |
| `01`, `03`, `04` | 1.00 | el precio a secas (`ceil` al peso) |

La tasa de IVA que se elige en cada renglón de una factura o cotización no interviene.

### Lo que no alcanza: el total de un documento

Con varias piezas, el IVA se calcula sobre el importe del renglón, así que 2 × $234.00 dan $467.99.
Se queda así: el cierre del total al peso es [remotas/030](remotas/030-total-al-peso-cerrado.md),
otra historia. Nunca se calcula el IVA pieza por pieza.

## Backend (Laravel)

### `CalculadoraPrecioArticulo`

- **`tasaIva(?ObjetoImpuesto)`**: `Articulo::TASA_IVA` para `02`, `0` para el resto y para `null`.
- **`factorIva(?ObjetoImpuesto)`**: `1 + tasaIva()`.
- **`redondearAPesoEntero($crudo, $factor)`**: los cuatro pasos de la regla.
- **`precioVentaFinal($costo, $utilidad, ?ObjetoImpuesto)`**: markup y luego redondeo. Es lo que
  persiste el modelo.

`redondeo2`, `techo2`, `costoConDescuento`, `precioVentaSinIva`, `utilidad` y `precioConIva` no
cambian.

### `Articulo`

- `calcularPrecio()` usa `precioVentaFinal()` con su `objeto_imp`. Como lo usan `recalcularPrecio()`
  (alta, edición, importación CSV, recálculo en bloque del catálogo) y `Catalogo::articulosAfectados()`,
  todos heredan el eslabón sin lógica propia. El conteo de impacto deja de contar un cambio que
  aterriza en el mismo peso.
- El evento `saving` también recalcula cuando cambia `objeto_imp`.
- El accessor `precio_unitario_con_iva` aplica `tasaIva($this->objeto_imp)` en lugar de siempre
  `TASA_IVA`; para cualquier artículo guardado es un entero exacto.
- `utilidad` no cambia de fórmula; su valor sube con el ajuste.
- Sin cambio de esquema.

### Migración de datos `2026_10_09_100000_precios_sin_centavos`

Solo datos. Recalcula `precio_unitario_sin_iva` de todos los artículos, incluidos los eliminados, a
partir del `costo_con_descuento` guardado, la utilidad efectiva y el `objeto_imp`. Las entradas
capturadas no se tocan, así que es determinista y se puede repetir. Los precios suben de $0.00 a
$1.99. `down()` regresa al markup sin redondeo.

### `ArticuloController`

- `resumenPrecio()` arma la cadena del formulario con el precio crudo, el IVA, el precio con IVA
  crudo, el **Redondeo** y el **Precio final** (con o sin "con IVA"). Marca como ocultos los renglones
  de IVA si no es objeto `02` y el de redondeo si no hubo ajuste.
- El aviso al guardar dice "Precio de venta con IVA: $X." con `02` y "Precio de venta: $X." con el
  resto.

## Navegador (Blade + JavaScript nativo)

- `public/js/precio-articulo.js` gana `redondearAPesoEntero` y `factorIva`, espejo exacto de PHP.
- El resumen del formulario (`<dl data-resumen-precio>`) gana `data-objeto="objeto_imp"`; cada
  renglón es un `<div data-renglon="…">`. El JS recalcula en vivo al cambiar precio, utilidad,
  catálogo y **objeto de impuesto**; muestra u oculta los renglones de IVA, el de redondeo y el
  sufijo "con IVA" del precio final. Sin objeto elegido supone `02`.

```
Precio de lista del proveedor      $130.00
Descuento del catálogo (0%)         −$0.00
Costo                              $130.00
Utilidad (55%)                     +$71.50
Precio de venta sin IVA            $201.50
IVA (16%)                          +$32.24
Precio con IVA                     $233.74
Redondeo                            +$0.26
Precio final con IVA               $234.00
```

- Ficha del listado: el enlace lleva `data-etiqueta-precio` ("Precio con IVA" o "Precio") y
  `ficha-articulo.js` lo pone en el rótulo, que antes decía "Precio con IVA" siempre.
- El listado no cambia de columnas.

## Fuera de alcance

Precios psicológicos (.99, .90), granularidad configurable ($5, $10), interruptor por artículo o
catálogo, redondeo por tipo de cliente, re-redondeo en documentos (un descuento de línea vuelve a
producir centavos y así se queda), cambios a documentos ya emitidos, cambios al PDF, precio mínimo
forzado, redondeo hacia abajo o al más cercano, historial de precios previos.

## Criterios de aceptación

1. El precio que lee el cliente de cualquier artículo guardado termina en `.00`.
2. Costo $130.00 al 55% (`02`) da $201.72 sin IVA y $234.00 con IVA.
3. El redondeo nunca baja el precio ni la utilidad respecto al markup capturado.
4. Costo $180.00 al 25% da $225.00 y $261.00: no se mueve.
5. Precio crudo $6.00 ($6.96 con IVA) aterriza en $6.90 / $8.00, porque $7.00 es inalcanzable.
6. El barrido de $0.01 a $2,000.00 con factor 1.16 y 1.00 da siempre un peso entero, nunca baja y el
   ajuste es menor a $2.00, en PHP y en JavaScript.
7. Con `01`, `03` o `04`, el precio a secas queda en el peso entero (130 al 55% → $202.00) y ni la
   ficha ni el aviso al guardar lo rotulan como "con IVA".
8. El fixture compartido da los mismos resultados en Pest y en `node --test`, incluidos el objetivo
   inalcanzable y los cuatro `objeto_imp`.
9. Alta, edición, importación CSV y recálculo por descuento o utilidad del catálogo producen el mismo
   precio entero para las mismas entradas.
10. El formulario muestra el renglón de redondeo solo cuando hubo ajuste, y cambiar el objeto de
    impuesto actualiza el resumen en vivo.
11. Tras la migración ningún artículo tiene precio con centavos, no cambia ningún precio de lista,
    utilidad ni costo, y repetirla no mueve nada.
12. Facturación y Cotizaciones siguen precargando `precio_unitario_sin_iva` y calculando igual.
13. Pint, la suite Pest y `node --test "tests/js/*.test.js"` pasan.

## Estado de implementación

Implementada el 2026-10-02.

- **Archivos nuevos**: la migración `2026_10_09_100000_precios_sin_centavos` (**aplicada en la base
  local**: 48 artículos, ninguno con centavos) y `tests/Feature/PreciosSinCentavosMigracionTest.php`.
- **Archivos modificados**: `CalculadoraPrecioArticulo`, `Articulo`, `ArticuloController`; vistas
  `articulos/_formulario`, `_filas` y `_ficha`; `precio-articulo.js` y `ficha-articulo.js`; el fixture
  `tests/Fixtures/precios-articulos.json` (cada caso gana `objeto_imp`, `precio_venta_crudo` y
  `precio_con_iva`; seis casos nuevos), sus dos recorridos con el barrido, y las expectativas de
  precio de `ArticuloTest`, `CatalogoTest`, `ImportacionArticulosTest`, `CotizacionesTest` y
  `ExistenciasTest`.
- **Decisiones al implementar**:
  - Los valores nuevos del fixture se derivaron con aritmética entera de centavos (BigInt), aparte de
    las dos implementaciones.
  - El encabezado de la columna del listado sigue diciendo "Precio con IVA" aunque en artículos sin
    IVA muestre el precio a secas.
- **Verificación**: `php artisan test` con 1123 pruebas en verde, `node --test "tests/js/*.test.js"`
  (46) y Pint sin cambios.

  **No se revisó la UI en un navegador real.** Falta probar en vivo el resumen del formulario (renglón
  de redondeo, cambio de objeto de impuesto) y el rótulo de la ficha.
- **Producción**: falta desplegar (`bash deploy/deploy.sh`); la migración recalcula los precios de
  todos los artículos de producción.
