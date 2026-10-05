# Spec: Precio distribuidor y cliente distribuidor

**Referencia:** reescritura de [remotas/033-precio-distribuidor.md](remotas/033-precio-distribuidor.md),
que se diseñó para la arquitectura anterior (Vue 3 + API). Se conservan las reglas de negocio: un
segundo precio de venta por artículo con su propia utilidad de dos niveles (catálogo / artículo), la
misma regla de peso entero (025) y de IVA por `objeto_imp`, el recálculo en cascada, la columna CSV
opcional al final, la ficha con dos precios y dos botones de compartir, y el cliente distribuidor
que hace nacer cada línea con el precio distribuidor en cotizaciones y facturas desde cero.
Extiende [009](009-precio-proveedor-utilidad.md), [025](025-precios-sin-centavos.md),
[005](005-gestion-clientes.md), [011](011-cotizaciones.md) y [012](012-facturacion.md).

| Remota | Aquí | Razón |
|---|---|---|
| Costo distribuidor = costo **sin goma** | El mismo `costo_con_descuento` del precio directo | La goma (remota 014) no existe en esta arquitectura (ver 025): los dos precios solo difieren en la utilidad |
| `ArticuloResource`, `CatalogoResource`, `ClienteResource`, `cliente_es_distribuidor` en los resources de documentos | Nada de eso: las vistas Blade leen el modelo | No hay API que alimentar |
| `GET …/impacto-precios` con tabla de vista previa | El conteo de `Catalogo::articulosAfectados()` y su paso de confirmación, que ya existían | Aquí el impacto es un conteo, no una tabla; ahora cuenta también los cambios del precio distribuidor |
| `aumentar-costos` | No existe aquí | — |
| Filtro de rango por columna (remota 025) | Solo ordenación | El listado local no tiene filtros de rango |
| `MostradorArticuloView.vue` (remota 031) | Solo la ficha del listado de artículos | La consulta de mostrador no existe aquí |
| `ClienteCombobox` / `ArticuloBuscador` traen los datos | El `select` de cliente y `GET /articulos/sugerencias` (ahora con `precio_distribuidor`) | Mismos datos, sin consulta nueva |
| Líneas de un documento guardado sin precios cacheados: el navegador consulta `GET /articulos/{id}` al cambiar de cliente | El servidor pinta los dos precios vigentes en cada línea (`data-precio-directo` / `data-precio-distribuidor`) | Una sola consulta al armar el formulario y ningún endpoint nuevo |
| Prop `precioDistribuidor` en `DocumentoLineas` | La presencia del aviso `[data-aviso-distribuidor]` activa la lógica en `documento-lineas.js` | Mismo patrón que el descuento permanente (023) |

## Historia de usuario

Como usuario único del sistema de facturación, quiero que cada artículo tenga, además del precio de
venta que ya calcula el sistema, un segundo precio para mis clientes distribuidores, con su propia
utilidad, para cotizar a cualquiera de los dos tipos de cliente sin sacar la cuenta a mano.

Además, quiero marcar en la ficha de cada cliente si es distribuidor, para que al armar una
cotización o una factura para ese cliente el sistema elija solo el precio distribuidor en cada
línea.

## Cadena de cálculo

Costo $200.00, utilidad directo 50%, utilidad distribuidor 25%, objeto de impuesto `02`:

```
                     costo_con_descuento  $200.00
                 ┌──────────────┴──────────────┐
      × (1 + 50%)  techo2               × (1 + 25%)  techo2
        $300.00 → con IVA $348.00         $250.00 → con IVA $290.00
      redondeo al peso entero (025)     redondeo al peso entero (025)
precio_unitario_sin_iva  $300.00     precio_distribuidor_sin_iva  $250.00
```

Con utilidad distribuidor 30%: $260.00 → $301.60 con IVA; $302 es inalcanzable con 1.16, así que
queda en **$261.21 → $303.00**. Sin IVA (`01`, `03`, `04`) los dos precios se llevan al peso a secas.

## Datos

Migración `2026_10_12_100000_precio_distribuidor` (esquema y relleno en el mismo archivo):

- `catalogos.utilidad_distribuidor_porcentaje` `decimal(5,2)` default `0`.
- `articulos.utilidad_distribuidor_porcentaje` `decimal(5,2)` nullable (`null` = hereda).
- `articulos.precio_distribuidor_sin_iva` `decimal(10,2)`, lo escribe solo el modelo.
- Relleno de todos los artículos, incluidos los eliminados: con 0% heredado, el precio distribuidor
  arranca en el costo llevado al peso entero.

Migración `2026_10_12_100001_add_es_distribuidor_to_clientes_table`: `clientes.es_distribuidor`
`boolean` NOT NULL default `false`. Ningún documento guardado se recalcula.

## Backend

- **`Articulo`**: `calcularPrecio($descuento, $utilidad, $utilidadDistribuidor)` devuelve
  `[costo, venta, distribuidor]` con `precioVentaFinal()` para los dos; `recalcularPrecio()` escribe
  los tres. El evento `saving` también recalcula cuando cambia `utilidad_distribuidor_porcentaje`.
  Accessors `precio_distribuidor_con_iva` y `utilidad_distribuidor_porcentaje_efectivo`. Orden
  `distribuidor` en `ORDENES`. `utilidad` y las existencias siguen midiéndose contra el
  precio directo.
- **`Catalogo`**: el recálculo en bloque se dispara con el descuento (todos), la utilidad directo
  (los que la heredan) o la distribuidor (los que heredan esa); si cambian las dos, los que heredan
  cualquiera. `articulosAfectados()` cuenta un artículo si se mueve cualquiera de sus dos precios
  (el tercer argumento es opcional y por omisión es el valor actual).
- **Validación**: igual que `utilidad_porcentaje` en cada contexto — en artículo y CSV `nullable`,
  `0` a `999.99`, 2 decimales; en catálogo obligatoria, en blanco vale `0`.
- **CSV**: `utilidad_distribuidor_porcentaje` al final de `COLUMNAS_CSV`, en
  `COLUMNAS_CSV_OPCIONALES`: un archivo de 7 columnas se importa como si la celda viniera vacía. La
  exportación la escribe (vacía si se hereda).
- **`ClienteRequest`**: `es_distribuidor` ausente o en blanco vale `false`; después
  `required|boolean`. Solo en el formulario: la importación CSV de clientes (024) no lo trae.
- **Sugerencias** (`GET /articulos/sugerencias`): con precio de venta agregan `precio_distribuidor`;
  con `precio=costo` (orden de compra) no.
- **`Articulo::conPreciosDeVenta($user, $lineas)`**: agrega `precio_directo` y
  `precio_distribuidor` vigentes a las líneas de artículo del usuario (una consulta; los artículos
  ajenos o eliminados no). Lo usan `CotizacionController::datosFormulario()` (también la ventana del
  dashboard) y `FacturaController::datosFormulario()`.
- **Clientes distribuidores** (`distribuidores`: `{id: razón social}`) en los datos de los dos
  formularios. En la factura vale `null` —y el aviso no se pinta— cuando viene de una cotización
  (precarga, `old('cotizacion_id')` o la corrección de una factura con `cotizacion_id`).

## Navegador (Blade + JavaScript nativo)

- **Artículo**: tarjeta nueva "Precio distribuidor" con el campo, el aviso de utilidad alta (032) y
  su cadena (costo → utilidad distribuidor → precio sin IVA → IVA → redondeo → precio distribuidor
  final). Las dos cadenas son el parcial `articulos/_resumen-precio`; `precio-articulo.js` lee de
  `data-herencia` qué utilidad del catálogo hereda el campo vacío. El aviso al guardar trae los dos
  precios.
- **Catálogo**: campo "Utilidad distribuidor (%)" con su aviso de utilidad alta, y columna en el
  listado. La confirmación dice "Se recalculará el precio de venta o el precio distribuidor de N
  artículos".
- **Listado de artículos**: columna "Precio distribuidor" (con IVA si aplica), ordenable.
- **Ficha**: los dos precios, cada uno con su rótulo, y dos botones ("Compartir precio" /
  "Compartir precio distribuidor"); el texto compartido lleva solo el precio de su botón.
- **Cliente**: casilla "Es distribuidor" en "Datos comerciales" con la ayuda *"Sus cotizaciones y
  facturas usarán el precio distribuidor de cada artículo en vez del precio de lista."*; insignia
  "Distribuidor" (o guion) en el listado.
- **`documentos/_aviso-distribuidor`**: `x-alerta tipo="info"` dentro del formulario, sobre las
  líneas, con `data-clientes-distribuidores`. Su presencia activa en `documento-lineas.js`:
  - el artículo que se agrega nace con `precio_distribuidor` si el cliente elegido es distribuidor
    (la sugerencia también lo muestra), y la fila guarda los dos precios en `data-*`;
  - cambiar de cliente reemplaza el precio de **todas** las líneas que tienen los dos precios,
    aunque se hayan editado a mano; las líneas libres no se tocan;
  - el aviso se muestra solo con un cliente distribuidor: *"**FERRETERIA LOPEZ** es distribuidor:
    cada línea usa el precio distribuidor."*, y en la cotización además *"Puedes cambiarlo línea por
    línea si esta cotización es una excepción."*
- Va en el formulario de cotización, en la ventana "Nueva cotización" del dashboard y en el de
  factura que no viene de una cotización. Convive con el descuento permanente (023): el descuento se
  sigue aplicando encima del precio que toque.

## Fuera de alcance

Igual que la remota: ventas de mostrador (019), órdenes de compra, utilidad o precio distribuidor
por cliente, niveles de distribuidor, reportes, historial, recálculo de documentos guardados,
existencias valuadas al precio distribuidor, cambios a la regla de redondeo o de IVA. Además:

- **Duplicar** una cotización o una factura para otro cliente no cambia los precios (la copia
  conserva los del original); solo cambiar el cliente dentro del formulario los reemplaza.
- El **timbrado directo** de una cotización y la autofactura de su venta usan el precio cotizado,
  como la factura desde una cotización.

## Criterios de aceptación

1. Costo $200.00 al 50% / 25% (`02`) da $300.00 / $348.00 y $250.00 / $290.00; al 30% el distribuidor
   da $261.21 / $303.00.
2. Un artículo sin utilidad distribuidor propia hereda la del catálogo y el formulario la muestra
   como placeholder.
3. Cambiar el descuento del catálogo recalcula los dos precios de todos sus artículos; cambiar una
   utilidad mueve solo el precio que corresponde, y solo en los artículos que la heredan.
4. Una utilidad distribuidor negativa, mayor a 999.99 o con 3 decimales se rechaza; 0 y 450 se
   aceptan.
5. El listado muestra y ordena (en las dos direcciones) por "Precio distribuidor".
6. El formulario de artículo muestra en vivo la cadena distribuidor junto a la directa.
7. Un CSV con la columna vacía hereda; con valor guarda la propia; uno de 7 columnas se importa;
   exportar y reimportar no pierde el dato.
8. La ficha muestra los dos precios y comparte cada uno por separado.
9. Tras la migración ningún artículo queda sin precio distribuidor.
10. La casilla "Es distribuidor" se marca y desmarca; ausente vale "no"; los clientes existentes
    quedan sin marcar; el listado muestra la insignia.
11. En la cotización y en la factura desde cero, un artículo agregado para un cliente distribuidor
    nace con el precio distribuidor, editable; cambiar de cliente reemplaza el precio de todas las
    líneas de artículo, incluidas las cargadas de un documento guardado.
12. La factura de una cotización conserva el precio cotizado y no muestra el aviso.
13. Marcar un cliente no modifica cotizaciones ni facturas guardadas.
14. Pint, la suite Pest y `node --test "tests/js/*.test.js"` pasan.

## Supuestos asumidos

1. Sin la goma, "costo neto" es `costo_con_descuento`; los dos precios parten de él.
2. La utilidad "directo" es `utilidad_porcentaje`; no se renombra nada.
3. El precio distribuidor hereda sin excepción el peso entero (025) y el IVA por `objeto_imp`.
4. **(Adición técnica)** Los dos precios vigentes de cada línea los pinta el servidor en lugar de
   consultarlos al cambiar de cliente.
5. **(Adición técnica)** El catálogo cuenta como "afectado" un artículo al que se le mueve
   cualquiera de los dos precios, para que el paso de confirmación no se salte un cambio que solo
   mueve el distribuidor.
6. **(Adición técnica)** La columna CSV nueva es opcional en el encabezado, para que los archivos
   exportados antes sigan importándose.

## Estado de implementación

Implementada el 2026-10-04.

- **Archivos nuevos**: las dos migraciones (**aplicadas en la base local**: 48 artículos, ninguno
  sin precio distribuidor), `articulos/_resumen-precio`, `documentos/_aviso-distribuidor` y
  `tests/Feature/PrecioDistribuidorTest.php` (32 pruebas).
- **Archivos modificados**: `Articulo`, `Catalogo`, `Cliente`, `ClienteFactory`; `ArticuloController`,
  `CatalogoController`, `CotizacionController`, `FacturaController`,
  `ExportacionArticulosController`; `ArticuloRequest`, `CatalogoRequest`, `ClienteRequest`;
  `ImportadorArticulosCsv`; vistas de artículos (formulario, listado, ficha, importar), catálogos,
  clientes, `documentos/_linea`, `cotizaciones/_formulario`, `_dialogo-nueva` y
  `facturas/_formulario`; `precio-articulo.js`, `ficha-articulo.js` y `documento-lineas.js`; el
  fixture compartido (cuatro casos con distribuidor, recorridos por Pest y node) y las expectativas
  de `ArticuloTest`, `CatalogoTest` e `ImportacionArticulosTest`.
- **Verificación**: `php artisan test` con 1219 pruebas en verde, `node --test "tests/js/*.test.js"`
  (50) y Pint.

  **No se revisó la UI en un navegador real.** Falta probar en vivo: la segunda cadena del
  formulario de artículo, los dos botones de compartir, la casilla del cliente, y en cotización y
  factura el precio con que nace la línea, el reemplazo al cambiar de cliente y el aviso.
- **Producción**: falta desplegar (`bash deploy/deploy.sh`); la migración rellena el precio
  distribuidor de todos los artículos de producción (con 0% de utilidad hasta capturarla por
  catálogo).
