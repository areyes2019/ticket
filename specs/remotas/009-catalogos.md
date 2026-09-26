# Spec: Gestión de catálogos (agrupación de artículos por proveedor con descuento)

## Historia de usuario

Como desarrollador del sistema de facturación, quiero abrir una nueva entidad "Catálogos", porque
hay proveedores que manejan varios catálogos de productos con descuentos distintos entre sí, para
poder agrupar mis artículos por catálogo y aplicar el descuento correspondiente a cada grupo sin
tener que capturarlo artículo por artículo.

## Objetivo / Alcance

Implementar un módulo CRUD de catálogos sobre la base ya existente de Laravel API + Vue 3 SPA +
Sanctum (ver [001-inicio-proyecto.md](001-inicio-proyecto.md),
[002-login-auth.md](002-login-auth.md)), el design system de
[003-design-system-tailwind.md](003-design-system-tailwind.md) y siguiendo el mismo patrón de
implementación que [005-gestion-proveedores.md](005-gestion-proveedores.md). `Catalogo` se
intercala entre `Proveedor` ([005](005-gestion-proveedores.md)) y `Articulo`
([006-gestion-articulos.md](006-gestion-articulos.md)): un proveedor tiene varios catálogos, y cada
artículo pasa a pertenecer a un catálogo (en vez de a un proveedor directamente). Incluye la
migración de los artículos ya existentes de 006 hacia esta nueva estructura. **No** incluye
descuentos variables por artículo dentro de un mismo catálogo, ni la aplicación del descuento en el
módulo de Facturación (que no existe todavía).

## Backend (Laravel)

- **Modelo `Catalogo`**, perteneciente a un `User` (`user_id`) y a un `Proveedor` (`proveedor_id`,
  obligatorio), con **soft deletes** habilitado. `Proveedor::catalogos(): HasMany` y
  `Catalogo::articulos(): HasMany`.
- **Campos**:
  - `nombre`: string, **obligatorio**. Único por proveedor (constraint a nivel de aplicación,
    `Rule::unique('catalogos','nombre')->where('proveedor_id', ...)->whereNull('deleted_at')`,
    mismo patrón que el nombre de `Articulo` en 006).
  - `descuento`: decimal(5,2), **obligatorio**, entre 0 y 100, con **valor por defecto de 0** si no
    se especifica.
- **Colisión de nombres con los catálogos SAT (descubierta en implementación)**: el prefijo
  `/api/v1/catalogos` ya lo usa el `CatalogoController` existente para los catálogos de referencia
  del SAT (régimen fiscal, códigos postales, claves de producto/servicio, etc., consumidos por
  Cliente y Artículo desde 004/006) — un concepto completamente distinto que comparte el mismo
  nombre en español. Para no tocar esos endpoints ya en uso, la entidad de negocio `Catalogo` de
  esta historia usa el prefijo **`/api/v1/catalogos-proveedor`** en vez de `/api/v1/catalogos`. El
  modelo, la tabla (`catalogos`) y el nombre de la entidad en el resto de la spec **no cambian**,
  solo el prefijo de ruta REST.
- **Endpoints** (bajo `auth:sanctum`, scopeados al usuario autenticado):
  - `GET /api/v1/catalogos-proveedor` — listado paginado, con `?search=` (por `nombre` del catálogo
    o nombre comercial del proveedor) y `?per_page=` opcional (mismo patrón que
    `ProveedorController::index` de 006, `min(per_page, 100)`, para alimentar el selector de
    catálogo del formulario de Artículo).
  - `POST /api/v1/catalogos-proveedor` — alta.
  - `GET /api/v1/catalogos-proveedor/{id}` — detalle.
  - `PUT /api/v1/catalogos-proveedor/{id}` — edición de `nombre` y `descuento` únicamente;
    `proveedor_id` es **inmutable** tras la creación (ver validaciones). Si cambia `descuento`,
    dispara el recálculo en bloque descrito abajo.
  - `DELETE /api/v1/catalogos-proveedor/{id}` — borrado lógico (soft delete), bloqueado con `409
    Conflict` y mensaje específico ("No se puede eliminar: el catálogo tiene artículos asociados")
    si el catálogo tiene artículos; mismo patrón que `tiene_ordenes_activas` en Proveedor (005).
  - `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv` — reemplaza a
    `POST /api/v1/proveedores/{proveedor}/articulos/importar-csv` (006); asocia todas las filas
    importadas al catálogo de la ruta. Mismo formato de reporte
    (`{ "importados": <int>, "errores": [...] }`) y mismas columnas CSV que 006.
- **Validaciones** (Form Requests):
  - `proveedor_id`: requerido, debe existir y pertenecer al usuario autenticado. **Solo se acepta
    en el alta** (`StoreCatalogoRequest`); el Form Request de edición
    (`UpdateCatalogoRequest`) no incluye este campo, y si se envía en el `PUT` se ignora (no se
    valida ni se persiste), para que `proveedor_id` sea inmutable tras la creación.
  - `nombre`: requerido, string, único por proveedor.
  - `descuento`: requerido (con default `0` si se omite en la petición), numérico, entre 0 y 100,
    máximo 2 decimales.
- Respuestas mediante `CatalogoResource`, consistente con la convención de 001/004/005/006.

### Cambios sobre `Articulo` (extiende 006)

- **Migración de esquema**: la columna `proveedor_id` de `articulos` se reemplaza por
  `catalogo_id` (FK obligatoria a `catalogos`). **Migración de datos** en el mismo cambio: por cada
  `Proveedor` que ya tenga artículos, se crea automáticamente un `Catalogo` llamado "General" con
  `descuento = 0`, y todos sus artículos existentes se reasignan a ese catálogo, sin perder datos.
- **Nueva columna `precio_con_descuento`**: decimal(10,2), calculada como
  `precio_unitario_sin_iva * (1 - descuento_del_catalogo / 100)`, redondeada a 2 decimales, y
  **persistida** (a diferencia de `precio_unitario_con_iva`, que sigue siendo un atributo calculado
  de solo lectura sin columna propia).
  - Se recalcula al crear/editar el artículo (usando el descuento del catálogo seleccionado).
  - Se recalcula **en bloque** (una actualización masiva vía query, no iterando artículo por
    artículo con Eloquent) para todos los artículos de un catálogo cuando se edita el `descuento`
    de ese catálogo.
- **Unicidad de `nombre` de Artículo**: se mantiene el mismo espíritu de 006 (único por proveedor),
  pero ahora aplica sobre el proveedor derivado del catálogo
  (`Rule::unique('articulos','nombre')->whereIn('catalogo_id', <catálogos del proveedor>)->whereNull('deleted_at')`
  o equivalente vía join): dos artículos del mismo proveedor no pueden compartir nombre aunque
  estén en catálogos distintos de ese proveedor.
- **Validaciones** (Form Requests de Artículo): `catalogo_id` reemplaza a `proveedor_id` —
  requerido, debe existir y pertenecer (vía su proveedor) al usuario autenticado.
- **`ArticuloResource`**: agrega `precio_con_descuento` a la respuesta, junto a los campos ya
  existentes de 006 (incluyendo `precio_unitario_con_iva`); expone también el catálogo y, dentro de
  este, el proveedor derivado.
- La ruta `POST /api/v1/proveedores/{proveedor}/articulos/importar-csv` de 006 se **elimina**, en
  favor de `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv` descrita arriba. La
  ruta `GET /api/v1/articulos/exportar-csv` y sus columnas **no cambian**.

## Frontend (Vue 3)

- **`/catalogos`** (protegida): listado paginado de catálogos en tabla, con buscador (nombre del
  catálogo o proveedor), mostrando columnas de proveedor y descuento.
- **`/catalogos/crear`**: formulario de alta con selector de proveedor (`Select` simple,
  obligatorio, mismo patrón que en Artículo/006), `nombre` (`Input`, obligatorio) y `descuento`
  (`Input` numérico, precargado en `0`, el usuario puede dejarlo o modificarlo).
- **`/catalogos/:id/editar`**: mismo formulario, precargado, para edición de `nombre` y
  `descuento`; el proveedor se muestra de solo lectura (no editable), ya que es fijo desde la
  creación del catálogo.
- Confirmación (modal `Dialog`) antes de eliminar un catálogo; si el backend responde `409` por
  tener artículos asociados, se muestra el mensaje de error específico dentro del propio diálogo
  (mismo patrón que el `409` de Proveedor en 005), sin cerrarlo como éxito.
- **`CatalogoSelect.vue`** (nuevo componente): reemplaza a `ProveedorSelect.vue` en el formulario de
  Artículo (`/articulos/crear` y `/articulos/:id/editar`); lista los catálogos del usuario
  mostrando "Proveedor — Catálogo" para que quede claro el proveedor derivado. El proveedor ya no
  se selecciona directamente en el formulario de Artículo; se muestra de solo lectura, derivado del
  catálogo elegido.
- En el formulario de Artículo, junto a `precio_unitario_sin_iva`, se muestra en vivo (solo
  lectura) tanto `precio_unitario_con_iva` (ya existente en 006) como el nuevo
  `precio_con_descuento`, usando el descuento del catálogo seleccionado.
- **`/articulos`**: la columna de "Proveedor" de la tabla (006) se reemplaza/complementa con
  "Catálogo" (mostrando el proveedor derivado igualmente visible).
- **Importar CSV** en `/articulos`: el modal pasa a pedir un **catálogo** en vez de un proveedor
  como destino de la importación (mismo flujo y reporte de errores que 006).
- Enlace a `/catalogos` agregado a la navegación del `AppLayout`, junto a "Proveedores" y
  "Artículos".

## Fuera de alcance

- Jerarquía de catálogos (subcatálogos) o que un artículo pertenezca a más de un catálogo
  simultáneamente.
- Descuentos distintos por artículo dentro de un mismo catálogo (el descuento es único y uniforme
  por catálogo).
- Aplicación real del descuento en el módulo de Facturación/CFDI: esta historia solo deja
  `precio_con_descuento` calculado y disponible a nivel de artículo; su uso en timbrado se define
  en una historia futura.
- Historial de cambios de descuento (auditoría de valores anteriores).
- Roles/permisos diferenciados o multiempresa (mismo patrón que las entidades anteriores).
- Importación/exportación masiva de catálogos en sí (solo de artículos, como en 006).

## Estado de implementación

Implementada el 2026-08-03.

- **Colisión de rutas con los catálogos SAT**: como se documenta en el supuesto 20, se descubrió
  durante la implementación que `/api/v1/catalogos` ya lo usaba el `CatalogoController` de
  catálogos SAT (004/006). Se resolvió moviendo la entidad de negocio a
  `/api/v1/catalogos-proveedor`, con un controlador nuevo `CatalogoProveedorController` (el nombre
  `CatalogoController` seguía tomado); el modelo Eloquent se sigue llamando `Catalogo`.
- **Migración de datos verificada contra datos reales**: la base de desarrollo (MySQL) ya tenía 13
  artículos de pruebas anteriores asociados a un proveedor. Al correr la migración, se generó
  automáticamente un catálogo "General" (descuento 0%) para ese proveedor y los 13 artículos
  quedaron reasignados con `catalogo_id` y `precio_con_descuento = precio_unitario_sin_iva`, sin
  perder ningún dato — confirmado con consultas directas antes/después de migrar.
- **Bug real de orden de `DROP` en MySQL (detectado y corregido en la propia verificación)**: la
  migración fallaba en MySQL (aunque pasaba en SQLite, usado por los tests) con
  `Cannot drop index ... needed in a foreign key constraint`, porque el `dropIndex` se ejecutaba
  antes que el `dropForeign` sobre la misma columna. SQLite recrea la tabla completa en cada
  alteración y no le importa el orden; MySQL sí. Se corrigió invirtiendo el orden (`dropForeign`
  antes que `dropIndex`) en `up()` y `down()`.
- **Bug real de división entera en SQLite (detectado y corregido en tests)**: el recálculo en
  bloque de `precio_con_descuento` (evento `updated` de `Catalogo`) usa SQL crudo
  (`ROUND(precio_unitario_sin_iva * (1 - {descuento} / 100), 2)`). En SQLite, `/` entre dos
  literales enteros trunca (`20 / 100` = `0`), a diferencia de MySQL que siempre divide en punto
  flotante; el resultado no cambiaba nunca en los tests. Se corrigió forzando el literal decimal
  `100.0` en vez de `100`.
- **Unicidad de nombre de Artículo por proveedor derivado**: `StoreArticuloRequest`/
  `UpdateArticuloRequest` resuelven primero el `Catalogo` del `catalogo_id` enviado y comparan
  contra todos los catálogos de ese mismo proveedor (`Catalogo::where('proveedor_id', ...)->pluck('id')`),
  no solo contra el catálogo elegido, para que dos artículos del mismo proveedor no puedan
  compartir nombre aunque estén en catálogos distintos.
- **Precio con descuento calculado en el controlador, no en el Form Request**: `precio_con_descuento`
  no forma parte de las reglas de validación (así que cualquier valor que el cliente intente enviar
  para ese campo se ignora); `ArticuloController::store/update/importarCsv` lo calculan explícitamente
  a partir del descuento del catálogo resuelto justo antes de guardar.
- **Verificación end-to-end**: la suite Pest completa (104 tests, incluyendo los 16 nuevos de
  `CatalogosTest` y los de `ArticulosTest`/`FacturasTest`/`CotizacionesTest` actualizados para usar
  `catalogo_id` en vez de `proveedor_id`) pasa tanto contra SQLite (tests) como se corrió
  `php artisan migrate` real contra MySQL. Se levantó `php artisan serve` real y se probó por HTTP
  con un usuario y token de Sanctum de prueba (creado y eliminado al terminar) el flujo completo:
  crear proveedor, crear catálogo con descuento, crear artículo (verificando
  `precio_con_descuento`), editar el descuento del catálogo y confirmar el recálculo en bloque
  reflejado al releer el artículo, bloqueo `409` al eliminar un catálogo con artículos, importar y
  exportar CSV, y eliminar correctamente tras vaciar el catálogo. `vue-tsc`, ESLint y Prettier
  corren limpios sobre los archivos nuevos/modificados, Pint no reportó cambios de estilo
  pendientes, y `vite build` compila la SPA completa sin errores. **No se pudo verificar
  visualmente la UI en un navegador real** (mismo entorno Windows sin herramienta de navegador
  headless que en 004/005/006) — se recomienda abrir `/catalogos` y `/articulos` manualmente para
  confirmar el CRUD de catálogos, el nuevo selector de catálogo en el formulario de artículo (con
  el precio con descuento en vivo) y el modal de importación CSV antes de dar la funcionalidad por
  completamente probada visualmente.

## Criterios de aceptación

1. Un usuario autenticado puede crear un catálogo capturando proveedor (obligatorio), nombre
   (obligatorio) y descuento (0–100%, con valor por defecto de 0% si se deja vacío).
2. Omitir el proveedor o el nombre muestra un error de validación y no permite guardar.
3. Capturar un descuento fuera del rango 0–100 muestra un error de validación.
4. Capturar un nombre de catálogo ya usado por otro catálogo **del mismo proveedor** muestra un
   error de "nombre duplicado"; el mismo nombre sí puede usarse en un proveedor distinto o
   reutilizarse tras eliminar (soft delete) el catálogo que lo tenía.
5. El listado `/catalogos` muestra los catálogos del usuario autenticado, paginados, con proveedor
   y descuento visibles, y la búsqueda filtra por nombre de catálogo o proveedor.
6. Editar un catálogo existente permite modificar nombre y descuento, y persiste los cambios; el
   proveedor no es editable (se muestra de solo lectura) y cualquier `proveedor_id` enviado en la
   edición se ignora.
7. Al editar el descuento de un catálogo, el `precio_con_descuento` de todos los artículos que ya
   tenía se recalcula automáticamente, sin necesidad de editar cada artículo uno por uno.
8. Eliminar un catálogo sin artículos asociados lo remueve del listado (soft delete) pero no lo
   borra físicamente de la base de datos.
9. Intentar eliminar un catálogo que tiene artículos asociados responde `409` con un mensaje
   específico y no lo elimina.
10. Un artículo se crea o edita seleccionando un catálogo (ya no un proveedor directamente); el
    proveedor correspondiente se muestra derivado, de solo lectura.
11. Al crear o editar un artículo, se calcula y persiste `precio_con_descuento` usando el descuento
    del catálogo seleccionado, redondeado a 2 decimales.
12. Los artículos creados antes de esta historia (bajo 006) quedan asociados, tras la migración, a
    un catálogo "General" (0% de descuento) del mismo proveedor que tenían, sin perder ningún dato.
13. Importar un CSV de artículos asocia todas las filas al catálogo preseleccionado (y por lo
    tanto a su proveedor), con el mismo comportamiento de reporte de errores por fila que en 006.
14. El listado `/articulos` y su exportación CSV siguen funcionando sin errores tras el cambio de
    `proveedor_id` a `catalogo_id`.
15. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. "Catálogo" es una entidad propia del usuario dueño de la cuenta (no compartida entre usuarios ni
   multiempresa por ahora).
2. Un catálogo pertenece a **exactamente un** proveedor (obligatorio); un proveedor puede tener
   varios catálogos (`Proveedor::catalogos(): HasMany`).
3. **(Redefinido)** Un catálogo tiene `nombre` (obligatorio) y `descuento` (obligatorio, con valor
   por defecto de 0% si no se especifica).
4. El descuento es un porcentaje (0–100%) uniforme para todos los artículos del catálogo (no varía
   por artículo individual).
5. **(Redefinido)** El precio con descuento de cada artículo se calcula **y se persiste** en una
   columna propia del artículo (`precio_con_descuento`), a diferencia del precio con IVA (que solo
   se calcula al leer); se recalcula automáticamente para todos los artículos de un catálogo cuando
   cambia el descuento de ese catálogo.
6. Un artículo deja de pertenecer directamente a un proveedor y pasa a pertenecer a **exactamente
   un catálogo** (`catalogo_id` reemplaza a `proveedor_id` en la tabla `articulos`); el proveedor
   del artículo queda determinado indirectamente vía `catalogo.proveedor_id`.
7. Un catálogo puede tener varios artículos.
8. El nombre del catálogo es único por proveedor (mismo patrón que el nombre de artículo en 006);
   puede repetirse entre proveedores distintos o reutilizarse tras un soft delete.
9. Existe un CRUD completo de catálogos (crear, ver, editar, eliminar) con listado, búsqueda y
   paginación, siguiendo el mismo patrón visual que Proveedores/Artículos.
10. "Eliminar" un catálogo es borrado lógico (soft delete); si tiene artículos asociados, se
    bloquea (409, con mensaje específico) en vez de dejar artículos huérfanos o eliminarlos en
    cascada, mismo patrón que `tiene_ordenes_activas` en Proveedor (005).
11. En el formulario de alta/edición de Artículo, el selector de proveedor (006) se reemplaza por
    un selector de catálogo; el proveedor se muestra derivado, de solo lectura.
12. Los endpoints de importación CSV de artículos se asocian a un catálogo
    (`POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv`) en vez de a un proveedor
    directamente; las columnas del CSV no cambian respecto a 006.
13. No se contempla jerarquía de catálogos (subcatálogos), ni que un artículo pertenezca a más de
    un catálogo a la vez.
14. No hay roles/permisos diferenciados ni multiempresa (mismo patrón que las entidades
    anteriores).
15. **(Adición técnica)** Migración de datos: cada proveedor que ya tenga artículos (de 006) recibe
    automáticamente un catálogo "General" (descuento 0%) al que se reasignan todos sus artículos
    existentes, en la misma migración que agrega `catalogo_id` y elimina `proveedor_id` de
    `articulos`.
16. **(Adición técnica)** El recálculo de `precio_con_descuento` al editar el descuento de un
    catálogo se hace mediante una actualización masiva (query directa), no iterando y guardando
    cada artículo individualmente uno por uno vía Eloquent.
17. **(Adición técnica)** Nuevo componente `CatalogoSelect.vue` en el frontend, que reemplaza a
    `ProveedorSelect.vue` en el formulario de Artículo, mostrando "Proveedor — Catálogo" para que
    se entienda el proveedor derivado.
18. La unicidad de `nombre` de Artículo (006) se mantiene igual en espíritu pero ahora aplica sobre
    el proveedor derivado del catálogo, no sobre `catalogo_id`: dos artículos del mismo proveedor
    no pueden compartir nombre aunque estén en catálogos distintos de ese mismo proveedor.
19. **(Redefinido)** El proveedor de un catálogo es **fijo**: se define solo al crear el catálogo
    y no es editable después (`UpdateCatalogoRequest` no acepta `proveedor_id`); el formulario de
    edición lo muestra de solo lectura. Si un proveedor deja de ser el correcto para un catálogo,
    la única forma de corregirlo es creando un catálogo nuevo bajo el proveedor correcto y
    reasignando manualmente sus artículos (no hay una operación de "mover catálogo de proveedor").
20. **(Adición técnica, descubierta en implementación)** Las rutas REST de la entidad `Catalogo`
    viven bajo `/api/v1/catalogos-proveedor` en vez de `/api/v1/catalogos`, porque este último
    prefijo ya lo usa el `CatalogoController` de catálogos SAT existente (004/006). El nombre del
    modelo, la tabla y el resto de la spec no cambian, solo el prefijo de ruta.
