# Spec: Reembolso de una venta (el dinero sale, los artículos regresan y la factura se puede cancelar)

> **Estado: implementada** el 2026-10-20 (ver "Estado de implementación" al final). El usuario aprobó el 2026-10-20, sin cambios, las
> 22 asunciones funcionales y las cinco adiciones técnicas de la primera versión, y el mismo día el
> **cambio de la factura**: sus 11 asunciones y sus tres adiciones técnicas (6, 7 y 8). Las
> "decisiones tomadas al redactar" están marcadas así en el texto y quedan a su revisión.

**Modifica:**

- [019-pedidos-mostrador.md](019-pedidos-mostrador.md) y
  [021-cotizacion-aceptada-a-venta.md](021-cotizacion-aceptada-a-venta.md): la venta gana el estado
  **Reembolsado**, que la congela (no se edita, no cobra, no se entrega, no se factura ni se
  autofactura) y aparece en el filtro de estado del listado.
- [029-pago-cotizacion-pedido-orden-trabajo.md](029-pago-cotizacion-pedido-orden-trabajo.md) y
  [011-cotizaciones.md](011-cotizaciones.md): la cotización gana el estado **Reembolsada**. Se
  reembolsa desde su venta si la tiene, y desde la propia cotización si no la tiene (distribuidor o
  solo suministros).
- [016-tesoreria.md](016-tesoreria.md): el reembolso es un **tercer origen de movimientos
  automáticos**, ahora de egreso. Los ingresos de un documento reembolsado dejan de mostrar utilidad.
- [018-inventario.md](018-inventario.md): nace el motivo de sistema `reembolso_venta`.
- [022-ordenes-trabajo.md](022-ordenes-trabajo.md): la orden gana el estado **Cancelada**, que sale
  del dashboard igual que la entregada.
- [012-facturacion.md](012-facturacion.md) y [015-cotizacion-a-factura-y-duplicar.md](015-cotizacion-a-factura-y-duplicar.md):
  el reembolso puede **pedir la cancelación** de la factura timbrada (con `CanceladorFacturas`, sin
  cambiar sus reglas) y **descarta** la que está en borrador o pendiente de timbrar. El detalle de
  una factura cancelada por un reembolso lo dice.

## Historia de usuario

Como usuario, tengo clientes que, una vez que pagaron, desean un reembolso de la venta. Entonces el
dinero se tiene que reembolsar y los artículos regresan a las existencias.

Si el cliente cancela la venta y pide un reembolso:

```
SI factura timbrada = true
ENTONCES  el sistema pregunta si también se desea cancelar la factura timbrada
SI factura timbrada = false
ENTONCES  el sistema solo saca el dinero de Tesorería y reintegra los artículos a existencias
```

```
VENTA PED-0043  (pagos: $500 en Caja, $700 en Banco; Sello 4912 × 2; factura FAC-0021 timbrada)
     │
     └── [Reembolsar]  motivo: "El cliente ya no lo quiere"
              │   "¿También quieres cancelar la factura FAC-0021 ante el SAT?"
              │        (•) Sí, cancelar factura   motivo SAT: 03 – No se llevó a cabo la operación
              │        ( ) No, dejarla vigente
              │   "¿Seguro que quieres reembolsar $1,200.00? Esta acción no se puede deshacer."
              ▼
         0. Revisión previa: saldos, cuentas activas, venta sin reembolsar      (adición 6)
         1. SAT: cancelación de FAC-0021 pedida → aceptada o pendiente
         2. Tesorería:   Caja  −$500   "Reembolso de Venta PED-0043"
                         Banco −$700   "Reembolso de Venta PED-0043"
         3. Inventario:  Sello 4912 +2  (motivo: Reembolso de venta)
         4. Orden de trabajo de PED-0043 → Cancelada
         5. PED-0043 → Reembolsado   (y su cotización COT-0012 → Reembolsada)
```

## Objetivo / Alcance

Un botón **"Reembolsar"** en la venta (y en la cotización pagada que no tiene venta) que:

1. si hay factura timbrada, pregunta si también se cancela ante el SAT y, con "Sí", pide la
   cancelación antes de mover nada;
2. en un solo paso que se aplica completo o no se aplica:
   - saca de Tesorería todo lo que el cliente pagó, por la misma cuenta por la que entró;
   - regresa a existencias lo que esa venta sacó;
   - descarta la factura en borrador o pendiente de timbrar, si la hay;
   - cancela su orden de trabajo;
   - deja la venta (y su cotización) en un estado final de solo lectura.

- Una tabla nueva, `reembolsos`, y un servicio único, `Reembolsador`, que usa los servicios que ya
  existen (`RegistradorMovimientos`, `RegistradorInventario` y `CanceladorFacturas`). No se toca
  saldo, existencia ni factura por otro camino.
- Modal `<dialog>` nativo y formulario normal, con el manejador que ya existe en `app.js`
  (`data-abrir-dialogo`, `data-confirmar`, `data-enviar-una-vez`). **No se escribe JavaScript nuevo**:
  el motivo SAT se muestra siempre y el servidor lo ignora con "No, dejarla vigente".

**Fuera de alcance:** reembolso **parcial** (de algunos artículos o de parte del dinero),
penalizaciones o comisiones por cancelar, deshacer un reembolso, comprobante de reembolso para el
cliente (ticket, PDF o correo), autorización especial para reembolsar, cancelación con **factura
sustituta** (motivo 01) desde el reembolso, nota de crédito (CFDI de egreso) y reembolso de órdenes
de compra.

## Reglas de negocio

### Qué se puede reembolsar

1. Se reembolsa una **venta** (`Pedido`), de mostrador o nacida de una cotización, en cualquier
   estado con pagos: anticipo, pagado o **entregado**.
2. También se reembolsa una **cotización pagada que no tiene venta** (distribuidor o solo
   suministros, 029): en `enviada`, `pagada` o `producto_entregado`, con al menos un pago.
3. Una cotización **con venta** (estado `aceptada`) no muestra el botón: se reembolsa desde su venta,
   y la cotización queda reembolsada con ella (regla 33). Su detalle muestra en su lugar *"Para
   reembolsar, entra a la venta PED-0043."* con el enlace.
4. Sin pagos no hay reembolso: el botón no aparece. Esa venta se sigue borrando como hoy (019) y esa
   cotización se sigue editando o eliminando como hoy (011).
5. Un documento ya reembolsado no se reembolsa otra vez (regla 42). La factura **ya no bloquea** el
   reembolso: ver "La factura".

### Cuánto dinero sale y de dónde

6. Se devuelve **exactamente lo que el cliente pagó**, aunque no haya cubierto el total. Sin
   penalización ni comisión.
7. Los pagos que se devuelven son todos los ligados a la venta: los suyos (`pedido_pagos`) y los de
   su cotización (`cotizacion_pagos`). En una venta que cobra en su cotización (029) solo existen los
   segundos; en una aceptada antes de 029 pueden existir los dos.
8. **Un egreso por pago**, en la **misma cuenta** del pago y por su mismo monto. Concepto:
   *"Reembolso de Venta PED-0043"* o *"Reembolso de Cotización COT-0012"* (la cotización sin venta).
   Fecha: el día del reembolso, en la zona del negocio.
9. Los egresos son **automáticos** (solo lectura): su `documentable` es el `Reembolso`. Se corrigen
   solo desde su documento, y como el reembolso no se deshace, nunca se corrigen.
10. Los pagos originales y sus ingresos **no se borran ni se cambian**: Tesorería conserva la
    historia completa (entró $1,200, salió $1,200).
11. Si alguna cuenta **no tiene saldo suficiente**, el reembolso entero se rechaza (Tesorería prohíbe
    saldos negativos, 016): *"La cuenta Caja no tiene saldo suficiente para devolver $500.00."*
12. **(Decisión tomada al redactar)** Si alguna cuenta está **inactiva**, el reembolso también se
    rechaza: *"La cuenta Caja está inactiva. Actívala para hacer el reembolso."* Es la misma regla
    de Tesorería para cualquier movimiento nuevo.

### La utilidad

13. En el listado de movimientos de Tesorería, los ingresos de un documento reembolsado muestran
    **"Reembolsada"** en lugar de su utilidad, con el título *"La venta se reembolsó; su utilidad ya
    no cuenta."* Los egresos del reembolso no muestran utilidad.

### Las existencias

14. Regresa **lo que la venta sacó**, no lo que dicen sus líneas: el neto de sus movimientos de
    inventario por artículo (`RegistradorInventario::revertirDocumento`, el mismo de la edición y el
    borrado de ventas). Así:
    - regresan todos los artículos de la bodega, en la cantidad en que salieron;
    - las líneas libres y los artículos que no están en existencias no hacen nada, porque nunca
      salieron;
    - una venta editada varias veces no regresa de más.
15. Los artículos de **producción** (sellos fabricados) regresan igual que los demás, si salieron.
16. En una **cotización sin venta**, solo hay algo que regresar si se marcó "producto entregado":
    antes de eso no descontó nada (018, 029).
17. Cada artículo deja un movimiento de **entrada** con el motivo nuevo **`reembolso_venta`**
    ("Reembolso de venta"), ligado a la venta o a la cotización. Es un motivo de sistema: no aparece
    entre los motivos de un ajuste manual.
18. **(Decisión tomada al redactar)** La entrada sigue la regla 1 de Inventario (018): si el artículo
    tiene faltante pendiente, primero lo salda y solo el resto sube la existencia. Ejemplo: se
    vendieron 2 sin tener ninguno (existencia 0, faltante 2); al reembolsar queda existencia 0 y
    faltante 0, no existencia 2.

### La factura

La factura que cuenta es la de la venta o la de su cotización (una sola entre las dos, 021), o la de
la cotización sin venta.

19. **Sin factura**, o con una ya `cancelada`: el reembolso no pregunta nada. Solo saca el dinero y
    regresa los artículos.
20. **Factura en borrador o pendiente de timbrar:** se **descarta** (se borra, como el botón
    "Eliminar" de Facturación) dentro de la misma transacción del reembolso, para que no pueda
    timbrarse después. La ventana lo avisa: *"La factura FAC-0021, que todavía no se timbró, se
    descartará."*
21. **Factura timbrada:** la ventana muestra su folio y su total, y pregunta *"¿También quieres
    cancelar la factura FAC-0021 ante el SAT?"* con dos opciones obligatorias:
    - **Sí, cancelar factura** (marcada por defecto);
    - **No, dejarla vigente**.
22. Con **"Sí"**, la ventana pide el **motivo SAT**, con **"03 – No se llevó a cabo la operación"**
    por defecto.
    - **(Decisión tomada al redactar)** Solo se ofrecen **02**, **03** y **04**. El **01** exige una
      factura sustituta, que no tiene sentido en un reembolso. Quien la necesite cancela desde
      Facturación.
23. Con **"Sí"**, el orden es: revisión previa (regla 27) → pedir la cancelación al SAT → reembolso.
    Si al **pedir** la cancelación facturapi.io responde con error (servicio caído, complementos de
    pago que el SAT exige cancelar antes, etc.), **el reembolso no se hace** y la ventana regresa con
    *"No se pudo pedir la cancelación de la factura FAC-0021: {mensaje}. No se reembolsó nada;
    inténtalo de nuevo."*
24. Si el SAT acepta la cancelación de inmediato o la deja **pendiente** (el cliente tiene que
    aceptarla), el reembolso se hace **en ese momento**, sin esperar. La factura se sigue
    actualizando como hoy, al abrir su detalle (`CanceladorFacturas::refrescar`).
25. Si después el SAT **rechaza** la cancelación, el reembolso **no se deshace**. La venta muestra el
    aviso *"La factura FAC-0021 sigue vigente: la cancelación fue rechazada."* con el botón de la
    regla 30.
26. Con **"No"**, el reembolso se hace igual y la factura queda timbrada. La venta muestra el aviso
    permanente *"Reembolsada con factura vigente FAC-0021."*, sin botón.
    - **(Decisión tomada al redactar)** Si la factura ya tenía una **cancelación en curso** (pedida
      antes desde Facturación, `pending` o `verifying`), la ventana no pregunta: dice *"La factura
      FAC-0021 ya tiene una cancelación en curso."* y el reembolso sigue como en la regla 24.
27. **(Adición técnica 6: revisar todo antes de llamar al SAT)** Antes de pedir la cancelación, el
    servidor revisa, sin escribir nada, todo lo que puede hacer fallar el reembolso: que el documento
    se pueda reembolsar (reglas 1 a 5), que cada cuenta esté activa y que cada una tenga saldo para
    la suma de lo que le toca devolver. Si algo falla, no se llama al SAT y se muestra el mensaje de
    la regla 11 o 12. Así, la factura nunca queda cancelada por un reembolso que no se pudo hacer.
    - Entre la revisión y el reembolso pasan unos segundos. Si en ese lapso alguien más mueve la
      cuenta y el reembolso falla de todos modos, el mensaje lo dice con claridad: *"Se pidió la
      cancelación de la factura FAC-0021, pero el reembolso no se hizo: {motivo}. Corrige y
      reembolsa de nuevo; la factura ya no se volverá a cancelar."* Al reintentar, la factura ya
      está en cancelación, así que aplica la decisión de la regla 26.
28. **(Adición técnica 7: guardar qué se decidió sobre la factura)** El reembolso guarda la factura
    (id y folio), la decisión (`cancelar`, `conservar` o `descartar`) y el motivo SAT. El recuadro
    del reembolso en la venta los muestra: *"Factura FAC-0021: se pidió su cancelación (03 – No se
    llevó a cabo la operación). Estado: Cancelada."*
29. En Facturación, el detalle de una factura cancelada desde un reembolso dice *"Cancelada por el
    reembolso de la Venta PED-0043"*, con enlace.
30. **(Adición técnica 8: reintentar la cancelación desde la venta)** Cuando la cancelación quedó
    **rechazada** (regla 25), el aviso lleva el botón **"Volver a cancelar factura"**. Abre el mismo
    formulario de cancelación de Facturación (`facturas.cancelar`), con los mismos motivos, y al
    terminar regresa a la venta.
31. Cancelar la factura de una venta reembolsada **no regresa artículos otra vez**: la factura de una
    venta o de una cotización nunca movió inventario (`Factura::mueveInventario()`, 018), así que
    `devolverFactura()` no hace nada.

### Lo que pasa con la venta y sus documentos

32. La venta pasa a **Reembolsado** (`reembolsado`) y la cotización a **Reembolsada**
    (`reembolsada`). Son estados finales: no se borran, se consultan, y ya no se editan, no reciben
    ni eliminan pagos, no se entregan, no se facturan, no caducan y no se autofacturan (el portal
    responde *"Esta venta se reembolsó."*).
    - **(Decisión tomada al redactar)** La etiqueta de la venta es "Reembolsado", en masculino, como
      "Pagado" y "Entregado". La de la cotización es "Reembolsada", como "Pagada" y "Entregada".
33. Al reembolsar una venta que nació de una cotización, la cotización pasa a **Reembolsada** en la
    misma operación.
34. Si la venta tiene **orden de trabajo**, pasa a **Cancelada** (`cancelada`), en cualquier estado
    en que esté (incluida la entregada). Se conserva con su diseño para consulta, pero sale del
    dashboard, de la hoja de producción y de las etiquetas de producción, y ya no avanza ni se edita.
35. El detalle de un documento reembolsado muestra un recuadro con la fecha, el usuario, el monto
    devuelto, el motivo, los egresos (cuenta y monto, con enlace a Tesorería) y lo que pasó con la
    factura (regla 28), con los avisos de las reglas 25 y 26.

### Quién y cómo

36. Lo hace cualquier usuario registrado, sobre sus propios documentos (misma Policy que el resto).
37. El botón abre un `<dialog>` con:
    - el monto a devolver y el desglose por cuenta (*"Caja $500.00 · Banco $700.00"*);
    - los artículos que regresan a existencias, o *"Ningún artículo regresa a existencias."*;
    - si tiene orden de trabajo: *"La orden de trabajo se cancelará."*;
    - lo de la factura, según el caso: la pregunta y el motivo SAT (reglas 21 y 22), el aviso de
      descarte (regla 20) o el de cancelación en curso (regla 26);
    - el campo **Motivo** del reembolso, obligatorio, texto libre de hasta 500 caracteres;
    - el botón "Reembolsar $1,200.00", con `data-confirmar`: *"¿Seguro que quieres reembolsar
      $1,200.00? Esta acción no se puede deshacer."*
38. El reembolso **no se deshace**. Si fue un error, se hace una venta nueva.
39. Al terminar: *"Venta PED-0043 reembolsada. Salieron $1,200.00 de Tesorería y sus artículos
    regresaron a existencias."* (sin la última frase si ningún artículo regresó), más, según el caso:
    *"Se pidió la cancelación de la factura FAC-0021: {Cancelada | Pendiente de que el cliente la
    acepte}."*, *"La factura FAC-0021 quedó vigente."* o *"Se descartó la factura FAC-0021."*

### Adición técnica 1: todo o nada

40. Todo lo que pasa dentro del sistema corre en **una sola transacción** de base de datos: egresos,
    entradas de inventario, descarte de la factura sin timbrar, orden de trabajo y estados. Si
    cualquier paso falla (saldo insuficiente, cuenta inactiva, un error inesperado), se deshace todo y
    el usuario ve el mensaje del paso que falló. Los registradores ya trabajan dentro de la
    transacción de quien los llama.
    - La llamada al SAT queda **fuera** de la transacción, como en Facturación: no se puede deshacer.
      Por eso va primero y la protege la adición 6 (regla 27).

### Adición técnica 2: evitar el reembolso doble

41. El botón lleva `data-enviar-una-vez`: se desactiva al primer clic.
42. Dentro de la transacción, el servidor **bloquea** la venta (y su cotización) con
    `lockForUpdate` y **vuelve a revisar** que se pueda reembolsar. Si otra petición ya lo hizo, la
    segunda no mueve nada y regresa con *"Esta venta ya se reembolsó."* Además, `reembolsos` tiene
    una restricción única por documento, que es la última red si todo lo demás fallara.

### Adición técnica 3: ver las reembolsadas aparte

43. En el listado de ventas, el filtro **Estado** gana la opción **"Reembolsado"** (sale sola del
    enum). La etiqueta de color es nueva (`etiqueta-pedido-reembolsado`), en el tono de error del
    estilo uniforme (003).
44. En la bandeja de cotizaciones (014), el filtro de estado gana **"Reembolsada"**, con su etiqueta
    (`etiqueta-reembolsada`).
45. Las reembolsadas **siguen apareciendo** en el listado normal, con su etiqueta. No se esconden.

### Adición técnica 4: la revisión de inventario

46. El comando `inventario:auditar` reconstruye las existencias por **tipo** de movimiento (entrada,
    salida, ajuste), no por motivo. El reembolso escribe entradas normales, así que la revisión ya
    las reconoce sin cambios. La prueba de la regla 55 lo deja comprobado.

## Backend (Laravel)

### Migración `2026_10_20_100000_create_reembolsos_table`

- `reembolsos`:
  - `id`, `user_id` (FK), `reembolsable_type` + `reembolsable_id` (morph: `Pedido` o `Cotizacion`),
    `monto` `decimal(12,2)`, `motivo` `string(500)`, `timestamps`;
  - `factura_id` (FK nullable a `facturas`, `nullOnDelete`: la descartada se borra),
    `factura_folio` `string` nullable (sobrevive al descarte), `factura_decision` `string` nullable
    (`cancelar`, `conservar`, `descartar`; `null` sin factura) y `motivo_cancelacion` `string(2)`
    nullable (adición 7).
  - Índice **único** (`reembolsable_type`, `reembolsable_id`).
- Los estados nuevos no necesitan columnas: `pedidos.estado`, `cotizaciones.estado` y
  `ordenes_trabajo.estado` ya son texto. Si alguna es `enum` de MySQL, la migración la amplía.
- `down()` borra la tabla.

### Enums

- `EstadoPedido::Reembolsado` (`reembolsado`, "Reembolsado"). `esEditable()` ya lo excluye.
- `EstadoCotizacion::Reembolsada` (`reembolsada`, "Reembolsada"). `esEditable()` ya lo excluye.
- `EstadoOrdenTrabajo::Cancelada` (`cancelada`, "Cancelada"). `siguiente()` devuelve `null`.
- `MotivoMovimientoInventario::ReembolsoVenta` (`reembolso_venta`, "Reembolso de venta"). No entra
  en `manuales()`.
- Nuevo `DecisionFacturaReembolso`: `Cancelar`, `Conservar`, `Descartar`.
- `MotivoCancelacion::paraReembolso()`: `[02, 03, 04]` (regla 22).

### Modelos

- `Reembolso`: `reembolsable()` (MorphTo), `user()`, `factura()`, `movimientos()` (MorphMany de
  `Movimiento` por `documentable`), `avisoFactura(): ?string` (reglas 25 y 26) y
  `puedeReintentarCancelacion(): bool` (regla 30: decisión `cancelar` y factura con
  `estado_cancelacion` rechazada).
- `Pedido` y `Cotizacion`: `reembolso()` (MorphOne), `estaReembolsado()`, `puedeReembolsarse()`,
  `motivoNoReembolsable(): ?string` (reglas 1 a 5: ya reembolsado, sin pagos; en la cotización,
  además, "tiene venta") y `facturaParaReembolso(): ?Factura` (la de la venta o la de su cotización,
  no cancelada).
- `Pedido`:
  - `recalcularEstado()` no toca una venta reembolsada (igual que una entregada).
  - `puedeRegistrarPago()`, `puedeEliminarPago()`, `puedeEntregarse()`/`motivoNoEntrega()`,
    `puedeCompartirTicket()`, `puedeCrearOrdenTrabajo()` y `motivoAutofacturaNoDisponible()`
    rechazan la reembolsada.
  - `pagosParaReembolso()`: los `PedidoPago` y los `CotizacionPago` de su cotización (regla 7).
- `Cotizacion`: `puedeRegistrarPago()`, `puedeEntregarse()`, `puedeEliminarse()`,
  `mostrarAvisoCaducidad()` y el cálculo de facturable rechazan la reembolsada. `ESTADOS_FACTURABLES`
  no la incluye.
- `Factura`: `reembolso()` (HasOne por `reembolsos.factura_id`), para la leyenda de la regla 29.
- `OrdenTrabajo`: `esEditable()` y `puedeAvanzar()` rechazan la cancelada.
- `Movimiento::documentoOrigen()`:
  - origen `Reembolso` → etiqueta y enlace del documento, `muestra_utilidad = false`;
  - origen `CotizacionPago`/`PedidoPago` de un documento reembolsado → nueva llave
    `reembolsada = true`, que la vista pinta como "Reembolsada" (regla 13).

### Servicio `App\Services\Ventas\Reembolsador`

```php
public function reembolsar(Pedido|Cotizacion $documento, string $motivo, User $user,
    ?DecisionFacturaReembolso $decision, ?MotivoCancelacion $motivoSat): Reembolso
```

1. **Revisión previa** (adición 6, regla 27), sin bloqueos ni escritura: `motivoNoReembolsable()`,
   cuentas activas y saldo por cuenta contra la suma de sus pagos. Si algo falla, lanza
   `ReembolsoNoPermitidoException` con el mensaje.
2. **Factura timbrada sin cancelación en curso y decisión `cancelar`:**
   `CanceladorFacturas::cancelar($factura, $motivoSat)`. `FacturapiException` →
   `ReembolsoNoPermitidoException` con el mensaje de la regla 23. Nada más se ejecuta.
3. Dentro de `DB::transaction` (adición 1):
   1. Bloquea el documento y, si es una venta con cotización, la cotización (`lockForUpdate`,
      regla 42), y vuelve a revisar `motivoNoReembolsable()`.
   2. Crea el `Reembolso` con el motivo, la suma de los pagos y los datos de la factura (regla 28).
   3. Por cada pago: `RegistradorMovimientos::registrar($pago->cuenta, TipoMovimiento::Egreso,
      $pago->monto, $hoy, $concepto, $reembolso)`. `SaldoNegativoException` y
      `CuentaInactivaException` suben y deshacen todo; si el paso 2 ya pidió la cancelación, el
      controlador usa el mensaje de la regla 27.
   4. `RegistradorInventario::revertirDocumento($documento, MotivoMovimientoInventario::ReembolsoVenta)`.
   5. Factura en borrador o pendiente → se borra (regla 20).
   6. Orden de trabajo (si hay) → `cancelada`.
   7. Estado del documento → `reembolsado`/`reembolsada`, y el de su cotización → `reembolsada`.

La decisión que se guarda sale de la factura, no solo del formulario: `descartar` si estaba en
borrador o pendiente, `cancelar` si se pidió en el paso 2 o ya tenía una cancelación en curso
(regla 26), `conservar` si se eligió "No", y `null` sin factura.

### Rutas, Request y controlador

- `POST /pedidos/{pedido}/reembolso` → `ReembolsoController@pedido`, nombre `pedidos.reembolso`.
- `POST /cotizaciones/{cotizacion}/reembolso` → `ReembolsoController@cotizacion`, nombre
  `cotizaciones.reembolso`.
- `ReembolsoRequest`:
  - autoriza con la Policy (`reembolsar`, dueño del documento; ajeno → 404 como el resto);
  - `motivo` requerido, texto, máximo 500;
  - `cancelar_factura` (`si`/`no`) requerido **solo** si el documento tiene factura timbrada sin
    cancelación en curso; si no, se ignora;
  - `motivo_cancelacion` requerido con `cancelar_factura = si`, dentro de
    `MotivoCancelacion::paraReembolso()`; con `no`, se ignora.
- El controlador llama al servicio y regresa al detalle con el mensaje de éxito (regla 39), o con el
  mensaje de la excepción en `error` (reglas 11, 12, 23, 27 y 42).
- El formulario de "Volver a cancelar factura" (regla 30) manda a `facturas.cancelar` un campo
  `regresar_a` con la URL de la venta. `FacturaController::cancelar` lo respeta solo si es una ruta
  interna de la aplicación; si no, regresa a la factura como hoy.

### Policy

- `PedidoPolicy::reembolsar` y `CotizacionPolicy::reembolsar`: dueño del documento. La regla de
  negocio (`motivoNoReembolsable`) la revisa el servicio, no la Policy, para no duplicarla fuera de
  la transacción.

### Consultas que excluyen la orden cancelada

- `DashboardController`: la columna de órdenes excluye `entregado` **y** `cancelada`.
- `OrdenTrabajo::enProduccion()` ya filtra por `en_proceso`: no cambia.

## Frontend (Blade)

- `pedidos/show.blade.php` y `cotizaciones/show.blade.php` (y la vista previa de la bandeja):
  - botón "Reembolsar" con `data-abrir-dialogo` cuando `puedeReembolsarse()`; sin botón en los
    demás casos (y, en la cotización con venta, el enlace de la regla 3);
  - parcial nuevo `reembolsos/_dialogo.blade.php` (regla 37), con la sección de la factura según el
    caso (reglas 20, 21, 22 y 26);
  - parcial nuevo `reembolsos/_resumen.blade.php` para el documento reembolsado (reglas 28 y 35),
    con el aviso y el botón de las reglas 25, 26 y 30, en lugar de los botones de cobrar, entregar,
    editar y facturar.
- `facturas/show.blade.php`: la leyenda de la regla 29.
- `tesoreria/movimientos/index.blade.php`: "Reembolsada" en la columna de utilidad (regla 13).
- `estilos.blade.php`: `etiqueta-pedido-reembolsado`, `etiqueta-reembolsada` y
  `etiqueta-orden-cancelada`.

## Pruebas (adición técnica 5)

Feature tests, en el estilo de las existentes. facturapi.io se simula con `Http::fake`, como en las
pruebas de Facturación.

47. Venta de mostrador sin factura, con dos pagos en dos cuentas → un egreso por pago en su cuenta,
    saldos correctos, venta en `reembolsado`, ingresos originales intactos y `factura_decision` en
    `null`.
48. Venta que cobra en su cotización → los egresos salen de los pagos de la cotización; la venta queda
    `reembolsado` y la cotización `reembolsada`; la orden de trabajo, `cancelada` y fuera del
    dashboard.
49. Cotización de distribuidor entregada → regresan sus artículos; la misma sin entregar → no
    regresa nada y el dinero sí sale.
50. Inventario: regresan solo los artículos que salieron (con línea libre y artículo fuera de bodega
    en la misma venta); una venta editada dos veces regresa el neto; con faltante pendiente, la
    entrada lo salda primero (regla 18).
51. Factura timbrada, "Sí" con motivo 03:
    - el SAT acepta → factura cancelada, reembolso hecho, decisión `cancelar` y leyenda en la
      factura;
    - el SAT deja pendiente → reembolso hecho y factura en cancelación;
    - el SAT responde error → **nada** cambia (ni egresos, ni inventario, ni estados).
52. Factura timbrada, "No" → reembolso hecho, factura timbrada intacta, decisión `conservar` y aviso
    permanente. Factura en borrador o pendiente → se borra y la decisión es `descartar` con su folio.
53. Adición 6: con una cuenta sin saldo y "Sí", **no se llama** a facturapi.io (`Http::assertNothingSent`)
    y nada cambia. Lo mismo con una cuenta inactiva.
54. Rechazos: sin pagos, cotización con venta, motivo vacío, "Sí" sin motivo SAT, motivo SAT 01 y
    documento ajeno (404).
55. Todo o nada y doble reembolso: con una segunda cuenta sin saldo (sin factura) no queda **ningún**
    egreso, entrada ni cambio de estado; el segundo envío del mismo reembolso regresa "ya se
    reembolsó" y no mueve nada; después, `inventario:auditar` termina sin descuadres.
56. Documento reembolsado: no recibe pagos, no se edita, no se entrega, no se factura y el portal de
    autofactura lo rechaza. Con la cancelación rechazada, la venta muestra "Volver a cancelar
    factura" y, al usarlo, regresa a la venta.

## Estado de implementación

Implementada el 2026-10-20, con las 26 pruebas de `tests/Feature/ReembolsoTest.php`.

- **Vista previa de la bandeja de cotizaciones:** el botón "Reembolsar" es un enlace al detalle
  (`cotizaciones.show#dialogo-reembolso`), que abre la ventana con `:target`. La ventana vive solo en
  el detalle, para no repetirla en el fragmento que llega por AJAX.
- **`MovimientoInventario::netosSalidos()`:** el cálculo del neto que sacó un documento salió de
  `RegistradorInventario::revertirDocumento()` para que la ventana muestre los mismos artículos que
  después regresan.
- **Mensajes de pago:** la venta y la cotización reembolsadas rechazan pagos y su borrado con un
  mensaje propio (*"La venta se reembolsó: ya no recibe pagos."*, *"Los pagos de una venta
  reembolsada no se eliminan: el reembolso ya los devolvió."*), en lugar de los genéricos.
- **Regla 30:** el enlace lleva `regresar_a` a `facturas.show#dialogo-cancelar`; el formulario lo
  reenvía y `FacturaController::cancelar` solo lo acepta si es una ruta interna (empieza con `/` y no
  con `//`).
