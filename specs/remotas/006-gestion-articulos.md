# Spec: Gestión de artículos (catálogo fiscal SAT y precio unitario)

## Historia de usuario

Como usuario único del sistema de facturación, quiero administrar (crear, ver, editar y eliminar)
mis artículos de acuerdo con el sistema fiscal mexicano (clave de producto/servicio, clave de
unidad y objeto de impuesto del SAT) y con sus especificaciones comerciales de precio unitario,
ligando cada artículo a uno de mis proveedores, para tener un catálogo de artículos listo para
timbrar CFDI cuando exista el módulo de facturación, sin tener que volver a capturar sus datos
fiscales cada vez.

## Objetivo / Alcance

Implementar un módulo CRUD de artículos sobre la base ya existente de Laravel API + Vue 3 SPA +
Sanctum (ver [001-inicio-proyecto.md](001-inicio-proyecto.md),
[002-login-auth.md](002-login-auth.md)), el design system de
[003-design-system-tailwind.md](003-design-system-tailwind.md) y siguiendo el mismo patrón de
implementación que [004-gestion-clientes.md](004-gestion-clientes.md) (catálogos SAT oficiales,
combobox con búsqueda) y [005-gestion-proveedores.md](005-gestion-proveedores.md) (CRUD comercial
simple). Cada artículo pertenece a un `Proveedor` ya existente ([005](005-gestion-proveedores.md)).
Incluye importación y exportación de artículos vía CSV. **No** incluye la emisión/timbrado de CFDI
en sí, ni inventario/existencias.

## Backend (Laravel)

- **Modelo `Articulo`**, perteneciente a un `User` (`user_id`) y a un `Proveedor` (`proveedor_id`,
  obligatorio), con **soft deletes** habilitado (`SoftDeletes` trait). Un `Proveedor` tiene muchos
  `Articulo` (`Proveedor::articulos(): HasMany`).
- **Campos**:
  - `nombre`: string, **obligatorio**.
  - `modelo`: string, **obligatorio**.
  - `clave_prod_serv`: string, **obligatoria**. Clave de producto/servicio del catálogo oficial SAT
    `c_ClaveProdServ` (8 dígitos).
  - `clave_unidad`: string, **obligatoria**. Clave de unidad de medida del catálogo oficial SAT
    `c_ClaveUnidad` (ej. `H87`, `PZA`).
  - `objeto_imp`: string (2 caracteres), **obligatorio**. Objeto de impuesto, valor fijo de un
    `enum` de backend (catálogo `c_ObjetoImp`, no se consulta contra una tabla externa):
    - `01`: No objeto de impuesto.
    - `02`: Sí objeto de impuesto.
    - `03`: Sí objeto del impuesto y no obligado al desglose.
    - `04`: Sí objeto del impuesto y no causa impuesto.
  - `precio_unitario_sin_iva`: decimal(10,2), **obligatorio**, mayor a 0, en pesos mexicanos (MXN).
    No se persiste el precio con IVA; se expone como atributo calculado de solo lectura en el
    Resource (`precio_unitario_con_iva = precio_unitario_sin_iva * 1.16`), asumiendo siempre la
    tasa general del 16% (no se contemplan tasa 0%, exento ni IVA fronterizo en esta historia).
- **Catálogos SAT `c_ClaveProdServ` y `c_ClaveUnidad`**: se amplía la base SQLite reducida de
  catálogos SAT creada en [004](004-gestion-clientes.md) (`storage/app/sat-catalogos.sqlite`) con
  estas dos tablas nuevas, y se amplía el comando `catalogos-sat:actualizar`
  (`app/Console/Commands/ActualizarCatalogosSat.php`) para regenerarlas junto con
  `c_RegimenFiscal`/`c_CodigoPostal`.
  - `GET /api/v1/catalogos/claves-prod-serv?q=...` — búsqueda por texto (catálogo muy extenso para
    cargarse completo).
  - `GET /api/v1/catalogos/claves-unidad?q=...` — búsqueda por texto.
  - `objeto_imp` **no** requiere endpoint de catálogo: sus 4 opciones se sirven embebidas en el
    frontend (o en un endpoint estático simple si se prefiere no duplicar los textos), ya que el
    SAT rara vez actualiza este catálogo.
- **Unicidad de `nombre`**: único por proveedor (constraint a nivel de aplicación,
  `Rule::unique('articulos','nombre')->where('proveedor_id', ...)->whereNull('deleted_at')`, mismo
  patrón que el RFC en Cliente/Proveedor, para permitir reutilizar el nombre tras un soft delete).
  Proveedores distintos sí pueden tener artículos con el mismo nombre.
- **Endpoints** (bajo `auth:sanctum`, scopeados al usuario autenticado):
  - `GET /api/v1/articulos` — listado paginado, con `?search=` (por `nombre`, `modelo` o nombre
    comercial del proveedor asociado).
  - `POST /api/v1/articulos` — alta.
  - `GET /api/v1/articulos/{id}` — detalle.
  - `PUT /api/v1/articulos/{id}` — edición.
  - `DELETE /api/v1/articulos/{id}` — borrado lógico (soft delete) simple, sin restricciones
    adicionales (no se bloquea por relaciones futuras como líneas de factura; se difiere a la
    historia de facturación, igual que en Cliente).
  - `POST /api/v1/proveedores/{proveedor}/articulos/importar-csv` — importa artículos desde un
    archivo CSV, todos asociados al `{proveedor}` de la ruta (el CSV no lleva columna de
    proveedor). Procesa fila por fila: las filas válidas se importan y las inválidas se reportan
    sin abortar el archivo completo. Responde con un reporte
    `{ "importados": <int>, "errores": [{ "fila": <int>, "motivo": <string> }, ...] }`.
  - `GET /api/v1/articulos/exportar-csv` — exporta a CSV el listado de artículos resultante del
    `?search=` aplicado (o todos si no hay filtro), con las mismas columnas que espera la
    importación.
  - Columnas CSV (idénticas en importación y exportación, sin columna de proveedor):
    `nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva`.
- **Validaciones** (Form Requests):
  - `proveedor_id`: requerido, debe existir y pertenecer al usuario autenticado.
  - `nombre`: requerido, string, único por proveedor.
  - `modelo`: requerido, string.
  - `clave_prod_serv`: requerido, debe existir en el catálogo `c_ClaveProdServ`.
  - `clave_unidad`: requerido, debe existir en el catálogo `c_ClaveUnidad`.
  - `objeto_imp`: requerido, uno de `01`, `02`, `03`, `04`.
  - `precio_unitario_sin_iva`: requerido, numérico, mayor a 0, máximo 2 decimales.
  - Fila de importación CSV: mismas reglas que el alta individual, aplicadas por fila; el
    `proveedor_id` se toma de la ruta, no de la fila.
- **Codificación del archivo CSV**: el importador acepta **UTF-8 (con o sin BOM) y Windows-1252**,
  y transcodifica a UTF-8 antes de parsear. Las tres son las que produce una hoja de cálculo según
  la opción de guardado que elija el usuario, y ninguna es una elección informada: "CSV (delimitado
  por comas)" de Excel en español escribe en la codificación ANSI del sistema, y "CSV UTF-8"
  antepone un BOM. El archivo se detecta por su contenido, no por su nombre ni por un encabezado
  declarado.

  No es una comodidad: un artículo llamado `Sello redondo de Ø X 45 mm` guardado en Windows-1252
  llega con bytes que no son UTF-8 válido, y a partir de ahí **ninguna capa del sistema puede
  representarlo** — la respuesta JSON del reporte de importación falla al serializarse y el usuario
  recibe un error de codificación en vez del resultado de su importación. La conversión ocurre en la
  puerta de entrada, de modo que todo lo que circula por dentro del sistema ya es UTF-8 válido.

  El BOM se retira antes de leer el encabezado: si sobrevive, la primera columna deja de llamarse
  `nombre` y el archivo entero se rechaza fila por fila reclamando un campo que sí está presente.
- **Normalización de las celdas del CSV antes de validar**: una hoja de cálculo reescribe las celdas
  al guardar el archivo, y esas reescrituras no son errores del usuario. Cada columna de valor
  cerrado se lleva a su forma canónica antes de aplicar la regla:
  - `clave_unidad` a mayúsculas.
  - `objeto_imp` **rellenado con cero a la izquierda** cuando la celda trae un solo dígito: Excel y
    Google Sheets tratan `02` como número y lo escriben de vuelta como `2`, y esta es la única
    columna del archivo con cero inicial. Sin esta normalización, un CSV exportado por el propio
    sistema deja de ser importable con solo abrirlo y guardarlo, que es justo lo que el usuario hace
    para editarlo.
  - `tamano_goma` a minúsculas y sin espacios alrededor (ver
    [014](014-costo-elaboracion-goma.md)).

  La normalización **no relaja la validación**: un valor que sigue sin corresponder a la lista
  cerrada tras normalizarse rechaza su fila como cualquier otro dato inválido.
- **El motivo de una fila rechazada nombra la columna y el valor recibido**, no solo la regla que
  falló: `objeto_imp "9" no es un valor válido (01, 02, 03, 04)`. Un archivo de decenas de filas con
  el mismo defecto produce decenas de motivos idénticos, y sin el valor concreto el usuario no tiene
  cómo saber qué corregir en su hoja. Aplica a las columnas de lista cerrada (`objeto_imp`,
  `tamano_goma`), donde el mensaje genérico del framework no dice nada útil.
- Respuestas mediante Laravel API Resources (`ArticuloResource`), consistente con la convención de
  001/004/005.

## Frontend (Vue 3)

- **`/articulos`** (protegida): listado paginado de artículos en tabla, con buscador (nombre,
  modelo o proveedor). Columnas visibles: nombre, modelo, proveedor y precio con IVA (el precio
  sin IVA solo se ve/edita en el formulario de alta/edición, no en esta tabla).
- **Corrección de desborde en la tabla de listado** (agregada el 2026-08-03, tras detectarse en
  verificación visual real que un proveedor con `nombre_comercial` largo empujaba el botón
  "Eliminar" fuera del área visible de la tabla, sin que el usuario notara que había que hacer
  scroll horizontal para encontrarlo): la celda de proveedor trunca el texto con elipsis a un
  ancho máximo fijo y expone el nombre completo mediante el atributo `title` (tooltip nativo del
  navegador, sin componente nuevo). El truncado se implementa como una prop reutilizable del
  primitivo compartido `components/ui/table/TableCell.vue` (no repetido celda por celda en cada
  vista), para quedar disponible en el resto de tablas de listado de la app (Proveedores,
  Clientes, Catálogos, Facturas, Cotizaciones) — mismo espíritu que la regla general de `Dialog`
  con contenido dinámico ya documentada en
  [003-design-system-tailwind.md](003-design-system-tailwind.md). Esa prop quedó formalizada como
  regla general de `Table` en 003 el 2026-08-14, junto con el ancho de página de los listados densos,
  cuando el mismo desborde reapareció en el listado de artículos de
  [025](025-filtros-columna-listado-articulos.md) por la vía del contenedor en vez de la del
  contenido de una celda (ver detalle de la corrección original en "Estado de implementación").
- **`/articulos/crear`**: formulario de alta con:
  - Selector de proveedor: `Select` simple con los proveedores propios del usuario (obligatorio).
  - `nombre`, `modelo`: `Input` de texto (obligatorios).
  - Selector de clave de producto/servicio: **combobox con búsqueda** contra
    `GET /api/v1/catalogos/claves-prod-serv?q=...` (mismo patrón que el combobox de código postal
    en Cliente).
  - Selector de clave de unidad: **combobox con búsqueda** contra
    `GET /api/v1/catalogos/claves-unidad?q=...`.
  - Selector de objeto de impuesto: `Select` simple con las 4 opciones fijas.
  - `precio_unitario_sin_iva`: `Input` numérico (obligatorio); junto a él se muestra, solo de
    lectura, el precio con IVA calculado en vivo (`precio * 1.16`) para referencia del usuario.
- **`/articulos/:id/editar`**: mismo formulario, precargado, para edición.
- Confirmación (modal `Dialog`) antes de eliminar un artículo.
- Mensajes de error de validación por campo (ej. nombre duplicado en el proveedor, clave SAT
  inexistente, precio inválido), usando `Input`/`Alert`.
- **Importar CSV**: botón en `/articulos` que abre un modal para (1) seleccionar el proveedor
  destino y (2) subir el archivo CSV; al terminar, muestra el reporte de resultado (cuántos se
  importaron y el detalle de errores por fila, si los hay).
- **Exportar CSV**: botón en `/articulos` que descarga el listado actualmente filtrado (respetando
  `?search=`) en el mismo formato de columnas que espera la importación.
- **Layout del modal de importación**: el modal de "Importar CSV" sigue la regla general de
  `Dialog` con contenido dinámico definida en
  [003-design-system-tailwind.md](003-design-system-tailwind.md) (contenedores con `min-w-0`,
  listado de columnas en bloque aparte con `overflow-x-auto` en vez de prosa, e `<input
  type="file">` truncado dentro de un contenedor `min-w-0`), para que no se desborde ni con el
  listado de columnas del CSV ni con nombres de archivo largos.
- Enlace a `/articulos` agregado a la navegación del `AppLayout`, junto a "Clientes" y
  "Proveedores".

## Fuera de alcance

- Emisión/timbrado real de CFDI — historia futura que consumirá los datos de `Articulo`.
- Inventario/existencias (cantidad en stock, movimientos de almacén).
- Código interno / SKU propio del sistema (solo nombre, modelo y claves SAT).
- Tasas de IVA distintas a la general del 16% (0%, exento, IVA fronterizo); el precio con IVA
  mostrado es siempre un cálculo informativo, nunca almacenado.
- Bloqueo de eliminación de un artículo por relaciones futuras (ej. líneas de factura, órdenes de
  compra): se difiere a cuando existan esos módulos, igual que en Cliente/Proveedor.
- Validación de las claves SAT contra el webservice real del SAT (solo contra el catálogo local
  descargado).
- Historial de cambios de precio.
- Roles/permisos diferenciados (cualquier usuario autenticado gestiona solo sus propios
  artículos).
- Multiempresa o artículos compartidos entre usuarios/proveedores.
- Edición o eliminación masiva vía CSV (la importación solo da de alta artículos nuevos).

## Estado de implementación

Implementada el 2026-07-31.

- **Catálogos SAT ampliados**: `catalogos-sat:actualizar` ahora reconstruye 4 tablas (antes 2):
  `cfdi_40_regimenes_fiscales`, `cfdi_40_codigos_postales`, `cfdi_40_productos_servicios` (52,513
  entradas) y `cfdi_40_claves_unidades` (2,418 entradas). Se ejecutó manualmente para regenerar
  `storage/app/sat-catalogos.sqlite` (ahora ~13 MB); igual que en 004, no corre automático en
  `composer install`.
- **`ObjetoImpuesto` como enum de PHP** (`App\Enums\ObjetoImpuesto`, backed por `string`), usado
  como cast del atributo `objeto_imp` en el modelo `Articulo` (Eloquent lo serializa/deserializa
  automáticamente) y validado en los Form Requests con `Rule::enum(ObjetoImpuesto::class)`.
- **Precio con IVA no se persiste**: `Articulo::precioUnitarioConIva()` es un accessor
  (`precio_unitario_sin_iva * 1.16`, redondeado a 2 decimales) expuesto en `ArticuloResource` como
  `precio_unitario_con_iva`; la columna de base de datos solo guarda `precio_unitario_sin_iva`.
- **Unicidad de `nombre` por proveedor**: `Rule::unique('articulos','nombre')->where('proveedor_id', ...)->whereNull('deleted_at')`,
  mismo patrón que el RFC en Cliente/Proveedor. Se valida tanto en alta/edición individual como en
  cada fila de la importación CSV (reutilizando la regla contra la base de datos real, por lo que
  dos filas del mismo CSV con el mismo nombre se detectan correctamente: la primera se inserta y la
  segunda ya la ve duplicada).
- **Importación CSV**: `POST /api/v1/proveedores/{proveedor}/articulos/importar-csv` lee el archivo
  fila por fila con `fgetcsv` (sin librería externa; PHP nativo es suficiente), valida cada fila con
  las mismas reglas que el alta individual (`proveedor_id` fijo al de la ruta) y acumula
  `importados`/`errores` sin abortar el archivo completo. Cubierto por tests de importación 100%
  válida, parcialmente válida (reporta fila y motivo) y de un proveedor ajeno (404).
- **Exportación CSV**: `GET /api/v1/articulos/exportar-csv` usa `response()->streamDownload()` con
  `fputcsv`, respeta el `?search=` aplicado y genera exactamente las mismas 6 columnas que espera la
  importación (sin columna de proveedor), verificado con un test que reimporta el patrón de
  columnas.
- **`ProveedorController::index` ganó un parámetro opcional `per_page`** (`min(per_page, 100)`,
  default 15 sin cambios) para que el nuevo `ProveedorSelect.vue` del formulario de artículo pueda
  listar hasta 100 proveedores en el `<select>` sin paginar manualmente; no estaba en el spec
  original pero es un cambio mínimo y retrocompatible (los tests de Proveedores siguen en verde sin
  tocarlos).
- **Verificación end-to-end**: la suite Pest completa (22 tests nuevos del módulo Artículos, 59 en
  total) pasa contra los catálogos SAT reales. Se corrió además `php artisan serve` real y se probó
  por HTTP con un usuario y token de Sanctum de prueba (creado y eliminado al terminar) el flujo
  completo: crear proveedor, crear artículo (verificando el cálculo de `precio_unitario_con_iva`),
  listar con búsqueda, rechazo de nombre duplicado, búsqueda en los catálogos de clave de
  producto/servicio y clave de unidad, exportar CSV y reimportar ese mismo formato de columnas.
  `vue-tsc`, ESLint y Prettier corren limpios sobre los archivos nuevos/modificados, Pint no
  reportó cambios de estilo pendientes, y `vite build` compila la SPA completa sin errores. **No se
  pudo verificar visualmente la UI en un navegador real** (mismo entorno Windows sin herramienta de
  navegador headless que en 004/005) — se recomienda abrir `/articulos` manualmente para confirmar
  la tabla, los comboboxes de clave SAT, el modal de importación CSV (incluyendo el reporte de
  errores por fila) y el diálogo de confirmación de borrado antes de dar la funcionalidad por
  completamente probada visualmente.
- **Corregido (detectado el 2026-07-31 en verificación visual manual, corregido el mismo día)**: el
  modal de importación CSV se desbordaba — los campos (selector de proveedor, `<input
  type="file">`, y el listado de columnas del CSV embebido en la descripción) excedían el ancho
  fijo del `Dialog`, por la causa raíz documentada en la regla general de
  [003-design-system-tailwind.md](003-design-system-tailwind.md) (`min-width: auto` en hijos de
  grid). Aplicado en `ArticulosListView.vue` siguiendo esa regla: (1) el listado de columnas salió
  de la prosa de `DialogDescription` y ahora vive en un `<code>` propio con `overflow-x-auto` +
  `whitespace-nowrap` (se puede hacer scroll horizontal dentro del bloque en vez de ensanchar el
  modal); (2) el contenedor `div.space-y-4` que envuelve todos los campos, y el que envuelve el
  `<input type="file">`, llevan `min-w-0` explícito; (3) el propio `<input type="file">` lleva
  `min-w-0` para poder encogerse y truncar el nombre del archivo elegido en vez de ensanchar su
  contenedor. Verificado con `vue-tsc`, ESLint, Prettier y `vite build` limpios; no se pudo
  reverificar visualmente en un navegador real (misma limitación de entorno).
- **Corregido (reportado el 2026-08-03, corregido el mismo día)**: en la tabla de `/articulos`, un
  proveedor con `nombre_comercial` largo ensanchaba su columna sin límite (`TableCell` aplica
  `whitespace-nowrap` a toda celda) y empujaba el botón "Eliminar" fuera del área visible del
  contenedor `overflow-auto` de `Table`, sin que el usuario notara que había que hacer scroll
  horizontal para encontrarlo. Se corrigió: (1) `components/ui/table/TableCell.vue` ganó una prop
  `truncate` opcional que envuelve el contenido en un `<span class="block max-w-[220px]
  truncate">`, reutilizable por cualquier tabla de listado de la app (no solo Artículos); (2)
  `ArticulosListView.vue` la usa en la celda de proveedor junto con el atributo `title` nativo
  (pasa por fallthrough automático de Vue al no estar declarado como prop propio), que muestra el
  nombre completo como tooltip del navegador al pasar el mouse; (3) aprovechando el cambio, la
  tabla redujo sus columnas visibles a nombre, modelo, proveedor y precio con IVA (antes incluía
  además catálogo, precio sin IVA y precio con descuento, agregados en
  [009-catalogos.md](009-catalogos.md); precio sin IVA sigue siendo editable en el formulario,
  solo se quitó de la tabla). Formalizar la prop `truncate` como regla general de `Table` en
  [003-design-system-tailwind.md](003-design-system-tailwind.md) (mismo espíritu que la regla ya
  existente de `Dialog`) queda **pendiente** como paso aparte, fuera de esta historia. Verificado
  con `vue-tsc`, ESLint, Prettier y `vite build` limpios; **no se pudo verificar visualmente en un
  navegador real** (misma limitación de entorno que el resto de esta historia) — se recomienda
  abrir `/articulos` con un proveedor de nombre largo para confirmar que el texto se trunca con
  elipsis, el tooltip muestra el nombre completo, y el botón "Eliminar" permanece visible sin
  scroll horizontal.
- **Corregido (reportado el 2026-08-07, corregido el mismo día)**: una importación real de 36 filas
  falló entera con `The selected objeto imp is invalid`. El archivo se había preparado en una hoja
  de cálculo, que interpretó `02` como número y lo guardó como `2`; `objeto_imp` es la única columna
  del CSV con cero inicial, así que fue la única afectada y las dos claves SAT de la misma fila
  validaban sin problema. Se agregó el relleno con cero a la izquierda junto a las normalizaciones
  que ya existían para `clave_unidad` y `tamano_goma`, y los motivos de las columnas de lista
  cerrada pasaron a nombrar la columna y el valor recibido: el mensaje del framework, repetido 36
  veces sin decir qué valor había leído, no daba forma de llegar a la causa.
- **Corregido (reportado el 2026-08-07, corregido el mismo día)**: el mismo archivo, ya con las
  claves de objeto de impuesto corregidas, falló entero con `Malformed UTF-8 characters, possibly
  incorrectly encoded`. La hoja de cálculo lo había guardado en Windows-1252, donde la `Ø` de
  `Sello redondo de Ø X 45 mm` es el byte `0xD8`; ese byte no es UTF-8 válido, así que la respuesta
  JSON del reporte no podía serializarse y el error de codificación sustituía al resultado de la
  importación. Se transcodifica el archivo a UTF-8 al abrirlo, junto con el retiro del BOM que
  antepone la opción "CSV UTF-8" de Excel — el otro camino por el que el usuario habría llegado al
  mismo callejón. La detección es por contenido: `mb_check_encoding` sobre el archivo completo, una
  sola vez.

## Criterios de aceptación

1. Un usuario autenticado puede crear un artículo capturando proveedor, nombre, modelo, clave de
   producto/servicio, clave de unidad, objeto de impuesto y precio unitario sin IVA (todos
   obligatorios).
2. Omitir cualquiera de los campos obligatorios muestra un error de validación y no permite
   guardar.
3. Capturar una clave de producto/servicio o clave de unidad que no exista en el catálogo SAT
   correspondiente muestra un error de validación y no permite guardar.
4. Capturar un precio unitario sin IVA menor o igual a 0 muestra un error de validación.
5. Capturar un nombre ya usado por otro artículo **del mismo proveedor** muestra un error de
   "nombre duplicado"; el mismo nombre sí puede usarse en un proveedor distinto o reutilizarse tras
   eliminar (soft delete) el artículo que lo tenía.
6. El listado `/articulos` muestra los artículos del usuario autenticado, paginados, y la búsqueda
   filtra por nombre, modelo o proveedor.
7. Editar un artículo existente permite modificar cualquier campo y persiste los cambios.
8. Eliminar un artículo lo remueve del listado (soft delete) pero no lo borra físicamente de la
   base de datos.
9. Importar un CSV válido (proveedor preseleccionado) da de alta todos los artículos del archivo
   asociados a ese proveedor.
10. Importar un CSV con algunas filas inválidas (ej. clave SAT inexistente) importa las filas
    válidas y reporta el número de fila y motivo de cada fila rechazada, sin abortar el archivo
    completo.
11. Exportar el listado de artículos genera un CSV con las columnas
    `nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva`, editable y
    reimportable directamente para un proveedor.
12. Un CSV abierto y guardado en una hoja de cálculo sigue siendo importable: una fila con
    `objeto_imp` en `2` se importa como `02`, igual que una que traiga `02`. Un valor que no
    corresponde a ninguna de las cuatro claves aun después de normalizarse rechaza su fila, con un
    motivo que nombra la columna y el valor recibido.
13. Un CSV guardado en Windows-1252 se importa con sus acentos y símbolos intactos: un artículo
    llamado `Sello redondo de Ø X 45 mm` queda con ese mismo nombre. Un CSV guardado como UTF-8 con
    BOM también se importa, sin que la primera columna se rechace como faltante.
14. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.
15. El modal de importación CSV se muestra completo dentro de los límites del `Dialog` (sin
    desbordar contenido ni requerir scroll horizontal) en viewports de escritorio estándar
    (≥1280px), tanto con el listado de columnas de la descripción como con un archivo seleccionado
    cuyo nombre sea largo (ej. más de 40 caracteres).
16. En el listado `/articulos`, toda celda de texto con un valor largo (ej. más de 40 caracteres)
    lo muestra truncado con elipsis y completo en un tooltip (atributo `title`) al pasar el mouse; el
    botón "Eliminar" de esa fila permanece dentro del área visible de la tabla, sin requerir scroll
    horizontal, en viewports de escritorio estándar (≥1280px). Las columnas concretas de la tabla
    cambiaron después —proveedor dio paso a catálogo en [011](011-precio-proveedor-utilidad.md), y
    la tabla quedó en nombre, modelo, costo y precio de venta en
    [025](025-filtros-columna-listado-articulos.md)—; lo que no cambia es la regla.

## Supuestos asumidos (registro completo)

1. "Artículo" es una entidad propia del usuario dueño de la cuenta (no compartida entre usuarios ni
   multiempresa por ahora).
2. Un artículo pertenece a **exactamente un** proveedor (`proveedor_id`, obligatorio); un proveedor
   puede tener varios artículos (`Proveedor::articulos()`).
3. El proveedor se selecciona de los proveedores ya existentes del usuario; no se puede escribir un
   proveedor libre ni crear uno nuevo desde el formulario de artículo.
4. Campos obligatorios: `nombre`, `modelo`, clave de producto/servicio, clave de unidad, objeto de
   impuesto y precio unitario sin IVA.
5. **(Redefinido)** "Clave SAT" no es un solo campo: incluye clave de producto/servicio
   (`c_ClaveProdServ`), clave de unidad de medida (`c_ClaveUnidad`) y objeto de impuesto
   (`c_ObjetoImp`, valores fijos `01`-`04`). Las dos primeras se validan contra catálogos oficiales
   del SAT (misma estrategia que régimen fiscal/código postal en Cliente); la tercera es un `enum`
   de backend sin catálogo externo.
6. `precio_unitario_sin_iva` es un valor numérico positivo (mayor a 0), con 2 decimales, en pesos
   mexicanos (MXN).
7. El IVA aplicado es siempre la tasa general del 16% (no se contempla tasa 0%, exento, ni IVA
   fronterizo en esta historia); el precio con IVA se calcula solo para mostrarse en pantalla, no
   se almacena.
8. `nombre` y `modelo` son campos de texto libre (sin catálogo ni formato especial), ambos
   obligatorios.
9. No hay campo de código interno / SKU propio del sistema en esta historia.
10. No se maneja inventario/existencias (cantidad en stock) en esta historia — solo datos maestros
    del artículo.
11. **(Redefinido)** `nombre` es único **por proveedor** (dos artículos del mismo proveedor no
    pueden compartir nombre; proveedores distintos sí pueden repetir nombre), validado a nivel de
    aplicación permitiendo reutilizar el nombre tras un soft delete, mismo patrón que el RFC en
    Cliente/Proveedor.
12. "Eliminar" un artículo es borrado lógico (soft delete) simple, sin restricciones adicionales de
    negocio en esta historia (no se bloquea por relaciones futuras).
13. Existe una pantalla de listado de artículos con búsqueda y paginación, filtrando por nombre,
    modelo o proveedor.
14. No hay roles/permisos diferenciados ni multiempresa (mismo patrón que Cliente/Proveedor).
15. **(Redefinido)** Se incluye importación y exportación de artículos vía CSV en esta historia.
16. **(Adición técnica)** El objeto de impuesto (`c_ObjetoImp`) se modela como un `enum` de PHP
    hardcodeado en el backend (4 valores fijos y estables del SAT), no como tabla/catálogo externo
    consultado por endpoint.
17. **(Adición técnica)** La base SQLite reducida de catálogos SAT (de 004) se amplía con
    `c_ClaveProdServ` y `c_ClaveUnidad`, con endpoints de búsqueda propios
    (`/api/v1/catalogos/claves-prod-serv` y `/api/v1/catalogos/claves-unidad`), en vez de validar
    solo el formato de estos campos.
18. **(Adición técnica)** La importación CSV asocia todas las filas del archivo al proveedor
    preseleccionado en pantalla antes de subir el archivo; el CSV no lleva columna de proveedor.
19. **(Adición técnica)** Ante errores parciales en la importación CSV, se importan las filas
    válidas y se reporta fila por fila el motivo de las filas rechazadas, en vez de rechazar el
    archivo completo.
20. **(Adición técnica)** La exportación usa exactamente las mismas columnas que espera la
    importación (`nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva`,
    sin columna de proveedor), de modo que un CSV exportado, editado, sirve como plantilla
    reimportable para un proveedor.
21. La validación de las claves de producto/servicio y de unidad es solo contra el catálogo local
    descargado del SAT, no contra el webservice real.
22. La importación CSV solo da de alta artículos nuevos; no se usa para editar ni eliminar
    artículos existentes de forma masiva.
