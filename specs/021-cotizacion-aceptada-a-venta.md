# Spec: Flujo de venta — la cotización aceptada se convierte en venta

> **Estado: implementada** el 2026-10-02, con autorización del usuario. Ver "Estado de
> implementación".

> **Reemplazada en parte por [029](029-pago-cotizacion-pedido-orden-trabajo.md)** (implementada el 2026-10-04). Al implementarse:
> - **se retira el botón "Aceptar"**, junto con su ruta, su ventana, `CotizacionAceptacionController`,
>   `AceptarCotizacionRequest` y `AceptadorCotizacion`. Este último pasa a ser
>   `CreadorVentaDeCotizacion`;
> - la venta de una cotización nace **con el primer pago** y solo para un cliente que no es
>   distribuidor y una cotización con algo de producción. Nace con su orden de trabajo;
> - esa venta **no tiene pagos propios**: los lee de la cotización (`pedidos.cobro_en_cotizacion`),
>   se corrige editando la cotización (que sigue editable mientras la venta no esté entregada ni
>   facturada) y cobra el saldo de la entrega en la cotización;
> - las ventas creadas con "Aceptar" antes de 029 **conservan** las reglas de esta spec.
>
> Lo demás (estado `aceptada`, `cotizacion_id`/`cliente_id`, inventario sin bloqueo, una sola
> factura vigente y borrar la venta para regresar la cotización a Enviada) se queda.

**Modifica:**

- [011-cotizaciones.md](011-cotizaciones.md): nace el estado `aceptada`. Una cotización aceptada ya
  no se edita, no se borra, no caduca, no recibe pagos ni se entrega: su vida sigue en la venta.
- [019-pedidos-mostrador.md](019-pedidos-mostrador.md): el **Pedido pasa a llamarse Venta** en la
  interfaz (no en el código ni en la base de datos) y gana un segundo origen: la cotización
  aceptada. `pedidos` gana `cotizacion_id` y `cliente_id`.
- [014-cotizaciones-bandeja.md](014-cotizaciones-bandeja.md) y
  [020-dashboard-cotizaciones-facturas.md](020-dashboard-cotizaciones-facturas.md): carpeta
  "Aceptadas" y botón "Aceptar" en la vista previa.
- [015-cotizacion-a-factura-y-duplicar.md](015-cotizacion-a-factura-y-duplicar.md) y
  [019](019-pedidos-mostrador.md) (autofactura): una cotización aceptada y su venta comparten **una
  sola** factura vigente.

**No modifica:** Tesorería (016), Inventario (018) salvo el punto "Inventario", Órdenes de compra
(017) ni Facturación (012).

## Historia de usuario

Como usuario, quiero que toda venta —la que entra por el mostrador y la que nace de una cotización
que el cliente aceptó— quede en un solo lugar, la **Venta**, con su cliente, sus productos, su total
y sus pagos, para cobrar, entregar y facturar siempre de la misma forma. Más adelante, de la venta
saldrá la orden de trabajo.

```
VENTA DE MOSTRADOR                    COTIZACIÓN COT-0012
     │                                      │
     ├── Cliente                            ├── Cliente
     ├── Productos                          ├── Productos
     ├── Total                              └── Total
     └── Pago                                     │
                                              [Aceptar]
                                                  ↓
                                         VENTA PED-0043  ← Cliente, Productos, Total
                                                  │         copiados de la cotización
                                                  └── Pago (ticket, QR, entrega, autofactura)

            (fuera de alcance)  VENTA → ORDEN DE TRABAJO
```

## Objetivo / Alcance

1. **La Venta es el Pedido de 019.** No se crea una entidad nueva: el Pedido ya tiene cliente,
   líneas, totales, pagos contra Tesorería, ticket con QR, entrega y autofactura. Lo que cambia es el
   nombre que ve el usuario y que ahora puede nacer de una cotización.
2. **Aceptar una cotización** crea su venta en un solo paso, con las mismas líneas, precios,
   descuentos y total. La cotización queda en `aceptada` y apunta a su venta.
3. A partir de ahí, **pagos, ticket, etiqueta, entrega y autofactura viven en la venta**, con las
   reglas de 019 sin cambios.
4. **No se rompe nada existente**:
   - las rutas, tablas, clases y nombres de ruta `pedidos.*` no cambian (los QR ya impresos apuntan
     a `/pedidos/{id}/entregar` y los enlaces de autofactura ya enviados siguen sirviendo);
   - las cotizaciones que ya tienen pagos, o que están `pagada` o `producto_entregado`, siguen su
     flujo de 011 tal cual: cobrar, entregar y facturar desde la cotización;
   - "Facturar" una cotización (015 y el timbrado directo de 020) sigue funcionando, también para
     una aceptada (ver "Facturación").
5. Laravel + Blade + JavaScript nativo, como el resto del sistema. **Ninguna llamada AJAX nueva**:
   aceptar es un formulario normal dentro de un `<dialog>`.

**No** incluye la Orden de Trabajo, retirar los pagos de la cotización ni unificar el listado de
cotizaciones con el de ventas (ver "Fuera de alcance").

## Backend (Laravel)

### Enums

- `EstadoCotizacion` gana `Aceptada` (`aceptada`, etiqueta "Aceptada"). `esEditable()` no cambia
  (solo `borrador` y `enviada`), así que la aceptada queda fuera de la edición, del borrado y de los
  scopes de caducidad `porCaducar()` y `vencidas()` **sin tocarlos**. Su clase de etiqueta es
  `etiqueta-aceptada` (nueva en `app.css`, con los tokens de 003).
- `EstadoPedido` no cambia.

### Migración `..._cotizacion_aceptada_a_venta.php`

**Tabla `pedidos`**:

| Columna | Definición |
|---|---|
| `cotizacion_id` | `foreignId` nullable → `cotizaciones`, **`restrictOnDelete`**, **único**, después de `user_id` |
| `cliente_id` | `foreignId` nullable → `clientes`, `nullOnDelete`, índice, después de `cotizacion_id` |

- `cotizacion_id` único: **una cotización, una venta**. `restrict` es la red de seguridad: una
  cotización aceptada no se puede borrar por regla (ver "Modelo `Cotizacion`").
- `cliente_id` guarda el cliente fiscal de la cotización. Las ventas de mostrador lo dejan en `null`,
  como hoy. Los clientes usan soft delete, así que el `nullOnDelete` solo actúa con un borrado
  físico.

**Tabla `cotizaciones`**: gana `aceptada_en` (`timestamp`, nullable, después de `estado`).

La columna `estado` ya es `string`: `aceptada` no necesita migración.

`down()` quita las tres columnas. Si hay cotizaciones en `aceptada`, falla con un mensaje claro en
lugar de dejarlas en un estado que el enum anterior no conoce.

### Modelo `Cotizacion`

- Relación `venta(): HasOne` (`Pedido`, por `pedidos.cotizacion_id`).
- Cast `aceptada_en` → `immutable_datetime`. No asignable.
- **Métodos de regla nuevos** (los usan controlador, Policy y Blade):
  - `estaAceptada(): bool` → estado `aceptada`.
  - `puedeAceptarse(): bool` → `motivoNoAceptable() === null`.
  - `motivoNoAceptable(): ?string` → el primer motivo, en este orden:
    - "Ya se aceptó: su venta es PED-0043." (tiene venta);
    - "Esta cotización ya tiene pagos: se cobra y se entrega desde aquí." (`tienePagos()`, el flujo
      anterior);
    - "Solo se acepta una cotización en borrador o enviada." (otro estado);
    - "Ya tiene la factura FAC-0012." (`estaFacturada()`).
  - `marcarAceptada(): void` → `estado = aceptada`, `aceptada_en = now()`.
  - `revertirAceptacion(): void` → `estado = enviada`, `aceptada_en = null` y `touch()`, para que la
    caducidad vuelva a contar desde hoy (ver "Borrar la venta").
- **Métodos que cambian:**
  - `puedeRegistrarPago()` y `puedeEntregarse()` ya exigen `enviada` y `pagada`: en `aceptada` dan
    `false` sin cambios.
  - `esFacturable()` gana un estado válido, `aceptada`, y una condición: que la venta no tenga ya
    una factura vigente (ver "Facturación").
- La bandeja filtra la aceptada con `?estado=aceptada`, como cualquier estado: la etiqueta sale sola
  de `EstadoCotizacion::opciones()`. No hace falta scope ni constante propia.

### Modelo `Pedido` (Venta)

- `cotizacion_id` y `cliente_id` **no asignables**: los escribe solo el aceptador.
- Relaciones `cotizacion(): BelongsTo` y `cliente(): BelongsTo` (`withTrashed()`, como en
  `Cotizacion::cliente()`).
- `esDeCotizacion(): bool` → `cotizacion_id !== null`.
- El folio **no cambia**: sigue siendo `PED-0043` (`folio_formateado`), con la misma numeración, y el
  ticket sigue imprimiendo `No. 0043`. Ver supuesto 2. Por lo mismo, el filtro de folio del listado
  y el concepto de Tesorería ("Pago de Pedido PED-0043") quedan como en 019.
- `motivoAutofacturaNoDisponible()` gana una revisión al inicio: si `esDeCotizacion()` y la
  cotización tiene factura vigente → "Esta venta ya se facturó." (ver "Facturación").

### Aceptar: `App\Services\Ventas\AceptadorCotizacion`

`aceptar(Cotizacion $cotizacion, array $datosCliente): Pedido`, en **una transacción**:

1. Bloquea la cotización con `lockForUpdate()` y vuelve a comprobar `puedeAceptarse()` con la fila
   bloqueada. Si no, lanza un error de validación con el motivo. Dos clics no crean dos ventas, y el
   índice único de `cotizacion_id` es la última red.
2. Asigna el folio de venta con el mismo mecanismo de `PedidoController::store`, que se extrae a
   `Pedido::siguienteFolio(User)` para que lo usen los dos (como `Factura::siguienteFolio` en 019).
3. Crea el pedido en `pendiente` con:
   - `cotizacion_id`, `cliente_id`;
   - `cliente_nombre`, `cliente_telefono` y `cliente_correo` desde `$datosCliente` (ver
     "Validaciones");
   - descuento global de la cotización.
4. Copia las líneas **tal cual**: `orden`, `articulo_id`, `cantidad`, `descripcion`, `modelo`,
   `precio_unitario`, descuento y `tasa_iva`. `costo_unitario` se toma del artículo **vigente**, con la
   misma consulta de `PedidoController` (regla de 019: el costo es el del momento de la venta). Las
   líneas libres se copian como libres.
5. Recalcula totales con `CalculadoraTotalesDocumento`. Con las mismas líneas y el mismo descuento,
   el total es el de la cotización.
6. Aplica la salida de inventario (ver "Inventario").
7. `$cotizacion->marcarAceptada()`.

Devuelve la venta. **No** registra ningún pago: el primero se captura en la venta, como en
mostrador.

### Inventario (018)

La venta de cotización descuenta existencias **al aceptarse** (cuando nace la venta), con el motivo
`VentaPedido`, igual que una venta de mostrador. Pero **no se bloquea por existencia** (supuesto 6):

- `RegistradorInventario::salidaPorDocumento($venta, $venta->lineas, VentaPedido, creaFila: true)`,
  la misma regla que la cotización entregada de 018: un artículo sin fila la crea, y lo que no
  alcanza queda como faltante pendiente para que Reposición lo pida.
- El bloqueo de 019 ("{modelo} no tiene existencia en bodega") sigue valiendo **solo para la venta de
  mostrador**. En `PedidoController::update` la validación de existencias se omite cuando
  `esDeCotizacion()`; revertir y volver a descontar (`revertirDocumento` + `salidaPorDocumento`) se
  hace igual, con `creaFila: true`.
- La cotización aceptada **no** mueve inventario: su `entregar` ya no aplica. No hay doble salida.
- La factura de la cotización aceptada no mueve inventario: `Factura::mueveInventario()` ya da
  `false` con `cotizacion_id`.

### Facturación (012, 015, 019)

**Una cotización aceptada y su venta tienen una sola factura vigente entre las dos.** Se conservan
las dos vías que ya existen, para no quitarle nada al usuario:

- **Desde la cotización** (015 y el timbrado directo de 020): sigue igual. Es la vía natural cuando
  el cliente ya está en el catálogo con RFC y quiere PPD por los anticipos. La factura queda con
  `cotizacion_id`.
- **Desde la venta** (autofactura de 019): sigue igual, cuando la venta está pagada.

Para que no salgan dos:

- `Cotizacion::esFacturable()` → `false` si su venta tiene factura vigente, con motivo "Su venta
  PED-0043 ya se facturó en FAC-0012."
- `Pedido::motivoAutofacturaNoDisponible()` → "Esta venta ya se facturó." si la cotización tiene
  factura vigente.
- Las dos comprobaciones se repiten dentro de la transacción de cada vía, con la fila bloqueada
  (`FacturaController::guardarNueva` y `Autofacturador`).
- El detalle de la venta muestra la factura vigente venga de donde venga.

### Borrar la venta (deshacer la aceptación)

`PedidoController::destroy` ya exige `pendiente` y sin pagos. Si la venta es de cotización, en la
misma transacción, **antes** del `delete()`:

1. `revertirDocumento($venta, CorreccionPedido)`, como hoy.
2. `$venta->cotizacion->revertirAceptacion()`: la cotización regresa a `enviada` y se puede editar,
   cobrar por el flujo anterior o aceptar de nuevo.

Mensaje: "Venta PED-0043 eliminada. La cotización COT-0012 volvió a Enviada." Así un "Aceptar" por
error se deshace sin tocar la base de datos a mano.

### Rutas (web)

Dentro del grupo autenticado, antes del `Route::resource('cotizaciones', ...)`:

| Método | URL | Acción | Nombre |
|---|---|---|---|
| POST | `/cotizaciones/{cotizacion}/aceptar` | acepta y crea la venta | `cotizaciones.aceptar` |

Ninguna ruta existente cambia de URL ni de nombre.

### Controlador `CotizacionAceptacionController` (nuevo, `store`)

- Valida con `AceptarCotizacionRequest`, llama a `AceptadorCotizacion::aceptar()` y redirige al
  detalle de la venta: "Cotización COT-0012 aceptada. Se creó la venta PED-0043. Registra el pago
  para compartir el ticket."
- Desde el detalle, la bandeja o el dashboard lleva **siempre** a la venta: lo que sigue es cobrar.
  Por eso no hay parámetro `origen`.
- Un motivo de `puedeAceptarse()` regresa con el error en la bolsa `aceptar`.

### Autorización

- `CotizacionPolicy::operar` cubre "Aceptar" (ajena → 404). El estado lo revisa el servicio.
- `CotizacionPolicy::update` y `delete` ya niegan por `esEditable()`; motivo nuevo (403) para la
  aceptada: "Una cotización aceptada ya no se modifica: corrige la venta PED-0043."
- `PedidoPolicy` no cambia.

### Validaciones (`AceptarCotizacionRequest`, bolsa `aceptar`)

La venta necesita nombre y teléfono (el ticket, la etiqueta y el aviso de "listo" los usan), y el
cliente de la cotización puede no tener teléfono. La ventana de "Aceptar" los pide **precargados**:

- `cliente_nombre`: requerido, máx. 150. Precarga: `nombre_contacto` del cliente o, si está vacío,
  su `razon_social`.
- `cliente_telefono`: requerido, normalizado con `NormalizaTelefono` y la misma regla que
  `PedidoRequest`. Precarga: `telefono` del cliente.
- `cliente_correo`: nullable, `email` (la misma regla de `PedidoRequest`). Precarga: `correo` del
  cliente.

Lo que el usuario escriba aquí se guarda **en la venta**, no en el catálogo de Clientes.

## Vistas (Blade)

### Navegación y nombres

- El enlace del menú de aplicaciones ([menu-apps](../resources/views/components/menu-apps.blade.php))
  cambia de "Pedidos" a **"Ventas"**, con el detalle "Mostrador y cotizaciones aceptadas". El grupo
  sigue siendo "Ventas" y la ruta sigue siendo `pedidos.index`.
- En `pedidos/*` los textos "Pedido" / "Nuevo pedido" pasan a "Venta" / "Nueva venta". El ticket, la
  etiqueta y el portal de autofactura dicen "Venta No. 0043". Ver supuesto 2.

### Cotización: ventana "Aceptar" (`cotizaciones/_dialogo-aceptar.blade.php`, nuevo)

`<dialog>` con el manejador de `app.js`, compartido por el detalle, la bandeja y el dashboard:

- Resumen: folio, cliente y **total**.
- Nombre, teléfono y correo precargados (ver "Validaciones").
- Si hay líneas sin existencia o con existencia insuficiente, un `x-alerta` informativo las lista:
  "Quedará faltante de: Sello 4912 (faltan 2)." Solo avisa.
- Botón "Aceptar y crear venta" con `data-enviar-una-vez`.

### Cotización: detalle, vista previa y fila

- **Botón "Aceptar"** cuando `puedeAceptarse()`, junto a "Facturar".
- En una aceptada: etiqueta "Aceptada", la línea "Venta: PED-0043" con enlace a `pedidos.show`, y
  **ningún** botón de editar, eliminar, pago o entrega. Quedan "PDF", "Enviar", "Duplicar" y
  "Facturar" (si `esFacturable()`).
- En una cotización con pagos (flujo anterior) no aparece "Aceptar": todo sigue como hoy.

### Cotización: bandeja (014) y dashboard (020)

- Etiqueta nueva **"Aceptada"** (`?estado=aceptada`), después de "Enviada".
- La fila de una aceptada lleva su etiqueta de color. El aviso de caducidad no aparece (ya no
  caduca).

### Venta: detalle y listado (`pedidos/show`, `pedidos/index`)

- Detalle: "Origen: Cotización COT-0012" con enlace a `cotizaciones.show` cuando `esDeCotizacion()`;
  si tiene `cliente_id`, la razón social y el RFC bajo el nombre.
- Listado: icono `file-earmark-text` con `title="De la cotización COT-0012"` en las filas de
  cotización. Filtro nuevo de origen: "Todas", "Mostrador", "Cotización".
- La confirmación de "Eliminar" de una venta de cotización avisa: "La cotización COT-0012 volverá a
  Enviada."

## JavaScript

Ninguno nuevo. La ventana usa `<dialog>` y `data-enviar-una-vez` de `app.js`. Aceptar es un
formulario normal que lleva a la venta (sin respuesta JSON, a diferencia del timbrado de 020).

## Pruebas (Pest)

`tests/Feature/AceptarCotizacionTest.php` y ajustes en `PedidosTest`, `PedidoInventarioTest`,
`AutofacturaTest`, `CotizacionesTest` y `TimbrarCotizacionTest`.

1. Invitado → login; cotización ajena → 404.
2. Aceptar una `enviada` (y una `borrador`) sin pagos crea una venta `pendiente` con `cotizacion_id`,
   `cliente_id`, las mismas líneas (incluidas las libres), el mismo descuento y **el mismo total**; la
   cotización queda `aceptada` con `aceptada_en`.
3. El folio de la venta sigue la numeración de ventas, sin saltos ni choques con las de mostrador.
4. No se acepta: con pagos, `pagada`, `producto_entregado`, facturada o ya aceptada. Dos peticiones
   seguidas crean **una** venta.
5. Aceptar exige nombre y teléfono, y lo capturado no modifica el `Cliente`.
6. Inventario: aceptar descuenta con `venta_pedido` **sin bloquear** (artículo sin fila la crea;
   existencia insuficiente deja faltante). Editar la venta de cotización no bloquea. La venta de
   mostrador **sigue** bloqueando (las pruebas de 019 no cambian).
7. Una cotización aceptada: editar, eliminar, registrar pago y entregar → rechazados; la purga de
   caducidad no la borra aunque tenga más de 30 días.
8. Pagos, ticket, entrega y autofactura de una venta de cotización funcionan igual que en mostrador.
9. Facturar la cotización aceptada bloquea la autofactura de su venta, y al revés. Cancelar esa
   factura libera las dos vías.
10. Borrar una venta de cotización `pendiente` sin pagos devuelve las existencias y regresa la
    cotización a `enviada`, con la caducidad reiniciada.
11. Filtro de origen del listado de ventas ("Mostrador", "Cotización"); el filtro de folio sigue
    igual que en 019.
12. Las suites de cotizaciones, pagos de cotización, facturas, timbrado directo, pedidos, Tesorería e
    inventario siguen pasando; solo cambian las aserciones de textos de pantalla ("Pedido" → "Venta"). El
    folio `PED-` no cambia.

## Fuera de alcance

- **Orden de trabajo** (de la venta a producción). Cuando llegue, colgará **solo de la venta**, que
  ya reúne los dos orígenes; ya no hace falta la relación polimórfica con cotización que planteaba
  [remotas/038](remotas/038-produccion-ordenes-trabajo.md).
- **Retirar los pagos y la entrega de la cotización.** En esta spec conviven: las cotizaciones que
  ya cobraron siguen su flujo. Retirarlo es una spec aparte, cuando no queden cotizaciones abiertas
  con pagos.
- Migrar cotizaciones antiguas (`pagada`, `producto_entregado`) a ventas.
- Aceptación por parte del cliente (enlace público para que él acepte).
- Aceptar una parte de la cotización, o varias ventas por cotización.
- Renombrar tablas, clases o rutas de `pedidos` a `ventas`.
- Un listado único de cotizaciones y ventas.

## Estado de implementación

Implementada el 2026-10-02.

- **Archivos nuevos**:
  - migración `2026_10_05_100000_cotizacion_aceptada_a_venta` (revisada con `migrate --pretend`
    contra MySQL y **aplicada en la base local**),
  - `App\Services\Ventas\AceptadorCotizacion`, `CotizacionAceptacionController`,
    `AceptarCotizacionRequest`,
  - vista `cotizaciones/_dialogo-aceptar`,
  - pruebas `AceptarCotizacionTest` (29).
- **Archivos modificados**:
  - `EstadoCotizacion` (`Aceptada`), modelos `Cotizacion` (`venta()`, `motivoNoAceptable()`,
    `faltantesAlAceptar()`, `marcarAceptada()`, `revertirAceptacion()`, `facturaDeLaVenta()`;
    `esFacturable()`, `motivoNoFacturable()` y `porFacturar()` ven la factura de la venta) y
    `Pedido` (`cotizacion()`, `cliente()`, `esDeCotizacion()`, `facturaDeLaCotizacion()`,
    `siguienteFolio()` estático, filtro de origen),
  - `CotizacionPolicy` (motivo de la aceptada), `CotizacionController` (precarga `venta`; duplicar no
    copia `aceptada_en`), `DashboardController` (precarga), `PedidoController` (folio compartido,
    sin bloqueo por existencia en la venta de cotización, borrar revierte la aceptación, textos
    "Venta"), `ListadoPedidosRequest` (origen), `Autofacturador` (bloquea la cotización),
    `routes/web.php`,
  - vistas `cotizaciones/show`, `cotizaciones/_vista-previa`, `pedidos/*`, `autofactura/show`,
    `facturas/show`, `components/menu-apps` y `app.css` (`etiqueta-aceptada`, `etiqueta-venta`,
    `senal-origen`),
  - `AutofacturaTest`: el texto "Este pedido ya se facturó." pasó a "Esta venta ya se facturó.".
- **Decisiones al implementar**:
  - Aceptar lleva siempre a la venta, también desde la vista previa (sin `origen`).
  - La fila de la cotización es el punto de bloqueo que comparten las dos vías de factura:
    `Autofacturador` bloquea el pedido y después la cotización; `FacturaController` solo la
    cotización. Así no hay dos facturas ni interbloqueo.
  - La aceptada aparece como etiqueta de la bandeja a partir del enum, sin constante propia.
  - El ticket sigue imprimiendo "TICKET No. 0042": solo cambiaron los textos de pantalla.
- **Verificación**: `php artisan test` con 1004 pruebas en verde (975 anteriores + 29 nuevas),
  `node --test "tests/js/*.test.js"` (37) y Pint sin cambios.

  **No se revisó la UI en un navegador real.** Falta probar la ventana "Aceptar" en el detalle, la
  bandeja y el dashboard, y el filtro de origen del listado de ventas.

## Criterios de aceptación

1. Una cotización en borrador o enviada, sin pagos, muestra "Aceptar". Al confirmar se crea la venta
   con el mismo cliente, productos y total, y se abre su detalle.
2. La cotización queda "Aceptada", enlazada a su venta, y ya no se edita, borra, cobra, entrega ni
   caduca.
3. En la venta se registra el anticipo o el total, se comparte el ticket con QR, se imprime la
   etiqueta, se entrega por escaneo y el cliente se autofactura, igual que una venta de mostrador.
4. Aceptar descuenta existencias sin bloquear por falta de mercancía, y deja faltante para
   reposición.
5. Nunca hay dos facturas vigentes entre una cotización aceptada y su venta.
6. Borrar la venta (sin pagos) regresa la cotización a Enviada.
7. Las cotizaciones con pagos anteriores a esta spec, los QR ya impresos y los enlaces de autofactura
   ya enviados siguen funcionando.
8. `php artisan test`, `pint` y `node --test "tests/js/*.test.js"` en verde.

## Supuestos asumidos (registro completo)

Los marcados **[decidir]** quedaron aceptados al autorizar la implementación.

1. **[decidir] La Venta es el Pedido de 019**, no una tabla nueva. Lo único que se renombra es lo que
   ve el usuario; tablas, clases y rutas siguen siendo `pedidos`.
2. **El folio de la venta se queda como `PED-0043`** (decidido por el usuario), con la misma
   numeración para mostrador y cotización. El ticket sigue imprimiendo `No. 0043`.
3. **[decidir] Se acepta desde `borrador` o `enviada`**: el cliente puede aceptar en persona una
   cotización que nunca se le envió.
4. **[decidir] Las cotizaciones que ya tienen pagos no se aceptan**: siguen cobrándose y
   entregándose desde la cotización (011). Las dos formas conviven hasta una spec que retire la
   anterior.
5. **El nombre, teléfono y correo de la venta se piden al aceptar**, precargados del cliente, porque
   el ticket y la etiqueta los necesitan y el cliente puede no tener teléfono. No se escriben en el
   catálogo.
6. **[decidir] La venta de cotización descuenta inventario al aceptarse pero no se bloquea** por
   falta de existencia: lo cotizado suele ser sobre pedido. El bloqueo de 019 sigue solo en
   mostrador.
7. **El costo de las líneas es el del artículo al aceptar**, no el de la cotización (regla de 019
   para la utilidad de la venta).
8. **[decidir] Se conservan las dos vías de factura** (desde la cotización y autofactura de la
   venta), con una sola factura vigente entre las dos.
9. **Borrar la venta deshace la aceptación**: la cotización vuelve a `enviada` y su caducidad cuenta
   desde ese día.
10. **La venta de cotización se puede editar** con las reglas de 019 (mientras no esté pagada). La
    cotización no cambia: guarda lo que se cotizó.
11. **Aceptar no registra pago**: el primer pago siempre se captura en la venta.
12. Los conceptos de Tesorería no cambian: "Pago de Pedido PED-…", también para las ventas de
    cotización.
