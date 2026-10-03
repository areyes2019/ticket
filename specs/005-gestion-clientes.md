# Spec: Gestión de clientes (datos comerciales y fiscales SAT)

> **Desde [023](023-descuento-permanente-cliente.md)**: la ficha gana el **descuento permanente**
> (0% a 50%) y el listado una columna "Descuento".

## Historia de usuario

Como usuario del sistema de facturación, quiero administrar (crear, ver, editar y eliminar) los
datos de mis clientes, capturando tanto datos comerciales (nombre comercial, nombre de contacto,
correo, teléfono, dirección) como datos fiscales exigidos por el SAT para timbrado de CFDI (RFC,
razón social, régimen fiscal, código postal fiscal), y encontrarlos rápidamente con una búsqueda
que se actualiza mientras escribo, para poder timbrar facturas válidas a mis clientes sin tener que
volver a capturar sus datos fiscales cada vez.

## Objetivo / Alcance

Implementar un módulo CRUD de clientes sobre la arquitectura monolítica Laravel + Blade +
JavaScript nativo definida en [001-inicio-proyecto.md](001-inicio-proyecto.md), con la
autenticación por sesión web de [002-login.md](002-login.md), los componentes Blade de
[003-estilo-uniforme.md](003-estilo-uniforme.md) y las convenciones ya establecidas por
[004-gestion-proveedores.md](004-gestion-proveedores.md).

- Laravel resuelve el módulo completo: rutas web, controlador, validación, autorización y vistas
  Blade.
- No se crea API REST, no se usa Sanctum ni API Resources, y no hay stores ni estado en el
  frontend.
- JavaScript se usa en dos lugares: la **búsqueda dinámica** del listado (Axios) y la
  confirmación antes de eliminar (manejador `data-confirmar` ya existente).

Incluye la captura y validación de datos fiscales, pero **no** incluye la emisión/timbrado de CFDI
(historia futura que consumirá estos datos).

## Backend (Laravel)

### Modelo y base de datos

- **Modelo `Cliente`**, perteneciente a un `User` (`user_id`), con **soft deletes** (`SoftDeletes`).
- Relación `User::clientes()` (`hasMany`) y `Cliente::user()` (`belongsTo`).
- `user_id` **no** está en `fillable`: el alta se hace siempre con
  `$request->user()->clientes()->create($datos)`.
- **Campos fiscales (obligatorios)**:
  - `rfc`: string(13). Validado con `App\Rules\RfcValido` (ya existe, creada en 004). Se permiten
    los RFC genéricos `XAXX010101000` (público en general) y `XEXX010101000` (extranjero). Se
    guarda en mayúsculas y sin espacios. **Único por usuario** a nivel de aplicación
    (`Rule::unique('clientes','rfc')->where('user_id', ...)->withoutTrashed()`), igual que en 004.
  - `razon_social`: string (tal como aparece en la Constancia de Situación Fiscal).
  - `regimen_fiscal`: string(3), clave del catálogo SAT `c_RegimenFiscal`, respaldada por el enum
    `App\Enums\RegimenFiscal` (ver "Catálogos SAT").
  - `codigo_postal_fiscal`: string(5), exactamente 5 dígitos (ver "Catálogos SAT").
- **Campos comerciales (opcionales)**:
  - `nombre_comercial`: string.
  - `nombre_contacto`: string. **(Nuevo respecto a la spec remota.)**
  - `correo`: string, formato de email válido si se captura. (La spec remota lo llamaba
    `correo_contacto`; se alinea con el nombre usado en `proveedores`.)
  - `telefono`: string(13), normalizado a `+52` + 10 dígitos con la **misma** lógica idempotente de
    `ProveedorRequest` (se extrae a un lugar compartido, ver "Validaciones").
  - `direccion_comercial`: string.
- **Tipo de persona (Física/Moral)**: no se persiste; accessor `tipo_persona` en el modelo que usa
  `phpcfdi/rfc` (`isFisica()` / `isMoral()`); para los RFC genéricos devuelve `null`.
- **Migración** `clientes`:
  - `user_id` como `foreignId()->constrained()`.
  - `softDeletes()`.
  - Índice compuesto `(user_id, rfc)`.
  - Sin `UNIQUE` en MySQL para el RFC (mismo motivo que en 004: no puede excluir los borrados
    lógicamente).
  - `regimen_fiscal` se castea al enum `RegimenFiscal` en el modelo.
- **Factory** `ClienteFactory` con RFC válidos (físicas y morales) y un régimen del enum.

### Catálogos SAT

- **Régimen fiscal**: enum respaldado `App\Enums\RegimenFiscal` (string) con las 19 claves vigentes
  de `c_RegimenFiscal` (601, 603, 605, 606, 607, 608, 610, 611, 612, 614, 615, 616, 620, 621, 622,
  623, 624, 625, 626) y un método `descripcion()` con el texto oficial. El select del formulario se
  llena desde el enum directamente en Blade; no hay endpoint de catálogo. Se valida con
  `Rule::enum(RegimenFiscal::class)`.
- **Código postal fiscal**: en esta historia se valida **solo el formato** (5 dígitos,
  `regex:/^\d{5}$/`). La validación contra `c_CodigoPostal` (~95 mil registros) se difiere a la
  historia de facturación, que es la que necesitará la infraestructura completa de catálogos SAT
  (uso de CFDI, forma de pago, productos/servicios, etc.).
- No se instala `phpcfdi/sat-catalogos` ni se descarga la base SQLite de catálogos en esta
  historia.

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]`:

```php
Route::get('clientes/buscar', [ClienteController::class, 'buscar'])->name('clientes.buscar');

Route::resource('clientes', ClienteController::class)
    ->except('show')
    ->parameters(['clientes' => 'cliente']);
```

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/clientes` | `index` — página completa del listado, con filtros en la URL | `clientes.index` |
| GET | `/clientes/buscar` | `buscar` — fragmento HTML con tabla + paginación (AJAX) | `clientes.buscar` |
| GET | `/clientes/crear` | `create` | `clientes.create` |
| POST | `/clientes` | `store` | `clientes.store` |
| GET | `/clientes/{cliente}/editar` | `edit` | `clientes.edit` |
| PUT | `/clientes/{cliente}` | `update` | `clientes.update` |
| DELETE | `/clientes/{cliente}` | `destroy` — borrado lógico | `clientes.destroy` |

- `/clientes/buscar` es una ruta **separada** de `/clientes` (y no la misma URL respondiendo
  distinto según la cabecera AJAX) para que el navegador nunca guarde en caché ni muestre al
  regresar con "atrás" un fragmento suelto en lugar de la página completa.

### Controlador (`ClienteController`)

- **Filtros de búsqueda** (cuatro campos independientes, uno por columna; se combinan con **Y**;
  cada uno busca coincidencia parcial y se ignora si viene vacío):
  - `razon_social` → `razon_social LIKE %…%`
  - `nombre_comercial` → `nombre_comercial LIKE %…%`
  - `nombre_contacto` → `nombre_contacto LIKE %…%`
  - `rfc` → `rfc LIKE %…%` (el término se pasa a mayúsculas y sin espacios)
- La consulta filtrada vive en un scope local del modelo (`Cliente::scopeFiltrar(array $filtros)`)
  para que `index` y `buscar` usen exactamente la misma lógica.
- `index`: lista **solo** los clientes del usuario (`$request->user()->clientes()`), aplica los
  filtros, ordena por `razon_social` ascendente, pagina de 25 en 25 con `->withQueryString()` y
  devuelve la vista completa.
- `buscar`: misma consulta; devuelve **solo** el parcial `clientes/_resultados.blade.php` (filas y
  paginación, sin el encabezado de la tabla donde viven los filtros). La ruta
  de la paginación se fija con `->withPath(route('clientes.index'))`, para que los enlaces de página
  apunten a la página completa (funcionan sin JavaScript y se pueden compartir).
- `create` / `edit`: vista del formulario; `edit` recibe el cliente por route model binding.
- `store` / `update`: guardan los datos validados y redirigen a `clientes.index` con mensaje flash
  de éxito.
- `destroy`: soft delete y redirección con mensaje de éxito.

### Regla de eliminación

- Soft delete simple.
- La regla de negocio acordada es que **no debe permitirse eliminar un cliente con facturas
  timbradas**; como el módulo de facturación no existe, queda documentada en un PHPDoc de
  `ClienteController::destroy()` y se implementará cuando exista la relación `Cliente → facturas`.

### Autorización (`ClientePolicy`)

- Igual que `ProveedorPolicy`: `update` y `delete` solo para el dueño; a los demás
  `Response::denyAsNotFound()` (404). El administrador no tiene excepción.
- `ClienteRequest::authorize()` usa `Gate::inspect('update', $cliente)` en edición (lección de 004:
  el Form Request valida antes que el controlador).

### Validaciones (Form Request único: `ClienteRequest`)

- `prepareForValidation()`:
  - **RFC**: sin espacios y en mayúsculas.
  - **Teléfono**: misma normalización idempotente que proveedores. Para no duplicarla, se extrae a
    un único lugar reutilizable (p. ej. un trait `NormalizaTelefono` en `app/Http/Requests/Concerns/`)
    usado por `ProveedorRequest` y `ClienteRequest`.
- `rules()`:
  - `rfc`: requerido, string, `RfcValido`, único por usuario (sin borrados, ignorando el actual).
  - `razon_social`: requerido, string, max 255.
  - `regimen_fiscal`: requerido, `Rule::enum(RegimenFiscal::class)`.
  - `codigo_postal_fiscal`: requerido, `regex:/^\d{5}$/`.
  - `nombre_comercial`, `nombre_contacto`, `direccion_comercial`: `nullable`, string, max 255.
  - `correo`: `nullable`, email, max 255.
  - `telefono`: `nullable`, `regex:/^\+52\d{10}$/`.
- `messages()`: "RFC duplicado: ya tienes un cliente registrado con ese RFC.", mensaje claro para el
  CP ("El código postal fiscal debe tener 5 dígitos.") y para el teléfono.
- `attributes()`: nombres de los campos en español.

## Vistas (Blade)

Todas extienden `layouts.app` y usan **solo** los componentes de 003. No se escriben `<select>`,
`<input>`, botones, cards ni alertas a mano (lo verifica `EstiloUniformeTest`).

- **Extensión de `<x-campo>`**: se agrega `tipo="select"` con la prop `opciones`
  (`array<valor, texto>`) y `placeholder` opcional, con el mismo marcado de error (`campo-error`,
  `aria-invalid`) que los demás tipos. Se agrega un ejemplo a `/estilos`. Es necesario porque
  `EstiloUniformeTest` prohíbe `<select>` fuera de `resources/views/components/`.
- **`clientes/index.blade.php`** (`/clientes`):
  - Botón "Nuevo cliente" (`bi-plus-lg`).
  - **Formulario de filtros**: `<form id="filtros-clientes" method="GET"
    action="{{ route('clientes.index') }}" data-busqueda-dinamica="{{ route('clientes.buscar') }}">`
    arriba de la tabla, con el botón "Limpiar" (`bi-x-lg`, enlace a `clientes.index`). No hay
    botón "Buscar" visible porque la búsqueda es dinámica; solo se renderiza dentro de
    `<noscript>` como respaldo, ya que sin JavaScript un formulario con varios campos y sin botón
    no se envía con Enter. Los campos de filtro **no** están dentro del
    `<form>` sino en la tabla, asociados con el atributo HTML `form="filtros-clientes"`.
  - **Tabla** `tabla` dentro de `<x-card>`, columnas: razón social, nombre comercial, contacto,
    RFC, régimen (clave), teléfono y acciones.
  - **Filtros por columna**: el `<thead>` tiene dos filas; la primera con los títulos y la segunda
    con un `<x-campo tipo="search" form="filtros-clientes">` bajo cada columna filtrable:
    **Razón social**, **Nombre comercial**, **Contacto** y **RFC** (las columnas régimen, teléfono y
    acciones quedan con la celda vacía). Cada campo se precarga con el filtro actual. La etiqueta de
    `<x-campo>` ("Filtrar por razón social", etc.) se conserva para lectores de pantalla pero se
    oculta visualmente con CSS (`.tabla-filtros .campo label`), porque el título de la columna ya
    la indica. El `<thead>` **no** se reemplaza al buscar, así el campo no pierde el foco ni lo
    escrito.
  - Un `<x-alerta tipo="error" hidden data-busqueda-error>` pre-renderizado ("No se pudo realizar
    la búsqueda. Intenta de nuevo."), que el JavaScript solo muestra u oculta.
- **`clientes/_resultados.blade.php`**: dos piezas con `id` fijo: `<tbody id="clientes-filas">`
  con las filas (acciones "Editar" `bi-pencil` y "Eliminar" `bi-trash` con
  `data-confirmar="¿Eliminar este cliente?"`, y la fila de "sin clientes" / "ningún cliente
  coincide") y `<div id="clientes-paginacion">` con `<x-paginacion>`. `index` lo incluye dentro de
  la tabla y `buscar` lo devuelve solo; así el HTML de las filas existe **en un solo lugar**
  (Blade) y JavaScript nunca las construye.
- **`clientes/crear.blade.php`** y **`clientes/editar.blade.php`**: comparten
  `clientes/_formulario.blade.php`, con dos `<x-card>`:
  - **Datos fiscales**: RFC, razón social, régimen fiscal (`<x-campo tipo="select">` con
    "601 – General de Ley Personas Morales", etc.), código postal fiscal.
  - **Datos comerciales**: nombre comercial, nombre de contacto, correo (`email`), teléfono (`tel`),
    dirección comercial.
  - Botones "Guardar" (`bi-save`) y "Cancelar".
- **Errores de validación** y **mensajes flash**: igual que en 004 (alerta de error arriba del
  formulario; parcial de mensajes en el listado).
- **Menú**: enlace "Clientes" (`bi-people`) en `layouts/app.blade.php`, antes de "Proveedores".

## JavaScript

### Búsqueda dinámica (`public/js/busqueda-dinamica.js`)

Archivo nuevo, cargado solo en `clientes/index` con `@push('scripts')`. Es genérico (se activa por
atributos `data-*`), para que otros listados (p. ej. Proveedores) puedan adoptarlo después sin
reescribirlo.

- Al escribir en cualquiera de los campos asociados al formulario (`formulario.elements`, que
  incluye los que están en la tabla con `form="…"`), espera **300 ms** sin nuevas pulsaciones
  (debounce) y hace `axios.get(<data-busqueda-dinamica>, { params })` con sus valores.
- Si llega una nueva búsqueda antes de que termine la anterior, **cancela** la anterior
  (`AbortController`), para que una respuesta lenta no sobrescriba una más reciente.
- Convierte el HTML recibido en nodos (`<template>`) y reemplaza en la página cada elemento que
  tenga el mismo `id` (`clientes-filas`, `clientes-paginacion`); después actualiza la URL
  de la barra con `history.replaceState` (`/clientes?nombre_comercial=…&rfc=…`), de modo que
  recargar, compartir el enlace o volver con "atrás" conserva la búsqueda.
- Si el formulario llegara a enviarse, evita la recarga y ejecuta la búsqueda de inmediato.
- Los clics en los enlaces de `#clientes-paginacion` se interceptan y se cargan por AJAX contra
  `/clientes/buscar` con la misma query string.
- Mientras carga, marca la tabla con `aria-busy="true"`; `#clientes-filas` lleva
  `aria-live="polite"`.
- Errores: si la respuesta es 401 o 419 (sesión vencida), recarga la página para que Laravel
  redirija al login; cualquier otro error muestra el `<x-alerta>` oculto `data-busqueda-error`.
- **Sin JavaScript** el módulo sigue funcionando: el formulario hace un `GET` normal a
  `/clientes` y la paginación usa enlaces normales.

### Confirmación de eliminación

Se reutiliza el manejador `data-confirmar` de `public/js/app.js` (creado en 004). No se agrega
código.

## Pruebas (Pest)

`tests/Feature/ClienteTest.php`:

- Invitado redirigido al login en `/clientes`; en `/clientes/buscar` con cabeceras AJAX responde
  401; usuario suspendido no entra.
- Crear, listar, editar y eliminar (soft delete, `assertSoftDeleted`).
- Aislamiento: el listado y `/clientes/buscar` no muestran clientes ajenos; editar, actualizar o
  eliminar uno ajeno responde 404 (también con datos inválidos).
- Validaciones: RFC inválido, RFC duplicado del mismo usuario (aceptado para otro usuario y tras
  soft delete), RFC genérico aceptado, régimen fuera del enum rechazado, CP que no sea de 5 dígitos
  rechazado, correo inválido, teléfono normalizado e idempotente.
- `tipo_persona` devuelve física/moral/`null` según el RFC.
- Búsqueda: cada uno de los cuatro filtros por separado y combinados; el RFC se busca sin importar
  mayúsculas; `/clientes/buscar` devuelve el fragmento (sin `<html>` ni `<thead>`) y sus enlaces de
  paginación apuntan a `/clientes` conservando los filtros.
- `user_id` no se puede asignar desde el formulario.
- `ProveedorTest` sigue pasando tras extraer la normalización del teléfono.
- `EstiloUniformeTest` pasa, incluida la vista con `<x-campo tipo="select">`.

## Fuera de alcance

- Emisión/timbrado de CFDI.
- Uso de CFDI (`c_UsoCFDI`): se elige por factura en la historia de facturación.
- Validación del código postal contra el catálogo `c_CodigoPostal` (se difiere a facturación).
- Instalación de `phpcfdi/sat-catalogos` y de la base de catálogos SAT.
- Compatibilidad Régimen Fiscal ↔ Uso de CFDI y Régimen Fiscal ↔ Tipo de persona.
- Validación del RFC contra el webservice del SAT.
- Bloqueo efectivo de eliminación por facturas timbradas (documentado, no implementable aún).
- Pantalla de detalle de cliente.
- Roles/permisos diferenciados, multiempresa, importación/exportación masiva.
- Búsqueda dinámica en Proveedores (el script queda listo para reutilizarse, pero no se aplica en
  esta historia).

## Estado de implementación

Implementada el 2026-09-25.

- **Clase CSS de los filtros**: no se creó `campo-filtro`. `EstiloUniformeTest` rechaza en las
  vistas cualquier clase que empiece con `campo`, así que se estilan con la fila
  `<tr class="tabla-filtros">` (`.tabla-filtros .campo label`).
- **Parciales**: las filas viven en `clientes/_filas.blade.php` (`<tbody id="clientes-filas">`) y
  la paginación en `clientes/_paginacion.blade.php`. `index` los incluye en su lugar y
  `_resultados` (respuesta de `buscar`) envuelve el `<tbody>` en un `<table>`, porque el navegador
  descarta un `<tbody>` suelto al interpretar el HTML en un `<template>`.
- **Atributo `hidden`**: se agregó `[hidden] { display: none !important; }` en `app.css`, porque
  `.alerta` usa `display: flex` y anulaba el `hidden` de la alerta de error de la búsqueda.
- **Usuario suspendido durante una búsqueda**: `AsegurarUsuarioActivo` redirige al login y Axios
  sigue esa redirección de forma transparente. El script compara la URL final de la respuesta con
  la de `/clientes/buscar` y, si difiere, recarga la página en lugar de insertar el HTML del login
  en la tabla.
- **`<x-campo tipo="select">`**: props `opciones` y `vacia` (texto de la opción vacía). Se agregó
  a `/estilos` junto con el icono `people`.
- **Teléfono**: la normalización se movió al trait `App\Http\Requests\Concerns\NormalizaTelefono`,
  usado por `ProveedorRequest` y `ClienteRequest`.
- **Verificación**: la suite Pest pasa (152 tests; 46 del módulo) y Pint no reporta cambios; la
  migración corrió en MySQL; `node --check` valida la sintaxis de `busqueda-dinamica.js`. **No se
  probó la búsqueda dinámica en un navegador real**: conviene abrir `/clientes` y confirmar que al
  escribir en los filtros la tabla cambia sin recargar, sin perder el foco, y que la URL se
  actualiza.

## Criterios de aceptación

1. Un usuario autenticado puede crear un cliente capturando RFC, razón social, régimen fiscal y
   código postal fiscal (obligatorios) y, opcionalmente, nombre comercial, nombre de contacto,
   correo, teléfono y dirección comercial.
2. Un RFC con formato inválido muestra error y no permite guardar.
3. Un RFC ya registrado por el mismo usuario muestra "RFC duplicado"; el mismo RFC se acepta para
   otro usuario o tras eliminar el cliente que lo tenía.
4. El régimen fiscal se elige de una lista cerrada; un valor fuera del catálogo enviado a mano es
   rechazado.
5. Un código postal fiscal que no sea de 5 dígitos muestra error de validación.
6. `/clientes` muestra solo los clientes del usuario, paginados de 25 en 25.
7. La tabla tiene un campo de búsqueda debajo del título de cada columna filtrable: razón social,
   nombre comercial, contacto y RFC. Al escribir en cualquiera, las filas se actualizan **sin
   recargar la página** y sin perder el foco del campo, y los filtros se combinan.
8. La URL refleja los filtros: al recargar o compartir el enlace se ve el mismo resultado, y la
   paginación conserva los filtros.
9. No hay botón "Buscar" visible; sin JavaScript aparece como respaldo y la búsqueda funciona
   con recarga normal.
10. Editar un cliente permite modificar cualquier campo y persiste los cambios.
11. Eliminar pide confirmación y hace soft delete.
12. Editar, actualizar o eliminar un cliente ajeno responde 404.
13. El menú muestra "Clientes" con su icono.
14. Pint corre sin cambios y la suite Pest pasa, incluidas `EstiloUniformeTest` y `ProveedorTest`.

## Supuestos asumidos (registro completo)

1. "Cliente" es una entidad propia del usuario (no compartida ni multiempresa).
2. El módulo solo gestiona datos del cliente; no emite facturas.
3. Campos fiscales obligatorios: RFC, razón social, régimen fiscal, código postal fiscal. Uso de
   CFDI excluido.
4. **(Redefinido)** Campos comerciales opcionales: nombre comercial, **nombre de contacto**,
   correo, teléfono, dirección comercial. Sin notas internas.
5. El RFC es único por usuario, no global; se puede reutilizar tras un soft delete.
6. Se permiten los RFC genéricos (`XAXX010101000`, `XEXX010101000`), sujetos a la misma regla de
   unicidad (un solo cliente "público en general" por usuario).
7. El régimen fiscal se elige de un catálogo cerrado (19 claves vigentes de `c_RegimenFiscal`),
   mantenido como enum en el código.
8. **(Redefinido)** El código postal fiscal se valida solo por formato (5 dígitos) en esta
   historia; la validación contra `c_CodigoPostal` se difiere a facturación.
9. Eliminar es soft delete; la restricción por facturas timbradas queda documentada.
10. Sin roles diferenciados: cada usuario, incluido el administrador, gestiona solo sus clientes.
11. El RFC se valida solo por estructura (`phpcfdi/rfc`), no contra el SAT.
12. Tipo de persona inferido del RFC, no capturado.
13. **(Nuevo)** La búsqueda usa **cuatro campos independientes, uno en el encabezado de cada
    columna** (razón social, nombre comercial, nombre de contacto, RFC), combinados con Y y con
    coincidencia parcial.
14. **(Nuevo)** La búsqueda dinámica se dispara 300 ms después de dejar de escribir, sin mínimo de
    caracteres.
15. **(Nuevo)** El teléfono se normaliza a `+52` + 10 dígitos, igual que en proveedores.
16. El listado se ordena por razón social ascendente y se pagina de 25 en 25.
17. Tras crear, editar o eliminar se vuelve al listado con mensaje de éxito.
18. Iconos: `bi-people` (menú), `bi-plus-lg`, `bi-search`, `bi-x-lg`, `bi-pencil`, `bi-trash`,
    `bi-save`.
