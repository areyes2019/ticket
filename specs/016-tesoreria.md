# Spec: Tesorería (cuentas, movimientos y saldos)

**Referencia:** reescritura de [remotas/010-tesoreria.md](remotas/010-tesoreria.md), que se diseñó
para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan sus reglas de negocio: cuentas
con saldo inicial inmutable, saldo actual guardado y recalculado bajo bloqueo, transferencias como
dos movimientos vinculados, saldo nunca negativo, movimientos automáticos de solo lectura, la
cotización como fuente de verdad de los pagos y la utilidad de venta por documento. La parte del
navegador y el protocolo entre navegador y servidor se rehicieron para Laravel + Blade + JavaScript
nativo.

**Modifica:** [011-cotizaciones.md](011-cotizaciones.md) (el pago pide una cuenta en lugar de la
forma de pago SAT; registrar y eliminar un pago mueve Tesorería).

Se retiró lo que la spec remota había heredado de historias que aquí todavía no existen: la
integración con Pedidos ([remotas/027](remotas/027-venta-mostrador-ticket.md)) y con Órdenes de
Compra ([remotas/012](remotas/012-ordenes-compra.md)). El enganche queda genérico (relación
polimórfica `documentable` y un servicio único de movimientos), para que esos módulos se conecten
cuando lleguen sin migraciones nuevas en Tesorería.

## Historia de usuario

Como usuario registrado, quiero administrar el flujo de dinero del negocio en un módulo propio, para
registrar y consultar todos los movimientos financieros que afectan mis cuentas —vengan de otro
módulo del sistema o los capture yo a mano— y conocer en todo momento el saldo real de cada cuenta.

## Objetivo / Alcance

Implementar el módulo de Tesorería sobre la arquitectura monolítica Laravel + Blade + JavaScript
nativo de [001-inicio-proyecto.md](001-inicio-proyecto.md), con la sesión web de
[002-login.md](002-login.md), los componentes Blade de [003-estilo-uniforme.md](003-estilo-uniforme.md)
y las convenciones de los módulos anteriores.

- Laravel resuelve el módulo completo: rutas web, controladores, Form Requests, Policies, un
  servicio de movimientos y las vistas.
- No se crea API REST, no se usa Sanctum ni API Resources, y no hay stores ni estado en el
  frontend. Los selectores de cuenta los llena el controlador al pintar la vista.
- Los modales son `<dialog>` nativos con formularios normales, con el manejador que ya existe en
  `app.js` (`data-abrir-dialogo`, `data-abrir-al-cargar`). Todo funciona sin JavaScript.
- **No se escribe JavaScript nuevo.** El único JavaScript es el que ya existe: diálogos,
  `data-confirmar` y `data-enviar-una-vez`.

Incluye: cuentas, ingresos, egresos, ajustes y transferencias manuales, listado de movimientos con
filtros, consulta de saldos, el ingreso automático de cada pago de cotización y la utilidad de venta
de los movimientos que vienen de una cotización.

Tesorería **no** administra ventas, compras ni facturación: solo registra el efecto financiero que
esos módulos producen, para poder reutilizarse en otros sistemas.

### Relación con Facturación: quién es la fuente de verdad de los pagos

**La Cotización es la fuente de verdad de los pagos recibidos; la Factura solo registra el
movimiento fiscal (el CFDI).**

- Facturación ([012](012-facturacion.md), [015](015-cotizacion-a-factura-y-duplicar.md)) **no se
  modifica**: timbrar no genera movimientos, `Factura` y `ComplementoPago` no ganan cuenta y no hay
  acción de "registrar pago" sobre una factura.
- El `forma_pago` (catálogo SAT `c_FormaPago`) de `Factura` y de `ComplementoPago` se conserva
  intacto: es un dato fiscal real del CFDI.
- La única fuente de ingresos automáticos es `CotizacionPago`.

## Backend (Laravel)

### Enums (`app/Enums`)

Con `etiqueta()` y `opciones()`, como los existentes.

- `TipoCuenta`: `efectivo` (Efectivo), `banco` (Banco), `digital` (Digital), `otro` (Otro).
- `TipoMovimiento`: `ingreso` (Ingreso), `egreso` (Egreso), `transferencia` (Transferencia),
  `ajuste` (Ajuste). `manuales()` devuelve los tres que acepta el formulario de movimiento
  (sin `transferencia`).

### Migración `..._create_tesoreria_tables.php`

**Tabla `cuentas`**

| Columna | Definición |
|---|---|
| `id` | |
| `user_id` | `foreignId` → `users` |
| `nombre` | `string(100)` |
| `tipo` | `string(10)` (`TipoCuenta`) |
| `saldo_inicial` | `decimal(14,2)` |
| `saldo_actual` | `decimal(14,2)` |
| `activa` | `boolean`, default `true` |
| `timestamps` | |

Índice `(user_id, nombre)`.

**Tabla `movimientos`**

| Columna | Definición |
|---|---|
| `id` | |
| `user_id` | `foreignId` → `users` |
| `cuenta_id` | `foreignId` → `cuentas`, **`restrictOnDelete`** |
| `tipo` | `string(15)` (`TipoMovimiento`) |
| `monto` | `decimal(14,2)`, **con signo** (ver abajo) |
| `fecha` | `date` |
| `concepto` | `string(255)` |
| `documentable_type`, `documentable_id` | `nullableMorphs` (crea su índice) |
| `transferencia_id` | `uuid`, nullable, índice |
| `timestamps` | |

Índices `(user_id, fecha)` y `(cuenta_id, fecha)`.

**Tabla `cotizacion_pagos`**

- Gana `cuenta_id`: `foreignId` → `cuentas`, `restrictOnDelete`.
- Pierde `forma_pago`.

`restrictOnDelete` en las dos llaves hace que la base de datos respalde la regla "una cuenta con
movimientos no se borra".

Las cantidades son `decimal(14,2)`, no `(12,2)` como en la remota, porque `cotizacion_pagos.monto`
ya es `(14,2)` y su movimiento tiene que poder guardarlo.

**Pagos ya registrados (asunción 9).** La migración, en este orden y con el query builder (sin
modelos):

1. Por cada usuario que tenga pagos de cotización, crea la cuenta **"Caja General"** (`efectivo`,
   saldo inicial 0, activa).
2. Asigna esa cuenta a todos los pagos de ese usuario.
3. Crea un movimiento `ingreso` por cada pago, con el monto, la `fecha_pago`, el concepto
   automático y `documentable` apuntando al pago.
4. Pone `saldo_actual` de cada cuenta creada = suma de sus movimientos.
5. Después vuelve `cuenta_id` obligatoria y borra `forma_pago`.

`down()` borra las tablas nuevas y devuelve `forma_pago` a `cotizacion_pagos` con el valor `99`
(Por definir) en las filas existentes.

### Morph map

`Relation::enforceMorphMap(['cotizacion_pago' => CotizacionPago::class])` en `AppServiceProvider`.
`documentable_type` guarda un alias estable, no el nombre de la clase: mover o renombrar el modelo
no rompe los movimientos guardados. Los módulos futuros agregan su alias aquí.

### Modelo `Cuenta`

- `user_id` no es asignable; lo pone el controlador.
- Asignables: `nombre`, `tipo`, `saldo_inicial`, `activa`. `saldo_actual` **no es asignable**:
  solo lo escribe el servicio de movimientos (y el alta, igual al inicial).
- Casts: `tipo` → `TipoCuenta`, `saldo_inicial`/`saldo_actual` → `decimal:2`, `activa` → `boolean`.
- `movimientos(): HasMany`.
- `tieneMovimientos(): bool` (usa `movimientos_count` si el listado lo precargó, igual que
  `Cotizacion::tienePagos()`).
- Scopes `activas()` y `delUsuario(User)`.
- `saldo_inicial` es **inmutable tras la creación**: el formulario de edición no lo acepta y si
  llega se ignora. Para corregirlo se registra un Ajuste.

### Modelo `Movimiento`

- `user_id` y `transferencia_id` no son asignables.
- Casts: `tipo` → `TipoMovimiento`, `monto` → `decimal:2`, `fecha` → `date`.
- `cuenta(): BelongsTo`, `documentable(): MorphTo`.
- `esAutomatico(): bool` → `documentable_type !== null`.
- `esTransferencia(): bool` → `transferencia_id !== null`.
- `contraparte(): ?Movimiento` → la otra fila de la transferencia.
- `montoCapturado(): string` → el valor que el usuario escribió: `abs(monto)` para ingreso, egreso y
  transferencia; `monto` tal cual para ajuste. Lo usa el formulario de edición.
- `documentoOrigen(): ?array` (adición técnica del Problema 3) → `null` en movimientos manuales. Para
  un `CotizacionPago`:

  ```php
  [
      'etiqueta' => 'COT-0012',
      'url' => route('cotizaciones.show', $cotizacion),
      'utilidad' => '1234.50',      // string|null; null = no disponible
      'utilidad_parcial' => false,  // true = hay líneas sin costo excluidas
  ]
  ```

  Los módulos futuros agregan su caso aquí. Blade lo usa directamente.

**Monto con signo (adición 2).** `monto` guarda el **efecto sobre el saldo**:

| Tipo | Se captura | Se guarda |
|---|---|---|
| `ingreso` | positivo | positivo |
| `egreso` | positivo | **negativo** |
| `ajuste` | positivo o negativo, distinto de 0 | tal cual |
| `transferencia` | positivo | negativo en la cuenta origen, positivo en la destino |

Así, `saldo_actual = saldo_inicial + SUM(monto)` es literal y la suma de una transferencia es 0.

### Transferencias

Una transferencia mueve dinero entre dos cuentas propias y **no es ingreso ni egreso del negocio**.
Para el usuario es una sola operación; se guarda como **dos filas** `tipo = transferencia` con el
mismo `transferencia_id` (uuid): una resta en la origen y otra suma en la destino. Las dos filas
comparten fecha y concepto.

Una transferencia **no se edita** (asunción 2): se elimina y se vuelve a capturar. Eliminar
cualquiera de sus dos filas elimina ambas.

### Servicio `App\Services\Tesoreria\RegistradorMovimientos` (adición 1)

Única vía para crear, editar o eliminar movimientos y para tocar `saldo_actual`. Lo usan los
controladores de Tesorería y `CotizacionPagoController`. Cada método corre en `DB::transaction` (si
ya hay una abierta, se anida en ella) y sigue siempre los mismos pasos:

1. **Bloquea** las cuentas involucradas con `lockForUpdate()`, **en orden de `id` ascendente**
   (adición 3). Dos transferencias cruzadas (A→B y B→A) piden los bloqueos en el mismo orden y no se
   traban entre sí.
2. Revisa que las cuentas que **reciben un movimiento nuevo** estén activas.
3. Escribe o borra el/los movimiento(s).
4. Recalcula `saldo_actual = saldo_inicial + SUM(movimientos.monto)` de cada cuenta involucrada.
5. Si alguna quedó **por debajo de 0**, lanza `App\Exceptions\SaldoNegativoException` y la
   transacción se deshace: no se guarda nada.

Validar después de escribir, con las cuentas bloqueadas, es lo mismo que validar antes, pero sin
repetir la fórmula del saldo: el saldo contra el que se compara no puede cambiar en medio.

Métodos:

- `registrar(Cuenta $cuenta, TipoMovimiento $tipo, string $montoCapturado, string $fecha, string $concepto, ?Model $documento = null): Movimiento`
  convierte el monto capturado al monto con signo según el tipo.
- `transferir(Cuenta $origen, Cuenta $destino, string $monto, string $fecha, string $concepto): Movimiento`
  crea las dos filas y devuelve la de la cuenta origen.
- `actualizar(Movimiento $movimiento, Cuenta $cuenta, string $montoCapturado, string $fecha, string $concepto): Movimiento`
  solo manuales y no transferencias. Si cambia la cuenta, bloquea las dos (la anterior puede
  quedar negativa si pierde un ingreso; la nueva tiene que estar activa).
- `eliminar(Movimiento $movimiento): void` solo manuales; en una transferencia borra las dos filas.
- `eliminarDeDocumento(Model $documento): void` borra los movimientos de ese documento. **No exige
  cuenta activa** (asunción 3): quitar un movimiento no es un movimiento nuevo.

Los métodos de manuales rechazan un movimiento automático con una `LogicException`: la Policy ya
debió impedirlo antes de llegar aquí.

**`SaldoNegativoException`** lleva el mensaje "El movimiento dejaría la cuenta {nombre} con saldo
negativo." Cada controlador la convierte en la respuesta que le toca (error de validación del
diálogo o aviso de la página).

La regla de saldo no negativo aplica a **cualquier** operación que reste (asunción 1):

- egreso, ajuste negativo y cuenta origen de una transferencia;
- editar un ingreso a la baja, moverlo a otra cuenta, o eliminarlo;
- eliminar una transferencia (la cuenta destino pierde el dinero);
- eliminar un pago de cotización (su cuenta pierde el ingreso).

### Integración con Cotizaciones (011)

**`CotizacionPago`**

- `cuenta_id` reemplaza a `forma_pago` en `$fillable` y en los casts.
- Gana `cuenta(): BelongsTo` y `movimiento(): MorphOne` (`documentable`).

**`CotizacionPagoController::store`**, dentro de la transacción que ya existe y **después** de
bloquear la cotización (orden de bloqueo: cotización → cuenta, adición 3):

- Crea el pago con `cuenta_id` en lugar de `forma_pago`.
- Llama a `RegistradorMovimientos::registrar()` con `ingreso`, el monto **ya calculado** del pago,
  `fecha_pago`, el concepto automático y el pago como documento.
- **Concepto automático:** `"{TipoPago::etiqueta()} de Cotización {folio_formateado}"` (asunción 8):
  "Anticipo de Cotización COT-0012", "Saldo de Cotización COT-0012", "Pago total de Cotización
  COT-0012". No se puede editar.
- Una `SaldoNegativoException` no puede ocurrir aquí (un ingreso solo suma). Una cuenta que se
  desactivó entre el formulario y la escritura se rechaza con un error de validación en la bolsa
  `pago`: "La cuenta {nombre} está inactiva."

**`CotizacionPagoController::destroy`** ya existe (solo el último pago por `id`, nunca en
`producto_entregado`, y una `pagada` que deja de cubrir el total regresa a `enviada`). Gana un solo
paso dentro de su transacción:

- Antes de `$pago->delete()`, llama a `RegistradorMovimientos::eliminarDeDocumento($pago)`.
- Si lanza `SaldoNegativoException`, no se borra nada y regresa al detalle con el aviso de error:
  "No se puede eliminar el pago: la cuenta {nombre} quedaría con saldo negativo."

Se conserva el criterio por `id` (y no por `created_at` como en la remota): ya está implementado y
probado, y con ids autoincrementales da el mismo orden.

**Crear una cotización o timbrar su factura no genera ningún movimiento**: solo el pago lo hace.

**RN-013 (un documento con movimientos no se elimina)** se cumple sin validación nueva:
`CotizacionPolicy::delete` ya niega el borrado si `tienePagos()`, `cotizacion_pagos` ya tiene
`restrictOnDelete` hacia la cotización, y la caducidad (`scopeVencidas`) solo toca cotizaciones sin
pagos. Una cotización con movimientos nunca se borra, y el enlace del movimiento a su cotización
nunca se rompe.

### Utilidad de venta (`Cotizacion::utilidadVenta()`)

Un movimiento que viene de un pago de cotización muestra cuánto le quedó de utilidad al usuario por
esa venta.

- **Es la utilidad del documento completo, no la del pago.** El anticipo y el saldo de una misma
  cotización muestran el mismo número. No depende de cuánto se haya cobrado.
- **Fórmula**, sobre las líneas de la cotización:

  ```
  utilidad = Σ ( importe − costo_unitario × cantidad )   (solo líneas con costo_unitario)
  ```

  `importe` ya es el neto sin IVA después del descuento de línea y de la parte prorrateada del
  descuento global (011), así que la utilidad se mide contra lo que se cobró, no contra el precio de
  lista. `costo_unitario` es la copia del costo del artículo al guardar la línea (011).
- **Regla de líneas sin costo (asunción 6)**:
  - Una línea **libre** (`articulo_id` nulo) se excluye y marca `utilidad_parcial = true`. A
    diferencia de la remota, aquí sí aplica a cotizaciones: sus líneas pueden ser libres.
  - Una línea **de catálogo sin `costo_unitario`** (el artículo no tenía costo al guardarse) también
    se excluye y marca `utilidad_parcial = true`.
  - Si **ninguna** línea tiene costo, `utilidad = null` ("No disponible"), que es distinto de $0.00.
- Devuelve `['utilidad' => ?string, 'parcial' => bool]`, en centavos enteros internamente
  (`CalculadoraTotalesDocumento::centavos()`), igual que los totales.
- Vive en el modelo, no en Tesorería, para que otra pantalla pueda usarla sin pasar por movimientos.
  Cuando exista Pedido, se extrae a un trait.
- No se reconstruye el costo de nada con el costo actual del artículo: daría un número falso.

### Rutas (web)

Dentro del grupo `auth` + `AsegurarUsuarioActivo`, con
`Route::prefix('tesoreria')->name('tesoreria.')`:

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/tesoreria/cuentas` | listado | `tesoreria.cuentas.index` |
| GET | `/tesoreria/cuentas/create` | formulario de alta | `tesoreria.cuentas.create` |
| POST | `/tesoreria/cuentas` | alta | `tesoreria.cuentas.store` |
| GET | `/tesoreria/cuentas/{cuenta}/edit` | formulario de edición | `tesoreria.cuentas.edit` |
| PUT | `/tesoreria/cuentas/{cuenta}` | edición | `tesoreria.cuentas.update` |
| DELETE | `/tesoreria/cuentas/{cuenta}` | borrado | `tesoreria.cuentas.destroy` |
| PATCH | `/tesoreria/cuentas/{cuenta}/activa` | activar / desactivar | `tesoreria.cuentas.activa` |
| GET | `/tesoreria/movimientos` | listado con filtros | `tesoreria.movimientos.index` |
| POST | `/tesoreria/movimientos` | ingreso, egreso o ajuste manual | `tesoreria.movimientos.store` |
| GET | `/tesoreria/movimientos/{movimiento}/edit` | formulario de edición | `tesoreria.movimientos.edit` |
| PUT | `/tesoreria/movimientos/{movimiento}` | edición | `tesoreria.movimientos.update` |
| DELETE | `/tesoreria/movimientos/{movimiento}` | eliminación | `tesoreria.movimientos.destroy` |
| POST | `/tesoreria/transferencias` | transferencia | `tesoreria.transferencias.store` |
| GET | `/tesoreria/saldos` | saldos | `tesoreria.saldos` |

`Route::resource('cuentas')->except('show')` y `Route::resource('movimientos')->except(['create', 'show'])`
dentro del grupo. La transferencia tiene su propia ruta porque sus reglas y su forma son distintas:
dos cuentas y dos filas.

### Controladores

- **`CuentaController`**
  - `index`: cuentas del usuario con `withCount('movimientos')`, búsqueda `?q=` por nombre, filtro
    `?activa=` (todas / activas / inactivas), orden por nombre, paginadas con `withQueryString()`.
  - `store`: crea con `saldo_actual = saldo_inicial`. Redirige al listado: "Cuenta {nombre} creada."
  - `update`: solo `nombre`, `tipo` y `activa`.
  - `alternarActiva`: invierte `activa`. "Cuenta {nombre} desactivada." / "…activada."
  - `destroy`: si `tieneMovimientos()`, regresa al listado con el error "No se puede eliminar: la
    cuenta tiene movimientos registrados" y la marca para ofrecer desactivarla (ver Vistas). Si no,
    la borra. Mismo patrón que `CatalogoController::destroy`.
- **`MovimientoController`**
  - `index`: movimientos del usuario, filtros combinables (ver Validaciones), orden `fecha desc, id
    desc`, 25 por página con `withQueryString()`. Precarga `cuenta` y, con
    `morphWith([CotizacionPago::class => ['cotizacion.lineas']])`, el documento origen con las
    líneas para la utilidad (adición 7): sin eso habría una consulta por fila. Pasa a la vista las
    cuentas **activas** (para los diálogos) y **todas** (para el filtro).
  - `store`: `RegistradorMovimientos::registrar()`. Éxito: "Ingreso de $1,500.00 registrado en
    {cuenta}." Una `SaldoNegativoException` se convierte en error de validación del campo `monto`,
    en la bolsa del tipo, para que su diálogo se reabra con el mensaje.
  - `edit`: formulario de edición en su propia página (no en diálogo: así no hace falta JavaScript
    para precargar los datos de una fila). Solo manuales que no sean transferencia.
  - `update`: `RegistradorMovimientos::actualizar()`. El tipo no cambia; para cambiarlo se elimina y
    se vuelve a capturar. `SaldoNegativoException` → error del campo `monto`.
  - `destroy`: `RegistradorMovimientos::eliminar()`. `SaldoNegativoException` → regresa al listado con
    el aviso de error.
- **`TransferenciaController::store`** (invocable o con `store`): `RegistradorMovimientos::transferir()`.
  Éxito: "Transferencia de $500.00 de {origen} a {destino} registrada." `SaldoNegativoException` →
  error del campo `monto` en la bolsa `transferencia`.
- **`SaldoController`** (invocable): todas las cuentas del usuario (activas e inactivas), ordenadas
  por nombre, sin paginar, más el total global `SUM(saldo_actual)`.
- **`CotizacionController::show`** pasa `$cuentas`: las cuentas activas del usuario (`id → nombre`)
  para los diálogos de pago, en lugar de `$formasPago`. Precarga `pagos.cuenta`.

Todos los controladores filtran por `user_id` del usuario autenticado; el `user_id` de lo que se crea
lo pone el controlador.

### Autorización (adición 6)

- **`CuentaPolicy`**: `update`, `delete` y `alternarActiva` → dueño, o 404 si es ajena (patrón de
  `CotizacionPolicy::esDueno`).
- **`MovimientoPolicy`**:
  - `update` → dueño (si no, 404); después `Response::deny('Este movimiento lo generó
    {etiqueta del documento}: corrígelo desde ahí.')` si es automático, y `Response::deny('Una
    transferencia no se edita: elimínala y vuelve a capturarla.')` si es transferencia.
  - `delete` → dueño; deny con el mismo motivo si es automático. Una transferencia sí se elimina.
- Una cuenta ajena enviada en un formulario no pasa la validación (`Rule::exists` con `user_id`).

### Validaciones (Form Requests)

**`CuentaRequest`**

- `nombre`: requerido, string, máximo 100.
- `tipo`: requerido, `Rule::enum(TipoCuenta::class)`.
- `saldo_inicial`: **solo en el alta** (`exclude` en `PUT`): requerido, numérico, ≥ 0,
  `decimal:0,2`, máximo 9 999 999 999.99.
- `activa`: booleano, opcional (default `true`).

**`MovimientoRequest`** (alta y edición)

- La bolsa de errores es el tipo (`ingreso`, `egreso`, `ajuste`), para reabrir el diálogo correcto.
  En la edición la bolsa es la de por defecto.
- `tipo`: solo en el alta; requerido, uno de `TipoMovimiento::manuales()`. `transferencia` se
  rechaza.
- `cuenta_id`: requerido, cuenta del usuario. Si existe pero está inactiva: "La cuenta {nombre} está
  inactiva y no admite movimientos."
- `monto`: requerido, numérico, `decimal:0,2`, valor absoluto máximo 9 999 999 999.99.
  - `ingreso`/`egreso`: mayor a 0.
  - `ajuste`: distinto de 0 (acepta negativos).
- `fecha`: requerida, `date_format:Y-m-d`, **no futura** en la zona del negocio
  (`config('app.zona_negocio')`), igual que `fecha_pago` (asunción 4).
- `concepto`: requerido, string, máximo 255. En el ajuste la etiqueta es "Motivo".
- El saldo no negativo **no** se valida aquí: lo revisa el servicio con la cuenta bloqueada.

**`TransferenciaRequest`** (bolsa `transferencia`)

- `cuenta_origen_id` y `cuenta_destino_id`: requeridos, cuentas del usuario, activas, y
  `different:cuenta_origen_id` en la destino ("La cuenta destino debe ser distinta de la origen").
- `monto`: requerido, numérico, mayor a 0, `decimal:0,2`.
- `fecha` y `concepto`: como en el movimiento.

**`ListadoMovimientosRequest`** (filtros del listado; un valor inválido se ignora, no da error)

- `fecha_desde`, `fecha_hasta`: `date_format:Y-m-d`; días calendario en la zona del negocio.
- `cuenta_id`: cuenta del usuario (activa o inactiva).
- `tipo`: cualquiera de `TipoMovimiento`.
- `concepto`: texto libre, `LIKE %…%`.
- Se combinan entre sí.

**`CotizacionPagoRequest` (cambia)**

- `forma_pago` se elimina.
- `cuenta_id`: requerido, cuenta del usuario, activa (mismo mensaje de inactiva).
- El resto de las reglas (un solo anticipo, monto calculado para `saldo`/`pago_total`, sin
  sobrepago) no cambia.

### Índices y rendimiento

- El listado de movimientos filtra por `user_id` y ordena por `fecha`: índice `(user_id, fecha)`.
- El filtro por cuenta y el recálculo del saldo usan `(cuenta_id, fecha)`.
- `transferencia_id` y el morph tienen su propio índice (borrar la contraparte, buscar el
  movimiento de un pago).
- Saldos es una lectura simple de `cuentas`: no suma movimientos.

## Vistas (Blade)

Todas con `layouts/app`, `x-card`, `x-campo`, `x-boton`, `x-alerta` y `x-paginacion`.

### Navegación

- En [layouts/app.blade.php](../resources/views/layouts/app.blade.php), un enlace **"Contabilidad"**
  (`bi-cash-coin`) después de "Facturas", que lleva a `tesoreria.movimientos.index` (asunción 7).
- Dentro del módulo, las tres pantallas comparten una barra de pestañas (parcial
  `tesoreria/_pestanas.blade.php`): **Movimientos · Cuentas · Saldos**, con la actual marcada.
- El nombre visible es "Contabilidad"; rutas, clases, tablas y vistas usan `tesoreria`.

### `tesoreria/cuentas/index.blade.php`

- Formulario GET: buscador por nombre y `select` de estado (Todas / Activas / Inactivas).
- Tabla: nombre, tipo, saldo inicial, saldo actual, estado (etiqueta Activa/Inactiva).
- Por fila: "Editar", "Desactivar"/"Activar" (formulario PATCH) y "Eliminar" (formulario DELETE con
  `data-confirmar="¿Eliminar la cuenta {nombre}? El borrado es definitivo."`).
- Si el borrado se rechazó por tener movimientos, el error aparece como `x-alerta` arriba del
  listado, con un botón **"Desactivar cuenta"** (formulario PATCH) cuando la cuenta sigue activa.
- Botón "Nueva cuenta".

### `tesoreria/cuentas/crear.blade.php` y `editar.blade.php` (`_formulario.blade.php`)

- `nombre` (texto), `tipo` (`select` de 4 opciones), `saldo_inicial` (numérico, `step="0.01"`).
- En la edición: `saldo_inicial` de **solo lectura** (texto, no campo), con la nota "El saldo inicial
  no se modifica. Para corregirlo, registra un ajuste en Movimientos."; y la casilla "Cuenta activa".

### `tesoreria/movimientos/index.blade.php`

- **Filtros** (formulario GET): desde, hasta, cuenta (todas las cuentas), tipo y concepto. Botón
  "Filtrar" y enlace "Limpiar".
- **Botones de acción**: "Registrar ingreso", "Registrar egreso", "Registrar transferencia" y
  "Registrar ajuste", cada uno abre su `<dialog>`:
  - Ingreso / egreso: cuenta (solo activas), monto, fecha (hoy por defecto) y concepto.
  - Transferencia: cuenta origen, cuenta destino, monto, fecha y concepto.
  - Ajuste: cuenta, monto (admite negativo, con la nota "Usa un monto negativo para restar"), fecha y
    motivo.
  - Con errores de su bolsa, el diálogo se abre solo (`data-abrir-al-cargar`) con los valores de
    `old()`.
  - Si el usuario no tiene cuentas activas, en lugar de los botones se muestra "Primero crea una
    cuenta" con enlace a `tesoreria.cuentas.create`.
  - Los botones de envío llevan `data-enviar-una-vez`.
- **Tabla**: fecha, cuenta, tipo, concepto, monto, utilidad, origen y acciones.
  - **Monto** con signo y color según su efecto: verde si suma, rojo si resta.
  - **Origen**: "Manual"; "Transferencia" con el nombre de la otra cuenta ("→ BBVA" / "← Caja
    General"); o el enlace al documento ("COT-0012") con la etiqueta "Automático".
  - **Utilidad**, solo en movimientos con documento origen:
    - el monto en verde (rojo si es negativa);
    - si `utilidad_parcial`, junto al monto un texto discreto **"Parcial"** con `title="La cotización
      tiene líneas sin costo conocido; no se incluyen en la utilidad."`;
    - si `utilidad` es `null`: un guion con el texto **"No disponible"**;
    - vacía en manuales, transferencias y ajustes.
  - **Acciones**:
    - Manual: "Editar" (enlace a `edit`) y "Eliminar" (`data-confirmar`).
    - Transferencia: solo "Eliminar", con `data-confirmar="Se eliminarán los dos movimientos de esta
      transferencia."`.
    - Automático: los dos botones **deshabilitados**, con `title` "Se corrige desde COT-0012".
- Sin movimientos: "No hay movimientos con estos filtros."
- Paginación con los filtros conservados.

### `tesoreria/movimientos/editar.blade.php`

- Muestra el tipo como texto (no se edita); campos cuenta (activas, más la actual si ya está
  inactiva), monto (el capturado, `montoCapturado()`), fecha y concepto/motivo.

### `tesoreria/saldos.blade.php`

- Tabla: cuenta, tipo, estado y saldo actual. Las inactivas se muestran con su etiqueta.
- Pie: **Total global**.
- Cada fila enlaza a `tesoreria.movimientos.index?cuenta_id={id}`.
- Sin desglose de movimientos.

### Cotización: diálogos de pago (`cotizaciones/_dialogos-pago.blade.php`)

- El `x-campo` `forma_pago` se reemplaza por `cuenta_id`: "Cuenta", `select` con `$cuentas`, vacía
  "Selecciona la cuenta".
- Si el usuario no tiene cuentas activas: el diálogo muestra "Para registrar pagos, primero crea una
  cuenta en Contabilidad" con enlace a `tesoreria.cuentas.create`, y el botón de registrar queda
  deshabilitado.

### Cotización: detalle (`cotizaciones/show.blade.php`)

- El historial de pagos muestra la columna **"Cuenta"** en lugar de "Forma de pago".
- El botón de eliminar del último pago (ya existe) cambia su confirmación: "¿Eliminar este pago de
  {monto}? También se eliminará su ingreso en Contabilidad y, si la cotización estaba pagada,
  regresará a Enviada."

## JavaScript

No se agrega. Diálogos, confirmaciones y doble clic usan lo que ya existe en `public/js/app.js`. Los
filtros y la paginación son formularios GET normales.

## Pruebas (Pest) (adición 8)

- **`TesoreriaCuentasTest`** (nuevo):
  - alta con `saldo_actual = saldo_inicial`; validaciones de nombre, tipo y saldo inicial negativo;
  - `PUT` con `saldo_inicial` lo ignora;
  - activar/desactivar;
  - borrar sin movimientos funciona; con movimientos regresa el error y no borra;
  - búsqueda y filtro de estado;
  - cuenta ajena → 404 en editar, actualizar, borrar y alternar.
- **`TesoreriaMovimientosTest`** (nuevo):
  - ingreso suma, egreso resta, ajuste positivo y negativo corrigen; el monto se guarda con signo;
  - egreso y ajuste negativo que dejarían saldo negativo → error en `monto` de su bolsa y nada
    guardado;
  - cuenta inactiva → error de validación;
  - `tipo = transferencia` en `POST /movimientos` → error;
  - fecha futura → error;
  - editar recalcula el saldo, incluido cambiar de cuenta (las dos cuentas cuadran); editar un
    ingreso a la baja que dejaría negativo → error;
  - eliminar recalcula; eliminar un ingreso ya gastado → error y nada borrado;
  - un movimiento automático no se edita ni se elimina (403);
  - filtros combinados (rango, cuenta, tipo, concepto) y aislamiento entre usuarios;
  - `saldo_actual == saldo_inicial + SUM(monto)` después de una serie mixta de operaciones.
- **`TesoreriaTransferenciasTest`** (nuevo):
  - crea dos filas con el mismo `transferencia_id`, origen baja, destino sube, total global igual;
  - misma cuenta → error; origen sin saldo suficiente → error y nada guardado; cuenta inactiva →
    error;
  - eliminar una fila borra las dos; eliminar con la destino ya gastada → error;
  - una transferencia no se edita (403).
- **`TesoreriaSaldosTest`** (nuevo): lista activas e inactivas con el total global; solo las del
  usuario.
- **`CotizacionPagosTest`** (se actualiza):
  - los envíos mandan `cuenta_id` en lugar de `forma_pago`; cuenta ajena o inactiva → error;
  - cada tipo de pago crea su ingreso con monto, fecha, concepto "… de Cotización COT-…" y documento;
  - eliminar el último pago borra su movimiento y recalcula el saldo; si la cuenta quedaría negativa,
    no borra nada y muestra el aviso;
  - eliminar el pago funciona aunque la cuenta ya esté inactiva;
  - crear una cotización y timbrar su factura no crean movimientos.
- **`TesoreriaUtilidadTest`** (nuevo):
  - utilidad completa (con descuento de línea y global) en los dos movimientos de una cotización
    pagada en anticipo + saldo;
  - línea libre o línea de catálogo sin costo → parcial;
  - ninguna línea con costo → "No disponible";
  - movimientos manuales sin utilidad;
  - el listado no hace una consulta por fila (conteo de consultas con varias cotizaciones).
- **Migración**: prueba de que los pagos existentes quedan en "Caja General" con su movimiento y el
  saldo correcto (`$this->artisan('migrate:rollback --step=1')` + datos + `migrate`).
- Las pruebas que hoy envían `forma_pago` en pagos de cotización (`CotizacionesTest`,
  `CotizacionAFacturaTest`, `DuplicarDocumentosTest`, `PurgarCotizacionesTest`) se ajustan. Las de
  facturación (`FacturasTest`, `ConstructorPayloadFacturapiTest`, `FacturaDocumentosTest`) **no
  cambian**.

## Fuera de alcance

- Cualquier modificación a Facturación (012, 015): ni cuenta en `Factura` o `ComplementoPago`, ni
  "registrar pago" sobre facturas; timbrar nunca genera movimientos.
- Integración con Pedidos ([remotas/027](remotas/027-venta-mostrador-ticket.md)) y Órdenes de Compra
  ([remotas/012](remotas/012-ordenes-compra.md)): el enganche queda listo (morph map, servicio), sin
  implementación.
- Reportes financieros (totales por periodo, gráficas, exportación a Excel/PDF).
- Reportes de rentabilidad agregados y desglose de utilidad por línea.
- Recalcular el costo de líneas ya guardadas.
- Múltiples divisas: todo es MXN.
- Sobregiro o saldo negativo en cualquier cuenta.
- Edición del `saldo_inicial` (la vía es un ajuste) y edición de transferencias (se eliminan y se
  recapturan).
- Cambiar el tipo de un movimiento ya guardado.
- Edición de un `CotizacionPago`: solo se elimina el último y se vuelve a capturar.
- Eliminación de pagos de una cotización `producto_entregado`.
- Conciliación bancaria, importación de estados de cuenta, categorías o centros de costo.
- Adjuntar comprobantes a un movimiento.
- Bitácora de quién modificó o eliminó un movimiento.
- Permisos por rol dentro del módulo: cada usuario administra solo sus cuentas.

## Estado de implementación

Implementada el 2026-10-01.

- **Archivos nuevos**:
  - migración `2026_10_01_100000_create_tesoreria_tables` (revisada con `migrate --pretend` contra
    MySQL; **no se ha corrido en la base local**),
  - enums `TipoCuenta`, `TipoMovimiento`; modelos `Cuenta`, `Movimiento`; `CuentaFactory`,
  - `App\Services\Tesoreria\RegistradorMovimientos`; excepciones `OperacionTesoreriaRechazada`,
    `SaldoNegativoException`, `CuentaInactivaException`,
  - `CuentaController`, `MovimientoController`, `TransferenciaController`, `SaldoController`,
  - `CuentaRequest`, `MovimientoRequest`, `TransferenciaRequest`, `ListadoMovimientosRequest` y el
    trait `Requests\Concerns\ValidaCuentas`,
  - `CuentaPolicy`, `MovimientoPolicy`,
  - vistas `tesoreria/*` y `cotizaciones/_cuenta-pago`,
  - pruebas `TesoreriaCuentasTest`, `TesoreriaMovimientosTest`, `TesoreriaTransferenciasTest`,
    `TesoreriaSaldosTest`, `TesoreriaUtilidadTest`, `TesoreriaMigracionTest`.
- **Archivos modificados**: `Cotizacion` (`utilidadVenta()`), `CotizacionPago` (`cuenta`,
  `movimiento`, `conceptoMovimiento()`), `User` (`cuentas`, `movimientos`), `AppServiceProvider`
  (morph map), `CotizacionPagoRequest`, `CotizacionPagoController`, `CotizacionController`
  (`$cuentas` en lugar de `$formasPago`), `routes/web.php`, `layouts/app`, `app.css`,
  `cotizaciones/_dialogos-pago` y `cotizaciones/show`, y las pruebas que creaban pagos con
  `forma_pago`.
- **Decisiones al implementar**:
  - Además de `SaldoNegativoException` existe `CuentaInactivaException` (las dos heredan de
    `OperacionTesoreriaRechazada`): cubre la cuenta que se desactiva entre el formulario y la
    escritura. Cada controlador atrapa la base y decide el campo del error.
  - El filtro "hasta" compara con `< día siguiente`: SQLite guarda la fecha con hora y así el
    índice `(user_id, fecha)` sigue sirviendo en MySQL.
  - El listado resuelve la cuenta del otro lado de cada transferencia con una sola consulta por
    página.
- **Verificación**: las pruebas nuevas y `CotizacionPagosTest` pasan (98 pruebas). La única falla
  de la suite es anterior a esta historia (`EstiloUniformeTest` por un `<button>` en
  `dashboard.blade.php`). Pint no reporta cambios.

  **No se revisó la UI en un navegador real.** Falta probar los cuatro diálogos de Movimientos, la
  edición de un movimiento, la desactivación ofrecida al borrar una cuenta con movimientos y el
  pago de una cotización con el `select` de cuentas, en escritorio y en celular.

## Criterios de aceptación

1. Un usuario puede crear una cuenta con nombre, tipo (efectivo/banco/digital/otro) y saldo inicial;
   su saldo actual arranca igual al inicial.
2. El saldo inicial no se modifica después de creada la cuenta: el formulario lo muestra de solo
   lectura y un valor enviado en la edición se ignora.
3. Una cuenta inactiva no admite movimientos nuevos de ningún tipo, ni manuales ni pagos de
   cotización, pero su historial sigue visible y su saldo aparece en Saldos.
4. Una cuenta sin movimientos se puede eliminar; una con movimientos no se elimina, se muestra el
   motivo y se ofrece desactivarla.
5. Un ingreso suma, un egreso resta y un ajuste corrige según su signo, y el saldo de la cuenta cambia
   en el momento.
6. Ninguna operación deja una cuenta en negativo: egresos, ajustes negativos, transferencias, y
   también editar o eliminar un ingreso, eliminar una transferencia o eliminar un pago de
   cotización. La operación se rechaza con el motivo y no se guarda nada.
7. Una transferencia baja el saldo de la cuenta origen y sube el de la destino por el mismo monto, sin
   cambiar el total global. No se puede transferir a la misma cuenta. Eliminarla quita sus dos
   movimientos.
8. Un movimiento manual se edita y se elimina, y el saldo de las cuentas afectadas se recalcula.
9. Un movimiento automático no se edita ni se elimina: sus botones aparecen deshabilitados con el
   aviso de corregirlo desde la cotización, y el servidor lo rechaza.
10. Registrar un pago de cotización (anticipo, saldo o pago total) crea un ingreso en la cuenta
    elegida, con el monto y la fecha del pago y el concepto "<Tipo> de Cotización COT-<folio>".
11. El diálogo de pago de una cotización pide una cuenta (no la forma de pago SAT), y el historial de
    pagos muestra la cuenta de cada uno.
12. Crear una cotización o timbrar una factura no crea movimientos.
13. La forma de pago SAT de facturas y complementos de pago funciona exactamente igual que antes.
14. Eliminar el último pago de una cotización elimina también su movimiento, recalcula el saldo y, si
    la cotización estaba pagada y ya no cubre el total, la regresa a Enviada.
15. Solo se puede eliminar el último pago de una cotización.
16. El listado de movimientos filtra combinando fecha, cuenta, tipo y concepto, y cada movimiento
    automático enlaza a su cotización.
17. Saldos muestra el saldo de todas las cuentas, activas e inactivas, y el total global, y cada
    cuenta lleva a sus movimientos.
18. El saldo actual de una cuenta siempre es su saldo inicial más la suma de sus movimientos.
19. El menú muestra "Contabilidad", y desde ahí se llega a Movimientos, Cuentas y Saldos.
20. Un movimiento que viene de un pago de cotización muestra la utilidad de la cotización completa;
    los demás no muestran utilidad.
21. Una cotización cobrada en varios pagos muestra la misma utilidad en cada uno de sus movimientos.
22. La utilidad es la suma, por línea con costo, del importe ya neto de descuentos menos el costo
    capturado al vender.
23. Una cotización con líneas libres o líneas sin costo muestra su utilidad marcada como "Parcial";
    una sin ninguna línea con costo muestra "No disponible".
24. Los pagos de cotización que existían antes de esta historia quedan en la cuenta "Caja General" de
    su usuario, con su movimiento, y esa cuenta cuadra.
25. Nada de esto funciona con cuentas o movimientos ajenos (404).
26. Pint no reporta cambios y la suite Pest pasa completa.

## Supuestos asumidos (registro completo)

1. Tesorería es por usuario (`user_id`), con Policies que responden 404 a lo ajeno, igual que los
   demás módulos. Los roles existentes no cambian nada aquí.
2. Una cuenta tiene nombre, tipo (`efectivo`/`banco`/`digital`/`otro`), saldo inicial y estado
   activa/inactiva.
3. Una cuenta inactiva no recibe movimientos nuevos, pero conserva su historial y sigue en el listado
   y en Saldos.
4. Una cuenta solo se elimina si nunca tuvo movimientos; si los tiene, solo se desactiva.
5. El saldo inicial se define al crear la cuenta; para corregirlo se usa un ajuste.
6. El saldo de una cuenta nunca queda en negativo.
7. Un ingreso o egreso manual pide cuenta, monto positivo, fecha real y concepto libre.
8. Una transferencia pide cuenta origen y destino distintas, monto positivo, fecha y concepto; para
   el usuario es una sola operación.
9. Un ajuste pide cuenta, monto positivo o negativo, fecha y motivo.
10. Un movimiento manual se edita o elimina sin restricción de fecha. *Precisión al redactar:* el tipo
    no se edita; para cambiarlo se elimina y se vuelve a capturar.
11. Cada pago de cotización genera un ingreso en el momento en que se registra.
12. El `select` de forma de pago SAT del pago de cotización se reemplaza por uno de cuentas.
13. Facturación no se modifica y nunca genera movimientos. La cotización es la fuente de verdad de los
    pagos; la factura, del CFDI.
14. Pedidos y Órdenes de Compra no existen en este proyecto; el mecanismo queda genérico.
15. Un movimiento automático solo se consulta; se corrige desde su documento.
16. RN-013 se cumple con las reglas vigentes de 011 (Policy, llave `restrict` y caducidad sin pagos).
17. El concepto automático lo genera el sistema y no se edita.
18. El listado filtra combinando rango de fechas, cuenta, tipo y concepto.
19. Saldos muestra todas las cuentas en una sola pantalla, sin desglose.
20. Los reportes financieros se construirán sobre estos datos en otra historia.
21. Todo es MXN.
22. El menú dice "Contabilidad"; el nombre técnico es `tesoreria`.
23. La utilidad es la del documento completo, sin IVA y con los descuentos aplicados, y solo existe en
    movimientos de pagos de cotización.

**Asunciones de la auditoría (aprobadas):**

1. La regla de saldo no negativo también aplica al eliminar o reducir un ingreso, al eliminar una
   transferencia y al eliminar un pago de cotización.
2. Una transferencia no se edita: se elimina y se vuelve a capturar.
3. Se puede eliminar un pago de cotización (y su movimiento) aunque su cuenta ya esté inactiva.
4. Los movimientos manuales no aceptan fecha futura, igual que los pagos.
5. Se puede desactivar una cuenta con saldo distinto de 0.
6. Utilidad: las líneas libres y las líneas de catálogo sin costo se excluyen y marcan "Parcial"; si
   ninguna línea tiene costo, "No disponible".
7. Un solo enlace "Contabilidad" en el menú, con pestañas Movimientos / Cuentas / Saldos dentro del
   módulo.
8. El concepto automático usa la etiqueta del tipo de pago y el folio con el formato que ya usa la
   cotización ("COT-0012").
9. La migración crea "Caja General" por usuario con pagos, les asigna esa cuenta y genera sus
   movimientos para que el saldo cuadre.

**Adiciones técnicas aprobadas:**

1. Servicio único `RegistradorMovimientos` para crear, editar y eliminar movimientos y recalcular
   saldos.
2. `monto` guardado con signo (el efecto sobre el saldo).
3. Orden de bloqueo fijo: cuentas por `id` ascendente; en pagos, primero la cotización y después la
   cuenta.
4. Enums `TipoCuenta` y `TipoMovimiento`.
5. Llaves `restrictOnDelete` hacia `cuentas` e índices de `movimientos`.
6. `CuentaPolicy` y `MovimientoPolicy`, con el motivo "corrígelo desde el documento origen".
7. Precarga del documento origen con sus líneas en el listado de movimientos.
8. Pruebas Pest del módulo y ajuste de las existentes.

*Precisiones al redactar:* `decimal(14,2)` en lugar de `(12,2)` para alinearse con
`cotizacion_pagos.monto`; morph map con alias `cotizacion_pago`; edición de movimientos en su propia
página en lugar de un diálogo, para no necesitar JavaScript; el criterio del último pago sigue
siendo por `id`, como ya está implementado.
