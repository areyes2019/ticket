# Spec: Órdenes de compra (captura, envío al proveedor, pago de contado y recepción)

**Referencia:** reescritura de [remotas/012-ordenes-compra.md](remotas/012-ordenes-compra.md), que se
diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan sus reglas de negocio:
ciclo `borrador` → `enviada` → `pagada` → `recibida` con sus dos retrocesos, precio precargado al
costo y editable sin tocar el catálogo, artículos filtrados por proveedor, pago único de contado
como egreso de Tesorería sujeto a saldo no negativo, cancelación del pago, recepción manual,
duplicar, y el bloqueo real del borrado de proveedores. La parte del navegador y el protocolo entre
navegador y servidor se rehicieron para Laravel + Blade + JavaScript nativo.

**Toma la distribución de:** [013-dashboard-bandeja-correo.md](013-dashboard-bandeja-correo.md),
igual que [014-cotizaciones-bandeja.md](014-cotizaciones-bandeja.md): el listado es una bandeja de
tres columnas (carpetas, lista y visor) con sus piezas y estilos (ver "Bandeja").

**Modifica:** [004-gestion-proveedores.md](004-gestion-proveedores.md) (`tiene_ordenes_activas`
deja de ser columna y se deriva de las órdenes), [011-cotizaciones.md](011-cotizaciones.md)
(`articulos.sugerencias` acepta proveedor y precio de costo; `CotizacionMail` usa el trait
`AdjuntaPdf`) y [016-tesoreria.md](016-tesoreria.md) (segundo tipo de documento origen de
movimientos: la orden de compra, como egreso).

Se retiró de la remota lo que pertenecía a la arquitectura anterior o a historias que aquí no
existen:

- API REST, Sanctum, API Resources, pantallas Vue, stores y Vitest.
- El envío de WhatsApp por Twilio y la ruta de PDF público firmada: aquí rige la regla de 011,
  **el servidor nunca envía mensajes de WhatsApp**; se comparte desde el aparato del usuario.
- El botón "Compartir PDF" separado de "Enviar por WhatsApp": con el menú del sistema serían el
  mismo botón (asunción 1 de la auditoría).
- La comparación del `total` enviado por el navegador: aquí los totales nunca se aceptan del
  formulario.
- Las generalizaciones que la remota pedía y que este proyecto ya tiene: la tabla de líneas
  compartida (`documentos/_linea`, `documento-lineas.js`, `totales-documento.js`), el fixture
  compartido de totales (`tests/Fixtures/totales-documentos.json`) y el servicio único de
  movimientos (`RegistradorMovimientos`). La orden de compra solo los consume.
- Las referencias a Inventario ([remotas/017](remotas/017-inventario.md)), ajuste al peso
  ([remotas/030](remotas/030-total-al-peso-cerrado.md)) y PWA
  ([remotas/029](remotas/029-pwa-mostrador.md)): recibir una orden no toca existencias.

> **Modificada por [018-inventario.md](018-inventario.md):** recibir una orden **sí** suma sus
> líneas a existencias, dentro de una transacción que vuelve a comprobar `puedeRecibirse()`. Las
> afirmaciones de esta spec de que recibir no toca existencias quedan superadas por la 018.

## Historia de usuario

Como usuario registrado, quiero generar órdenes de compra a mis proveedores, con el mismo estilo de
una cotización, enviarlas por correo y tener la opción de compartirlas por WhatsApp.

## Objetivo / Alcance

Implementar el módulo de Órdenes de compra sobre la arquitectura monolítica Laravel + Blade +
JavaScript nativo de [001-inicio-proyecto.md](001-inicio-proyecto.md), con la sesión web de
[002-login.md](002-login.md), los componentes Blade de [003-estilo-uniforme.md](003-estilo-uniforme.md),
los [Proveedores](004-gestion-proveedores.md), los [Artículos](007-gestion-articulos.md) con sus
[catálogos](008-catalogos.md) y su [costo](009-precio-proveedor-utilidad.md), y conectado a
[Tesorería](016-tesoreria.md).

- Laravel resuelve el módulo completo: rutas web, controladores, Form Requests, una Policy y las
  vistas. No se crea API, no hay stores ni estado en el frontend.
- Los modales son `<dialog>` nativos con formularios normales (`data-abrir-dialogo`,
  `data-abrir-al-cargar`). Todo funciona sin JavaScript salvo el buscador de artículos y el
  compartir por WhatsApp, igual que en cotizaciones.
- **El único JavaScript que cambia** es `documento-lineas.js`, para vaciar las líneas al cambiar de
  proveedor (ver "JavaScript"). Lo demás reutiliza lo existente.

La orden de compra es el documento espejo de la cotización: la cotización le dice a un cliente
"esto te vendo, a este precio de venta"; la orden le dice a un proveedor "esto te compro, a este
costo". La aritmética es idéntica; lo que cambia es de qué lado del negocio está el dinero.

**No** incluye: inventario o existencias, recepción parcial, timbrado, captura del CFDI que el
proveedor emite al usuario, cuentas por pagar ni multiempresa.

## Backend (Laravel)

### Enum `EstadoOrdenCompra` (`app/Enums`) (adición 1)

Con `etiqueta()` y `opciones()`, como los existentes.

| Valor | Etiqueta |
|---|---|
| `borrador` | Borrador |
| `enviada` | Enviada |
| `pagada` | Pagada |
| `recibida` | Recibida |

- `esEditable(): bool` → `borrador` o `enviada`.
- `esActiva(): bool` → todo menos `recibida` (lo usa el bloqueo de proveedores).

### Migración `..._create_ordenes_compra_tables.php` (adición 7)

**Tabla `users`**: gana `ultimo_folio_orden_compra` (`unsignedInteger`, default 0) después de
`ultimo_folio_factura`.

**Tabla `ordenes_compra`**

| Columna | Definición |
|---|---|
| `id` | |
| `user_id` | `foreignId` → `users` |
| `proveedor_id` | `foreignId` → `proveedores` (sin cascada: el proveedor usa soft delete) |
| `duplicada_de_id` | `foreignId` nullable → `ordenes_compra`, `nullOnDelete` |
| `folio` | `unsignedInteger` |
| `estado` | `string(10)`, default `borrador` (`EstadoOrdenCompra`) |
| `fecha_entrega_esperada` | `date`, nullable |
| `observaciones` | `text`, nullable |
| `descuento_global_tipo` | `string`, nullable (`TipoDescuento`) |
| `descuento_global_valor` | `decimal(10,2)`, nullable |
| `subtotal`, `total_descuento`, `base_iva_16`, `total_iva_16`, `base_iva_0`, `base_exento`, `total` | `decimal(14,2)` |
| `cuenta_id` | `foreignId` nullable → `cuentas`, **`restrictOnDelete`** |
| `fecha_pago` | `date`, nullable |
| `timestamps` | |

Índices: `(user_id, folio)` único, `(user_id, created_at)`, `(user_id, estado)` y
`(proveedor_id, estado)` (el bloqueo de borrado de proveedores).

**Tabla `orden_compra_lineas`**

| Columna | Definición |
|---|---|
| `id` | |
| `orden_compra_id` | `foreignId` → `ordenes_compra`, `cascadeOnDelete` |
| `orden` | `unsignedSmallInteger` (posición en el documento) |
| `articulo_id` | `foreignId` nullable → `articulos` (null = línea libre) |
| `cantidad` | `unsignedInteger` |
| `descripcion` | `string(255)` |
| `modelo` | `string(255)`, nullable |
| `precio_unitario` | `decimal(10,2)` |
| `descuento_tipo` | `string`, nullable |
| `descuento_valor` | `decimal(12,2)`, nullable |
| `tasa_iva` | `string(10)` (`TasaIva`) |
| `importe`, `iva_importe` | `decimal(14,2)` |
| `timestamps` | |

**Tabla `proveedores`** (adición 5): pierde `tiene_ordenes_activas`.

`down()` borra las tablas nuevas, quita `ultimo_folio_orden_compra` y devuelve
`tiene_ordenes_activas` (boolean, default `false`).

### Morph map

`AppServiceProvider` agrega `'orden_compra' => OrdenCompra::class` a `Relation::enforceMorphMap()`.

### Modelo `OrdenCompra` (tabla `ordenes_compra`)

- `protected $table = 'ordenes_compra'`: `Str::plural` inferiría `orden_compras`.
- Pertenece a un `User` (`user_id` **no** asignable; el alta usa
  `$request->user()->ordenesCompra()->create(...)`) y a un `Proveedor`.
- **`proveedor()` incluye eliminados (`->withTrashed()`)** (adición 6): una orden `recibida` ya no
  bloquea el borrado de su proveedor, y la orden sigue existiendo como documento; sin esto el detalle
  y el PDF fallan al leer el proveedor.
- `folio`, `estado`, `cuenta_id`, `fecha_pago`, los totales y `duplicada_de_id` **no son
  asignables**: los escriben el controlador y los métodos del modelo.
- **Sin soft delete**: el borrado es físico y se lleva sus líneas.
- Casts: `estado` → `EstadoOrdenCompra`, `descuento_global_tipo` → `TipoDescuento`, totales y
  `descuento_global_valor` → `decimal:2`, `fecha_entrega_esperada` y `fecha_pago` → `date`.
- Relaciones: `user()`, `proveedor()`, `lineas()` (`hasMany`, por `orden`), `cuenta()`
  (`belongsTo`), `movimiento()` (`morphOne` `documentable`), `duplicadaDe()`. En `User`:
  `ordenesCompra()`. En `Proveedor`: `ordenesCompra()`.
- `aplicarTotales(array $totales)`: igual que `Cotizacion`.
- Accessor `folio_formateado` → `OC-0015` (4 dígitos, como `COT-0012`; asunción 2 de la auditoría).
- **Métodos de regla** (adición 1; los usan controlador, Policy y Blade; nadie reimplementa la
  condición):
  - `esEditable()` → `estado->esEditable()`.
  - `puedeEliminarse()` → `borrador`.
  - `puedeRegistrarPago()` → `enviada`.
  - `puedeCancelarPago()` → `pagada`.
  - `puedeRecibirse()` → `pagada`.
  - `estaPagada()` → `cuenta_id !== null`.
  - `marcarEnviada()` → `borrador` pasa a `enviada`; en cualquier otro estado no cambia el estado.
  - `conceptoPago(): string` → `"Pago de Orden de compra {folio_formateado}"`.
- Scope `filtrar(array $filtros)` para el listado (ver "Validaciones").

**Pago sin tabla.** El pago es único, de contado y por el total. Vive en `cuenta_id`/`fecha_pago`:
los dos en `null` = no pagada; los dos con valor = pagada. Una tabla de pagos tendría siempre cero o
una fila. Pagar en parcialidades, si algún día se pide, exigirá migrar a una tabla propia.

#### El IVA de una orden de compra

El IVA de la orden es el que **cobra el proveedor** (IVA acreditable). Como el sistema no lleva
contabilidad fiscal de IVA, la distinción no cambia ninguna fórmula: la aritmética es la de 011 y
solo cambian etiquetas del PDF.

### Folio

Mismo mecanismo que la cotización (011): numeración propia por usuario, independiente de
cotizaciones y facturas, que **nunca se reutiliza**. Al crear o duplicar, dentro de la transacción,
`User::whereKey(...)->lockForUpdate()->first()`; el folio nuevo es
`max(ultimo_folio_orden_compra, folio máximo del usuario) + 1` y se guarda como contador. El índice
único `(user_id, folio)` es la última red.

### Modelo `OrdenCompraLinea` (tabla `orden_compra_lineas`)

Misma forma que `CotizacionLinea`, sin `costo_unitario` (el precio de la línea ya es el costo):

- `articulo_id` nullable: **se aceptan líneas libres** (asunción 3 de la auditoría), para fletes,
  maniobras o cualquier concepto que el proveedor cobre y no esté en su catálogo.
- `descripcion` y `modelo`: copias desacopladas del artículo, editables.
- `precio_unitario`: se precarga con el **`costo_con_descuento`** del artículo (009) —el precio de
  lista del proveedor con el descuento de su catálogo, lo que efectivamente se le paga—, **no** con
  `precio_unitario_sin_iva`. Es editable, porque el proveedor puede cotizar distinto ese día.
  Editarlo **no** modifica `precio_proveedor` ni recalcula precios de venta: la orden es un documento
  independiente del catálogo.
- `tasa_iva`: misma precarga que en cotización (`16` si `objeto_imp = 02`, si no `exento`; libre →
  `16`).
- `importe` e `iva_importe`: calculados en el servidor.

### Totales

`CalculadoraTotalesDocumento::calcular()` tal cual, sin cambios: ya es genérica. El servidor recalcula
en cada alta y edición; ningún total, importe, folio, estado o dato de pago se acepta del formulario.
El resumen del navegador es informativo. El fixture compartido de 011 ya cubre la fórmula para los
tres documentos.

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]`, **antes** del
`Route::resource`:

```php
Route::get('ordenes-compra/buscar', [OrdenCompraController::class, 'buscar'])->name('ordenes-compra.buscar');
Route::post('ordenes-compra/{ordenCompra}/enviar', [EnvioOrdenCompraController::class, 'correo'])->name('ordenes-compra.enviar');
Route::post('ordenes-compra/{ordenCompra}/marcar-enviada', [EnvioOrdenCompraController::class, 'marcarEnviada'])->name('ordenes-compra.marcar-enviada');
Route::get('ordenes-compra/{ordenCompra}/pdf', [OrdenCompraController::class, 'pdf'])->name('ordenes-compra.pdf');
Route::post('ordenes-compra/{ordenCompra}/duplicar', [OrdenCompraController::class, 'duplicar'])->name('ordenes-compra.duplicar');
Route::post('ordenes-compra/{ordenCompra}/recibir', [OrdenCompraController::class, 'recibir'])->name('ordenes-compra.recibir');
Route::post('ordenes-compra/{ordenCompra}/pago', [OrdenCompraPagoController::class, 'store'])->name('ordenes-compra.pago.store');
Route::delete('ordenes-compra/{ordenCompra}/pago', [OrdenCompraPagoController::class, 'destroy'])->name('ordenes-compra.pago.destroy');

Route::resource('ordenes-compra', OrdenCompraController::class)
    ->parameters(['ordenes-compra' => 'ordenCompra']);
```

`->parameters(...)` es obligatorio desde el primer commit: sin él Laravel singulariza en inglés y el
binding implícito falla. Se usa `ordenCompra` (camelCase) para que el argumento del controlador sea
`$ordenCompra`.

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/ordenes-compra` | listado, filtros y página en la URL | `ordenes-compra.index` |
| GET | `/ordenes-compra/buscar` | fragmento HTML para la búsqueda dinámica | `ordenes-compra.buscar` |
| GET | `/ordenes-compra/{ordenCompra}/vista-previa` | fragmento HTML del visor de la bandeja | `ordenes-compra.vista-previa` |
| GET | `/ordenes-compra/crear` | formulario de alta | `ordenes-compra.create` |
| POST | `/ordenes-compra` | alta | `ordenes-compra.store` |
| GET | `/ordenes-compra/{ordenCompra}` | detalle con pago y acciones | `ordenes-compra.show` |
| GET | `/ordenes-compra/{ordenCompra}/editar` | formulario de edición | `ordenes-compra.edit` |
| PUT | `/ordenes-compra/{ordenCompra}` | edición | `ordenes-compra.update` |
| DELETE | `/ordenes-compra/{ordenCompra}` | borrado físico | `ordenes-compra.destroy` |
| POST | `/ordenes-compra/{ordenCompra}/enviar` | correo con el PDF adjunto | `ordenes-compra.enviar` |
| POST | `/ordenes-compra/{ordenCompra}/marcar-enviada` | tras compartir por WhatsApp (AJAX, JSON) | `ordenes-compra.marcar-enviada` |
| GET | `/ordenes-compra/{ordenCompra}/pdf` | PDF al vuelo (`?descargar=1` fuerza descarga) | `ordenes-compra.pdf` |
| POST | `/ordenes-compra/{ordenCompra}/duplicar` | copia y redirige a su detalle | `ordenes-compra.duplicar` |
| POST | `/ordenes-compra/{ordenCompra}/recibir` | marca `recibida` | `ordenes-compra.recibir` |
| POST | `/ordenes-compra/{ordenCompra}/pago` | registra el pago de contado | `ordenes-compra.pago.store` |
| DELETE | `/ordenes-compra/{ordenCompra}/pago` | cancela el pago | `ordenes-compra.pago.destroy` |

No hay ruta pública ni firmada.

### Controladores

- **`OrdenCompraController`**
  - `index`: la bandeja. Órdenes del usuario con `proveedor` precargado, filtradas con
    `ListadoOrdenesCompraRequest`, orden `created_at desc, id desc`, 25 por página con
    `withQueryString()`. Además pasa `abierta` (la de `?orden=` si es del usuario —una ajena o
    inexistente se ignora— o la primera de la página) y `contadores` (órdenes por carpeta, sin
    etiqueta ni búsqueda, en una sola consulta), como `CotizacionController` (014).
  - `buscar`: la misma consulta; devuelve `ordenes-compra/_resultados` (carpetas, filas y
    paginación) con `->withPath(route('ordenes-compra.index'))`.
  - `vistaPrevia`: `Gate::authorize('view')` (ajena → 404) y el parcial `_vista-previa`.
  - `create`: proveedores del usuario (sin eliminados, por nombre comercial).
  - `store`: en una transacción asigna folio, crea la orden en `borrador`, guarda líneas y totales.
    Redirige al detalle: "Orden de compra OC-0015 creada."
  - `show`: con `lineas`, `proveedor` y `cuenta`. Pasa `$cuentas` (cuentas **activas** del usuario,
    `id → nombre`) para el diálogo de pago.
  - `edit` / `update`: solo si `esEditable()` (la Policy lo niega con mensaje). `update` borra y
    vuelve a crear las líneas, recalcula totales y, si estaba `enviada`, la regresa a `borrador` con
    el flash "La orden volvió a borrador: reenvíala para que el proveedor vea los cambios."
  - `destroy`: solo `borrador`. Redirige al listado.
  - `pdf`: `GeneradorPdfOrdenCompra`, `inline` (o `attachment` con `?descargar=1`), nombre
    `orden-compra-OC-0015.pdf`. No persiste copia.
  - `duplicar`: copia `proveedor_id`, descuento global, observaciones, líneas (con sus importes) y
    totales; folio nuevo, `estado = borrador`, sin pago, **sin** `fecha_entrega_esperada`, y
    `duplicada_de_id` = la original (asunción 6 de la auditoría). Redirige al detalle de la copia.
    Se puede duplicar en cualquier estado.
  - `recibir`: solo si `puedeRecibirse()`. Acción manual, total, sin validar cantidades y
    **sin efecto sobre existencias** (superado por [018](018-inventario.md): ahora suma
    existencias). Irreversible en esta historia. Flash "Orden de compra OC-0015 recibida."
- **`EnvioOrdenCompraController`**: igual que `EnvioCotizacionController`.
  - `correo`: `EnviarOrdenCompraRequest`, envía `OrdenCompraMail` síncrono; si falla, error en la
    bolsa `envio` y el estado no cambia. Si sale, `marcarEnviada()`. Se puede enviar o reenviar en
    cualquier estado; solo `borrador` cambia.
  - `marcarEnviada`: `Gate::authorize('operar', $ordenCompra)`, `marcarEnviada()`, responde
    `{ estado, etiqueta }`.
- **`OrdenCompraPagoController`**: ver "Pago de contado".
- **`ArticuloController::sugerencias`** (cambia; ver "Integración con Artículos").
- **`ProveedorController`** (cambia; ver "Integración con Proveedores").
- **`MovimientoController::index`** (cambia; ver "Integración con Tesorería").

Todos filtran por `user_id`; lo ajeno responde 404.

### Pago de contado (`OrdenCompraPagoController`)

**`store`** (`OrdenCompraPagoRequest`, bolsa `pago`):

1. Abre `DB::transaction` y **bloquea la orden** con `lockForUpdate()` (adición 3): dos clics
   seguidos no registran dos pagos.
2. Vuelve a comprobar `puedeRegistrarPago()` con la fila bloqueada; si ya no, error en la bolsa
   `pago`.
3. Escribe `cuenta_id`, `fecha_pago` y `estado = pagada`.
4. Llama a `RegistradorMovimientos::registrar($cuenta, TipoMovimiento::Egreso, $orden->total, $fecha_pago, $orden->conceptoPago(), $orden)`,
   que bloquea la cuenta, crea el movimiento (guardado en negativo) y recalcula el saldo. Orden de
   bloqueo: **orden → cuenta**, el mismo criterio que cotización → cuenta en 016.
5. **El monto es siempre el `total` de la orden**, tomado del servidor. Un `monto` en la petición se
   ignora en silencio.

Excepciones (adición 4): `SaldoNegativoException` y `CuentaInactivaException` (las dos heredan de
`OperacionTesoreriaRechazada`) deshacen la transacción —ni pago ni movimiento, la orden sigue
`enviada`— y se convierten en error del campo `cuenta_id` en la bolsa `pago`, con su mensaje ("El
movimiento dejaría la cuenta {nombre} con saldo negativo." / "La cuenta {nombre} está inactiva."),
para que el diálogo se reabra con el aviso.

La regla de saldo no negativo, que en 016 solo se activaba al quitar ingresos, aquí es una condición
de la operación diaria: no se paga una orden desde una cuenta sin fondos.

Éxito: "Pago de $417.60 registrado en {cuenta}. La orden quedó pagada."

**`destroy`** (cancelar el pago):

1. `DB::transaction`, bloquea la orden y comprueba `puedeCancelarPago()` (`recibida` → aviso de
   error "Una orden recibida no admite cancelar su pago.").
2. `RegistradorMovimientos::eliminarDeDocumento($orden)`: borra el egreso y recalcula el saldo.
   No exige cuenta activa (quitar un movimiento no es un movimiento nuevo, 016). Quitar un egreso
   solo suma, así que no puede dejar saldo negativo.
3. Limpia `cuenta_id`/`fecha_pago` y regresa la orden a `enviada` (vuelve a ser editable).

Éxito: "Pago cancelado. La orden volvió a Enviada."

### PDF (`App\Services\OrdenesCompra\GeneradorPdfOrdenCompra`)

- Misma forma que `GeneradorPdfCotizacion`: `generar()`, `contenido()` y `nombreArchivo()`; carga
  `proveedor` y `lineas`; subconjunto de fuentes; tamaño carta.
- Vista `ordenes-compra/pdf.blade.php`, basada en `cotizaciones/pdf.blade.php` con estilos propios
  dentro de la vista. Diferencias:
  - encabezado **"Orden de compra"** y folio `OC-0015`;
  - bloque del **proveedor** (nombre comercial, razón social si existe, RFC, contacto, correo,
    teléfono) en lugar del cliente;
  - **fecha de entrega esperada** y **observaciones**, solo si existen;
  - desglose con la etiqueta "IVA 16% (acreditable)";
  - sin saldo pendiente ni datos de pago.

### Correo (`App\Mail\OrdenCompraMail`) y trait `AdjuntaPdf` (adición 9)

- `OrdenCompraMail`: asunto "Orden de compra OC-0015 — {app.name}", vista `emails/orden-compra`,
  síncrono (sin cola), PDF adjunto.
- Copia oculta al buzón del negocio (`negocio.copia_correos`, de fábrica `ventas@sellopronto.com.mx`)
  con el trait `CopiaAlNegocio`: ver [032](032-copia-correos-negocio.md).
- **Trait `App\Mail\Concerns\AdjuntaPdf`**: un método `adjuntoPdf(string $contenido, string $nombre): Attachment`
  con `Attachment::fromData(...)->withMime('application/pdf')`. Lo usan `CotizacionMail` y
  `OrdenCompraMail` en lugar de repetir el adjunto. `FacturaMail` no cambia (adjunta XML y PDF).
  `EnvioCotizacionTest` sigue siendo la red que verifica que el correo de la cotización no cambió.

### Integración con Artículos (007, 011)

`ArticuloController::sugerencias` acepta dos parámetros opcionales:

- `proveedor_id`: limita a artículos con `articulos.proveedor_id` igual (la copia del proveedor del
  catálogo que ya escribe el modelo). Un proveedor ajeno no devuelve nada.
- `precio=costo`: `precio_unitario` de la respuesta es `costo_con_descuento` en lugar de
  `precio_unitario_sin_iva`.

Sin parámetros responde exactamente lo mismo que hoy. Es el único cambio sobre el módulo de
Artículos.

### Integración con Proveedores (004) (adición 5)

- Se elimina la columna `tiene_ordenes_activas` (ver Migración), su entrada en `$attributes` y su
  cast.
- `Proveedor::tieneOrdenesActivas(): bool` → existe al menos una orden suya con estado distinto de
  `recibida` (`EstadoOrdenCompra::esActiva()`). **Un `borrador` también bloquea**: si hay un borrador
  colgando, borrarlo es un clic, y una regla con excepciones nadie la recuerda.
- `ProveedorController::destroy` usa el método; el mensaje no cambia ("No se puede eliminar: tiene
  órdenes de compra activas").
- `ProveedorFactory::conOrdenesActivas()` desaparece: las pruebas crean una orden real.

### Integración con Tesorería (016)

- `Movimiento::documentoOrigen()` agrega el caso `OrdenCompra`:

  ```php
  [
      'etiqueta' => 'OC-0015',
      'url' => route('ordenes-compra.show', $orden),
      'utilidad' => null,
      'utilidad_parcial' => false,
      'muestra_utilidad' => false,
  ]
  ```

  `muestra_utilidad` (true en el caso de cotización) le dice a la vista que deje vacía la columna de
  utilidad: un egreso no tiene utilidad de venta, y "No disponible" daría a entender que falta un
  dato.
- `MovimientoController::index` agrega `OrdenCompra::class => []` al `morphWith` (la orden es el
  propio documento; no hay que bajar líneas).
- El egreso aparece como **automático**: botones de editar y eliminar deshabilitados con "Se corrige
  desde OC-0015", y `MovimientoPolicy` lo rechaza en el servidor. La corrección es cancelar el pago.
- `RegistradorMovimientos` no cambia; solo se actualiza su comentario para decir que atiende dos
  documentos origen.

### Autorización (`OrdenCompraPolicy`) (adición 2)

- `view`, `update`, `delete`, `operar` → dueño; ajena → 404 (patrón `esDueno` de `CotizacionPolicy`).
- `update` → `Response::deny('Una orden pagada no se edita: cancela el pago primero.')` en
  `pagada`, y `'Una orden recibida ya no se edita.'` en `recibida`.
- `delete` → `deny('Solo se elimina una orden en borrador.')` fuera de `borrador`.
- `operar` (enviar, compartir, pagar, cancelar pago, recibir, duplicar, PDF) → dueño. Cada acción
  comprueba además su método de regla.

### Validaciones (Form Requests)

**`OrdenCompraRequest`** (alta y edición). Mismas reglas de línea y descuento global que
`CotizacionRequest` (011), con estas diferencias:

- `proveedor_id`: requerido, existe, es del usuario y no está eliminado. En edición se acepta el
  proveedor ya guardado aunque se haya eliminado después.
- `lineas.*.articulo_id`: nullable, `distinct`, artículo del usuario sin eliminar **cuyo
  `proveedor_id` sea el `proveedor_id` de la orden** ("El artículo de la línea :position no
  pertenece a un catálogo de este proveedor."). En edición se aceptan los artículos ya guardados en
  la orden aunque se hayan eliminado o cambiado de catálogo (asunción 7 de la auditoría): las líneas
  guardadas son copias.
- `fecha_entrega_esperada`: nullable, `date_format:Y-m-d`. Puede ser pasada o futura; es
  informativa.
- `observaciones`: nullable, string, máximo 2000.
- Totales, folio, estado, `cuenta_id`, `fecha_pago`, `user_id` y `duplicada_de_id` se ignoran.
- Sin regla "el total no puede ser menor a lo pagado": una orden pagada no se edita.

**`EnviarOrdenCompraRequest`**: igual que `EnviarCotizacionRequest` (bolsa `envio`,
`destinatarios_texto` partido en 1 a 5 correos), autorizando `operar` sobre `ordenCompra`.

**`OrdenCompraPagoRequest`** (bolsa `pago`):

- `cuenta_id`: requerido, cuenta del usuario, activa (trait `ValidaCuentas`, mismo mensaje de
  inactiva).
- `fecha_pago`: requerida, `date_format:Y-m-d`, **no futura** en la zona del negocio (asunción 4
  de la auditoría).
- `monto` no tiene regla: se ignora.
- El saldo no negativo **no** se valida aquí: lo revisa el servicio con la cuenta bloqueada.

**`ListadoOrdenesCompraRequest`**: los parámetros de la bandeja, como `ListadoCotizacionesRequest`
(014). No rechaza nada: un valor inválido se ignora.

| Parámetro | Valores | Por defecto |
|---|---|---|
| `periodo` | `hoy`, `semana`, `mes`, `todas` | `mes` |
| `estado` | `borrador`, `enviada`, `pagada`, `recibida` | ninguno |
| `q` | texto libre: nombre comercial, contacto o RFC del proveedor (incluidos eliminados; el RFC sin espacios y en mayúsculas), o folio (`15`, `0015`, `OC-0015`, con **O**) | vacío |
| `orden` | id de la orden abierta | la primera de la lista |
| `page` | página | 1 |

Los periodos son días calendario completos en `config('app.zona_negocio')`, convertidos a UTC antes
de comparar contra `created_at`; reutiliza `ListadoCotizacionesRequest::rango()`. `parametros()`
arma los enlaces sin `orden`: al cambiar de carpeta o etiqueta se abre la primera de la lista
nueva.

`attributes()` en español en todos.

## Vistas (Blade)

Todas con `layouts/app`, `x-card`, `x-campo`, `x-boton`, `x-alerta`, `x-icono` y `x-paginacion`.

### Navegación

En `layouts/app.blade.php`, **"Órdenes de compra"** (`bi-cart`) justo después de "Proveedores"
(asunción 8 de la auditoría).

### Bandeja (`ordenes-compra/index.blade.php`)

La misma distribución de 013 y 014, con órdenes reales del usuario:

- `@section('contenido-clase', 'contenido-bandeja')`: ocupa toda la pantalla bajo el menú.
- `<h1>` solo para lectores, avisos de sesión y errores de las bolsas `envio` y `pago`.
- Formulario oculto `#filtros-ordenes` con `data-busqueda-dinamica` y los campos ocultos `periodo` y
  `estado` (`data-busqueda-sincronizar`); el buscador de la lista se asocia con
  `form="filtros-ordenes"`.

| Columna | Contenido |
|---|---|
| Izquierda (`_carpetas`) | **"Nueva orden"** (`bi-plus-lg`), carpetas Hoy (`bi-calendar-day`), Esta semana (`bi-calendar-week`), Este mes (`bi-calendar-month`, activa al entrar) y Todas (`bi-inbox`) con contador; etiquetas Borrador, Enviada, Pagada y Recibida con el color de su estado |
| Centro | Buscador "Buscar por folio, proveedor o RFC", filas (`_filas`) y paginación (`_paginacion`) |
| Derecha | La vista previa de `abierta`, o "Selecciona una orden de compra" |

- Carpeta y etiqueta se combinan; pulsar la etiqueta activa la quita. Son enlaces normales
  (`data-busqueda-enlace`).
- **Fila** (`<x-ordenes-compra.fila>`): iniciales y nombre comercial del proveedor
  (`<x-bandeja.avatar>`), fecha (hora si es de hoy, "Ayer" o fecha corta), folio, total, etiqueta
  de estado y, si no está recibida y tiene fecha esperada, "Entrega dd/mm". Es un enlace al detalle
  con `data-vista-previa`. Sin resultados: "Sin órdenes de compra".
- **Vista previa** (`_vista-previa`): barra de acciones con "Volver" (celular), Enviar por correo,
  Compartir por WhatsApp (el PDF se baja al apuntar al botón), Registrar pago (si `enviada`), Marcar
  como recibida (si `pagada`), Duplicar, Ver PDF, Descargar y "Abrir" (el detalle). Editar, Cancelar
  pago y Eliminar siguen **solo** en el detalle. Debajo, la etiqueta de estado, el pago si existe y
  la hoja.
- Las acciones de la vista previa mandan `origen=bandeja` y regresan a la bandeja anterior (solo si
  la página anterior es la bandeja; si no, a `/ordenes-compra?orden={id}`); duplicar abre la copia
  en la bandeja. El trait `RegresaABandeja` gana `destinoOrdenCompra()`, con la misma regla que
  `destinoCotizacion()`.
- **Hoja** (`<x-ordenes-compra.hoja>`): el contenido del PDF en HTML. La usan la vista previa y el
  detalle.
- Las ventanas de envío y pago viven en el parcial `ordenes-compra/_dialogos` (con `$origen`
  opcional), compartido por el detalle y la vista previa.
- **Diseño adaptable**: el de 013 (tres columnas en escritorio; lista y visor con "Carpetas" en
  tableta; una columna con "Volver" en celular). Sin scroll horizontal.
- **Sin JavaScript**: carpetas, etiquetas y paginación recargan la página, el buscador funciona con
  Enter y una fila abre el detalle.

### `ordenes-compra/crear.blade.php` y `editar.blade.php` (`_formulario.blade.php`)

- Proveedor: `select` con `data-proveedor-orden` (nombre comercial — RFC).
- Tabla de líneas con `documentos/_linea`, `documentos/_aviso-duplicado` y
  `<template id="plantilla-linea">`, igual que cotización. El formulario lleva
  `data-documento-lineas` y `data-sugerencias="{{ route('articulos.sugerencias', ['precio' => 'costo']) }}"`;
  el script le agrega el `proveedor_id` elegido. Admite líneas libres (sin
  `data-sin-lineas-libres`).
- Sin proveedor elegido, el buscador de artículos queda deshabilitado con el texto "Elige primero el
  proveedor".
- Encabezado de la columna de precio: **"Costo unitario"**.
- Descuento global y resumen de totales marcado como "estimado".
- Fecha de entrega esperada (`date`, opcional) y observaciones (`textarea`, opcional, "Se imprimen
  en la orden para el proveedor").
- Reconstrucción desde `old('lineas')` o desde las líneas guardadas, con los errores en su celda; 3
  filas vacías para línea libre sin JavaScript.
- Al editar una orden `enviada`: aviso "Al guardar, la orden regresa a borrador y tendrás que
  reenviarla."
- Sin JavaScript, cambiar de proveedor no vacía nada: si quedan artículos de otro proveedor, el
  servidor rechaza esas líneas con su mensaje.

### `ordenes-compra/show.blade.php` — detalle

- Encabezado: folio, estado, proveedor, fecha de creación, fecha de entrega esperada si existe.
- El documento (líneas y totales) igual que en el PDF, y las observaciones si existen.
- **Pago**, si `estaPagada()`: cuenta, fecha y monto, con enlace a Contabilidad.
- **Acciones**, cada una visible según los métodos del modelo:
  - **"Editar"**: `@can('update')`.
  - **"Enviar por correo"**: `<dialog>` con `destinatarios_texto` prellenado con
    `Proveedor.correo`.
  - **"Compartir por WhatsApp"** (asunción 1 de la auditoría): botón `data-compartir-pdf` con
    `data-pdf`, `data-marcar` (`ordenes-compra.marcar-enviada`),
    `data-archivo="orden-compra-OC-0015.pdf"`, `data-telefono` (teléfono del proveedor sin `+52`,
    como en cotización), `data-precargar="al-cargar"` y
    `data-texto="Orden de compra OC-0015 de {app.name} por $417.60"`. Comparte PDF y texto por el
    menú del sistema (o descarga y abre `wa.me` en escritorio) y deja la orden `enviada`. Aparece
    en cualquier estado.
  - **"Registrar pago"**: si `puedeRegistrarPago()`. `<dialog>` con cuenta (`select` de `$cuentas`,
    vacía "Selecciona la cuenta"), fecha de pago (hoy por defecto) y el texto de confirmación "Se
    pagará el total de la orden: $417.60", **sin campo de monto**. Con errores de la bolsa `pago` se
    reabre solo (`data-abrir-al-cargar`) con el mensaje dentro. Sin cuentas activas: "Para registrar
    pagos, primero crea una cuenta en Contabilidad" con enlace a `tesoreria.cuentas.create`, y el
    botón deshabilitado (parcial `cotizaciones/_cuenta-pago` reutilizado). Botón con
    `data-enviar-una-vez`.
  - **"Cancelar pago"**: si `puedeCancelarPago()`. Formulario DELETE con `data-confirmar="Se
    eliminará el egreso de $417.60 en Contabilidad, se recalculará el saldo de {cuenta} y la orden
    volverá a Enviada para que puedas editarla."`.
  - **"Marcar como recibida"**: si `puedeRecibirse()`, con `data-confirmar="¿Marcar la orden como
    recibida? Ya no podrás cancelar su pago ni editarla."`.
  - **"Duplicar"**: formulario POST con `data-enviar-una-vez`.
  - **"Ver PDF"** (pestaña nueva) y **"Descargar PDF"**.
  - **"Eliminar"**: `@can('delete')`, `data-confirmar` que advierte que el borrado es definitivo.
- `x-alerta` oculta con `data-compartir-error` para los avisos del script.

### `emails/orden-compra.blade.php`

Texto breve: saludo al contacto del proveedor, folio, total, fecha de entrega esperada si existe y
"Adjuntamos la orden en PDF".

### Tesorería: listado de movimientos

La columna "Origen" muestra el enlace "OC-0015" con la etiqueta "Automático", igual que las
cotizaciones; la columna "Utilidad" queda vacía cuando `muestra_utilidad` es `false`.

## JavaScript

- **`documento-lineas.js`** (cambia, genérico): si el formulario tiene un `select[data-proveedor-orden]`:
  - agrega `proveedor_id` a la URL de sugerencias y deshabilita el buscador mientras no haya
    proveedor;
  - al cambiar el proveedor **con líneas de artículo capturadas**, pide confirmación ("Cambiar de
    proveedor quitará las N líneas de artículos capturadas. ¿Continuar?"). Si acepta, quita las
    líneas con `articulo_id` (las libres se quedan, porque no dependen del proveedor) y recalcula;
    si cancela, regresa el `select` al proveedor anterior.
  - Cotización y factura no tienen ese `select`, así que su comportamiento no cambia.
- **`bandeja-cotizaciones.js` pasa a `bandeja-documentos.js`** y se vuelve genérico: se activa en
  `[data-bandeja-documentos]`, con `data-parametro` (el parámetro de la URL y el atributo de las
  filas: `cotizacion` o `orden`) y `data-sin-seleccion` (texto del visor vacío). El visor es
  `[data-visor-documento]`. La bandeja de cotizaciones no cambia de comportamiento.
- **`compartir-pdf.js`**, **`busqueda-dinamica.js`**, **`totales-documento.js`** y `app.js`
  (diálogos, `data-confirmar`, `data-enviar-una-vez`): sin cambios.

## Pruebas (Pest y Node) (adición 8)

- **`OrdenesCompraTest`** (nuevo):
  - alta en `borrador` con folio `OC-0001`; folios consecutivos por usuario e independientes de
    cotizaciones; un folio borrado no se reutiliza;
  - totales recalculados en servidor; un `total` manipulado en la petición se ignora;
  - artículo de otro proveedor → error; artículo ajeno → error; línea libre aceptada;
  - el precio guardado es el capturado y **no** modifica `precio_proveedor`, `costo_con_descuento`
    ni `precio_unitario_sin_iva` del artículo;
  - editar una `enviada` la regresa a `borrador`; `pagada`/`recibida` → 403 con mensaje en editar y
    actualizar;
  - eliminar solo en `borrador`;
  - recibir solo desde `pagada`; desde otro estado no cambia nada;
  - duplicar: folio nuevo, `borrador`, mismas líneas/descuento/observaciones, sin pago, sin fecha
    esperada, `duplicada_de_id`;
  - filtros combinados (proveedor, RFC, folio `OC-0015` y `15`, estado, rango y periodo) y "Este
    mes" por defecto;
  - PDF: responde `application/pdf` con el nombre correcto, también con el proveedor eliminado
    (`withTrashed`);
  - todo lo ajeno → 404.
- **`OrdenCompraPagoTest`** (nuevo):
  - pagar una `enviada` crea un egreso por el total (guardado en negativo) con la fecha, el concepto
    "Pago de Orden de compra OC-0001" y `documentable` = la orden; la orden queda `pagada` y el saldo
    baja exactamente el total;
  - un `monto` en la petición se ignora;
  - pagar en `borrador`, `pagada` o `recibida` → error;
  - saldo insuficiente → error en `cuenta_id` de la bolsa `pago`, sin movimiento, orden `enviada`;
  - cuenta inactiva o ajena → error; fecha futura → error;
  - cancelar el pago borra el egreso, devuelve el saldo y regresa a `enviada`; funciona con la cuenta
    ya inactiva; en `recibida` → aviso y nada cambia;
  - el egreso no se edita ni se elimina desde Tesorería (403) y el listado de movimientos lo enlaza a
    la orden sin consulta por fila.
- **`EnvioOrdenCompraTest`** (nuevo): `Mail::fake()`; el correo lleva el PDF adjunto, los
  destinatarios y el asunto; `borrador` → `enviada`; reenviar no cambia el estado; destinatarios
  inválidos → error en la bolsa `envio`; `marcar-enviada` responde el JSON y cambia el estado.
- **`ProveedorTest`** (se actualiza): con una orden en `borrador`, `enviada` o `pagada` no se
  elimina y muestra el mensaje; con todas `recibida`, o sin órdenes, se elimina (soft delete). Se
  quitan las pruebas que usaban la columna.
- **`ArticuloTest`** (se agrega): `sugerencias` con `proveedor_id` y `precio=costo`; sin parámetros
  no cambia.
- **`EnvioCotizacionTest`**, **`CotizacionPagosTest`** y las de Tesorería: sin cambios, deben seguir
  pasando.
- **Node**: si el cambio de `documento-lineas.js` extrae alguna función pura (p. ej. qué líneas
  quitar al cambiar de proveedor), se prueba en `tests/js/`. El fixture de totales no cambia.
- `EstiloUniformeTest` sigue pasando con las vistas nuevas.

## Fuera de alcance

- Inventario y existencias: recibir no suma stock.
- Recepción parcial, faltantes, mermas, devoluciones, y revertir una recepción.
- Pago en parcialidades, anticipos, crédito, cuentas por pagar o saldo a favor.
- Cancelar una orden ("ya no la quiero"): en `borrador` se elimina.
- Timbrado, CFDI, y captura del CFDI del proveedor.
- Convertir la orden en otro documento.
- Actualizar el catálogo desde la orden (`precio_proveedor`, precios de venta).
- Envío de WhatsApp desde el servidor (Twilio u otro) y rutas públicas de PDF.
- Aprobación por monto o rol, comparativo entre proveedores, recordatorios por fecha de entrega.
- Fletes o gastos como concepto propio: se capturan como línea libre.
- Múltiples divisas: todo es MXN.
- Roles/permisos diferenciados y multiempresa.

## Estado de implementación

Implementada el 2026-10-01.

- **Archivos nuevos**:
  - migración `2026_10_02_100000_create_ordenes_compra_tables` (revisada con `migrate --pretend`
    contra MySQL y **aplicada en la base local**),
  - enum `EstadoOrdenCompra`; modelos `OrdenCompra`, `OrdenCompraLinea`; `OrdenCompraFactory`,
  - `OrdenCompraController`, `EnvioOrdenCompraController`, `OrdenCompraPagoController`,
  - `OrdenCompraRequest` (hereda de `CotizacionRequest`, como `FacturaRequest`),
    `EnviarOrdenCompraRequest` (hereda de `EnviarCotizacionRequest`), `OrdenCompraPagoRequest`,
    `ListadoOrdenesCompraRequest`,
  - `OrdenCompraPolicy`, `GeneradorPdfOrdenCompra`, `OrdenCompraMail`, trait `Mail\Concerns\AdjuntaPdf`,
  - vistas `ordenes-compra/*` y `emails/orden-compra`,
  - pruebas `OrdenesCompraTest`, `OrdenCompraPagoTest`, `EnvioOrdenCompraTest`.
- **Archivos modificados**: `User` y `Proveedor` (`ordenesCompra`, `tieneOrdenesActivas()`),
  `ProveedorController`, `ProveedorFactory` (sin `conOrdenesActivas()`), `Movimiento`
  (`documentoOrigen()` con `muestra_utilidad`), `MovimientoController` (`morphWith`),
  `AppServiceProvider` (alias `orden_compra`), `ArticuloController::sugerencias`, `CotizacionRequest`
  (tipo de `documento()`), `CotizacionMail` (`AdjuntaPdf`), `documentos/_linea` (etiqueta del precio
  opcional), `components/campo` (tipo `textarea`), `tesoreria/movimientos/index`, `layouts/app`,
  `app.css` (`etiqueta-recibida`), `routes/web.php`, `public/js/documento-lineas.js` y
  `ProveedorTest`.
- **Decisiones al implementar**:
  - `x-campo` ganó el tipo `textarea` para las observaciones: la revisión de estilo prohíbe
    `<textarea>` escrito a mano en las vistas.
  - El detalle se dibuja con el parcial `ordenes-compra/_hoja` (mismas clases `.hoja` que la
    cotización), no con un componente nuevo.
  - `data-telefono` lleva el número completo sin `+` (`524491234567`), igual que la cotización:
    `compartir-pdf.js` lo usa tal cual en `wa.me`.
  - El cambio de `documento-lineas.js` no extrajo ninguna función pura, así que no hay prueba de
    Node nueva; el fixture de totales no cambió.
- **Verificación**: las 46 pruebas nuevas y la suite completa pasan (678 de 679; la única falla es
  anterior a esta historia: `EstiloUniformeTest` por el `<button>` de `dashboard.blade.php`). Pint
  no reporta cambios y las 36 pruebas de Node pasan.

- **Bandeja (013/014)**, agregada el mismo día a pedido del usuario: el listado en tabla se reemplazó
  por la bandeja. Archivos nuevos: `components/ordenes-compra/fila` y `hoja` (la hoja era el parcial
  `_hoja`), `ordenes-compra/_carpetas`, `_vista-previa` y `_dialogos`. Se eliminó `_atajos`.
  `bandeja-cotizaciones.js` pasó a `bandeja-documentos.js` (genérico) y `RegresaABandeja` ganó
  `destinoOrdenCompra()`. Las pruebas del listado se reemplazaron por el bloque `bandeja` de
  `OrdenesCompraTest` (28 pruebas en el archivo); la suite pasa 794 de 795 (la misma falla anterior).

  **No se revisó la UI en un navegador real.** Falta probar la bandeja (clic en filas, filtrar con
  una orden abierta, acciones desde el visor, celular), el formulario (buscador filtrado por
  proveedor, confirmación al cambiar de proveedor, totales en vivo), los diálogos de envío y pago,
  cancelar el pago, recibir, compartir por WhatsApp en un teléfono y el PDF impreso, y confirmar que
  los formularios de cotización y factura siguen igual.

## Criterios de aceptación

1. Un usuario crea una orden de compra eligiendo un proveedor y una o varias líneas, ve los totales
   estimados en vivo, y la orden queda en `borrador` con folio `OC-<número>` propio.
2. El buscador de artículos solo ofrece artículos de catálogos del proveedor elegido; una línea con
   un artículo de otro proveedor se rechaza con un error de validación.
3. El precio de cada línea se precarga con el costo del artículo (precio de lista menos el descuento
   del catálogo), no con su precio de venta.
4. Ese precio es editable mientras la orden no esté pagada, y editarlo no cambia el artículo ni
   ningún precio del catálogo.
5. Se pueden agregar líneas libres (fletes u otros conceptos).
6. Cambiar de proveedor con líneas de artículo capturadas pide confirmación y las quita.
7. Enviar por correo adjunta el PDF y deja la orden `enviada`; compartir por WhatsApp entrega el PDF
   al menú del aparato (o lo descarga y abre WhatsApp en escritorio) y también la deja `enviada`.
8. El PDF muestra "Orden de compra", los datos del proveedor, y la fecha de entrega esperada y las
   observaciones cuando existen.
9. Registrar el pago de una orden `enviada` pide una cuenta activa y una fecha no futura; el monto es
   siempre el total y cualquier monto enviado se ignora.
10. El pago crea un egreso automático en la cuenta elegida, con la fecha del pago y el concepto
    "Pago de Orden de compra OC-<folio>", y deja la orden `pagada`.
11. Si el pago dejaría la cuenta en negativo, se rechaza con el mensaje dentro del diálogo, no se crea
    movimiento y la orden sigue `enviada`.
12. Cancelar el pago de una orden `pagada` elimina su egreso, devuelve el saldo y la regresa a
    `enviada`, editable. Una orden `recibida` no admite cancelar el pago.
13. Una orden `pagada` se marca como `recibida` manualmente; desde otro estado no se puede.
14. Solo se edita en `borrador`/`enviada` (editar una `enviada` la regresa a `borrador`) y solo se
    elimina en `borrador`.
15. Los totales siempre los calcula el servidor; un total manipulado en la petición no se guarda.
16. `/ordenes-compra` es una bandeja de tres columnas: carpetas Hoy, Esta semana, Este mes (activa al
    entrar) y Todas con contador; etiquetas por estado que se combinan con la carpeta; un buscador por
    folio, proveedor o RFC; y la orden elegida a la derecha en HTML, sin recargar, con sus acciones.
    Las acciones hechas desde la bandeja regresan a ella con la orden abierta.
17. Duplicar crea una copia en `borrador` con folio propio, mismo proveedor, líneas, descuento global
    y observaciones, sin pago ni fecha esperada.
18. Un proveedor con al menos una orden en estado distinto de `recibida` (incluido `borrador`) no se
    elimina y se muestra "No se puede eliminar: tiene órdenes de compra activas"; con todas recibidas
    o sin órdenes, se elimina como antes.
19. El egreso de una orden aparece en Contabilidad como automático, enlazado a la orden, sin
    utilidad, y no se edita ni se elimina desde ahí.
20. Los pagos de cotización, el envío de cotizaciones y los formularios de cotización y factura
    funcionan exactamente igual que antes.
21. El menú muestra "Órdenes de compra" después de "Proveedores".
22. Nada de esto funciona con órdenes, proveedores, artículos o cuentas ajenos (404 o error de
    validación).
23. Pint no reporta cambios y las suites Pest y Node pasan completas.

## Supuestos asumidos (registro completo)

**Asunciones de negocio (de la remota, vigentes):**

1. La orden de compra es del usuario dueño (mono-usuario, sin multiempresa).
2. Pertenece a un proveedor obligatorio, como la cotización a un cliente.
3. Folio propio por usuario, independiente de cotizaciones y facturas.
4. Líneas con la misma estructura que la cotización, más descuento global.
5. Los totales se calculan en el servidor con el algoritmo de 011.
6. Fecha de entrega esperada y observaciones opcionales, impresas en el PDF, sin efecto en el flujo.
7. El precio se precarga con `costo_con_descuento`, no con el precio de venta.
8. Toda la orden es editable mientras no esté `pagada`.
9. El buscador ofrece solo artículos del proveedor; cambiar de proveedor obliga a confirmar el
   vaciado.
10. Comprar no modifica el catálogo.
11. Todo en MXN.
12. Ciclo `borrador` → `enviada` → `pagada` → `recibida`, con dos retrocesos: editar una `enviada`
    la regresa a `borrador`; cancelar el pago regresa una `pagada` a `enviada`.
13. `recibida` es una marca manual solo desde `pagada`, sin efecto sobre inventario.
14. Solo se elimina (físicamente) en `borrador`.
15. No hay cancelación de una orden.
16. El correo adjunta el PDF generado al vuelo, con el correo del proveedor prellenado y editable.
17. El pago es siempre de contado, único y por el total.
18. El pago exige una cuenta activa y genera un egreso automático sujeto a saldo no negativo.
19. "Cancelar pago" sustituye a la eliminación LIFO; no se permite en `recibida`.
20. Un proveedor con una orden no `recibida` —incluido `borrador`— no se elimina.
21. Duplicar crea una copia en `borrador`, con folio nuevo y sin pago.
22. Sin recepción parcial, CFDI, aprobación, fletes como concepto propio ni comparativos.

**Asunciones de la auditoría (aprobadas):**

1. Un solo botón "Compartir por WhatsApp" (PDF + texto, teléfono del proveedor) que deja la orden
   `enviada`, como en cotizaciones; no hay un "Compartir PDF" aparte.
2. Folio de 4 dígitos (`OC-0015`), contador `users.ultimo_folio_orden_compra` con bloqueo, nunca se
   reutiliza.
3. La orden acepta líneas libres (resuelve la contradicción de la remota con los fletes).
4. `fecha_pago` no puede ser futura, en la zona del negocio.
5. ~~Listado clásico (tabla, filtros por columna, atajos y rango), no bandeja.~~ **Redefinida
   después de implementar**: el listado es una bandeja como la de cotizaciones (013/014), sin rango
   de fechas personalizado.
6. Duplicar guarda `duplicada_de_id`.
7. Un artículo eliminado o que ya no es del proveedor no se agrega; las líneas guardadas conservan
   su copia y no se revalidan al enviar o pagar.
8. "Órdenes de compra" en el menú junto a Proveedores.

**Adiciones técnicas aprobadas:**

1. Enum `EstadoOrdenCompra` y métodos de regla en el modelo.
2. `OrdenCompraPolicy` con 404 a lo ajeno y motivos de rechazo.
3. Bloqueo orden → cuenta al pagar y al cancelar el pago.
4. Excepciones de Tesorería convertidas en errores de la bolsa `pago`.
5. `Proveedor::tieneOrdenesActivas()` derivado por consulta, migración que elimina la columna y
   ajuste del factory.
6. `OrdenCompra::proveedor()->withTrashed()`.
7. Migración con índices y llaves (`restrictOnDelete` hacia `cuentas`, cascada de líneas).
8. Pruebas Pest del módulo y de las integraciones.
9. Trait `AdjuntaPdf` para los correos de cotización y orden de compra.

*Precisiones al redactar:* parámetro de ruta `ordenCompra` en camelCase (la remota anticipaba
`orden_compra`; su implementación terminó usando camelCase); `documentoOrigen()` gana
`muestra_utilidad` para que un egreso no muestre "No disponible"; al cambiar de proveedor se quitan
solo las líneas de artículo y se conservan las libres, que no dependen del proveedor; las líneas de
la orden no guardan `costo_unitario` porque su precio ya es el costo.
