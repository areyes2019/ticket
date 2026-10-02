# Spec: Cotizaciones con estilo bandeja

**Modifica:** [011-cotizaciones.md](011-cotizaciones.md), solo el listado (`/cotizaciones`) y la parte
del detalle que dibuja el documento. **Toma la distribución de:**
[013-dashboard-bandeja-correo.md](013-dashboard-bandeja-correo.md): tres columnas (carpetas, lista y
visor), con sus piezas y estilos.

> **Desde [020](020-dashboard-cotizaciones-facturas.md)** la vista previa de la cotización también
> se usa en el dashboard, y su botón "Facturar" timbra directo tras una ventana de confirmación (ya no
> abre el formulario de factura). `bandeja-documentos.js` admite varias listas con un solo visor.

## Historia de usuario

Como usuario, quiero ver mis cotizaciones como en un gestor de correo, con carpetas por periodo y
etiquetas por estado, para encontrar una cotización y revisarla, enviarla o descargarla sin salir de
la lista.

**Ruta:** `http://ticket_factura.test/public/cotizaciones` (ruta `cotizaciones.index`, ya existente).

## Objetivo / Alcance

- La bandeja **reemplaza** el listado en tabla de 011. El detalle, el formulario de alta y edición,
  el PDF, los pagos y la caducidad siguen igual.
- El dashboard conserva su bandeja de correo de demostración (013). Aquí se reutilizan su diseño y
  sus piezas, pero con **cotizaciones reales** del usuario.
- La cotización elegida se ve a la derecha **en HTML**, sin recargar la página, con el mismo contenido
  que el PDF.

## Backend (Laravel)

### Rutas

Nueva, antes del `Route::resource`:

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/cotizaciones/{cotizacion}/vista-previa` | fragmento HTML del visor (acciones, hoja y ventana de envío) | `cotizaciones.vista-previa` |

`cotizaciones.index` y `cotizaciones.buscar` cambian de parámetros (ver abajo). Las demás rutas de 011
no cambian.

### Parámetros de la bandeja (`ListadoCotizacionesRequest`)

Como antes, no rechaza nada: un valor inválido se ignora.

| Parámetro | Valores | Por defecto |
|---|---|---|
| `periodo` | `hoy`, `semana`, `mes`, `todas` | `mes` |
| `estado` | `borrador`, `enviada`, `pagada`, `producto_entregado`, `por_caducar` | ninguno |
| `q` | texto libre | vacío |
| `cotizacion` | id de la cotización abierta | la primera de la lista |
| `page` | página | 1 |

- **Se retiran** los parámetros `cliente`, `rfc`, `folio`, `fecha_desde` y `fecha_hasta` de 011 (el
  buscador único y "Todas" los sustituyen).
- `rango(periodo)` es estático: lo usan los filtros y los contadores. "Todas" no tiene límite.
- `etiquetas()` devuelve los estados (`EstadoCotizacion::opciones()`) más "Por caducar".
- `parametros()` arma los enlaces sin `cotizacion`: al cambiar de carpeta o etiqueta se abre la
  primera cotización de la lista nueva.

### Modelo `Cotizacion`

- Constante `POR_CADUCAR = 'por_caducar'`: valor del filtro de estado que no es un estado.
- **Scope `porCaducar()`**: las que ya muestran el aviso de caducidad. Es la misma regla que
  `mostrarAvisoCaducidad()` expresada en SQL: `borrador` o `enviada`, sin pagos y con `updated_at`
  anterior al inicio de hoy (zona del negocio) menos `DIAS_CADUCIDAD − DIAS_AVISO_CADUCIDAD − 1` días
  (22). Una prueba compara las dos en el límite (22 y 23 días).
- **Scope `filtrar()`** recibe `texto`, `folio`, `estado`, `desde` y `hasta`:
  - `texto` busca en la razón social, el nombre comercial y el RFC del cliente (incluidos clientes
    eliminados). Para el RFC se quitan los espacios y se pasa a mayúsculas.
  - Si el texto parece folio (`12`, `0012`, `COT-0012`), también coincide por folio (con **O**). Un
    número solo puede traer además clientes cuyo RFC contenga esos dígitos.
  - `estado = por_caducar` aplica `porCaducar()`; cualquier otro estado, igualdad.

### Controladores

- **`CotizacionController::index`**: misma consulta paginada de 011 (25 por página,
  `withQueryString()`, más recientes primero). Además:
  - `abierta`: la cotización de `?cotizacion=` si es del usuario (una ajena o inexistente se ignora
    sin error). Si no hay, la primera de la página. Se carga con `cliente`, `lineas` y `pagos`.
  - `contadores`: cuántas cotizaciones tiene cada carpeta, **sin** etiqueta ni búsqueda, en una sola
    consulta (`sum(case when created_at between … )` por periodo y `count(*)` para "Todas").
- **`buscar`**: devuelve `_resultados` (carpetas, filas y paginación), con los enlaces de página hacia
  la bandeja.
- **`vistaPrevia`**: `Gate::authorize('view')` (ajena → 404) y el parcial `_vista-previa`.
- **`EnvioCotizacionController::correo`**: si el formulario trae `origen=bandeja`, al enviar con éxito
  regresa a la página anterior **solo si es la bandeja** (`/cotizaciones?…`), para conservar carpeta,
  etiqueta, búsqueda y cotización abierta. Si la página anterior es otra, va a
  `/cotizaciones?cotizacion={id}`. Sin `origen`, sigue yendo al detalle. Los errores siguen regresando
  con `back()` y la bolsa `envio`.

## Vistas (Blade)

### `cotizaciones/index.blade.php`: la bandeja

- `@section('contenido-clase', 'contenido-bandeja')`: ocupa toda la pantalla bajo el menú, como 013.
- `<h1>` solo para lectores de pantalla, los avisos de sesión (`documentos._mensajes`) y los errores
  de envío (bolsa `envio`).
- Un formulario oculto `#filtros-cotizaciones` con `data-busqueda-dinamica` y dos campos ocultos,
  `periodo` y `estado`, marcados `data-busqueda-sincronizar`. El buscador de la lista se asocia con
  `form="filtros-cotizaciones"`.

| Columna | Contenido |
|---|---|
| Izquierda (`_carpetas`) | Botón **"Nueva cotización"** (`bi-plus-lg`, lleva a `cotizaciones.create`), carpetas y etiquetas |
| Centro | Buscador ("Buscar por folio, cliente o RFC"), filas (`_filas`) y paginación (`_paginacion`) |
| Derecha | La vista previa de `abierta`, o "Selecciona una cotización" |

### Carpetas y etiquetas (`_carpetas`)

| Carpeta | Icono |
|---|---|
| Hoy | `bi-calendar-day` |
| Esta semana | `bi-calendar-week` |
| Este mes (activa al entrar) | `bi-calendar-month` |
| Todas | `bi-inbox` |

- Cada carpeta muestra su contador cuando es mayor que cero.
- Etiquetas: **Borrador**, **Enviada**, **Pagada**, **Entregada** y **Por caducar**, cada una con el
  cuadrito del color de su etiqueta de estado (`claseEtiqueta()`; "Por caducar" usa el color de
  advertencia).
- Carpeta y etiqueta **se combinan**. Pulsar la etiqueta activa la quita.
- Todas son enlaces normales (`data-busqueda-enlace`) que llevan los filtros actuales. La opción
  activa lleva `aria-current="true"`.

### Fila (`<x-cotizaciones.fila>`)

- Iniciales del cliente (`<x-bandeja.avatar>` de 013), razón social, fecha (hora si es de hoy, "Ayer",
  o fecha corta, en la zona del negocio), folio, total, etiqueta de estado y el aviso "Se elimina en N
  días" cuando aplica.
- Es un enlace al **detalle**, con `data-vista-previa` apuntando a `cotizaciones.vista-previa`. Sin
  JavaScript abre el detalle; con él abre la vista previa.
- Sin estrella, sin negritas de "no leído" y sin clip.
- Sin resultados: "Sin cotizaciones".

### Vista previa (`_vista-previa`)

- Barra de acciones:
  - "Volver" (solo en celular),
  - **"Enviar"** (`bi-send`): abre la ventana de correo de 011 (`#dialogo-envio`), con el correo del
    cliente prellenado y `origen=bandeja`,
  - **"Descargar"** (`bi-download`): `cotizaciones.pdf?descargar=1`,
  - "Abrir" (`bi-box-arrow-up-right`, discreto, a la derecha): el detalle. Editar, WhatsApp, pagos,
    Entregar, Duplicar y Eliminar siguen **solo** en el detalle.
- Etiqueta de estado y aviso de caducidad sobre la hoja.
- La hoja (`<x-cotizaciones.hoja>`).
- Con errores de envío, la ventana se abre sola al cargar (`data-abrir-al-cargar`).

### Hoja (`<x-cotizaciones.hoja>`)

- El contenido del PDF en HTML: negocio, folio, fecha, cliente (razón social, RFC, correo y
  teléfono si existen), líneas (cantidad, descripción, modelo, precio unitario, descuento, importe),
  subtotal, descuento (si hay), IVA 16%, total y, si hay pagos, pagado y saldo pendiente.
- Aspecto de hoja: fondo blanco sobre el gris del visor, ancho máximo `--ancho-hoja` (52rem) y
  esquinas rectas. La tabla de líneas se desplaza dentro de la hoja si no cabe.
- **La usa también el detalle** (`show.blade.php`), en lugar de sus tarjetas "Cliente", "Líneas" y
  "Totales", seguida de "Último movimiento". Así el documento se dibuja en un solo lugar para la
  pantalla (el PDF conserva su vista propia porque Dompdf no lee `app.css`).

### Piezas de 013 reutilizadas

- `<x-bandeja.avatar>` sin cambios.
- `<x-bandeja.encabezado-lista>` gana props opcionales `texto`, `nombre`, `valor` y `formulario`. Sin
  ellos se comporta igual que en el dashboard.
- Todas las clases `bandeja-*` de `app.css`. Solo se agregan las que cambian (enlaces en lugar de
  botones, total de la fila, paginación, fondo del visor y la hoja).

## JavaScript

### `public/js/bandeja-cotizaciones.js` (nuevo)

> Desde [017](017-ordenes-compra.md) este script es `public/js/bandeja-documentos.js`, genérico
> (`data-bandeja-documentos`, `data-parametro="cotizacion"`, `data-visor-documento`) y compartido
> con la bandeja de órdenes de compra. El comportamiento de la cotización descrito aquí no cambió.

- **Clic en una fila**: pide `cotizaciones.vista-previa` con Axios (cancela la petición anterior), la
  pone en el visor, resalta la fila y guarda `?cotizacion={id}` en la URL con `history.replaceState`.
  Ctrl, Cmd o Mayús + clic abren el detalle como un enlace normal.
- En celular, abrir una fila muestra el visor a pantalla completa y "Volver" regresa a la lista.
- "Carpetas" las despliega en tableta y celular, y elegir una las cierra.
- **Tras cada búsqueda** (evento `busqueda:actualizada`): si la cotización abierta sigue en la lista
  se resalta; si no, se abre la primera. Si la lista queda vacía, se muestra "Selecciona una
  cotización".
- Errores: 401/419 o una redirección recargan la página, y cualquier otro error muestra "No se pudo
  abrir la cotización. Intenta de nuevo."

### `public/js/busqueda-dinamica.js` (extensión genérica)

Después de reemplazar los resultados y actualizar la URL, el formulario dispara
`busqueda:actualizada` (burbujea). Los demás listados no lo escuchan y no cambian.

**Sin JavaScript:** carpetas, etiquetas y paginación recargan la página. El buscador necesita Enter y
no tiene botón, porque el formulario está oculto. Una fila abre el detalle, y la vista previa inicial
y la ventana de envío (`dialog:target`) funcionan.

## Diseño adaptable

El mismo de 013:

- **Escritorio:** tres columnas.
- **Tableta:** lista y visor, con las carpetas en "Carpetas".
- **Celular:** una columna a la vez, con "Volver".

No hay scroll horizontal de página en ningún tamaño.

## Pruebas (Pest)

- `CotizacionesTest`, bloque `bandeja` (reemplaza al bloque `listado`):
  - "Este mes" por defecto y "Todas",
  - contadores por carpeta, sin contar cotizaciones ajenas,
  - periodos en la zona del negocio (23:30 del 31 de agosto),
  - carpeta + etiqueta combinadas,
  - enlace para quitar la etiqueta activa,
  - "Por caducar" (límite de 22 y 23 días, contra `mostrarAvisoCaducidad()`),
  - búsqueda por razón social, nombre comercial, RFC con espacios y folio (`12` y `COT-0012`),
  - vista previa inicial de la primera cotización, con los enlaces de Enviar y Descargar,
  - `?cotizacion=` propia y ajena,
  - fragmentos de `vista-previa` y `buscar`.
- `vista-previa` de una cotización ajena responde 404 (se suma a la prueba de aislamiento).
- `EnvioCotizacionTest`: con `origen=bandeja` regresa a la bandeja anterior; si la página anterior es
  otra (u otro sitio), a `/cotizaciones?cotizacion={id}`.
- `PurgarCotizacionesTest` usa `?periodo=todas` en lugar del rango de fechas.

## Fuera de alcance

- Editar, compartir por WhatsApp, registrar pagos, entregar, duplicar o eliminar desde la bandeja
  (siguen en el detalle).
- Rango de fechas personalizado.
- Contadores en las etiquetas, o contadores que dependan de la etiqueta o de la búsqueda.
- Marcar como leída, destacar o seleccionar varias cotizaciones.
- Llevar el estilo bandeja a facturas.
- Cambiar el PDF.

## Estado de implementación

Implementada el 2026-09-30.

- **Archivos nuevos**:
  - `components/cotizaciones/fila.blade.php` y `hoja.blade.php`,
  - `cotizaciones/_carpetas.blade.php` y `_vista-previa.blade.php`,
  - `public/js/bandeja-cotizaciones.js`.
- **Archivos modificados**:
  - `ListadoCotizacionesRequest`, `Cotizacion` (`POR_CADUCAR`, `porCaducar`, `filtrar`),
    `CotizacionController` (`index`, `buscar`, `vistaPrevia`, contadores) y
    `EnvioCotizacionController`,
  - `routes/web.php`,
  - las vistas `index`, `show`, `_filas`, `_resultados` y `_paginacion`,
  - `components/bandeja/encabezado-lista`, `busqueda-dinamica.js` y `app.css`.
- **Archivo eliminado**: `cotizaciones/_atajos.blade.php`.
- **Verificación**:
  - la suite Pest pasa completa (627 pruebas),
  - Pint no reporta cambios pendientes,
  - `node --check` valida los scripts y `node --test` pasa.

  **No se revisó la UI en un navegador real.** Falta abrir `/cotizaciones` en escritorio, tableta y
  celular y probar tres cosas: el clic en filas, filtrar con una cotización abierta, y enviar un correo
  desde la bandeja.

## Criterios de aceptación

1. `/cotizaciones` muestra la bandeja de tres columnas bajo el menú, con "Nueva cotización", las
   carpetas Hoy, Esta semana, Este mes y Todas (con contador) y las etiquetas Borrador, Enviada,
   Pagada, Entregada y Por caducar (con su color).
2. Al entrar está activa "Este mes" y se ve abierta la primera cotización de la lista.
3. Carpeta y etiqueta se combinan y filtran sin recargar; pulsar la etiqueta activa la quita.
4. El buscador filtra por folio (`12` o `COT-0012`), cliente o RFC mientras se escribe.
5. Cada fila muestra iniciales, cliente, fecha, folio, total, estado y el aviso de caducidad.
6. Clic en una fila muestra la cotización a la derecha en HTML, sin recargar, con el contenido del PDF.
7. Arriba de la hoja están "Enviar" (ventana de correo con el correo del cliente) y "Descargar"
   (PDF), más "Abrir" hacia el detalle.
8. Enviar desde la bandeja regresa a la bandeja con la misma carpeta, etiqueta y cotización abierta,
   con el aviso de éxito. Un error de envío reabre la ventana con el mensaje.
9. Recargar la página conserva la cotización abierta.
10. Una cotización ajena no se muestra ni por `?cotizacion=` ni por `vista-previa` (404).
11. En celular se ve una columna a la vez, con "Carpetas" y "Volver". No hay scroll horizontal.
12. El detalle muestra el documento con la misma hoja.
13. El dashboard sigue igual.
14. Pint no reporta cambios y la suite Pest pasa.

## Supuestos asumidos (registro completo)

Todos fueron aprobados sin cambios.

1. La bandeja reemplaza el listado actual de `/cotizaciones`. El detalle, el formulario, el PDF, los
   pagos y la caducidad no cambian.
2. El dashboard sigue con su bandeja de demostración (013); aquí solo se reutiliza su diseño.
3. La bandeja muestra cotizaciones reales del usuario.
4. "Nueva cotización" en lugar de "Redactar", y lleva al formulario actual, no a una ventana.
5. Carpetas Hoy, Esta semana y Este mes, con "Este mes" activa al entrar, y cada una con su contador.
6. Una cuarta carpeta, "Todas", sin límite de fecha.
7. Etiquetas por estado (Borrador, Enviada, Pagada, Entregada) con su color, más "Por caducar".
8. Carpeta y etiqueta se combinan; volver a pulsar la etiqueta activa la quita.
9. Se retira el rango de fechas personalizado.
10. Un solo buscador por folio, cliente y RFC, que filtra mientras se escribe.
11. Cada fila muestra iniciales, cliente, folio, total, fecha, estado y aviso de caducidad.
12. Más recientes primero, 25 por página, con paginación al pie de la lista.
13. Sin estrella, negritas de no leído ni contadores de no leídos.
14. Sin resultados: "Sin cotizaciones".
15. Clic en una fila muestra la cotización a la derecha en HTML sin recargar, con el contenido del
    PDF y aspecto de hoja.
16. Arriba del visor, "Enviar" (la ventana de correo existente) y "Descargar" (el PDF).
17. "Abrir" lleva al detalle; las demás acciones no pasan a la bandeja.
18. Al entrar se abre la primera; con la lista vacía, "Selecciona una cotización".
19. La cotización abierta queda en la URL (`?cotizacion=15`).
20. Después de enviar por correo se regresa a la bandeja con esa cotización abierta.
21. En tableta y celular, como 013: "Carpetas" y "Volver".
22. Sin JavaScript, los filtros recargan la página y una fila abre el detalle.

**Adiciones técnicas aprobadas:**

1. La vista previa se pide al servidor al hacer clic, en lugar de traer todas las cotizaciones
   escondidas en la página.
2. Se reutilizan las piezas de la bandeja de 013; solo se crean la fila y la hoja de cotización.
3. La hoja es una pieza aparte que usan la vista previa y el detalle.
