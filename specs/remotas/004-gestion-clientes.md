# Spec: Gestión de clientes (datos comerciales y fiscales SAT)

## Historia de usuario

Como usuario del sistema de facturación, quiero administrar (crear, ver, editar y eliminar) los
datos de mis clientes, capturando tanto datos comerciales (nombre comercial, contacto, teléfono,
dirección) como datos fiscales exigidos por el SAT para timbrado de CFDI (RFC, razón social,
régimen fiscal, código postal fiscal), para poder timbrar facturas válidas a mis clientes sin
tener que volver a capturar sus datos fiscales cada vez.

## Objetivo / Alcance

Implementar un módulo CRUD de clientes sobre la base ya existente de Laravel API + Vue 3 SPA +
Sanctum (ver [001-inicio-proyecto.md](001-inicio-proyecto.md),
[002-login-auth.md](002-login-auth.md)) y el design system de
[003-design-system-tailwind.md](003-design-system-tailwind.md). Incluye captura y validación de
datos fiscales según catálogos oficiales del SAT (régimen fiscal, código postal), pero **no**
incluye la emisión/timbrado de CFDI en sí (eso es una historia futura que consumirá estos datos).

## Backend (Laravel)

- **Modelo `Cliente`**, perteneciente a un `User` (`user_id`), con **soft deletes** habilitado
  (`SoftDeletes` trait).
- **Campos fiscales (obligatorios)**:
  - `rfc`: string. Validado por formato/estructura SAT (persona física: 13 caracteres; persona
    moral: 12 caracteres; se permite el RFC genérico de público en general `XAXX010101000`), sin
    validarse contra el webservice real del SAT.
  - `razon_social`: string (nombre/razón social tal como aparece en la Constancia de Situación
    Fiscal).
  - `regimen_fiscal`: valor del catálogo oficial SAT `c_RegimenFiscal`.
  - `codigo_postal_fiscal`: valor validado contra el catálogo oficial SAT `c_CodigoPostal`.
- **Campos comerciales (opcionales)**: `nombre_comercial`, `correo_contacto`, `telefono`,
  `direccion_comercial`.
- **Tipo de persona (Física/Moral)**: no se persiste como columna; se calcula mediante un accessor
  en el modelo a partir de la longitud del `rfc` (13 → Física, 12 → Moral; el RFC genérico se
  trata como caso especial sin tipo de persona asociado).
- **Catálogos SAT**: se usa una librería empaquetada de catálogos oficiales (ej.
  `phpcfdi/sat-catalogs`) como fuente de verdad para `c_RegimenFiscal` y `c_CodigoPostal`, en vez
  de mantener tablas propias a mano o permitir texto libre.
  - Endpoint `GET /api/v1/catalogos/regimenes-fiscales` para poblar el select del frontend.
  - Endpoint `GET /api/v1/catalogos/codigos-postales?q=...` con búsqueda/filtrado por texto
    (el catálogo completo es demasiado grande para cargarlo entero en el frontend).
- **Unicidad de RFC**: único por usuario (constraint `unique(user_id, rfc)`), no global — dos
  usuarios distintos pueden tener un cliente con el mismo RFC.
- **Endpoints** (bajo `auth:sanctum`, scopeados al usuario autenticado):
  - `GET /api/v1/clientes` — listado paginado, con `?search=` (por `razon_social`,
    `nombre_comercial` o `rfc`).
  - `POST /api/v1/clientes` — alta.
  - `GET /api/v1/clientes/{id}` — detalle.
  - `PUT /api/v1/clientes/{id}` — edición.
  - `DELETE /api/v1/clientes/{id}` — borrado lógico (soft delete).
- **Eliminación de clientes**: soft delete simple por ahora. La regla de negocio acordada es que
  **no debe permitirse eliminar un cliente con facturas timbradas asociadas**; como el módulo de
  facturación (timbrado de CFDI) todavía no existe, esta restricción no se puede implementar
  técnicamente en esta historia — queda documentada aquí como requisito a incorporar (ej. mediante
  una verificación `whenNoFacturasTimbradas` en el `DELETE`) en cuanto exista el módulo de
  facturación y su relación con `Cliente`.
- **Validaciones** (Form Requests):
  - `rfc`: requerido, formato válido (regex + longitud según tipo de persona o genérico), único
    por usuario.
  - `razon_social`: requerido, string.
  - `regimen_fiscal`: requerido, debe existir en el catálogo `c_RegimenFiscal`.
  - `codigo_postal_fiscal`: requerido, 5 dígitos numéricos, debe existir en el catálogo
    `c_CodigoPostal`.
  - `correo_contacto`: opcional, formato de email válido si se envía.
  - `nombre_comercial`, `telefono`, `direccion_comercial`: opcionales, string.
- Respuestas mediante Laravel API Resources (`ClienteResource`), consistente con la convención de
  001.

## Frontend (Vue 3)

- **`/clientes`** (protegida): listado paginado de clientes en tabla, con buscador (razón
  social/nombre comercial/RFC). Requiere agregar el componente `Table` al design system de 003
  (no estaba en el set inicial de componentes base).
- **`/clientes/crear`**: formulario de alta con los campos fiscales (RFC, razón social, régimen
  fiscal, código postal fiscal) y comerciales (nombre comercial, correo, teléfono, dirección).
  - Selector de régimen fiscal: `Select` simple (catálogo acotado, ~100 opciones).
  - Selector de código postal: **combobox con búsqueda** contra
    `GET /api/v1/catalogos/codigos-postales?q=...` (el catálogo es demasiado extenso para un
    `Select` cargado completo). Requiere agregar un componente `Combobox` al design system de 003.
- **`/clientes/:id/editar`**: mismo formulario, precargado, para edición.
- Confirmación (modal `Dialog`, ya existente en 003) antes de eliminar un cliente.
- Mensajes de error de validación (ej. RFC con formato inválido, CP inexistente) mostrados por
  campo, usando los componentes `Input`/`Alert` ya definidos en 003.
- Enlace a `/clientes` agregado a la navegación del `/dashboard`.

## Fuera de alcance

- Emisión/timbrado real de CFDI (facturación) — historia futura que consumirá los datos de
  `Cliente`.
- Uso de CFDI (`c_UsoCFDI`): se selecciona por factura al momento de timbrar, no se captura ni
  almacena en el cliente en esta historia.
- Validación de compatibilidad Régimen Fiscal ↔ Uso de CFDI, y Régimen Fiscal ↔ Tipo de Persona:
  se difieren a la historia de facturación.
- Validación del RFC contra el webservice real del SAT (solo se valida formato/estructura).
- Bloqueo efectivo de eliminación por facturas timbradas: la regla queda documentada pero no
  implementable hasta que exista el módulo de facturación.
- Roles/permisos diferenciados (cualquier usuario autenticado gestiona solo sus propios clientes).
- Multiempresa o clientes compartidos entre usuarios.
- Importación/exportación masiva de clientes (ej. CSV).

## Estado de implementación

Implementada el 2026-07-31.

- **`phpcfdi/sat-catalogos` no trae base de datos incluida**: el paquete de Composer solo trae
  código; los catálogos viven en un recurso externo
  ([`phpcfdi/resources-sat-catalogs`](https://github.com/phpcfdi/resources-sat-catalogs)) que hay
  que descargar y convertir a SQLite aparte. La base completa (~180 catálogos: aduanas, nómina,
  transporte, etc.) pesa ~100 MB; este proyecto solo usa `c_RegimenFiscal` y `c_CodigoPostal`, así
  que se generó una base SQLite reducida (~8 MB, 19 + ~95,748 filas) con únicamente esas dos
  tablas. Se creó el comando `php artisan catalogos-sat:actualizar`
  (`app/Console/Commands/ActualizarCatalogosSat.php`) que descarga el recurso y reconstruye
  `storage/app/sat-catalogos.sqlite` de forma reproducible; hay que correrlo manualmente después de
  instalar dependencias (no corre automático en `composer install`) y cada vez que el SAT actualice
  los catálogos.
- **`phpcfdi/rfc`** se usa tanto para validar el formato del RFC (`Rfc::parseOrNull`) como para
  inferir el tipo de persona (`isFisica()`/`isMoral()`/`isGeneric()`/`isForeign()`) en el accessor
  `tipo_persona` del modelo `Cliente` — no se escribió una regex propia. Solo se valida estructura;
  `doesCheckSumMatch()` existe en la librería pero **no se usa**, porque hay RFC reales con dígito
  verificador que no coincide (ver supuesto #11).
- **Unicidad de RFC**: se implementó solo a nivel de aplicación
  (`Rule::unique('clientes','rfc')->where('user_id', ...)->whereNull('deleted_at')`), sin
  constraint `UNIQUE` en la base de datos, para permitir reutilizar un RFC después de un soft
  delete (ver supuesto #9 y adición A de la spec).
- **Regla "no eliminar con facturas timbradas"**: documentada en `ClienteController::destroy()`
  como comentario, pero no hay verificación real porque el módulo de facturación no existe todavía
  (ver "Fuera de alcance").
- **Catálogo de código postal en el combobox**: la tabla `cfdi_40_codigos_postales` no trae un
  campo de texto descriptivo (el `texto` del catálogo es el mismo código); mostrar estado/municipio
  legibles requeriría además las tablas `cfdi_40_estados`/`cfdi_40_municipios` (excluidas de la
  base reducida). El combobox de código postal solo busca y muestra el código en sí, no la
  localidad.
- **Componentes shadcn-vue agregados**: `Table`, `Select`, `Combobox`, `Popover`, `Label` (no existe
  un componente `Command` separado en el registro Vue; `Combobox` ya viene autocontenido sobre los
  primitivos de Reka UI). Al correr `npx shadcn-vue add`, el CLI reescribió `src/style.css` como ya
  advertía la spec 003: quitó el `@import` de la fuente Open Sans y duplicó un bloque `@layer base`
  redundante; ambos se corrigieron a mano después.
- **Layout compartido**: se extrajo `src/layouts/AppLayout.vue` (header + nav + logout) desde
  `DashboardView.vue`, para que `/clientes`, `/clientes/crear` y `/clientes/:id/editar` compartan la
  misma navegación, con el enlace "Clientes" agregado junto al de "Inicio".
- **Verificación end-to-end**: la suite Pest (14 tests del módulo, 21 en total) corre contra los
  catálogos SAT reales. Adicionalmente se levantaron `php artisan serve` y `npm run dev` reales y se
  probó el flujo completo por HTTP (login, listar catálogos, crear y listar clientes) contra MySQL y
  la base SQLite de catálogos. **No se pudo verificar visualmente la UI en un navegador real** (sin
  herramienta de navegador headless disponible en este entorno Windows) — se verificó en su lugar
  que `vue-tsc`, ESLint y Prettier corren limpios y que el servidor de Vite sirve la SPA sin
  errores. Se recomienda abrir `/clientes` manualmente para confirmar tabla, combobox de código
  postal y diálogo de confirmación de borrado antes de dar la funcionalidad por completamente
  probada.

## Criterios de aceptación

1. Un usuario autenticado puede crear un cliente capturando RFC, razón social, régimen fiscal y
   código postal fiscal (obligatorios), y opcionalmente nombre comercial, correo, teléfono y
   dirección comercial.
2. Capturar un RFC con formato inválido (longitud/estructura incorrecta) muestra un error de
   validación y no permite guardar.
3. Capturar un RFC ya registrado por el mismo usuario muestra un error de "RFC duplicado"; el
   mismo RFC sí puede registrarse por un usuario distinto.
4. El régimen fiscal se elige de un catálogo cerrado (no texto libre); seleccionar un valor fuera
   del catálogo no es posible desde la UI.
5. El código postal fiscal se valida contra el catálogo oficial del SAT; un CP inexistente muestra
   error de validación.
6. El listado `/clientes` muestra los clientes del usuario autenticado (no los de otros usuarios),
   paginados, y la búsqueda filtra por razón social, nombre comercial o RFC.
7. Editar un cliente existente permite modificar cualquier campo (fiscal o comercial) y persiste
   los cambios.
8. Eliminar un cliente lo remueve del listado (soft delete) pero no lo borra físicamente de la
   base de datos.
9. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. "Cliente" es una entidad propia del usuario dueño de la cuenta (no compartida entre usuarios ni
   multiempresa por ahora).
2. El módulo es solo de gestión de datos del cliente (alta/baja/consulta/edición); no incluye
   emitir facturas.
3. **(Redefinido)** Campos fiscales obligatorios: RFC, razón social, régimen fiscal, código postal
   fiscal. El **Uso de CFDI se excluye** del cliente — se elegirá por factura en la futura historia
   de facturación, no se guarda como valor por defecto aquí.
4. **(Redefinido)** Campos comerciales opcionales: nombre comercial, correo de contacto, teléfono,
   dirección comercial. Se **excluyen las notas internas**.
5. El RFC es único por usuario, no global.
6. Se permite registrar el RFC genérico de público en general (`XAXX010101000`).
7. El régimen fiscal se selecciona de un catálogo cerrado (el oficial del SAT, `c_RegimenFiscal`),
   no texto libre.
8. ~~El Uso de CFDI se selecciona de un catálogo cerrado~~ — **(Eliminado)**: ya no aplica a esta
   spec al excluirse Uso de CFDI del cliente; se retoma en la historia de facturación.
9. **(Redefinido)** "Eliminar" un cliente es borrado lógico (soft delete); adicionalmente, no debe
   permitirse eliminar un cliente con **facturas timbradas** asociadas — restricción documentada
   pero no implementable aún porque el módulo de facturación no existe todavía.
10. No hay roles ni permisos diferenciados todavía (cualquier usuario autenticado gestiona solo sus
    propios clientes), consistente con 002.
11. La validación del RFC es solo de formato (estructura/longitud), no contra el webservice real
    del SAT.
12. **(Redefinido)** Origen del catálogo de régimen fiscal: librería de catálogos SAT ya
    empaquetada (ej. `phpcfdi/sat-catalogs`), no una tabla hardcodeada mantenida a mano ni texto
    libre.
13. ~~Validación de compatibilidad Régimen Fiscal ↔ Uso de CFDI~~ — **(Eliminada)**: no aplica al
    excluirse Uso de CFDI del cliente en esta spec.
14. **(Redefinido)** Tipo de persona (Física/Moral): no se agrega como campo explícito; se infiere
    automáticamente a partir de la longitud del RFC capturado.
15. **(Redefinido)** Código postal fiscal: se valida contra el catálogo oficial de códigos
    postales del SAT (`c_CodigoPostal`), no solo el formato de 5 dígitos.
16. Existe una pantalla de listado de clientes con búsqueda (por nombre/RFC) y paginación.
17. El catálogo de código postal, por su tamaño, se consulta vía endpoint de búsqueda con `?q=`
    en vez de cargarse completo en el frontend (requiere un componente `Combobox` no incluido en
    el set base de 003).
18. El listado de clientes requiere un componente `Table` no incluido en el set base de 003.
