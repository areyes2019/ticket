# Spec: Gestión de proveedores

## Historia de usuario

Como usuario del sistema de facturación, quiero administrar (crear, ver, editar y eliminar) los
datos de mis proveedores, capturando nombre comercial, nombre de contacto, correo, teléfono y RFC,
para tener un directorio de proveedores listo para usarse cuando integre los módulos futuros de
Artículos y Órdenes de compra.

## Objetivo / Alcance

Implementar un módulo CRUD de proveedores sobre la arquitectura monolítica Laravel + Blade +
JavaScript nativo definida en [001-inicio-proyecto.md](001-inicio-proyecto.md), con la
autenticación por sesión web de [002-login.md](002-login.md) y los componentes Blade del estilo
uniforme de [003-estilo-uniforme.md](003-estilo-uniforme.md).

- Laravel resuelve el módulo completo: rutas web, controlador, validación, autorización y vistas
  Blade.
- No se crea API REST, no se usa Sanctum ni API Resources, y no hay stores ni estado en el
  frontend.
- JavaScript se limita a la confirmación antes de eliminar (ver sección "JavaScript").

Incluye una preparación mínima (campo `tiene_ordenes_activas`) para la futura relación con Órdenes
de compra, pero **no** incluye la implementación de los módulos de Artículos u Órdenes de compra en
sí.

## Backend (Laravel)

### Modelo y base de datos

- **Modelo `Proveedor`**, perteneciente a un `User` (`user_id`), con **soft deletes** habilitado
  (`SoftDeletes` trait).
- Relación `User::proveedores()` (`hasMany`) y `Proveedor::user()` (`belongsTo`).
- `user_id` **no** está en `fillable`: el alta se hace siempre mediante
  `$request->user()->proveedores()->create($datos)`, para que nadie pueda asignar un proveedor a
  otro usuario manipulando el formulario.
- **Campos**:
  - `nombre_comercial`: string, **obligatorio**.
  - `nombre_contacto`: string, opcional.
  - `correo`: string, opcional; si se captura debe tener formato de email válido.
  - `telefono`: string, opcional; almacenado normalizado en formato E.164 mexicano
    (`+52` + 10 dígitos), para que quede listo para una futura integración con la API de WhatsApp
    Business. La normalización es manual (prefijo `+52` fijo + validación de 10 dígitos), sin
    librería externa de parseo telefónico.
  - `rfc`: string, opcional. Validado con la librería `phpcfdi/rfc` en cuanto a formato/estructura
    (persona física 13 caracteres, persona moral 12 caracteres; no se valida contra el webservice
    del SAT). Se guarda en mayúsculas y sin espacios. **Único por usuario** (a nivel de aplicación,
    con `Rule::unique('proveedores','rfc')->where('user_id', ...)->whereNull('deleted_at')`, para
    permitir reutilizar el RFC tras un soft delete).
  - `tiene_ordenes_activas`: boolean, `default: false`. Campo preparatorio para la futura relación
    con el módulo de Órdenes de compra; **no se expone en el formulario** en esta historia (solo
    existe en modelo/BD). Cuando exista el módulo de Órdenes de compra, una migración lo
    reemplazará por un dato calculado a partir de las órdenes.
- **Migración** `proveedores`:
  - `user_id` como `foreignId()->constrained()`.
  - `softDeletes()`.
  - Índice compuesto `(user_id, rfc)` para acelerar la validación de unicidad y el listado.
  - La unicidad del RFC **no** se impone en MySQL: un índice único no puede excluir los registros
    borrados lógicamente (`deleted_at` nulo), así que se valida en la aplicación.
- **Factory** `ProveedorFactory`, con un estado `conOrdenesActivas()` para las pruebas del bloqueo
  de eliminación.

### Dependencias

- Se instala `phpcfdi/rfc` con Composer (PHP puro, sin npm, compatible con 001).
- Se crea la regla de validación `App\Rules\RfcValido`, que usa `phpcfdi/rfc`. Queda disponible
  para que el futuro módulo de Clientes la reutilice.

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]` (el mismo de
`/dashboard` e `/historial-accesos`):

```php
Route::resource('proveedores', ProveedorController::class)
    ->except('show')
    ->parameters(['proveedores' => 'proveedor']);
```

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/proveedores` | `index` — listado paginado con `?buscar=` | `proveedores.index` |
| GET | `/proveedores/crear` | `create` — formulario de alta | `proveedores.create` |
| POST | `/proveedores` | `store` — alta | `proveedores.store` |
| GET | `/proveedores/{proveedor}/editar` | `edit` — formulario de edición | `proveedores.edit` |
| PUT | `/proveedores/{proveedor}` | `update` — edición | `proveedores.update` |
| DELETE | `/proveedores/{proveedor}` | `destroy` — borrado lógico | `proveedores.destroy` |

- Las URLs en español (`crear`, `editar`) se obtienen con
  `Route::resourceVerbs(['create' => 'crear', 'edit' => 'editar'])` en `AppServiceProvider::boot()`,
  consistente con las rutas de 002 (`/registro`, `/olvide-contrasena`). Aplica también a los
  futuros módulos.
- No hay pantalla de detalle, así que no existe la ruta `show`.

### Controlador (`ProveedorController`)

- `index`: lista **solo** los proveedores del usuario autenticado
  (`$request->user()->proveedores()`), con búsqueda por `nombre_comercial` o `nombre_contacto`,
  ordenados por `nombre_comercial` ascendente, paginados de 25 en 25 con `->withQueryString()`
  para conservar la búsqueda al cambiar de página.
- `create` / `edit`: devuelven la vista del formulario; `edit` recibe el proveedor por route model
  binding.
- `store` / `update`: guardan con los datos validados del Form Request y redirigen a
  `proveedores.index` con un mensaje de éxito en la sesión flash.
- `destroy`: aplica la regla de eliminación (ver abajo).
- Todas las respuestas son vistas Blade o redirecciones; no se devuelve JSON.

### Regla de eliminación

- Si `tiene_ordenes_activas` es `true`, **no** se elimina el registro y se redirige a
  `proveedores.index` con el mensaje de error "No se puede eliminar: tiene órdenes de compra
  activas" en la sesión flash.
- Si es `false`, procede con soft delete normal y redirige al listado con un mensaje de éxito.
- Como el módulo de Órdenes de compra no existe todavía y el campo no es editable desde la UI, en la
  práctica siempre será `false` en esta historia; la validación queda lista para cuando exista
  dicho módulo y se prueba con la factory `conOrdenesActivas()`.

### Autorización (`ProveedorPolicy`)

- `update` y `delete`: permitidas solo al dueño del proveedor (`$proveedor->user_id === $user->id`).
- Si el proveedor pertenece a otro usuario se responde **404** (`Response::denyAsNotFound()`), no
  403, para no revelar que el registro existe.
- El administrador (rol de 002) no tiene excepción: también gestiona solo sus propios proveedores.
- `edit` usa la misma autorización que `update`.

### Validaciones (Form Request único: `ProveedorRequest`)

Se usa una sola clase para alta y edición, para que la normalización no quede duplicada.

- `prepareForValidation()`:
  - **Teléfono**: se eliminan los caracteres que no sean dígitos; si quedan 12 dígitos que empiezan
    con `52`, se quita ese prefijo; si quedan 10 dígitos, se guarda como `+52XXXXXXXXXX`. La
    normalización debe ser **idempotente**: normalizar un teléfono ya guardado (`+524491234567`)
    produce el mismo valor.
  - **RFC**: se quitan los espacios y se convierte a mayúsculas.
- `rules()`:
  - `nombre_comercial`: requerido, string.
  - `nombre_contacto`: opcional (`nullable`), string.
  - `correo`: opcional (`nullable`), formato de email válido si se envía.
  - `telefono`: opcional (`nullable`); si se envía, debe reducirse a 10 dígitos numéricos válidos
    (queda normalizado como `+52XXXXXXXXXX`).
  - `rfc`: opcional (`nullable`); si se envía, `RfcValido` y
    `Rule::unique('proveedores','rfc')->where('user_id', ...)->whereNull('deleted_at')`, ignorando
    el proveedor actual en la edición. Laravel omite estas reglas cuando el valor es `null`, así que
    varios proveedores del mismo usuario pueden coexistir sin RFC.
- `attributes()`: nombres de los campos en español para los mensajes de error.
- `tiene_ordenes_activas` no forma parte de las reglas, así que no puede enviarse desde el
  formulario.

## Vistas (Blade)

Todas las vistas extienden `layouts.app` y usan **solo** los componentes de 003 (`<x-boton>`,
`<x-card>`, `<x-alerta>`, `<x-campo>`, `<x-icono>`). No se escriben botones, cards, alertas ni
campos a mano (lo verifica `EstiloUniformeTest`).

- **`proveedores/index.blade.php`** (`/proveedores`):
  - Botón "Nuevo proveedor" (`bi-plus-lg`) hacia `proveedores.create`.
  - Formulario `GET` de búsqueda con `<x-campo nombre="buscar">` y botón "Buscar" (`bi-search`).
    La búsqueda no usa AJAX: el resultado queda en la URL y funcionan el botón "atrás" y los
    enlaces compartidos.
  - Tabla con la clase `tabla` dentro de `<x-card>`, con el mismo patrón que el historial de
    accesos: nombre comercial, nombre de contacto, correo, teléfono, RFC y acciones.
  - Acciones por fila: "Editar" (`bi-pencil`), que es un enlace a `proveedores.edit`, y "Eliminar"
    (`bi-trash`), que es un `<form method="POST">` con `@csrf`, `@method('DELETE')` y un
    `<x-boton>` con el atributo `data-confirmar` (ver "JavaScript").
  - Mensaje cuando no hay proveedores (o cuando la búsqueda no encuentra resultados).
  - Paginación con el componente `<x-paginacion>`.
- **`proveedores/crear.blade.php`** y **`proveedores/editar.blade.php`**: comparten el parcial
  `proveedores/_formulario.blade.php`, con un único `<x-card>` y los campos:
  - nombre comercial (obligatorio),
  - nombre de contacto,
  - correo (`tipo="email"`),
  - teléfono (`tipo="tel"`),
  - RFC.

  En edición los campos se precargan con los valores guardados; el teléfono se muestra tal como está
  guardado (`+52XXXXXXXXXX`). El campo `tiene_ordenes_activas` **no** aparece en el formulario.
  Botones "Guardar" (`bi-save`) y "Cancelar" (enlace de vuelta al listado).
- **Errores de validación**: siguiendo la convención de 003, el texto de los errores se muestra en
  un `<x-alerta tipo="error">` arriba del formulario y cada campo con error queda marcado por
  `<x-campo>` (borde rojo y `aria-invalid`).
- **Mensajes flash**: un parcial muestra `session('exito')` con `<x-alerta tipo="exito">` y
  `session('error')` con `<x-alerta tipo="error">` en el listado.
- **Componente `<x-paginacion>`**: se extrae a `resources/views/components/paginacion.blade.php` el
  bloque de paginación que hoy está escrito en `historial-accesos/index.blade.php`, y ambas vistas
  lo usan. Se agrega a la página `/estilos`.
- **Menú**: enlace "Proveedores" (`bi-truck`) en `layouts/app.blade.php`, dentro de `@auth`,
  después de "Dashboard".

## JavaScript

Es el único JavaScript del módulo y no usa Axios.

- En `public/js/app.js` se agrega un manejador genérico: todo formulario cuyo botón de envío tenga
  el atributo `data-confirmar="…"` muestra `confirm()` con ese texto antes de enviarse, y se cancela
  el envío si el usuario no confirma.
- El botón "Eliminar" usa `data-confirmar="¿Eliminar este proveedor?"`.
- El manejador queda disponible para futuros módulos (por ejemplo, Clientes).
- Sin JavaScript, el formulario se envía igualmente; la regla de eliminación se aplica en el
  servidor.

## Pruebas (Pest)

Pruebas de feature en `tests/Feature/ProveedorTest.php`:

- Un invitado que abre `/proveedores` es redirigido al login; un usuario suspendido no puede entrar.
- Crear, listar, editar y eliminar un proveedor.
- Aislamiento entre usuarios: el listado no muestra proveedores ajenos, y editar, actualizar o
  eliminar uno ajeno responde 404.
- Validaciones de los criterios 2 a 6.
- El teléfono se guarda como `+52XXXXXXXXXX`, y editar sin modificar el teléfono ya normalizado no
  lo daña (idempotencia).
- El RFC se guarda en mayúsculas; un RFC duplicado del mismo usuario es rechazado; el mismo RFC se
  acepta para otro usuario y tras un soft delete.
- La eliminación con `tiene_ordenes_activas = true` (factory `conOrdenesActivas()`) no elimina el
  registro y deja el mensaje de error en la sesión.
- La eliminación normal es un soft delete (`assertSoftDeleted`).
- `tiene_ordenes_activas` no se puede modificar enviándolo en el formulario.
- La búsqueda filtra por nombre comercial o nombre de contacto y la paginación conserva el término
  buscado.
- La revisión automática de 003 (`EstiloUniformeTest`) sigue pasando.

## Fuera de alcance

- Módulos de "Artículos" y "Órdenes de compra": no se implementan en esta historia; solo se deja
  preparado el campo `tiene_ordenes_activas` en el modelo `Proveedor` para cuando existan.
- Integración real con la API de WhatsApp Business (envío de mensajes, botón/link `wa.me`, etc.):
  se difiere a una historia futura; en esta solo se garantiza que el teléfono quede almacenado en
  formato E.164 compatible.
- Validación del RFC contra el webservice real del SAT (solo se valida formato/estructura).
- Datos fiscales adicionales del proveedor (razón social, régimen fiscal, dirección fiscal, código
  postal): no se incluyen en esta historia, solo el RFC.
- Permisos diferenciados por rol: los roles de 002 existen, pero no dan acceso distinto a
  proveedores (cada usuario, incluido el administrador, gestiona solo los suyos).
- Pantalla de detalle de un proveedor.
- Búsqueda "mientras escribes" con AJAX.
- Multiempresa o proveedores compartidos entre usuarios.
- Importación/exportación masiva de proveedores (ej. CSV).

## Estado de implementación

Implementada el 2026-09-25.

- **Autorización en `ProveedorRequest::authorize()`**: en `update`, Laravel valida el Form Request
  antes de ejecutar el controlador, así que una llamada a `Gate::authorize()` dentro del controlador
  llegaba tarde: un proveedor ajeno enviado con datos inválidos recibía errores de validación en vez
  de 404. Por eso el Request devuelve `Gate::inspect('update', $proveedor)` (un `Response`, que
  conserva el 404 de `denyAsNotFound()`). `edit` y `destroy` autorizan en el controlador.
- **Teléfono inválido**: si tras quitar los no-dígitos no quedan 10 dígitos, el valor se deja como
  lo escribió el usuario para que lo vea en el formulario junto con el error.
- **Paginación**: `historial-accesos/index.blade.php` ahora usa `<x-paginacion>`, que también
  aparece en `/estilos`.
- **Verificación**: la suite Pest (105 tests; 29 del módulo) pasa y Pint no reporta cambios. Se
  comprobó con `php artisan serve` que `/estilos` muestra la paginación y que `/proveedores` redirige
  al login sin sesión. **No se revisó la UI visualmente en un navegador**. Además, el vhost de Apache
  de Laragon (`ticket_factura.test`) responde 404 en todas las rutas salvo `/` (incluida `/login`),
  un problema previo de configuración ajeno a este módulo.

## Notas para la implementación

Lecciones de una implementación anterior de este módulo que siguen aplicando:

- **Nombre de tabla explícito en el modelo**: `Proveedor` requiere `protected $table = 'proveedores'`
  porque la pluralización automática de Eloquent convierte "Proveedor" en "Proveedors" (no conoce la
  regla española "-es"). Por el mismo motivo, `Route::resource('proveedores', ...)` necesita
  `->parameters(['proveedores' => 'proveedor'])`: sin ese ajuste, Laravel infiere el parámetro de
  ruta como `{proveedore}`, lo que rompe el route model binding y el `ignore()` de la regla de
  unicidad del RFC al editar.
- **Normalización de teléfono idempotente**: si la normalización solo limpia los no-dígitos y
  antepone `+52`, al editar un proveedor sin tocar el teléfono el valor ya guardado
  (`+524491234567`) se convierte en `+52524491234567` y falla la validación. Por eso, cuando quedan
  12 dígitos que empiezan con `52`, hay que quitar ese prefijo antes de volver a anteponer `+52`.
- **Default de `tiene_ordenes_activas` en el modelo**: Eloquent no recarga en memoria los valores
  que vienen de un default de columna tras un `INSERT`, así que después de `create()` el atributo
  queda `null` en vez de `false`. Se evita con `protected $attributes = ['tiene_ordenes_activas' =>
  false]` en el modelo.
- **RFC opcional**: `nullable` junto con `RfcValido` y `Rule::unique(...)->whereNull('deleted_at')`;
  Laravel omite las reglas de formato y unicidad cuando el valor es `null`.

## Criterios de aceptación

1. Un usuario autenticado puede crear un proveedor capturando nombre comercial (obligatorio) y,
   opcionalmente, nombre de contacto, correo, teléfono y RFC.
2. Omitir el nombre comercial muestra un error de validación y no permite guardar.
3. Capturar un correo con formato inválido muestra un error de validación y no permite guardar.
4. Capturar un teléfono que no se reduzca a 10 dígitos válidos muestra un error de validación; un
   teléfono válido se guarda normalizado en formato `+52XXXXXXXXXX`.
5. Capturar un RFC con formato inválido muestra un error de validación y no permite guardar.
6. Capturar un RFC ya registrado por el mismo usuario muestra un error de "RFC duplicado"; el
   mismo RFC sí puede registrarse por un usuario distinto o reutilizarse tras eliminar (soft
   delete) el proveedor que lo tenía.
7. El listado `/proveedores` muestra los proveedores del usuario autenticado (no los de otros
   usuarios), paginados, y la búsqueda filtra por nombre comercial o nombre de contacto.
8. Editar un proveedor existente permite modificar cualquier campo visible en el formulario y
   persiste los cambios.
9. Eliminar un proveedor con `tiene_ordenes_activas = false` pide confirmación, lo remueve del
   listado (soft delete) y no lo borra físicamente de la base de datos.
10. Intentar eliminar un proveedor con `tiene_ordenes_activas = true` no lo elimina y muestra el
    mensaje "No se puede eliminar: tiene órdenes de compra activas" (no verificable desde la UI en
    esta historia porque el campo no es editable, pero sí con pruebas).
11. Pint corre sin cambios y la suite Pest pasa, incluida `EstiloUniformeTest`.
12. Intentar editar, actualizar o eliminar un proveedor de otro usuario responde 404.
13. Un invitado es redirigido al login y un usuario suspendido no puede acceder a `/proveedores`.
14. El menú muestra el enlace "Proveedores" con su icono a los usuarios autenticados.

## Supuestos asumidos (registro completo)

1. "Proveedor" es una entidad propia del usuario dueño de la cuenta (no compartida entre usuarios
   ni multiempresa por ahora).
2. **(Redefinido)** Además de nombre comercial, nombre de contacto, correo y teléfono, se agrega el
   campo **RFC** (opcional, validado con `phpcfdi/rfc`); no se agregan otros datos fiscales (razón
   social, régimen fiscal, dirección fiscal).
3. Único campo obligatorio: nombre comercial. Nombre de contacto, correo, teléfono y RFC son
   opcionales.
4. El correo, si se captura, debe tener formato de email válido; si se deja vacío no se valida.
5. **(Redefinido)** El teléfono se normaliza y almacena en formato E.164 mexicano (`+52` + 10
   dígitos), de forma manual (sin librería de parseo telefónico), para dejarlo listo para una
   futura integración con la API de WhatsApp Business (que no se implementa en esta historia).
6. **(Redefinido)** Solo el RFC es único por usuario; nombre comercial, correo y teléfono pueden
   repetirse entre proveedores del mismo usuario.
7. **(Redefinido)** "Eliminar" un proveedor es borrado lógico (soft delete); adicionalmente, se
   bloquea (con un mensaje específico) si el proveedor tiene `tiene_ordenes_activas = true`.
8. **(Redefinido, fusionado con #7)** No existe todavía el módulo de Órdenes de compra, por lo que
   se agrega un campo preparatorio `tiene_ordenes_activas` (boolean, default `false`) en el modelo,
   sin exponerlo en el formulario, para dejar lista la validación de eliminación hasta que dicho
   módulo exista.
9. Existe una pantalla de listado de proveedores con búsqueda (por nombre comercial o nombre de
   contacto) y paginación.
10. **(Redefinido)** Existen los roles `usuario` y `administrador` (002), pero no dan acceso
    diferenciado a proveedores: cualquier usuario autenticado, incluido el administrador, gestiona
    solo sus propios proveedores.
11. No hay multiempresa ni proveedores compartidos entre usuarios.
12. No se incluye importación/exportación masiva (ej. CSV) de proveedores.
13. La validación del RFC es solo de formato (estructura/longitud, vía `phpcfdi/rfc`), no contra el
    webservice real del SAT.
14. No se construye ningún botón o link de envío de WhatsApp (ej. `wa.me`) en esta historia; solo
    se garantiza que el teléfono quede en un formato compatible para cuando se integre la API real
    de WhatsApp Business.
15. No hay pantalla de detalle de un proveedor; el listado y el formulario de edición son
    suficientes.
16. Abrir, editar o eliminar un proveedor de otro usuario responde 404 (no 403), para no revelar que
    el registro existe.
17. `tiene_ordenes_activas` se mantiene como columna booleana en esta historia; el módulo de Órdenes
    de compra la reemplazará por un dato calculado.
18. El RFC se guarda en mayúsculas y sin espacios.
19. En edición, el teléfono se muestra tal como está guardado (`+52XXXXXXXXXX`).
20. Iconos del módulo: `bi-truck` (menú), `bi-plus-lg` (nuevo), `bi-search` (buscar), `bi-pencil`
    (editar), `bi-trash` (eliminar) y `bi-save` (guardar).
21. El listado se pagina de 25 en 25 y se ordena por nombre comercial ascendente.
22. Tras crear, editar o eliminar se vuelve al listado con un mensaje de éxito (o de error, si la
    eliminación está bloqueada).
