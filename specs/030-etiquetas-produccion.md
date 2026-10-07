# Spec: Planilla de etiquetas de producción

> **Estado: implementada** el 2026-10-06, el mismo día en que se definió con el usuario. Ver "Estado de
> implementación".

> **Desde [031](031-formatos-etiquetas.md)** (definida el 2026-10-06): la planilla ya no es fija. El
> tamaño de la etiqueta, las separaciones y los márgenes se ajustan y se guardan como formatos.
> Con los valores de fábrica sale igual que aquí.

**Extiende:** [022-ordenes-trabajo.md](022-ordenes-trabajo.md) (hoja de producción). Usa las mismas
órdenes "En proceso" que la hoja de producción, en el mismo orden, y las presenta como etiquetas
recortables con ticket, cliente, teléfono, saldo y modelo.

**No modifica:** la hoja de producción (022) ni la etiqueta individual de 50 × 25 mm de la venta
(019, `pedidos/etiqueta`). Las dos siguen igual.

## Historia de usuario

Como usuario, quiero imprimir una lista de los pedidos que están en producción, como la hoja de
producción, pero en etiquetas rectangulares de unos 6 × 3 cm sobre una planilla. Cada etiqueta lleva:

- número de ticket;
- nombre del cliente;
- teléfono;
- el saldo, si hay saldo, o "PAGADO" si ya está pagado;
- modelo del sello.

## Objetivo / Alcance

- Una página nueva, sin el layout de la aplicación, que pinta **una etiqueta de 60 × 30 mm por cada
  orden de trabajo "En proceso"** sobre hojas tamaño carta de **3 columnas × 8 renglones (24
  etiquetas)**.
- Antes de imprimir, el usuario elige **en qué etiqueta de la primera hoja empezar** (1 a 24), para
  aprovechar una planilla ya usada a medias.
- El texto que no cabe **achica su letra** hasta un mínimo legible antes de cortarse con "…".

## Contenido de la etiqueta

Cinco renglones, de arriba abajo:

| Renglón | Dato | Formato |
|---|---|---|
| 1 | Número de ticket | `PED-0005` (`folio_formateado`), negritas, el más grande |
| 2 | Cliente | `cliente_nombre` de la venta |
| 3 | Teléfono | `telefono_legible`; vacío si la venta no tiene teléfono |
| 4 | Saldo | `SALDO: $1,234.00` en negritas si `tieneSaldo()`, si no `PAGADO` (misma regla y formato que la etiqueta de 019) |
| 5 | Modelo | Los modelos de `lineasDeTrabajo()` separados por coma, en el orden de las líneas |

Detalle del renglón de modelo:

- Solo el modelo, **sin cantidad** (dos sellos iguales no muestran "×2").
- Un modelo repetido en varias líneas aparece **una sola vez**.
- Las líneas sin modelo (líneas libres) no aportan nada. Si ninguna línea tiene modelo, el renglón
  muestra "—".

No lleva imagen del diseño, color de tinta, fecha, descripción del artículo ni QR.

## Backend (Laravel)

### Consulta compartida

La consulta de `HojaProduccionController` (órdenes del usuario en `EstadoOrdenTrabajo::EnProceso`,
ordenadas por el `folio` de la venta, de la más antigua a la más reciente) se mueve a un solo lugar
que usen los dos controladores, por ejemplo un scope `enProduccion()` en `OrdenTrabajo`. La hoja de
producción no cambia de comportamiento.

### Ruta

```php
Route::get('pedidos/produccion/etiquetas', EtiquetasProduccionController::class)
    ->name('pedidos.produccion.etiquetas');
```

Va dentro del mismo grupo autenticado que `pedidos.produccion` y **antes** de las rutas
`pedidos/{pedido}`, para que "produccion" no se tome por un id.

### Controlador `EtiquetasProduccionController` (nuevo, invocable)

- Lee el parámetro opcional `inicio` (entero de 1 a 24, por defecto 1). Un valor fuera de rango o
  no numérico se toma como 1, sin error.
- Carga las órdenes con la consulta compartida y eager loading de `pedido.lineas` (más lo que
  necesite `lineasDeTrabajo()`), para no hacer una consulta por etiqueta.
- Pasa a la vista `ordenes-trabajo/etiquetas` las órdenes y `inicio`.

## Vista (Blade): `ordenes-trabajo/etiquetas`

Estilos propios, blanco y negro, igual que `ordenes-trabajo/imprimir`.

### Hoja

- `@page { size: letter; margin: 0; }`.
- La rejilla de 3 × 8 etiquetas de 60 × 30 mm (180 × 240 mm) va **centrada** en la hoja carta
  (215.9 × 279.4 mm): unos 18 mm a los lados y unos 19.7 mm arriba y abajo. Sin separación entre
  etiquetas.
- Cada etiqueta lleva un **borde delgado** (0.2 mm) como guía de corte.
- Antes de la primera etiqueta van `inicio - 1` **casillas vacías** (sin borde), así las etiquetas
  ya usadas de la planilla se quedan en blanco.
- Cada 24 casillas (contando las vacías) empieza una hoja nueva (`break-after: page`). Las hojas
  siguientes empiezan en la casilla 1.
- Cada etiqueta tiene 2 mm de margen interior y el texto alineado a la izquierda.

### Barra de pantalla (solo `@media screen`, no se imprime)

- Título "Etiquetas de producción" y el conteo: "5 órdenes en proceso · 1 hoja".
- Un formulario `GET` con el campo numérico **"Empezar en la etiqueta"** (`inicio`, 1 a 24) y el
  botón "Aplicar", que recarga la página con ese valor.
- El botón **"Imprimir"** (`window.print()`).
- En pantalla, la hoja se ve sobre fondo gris con su contorno, como vista previa.

Esta página **no se imprime sola al cargar** (no usa `imprimir-al-cargar.js`): primero hay que
elegir dónde empezar.

Sin órdenes en proceso, la página muestra "No hay órdenes en proceso." y no pinta la hoja.

## JavaScript: `public/js/etiquetas-produccion.js` (nuevo)

- **Achicar la letra (adición 2):** por cada renglón de cada etiqueta, mientras el texto se salga
  del ancho (`scrollWidth > clientWidth`), baja la letra de 0.5 pt en 0.5 pt hasta un **mínimo de
  7 pt**. Si a 7 pt sigue sin caber, el CSS lo corta con "…" (`white-space: nowrap; overflow:
  hidden; text-overflow: ellipsis`). Corre al cargar la página y antes de imprimir
  (`beforeprint`).
- La lógica de cálculo (de un tamaño y un ancho a un tamaño nuevo) va en una función pura,
  probada con `node --test`.
- Sin JavaScript la página funciona igual: los textos largos se cortan con "…".

Tamaños de partida: ticket 14 pt, saldo 11 pt, cliente, teléfono y modelo 10 pt.

## Botones de acceso

Junto al botón "Hoja de producción" que ya existe, uno nuevo, **"Imprimir etiquetas"**, que abre
`pedidos.produccion.etiquetas` en otra pestaña (`target="_blank"`):

- en el listado de ventas (`pedidos/index`), con texto;
- en el dashboard, en las herramientas de la columna "Órdenes de trabajo", solo con ícono y
  `title="Imprimir etiquetas"`, igual que el de la hoja.

## Pruebas (Pest)

`tests/Feature/EtiquetasProduccionTest.php`:

1. Salen solo las órdenes "En proceso" del usuario, ordenadas por folio. No salen las de otros
   estados ni las de otro usuario.
2. Cada etiqueta muestra `PED-xxxx`, cliente, teléfono y modelo.
3. Una venta con saldo muestra `SALDO: $…`, y una pagada muestra `PAGADO`.
4. Varias líneas muestran sus modelos separados por coma, sin cantidad y sin repetir. Las líneas sin
   modelo no aparecen. Sin modelos, el renglón muestra "—".
5. `inicio=10` pinta 9 casillas vacías antes de la primera etiqueta. `inicio=0`, `inicio=25` e
   `inicio=abc` se toman como 1.
6. 25 órdenes con `inicio=1` hacen dos hojas. 20 órdenes con `inicio=10` también hacen dos.
7. Sin órdenes se ve "No hay órdenes en proceso.".
8. Un invitado es redirigido al login.
9. La hoja de producción (022) sigue pasando sus pruebas sin cambios.

`tests/js/etiquetas-produccion.test.js`: la función de ajuste baja hasta 7 pt y no baja de ahí.

## Fuera de alcance

- Escoger qué pedidos imprimir: siempre salen todos los "En proceso".
- Ajustar márgenes o calibrar la impresora desde la página (adición 3, rechazada): si algo se sale
  de la etiqueta, se corrige en la configuración de impresión del navegador o de la impresora.
- Otros tamaños de planilla o de etiqueta.
- Imagen del diseño, color de tinta, QR o código de barras en la etiqueta.
- Marcar las órdenes como impresas.

## Criterios de aceptación

1. Desde el listado de ventas y desde el dashboard, "Imprimir etiquetas" abre la planilla en otra
   pestaña.
2. Sale una etiqueta de unos 60 × 30 mm por cada orden "En proceso", en orden de ticket, con
   ticket, cliente, teléfono, saldo o "PAGADO" y modelos.
3. La hoja carta lleva 24 etiquetas (3 × 8) con borde de corte. Si hay más, sigue en otra hoja.
4. "Empezar en la etiqueta" deja en blanco las casillas anteriores de la primera hoja.
5. Un nombre o una lista de modelos largos achica su letra hasta 7 pt antes de cortarse con "…".
6. La hoja de producción y la etiqueta individual de la venta siguen igual.
7. `php artisan test`, `pint` y `node --test "tests/js/*.test.js"` en verde.

## Supuestos asumidos (registro completo)

Revisados con el usuario el 2026-10-06. Se aprobaron todos sin cambios.

1. Salen solo los pedidos cuya orden de trabajo está "En proceso", los mismos de la hoja de
   producción.
2. Una etiqueta por pedido, aunque tenga varios sellos.
3. Varios modelos van en la misma etiqueta, separados por coma. Si no caben, se cortan con "…"
   (después de achicar la letra, por la adición 2).
4. Solo el modelo, sin cantidad.
5. Etiqueta de 60 × 30 mm.
6. Hoja carta de 3 columnas × 8 renglones (24 etiquetas). Si hay más pedidos, sigue en otra hoja.
7. Orden por número de ticket, del más antiguo al más reciente.
8. Sin imagen del diseño: solo texto.
9. Con saldo: "SALDO: $123.00" en negritas. Pagado: "PAGADO".
10. Solo los 5 datos pedidos: ticket, cliente, teléfono, saldo o pagado, y modelo.
11. Botón "Imprimir etiquetas" junto al de la hoja de producción, que abre la planilla para
    imprimir desde el navegador.
12. Se imprimen siempre todos los pedidos en producción, sin escoger.
13. Cada etiqueta lleva un borde delgado como guía de corte.
14. Cliente sin teléfono: el renglón del teléfono se queda vacío.

Decididos al redactar, sin revisión una por una:

- Un modelo repetido en varias líneas de la venta aparece una sola vez.
- Las líneas sin modelo no aportan al renglón. Sin ningún modelo, se muestra "—".
- La rejilla va centrada en la hoja, sin separación entre etiquetas.
- La página no se imprime sola al cargar, para dar oportunidad de elegir dónde empezar.

### Adiciones técnicas

1. **Empezar en otra etiqueta de la planilla:** aceptada. Campo "Empezar en la etiqueta" (1 a 24).
2. **Achicar la letra en vez de cortar:** aceptada. Mínimo 7 pt; si aun así no cabe, se corta
   con "…".
3. **Ajuste fino de márgenes:** rechazada. Se ajusta desde la configuración de la impresora.

## Estado de implementación

Implementada el 2026-10-06.

- `OrdenTrabajo::enProduccion()` (scope): la consulta de la hoja de producción, ahora compartida con
  `EtiquetasProduccionController`. `HojaProduccionController` la usa sin cambiar de comportamiento.
- `Pedido::modelosDeTrabajo()`: los modelos de `lineasDeTrabajo()` sin vacíos ni repetidos.
- `EtiquetasProduccionController` (invocable), ruta `pedidos.produccion.etiquetas`. `inicio` se lee
  con `FILTER_VALIDATE_INT` (1 a 24, por defecto 1). Precarga `pedido.lineas.articulo.catalogo`,
  `pedido.pagos` y `pedido.cotizacion.pagos` para el saldo.
- Vista `ordenes-trabajo/etiquetas`. **Diferencia con lo redactado:** la barra usa `<x-campo>` y
  `<x-boton>` (lo exige `EstiloUniformeTest`), así que la página carga `app.css` y Bootstrap Icons.
  Para no chocar con las clases de `app.css` (`.barra`, `.etiqueta`, `.casilla…`), las de la planilla
  llevan el prefijo `planilla-`.
- `public/js/etiquetas-produccion.js`: `tamanoQueCabe()` (pura, probada en
  `tests/js/etiquetas-produccion.test.js`) más un ajuste paso a paso de medio punto hasta 7 pt, al
  cargar y en `beforeprint`. El botón "Imprimir" de la barra (`data-imprimir`).
- Botón "Imprimir etiquetas" (ícono `tags`) en `pedidos/index` y en la columna de órdenes del
  dashboard.
- Pruebas: `tests/Feature/EtiquetasProduccionTest.php`. Suite completa, `pint` y `node --test` en
  verde. Revisado a ojo con Chrome sin interfaz: pantalla e impresión a PDF en una hoja carta.
