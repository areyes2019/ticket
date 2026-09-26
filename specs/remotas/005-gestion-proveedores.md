# Spec: Gestión de proveedores

## Historia de usuario

Como usuario del sistema de facturación, quiero administrar (crear, ver, editar y eliminar) los
datos de mis proveedores, capturando nombre comercial, nombre de contacto, correo, teléfono y RFC,
para tener un directorio de proveedores listo para usarse cuando integre los módulos futuros de
Artículos y Órdenes de compra.

## Objetivo / Alcance

Implementar un módulo CRUD de proveedores sobre la base ya existente de Laravel API + Vue 3 SPA +
Sanctum (ver [001-inicio-proyecto.md](001-inicio-proyecto.md),
[002-login-auth.md](002-login-auth.md)), el design system de
[003-design-system-tailwind.md](003-design-system-tailwind.md) y siguiendo el mismo patrón de
implementación que [004-gestion-clientes.md](004-gestion-clientes.md). Incluye una preparación
mínima (campo `tiene_ordenes_activas`) para la futura relación con Órdenes de compra, pero **no**
incluye la implementación de los módulos de Artículos u Órdenes de compra en sí.

## Backend (Laravel)

- **Modelo `Proveedor`**, perteneciente a un `User` (`user_id`), con **soft deletes** habilitado
  (`SoftDeletes` trait).
- **Campos**:
  - `nombre_comercial`: string, **obligatorio**.
  - `nombre_contacto`: string, opcional.
  - `correo`: string, opcional; si se captura debe tener formato de email válido.
  - `telefono`: string, opcional; almacenado normalizado en formato E.164 mexicano
    (`+52` + 10 dígitos), para que quede listo para una futura integración con la API de WhatsApp
    Business. La normalización es manual (prefijo `+52` fijo + validación de 10 dígitos), sin
    librería externa de parseo telefónico.
  - `rfc`: string, opcional. Validado con la librería `phpcfdi/rfc` (misma que usa `Cliente`) en
    cuanto a formato/estructura (persona física 13 caracteres, persona moral 12 caracteres; no se
    valida contra el webservice del SAT). **Único por usuario** (a nivel de aplicación, con
    `Rule::unique('proveedores','rfc')->where('user_id', ...)->whereNull('deleted_at')`, igual que
    en Cliente, para permitir reutilizar el RFC tras un soft delete).
  - `tiene_ordenes_activas`: boolean, `default: false`. Campo preparatorio para la futura relación
    con el módulo de Órdenes de compra; **no se expone en el formulario del frontend** en esta
    historia (solo existe en modelo/BD).
- **Endpoints** (bajo `auth:sanctum`, scopeados al usuario autenticado):
  - `GET /api/v1/proveedores` — listado paginado, con `?search=` (por `nombre_comercial` o
    `nombre_contacto`).
  - `POST /api/v1/proveedores` — alta.
  - `GET /api/v1/proveedores/{id}` — detalle.
  - `PUT /api/v1/proveedores/{id}` — edición.
  - `DELETE /api/v1/proveedores/{id}` — borrado lógico (soft delete), con validación previa (ver
    regla de eliminación abajo).
- **Regla de eliminación**: si `tiene_ordenes_activas` es `true`, el `DELETE` responde `409
  Conflict` con un mensaje específico ("No se puede eliminar: tiene órdenes de compra activas") y
  no elimina el registro. Si es `false`, procede con soft delete normal. Como el módulo de Órdenes
  de compra no existe todavía y el campo no es editable desde la UI, en la práctica siempre será
  `false` en esta historia — la validación queda lista para cuando exista dicho módulo.
- **Validaciones** (Form Requests):
  - `nombre_comercial`: requerido, string.
  - `nombre_contacto`: opcional, string.
  - `correo`: opcional, formato de email válido si se envía.
  - `telefono`: opcional; si se envía, debe reducirse a 10 dígitos numéricos válidos (se normaliza
    a `+52XXXXXXXXXX` al guardar).
  - `rfc`: opcional; si se envía, formato válido (regex + longitud según tipo de persona o
    genérico vía `phpcfdi/rfc`), único por usuario.
- Respuestas mediante Laravel API Resources (`ProveedorResource`), consistente con la convención
  de 001/004.

## Frontend (Vue 3)

- **`/proveedores`** (protegida): listado paginado de proveedores en tabla (componente `Table` ya
  agregado en 004), con buscador (nombre comercial/nombre de contacto).
- **`/proveedores/crear`**: formulario de alta con nombre comercial (obligatorio), nombre de
  contacto, correo, teléfono y RFC (opcionales). El campo `tiene_ordenes_activas` **no** aparece en
  el formulario.
- **`/proveedores/:id/editar`**: mismo formulario, precargado, para edición.
- Confirmación (modal `Dialog`, ya existente en 003) antes de eliminar un proveedor. Si el backend
  responde `409` por tener órdenes de compra activas, se muestra el mensaje de error específico en
  vez de cerrar el modal como éxito.
- Mensajes de error de validación (ej. correo con formato inválido, RFC con formato inválido,
  teléfono con longitud inválida) mostrados por campo, usando los componentes `Input`/`Alert` ya
  definidos en 003.
- Enlace a `/proveedores` agregado a la navegación del `AppLayout` (junto al de "Clientes",
  agregado en 004).

## Fuera de alcance

- Módulos de "Artículos" y "Órdenes de compra": no se implementan en esta historia; solo se deja
  preparado el campo `tiene_ordenes_activas` en el modelo `Proveedor` para cuando existan.
- Integración real con la API de WhatsApp Business (envío de mensajes, botón/link `wa.me`, etc.):
  se difiere a una historia futura; en esta solo se garantiza que el teléfono quede almacenado en
  formato E.164 compatible.
- Validación del RFC contra el webservice real del SAT (solo se valida formato/estructura).
- Datos fiscales adicionales del proveedor (razón social, régimen fiscal, dirección fiscal, código
  postal): no se incluyen en esta historia, solo el RFC.
- Roles/permisos diferenciados (cualquier usuario autenticado gestiona solo sus propios
  proveedores).
- Multiempresa o proveedores compartidos entre usuarios.
- Importación/exportación masiva de proveedores (ej. CSV).

## Estado de implementación

Implementada el 2026-07-31.

- **Nombre de tabla explícito en el modelo**: `Proveedor` requiere `protected $table = 'proveedores'`
  porque la pluralización automática de Eloquent convierte "Proveedor" en "Proveedors" (no conoce la
  regla española "-es"). Por el mismo motivo, `Route::apiResource('proveedores', ...)` necesitó
  `->parameters(['proveedores' => 'proveedor'])`: sin ese ajuste, Laravel infiere el parámetro de ruta
  como `{proveedore}` (singularización incorrecta), lo que rompía el binding implícito y el `ignore()`
  de la regla de unicidad del RFC al editar. `Cliente`/`clientes` no tuvo este problema porque la
  pluralización en inglés de "Cliente" coincide por accidente con el nombre de tabla en español.
- **Normalización de teléfono (idempotencia)**: la normalización a `+52XXXXXXXXXX` ocurre en
  `prepareForValidation()` de ambos Form Requests (mismo lugar donde `Cliente` normaliza el RFC).
  Se detectó y corrigió un bug real durante la verificación: al editar un proveedor sin tocar el
  campo teléfono, el valor ya normalizado que devuelve la API (`+524491234567`) se volvía a limpiar
  de no-dígitos y se le anteponía `+52` de nuevo, duplicando el código de país y rompiendo la
  validación (`+52524491234567`). Se corrigió detectando el prefijo `52` en los primeros dígitos
  (cuando el resultado tiene 12 dígitos) y quitándolo antes de reanteponer `+52`, lo que hace la
  normalización idempotente. Cubierto por el test "editar sin modificar el telefono ya normalizado
  no lo daña".
- **Default de `tiene_ordenes_activas` en el modelo**: se detectó que, tras `create()`, el recurso
  devolvía `tiene_ordenes_activas: null` en vez de `false` en la respuesta HTTP inmediata (aunque la
  columna sí tenía `0` en la base de datos), porque Eloquent no repuebla en memoria los valores que
  vienen de un default de columna a nivel de base de datos tras un `INSERT`. Se corrigió agregando
  `protected $attributes = ['tiene_ordenes_activas' => false]` al modelo `Proveedor`, que fija el
  valor por defecto también en PHP. Cubierto por una aserción adicional en el test de creación.
- **RFC opcional**: a diferencia de `Cliente` (RFC obligatorio), aquí `rfc` usa `nullable` junto con
  `RfcValido` (reutilizada tal cual de `App\Rules`) y `Rule::unique(...)->whereNull('deleted_at')`;
  Laravel omite las reglas de formato/unicidad cuando el valor es `null`, así que varios proveedores
  del mismo usuario pueden coexistir sin RFC.
- **No se reutilizan `RegimenFiscalSelect.vue` ni `CodigoPostalCombobox.vue`**: el spec de Proveedor no
  incluye régimen fiscal ni código postal, así que el formulario usa un único `Card` con `Input`s
  simples (a diferencia del formulario de Cliente, que separa datos fiscales/comerciales en dos
  `Card`s).
- **Eliminación con `tiene_ordenes_activas = true`**: implementada con `abort_if(...)` en el
  controller devolviendo `409` con el mensaje exacto del spec. La vista de listado (a diferencia de
  la de `Cliente`, que siempre elimina con éxito) captura el error dentro del propio diálogo de
  confirmación (`errorEliminar`, mostrado con `Alert`) en vez de cerrarlo como si fuera éxito;
  cubierto por el test de backend, no verificable end-to-end en el navegador en esta historia porque
  el campo no es editable desde la UI (tal como anticipaba el criterio de aceptación 10 del spec).
- **`tiene_ordenes_activas` dejó de ser una columna el 2026-08-05**: al implementar
  [Órdenes de compra](012-ordenes-compra.md), la columna se eliminó y el dato pasó a **derivarse por
  consulta** (existe al menos una orden del proveedor en estado distinto de `recibida`). El campo que
  expone `ProveedorResource` y el `409` del `DELETE` con su mensaje no cambiaron; lo descrito arriba
  sobre el default en el modelo y la columna en base de datos ya no aplica.
- **Verificación end-to-end**: la suite Pest (17 tests del módulo Proveedores, 37 en total del
  proyecto) pasa. Se corrieron además `php artisan serve` y `npm run dev` reales contra MySQL, y se
  probó el flujo HTTP completo (login vía cookie de sesión + CSRF de Sanctum, crear con teléfono sin
  formato normalizado, listar con búsqueda, editar, eliminar con soft delete) contra
  `POST/GET/PUT/DELETE /api/v1/proveedores`. Se confirmó que Vite sirve y transforma sin errores
  `ProveedoresListView.vue`, `ProveedorFormView.vue` y `stores/proveedores.ts`. `vue-tsc`, ESLint y
  Prettier corren limpios, y Pint no reportó cambios de estilo. **No se pudo verificar visualmente la
  UI en un navegador real** (mismo entorno Windows sin herramienta de navegador headless que en 004)
  — se recomienda abrir `/proveedores` manualmente para confirmar tabla, formulario y el diálogo de
  confirmación de borrado (incluyendo el caso de error 409) antes de dar la funcionalidad por
  completamente probada visualmente.

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
9. Eliminar un proveedor con `tiene_ordenes_activas = false` lo remueve del listado (soft delete)
   pero no lo borra físicamente de la base de datos.
10. Intentar eliminar un proveedor con `tiene_ordenes_activas = true` responde `409` con un mensaje
    específico y no lo elimina (no verificable end-to-end en esta historia porque el campo no es
    editable desde la UI, pero sí a nivel de backend/tests).
11. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

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
   bloquea (409 con mensaje específico) si el proveedor tiene `tiene_ordenes_activas = true`.
8. **(Redefinido, fusionado con #7)** No existe todavía el módulo de Órdenes de compra, por lo que
   se agrega un campo preparatorio `tiene_ordenes_activas` (boolean, default `false`) en el modelo,
   sin exponerlo en el formulario del frontend, para dejar lista la validación de eliminación hasta
   que dicho módulo exista.
9. Existe una pantalla de listado de proveedores con búsqueda (por nombre comercial o nombre de
   contacto) y paginación.
10. No hay roles ni permisos diferenciados todavía (cualquier usuario autenticado gestiona solo sus
    propios proveedores), consistente con 002 y 004.
11. No hay multiempresa ni proveedores compartidos entre usuarios.
12. No se incluye importación/exportación masiva (ej. CSV) de proveedores.
13. La validación del RFC es solo de formato (estructura/longitud, vía `phpcfdi/rfc`), no contra el
    webservice real del SAT.
14. No se construye ningún botón o link de envío de WhatsApp (ej. `wa.me`) en esta historia; solo
    se garantiza que el teléfono quede en un formato compatible para cuando se integre la API real
    de WhatsApp Business.
