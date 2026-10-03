# Spec: Descuento permanente por cliente

> **Estado: implementada** el 2026-10-02. Definida con el usuario ese mismo día (ver "Supuestos
> asumidos" y "Estado de implementación").

**Referencia:** reescritura de [remotas/015-descuento-permanente-cliente.md](remotas/015-descuento-permanente-cliente.md),
que se diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan las reglas de
negocio: el porcentaje en la ficha del cliente, su precarga editable en las líneas de la
cotización, la copia congelada, y la factura que no muestra el descuento pero cobra lo mismo. La
parte del navegador se rehízo para Laravel + Blade + JavaScript nativo, y se agregan tres caminos
que la remota no conocía: el timbrado directo (020), la venta de una cotización aceptada con su
autofactura (021/019) y el duplicado con cambio de cliente (015).

**Modifica:**

- [005-gestion-clientes.md](005-gestion-clientes.md): campo nuevo en la ficha y columna en el
  listado.
- [011-cotizaciones.md](011-cotizaciones.md): copia congelada, precarga del descuento en las líneas y
  aviso en el formulario y en la ventana "Nueva cotización" del dashboard.
- [015-cotizacion-a-factura-y-duplicar.md](015-cotizacion-a-factura-y-duplicar.md): la factura de una
  cotización llega sin descuento de línea, y el duplicado para otro cliente toma el descuento del
  nuevo.
- [020-dashboard-cotizaciones-facturas.md](020-dashboard-cotizaciones-facturas.md): el timbrado
  directo esconde el descuento igual que el formulario.
- [019-pedidos-mostrador.md](019-pedidos-mostrador.md) / [021](021-cotizacion-aceptada-a-venta.md):
  la autofactura de una venta que viene de una cotización esconde el descuento.
- [003-estilo-uniforme.md](003-estilo-uniforme.md): `x-alerta` gana la variante `info`.

**No modifica:** facturas creadas desde cero o duplicadas desde otra factura, órdenes de compra
(017), Tesorería (016), inventario (018), ventas de mostrador ni sus autofacturas, ni el PDF de la
cotización.

## Historia de usuario

Como usuario registrado quiero poder ofrecer a mis clientes un descuento permanente. Esto es, poder
clasificar a cierto cliente con un descuento no mayor al 50%. Así cada vez que yo le cotice algo a
ese cliente, el descuento se genera automáticamente sobre cada línea de artículos. Este movimiento
debería ser transparente a la factura. Únicamente será visible en la cotización.

## Qué significa "transparente a la factura"

Cliente con **15%**, un artículo de **$333.33** × **3**, IVA 16%, sin descuento global:

| | Cotización | Factura |
| --- | --- | --- |
| Precio unitario | $333.33 | **$283.33** |
| Descuento de línea | 15% | **—** |
| Importe de línea | $849.99 | $849.99 |
| Subtotal | $999.99 | **$849.99** |
| Descuento | $150.00 | **$0.00** |
| IVA 16% | $136.00 | $136.00 |
| **Total** | **$985.99** | **$985.99** |

> **Diferencia con la remota.** Allá el `subtotal` ya venía neto del descuento de línea y por eso no
> cambiaba. Aquí `CalculadoraTotalesDocumento` suma el **bruto** en el subtotal y pone los
> descuentos de línea en el renglón de descuento, así que en la factura cambian **tres** renglones:
> el precio, el subtotal y el descuento. El total y el IVA son idénticos, que es lo que importa.

## Datos

Una migración (`2026_10_08_100000_descuento_permanente_cliente`):

- `clientes.descuento_permanente` `decimal(5,2)` NOT NULL default `0.00`. No es nullable: `NULL` y
  `0` significarían lo mismo.
- `cotizaciones.descuento_cliente_porcentaje` `decimal(5,2)` NOT NULL default `0.00`: la **copia
  congelada** del descuento del cliente al capturar la cotización.
- Todo lo existente queda en `0.00`. No se recalcula ningún documento.

Sin índices: nunca se filtra por estas columnas. Sin historial ni vigencia.

## Ficha del cliente

- `ClienteRequest`: el campo en blanco o ausente se normaliza a `0` en `prepareForValidation`;
  después `required|numeric|decimal:0,2|min:0|max:50` (`Cliente::DESCUENTO_MAXIMO`), con mensajes
  propios. El tope aplica al dato del cliente, no al resultado de la cotización.
- Formulario: "Descuento permanente (%)" en "Datos comerciales", con la ayuda *"Se aplicará
  automáticamente a cada línea de las cotizaciones de este cliente. Máximo 50%."*
- Listado: columna "Descuento" antes de "Acciones", con `15%`, `12.5%` o `—`
  (`Cliente::descuentoPermanenteTexto()`).

## Cotización

### Copia congelada

`Cotizacion::congelarDescuentoCliente()` es el único que escribe `descuento_cliente_porcentaje`; nunca
llega del formulario (lo que mande el navegador se ignora porque no es `fillable`).

- **Alta**: se copia el descuento vigente del cliente.
- **Edición**: solo si cambió el cliente (`isDirty('cliente_id')`).
- **Duplicar para el mismo cliente**: copia exacta, con el congelado del original.
- **Duplicar para otro cliente** (supuesto 12 aplicado a la 015 local): se congela el descuento del
  cliente nuevo y **todas** las líneas toman ese porcentaje (o quedan sin descuento si es 0), con
  importes y totales recalculados. Si ninguna línea cambia de descuento, la copia queda tal cual.

Es contexto, no fuente de verdad: el cálculo sigue saliendo del descuento de cada línea.

### Precarga en el navegador

El parcial `cotizaciones/_aviso-descuento-cliente` va dentro del formulario de cotización y de la
ventana "Nueva cotización" del dashboard. Su presencia es lo que activa la lógica en
`documento-lineas.js`; factura y orden de compra no lo incluyen y no cambian.

- Lleva `data-descuentos-cliente` (`{cliente_id: {nombre, porcentaje}}`, solo los clientes con
  descuento, que arma `CotizacionController::datosFormulario()`) y, al editar,
  `data-descuento-congelado`, que manda mientras el cliente sea el de la cotización.
- Cada artículo que se agrega trae `porcentaje` y el valor del cliente en su columna de descuento. El
  precio sigue siendo el de lista. El descuento es editable como cualquier otro.
- Cambiar de cliente reemplaza el descuento de **todas** las líneas por el del nuevo, incluso si eso
  las deja sin descuento. Las ediciones a mano no se respetan.
- Sin JavaScript no se precarga nada: las líneas se capturan a mano y el servidor solo congela el
  porcentaje.
- El aviso (`x-alerta tipo="info"`) se pinta desde el servidor con el estado inicial y el JS lo
  muestra, lo oculta y le cambia nombre y porcentaje: *"**FERRETERIA LOPEZ** tiene un descuento
  permanente de **15%**, ya aplicado en cada línea. Puedes modificarlo línea por línea si esta
  cotización es una excepción."*

### Detalle y vista previa

`x-cotizaciones.hoja` (detalle y visor de la bandeja y del dashboard) muestra *"Descuento de cliente
al cotizar: **15%**"* cuando el congelado es mayor que 0. El PDF (`cotizaciones/pdf`) **no** lo lleva:
ya muestra el descuento por línea.

## Factura de una cotización

### Precio de facturación

`CalculadoraTotalesDocumento::precioConDescuentoDeLinea()` calcula
`redondeo2((bruto − descuento de línea) / cantidad)`, en centavos.

- Se parte del neto de la línea **antes del descuento global**, no del `importe` guardado como en la
  remota: aquí el `importe` de la línea ya trae su parte del global prorrateado, y como el global
  viaja visible a la factura, partir del importe lo descontaría dos veces.
- Vale igual para descuento en porcentaje que en monto. Sin descuento devuelve el mismo precio.
- El residuo de centavos (`$100.00 / 3 → $33.33`) se acepta y no se compensa.
- Vive solo en PHP; `totales-documento.js` no lo repite.

`CotizacionLinea` lo expone como `precio_unitario_facturacion` y arma con él `datosParaFactura()`
(precio rebajado, `descuento_tipo` y `descuento_valor` en `null`).

### Los tres caminos

**A la factura no viaja descuento de línea de ninguna clase**, venga del cliente o capturado a mano. El
descuento **global** sí viaja visible.

1. **Formulario** (`/facturas/crear?cotizacion=`): `FacturaController::precargaDeCotizacion()` usa
   `datosParaFactura()`. Si la cotización trae descuento de cliente, aviso *"Los precios unitarios ya
   incluyen el descuento de **15%** de este cliente. La factura no mostrará el descuento por
   separado."* El precio sigue siendo editable y el servidor no compara contra el total de la
   cotización.
2. **Timbrado directo** desde la vista previa (020): `TimbrarCotizacionRequest` usa
   `datosParaFactura()`.
3. **Venta de una cotización aceptada → autofactura** (021/019): la venta conserva el descuento
   visible, como la cotización (es su copia). `Autofacturador` pliega el descuento de las ventas con
   `cotizacion_id` y recalcula sus importes con la calculadora. Las ventas de mostrador siguen
   copiando importes y descuentos tal cual.

Una factura creada desde cero o duplicada de otra factura no consulta el descuento del cliente.

## `x-alerta` variante `info`

Icono `info-circle` y los colores `--color-info`, `--color-info-fondo` y `--color-info-borde` en
`app.css`.

## Fuera de alcance

Igual que la remota: descuento en monto a nivel cliente, por artículo, familia o volumen; vigencia;
autorización; historial; recálculo de documentos guardados; descuento en órdenes de compra,
Tesorería, complementos de pago y ventas de mostrador; compensar el residuo de centavos; validar el
descuento efectivo total; reportes. Además, la remota [036](remotas/036-descuento-venta-mostrador.md)
(descuento en la venta de mostrador) es otra historia.

## Supuestos asumidos

1. El descuento vive en la ficha del cliente, solo en porcentaje, de 0% a 50% con hasta 2 decimales.
   Es opcional y los clientes existentes quedan en 0%.
2. Es permanente: sin vigencia, sin aprobación, para todos los artículos por igual.
3. Al elegir un cliente con descuento, cada línea que se agregue lo trae precargado y editable. El
   precio unitario no se toca en la cotización.
4. Cambiar de cliente reemplaza el descuento de todas las líneas, incluso si las deja en 0%.
5. El descuento de cliente convive con el global: primero el de línea, después el global.
6. Cambiar el descuento de un cliente no modifica cotizaciones guardadas.
7. La factura cobra lo mismo que la cotización (salvo el residuo de centavos), no muestra descuento
   de línea y el precio rebajado sigue siendo editable.
8. Una factura desde cero no aplica descuento automático.
9. **(Decidido el 2026-10-02, pregunta 1 → a)** La venta de una cotización aceptada muestra el
   descuento como la cotización; su autofactura lo esconde en el precio. Las ventas de mostrador no
   cambian.
10. **(Decidido el 2026-10-02, pregunta 2 → a)** Duplicar para otro cliente aplica el descuento del
    cliente nuevo a todas las líneas y congela su valor. Para el mismo cliente, copia exacta.
11. **(Decidido el 2026-10-02, pregunta 3 → a)** Una sola regla: se esconde cualquier descuento de
    línea, también los capturados a mano y los de cotizaciones anteriores a esta historia.
12. **(Adición técnica)** La copia congelada solo la escribe el servidor; se reescribe solo al cambiar
    de cliente.
13. **(Adición técnica)** El precio de facturación lo calcula el servidor en una sola función,
    compartida por los tres caminos.
14. **(Adición técnica)** El aviso va en la vista de la cotización y no dentro de la tabla compartida
    de líneas; su presencia es lo que activa el descuento en el JS.

## Criterios de aceptación

1. En la ficha de un cliente se captura un descuento de 0% a 50% con hasta 2 decimales; en blanco
   vale 0%. 50.01%, negativos o 3 decimales se rechazan en el servidor.
2. El listado de clientes muestra el descuento, con guion cuando es 0%.
3. En la cotización, cada artículo que se agrega para un cliente con descuento lo trae precargado y
   editable, con el precio de lista intacto. Editarlo no cambia la ficha del cliente.
4. Cambiar de cliente con líneas capturadas reemplaza el descuento de todas, incluso si el nuevo es
   0%. El aviso aparece solo con descuento mayor a 0%.
5. La cotización guarda el porcentaje del cliente al capturarla; cambiarlo después no la mueve.
   Editarla sin cambiar de cliente lo conserva; cambiando de cliente toma el del nuevo.
6. Duplicar para el mismo cliente copia el congelado. Duplicar para otro aplica el del nuevo a todas
   las líneas y recalcula.
7. El detalle muestra "Descuento de cliente al cotizar" y el PDF no.
8. Facturar $333.33 × 3 al 15% precarga $283.33 sin descuento; el total es $985.99 en los dos
   documentos y el descuento de la factura queda en $0.00. Con descuento global, el global viaja
   visible y el total cuadra.
9. El timbrado directo y la autofactura de la venta de una cotización dan el mismo resultado.
10. Una factura desde cero no aplica descuento. Factura, orden de compra y ventas de mostrador se
    comportan igual que antes.

## Estado de implementación

Implementada el 2026-10-02.

- **Archivos nuevos**: la migración `2026_10_08_100000_descuento_permanente_cliente`, **aplicada en la
  base local**; el parcial `cotizaciones/_aviso-descuento-cliente`; y `tests/Feature/DescuentoClienteTest.php`
  con 37 pruebas.
- **Archivos modificados**:
  - Modelos y servicios: `Cliente`, `ClienteFactory`, `ClienteRequest`, `Cotizacion`,
    `CotizacionLinea` y `CalculadoraTotalesDocumento`.
  - Controladores y requests: `CotizacionController` (alta, edición, duplicar y
    `datosFormulario`), `FacturaController::precargaDeCotizacion()` y `TimbrarCotizacionRequest`.
  - `Autofacturador`.
  - Vistas: `clientes/_formulario`, `_filas` e `index`; `cotizaciones/_formulario` y
    `_dialogo-nueva`; `x-cotizaciones.hoja`; `facturas/_formulario`; `x-alerta`.
  - `app.css`, `documento-lineas.js` y `EstiloUniformeTest`.
- **Decisiones al implementar**:
  - El precio de facturación sale del neto **antes** del global (ver "Precio de facturación"), no del
    `importe` como decía la remota.
  - Duplicar para otro cliente no recalcula si ninguna línea cambia de descuento, para que la copia
    siga siendo exacta en el caso de siempre.
  - El congelado arranca el JS al editar; si el usuario cambia de cliente y regresa al original,
    vuelve a mandar el congelado.
- **Verificación**: `php artisan test` con 1092 pruebas en verde, `node --test "tests/js/*.test.js"`
  (37) y Pint sin cambios en los archivos tocados.

  **No se revisó la UI en un navegador real.** Falta probar la precarga y el reemplazo del descuento
  al cambiar de cliente (formulario y ventana del dashboard), el aviso y la tabla de clientes en 375
  px.
