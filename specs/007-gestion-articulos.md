# Spec: Gestión de artículos (catálogo fiscal SAT y precio unitario)

**Referencia:** reescritura de [remotas/006-gestion-articulos.md](remotas/006-gestion-articulos.md),
que se diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan las reglas de
negocio y las lecciones aprendidas con archivos CSV reales; la parte del navegador y el protocolo
entre navegador y servidor se rehicieron para Laravel + Blade + JavaScript nativo. Se retiró lo que
la spec remota había heredado de historias posteriores (catálogos, costo/utilidad, goma, filtros
por columna, imágenes): cada una llegará con su propia spec.

## Historia de usuario

Como usuario del sistema de facturación, quiero administrar (crear, ver, editar y eliminar) mis
artículos de acuerdo con el sistema fiscal mexicano (clave de producto/servicio, clave de unidad y
objeto de impuesto del SAT) y con su precio unitario, ligando cada artículo a uno de mis
proveedores, para tener un catálogo de artículos con el que dar precio a mis clientes y que quede
listo para timbrar CFDI cuando exista el módulo de facturación, sin volver a capturar sus datos
fiscales cada vez.

## Objetivo / Alcance

Implementar un módulo CRUD de artículos sobre la arquitectura monolítica Laravel + Blade +
JavaScript nativo de [001-inicio-proyecto.md](001-inicio-proyecto.md), con la sesión web de
[002-login.md](002-login.md), los componentes Blade de [003-estilo-uniforme.md](003-estilo-uniforme.md)
y las convenciones de [004-gestion-proveedores.md](004-gestion-proveedores.md) y
[005-gestion-clientes.md](005-gestion-clientes.md). Cada artículo pertenece a un `Proveedor` ya
existente (004).

- Laravel resuelve el módulo completo: rutas web, controladores, validación, autorización, vistas
  Blade, importación y exportación CSV.
- No se crea API REST, no se usa Sanctum ni API Resources, y no hay stores ni estado en el
  frontend.
- **Nace la infraestructura de catálogos SAT** que 005 difirió: las tablas `c_ClaveProdServ` y
  `c_ClaveUnidad` en MySQL y el comando que las llena.
- JavaScript nativo, **sin librerías de tablas** (ni DataTables ni jQuery), en cuatro lugares: la
  **búsqueda dinámica** del listado (reutiliza `busqueda-dinamica.js` de 005, con una extensión
  mínima para el orden por columna), el **campo con sugerencias** de las claves SAT (Axios), el
  **precio con IVA en vivo** del formulario y la confirmación antes de eliminar (manejador
  `data-confirmar` ya existente). Todo tiene respaldo sin JavaScript.

Incluye importación y exportación de artículos vía CSV. **No** incluye la emisión/timbrado de CFDI,
ni inventario/existencias.

## Backend (Laravel)

### Modelo y base de datos

- **Modelo `Articulo`** (tabla `articulos`), perteneciente a un `User` (`user_id`) y a un
  `Proveedor` (`proveedor_id`, obligatorio), con **soft deletes** (`SoftDeletes`).
- Relaciones: `User::articulos()` y `Proveedor::articulos()` (`hasMany`); `Articulo::user()` y
  `Articulo::proveedor()` (`belongsTo`). `Articulo::proveedor()` incluye los proveedores
  eliminados (`->withTrashed()`), para que un artículo cuyo proveedor se borró siga mostrando su
  nombre en el listado.
- `user_id` **no** está en `fillable`: el alta se hace con
  `$request->user()->articulos()->create($datos)`. `proveedor_id` sí es asignable, pero la
  validación exige que el proveedor sea del usuario.
- **Campos**:
  - `nombre`: string, **obligatorio**.
  - `modelo`: string, **obligatorio**.
  - `clave_prod_serv`: string(8), **obligatoria**. Clave del catálogo SAT `c_ClaveProdServ`.
  - `clave_unidad`: string(3), **obligatoria**. Clave del catálogo SAT `c_ClaveUnidad` (ej. `H87`,
    `E48`). Se guarda en mayúsculas.
  - `objeto_imp`: string(2), **obligatorio**. Enum `App\Enums\ObjetoImpuesto` (backed `string`,
    catálogo `c_ObjetoImp`), con método `descripcion()`:
    - `01`: No objeto de impuesto.
    - `02`: Sí objeto de impuesto.
    - `03`: Sí objeto del impuesto y no obligado al desglose.
    - `04`: Sí objeto del impuesto y no causa impuesto.
  - `precio_unitario_sin_iva`: `decimal(10,2)`, **obligatorio**, mayor a 0, en pesos (MXN).
- **Precio con IVA**: no se persiste. Accessor `precio_unitario_con_iva`
  (`precio_unitario_sin_iva × 1.16`, redondeado a 2 decimales). La tasa vive en **un solo lugar**
  (constante `Articulo::TASA_IVA = 0.16`); la vista y el JavaScript la leen de ahí, nunca la
  escriben por su cuenta.
- Casts: `objeto_imp` → `ObjetoImpuesto`, `precio_unitario_sin_iva` → `decimal:2`.
- **Migración** `articulos`:
  - `user_id` y `proveedor_id` como `foreignId()->constrained()`.
  - `softDeletes()`.
  - Índices `(user_id, nombre)` para el listado y `(proveedor_id, nombre)` para la unicidad.
  - La unicidad de `nombre` **no** se impone en MySQL (mismo motivo que el RFC en 004: un índice
    único no puede excluir los borrados lógicamente).
- **Factory** `ArticuloFactory`, con claves SAT que existan en los datos de prueba.

### Catálogos SAT (`c_ClaveProdServ` y `c_ClaveUnidad`)

- Dos tablas en la **misma base MySQL** del sistema (no una base SQLite aparte):
  - `sat_claves_prod_serv`: `clave` string(8) clave primaria, `descripcion`.
  - `sat_claves_unidad`: `clave` string(3) clave primaria, `nombre`.
  - Solo claves **vigentes**; sin `timestamps`.
- Modelos de solo lectura `SatClaveProdServ` y `SatClaveUnidad` (clave primaria string, sin
  autoincremento), para mostrar la descripción de la clave guardada en un artículo.
- **Comando `php artisan catalogos-sat:actualizar`**: descarga los catálogos de la fuente pública
  que mantiene la comunidad phpCfdi (`phpcfdi/resources-sat-catalogs`, la misma que alimentaba la
  base de la arquitectura anterior) y reemplaza el contenido de ambas tablas dentro de una
  transacción, de modo que un fallo a mitad de la descarga deja los datos anteriores intactos.
  Reporta cuántas claves cargó en cada tabla. No corre solo: se ejecuta tras la primera migración
  y cuando el SAT publique cambios. Queda listo para que facturación le agregue `c_CodigoPostal`,
  `c_UsoCFDI`, etc.
- Las pruebas **no** dependen de la descarga: usan un puñado de claves insertadas por la prueba.
- **Búsqueda** (alimenta el campo con sugerencias; es el único caso del módulo que justifica una
  petición AJAX, porque 52 mil claves no caben en un `<select>`):
  - Coincidencia por prefijo de la clave **o** parcial en la descripción/nombre.
  - Máximo 20 resultados, ordenados por clave.
  - Responde JSON `[{ "clave": "…", "descripcion": "…" }]`. Es un dato para un control, no una
    pantalla: no hay HTML que reutilizar, a diferencia de `clientes/buscar`.
- `objeto_imp` **no** tiene tabla ni búsqueda: sus 4 opciones salen del enum.

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]`, **antes** del
`Route::resource` para que `buscar`/`importar`/`exportar` no se confundan con un `{articulo}`:

```php
Route::get('articulos/buscar', [ArticuloController::class, 'buscar'])->name('articulos.buscar');
Route::get('articulos/importar', [ImportacionArticulosController::class, 'create'])->name('articulos.importar');
Route::post('articulos/importar', [ImportacionArticulosController::class, 'store'])->name('articulos.importar.store');
Route::get('articulos/exportar', ExportacionArticulosController::class)->name('articulos.exportar');

Route::get('catalogos-sat/claves-prod-serv', [CatalogoSatController::class, 'clavesProdServ'])->name('catalogos-sat.claves-prod-serv');
Route::get('catalogos-sat/claves-unidad', [CatalogoSatController::class, 'clavesUnidad'])->name('catalogos-sat.claves-unidad');

Route::resource('articulos', ArticuloController::class)
    ->except('show')
    ->parameters(['articulos' => 'articulo']);
```

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/articulos` | página completa del listado, con filtros, orden y página en la URL | `articulos.index` |
| GET | `/articulos/buscar` | fragmento HTML (títulos, filas, paginación, exportar) para la búsqueda dinámica | `articulos.buscar` |
| GET | `/articulos/crear` | formulario de alta | `articulos.create` |
| POST | `/articulos` | alta | `articulos.store` |
| GET | `/articulos/{articulo}/editar` | formulario de edición | `articulos.edit` |
| PUT | `/articulos/{articulo}` | edición | `articulos.update` |
| DELETE | `/articulos/{articulo}` | borrado lógico | `articulos.destroy` |
| GET | `/articulos/importar` | pantalla de importación y su reporte | `articulos.importar` |
| POST | `/articulos/importar` | procesa el CSV | `articulos.importar.store` |
| GET | `/articulos/exportar` | descarga el CSV (respeta `?nombre=`, `?modelo=`, `?orden=` y `?direccion=`) | `articulos.exportar` |
| GET | `/catalogos-sat/claves-prod-serv?q=` | sugerencias (JSON) | `catalogos-sat.claves-prod-serv` |
| GET | `/catalogos-sat/claves-unidad?q=` | sugerencias (JSON) | `catalogos-sat.claves-unidad` |

- El proveedor destino de la importación viaja **en el formulario** (`proveedor_id`), no en la URL
  (`/proveedores/{proveedor}/articulos/importar-csv` en la spec remota): con un formulario HTML
  normal el usuario elige el proveedor en un `<select>` y la URL de envío no puede depender de esa
  elección sin JavaScript.

### Controladores

- **`ArticuloController`**:
  - **Parámetros del listado** (todos opcionales, en la URL):
    - `nombre`, `modelo`: filtros de coincidencia parcial, sin distinguir mayúsculas, combinados
      con **Y**; uno vacío se ignora.
    - `orden`: `nombre` (por defecto), `modelo`, `proveedor` o `precio`; `direccion`: `asc` (por
      defecto) o `desc`. Cualquier otro valor cae al orden por defecto, nunca llega a la consulta
      tal cual (lista blanca). Ordenar por `proveedor` usa una subconsulta sobre
      `proveedores.nombre_comercial` (incluidos los eliminados); ordenar por `precio` usa
      `precio_unitario_sin_iva`, que da el mismo orden que el precio con IVA. Siempre se desempata
      por `id` para que la paginación sea estable.
    - `por_pagina`: 10, 25 (por defecto), 50 o 100; otro valor cae a 25.
  - La consulta vive en dos scopes del modelo, `Articulo::scopeFiltrar(array $filtros)` y
    `Articulo::scopeOrdenar(string $columna, string $direccion)`, y un método privado del
    controlador lee y sanea los parámetros; así `index`, `buscar` y la exportación usan
    **exactamente** la misma lógica.
  - `index`: solo los artículos del usuario, con `proveedor` precargado (`with`), filtrados,
    ordenados y paginados con `->withQueryString()`; devuelve la vista completa.
  - `buscar`: la misma consulta; devuelve solo el parcial `articulos/_resultados.blade.php`. La
    paginación se fija con `->withPath(route('articulos.index'))`, como en 005, para que sus enlaces
    apunten a la página completa.
  - `create` / `edit`: vista del formulario con la lista de proveedores del usuario (activos,
    ordenados por nombre comercial, **sin paginar**) y las opciones de `ObjetoImpuesto`.
  - `store` / `update`: guardan los datos validados y redirigen al listado con mensaje flash.
  - `destroy`: soft delete simple y redirección con mensaje de éxito. Como en 005, la futura
    restricción por líneas de factura queda anotada en el PHPDoc.
- **`ImportacionArticulosController`**:
  - `create`: pantalla de importación; si hay un reporte en la sesión flash, lo muestra.
  - `store`: valida el formulario, delega el archivo al servicio de importación y redirige a
    `articulos.importar` con el reporte en la sesión flash (patrón POST → redirección → GET: recargar
    la página no vuelve a importar el archivo).
- **`ExportacionArticulosController`** (invocable): `response()->streamDownload()` con `fputcsv`,
  recorriendo los artículos con `lazy()` para no cargarlos todos en memoria. Nombre del archivo:
  `articulos-AAAA-MM-DD.csv`.
  - Acepta los mismos parámetros del listado (`nombre`, `modelo`, `orden`, `direccion`) y exporta
    **todas** las filas que coinciden, en el orden de la tabla, sin paginar: lo que el usuario está
    viendo, completo.
- **`CatalogoSatController`**: las dos búsquedas del apartado anterior; con `q` vacío responde
  `[]`.

### Importación CSV (`App\Services\Articulos\ImportadorArticulosCsv`)

La lógica vive en un servicio, no en el controlador, para probarla sin peticiones HTTP.

- **Columnas** (idénticas en importación y exportación, sin columna de proveedor):
  `nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva`.
- **Encabezado**: la primera fila es el encabezado y las columnas se identifican por nombre. Si
  falta alguna, se rechaza el archivo completo con un mensaje que nombra las columnas faltantes (no
  tiene sentido reportar cada fila por un defecto del encabezado).
- **Proceso fila por fila**: las válidas se insertan, las inválidas se reportan y el archivo no se
  aborta. No se envuelve el archivo en una transacción, justamente para conservar las filas
  válidas. Las filas completamente vacías se ignoran.
- **Número de fila**: el de la hoja de cálculo (el encabezado es la fila 1, el primer artículo la
  2), para que el usuario lo encuentre sin contar.
- **Reporte**: `{ importados: int, errores: [{ fila: int, modelo: string, motivo: string }] }`.
- **Codificación**: acepta **UTF-8 (con o sin BOM) y Windows-1252** y transcodifica a UTF-8 antes de
  parsear. La detección es por contenido (`mb_check_encoding` sobre el archivo completo, una sola
  vez), no por el nombre ni por un encabezado declarado. El BOM se retira antes de leer el
  encabezado.

  No es una comodidad: "CSV (delimitado por comas)" de Excel en español guarda en Windows-1252,
  donde la `Ø` de `Sello redondo de Ø X 45 mm` es el byte `0xD8`, que no es UTF-8 válido; sin
  convertirlo, ese texto rompe todo lo que viene después (la sesión, la vista del reporte). Y si
  el BOM de "CSV UTF-8" sobrevive, la primera columna deja de llamarse `nombre` y el archivo se
  rechaza reclamando una columna que sí está.
- **Normalización antes de validar** (una hoja de cálculo reescribe celdas al guardar, y eso no es
  un error del usuario):
  - espacios alrededor de cada celda, fuera;
  - `clave_unidad` a mayúsculas;
  - `objeto_imp` **rellenado con cero a la izquierda** cuando trae un solo dígito: Excel y Google
    Sheets leen `02` como número y lo guardan como `2`. Sin esto, un CSV exportado por el propio
    sistema deja de ser importable con solo abrirlo y guardarlo.

  La normalización **no relaja la validación**: un valor que tras normalizarse sigue sin
  corresponder rechaza su fila.
- **Validación por fila**: exactamente las mismas reglas que el alta individual, con el
  `proveedor_id` tomado del formulario. Las reglas se definen **una sola vez** (un método estático
  de `ArticuloRequest` que reciben el Form Request y el importador), para que alta e importación no
  se desincronicen. Como la unicidad se consulta contra la base real, dos filas del mismo archivo
  con el mismo nombre se detectan: la primera entra y la segunda ya la ve duplicada.
- **El motivo nombra la columna y el valor recibido** en las columnas de lista cerrada, no solo la
  regla: `objeto_imp "9" no es un valor válido (01, 02, 03, 04)`. Un archivo con 36 filas y el
  mismo defecto produce 36 motivos, y sin el valor el usuario no sabe qué corregir.

### Exportación CSV

- Mismas 6 columnas y mismo orden que la importación; el archivo exportado, editado, se reimporta
  tal cual para un proveedor.
- Se escribe en **UTF-8 con BOM**, para que Excel en español muestre acentos y `Ø` al abrirlo con
  doble clic (el importador ya acepta ese BOM).
- `objeto_imp` sale como `02`, `precio_unitario_sin_iva` con punto decimal y dos decimales.

### Autorización (`ArticuloPolicy`)

- Igual que 004/005: `update` y `delete` solo para el dueño; a los demás
  `Response::denyAsNotFound()` (404). El administrador no tiene excepción.
- `ArticuloRequest::authorize()` usa `Gate::inspect('update', $articulo)` en edición (lección de
  004: el Form Request valida antes que el controlador).
- El proveedor elegido, tanto en el formulario como en la importación, debe ser del usuario: se
  valida con `Rule::exists('proveedores','id')->where('user_id', …)->withoutTrashed()`, así que un
  proveedor ajeno o eliminado es un error de validación, no un 404.

### Validaciones (`ArticuloRequest` y `ImportarArticulosRequest`)

- `ArticuloRequest::prepareForValidation()`: `clave_unidad` a mayúsculas y sin espacios.
- Reglas del artículo:
  - `proveedor_id`: requerido, existe, es del usuario y no está eliminado.
  - `nombre`: requerido, string, max 255,
    `Rule::unique('articulos','nombre')->where('proveedor_id', …)->withoutTrashed()`, ignorando el
    artículo actual en edición. Proveedores distintos sí pueden repetir nombre.
  - `modelo`: requerido, string, max 255.
  - `clave_prod_serv`: requerido, `exists:sat_claves_prod_serv,clave`.
  - `clave_unidad`: requerido, `exists:sat_claves_unidad,clave`.
  - `objeto_imp`: requerido, `Rule::enum(ObjetoImpuesto::class)`.
  - `precio_unitario_sin_iva`: requerido, numérico, `gt:0`, `decimal:0,2`, máximo
    `99999999.99` (lo que cabe en `decimal(10,2)`).
- `messages()`: "Nombre duplicado: este proveedor ya tiene un artículo con ese nombre.", "La clave
  de producto/servicio no existe en el catálogo del SAT.", "La clave de unidad no existe en el
  catálogo del SAT.".
- `attributes()`: nombres de los campos en español.
- `ImportarArticulosRequest`: `proveedor_id` (mismas reglas), `archivo` requerido,
  `mimes:csv,txt`, máximo 2 MB.

## Vistas (Blade)

Todas extienden `layouts.app` y usan **solo** los componentes de 003 (lo verifica
`EstiloUniformeTest`).

- **`articulos/index.blade.php`** (`/articulos`):
  - Botones "Nuevo artículo" (`bi-plus-lg`), "Importar CSV" (`bi-upload`, enlace a
    `articulos.importar`) y "Exportar CSV" (`bi-download`, enlace a `articulos.exportar` con los
    filtros y el orden actuales). El botón de exportar vive dentro de `<span
    id="articulos-exportar">`, que forma parte de la respuesta de `buscar`: su enlace se actualiza
    solo en cada búsqueda, sin JavaScript propio.
  - **Formulario de filtros** `<form id="filtros-articulos" method="GET" action="{{
    route('articulos.index') }}" data-busqueda-dinamica="{{ route('articulos.buscar') }}">`, igual
    que en 005: botón "Limpiar" (`bi-x-lg`) y "Buscar" solo dentro de `<noscript>`. Dentro del
    formulario, un `<div id="articulos-orden">` con dos `<input type="hidden">` (`orden` y
    `direccion`), que también forma parte de la respuesta de `buscar`, así el orden elegido se
    conserva al seguir escribiendo en los filtros.
  - **Filas por página**: `<x-campo tipo="select" nombre="por_pagina" form="filtros-articulos">`
    con 10, 25, 50 y 100, junto a la paginación. Cambiarlo dispara la búsqueda como cualquier
    campo del formulario.
  - Tabla `tabla` dentro de `<x-card>` con `data-busqueda-tabla`: nombre, modelo, proveedor,
    precio con IVA y acciones ("Editar" `bi-pencil`, "Eliminar" `bi-trash` con
    `data-confirmar="¿Eliminar este artículo?"`). El precio sin IVA solo se ve en el formulario.
  - **Títulos ordenables**: la primera fila del `<thead>` (`<tr id="articulos-titulos">`) tiene en
    Nombre, Modelo, Proveedor y Precio un enlace con `data-busqueda-enlace` a
    `articulos.index` con los filtros actuales, esa columna y la dirección invertida si ya era la
    activa (página 1). La columna activa muestra `bi-arrow-up` o `bi-arrow-down` y lleva
    `aria-sort`; las demás, `bi-arrow-down-up`. Acciones no se ordena. Esta fila también forma
    parte de la respuesta de `buscar`, para que las flechas y los enlaces reflejen siempre el
    estado actual.
  - **Cajas de búsqueda por columna**: la segunda fila del `<thead>` (`<tr class="tabla-filtros">`)
    tiene `<x-campo tipo="search" form="filtros-articulos">` **solo bajo Nombre y bajo Modelo**
    (las demás celdas vacías), igual que 005. Esta fila **no** se reemplaza al buscar, así la caja
    no pierde el foco ni lo escrito. La etiqueta ("Buscar por nombre", "Buscar por modelo") se
    conserva para lectores de pantalla y se oculta con la regla `.tabla-filtros .campo label` que
    ya existe.
  - No hay buscador global: las dos cajas son la única búsqueda del listado.
  - Un `<x-alerta tipo="error" hidden data-busqueda-error>` pre-renderizado, como en 005.
  - **Celdas de texto largo**: nombre, modelo y proveedor se truncan con elipsis a un ancho máximo y
    muestran el texto completo en el atributo `title`. Se resuelve con una clase CSS reutilizable
    en `app.css` (`.celda-truncada`, con el ancho desde una variable de `:root`), no celda por
    celda, para que el botón "Eliminar" nunca quede fuera de la vista por un nombre largo. Es la
    lección de la spec remota (el `nombre_comercial` largo empujaba las acciones fuera de la
    tabla), trasladada de un componente Vue a CSS.
  - Precios con formato `$1,234.56`.
  - Mensaje de lista vacía o de "ningún artículo coincide", parcial de mensajes flash.
- **Parciales**, con el mismo reparto que 005 (el HTML de las filas existe en un solo lugar):
  `articulos/_titulos.blade.php` (`<tr id="articulos-titulos">`), `articulos/_filas.blade.php`
  (`<tbody id="articulos-filas" aria-live="polite">`), `articulos/_paginacion.blade.php` (`<div
  id="articulos-paginacion">` con `<x-paginacion>`), `articulos/_orden.blade.php` y
  `articulos/_exportar.blade.php`. `_resultados.blade.php` (respuesta de `buscar`) los reúne,
  envolviendo `<tr>` y `<tbody>` en un `<table>` porque el navegador descarta esas etiquetas sueltas
  dentro de un `<template>` (lección de 005).
- **`articulos/crear.blade.php`** y **`articulos/editar.blade.php`**: comparten
  `articulos/_formulario.blade.php`, con un `<x-card>`:
  - Proveedor: `<x-campo tipo="select">` con los proveedores del usuario (obligatorio). Si el
    usuario no tiene proveedores, en lugar del formulario se muestra un `<x-alerta
    tipo="advertencia">` con un enlace a "Nuevo proveedor".
  - Nombre y modelo: texto (obligatorios).
  - Clave de producto/servicio y clave de unidad: `<x-campo>` de texto con
    `data-autocompletar="{{ route(…) }}"`. Debajo, el texto de ayuda muestra la descripción de la
    clave actual (en edición o tras un error de validación la pinta el servidor; al elegir una
    sugerencia la actualiza el JavaScript).
  - Objeto de impuesto: `<x-campo tipo="select">` con las 4 opciones del enum
    ("02 – Sí objeto de impuesto").
  - Precio unitario sin IVA: `<x-campo tipo="number" step="0.01" min="0.01">`; a su lado, solo de
    lectura, el precio con IVA (`data-tasa-iva` con el valor de `Articulo::TASA_IVA`).
  - Botones "Guardar" (`bi-save`) y "Cancelar".
- **`articulos/importar.blade.php`** (`/articulos/importar`): pantalla propia en lugar del modal de
  la spec remota, así no necesita JavaScript y no hereda los problemas de desborde del `Dialog`.
  - Explicación breve y el listado de columnas en un bloque de código aparte con desplazamiento
    horizontal propio (no dentro del párrafo).
  - Formulario `multipart/form-data`: proveedor destino (`<x-campo tipo="select">`), archivo
    (`<x-campo tipo="file" accept=".csv,text/csv">`), botón "Importar" (`bi-upload`).
  - Si hay reporte: `<x-alerta tipo="exito">` con "N artículos importados" y, si hubo rechazos,
    `<x-alerta tipo="advertencia">` con una tabla fila / modelo / motivo.
  - Enlace de vuelta al listado.
- **Errores de validación** y **mensajes flash**: igual que 004/005.
- **Menú**: enlace "Artículos" (`bi-box-seam`) en `layouts/app.blade.php`, después de
  "Proveedores".
- Se agregan a `/estilos` los iconos nuevos, el campo con sugerencias y la celda truncada.

## JavaScript

### Búsqueda dinámica del listado (`public/js/busqueda-dinamica.js`, ya existe)

Se reutiliza el script de 005, cargado en `articulos/index` con `@push('scripts')`. Ya resuelve,
sin cambios: debounce de 300 ms, cancelación de la petición anterior (`AbortController`),
reemplazo por `id` de lo que devuelve el servidor, URL actualizada con `history.replaceState`,
enlaces de paginación por AJAX, `aria-busy`, alerta de error y recarga ante 401/419 o redirección
al login. El selector de filas por página funciona sin cambios porque es un campo más del
formulario (un `<select>` dispara el mismo evento `input` al cambiar).

**Única extensión**, genérica y retrocompatible con Clientes: además de `.paginacion a`, el script
intercepta los clics en cualquier enlace con `data-busqueda-enlace` y los carga por AJAX con la
query string del enlace (mismo trato que la paginación: Ctrl/Cmd/Shift + clic abre normal). Así
funcionan los títulos ordenables; como la respuesta trae de nuevo la fila de títulos y los campos
ocultos de orden, el siguiente filtro escrito conserva el orden elegido.

**Sin JavaScript** el listado funciona completo con recargas normales: el formulario hace `GET` con
el botón de `<noscript>`, y los títulos y la paginación son enlaces normales.

### Campo con sugerencias (`public/js/autocompletar.js`)

Archivo nuevo, cargado solo en el formulario de artículos con `@push('scripts')`. Es genérico: se
activa en cualquier campo con `data-autocompletar="<url>"`, para que facturación lo reutilice.

- Al escribir, espera **300 ms** sin nuevas pulsaciones (igual que la búsqueda de 005) y hace
  `axios.get(url, { params: { q } })`; cancela la petición anterior con `AbortController`.
- Muestra hasta 20 sugerencias "clave – descripción" en una lista bajo el campo (`role="listbox"`,
  navegable con flechas, Enter y Escape). Al elegir una, escribe la clave en el campo y la
  descripción en el texto de ayuda.
- 401/419 recarga la página (sesión vencida); cualquier otro error simplemente no muestra
  sugerencias.
- **Sin JavaScript** el usuario escribe la clave a mano y el servidor la valida contra el
  catálogo.

### Precio con IVA en vivo (`public/js/precio-con-iva.js`)

- Recalcula el precio con IVA al escribir el precio sin IVA, con la tasa de `data-tasa-iva`. Es
  solo informativo: el precio que cuenta lo calcula el servidor.
- Sin JavaScript el campo muestra el precio con IVA guardado (en edición) o queda vacío.

### Confirmación de eliminación

Se reutiliza `data-confirmar` de `public/js/app.js`. No se agrega código.

## Pruebas (Pest)

`tests/Feature/ArticuloTest.php`, `tests/Feature/ImportacionArticulosTest.php`,
`tests/Feature/CatalogoSatTest.php`:

- Invitado redirigido al login en todas las rutas; usuario suspendido no entra.
- Crear, listar, editar y eliminar (soft delete, `assertSoftDeleted`).
- Aislamiento: el listado y la exportación no incluyen artículos ajenos; editar, actualizar o
  eliminar uno ajeno responde 404 (también con datos inválidos); elegir un proveedor ajeno o
  eliminado es error de validación, en el formulario y en la importación.
- Validaciones de los criterios 2 a 5; `clave_unidad` en minúsculas se guarda en mayúsculas.
- `precio_unitario_con_iva` = sin IVA × 1.16 redondeado a 2 decimales.
- Listado: filtro por nombre, por modelo y ambos combinados (sin distinguir mayúsculas); orden
  por cada una de las 4 columnas en ambas direcciones (el precio por valor, el proveedor por
  nombre comercial); `orden`/`direccion`/`por_pagina` inválidos caen al valor por defecto sin
  error; `por_pagina` 10/25/50/100; la paginación conserva filtros y orden.
- `/articulos/buscar` devuelve el fragmento (sin `<html>` ni la fila de cajas de búsqueda), con
  la fila de títulos, las filas, la paginación con enlaces a `/articulos`, los campos ocultos del
  orden y el enlace de exportar con los filtros actuales; con cabeceras AJAX y sin sesión responde
  401.
- Exportación con los mismos filtros y el mismo orden del listado, sin paginar.
- Un artículo cuyo proveedor se eliminó sigue apareciendo con el nombre del proveedor.
- Catálogos SAT: búsqueda por prefijo de clave y por descripción, límite de 20, `q` vacío → `[]`.
  El comando `catalogos-sat:actualizar` se prueba con la descarga simulada (`Http::fake`),
  incluido que un fallo deja los datos anteriores.
- Importación: archivo 100% válido; parcialmente válido (fila, modelo y motivo); encabezado con
  columnas faltantes; nombre repetido dentro del mismo archivo; `objeto_imp` `2` → `02`;
  `objeto_imp` `9` rechazado con motivo que nombra columna y valor; archivo Windows-1252 con `Ø`
  intacto; archivo UTF-8 con BOM; recargar tras importar no vuelve a importar.
- Exportación: columnas y orden exactos, y el archivo exportado se reimporta sin errores en otro
  proveedor.
- La extensión de `busqueda-dinamica.js` pasa `node --check`; `ClienteTest` sigue pasando. El
  comportamiento en el navegador (filtrar sin recargar, sin perder el foco, orden por AJAX) se
  verifica a mano (criterios 6 a 8).
- `user_id` no se puede asignar desde el formulario.
- `EstiloUniformeTest`, `ProveedorTest` y `ClienteTest` siguen pasando.

## Fuera de alcance

- Emisión/timbrado de CFDI.
- Catálogos por proveedor con descuento ([remotas/009](remotas/009-catalogos.md)), costo y utilidad
  ([remotas/011](remotas/011-precio-proveedor-utilidad.md)), costo de goma
  ([remotas/014](remotas/014-costo-elaboracion-goma.md)), imágenes
  ([remotas/020](remotas/020-imagenes-articulos.md)), mantenimiento masivo
  ([remotas/021](remotas/021-mantenimiento-articulos-catalogos.md)), redondeo al peso
  ([remotas/024](remotas/024-precios-sin-centavos.md)), filtros por columna y orden de captura
  ([remotas/025](remotas/025-filtros-columna-listado-articulos.md)) y precio distribuidor
  ([remotas/033](remotas/033-precio-distribuidor.md)): cada una tendrá su propia spec local.
- Inventario/existencias.
- Código interno / SKU propio.
- Tasas de IVA distintas al 16%; el precio con IVA es siempre informativo.
- Bloqueo de eliminación por relaciones futuras (líneas de factura, órdenes de compra).
- Validación de claves SAT contra el webservice del SAT; actualización automática de catálogos.
- Historial de cambios de precio.
- Edición o eliminación masiva vía CSV (la importación solo da de alta).
- Librerías de tablas (DataTables, jQuery u otras).
- Búsqueda por proveedor y buscador global en el listado.
- Volver al listado con los filtros aplicados tras guardar un artículo (la redirección va a
  `/articulos` limpio; el botón "atrás" del navegador sí los conserva porque viven en la URL).
- Orden por columna en los listados de Clientes o Proveedores (la extensión del script queda
  disponible para ellos).
- Roles/permisos diferenciados y multiempresa.

## Estado de implementación

Implementada el 2026-09-26.

- **Parámetros del listado en un Form Request**: en lugar de un método privado del controlador, los
  lee y sanea `ListadoArticulosRequest` (`filtros()`, `orden()`, `direccion()`, `porPagina()` y
  `parametros()` para armar enlaces), porque también los necesita `ExportacionArticulosController`.
  No valida nada: un valor fuera de la lista blanca cae al valor por defecto.
- **Select sin opción vacía**: `<x-campo tipo="select">` acepta `:vacia="false"` para omitir la
  opción vacía (lo usa "Filas por página"). No sirve `:vacia="null"`: `@props` sustituye un `null`
  por el valor por defecto.
- **Catálogos SAT reales**: `catalogos-sat:actualizar` cargó en MySQL 52,513 claves de
  producto/servicio y 2,418 de unidad en unos 3 segundos; ninguna tiene vigencia vencida hoy. El
  volcado de phpCfdi se parte **solo** en `\n`/`\r\n`: con `preg_split('/\R/')` (sin el modificador
  `u`) se perdían 3 unidades, porque `\R` también corta en el byte `0x85`, que forma parte de letras
  UTF-8 como la `Å` de «Ångström». La prueba del comando incluye ese caso.
- **`/estilos`**: muestra los iconos nuevos y la celda truncada; el campo con sugerencias solo se
  describe, porque la página no requiere sesión y el script recargaría la página al recibir el 401
  de la búsqueda.
- **Pruebas**: `cabecerasAjax()` pasó de `ClienteTest` a `tests/Pest.php`, junto con
  `sembrarCatalogosSat()`, para compartirlas entre archivos (PHP no permite declarar dos veces la
  misma función global).
- **Verificación**: la suite Pest pasa (287 tests; 82 del módulo en `ArticuloTest`,
  `ImportacionArticulosTest` y `CatalogoSatTest`), Pint no reporta cambios, `node --check` valida los
  tres scripts, las migraciones corrieron en MySQL y las consultas del listado (filtros y los cuatro
  órdenes, incluida la subconsulta por proveedor) se ejecutaron contra MySQL. **No se revisó la UI en
  un navegador real**: conviene abrir `/articulos` y confirmar que filtrar y ordenar actualizan la
  tabla sin recargar ni perder el foco, que las sugerencias de claves SAT aparecen y se eligen con
  teclado y mouse, que el precio con IVA cambia al escribir, y que un nombre largo se trunca sin
  empujar el botón "Eliminar".

## Criterios de aceptación

1. Un usuario autenticado puede crear un artículo capturando proveedor, nombre, modelo, clave de
   producto/servicio, clave de unidad, objeto de impuesto y precio unitario sin IVA (todos
   obligatorios).
2. Omitir cualquier campo obligatorio muestra un error y no permite guardar.
3. Una clave de producto/servicio o de unidad que no exista en el catálogo SAT muestra un error.
4. Un precio sin IVA menor o igual a 0, o con más de 2 decimales, muestra un error.
5. Un nombre ya usado por otro artículo **del mismo proveedor** muestra "nombre duplicado"; el
   mismo nombre se acepta en otro proveedor o tras eliminar el artículo que lo tenía.
6. `/articulos` muestra solo los artículos del usuario, 25 por página (con opción de 10, 50 o 100),
   con nombre, modelo, proveedor y precio con IVA.
7. Bajo los títulos Nombre y Modelo hay una caja de búsqueda cada una; al escribir, las filas se
   actualizan **sin recargar la página** y sin perder el foco (coincidencia parcial, sin distinguir
   mayúsculas), y las dos se combinan. No hay otra caja de búsqueda.
8. Al hacer clic en el título de Nombre, Modelo, Proveedor o Precio la tabla se ordena por esa
   columna sin recargar; un segundo clic invierte la dirección y una flecha indica el orden
   activo. El precio se ordena por valor. Filtros, orden, página y filas por página quedan en la
   URL: recargar o compartir el enlace muestra lo mismo, y sin JavaScript todo funciona con
   recargas normales.
9. Al escribir en la clave de producto/servicio o de unidad aparecen sugerencias del catálogo SAT;
   al elegir una se ve su descripción. Sin JavaScript, la clave escrita a mano se valida igual.
10. El formulario muestra el precio con IVA mientras se escribe el precio sin IVA.
11. Editar un artículo permite modificar cualquier campo y persiste los cambios.
12. Eliminar pide confirmación y hace soft delete.
13. Editar, actualizar o eliminar un artículo ajeno responde 404.
14. Importar un CSV válido para un proveedor da de alta todos sus artículos en ese proveedor.
15. Un CSV con filas inválidas importa las válidas y reporta fila, modelo y motivo de cada
    rechazada, sin abortar el archivo.
16. Exportar genera un CSV con las columnas
    `nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva`, que respeta las
    cajas de búsqueda de Nombre y Modelo y se reimporta directamente para un proveedor.
17. Un CSV abierto y guardado en una hoja de cálculo sigue siendo importable: `objeto_imp` `2` se
    importa como `02`; un valor que no corresponde a ninguna clave rechaza su fila con un motivo
    que nombra la columna y el valor.
18. Un CSV guardado en Windows-1252 conserva acentos y símbolos (`Sello redondo de Ø X 45 mm`); un
    CSV UTF-8 con BOM se importa sin rechazar la primera columna.
19. En el listado, un texto largo (más de 40 caracteres) se ve truncado con elipsis y completo al
    pasar el mouse, y el botón "Eliminar" sigue visible sin desplazamiento horizontal en pantallas
    de escritorio (≥1280px).
20. `php artisan catalogos-sat:actualizar` llena las tablas de claves SAT y reporta cuántas cargó.
21. El menú muestra "Artículos" con su icono.
22. Pint corre sin cambios y la suite Pest pasa, incluida `EstiloUniformeTest`.

## Supuestos asumidos (registro completo)

1. "Artículo" es una entidad propia del usuario (no compartida ni multiempresa).
2. Un artículo pertenece a **exactamente un** proveedor, elegido de los existentes del usuario; no
   se crea un proveedor desde el formulario de artículo.
3. Campos obligatorios: proveedor, nombre, modelo, clave de producto/servicio, clave de unidad,
   objeto de impuesto y precio unitario sin IVA. `nombre` y `modelo` son texto libre.
4. "Clave SAT" son tres datos: `c_ClaveProdServ` y `c_ClaveUnidad` (validadas contra el catálogo
   local) y `c_ObjetoImp` (enum de 4 valores fijos).
5. Precio en MXN, mayor a 0, 2 decimales; IVA siempre 16%, calculado y nunca almacenado.
6. `nombre` es único **por proveedor**, reutilizable tras un soft delete.
7. Eliminar es soft delete simple, sin restricciones de negocio en esta historia.
8. **(Cambio de arquitectura)** Los catálogos SAT viven en tablas MySQL del sistema, llenadas por
   un comando, en lugar de una base SQLite aparte: una sola base que respaldar y desplegar, y la
   validación es un `exists` normal.
9. **(Cambio de arquitectura)** Solo se cargan las claves vigentes del catálogo.
10. **(Cambio de arquitectura)** La importación es una pantalla propia (`/articulos/importar`) en
    vez de un modal; el reporte se muestra en esa pantalla tras la redirección.
11. **(Cambio de arquitectura)** El proveedor de la importación va en el formulario, no en la URL.
12. **(Redefinido)** El listado tiene dos cajas de búsqueda (nombre y modelo), orden por columna y
    selector de filas por página, resueltos por Laravel y actualizados sin recargar con el
    `busqueda-dinamica.js` de 005 (JavaScript nativo, sin DataTables ni jQuery). Se quita la
    búsqueda por proveedor y el buscador global.
13. **(Cambio de arquitectura)** El selector de proveedor lista todos los proveedores del usuario
    sin paginar, así que desaparece el parámetro `per_page` que la spec remota agregó a
    `ProveedorController::index`.
14. **(Decisión nueva)** El listado arranca ordenado por nombre ascendente (la spec remota no lo
    fijaba; se sigue la convención de 004/005) y el usuario puede reordenar por cualquier columna
    salvo Acciones.
15. **(Decisión nueva)** Un artículo cuyo proveedor fue eliminado sigue en el listado mostrando el
    nombre de ese proveedor; al editarlo hay que elegir un proveedor activo. Eliminar un proveedor
    no se bloquea por tener artículos.
16. **(Decisión nueva)** La importación exige el encabezado con las 6 columnas (por nombre); si
    falta alguna se rechaza el archivo completo. Archivo máximo de 2 MB.
17. **(Decisión nueva)** El número de fila del reporte es el de la hoja de cálculo (encabezado = 1).
18. **(Decisión nueva)** La exportación se escribe en UTF-8 con BOM.
19. La importación solo da de alta; no edita ni elimina artículos existentes.
20. Iconos: `bi-box-seam` (menú), `bi-plus-lg`, `bi-upload`, `bi-download`, `bi-x-lg`, `bi-arrow-up`, `bi-arrow-down`,
    `bi-arrow-down-up`,
    `bi-pencil`, `bi-trash`, `bi-save`.
