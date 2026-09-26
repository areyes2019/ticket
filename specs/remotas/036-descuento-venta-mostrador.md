# Spec: Descuento global en Venta al público (mostrador)

## Historia de usuario

Como usuario único del sistema, cuando atiendo una venta al público desde el celular (mostrador),
quiero poder aplicarle un descuento a la venta —igual que ya puedo en Factura y Cotización de
mostrador—, porque hoy esa pantalla no ofrece ninguna forma de capturarlo y el descuento
simplemente no se aplica.

## Objetivo / Alcance

Un solo cambio: agregar el **descuento global** (por monto fijo o por porcentaje) a la pantalla
"Venta al público" (`MostradorVentaView.vue`), reusando el mismo mecanismo que ya usan Factura y
Cotización de mostrador para el descuento permanente de cliente y que usa el escritorio
([015](015-descuento-permanente-cliente.md)) para su descuento global manual.

**No hay bug en el backend.** `FacturaTotalesCalculator`, `StorePedidoRequest`,
`PedidoController` y `TicketPedidoService` ya calculan, validan y aplican
`descuento_global_tipo`/`descuento_global_valor` para pedidos exactamente igual que para facturas
y cotizaciones ([027](027-venta-mostrador-ticket.md)). El bug es enteramente de frontend:
`MostradorVentaView.vue` manda siempre `null` en esos dos campos (líneas 146-147 antes de este
cambio) y no ofrece ningún control para capturarlos.

### Por qué no se agrega descuento por artículo

Se consideró agregarlo también, pero `CarritoMostrador.vue` —compartido por Venta al público,
Factura y Cotización de mostrador— tiene una decisión de diseño ya tomada y documentada: **el
descuento fino por renglón se captura en la computadora, no en el celular**. Añadirlo aquí
rompería esa consistencia entre las tres pantallas de mostrador sin que el bug reportado lo
necesite: "Venta al público" tampoco vincula un `Cliente` real (ver siguiente punto), así que ni
siquiera existe hoy un descuento por línea que debería estarse aplicando y no se aplica. Esta
historia se limita al descuento global.

### Por qué no se vincula un Cliente real

"Venta al público" sigue capturando nombre, teléfono y correo en texto libre, sin FK a `Cliente`
—mismo criterio que [027](027-venta-mostrador-ticket.md) ya fijó para el pedido de mostrador—, así
que el descuento permanente de cliente de [015](015-descuento-permanente-cliente.md) sigue sin
aplicarse aquí. Lo único que esta historia agrega es la posibilidad de teclear un descuento a
mano, igual que hace hoy el formulario de escritorio.

## Backend (Laravel)

Ningún cambio. El bug no vive aquí.

## Frontend (Vue 3)

### `CarritoMostrador.vue`

Hoy calcula sus totales con `calcularTotales(lineas.value, null, null, true)`
(`CarritoMostrador.vue:24`) y no ofrece ningún control de descuento, a propósito, porque lo
comparten las tres pantallas de mostrador.

- Gana un prop nuevo, `permiteDescuentoGlobal` (booleano, por defecto `false`) —mismo patrón que
  `permiteLineaLibre` de `PasoArticulosTarjetas.vue`—: solo "Venta al público" lo activa; Factura y
  Cotización de mostrador no lo pasan y no ven ningún cambio.
- Gana dos `defineModel` nuevos, `descuentoGlobalTipo` (`TipoDescuento | null`, por defecto `null`)
  y `descuentoGlobalValor` (`number | null`, por defecto `null`), mismo tipo que ya usa
  `DocumentoLineas.vue`.
- `totales` (línea 24) pasa a `calcularTotales(lineas.value, descuentoGlobalTipo.value, descuentoGlobalValor.value, true)`.
  Como los dos modelos nuevos llegan en `null` por defecto, Factura y Cotización de mostrador —que
  no los pasan— calculan exactamente igual que hoy.
- Cuando `permiteDescuentoGlobal` es `true`, el template gana un bloque debajo de la lista de
  líneas y antes del total: un `<select>` (Sin descuento / Porcentaje / Monto fijo) y un
  `<Input type="number">` para el valor, mismo patrón de `DocumentoLineas.vue:303-326` pero con las
  clases `h-12 text-base` que usa el resto de controles del mostrador para el dedo.
- Cuando `totales.value.total_descuento > 0`, aparece una línea "Descuento" entre las líneas
  capturadas y el "Total", con el monto en negativo (`-$…`), para que el descuento capturado se
  vea reflejado antes de pasar a cobrar.

### `MostradorVentaView.vue`

- Gana dos `ref` nuevos junto a `cuentaId`/`monto` (`MostradorVentaView.vue:59-60`):
  `descuentoGlobalTipo = ref<TipoDescuento | null>(null)` y
  `descuentoGlobalValor = ref<number | null>(null)`.
- `totales` (línea 73) pasa de `calcularTotales(lineas.value, null, null, true)` a
  `calcularTotales(lineas.value, descuentoGlobalTipo.value, descuentoGlobalValor.value, true)`.
- El `<CarritoMostrador>` del paso 2 (línea 323) gana `permite-descuento-global`,
  `v-model:descuento-global-tipo="descuentoGlobalTipo"` y
  `v-model:descuento-global-valor="descuentoGlobalValor"`.
- El `payload` de `cobrar()` (líneas 146-147) deja de mandar `null` fijo y manda
  `descuentoGlobalTipo.value` / `descuentoGlobalValor.value`.
- `nuevaVenta()` (líneas 236-252) limpia los dos `ref` a `null`, igual que limpia el resto de la
  captura.

## Fuera de alcance

- **Descuento por artículo/línea** en cualquier pantalla de mostrador (Venta al público, Factura o
  Cotización): sigue siendo exclusivo del formulario de escritorio.
- **Vincular un `Cliente` real** desde "Venta al público", y por lo tanto el descuento permanente
  automático de [015](015-descuento-permanente-cliente.md): sigue sin aplicarse aquí.
- **Cambios a Factura o Cotización de mostrador.** Ya aplican descuentos correctamente (por cliente
  vinculado); esta historia no las toca.
- **Límites de descuento** (monto o porcentaje máximo): no existen hoy en ningún documento del
  sistema y esta historia no agrega ninguno.
- **Roles o permisos** para restringir quién puede aplicar el descuento: sigue sin haber
  diferenciación de roles en el sistema.

## Criterios de aceptación

1. En el paso "Carrito" de "Venta al público" aparece un control para elegir tipo de descuento
   (Sin descuento / Porcentaje / Monto fijo) y capturar su valor.
2. Al capturar un descuento, el total que se ve en el carrito y en el paso de cobro ya lo
   descuenta, con una línea "Descuento" visible mientras el descuento sea mayor a cero.
3. El pedido creado guarda `descuento_global_tipo`/`descuento_global_valor` y el `total` enviado
   coincide con el recalculado en el servidor (sin `422`).
4. El ticket generado imprime el renglón "Descuento" cuando la venta lleva uno (comportamiento ya
   existente de `TicketPedidoService`, ahora alcanzable desde esta pantalla).
5. "Nueva venta" deja el descuento en blanco para la siguiente captura.
6. Factura y Cotización de mostrador no cambian: no aparece ningún control de descuento global
   nuevo en esas pantallas y sus totales no se alteran.
7. "Venta al público" sigue sin ofrecer descuento por artículo ni vincular un cliente del catálogo.

## Supuestos asumidos (registro completo)

1. "Venta al público" gana un descuento **global** (monto fijo o porcentaje), igual que Factura y
   Cotización de mostrador.
2. **(Corregido tras revisar el código)** No se agrega descuento por artículo/línea en "Venta al
   público": `CarritoMostrador.vue` —compartido por las tres pantallas de mostrador— ya tiene la
   decisión de diseño documentada de que el descuento fino por renglón se captura solo en
   escritorio, y romperla no era necesario para resolver el bug reportado.
3. "Venta al público" sigue sin vincular un `Cliente` real (mantiene nombre/teléfono/correo en
   texto libre); por lo tanto el descuento permanente por cliente no se aplica automáticamente
   aquí, solo el descuento global manual de esta historia.
4. Cualquier usuario con acceso a "Venta al público" puede aplicar el descuento, sin restricción de
   rol o permiso adicional a los que ya existen para acceder a esa pantalla.
5. No hay límite máximo de descuento (monto ni porcentaje) distinto al que ya aplican los demás
   documentos, que hoy es ninguno.
6. El descuento aplicado se refleja en el ticket impreso reusando el renglón "Descuento" que
   `TicketPedidoService` ya genera cuando `total_descuento > 0`, sin cambios ahí.

## Estado de implementación

Implementada el 2026-08-23.

- **Archivos modificados**: `frontend/src/components/mostrador/CarritoMostrador.vue` (prop
  `permiteDescuentoGlobal`, modelos `descuentoGlobalTipo`/`descuentoGlobalValor`, bloque de
  descuento y renglón "Descuento" en el resumen) y
  `frontend/src/views/mostrador/MostradorVentaView.vue` (refs de descuento, `totales`, payload de
  `cobrar()` y limpieza en `nuevaVenta()`). Sin cambios en backend.
- **Verificación**: `npm run build`, `npm run lint` y Vitest (95 tests) en verde; `php artisan test
  --filter=Pedido` en verde (31 pruebas, 160 aserciones) confirmando que el backend, sin tocarse,
  sigue aceptando y calculando el descuento igual que para facturas y cotizaciones.
- **No se pudo verificar en vivo** (sin navegador disponible en este entorno): la captura táctil
  del descuento y su reflejo en el ticket compartido desde el celular. La cobertura de tipos y
  pruebas automatizadas confirma el cableado; falta la verificación visual en un navegador real.
