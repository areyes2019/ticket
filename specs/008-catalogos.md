# Spec: Gestión de catálogos (artículos agrupados por proveedor con descuento)

**Referencia:** reescritura de [remotas/009-catalogos.md](remotas/009-catalogos.md), que se diseñó
para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan las reglas de negocio y las
lecciones de la implementación remota (orden de `DROP` en MySQL, división entera en SQLite); la
parte del navegador y el protocolo entre navegador y servidor se rehicieron para Laravel + Blade +
JavaScript nativo.

## Historia de usuario

Como usuario del sistema de facturación, quiero agrupar mis artículos en catálogos, porque hay
proveedores que manejan varios catálogos de productos con descuentos distintos entre sí, para
aplicar el descuento correspondiente a cada grupo sin tener que capturarlo artículo por artículo.

## Objetivo / Alcance

Implementar un módulo CRUD de catálogos sobre la arquitectura monolítica Laravel + Blade +
JavaScript nativo de [001-inicio-proyecto.md](001-inicio-proyecto.md), con la sesión web de
[002-login.md](002-login.md), los componentes Blade de [003-estilo-uniforme.md](003-estilo-uniforme.md)
y las convenciones de [004-gestion-proveedores.md](004-gestion-proveedores.md). `Catalogo` se
intercala entre `Proveedor` (004) y `Articulo` ([007-gestion-articulos.md](007-gestion-articulos.md)):
un proveedor tiene varios catálogos y cada artículo pertenece a un catálogo.

- Laravel resuelve el módulo completo: rutas web, controlador, validación, autorización y vistas
  Blade. No se crea API REST, no se usa Sanctum ni API Resources.
- JavaScript nativo solo en dos lugares: el **precio con descuento en vivo** del formulario de
  artículo y la confirmación antes de eliminar (manejador `data-confirmar` ya existente). Todo tiene
  respaldo sin JavaScript.
- Incluye la migración de los artículos existentes de 007 a la nueva estructura.

**No** incluye descuentos distintos por artículo dentro de un catálogo ni la aplicación del
descuento en facturación (que no existe todavía).

> **Desde [029](029-pago-cotizacion-pedido-orden-trabajo.md)** (implementada el 2026-10-04): el catálogo gana la casilla **"Requiere
> producción"** (`catalogos.requiere_produccion`, apagada por omisión), con la insignia "Producción"
> en el listado. Todos sus artículos la heredan sin excepción y no dispara recálculo de precios.

## Backend (Laravel)

### Modelo y base de datos

- **Modelo `Catalogo`** (tabla `catalogos`), perteneciente a un `User` (`user_id`) y a un
  `Proveedor` (`proveedor_id`, obligatorio), con **soft deletes**.
- Relaciones: `User::catalogos()`, `Proveedor::catalogos()` y `Catalogo::articulos()` (`hasMany`);
  `Catalogo::user()` y `Catalogo::proveedor()` (`belongsTo`). `Catalogo::proveedor()` incluye los
  proveedores eliminados (`->withTrashed()`), igual que `Articulo::proveedor()` en 007.
- `user_id` **no** está en `fillable`: el alta se hace con
  `$request->user()->catalogos()->create($datos)`.
- **Campos**:
  - `nombre`: string, **obligatorio**, único por proveedor.
  - `descuento`: `decimal(5,2)`, **obligatorio**, entre 0 y 100, con **valor por defecto 0** si se
    deja vacío.
- **Migración** `catalogos`: `user_id` y `proveedor_id` como `foreignId()->constrained()`,
  `softDeletes()`, índices `(user_id, nombre)` para el listado y `(proveedor_id, nombre)` para la
  unicidad. La unicidad no se impone en MySQL (mismo motivo que en 004/007: un índice único no puede
  excluir los borrados lógicamente).
- **Factory** `CatalogoFactory`.

### Cambios sobre `Articulo` (extiende 007)

- **Nueva columna `catalogo_id`** (FK obligatoria a `catalogos`). **`proveedor_id` se conserva** como
  copia del proveedor del catálogo (ver supuesto 6): como el proveedor de un catálogo nunca cambia,
  la copia no se puede desincronizar, y la unicidad de nombre, el orden por proveedor y
  `Articulo::proveedor()` de 007 siguen funcionando igual.
  - `catalogo_id` sustituye a `proveedor_id` en `fillable`: el formulario y la importación solo
    envían el catálogo. `proveedor_id` lo copia el modelo (evento `saving`) desde el catálogo cada
    vez que cambia `catalogo_id`; ningún controlador lo escribe.
  - `Articulo::catalogo()` (`belongsTo`, con `withTrashed()`).
- **Nueva columna `precio_con_descuento`**: `decimal(10,2)`, **persistida**, calculada como
  `precio_unitario_sin_iva × (1 − descuento / 100)` y redondeada a 2 decimales.
  - Se calcula en **un solo lugar** para el alta, la edición y la importación: el mismo evento
    `saving` de `Articulo`, cuando cambia `catalogo_id` o `precio_unitario_sin_iva`, con
    `Catalogo::precioConDescuento()`. El valor que envíe el navegador para ese campo se ignora (no
    es asignable ni está en las reglas).
  - Se recalcula **en bloque** (un solo `UPDATE`, no artículo por artículo) para todos los artículos
    del catálogo, incluidos los eliminados, cuando cambia su `descuento` (evento `updated` de
    `Catalogo`, solo si `wasChanged('descuento')`).
  - El `UPDATE` usa `ROUND(precio_unitario_sin_iva * (1 - {descuento} / 100.0), 2)`. El literal
    `100.0` es obligatorio: en SQLite (pruebas) `20 / 100` entre enteros da `0` (lección de la spec
    remota). El descuento se interpola ya formateado como número (`%.2F`), nunca como texto.
  - El cálculo en PHP redondea igual que MySQL (mitad hacia arriba, sobre el valor exacto): primero a
    6 decimales para quitar el ruido de punto flotante y luego a 2, como `precio_unitario_con_iva`.
    Una prueba compara el cálculo por fila con el recálculo en bloque, con casos de empate.
- `precio_unitario_con_iva` sigue siendo un accessor sin columna.
- **Migración de esquema y datos** (una migración):
  1. Agrega `catalogo_id` y `precio_con_descuento` como anulables.
  2. En una transacción, por cada proveedor **(incluidos los eliminados)** que tenga artículos
     **(incluidos los eliminados)**, crea un catálogo "General" con `descuento = 0` y el `user_id`
     del proveedor, y le reasigna esos artículos con `precio_con_descuento = precio_unitario_sin_iva`.
  3. Vuelve ambas columnas obligatorias.
  - `down()`: `dropForeign` **antes** que la columna (lección de MySQL de la spec remota: el índice
    de una FK no se puede borrar antes que la FK).

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]`:

```php
Route::resource('catalogos', CatalogoController::class)
    ->except('show')
    ->parameters(['catalogos' => 'catalogo']);
```

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/catalogos` | listado paginado, con `?buscar=` | `catalogos.index` |
| GET | `/catalogos/crear` | formulario de alta | `catalogos.create` |
| POST | `/catalogos` | alta | `catalogos.store` |
| GET | `/catalogos/{catalogo}/editar` | formulario de edición | `catalogos.edit` |
| PUT | `/catalogos/{catalogo}` | edición de nombre y descuento | `catalogos.update` |
| DELETE | `/catalogos/{catalogo}` | borrado lógico | `catalogos.destroy` |

- `/catalogos` ya no choca con los catálogos del SAT, que desde 007 viven en `/catalogos-sat/*` con
  `CatalogoSatController`; el rodeo `/api/v1/catalogos-proveedor` de la spec remota no hace falta.
- La importación de artículos sigue en `/articulos/importar` (007); el destino pasa a ser un
  catálogo elegido **en el formulario**.

### Controlador (`CatalogoController`)

- `index`: los catálogos del usuario, con el proveedor precargado y el número de artículos
  (`withCount`), ordenados por nombre de proveedor y de catálogo, paginados de 25 en 25 con
  `->withQueryString()`. `?buscar=` filtra por coincidencia parcial en el nombre del catálogo **o**
  en el `nombre_comercial` del proveedor. Búsqueda con recarga normal, como Proveedores (004).
- `create` / `edit`: vista del formulario; en el alta, la lista de proveedores activos del usuario
  (sin paginar, por nombre comercial).
- `store` / `update`: guardan los datos validados y redirigen al listado con mensaje flash.
- `destroy`: si el catálogo tiene artículos (no eliminados), **no** lo elimina y redirige al listado
  con el mensaje de error "No se puede eliminar: el catálogo tiene artículos asociados" (mismo patrón
  que `tiene_ordenes_activas` en 004, en lugar del `409` de la spec remota). Si no, soft delete.

### Autorización (`CatalogoPolicy`)

- Igual que 004/007: `update` y `delete` solo para el dueño; a los demás
  `Response::denyAsNotFound()` (404). `CatalogoRequest::authorize()` usa `Gate::inspect('update', …)`
  en edición.

### Validaciones (`CatalogoRequest`)

- `prepareForValidation()`: un `descuento` vacío o ausente se toma como `0`.
- `proveedor_id` (**solo en el alta**): requerido, del usuario y no eliminado
  (`Rule::exists('proveedores','id')->where('user_id', …)->withoutTrashed()`). En la edición la regla
  no existe, así que un `proveedor_id` enviado no aparece en `validated()` y se ignora: el proveedor
  es **inmutable**.
- `nombre`: requerido, string, max 255,
  `Rule::unique('catalogos','nombre')->where('proveedor_id', …)->withoutTrashed()`, ignorando el
  catálogo actual en edición.
- `descuento`: requerido, numérico, entre 0 y 100, `decimal:0,2`.
- Mensaje de duplicado: "Nombre duplicado: este proveedor ya tiene un catálogo con ese nombre.".

### Validaciones de artículo (`ArticuloRequest` e `ImportarArticulosRequest`)

- `catalogo_id` sustituye a `proveedor_id`: requerido, del usuario, no eliminado, y **su proveedor
  tampoco eliminado** (`ArticuloRequest::reglaCatalogo()`, compartida por el formulario y la
  importación). Mensaje: "Selecciona uno de tus catálogos.".
- Unicidad de `nombre`: igual que 007 (por proveedor), con el proveedor tomado del catálogo enviado.
  Dos artículos del mismo proveedor no pueden compartir nombre aunque estén en catálogos distintos.
- `ImportadorArticulosCsv::importar()` recibe un `Catalogo` en lugar de un `Proveedor`.

## Vistas (Blade)

Todas extienden `layouts.app` y usan solo los componentes de 003.

- **`catalogos/index.blade.php`**: botón "Nuevo catálogo" (`bi-plus-lg`), buscador ("Buscar por
  catálogo o proveedor") con "Buscar" y "Limpiar", tabla con catálogo, proveedor, descuento (`15%`),
  artículos y acciones ("Editar" `bi-pencil`, "Eliminar" `bi-trash` con
  `data-confirmar="¿Eliminar este catálogo?"`), paginación y mensajes flash.
- **`catalogos/crear.blade.php`** y **`catalogos/editar.blade.php`**: comparten
  `catalogos/_formulario.blade.php`:
  - Alta: proveedor (`<x-campo tipo="select">`, obligatorio). Si el usuario no tiene proveedores, en
    lugar del formulario se muestra un `<x-alerta tipo="advertencia">` con enlace a "Nuevo
    proveedor".
  - Edición: el proveedor se muestra deshabilitado (solo lectura).
  - Nombre (texto, obligatorio) y descuento (`number`, `step="0.01"`, `min="0"`, `max="100"`,
    precargado en `0`).
- **Formulario de artículo** (`articulos/_formulario.blade.php`): el selector de proveedor se
  reemplaza por un selector de **catálogo** con opciones "Proveedor — Catálogo (15%)", así el
  proveedor derivado queda a la vista sin poder elegirse aparte. Solo lista catálogos activos de
  proveedores activos. Si no hay ninguno, en lugar del formulario se muestra un aviso con enlace a
  "Nuevo catálogo". Bajo el precio con IVA se muestra, solo de lectura, el **precio con descuento**.
- **Listado de artículos**: nueva columna "Catálogo" (ordenable) junto a "Proveedor".
- **Importación de artículos**: el formulario pide un **catálogo** destino con el mismo selector;
  mismo reporte que 007.
- **Menú**: enlace "Catálogos" (`bi-collection`) en `layouts/app.blade.php`, entre "Proveedores" y
  "Artículos".

## JavaScript

### Precio con descuento en vivo (`public/js/precio-con-descuento.js`)

- Archivo nuevo, cargado solo en el formulario de artículos. El `<output data-precio-con-descuento>`
  lleva en `data-descuentos` un JSON `{ catalogo_id: descuento }` y en `data-precio`/`data-catalogo`
  los ids de los dos campos; recalcula al escribir el precio o al cambiar el catálogo.
- Es solo informativo: el precio que cuenta lo calcula el servidor. Sin JavaScript se muestra el
  valor guardado (en edición) o queda vacío.

### Confirmación de eliminación

Se reutiliza `data-confirmar` de `public/js/app.js`.

## Pruebas (Pest)

`tests/Feature/CatalogoTest.php`, más los ajustes de `ArticuloTest` e `ImportacionArticulosTest`:

- Invitado redirigido al login; usuario suspendido no entra; enlace en el menú.
- Crear, listar (solo los propios, búsqueda por catálogo y por proveedor), editar y eliminar (soft
  delete).
- Validaciones: proveedor y nombre obligatorios, proveedor ajeno o eliminado, descuento fuera de
  0–100 o con más de 2 decimales, descuento vacío → 0, nombre duplicado en el mismo proveedor, mismo
  nombre en otro proveedor y tras un soft delete.
- El proveedor no cambia al editar aunque se envíe `proveedor_id`.
- Editar o eliminar un catálogo ajeno responde 404.
- Eliminar un catálogo con artículos no lo elimina y muestra el mensaje; con artículos eliminados sí.
- Artículos: se crean con `catalogo_id`, copian el `proveedor_id` y calculan `precio_con_descuento`;
  cambiar el descuento del catálogo recalcula en bloque (incluido un caso de empate que debe
  coincidir con el cálculo por fila); nombre único por proveedor entre catálogos distintos; catálogo
  ajeno, eliminado o de un proveedor eliminado es error de validación; la importación asocia las
  filas al catálogo elegido.
- La migración de datos crea "General" y reasigna los artículos (verificada contra MySQL).
- `EstiloUniformeTest`, `ProveedorTest` y `ClienteTest` siguen pasando.

## Fuera de alcance

- Subcatálogos o artículos en más de un catálogo.
- Descuentos distintos por artículo dentro de un catálogo.
- Uso de `precio_con_descuento` en facturación/CFDI.
- Historial de cambios de descuento.
- Mover un catálogo a otro proveedor; mover artículos en lote entre catálogos
  ([remotas/034](remotas/034-filtro-catalogo-y-mover-lote-articulos.md)).
- Importación/exportación de catálogos (solo de artículos, como en 007).
- Bloquear la eliminación de un proveedor que tiene catálogos.
- Roles/permisos diferenciados y multiempresa.

## Estado de implementación

Implementada el 2026-09-25.

- **Proveedor y precio con descuento en el evento `saving` de `Articulo`**: se recalculan solo si
  cambia `catalogo_id` o `precio_unitario_sin_iva`, leyendo el catálogo con una consulta nueva (no la
  relación ya cargada, que podría ser la del catálogo anterior).
- **Selector de catálogo**: `Catalogo::scopeDisponibles()` (catálogos del usuario cuyo proveedor no
  está eliminado, ordenados por proveedor y nombre) alimenta el formulario de artículo y la
  importación; la etiqueta sale del accessor `Catalogo::etiqueta` y el descuento sin ceros
  sobrantes de `Catalogo::descuento_texto` ("12.5%").
- **Redondeo verificado en los tres motores**: SQLite, MySQL y PHP dan `9.05` para el empate
  `10.05 × 0.9`; una prueba compara el recálculo en bloque con el cálculo por fila y en MySQL real el
  recálculo de los 277 artículos con 12.5% coincidió al 100% con PHP (dentro de una transacción
  revertida).
- **Migración de datos contra MySQL real**: la base tenía 277 artículos de un proveedor. Se creó un
  catálogo "General" (0%) y los 277 quedaron con `catalogo_id`, el mismo `proveedor_id` y
  `precio_con_descuento = precio_unitario_sin_iva` (suma de precios idéntica antes y después: 106,788.50).
  También se probó `migrate:rollback --step=2` y volver a migrar en MySQL. Hay una prueba Pest que
  corre `down()`/`up()` con proveedores y artículos eliminados.
- **`/estilos`**: agrega el icono `bi-collection`.
- **Verificación**: la suite Pest pasa (331 tests), Pint no reporta cambios y `node --check` valida
  `precio-con-descuento.js`. **No se revisó la UI en un navegador real**: conviene abrir `/catalogos`
  (alta, edición con proveedor de solo lectura, bloqueo al eliminar) y `/articulos/crear` (selector de
  catálogo y precio con descuento en vivo al cambiar precio o catálogo).

## Criterios de aceptación

1. Un usuario autenticado puede crear un catálogo capturando proveedor (obligatorio), nombre
   (obligatorio) y descuento (0–100%, 0% si se deja vacío).
2. Omitir el proveedor o el nombre muestra un error y no permite guardar.
3. Un descuento fuera de 0–100 o con más de 2 decimales muestra un error.
4. Un nombre ya usado por otro catálogo **del mismo proveedor** muestra "nombre duplicado"; el mismo
   nombre se acepta en otro proveedor o tras eliminar el catálogo que lo tenía.
5. `/catalogos` muestra solo los catálogos del usuario, 25 por página, con proveedor, descuento y
   número de artículos; la búsqueda filtra por nombre del catálogo o del proveedor.
6. Editar un catálogo permite cambiar nombre y descuento; el proveedor se muestra de solo lectura y
   un `proveedor_id` enviado se ignora.
7. Al cambiar el descuento de un catálogo, el `precio_con_descuento` de todos sus artículos se
   recalcula sin editarlos uno por uno.
8. Eliminar un catálogo sin artículos pide confirmación y hace soft delete.
9. Eliminar un catálogo con artículos no lo elimina y muestra "No se puede eliminar: el catálogo
   tiene artículos asociados".
10. Editar, actualizar o eliminar un catálogo ajeno responde 404.
11. Un artículo se crea o edita eligiendo un catálogo; el proveedor se ve en la opción elegida y no
    se elige aparte.
12. Al crear o editar un artículo se guarda `precio_con_descuento` con el descuento del catálogo,
    redondeado a 2 decimales, y el formulario lo muestra mientras se escribe.
13. Los artículos anteriores quedan, tras la migración, en un catálogo "General" (0%) de su mismo
    proveedor, sin perder datos.
14. Importar un CSV asocia todas las filas al catálogo elegido, con el mismo reporte de errores que
    007.
15. El listado de artículos muestra la columna "Catálogo"; la exportación CSV no cambia.
16. El menú muestra "Catálogos" con su icono.
17. Pint corre sin cambios y la suite Pest pasa.

## Supuestos asumidos (registro completo)

1. "Catálogo" es una entidad propia del usuario (no compartida ni multiempresa).
2. Un catálogo pertenece a **exactamente un** proveedor; un proveedor puede tener varios.
3. Un catálogo tiene `nombre` (obligatorio) y `descuento` (obligatorio, 0% por defecto).
4. El descuento es un porcentaje uniforme para todos los artículos del catálogo.
5. `precio_con_descuento` se guarda en el artículo y se recalcula en bloque al cambiar el descuento.
6. **(Cambio de arquitectura)** Un artículo pertenece a exactamente un catálogo (`catalogo_id`) y
   **conserva `proveedor_id` como copia** del proveedor del catálogo, escrita solo por el modelo (la
   spec remota lo eliminaba). Es seguro porque el proveedor de un catálogo es inmutable.
7. El nombre del catálogo es único por proveedor, reutilizable tras un soft delete.
8. Eliminar un catálogo es soft delete; con artículos (no eliminados) se bloquea con un mensaje de
   error en el listado **(cambio de arquitectura: flash en vez de `409`)**.
9. **(Cambio de arquitectura)** El selector de catálogo es un `<select>` Blade ("Proveedor —
   Catálogo (15%)") en lugar del componente Vue `CatalogoSelect`.
10. **(Cambio de arquitectura)** El catálogo destino de la importación va en el formulario de
    `/articulos/importar`, no en la URL.
11. **(Cambio de arquitectura)** Rutas en `/catalogos` (el choque con los catálogos SAT ya no
    existe).
12. El proveedor de un catálogo es fijo desde su creación.
13. **(Decisión nueva)** Un catálogo cuyo proveedor fue eliminado sigue apareciendo en el listado y
    en sus artículos, pero no se ofrece al elegir catálogo para un artículo o una importación.
14. **(Decisión nueva)** La migración crea "General" también para proveedores eliminados con
    artículos, e incluye los artículos eliminados, para que ningún registro quede sin catálogo.
15. **(Decisión nueva)** Eliminar un proveedor no se bloquea por tener catálogos.
16. Iconos: `bi-collection` (menú), `bi-plus-lg`, `bi-pencil`, `bi-trash`, `bi-save`, `bi-search`,
    `bi-x-lg`.
