# Spec: Facturación (captura, timbrado CFDI vía facturapi.io, cancelación y complemento de pago)

**Referencia:** reescritura de [remotas/007-facturacion.md](remotas/007-facturacion.md), que se
diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan las reglas de negocio
(ciclo de estados, timbrado síncrono en un solo paso, folio interno separado del folio fiscal,
inmutabilidad del CFDI timbrado, cancelación con motivo y sustituta, complemento de pago 1:1, XML
en vivo y PDF al vuelo sin guardar archivos, compartir solo el PDF) y **todas las lecciones que dejó
la API real de facturapi.io** (nombres de `stamp{}`, `cancellation_status` con `none`,
`tax_included: false`, payload real del complemento, `complement_string`), que pasan de la bitácora
remota al cuerpo de esta spec como reglas.

Cambia todo lo que dependía de la SPA: no hay API REST ni Sanctum ni API Resources; no hay
`FacturaTotalesCalculator` propio ni copia en TypeScript (se reutiliza la calculadora de
documentos de [011](011-cotizaciones.md)); no se compara el total del navegador contra el del
servidor; los catálogos SAT chicos son enums; la decisión de qué se puede corregir tras un error
de timbrado vive en el servidor.

Se retiró lo heredado de historias que aquí no existen: el ajuste al peso cerrado
([remotas/030](remotas/030-total-al-peso-cerrado.md)), el precio distribuidor
([remotas/033](remotas/033-precio-distribuidor.md)), el `lib/compartir.ts` del mostrador
([remotas/029](remotas/029-pwa-mostrador.md), [remotas/031](remotas/031-mostrador-consulta.md)) y
la conversión cotización → factura ([remotas/043](remotas/043-facturas-parciales-cotizacion.md)).
Cada una, cuando llegue, extenderá esta spec.

## Historia de usuario

Como usuario registrado, quiero generar una factura seleccionando un cliente y uno o varios
artículos, ver las sumas desglosadas al final, y timbrarla con los sellos fiscales del SAT por
medio del PAC facturapi.io, para poder emitir CFDI válidos a mis clientes y quedarme con los sellos
guardados en la base de datos.

## Objetivo / Alcance

Implementar el módulo de facturación sobre la arquitectura monolítica Laravel + Blade + JavaScript
nativo de [001](001-inicio-proyecto.md), con la sesión web de [002](002-login.md), los componentes
de [003](003-estilo-uniforme.md) y los catálogos de [Clientes](005-gestion-clientes.md) y
[Artículos](007-gestion-articulos.md).

- Laravel resuelve el módulo completo: rutas web, controladores, Form Requests, Policy, cálculo de
  totales, comunicación con facturapi.io, PDF y correo. El navegador nunca habla con facturapi.io.
- **Se reutiliza sin cambios** lo que 011 dejó genérico: `CalculadoraTotalesDocumento`,
  `public/js/totales-documento.js`, su fixture, `public/js/documento-lineas.js` (con una opción
  nueva para desactivar líneas libres), el parcial de línea, `<x-celda>` y el manejador de
  `<dialog>` de `app.js`.
- **Nace la integración con facturapi.io** con el cliente HTTP de Laravel (sin SDK).
- **Nace `public/js/compartir-pdf.js`**, generalización de `compartir-cotizacion.js`, que sirve a
  cotizaciones y a facturas.
- JavaScript solo donde hay interacción real: la tabla de líneas, el compartir PDF y la búsqueda
  dinámica del listado. Todos los modales son `<dialog>` con formularios normales.

Incluye: captura (cliente + líneas de artículo con precio/descuento/IVA editables), desglose de
totales, timbrado síncrono, reintento, cancelación de CFDI, descarga de XML (en vivo) y PDF (al
vuelo, plantilla propia con QR del SAT), envío por correo, compartir el PDF por el menú del sistema
operativo y un complemento de pago básico para método PPD.

**No** incluye: notas de crédito/egreso, parcialidades múltiples de pago, multiempresa, ni la
conversión desde una cotización.

## Requisitos del entorno

- **Sin dependencias nuevas.** La integración usa `Illuminate\Support\Facades\Http` (timeout
  explícito, `Http::fake()` en pruebas). Dompdf y `chillerlan/php-qrcode` ya están instalados.
- **Configuración** en `config/services.php › facturapi`, leída siempre con `config()`:
  - `ambiente` ← `FACTURAPI_ENV` (`test`|`live`, por defecto `test`).
  - `llave` ← `FACTURAPI_TEST_KEY` o `FACTURAPI_LIVE_KEY` según el ambiente.
  - `url` = `https://www.facturapi.io/v2` (el mismo para ambos ambientes; solo cambia la llave).
  - `timeout` ← `FACTURAPI_TIMEOUT` (segundos, por defecto 30).
  - `emisor` ← `FACTURAPI_EMISOR_RFC`, `FACTURAPI_EMISOR_RAZON_SOCIAL`,
    `FACTURAPI_EMISOR_REGIMEN`, `FACTURAPI_EMISOR_CP` (datos del emisor para el PDF; ver
    "Datos del emisor").
  - Las variables se agregan vacías a `.env.example`; las llaves reales solo viven en `.env` y
    nunca se commitean.
- **Candados**: `CACHE_STORE=database` ya soporta `Cache::lock` (tabla `cache_locks` de la
  migración base).
- **Correo**: SMTP de `.env` (Mailpit en desarrollo), síncrono, igual que 011.
- **Límite de ejecución**: una petición web puede esperar hasta `timeout` segundos a facturapi.io;
  `max_execution_time` de PHP debe ser mayor (Laragon trae 120 s).

## Backend (Laravel)

### Enums (`app/Enums`)

- **Reutilizados**: `TasaIva`, `TipoDescuento`, `FormaPago`, `RegimenFiscal`, `ObjetoImpuesto`.
- `EstadoFactura`: `borrador`, `pendiente`, `timbrada`, `cancelada`, con `etiqueta()`.
- `MetodoPago`: `PUE` (Pago en una sola exhibición), `PPD` (Pago en parcialidades o diferido), con
  `etiqueta()` y `opciones()`.
- `UsoCfdi`: catálogo SAT `c_UsoCFDI` 4.0 completo (24 claves: `G01`–`G03`, `I01`–`I08`,
  `D01`–`D10`, `S01`, `CP01`, `CN01`) con `descripcion()`. `opcionesFactura()` excluye `CP01`
  (Pagos) y `CN01` (Nómina), que no aplican a un comprobante de Ingreso.
- `MotivoCancelacion`: `c_MotivoCancelacion` (`01` Comprobante emitido con errores con relación,
  `02` … sin relación, `03` No se llevó a cabo la operación, `04` Operación nominativa relacionada
  en una factura global), con `descripcion()` y `requiereSustituta()` (solo `01`).
- `EstadoCancelacion`: `none`, `pending`, `verifying`, `accepted`, `rejected` — el
  `cancellation_status` de facturapi.io. `rejected` (el receptor rechazó la cancelación) no se ha
  visto en la API real; se contempla para no romper y se confirma en la verificación contra el
  sandbox.
- `TipoErrorTimbrado`: `datos` (facturapi.io respondió 4xx: datos rechazados), `pac` (5xx,
  timeout o error de conexión).
- `EstadoComplementoPago`: `pendiente`, `timbrado`, `error`.

Todos son catálogos cortos y estables: viven en enums, como `RegimenFiscal` y `FormaPago`, no en
tablas ni en `catalogos-sat:actualizar`.

### Modelo `Factura` (tabla `facturas`)

- Pertenece a un `User` (`user_id`, no asignable; alta con `$request->user()->facturas()->create()`)
  y a un `Cliente` (`cliente_id`, obligatorio, del mismo usuario). `cliente()` incluye eliminados
  (`withTrashed()`).
- **Sin soft delete**: borrado físico solo en `borrador`/`pendiente` (se lleva sus líneas en
  cascada). `timbrada`/`cancelada` nunca se eliminan.
- **Campos**:
  - `folio`: entero, único por usuario. Ver "Folio".
  - `estado`: `EstadoFactura`, por defecto `borrador`.
  - Cabecera fiscal: `uso_cfdi` (`UsoCfdi`), `forma_pago` (`FormaPago`), `metodo_pago`
    (`MetodoPago`). `moneda` (`MXN`) y `tipo_comprobante` (`I`) son constantes del modelo, no
    columnas: no hay otro valor posible en esta historia.
  - `descuento_global_tipo` (nullable, `TipoDescuento`) y `descuento_global_valor`
    (decimal(10,2), nullable).
  - Totales, decimal(14,2), **siempre calculados en el servidor**: los mismos de la cotización
    (`subtotal`, `total_descuento`, `base_iva_16`, `total_iva_16`, `base_iva_0`, `base_exento`,
    `total`), con la constante `TOTALES`.
  - **Timbrado** (nullables hasta timbrar; nombres verificados contra la respuesta real de
    facturapi.io):

    | Columna | Campo de la respuesta |
    |---|---|
    | `facturapi_invoice_id` | `id` (identificador de facturapi.io, para cancelar, bajar XML y consultar) |
    | `uuid_fiscal` | `uuid` |
    | `facturapi_serie` | `series` |
    | `facturapi_folio` | `folio_number` |
    | `sello_cfdi` (text) | `stamp.signature` |
    | `sello_sat` (text) | `stamp.sat_signature` |
    | `no_certificado_sat` | `stamp.sat_cert_number` |
    | `fecha_timbrado` (datetime, UTC) | `stamp.date` |
    | `cadena_original_sat` (text) | `stamp.complement_string` |
    | `version_comprobante` | `cfdi_version` (en la raíz, **no** dentro de `stamp`) |
    | `url_verificacion_sat` (text) | `verification_url` (ver "PDF": si no llega, se arma) |

  - **Copia fiscal del receptor** (nullables hasta timbrar): `receptor_rfc`,
    `receptor_razon_social`, `receptor_regimen_fiscal`, `receptor_codigo_postal`,
    `receptor_correo`. Se escriben **al timbrar con éxito**, con exactamente los datos del cliente
    que se enviaron en el payload. Ver "Copias fiscales".
  - **Copia del emisor** (nullables hasta timbrar): `emisor_rfc`, `emisor_razon_social`,
    `emisor_regimen_fiscal`, `lugar_expedicion`. Ver "Datos del emisor".
  - **Error**: `error_timbrado` (text, nullable) y `tipo_error_timbrado` (`TipoErrorTimbrado`,
    nullable). Se limpian al timbrar con éxito.
  - **Cancelación**: `motivo_cancelacion` (`MotivoCancelacion`, nullable), `factura_sustituta_id`
    (FK nullable a `facturas`, `restrictOnDelete`), `estado_cancelacion` (`EstadoCancelacion`,
    nullable; solo se escribe a partir de `cancelar`), `fecha_cancelacion` (datetime, nullable,
    cuando llega `accepted`).
  - `timestamps`.
- Relaciones: `User::facturas()`, `Cliente::facturas()`; `lineas()` (ordenadas por `orden`);
  `complementoPago()` (`hasOne`); `sustituta()` (`belongsTo` a `Factura`).
- **Métodos de regla** (los usan controladores, Policy y Blade; nadie reimplementa la condición):
  - `esEditable()` → `borrador`, o `pendiente` con `tipo_error_timbrado = datos`.
  - `puedeEliminarse()` → `borrador` o `pendiente`.
  - `puedeReintentarse()` → `pendiente`.
  - `tieneDocumentoFiscal()` → `timbrada` o `cancelada` (hay XML, PDF y se puede compartir).
  - `puedeEnviarse()` → `timbrada`.
  - `cancelacionEnCurso()` → `estado_cancelacion` en `pending`/`verifying`.
  - `puedeCancelarse()` → `timbrada` y `estado_cancelacion` nulo, `none` o `rejected`.
  - `puedeRegistrarComplemento()` → `timbrada`, `metodo_pago = PPD` y sin complemento `timbrado`.
  - `receptor(): array` → la copia fiscal si existe; si no (antes de timbrar), los datos vivos del
    cliente. El PDF, el correo y el detalle leen siempre de aquí.
  - `folioFiscal(): ?string` → `"{serie}{folio}"` de facturapi.io, o `null` antes de timbrar.
  - `aplicarRespuestaTimbrado(array $respuesta, array $receptor, array $emisor)` y
    `registrarErrorTimbrado(string $mensaje, TipoErrorTimbrado $tipo)`.
- Scope `filtrar(array $filtros)` para el listado.
- Índices: `(user_id, folio)` único, `(user_id, estado)`, `(user_id, created_at)`, `uuid_fiscal`.

### Folio

Mismo mecanismo que la cotización (011): contador `users.ultimo_folio_factura` (entero, por
defecto 0), asignado dentro de una transacción con `User::lockForUpdate()`, nuevo folio =
`max(contador, folio máximo existente del usuario) + 1`, índice único como última red. Nunca se
reutiliza. Se muestra como `FAC-0012` (accessor `folio_formateado`).

Es un identificador **interno**, existe desde que se crea la factura. El folio fiscal real es
`facturapi_serie` + `facturapi_folio`, lo asigna facturapi.io al timbrar y es el que muestran el
detalle y el PDF de una factura timbrada.

### Modelo `FacturaLinea` (tabla `factura_lineas`)

Igual que `CotizacionLinea` (011), con estas diferencias:

- `articulo_id`: **obligatorio** (FK sin cascada; los artículos usan soft delete). **No hay líneas
  libres**: el CFDI exige clave de producto y de unidad, y solo el artículo las tiene.
- `descripcion`, `modelo` (ambos obligatorios), `precio_unitario`, `descuento_tipo`,
  `descuento_valor`, `tasa_iva`, `importe`, `iva_importe`, `orden`: mismas reglas y tipos que la
  cotización. `precio_unitario` se precarga con `precio_unitario_sin_iva` y es editable.
- **Copias fiscales del artículo**: `clave_prod_serv` (8), `clave_unidad` (3), `objeto_imp` (2).
  Nunca se aceptan del formulario: el servidor las toma del artículo (`withTrashed`) con una sola
  consulta al guardar las líneas, igual que `costo_unitario` en la cotización. En cada edición se
  recrean con los valores vigentes. No hay `costo_unitario` (la utilidad se calcula desde la venta,
  no desde la factura).

### Copias fiscales

Un PDF generado al vuelo solo es fiel al XML si lee los mismos datos que se timbraron. Por eso:

- Antes de timbrar, el detalle muestra los datos vivos del cliente y del artículo (todavía no hay
  comprobante).
- Al timbrar con éxito, la factura guarda la copia del receptor y la del emisor; las líneas ya
  guardaban sus claves SAT. Si después alguien corrige el RFC del cliente o la clave SAT del
  artículo, **la factura timbrada no cambia**.

### Datos del emisor

El emisor es la organización configurada en facturapi.io; el sistema no la administra. Para el
PDF:

- Si la respuesta del timbrado trae los datos del emisor (se revisa en la verificación contra el
  sandbox: vienen en `issuer_info`), se toman de ahí.
- Si no, se toman de `config('services.facturapi.emisor')`.
- En ambos casos se copian a la factura al timbrar, por la misma razón que el receptor.

### Modelo `ComplementoPago` (tabla `complementos_pago`)

- `factura_id` (FK, **única**: 1:1, `restrictOnDelete`), `fecha_pago` (date), `monto`
  (decimal(14,2)), `forma_pago` (`FormaPago`), `estado` (`EstadoComplementoPago`),
  `facturapi_invoice_id`, `uuid_fiscal`, `sello_cfdi`, `sello_sat`, `cadena_original_sat`,
  `fecha_timbrado` (mismo mapeo que la factura), `error_timbrado` (text, nullable), `timestamps`.
- `protected $table = 'complementos_pago'`.
- No guarda XML ni PDF, y esta historia no expone descarga para él.
- Sin relación con `CotizacionPago` (011): aquel es un registro interno sin CFDI.

### Calculadora de totales

Se reutiliza **tal cual** `App\Services\Documentos\CalculadoraTotalesDocumento` (011): centavos
enteros, dos pasadas, prorrateo del descuento global proporcional al neto de cada línea con el
centavo residual en la línea de mayor neto, IVA por línea sobre el importe ya neto. No hay
calculadora propia de facturas ni cambio al algoritmo.

Lo único que agrega facturación es cómo se traduce al CFDI, que no tiene descuento a nivel
documento:

- El `discount` de cada ítem enviado a facturapi.io es `bruto_linea − importe`, es decir, el
  descuento de la línea **más** su parte prorrateada del descuento global. Así facturapi.io calcula
  el IVA sobre la misma base que ya calculó el servidor, y el total timbrado coincide con el
  mostrado (bug de la remota del 2026-07-31: el descuento global solo restaba en pantalla).
- Cualquier cambio futuro al algoritmo sigue el mecanismo de "Fuente de verdad única" de 011:
  fixture compartido entre Pest y Node.

### Integración con facturapi.io

**`App\Services\Facturacion\FacturapiCliente`** — único punto que habla con facturapi.io:

- `Http::baseUrl(config url)->withToken(llave)->acceptJson()->timeout(config timeout)`.
- Métodos: `crearFactura(array $payload): array` (`POST /invoices`), `consultarFactura(string $id)`
  (`GET /invoices/{id}`), `cancelarFactura(string $id, MotivoCancelacion $motivo, ?string $sustitucion)`
  (`DELETE /invoices/{id}?motive=XX[&substitution=…]`), `descargarXml(string $id): string`
  (`GET /invoices/{id}/xml`), `crearComplementoPago(array $payload): array` (`POST /invoices`).
- Cualquier falla lanza `FacturapiException` con `mensaje` (el `message` de facturapi.io, o un
  texto propio para timeout/conexión: "facturapi.io no respondió en 30 segundos") y `tipo`
  (`TipoErrorTimbrado`: 4xx → `datos`; 5xx, `ConnectionException` y timeout → `pac`).
- Cada falla se registra con `Log::warning('facturapi', [operacion, factura_id, status, mensaje])`,
  **nunca** con la llave ni el payload completo.

**`App\Services\Facturacion\ConstructorPayloadFacturapi`** — arma los arreglos, sin HTTP (probable
con pruebas unitarias):

- `factura(Factura $factura): array`:
  - `customer`: `legal_name`, `tax_id`, `tax_system`, `email` (si hay), `address.zip` — del
    cliente, **inline** (sin pre-registrar clientes en facturapi.io).
  - `items[]`, uno por línea: `quantity`, `discount` (ver "Calculadora"), `product`:
    `description` (descripción + modelo), `product_key` (`clave_prod_serv`), `unit_key`
    (`clave_unidad`), `price` (`precio_unitario`), **`tax_included: false` siempre** (sin él
    facturapi.io extrae el IVA de dentro del precio en vez de sumarlo: `price=700, discount=350`
    timbró $350.01 en lugar de $406.00), `taxability` (`objeto_imp`) y `taxes`:
    - `objeto_imp = 02`: `16` → `[{ type: 'IVA', rate: 0.16 }]`; `0` → `[{ type: 'IVA', rate: 0 }]`;
      `exento` → `[{ type: 'IVA', rate: 0, factor: 'Exento' }]` (facturapi.io exige `rate`).
    - Otro `objeto_imp`: `taxes: []` (la validación garantiza que la línea sea `exento`).
  - `use`, `payment_form`, `payment_method`. Sin `status: draft`: timbrado inmediato.
- `complementoPago(ComplementoPago $complemento): array` — payload real verificado en la remota
  (distinto al de la documentación pública):
  `{ type: 'P', customer: {…igual que la factura…}, complements: [{ type: 'pago', data: { date,
  payment_form, related_documents: [{ uuid, installment: 1, last_balance, amount, taxes: [...] }] } }] }`.
  - `last_balance` = total de la factura; `amount` = monto del complemento.
  - `taxes` (obligatorio): un elemento por tasa presente en la factura, con `base` = base de esa
    tasa (`base_iva_16`, `base_iva_0`) × `monto / total`, redondeada a 2 decimales; `16` →
    `{ type: 'IVA', rate: 0.16, base }`, `0` → `{ type: 'IVA', rate: 0, base }`, exento →
    `{ type: 'IVA', rate: 0, factor: 'Exento', base }`. La remota usaba el `subtotal` de toda la factura,
    que aquí es antes de descuentos y daría una base inflada.

**`App\Services\Facturacion\TimbradorFacturas`** — orquesta el timbrado (lo usan `store`, `update`
y `timbrar`):

1. Toma `Cache::lock("timbrar-factura-{id}", timeout + 10)`. Si ya está tomado, no llama a
   facturapi.io y responde "Esta factura ya se está timbrando" (un doble clic no genera dos CFDI).
2. Recarga la factura; si ya no está en `borrador`/`pendiente`, sale sin hacer nada.
3. Llama `crearFactura` **fuera de cualquier transacción de base de datos** (no se retiene una
   conexión 30 s).
4. Éxito → `aplicarRespuestaTimbrado` (sellos, copias, `estado = timbrada`, limpia el error).
   Falla → `registrarErrorTimbrado` (`estado = pendiente`). Timeout = error de tipo `pac`.
5. Libera el candado siempre (`finally`).

**Reintento tras timeout** (verificado contra el sandbox): un timeout puede ocurrir **después** de
que facturapi.io timbró. Cada factura y cada complemento envían `idempotency_key` y `external_id`
con una referencia fija (`factura-{id}-{timestamp de creación}`; la fecha evita choques si la base
se reinicia y los ids se repiten).
- facturapi.io responde **409** ("La clave de idempotencia ya está siendo usada") si esa llave ya
  timbró un CFDI, con los mismos datos o con otros.
- Un intento **rechazado** (4xx) no gasta la llave: corregir y reintentar funciona.
- Ante un 409, el timbrador busca el CFDI con `GET /invoices?external_id=…` y **lo adopta**, en vez
  de timbrar otro. Si todavía no aparece, la factura queda `pendiente` (error `pac`) con "Reintenta
  en unos minutos".

### XML y PDF

- **Nunca se guarda** un XML ni un PDF en el sistema (ni de la factura ni del complemento).
- **XML**: cada descarga llama en vivo a `descargarXml` y devuelve el contenido
  (`application/xml`, `attachment`). Si falla: redirect al detalle con "No se pudo obtener el XML
  de facturapi.io. Intenta de nuevo." Sin reintento automático ni caché.
- **PDF** (`App\Services\Facturacion\GeneradorPdfFactura`, mismo patrón que
  `GeneradorPdfCotizacion`): vista `facturas/pdf.blade.php`, Dompdf, carta, DejaVu Sans con
  subconjunto de fuentes. Se genera solo con datos guardados (nunca llama a facturapi.io).
  Contenido:
  - Emisor (copia), receptor (`receptor()`), serie/folio fiscal, UUID, fecha y hora de timbrado,
    lugar de expedición, uso de CFDI, forma y método de pago, moneda, tipo de comprobante y versión.
  - Líneas: cantidad, clave de unidad, clave de producto, descripción, modelo, precio unitario,
    descuento, importe.
  - Totales: subtotal, descuento, IVA 16%, total, y el total en letra.
  - Sello CFDI, sello SAT, número de certificado SAT, cadena original del complemento de
    certificación.
  - **QR del SAT** (`chillerlan/php-qrcode`, incrustado como imagen): `url_verificacion_sat`; si no
    llegó, se arma
    `https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?id={uuid}&re={rfc emisor}&rr={rfc receptor}&tt={total}&fe={últimos 8 del sello CFDI}`.
  - Leyenda "Este documento es una representación impresa de un CFDI". En una `cancelada`, marca
    de agua "CANCELADA".
  - Nombre del archivo: `factura-{serie}{folio fiscal}.pdf` (el mismo en descarga, correo y
    compartir).

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]`, antes del
`Route::resource`:

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/facturas` | listado, filtros y página en la URL | `facturas.index` |
| GET | `/facturas/buscar` | fragmento HTML para la búsqueda dinámica | `facturas.buscar` |
| GET | `/facturas/crear` | formulario de alta | `facturas.create` |
| POST | `/facturas` | crea y timbra | `facturas.store` |
| GET | `/facturas/{factura}` | detalle (refresca una cancelación en curso) | `facturas.show` |
| GET | `/facturas/{factura}/editar` | formulario de corrección | `facturas.edit` |
| PUT | `/facturas/{factura}` | guarda y vuelve a timbrar | `facturas.update` |
| DELETE | `/facturas/{factura}` | borrado físico | `facturas.destroy` |
| POST | `/facturas/{factura}/timbrar` | reintento con los mismos datos | `facturas.timbrar` |
| POST | `/facturas/{factura}/cancelar` | cancela ante facturapi.io | `facturas.cancelar` |
| GET | `/facturas/{factura}/xml` | XML en vivo | `facturas.xml` |
| GET | `/facturas/{factura}/pdf` | PDF al vuelo (`?descargar=1` fuerza descarga) | `facturas.pdf` |
| POST | `/facturas/{factura}/enviar` | correo con XML y PDF | `facturas.enviar` |
| POST | `/facturas/{factura}/complemento-pago` | crea y timbra el complemento | `facturas.complemento-pago` |

`Route::resource('facturas', FacturaController::class)->parameters(['facturas' => 'factura'])`
(`Str::singular('facturas')` sí da `factura`, pero se deja explícito como en 011).

### Controladores

- **`FacturaController`**:
  - `index` / `buscar`: facturas del usuario con `cliente`, filtradas con
    `ListadoFacturasRequest`, ordenadas por `created_at` desc e `id`, 25 por página con
    `withQueryString()`; `buscar` devuelve el parcial `_resultados` con
    `withPath(route('facturas.index'))`.
  - `create`: formulario vacío con los clientes activos del usuario.
  - `store`: en una transacción asigna folio, crea la factura en `borrador`, guarda líneas (con
    copias fiscales) y totales. **Después del commit** llama a `TimbradorFacturas`:
    - éxito → redirect a `facturas.show` con "Factura timbrada. UUID …";
    - error `datos` → redirect a `facturas.edit` con el mensaje de facturapi.io: el mismo
      formulario, con todo lo capturado, listo para corregir;
    - error `pac` → redirect a `facturas.show` con el mensaje y el botón "Reintentar timbrado".
  - `show`: si `cancelacionEnCurso()`, consulta `consultarFactura` y actualiza
    `estado_cancelacion` (y `estado`/`fecha_cancelacion` si llegó `accepted`). Si esa consulta
    falla, **la página no falla**: muestra lo guardado y un aviso "No se pudo consultar el estado
    de la cancelación; se mostrará el último conocido".
  - `edit` / `update`: solo si `esEditable()`. `update` recrea líneas y totales en una transacción
    y después timbra, con el mismo manejo de resultado que `store`.
  - `destroy`: solo si `puedeEliminarse()`; redirect al listado.
  - `timbrar`: solo si `puedeReintentarse()`; mismo manejo de resultado.
  - `cancelar`: valida con `CancelarFacturaRequest`; `sustitucion` es el `uuid_fiscal` de la
    sustituta (la remota lo documentó así; se confirma contra el sandbox con un motivo `01`, y si
    facturapi.io espera su propio `id`, se envía `facturapi_invoice_id`). Guarda motivo, sustituta
    y `cancellation_status`; con `accepted` pasa a `cancelada`. Flash según el resultado:
    "Factura cancelada" o "Cancelación en proceso: el receptor o el SAT deben confirmarla".
  - `xml`, `pdf`: solo si `tieneDocumentoFiscal()`.
- **`EnvioFacturaController::correo`**: `EnviarFacturaRequest`; solo si `puedeEnviarse()`. Pide el
  XML a facturapi.io **antes** de armar el correo; si falla, no envía nada y avisa. Envía
  `FacturaMail` (asunto "Factura {serie}{folio} — {negocio}", cuerpo breve con total y UUID, XML y
  PDF adjuntos). No cambia el estado ni registra el envío. Lleva copia oculta al buzón del negocio
  (`negocio.copia_correos`, de fábrica `ventas@sellopronto.com.mx`): ver
  [032](032-copia-correos-negocio.md).
- **`ComplementoPagoController::store`**: `ComplementoPagoRequest`; solo si
  `puedeRegistrarComplemento()`. Crea (o, si el existente quedó en `error`, actualiza) el
  complemento en `pendiente`, timbra con el mismo candado por factura y guarda sellos (`timbrado`)
  o el mensaje (`error`). Un complemento en `error` se puede volver a intentar desde el mismo
  botón; uno `timbrado` bloquea cualquier otro.

### Autorización (`FacturaPolicy`)

- `view`, `update`, `delete` y `operar` (timbrar, cancelar, XML, PDF, enviar, complemento): solo el
  dueño; a los demás `Response::denyAsNotFound()` (404), igual que 011.
- `update` exige `esEditable()` y `delete` exige `puedeEliminarse()`; si no, `Response::deny(motivo)`
  (403): "Una factura timbrada no se puede modificar", etc.
- La regla de estado de cada acción de `operar` la revisa su controlador con los métodos del modelo;
  Blade usa `@can` y esos métodos para pintar los botones.
- Los Form Requests usan `Gate::inspect(...)` en `authorize()`.

### Validaciones

- **`FacturaRequest`** (alta y corrección): las reglas de líneas y descuentos de
  `CotizacionRequest` (topes incluidos: ≤ 100 líneas, cantidad ≤ 9999, precio ≤ $999,999.99), con
  estas diferencias:
  - `lineas.*.articulo_id`: **requerido**, del usuario, sin eliminar, `distinct`. En corrección se
    acepta un artículo eliminado después de guardarse la línea.
  - `lineas.*.modelo`: requerido.
  - Si el artículo tiene `objeto_imp` distinto de `02`, `lineas.*.tasa_iva` debe ser `exento`
    ("El artículo no es objeto de impuesto: la tasa debe ser Exento").
  - `uso_cfdi`: requerido, en `UsoCfdi::opcionesFactura()`.
  - `metodo_pago`: requerido, `MetodoPago`.
  - `forma_pago`: requerido, `FormaPago`. Con `PPD` debe ser `99` (Por definir); con `PUE` no puede
    ser `99` (regla del SAT para CFDI 4.0). El formulario fija `99` al elegir PPD.
  - No hay campo de total ni comparación contra el total del navegador: el servidor calcula y
    guarda; cualquier total, folio, estado, sello o copia fiscal que llegue se ignora.
- **`CancelarFacturaRequest`**: `motivo_cancelacion` requerido (`MotivoCancelacion`);
  `factura_sustituta_id` requerido si el motivo es `01`: factura propia, `timbrada` y distinta de
  la que se cancela.
- **`ComplementoPagoRequest`**: `fecha_pago` requerida, fecha, no futura; `monto` requerido,
  numérico, `gt:0`, `decimal:0,2`, no mayor al total de la factura; `forma_pago` requerida,
  `FormaPago` distinta de `99`.
- **`EnviarFacturaRequest`**: igual que `EnviarCotizacionRequest` (1 a 5 destinatarios `email:rfc`,
  campo de texto separado por comas).
- **`ListadoFacturasRequest`**: sanea `cliente` (razón social o nombre comercial), `rfc`, `folio`
  (acepta `FAC-0012`, `12` o el folio fiscal con serie), `uuid` (parcial), `estado` (lista blanca)
  y `pagina`.
- Los errores de cada diálogo van a su propia bolsa (`envio`, `cancelacion`, `complemento`) para
  que el detalle reabra el diálogo que falló. `attributes()` en español.

## Vistas (Blade)

Extienden `layouts/app.blade.php` con los componentes de 003. Se agrega "Facturas" al menú, icono
`receipt`, después de "Cotizaciones".

### `facturas/index.blade.php` — listado

- Tabla: folio (`FAC-0012`, y debajo el folio fiscal si existe), cliente, estado (etiqueta por
  color; "Cancelación en proceso" si aplica), total, fecha (zona del negocio) y acciones.
- Fila de filtros bajo los títulos (cliente, RFC, folio, UUID, estado) dentro del formulario
  `data-busqueda-dinamica`, con los parciales `_resultados`, `_filas`, `_paginacion` como 011.
- Acciones por fila: ver; **compartir PDF** si `tieneDocumentoFiscal()` (ver JavaScript); eliminar
  (`data-confirmar`) si `puedeEliminarse()`.

### `facturas/crear.blade.php` y `editar.blade.php` — formulario (`_formulario.blade.php`)

- Cliente (`select`), Uso de CFDI (`select` con `opcionesFactura()`), Método de pago (`select`
  PUE/PPD) y Forma de pago (`select`). Con JavaScript, elegir PPD fija la forma de pago en `99`;
  sin él, la validación lo exige.
- La tabla de líneas de 011 con `data-sin-lineas-libres`: siete columnas visibles (cantidad,
  descripción, modelo, precio unitario, descuento, IVA, importe) más quitar; el importe es el neto
  sin IVA. Buscador de artículos con el aviso de duplicado; sin botón "Agregar línea libre".
- Descuento global y resumen de totales en vivo (`totales-documento.js`), marcado como "estimado".
- Botón "Generar y timbrar" (en edición: "Guardar y timbrar"), que se deshabilita al enviar.
- En edición, un `x-alerta` de error con el `error_timbrado` previo.
- Reconstrucción desde `old('lineas')` tras un error de validación, como 011.
- **Sin JavaScript** se pueden corregir las líneas existentes, pero no agregar artículos (el
  buscador lo requiere y no hay líneas libres). El formulario lo dice en un aviso dentro de
  `<noscript>`.

### `facturas/show.blade.php` — detalle

- `pendiente`: `x-alerta` con el `error_timbrado`; botones "Reintentar timbrado" (siempre),
  "Corregir datos" (si `esEditable()`) y "Eliminar". Con error `pac`, el aviso de "Riesgo de
  reintento" si aplica.
- `timbrada`/`cancelada`: representación de la factura igual que el PDF (folio fiscal, UUID,
  fechas, emisor, receptor, líneas, totales, sellos, cadena original). Estado de cancelación
  visible: "Cancelación en proceso" o "Cancelada el …".
- **Acciones** (cada una según su método de regla):
  - "Enviar por correo" → `<dialog>`, destinatarios prellenados con `receptor()['correo']`.
  - "Descargar XML", "Ver PDF" (pestaña nueva), "Descargar PDF".
  - "Compartir PDF" (ver JavaScript), con la leyenda en letra chica: "Por aquí va el PDF; el XML
    se manda por correo."
  - "Cancelar factura" → `<dialog>` con el motivo (`select` de 4) y, si el motivo es `01`, un
    `select` con las facturas propias `timbradas` (folio fiscal — cliente — total). Sin JavaScript
    el `select` de sustituta está siempre visible y la validación exige llenarlo con `01`.
  - "Registrar complemento de pago" → `<dialog>` con fecha (hoy), monto (precargado con el total,
    editable) y forma de pago. Si hay complemento: sus datos (fecha, monto, forma, UUID o el error).
- Enlaces `href="#dialogo"` con `data-abrir-dialogo` y respaldo `dialog:target`, como 011.

## JavaScript

### Tabla de líneas

`documento-lineas.js` sin cambios de comportamiento para cotizaciones. Opción nueva: si el
formulario tiene `data-sin-lineas-libres`, no dibuja las 3 filas vacías de respaldo ni el botón
"Agregar línea libre". El parcial `_linea` y la plantilla se comparten desde las vistas de
cotización (o se mueven a `resources/views/documentos/` si hace falta para no depender de otro
módulo).

### Compartir PDF (`public/js/compartir-pdf.js`)

Reemplaza a `compartir-cotizacion.js` (se borra). Atiende todos los botones `[data-compartir-pdf]`
de la página:

- Atributos: `data-pdf` (URL), `data-archivo` (nombre), y opcionales `data-texto`, `data-telefono`,
  `data-marcar` (URL a la que se hace `POST` tras compartir) y `data-precargar`
  (`al-cargar` | `al-apuntar`).
- **Con texto** (cotizaciones): comportamiento actual sin cambios. Comparte archivo y texto; si el
  navegador no comparte archivos, descarga y abre `wa.me`; al terminar, `POST` a `data-marcar`.
- **Sin texto** (facturas): comparte **solo el archivo**. Si el navegador no puede compartir
  archivos (`puedeCompartirArchivos()`, que prueba `navigator.canShare` con un PDF vacío), **el
  botón no se muestra**. Si el menú se rechaza por otra razón, descarga el PDF y lo avisa ("No se
  pudo abrir el menú de compartir; el PDF se descargó"). Nunca abre WhatsApp.
- **Precarga**: el menú del sistema solo abre mientras dura el gesto del usuario, y esperar la
  descarga ahí lo agota.
  - `al-cargar` (detalle de factura): baja el PDF al cargar la página.
  - `al-apuntar` (renglones del listado): lo baja en `mouseenter` o `focus` del botón.
  - Cada PDF se baja una sola vez por página (se guarda en memoria por botón). Si al hacer clic no
    ha terminado, el botón dice "Preparando..." y el menú abre al terminar.
  - Sin `data-precargar` (cotización), baja al hacer clic, como hoy.
- Cerrar el menú sin elegir destino (`AbortError`) no es error ni descarga nada. 401/419 o redirect
  recargan la página.
- Compartir una factura no cambia su estado ni registra nada.
- `puedeCompartirArchivos(navegador)` es una función pura, exportada con `module.exports` para
  `tests/js/compartir-pdf.test.js` (con `navigator` simulado): sin `canShare` → falso; `canShare`
  que rechaza archivos → falso; que los acepta → verdadero.

### Búsqueda dinámica y confirmaciones

`busqueda-dinamica.js` y `data-confirmar` de `app.js`, sin cambios.

## Pruebas (Pest y Node)

Todas con `Http::fake()` para facturapi.io (ninguna prueba sale a la red) y `Mail::fake()`:

- Acceso: invitado al login, usuario suspendido fuera; cualquier ruta sobre una factura ajena → 404;
  cliente o artículo ajeno → error de validación.
- Alta: folio consecutivo por usuario; totales calculados por el servidor (los enviados se
  ignoran); copias `clave_prod_serv`/`clave_unidad`/`objeto_imp` tomadas del artículo; línea sin
  artículo rechazada; artículo repetido rechazado; `objeto_imp = 01` con tasa 16 rechazado; PPD con
  forma ≠ 99 y PUE con 99 rechazados.
- Timbrado exitoso: guarda cada campo de la tabla de mapeo, las copias de receptor y emisor, queda
  `timbrada` y redirige al detalle.
- Timbrado fallido: 4xx → `pendiente`, error `datos`, redirige a editar con el mensaje; 5xx y
  `ConnectionException` → `pendiente`, error `pac`, redirige al detalle; reintento exitoso limpia
  el error.
- Candado: con el candado tomado, `timbrar` no llama a facturapi.io (`Http::assertNothingSent`).
- Inmutabilidad: editar o eliminar una `timbrada`/`cancelada` → 403; corregir una `pendiente` con
  error `pac` → 403.
- Copias fiscales: cambiar el RFC del cliente después de timbrar no cambia el receptor del detalle
  ni del PDF.
- Payload (unitaria, `ConstructorPayloadFacturapi`): `tax_included: false` en cada ítem; `discount`
  = descuento de línea + parte global (caso de la remota: $700 + $300 con descuento global de $500
  → total $580.00); impuestos por tasa y por `objeto_imp`; payload de complemento con `taxes`
  proporcionales.
- Cancelación: `accepted` → `cancelada` con fecha; `pending` → sigue `timbrada` con "en proceso";
  el `show` siguiente con `accepted` la pasa a `cancelada`; si esa consulta falla, el detalle
  responde 200 con el aviso; motivo `01` sin sustituta, con una ajena o con ella misma → error.
- XML: responde el contenido de facturapi.io; si falla, redirect con el mensaje; en `pendiente` →
  rechazado.
- PDF: `application/pdf`, nombre con el folio fiscal; en `pendiente` → rechazado.
- Correo: lleva XML y PDF adjuntos y los destinatarios; si el XML falla no se envía nada; en
  `cancelada` → rechazado.
- Complemento: solo `timbrada` + PPD; monto mayor al total rechazado; segundo complemento
  `timbrado` rechazado; uno en `error` se puede reintentar.
- Listado: filtros por cliente, RFC, folio (`FAC-0012`, `12`, folio fiscal), UUID y estado;
  `/facturas/buscar` devuelve el fragmento.
- Node: `tests/js/compartir-pdf.test.js`; `node --check` sobre los scripts tocados.
- `CotizacionesTest`, `EnvioCotizacionTest`, `EstiloUniformeTest` y el fixture de la calculadora
  siguen pasando (cotizaciones cambia de script de compartir).

**Verificación contra el sandbox** (a mano, con `FACTURAPI_ENV=test`, dentro de una transacción
revertida o borrando después lo creado): timbrar una factura simple ($100 al 16% → $116.00) y la
del caso de descuento global ($580.00), comparando el `total` de la respuesta con el local;
cancelar con motivo `02` y con `01`; bajar el XML; timbrar un complemento. Se confirman ahí:
`verification_url`, los datos del emisor en la respuesta, el valor de `substitution`, `rejected`
y el mecanismo de idempotencia.

## Fuera de alcance

- Notas de crédito/Egreso, Traslado o Nómina: solo "Ingreso".
- Parcialidades múltiples: un solo complemento por factura.
- Conversión cotización → factura y el vínculo entre ambas
  ([remotas/043](remotas/043-facturas-parciales-cotizacion.md)).
- Ajuste al peso cerrado ([remotas/030](remotas/030-total-al-peso-cerrado.md)) y precio
  distribuidor ([remotas/033](remotas/033-precio-distribuidor.md)).
- Series/folios configurables por el usuario.
- Reenvío automático de correo al timbrar; correo en cola.
- Descarga de XML/PDF del complemento de pago.
- Validación de RFC o de compatibilidad uso de CFDI ↔ régimen contra el SAT: la valida
  facturapi.io al timbrar y su mensaje se muestra tal cual.
- Webhooks o timbrado asíncrono.
- Roles/permisos diferenciados y multiempresa.

## Estado de implementación

Implementada el 2026-09-28 y verificada ese mismo día contra el sandbox de facturapi.io, con el
código real de la app (`TimbradorFacturas`, `CanceladorFacturas`, `FacturapiCliente`) y la base
local dentro de transacciones revertidas. Las facturas de prueba que se crearon en el sandbox se
cancelaron al terminar.

- **Totales timbrados = totales del sistema**, comparando el `total` de la respuesta: $116.00
  (simple), $580.00 (700 + 300 con $500 de descuento global), $184.65 (tasas 16/0/exento, descuento
  de línea 10% y global 5%) y $291.16.
- **Corregido tras la verificación**:
  - Exento necesita `rate`: `{ type: 'IVA', rate: 0, factor: 'Exento' }`. Sin él, facturapi.io
    responde "El campo items.N.product.taxes.0.rate es requerido".
  - `stamp.date` llega como la FechaTimbrado del XML: hora de México **sin zona**
    (`2026-09-28T18:50:00`). Antes se interpretaba como UTC y quedaba 6 horas corrida. Ahora se
    interpreta en la zona del negocio y se guarda en UTC.
  - `cfdi_version` llega como número (`4`) y se guarda como `"4.0"`.
  - Idempotencia implementada (ver "Reintento tras timeout"). Se probó en vivo: tras timbrar se
    simuló localmente un timeout, se reintentó, y el 409 llevó a adoptar el mismo CFDI (mismo `id`)
    sin timbrar otro.
- **Confirmado**:
  - Los datos del emisor vienen en `issuer_info` (`legal_name`, `tax_id`, `tax_system`,
    `address.zip`); el CP de `address.zip` coincide con el `LugarExpedicion` del XML. Por eso
    `FACTURAPI_EMISOR_*` es solo un respaldo y puede quedar vacío.
  - `verification_url` sí llega (la URL armada a mano queda de respaldo).
  - Cancelación con motivo `02` y con motivo `01` + `substitution` = UUID de la sustituta: ambas
    `accepted` de inmediato en el sandbox, con `status: canceled`. `refrescar()` lo lee bien.
  - XML en vivo (4.5 KB, `Total` = total del sistema). Complemento de pago parcial ($58 de $116)
    timbrado con el payload real.
  - `GET /invoices?external_id=…` encuentra el CFDI.
- **Sin confirmar**: `rejected` en `cancellation_status` (el sandbox acepta todo de inmediato).
- **Reutilización**: `FacturaRequest` extiende `CotizacionRequest` (mismas reglas de líneas,
  descuentos y topes). `CotizacionRequest` ganó el método `documento()` para que la regla del
  artículo sirva a los dos. `_linea` y `_mensajes` se movieron de `cotizaciones/` a
  `resources/views/documentos/`, y el aviso de duplicado se extrajo a
  `documentos/_aviso-duplicado.blade.php`.
- **Doble clic**: además del candado del servidor, `app.js` ganó `data-enviar-una-vez`, que
  deshabilita el botón al enviar su formulario y lo reactiva al volver con "Atrás".
- **Script de apoyo** `public/js/facturas.js`: fija la forma de pago `99` al elegir PPD y muestra
  el campo de sustituta solo con el motivo `01`.
- **Compartir**: `compartir-pdf.js` reemplazó a `compartir-cotizacion.js`. La cotización conserva
  su comportamiento (texto, respaldo por `wa.me` y `marcar-enviada`); su etiqueta de estado pasó de
  `data-estado-cotizacion` a `data-estado-documento`. Un `MutationObserver` prepara los botones
  que llegan con la búsqueda dinámica del listado.
- **PDF**: Dompdf no parte palabras largas, así que los sellos se cortan en renglones de 100
  caracteres. Revisado visualmente, incluida la marca de agua "CANCELADA". Pesa ~37 KB.
- **Prueba de cotizaciones corregida**: `CotizacionPagosTest` fallaba entre las 18:00 y las 24:00
  de México porque tomaba la fecha en UTC. Ahora la toma en la zona del negocio. No tiene relación
  con facturación.
- **Verificación**: la suite Pest pasa (615 tests; 87 nuevos en `FacturasTest`,
  `FacturaDocumentosTest`, `ConstructorPayloadFacturapiTest` e `ImporteEnLetraTest`), y también
  `node --test "tests/js/*.test.js"` (36). Pint no reporta cambios y `node --check` valida los
  scripts. La migración corrió en MySQL. Los filtros del listado, el bloqueo del folio y el PDF se
  ejecutaron contra MySQL dentro de una transacción revertida. **No se revisó la UI en un
  navegador real**: falta abrir `/facturas/crear` (buscador, PPD → 99, resumen en vivo), el
  detalle con sus diálogos (enviar, cancelar con motivo 01, complemento) y "Compartir PDF" en
  Windows 11 y en un teléfono.

## Criterios de aceptación

1. Un usuario autenticado puede crear una factura eligiendo un cliente y una o varias líneas de
   artículo (cantidad, precio editable, descuento opcional, tasa de IVA), con los totales
   desglosados en vivo antes de enviar.
2. "Generar y timbrar" con datos válidos timbra en facturapi.io, guarda sellos, UUID, folio fiscal y
   las copias fiscales de receptor y emisor (sin guardar XML ni PDF) y lleva al detalle.
3. Si facturapi.io rechaza los datos, su mensaje se muestra, la factura queda `pendiente` en el
   listado sin perder lo capturado, y se puede corregir y reintentar. Si el fallo es del PAC o por
   timeout, se puede reintentar con los mismos datos.
4. Los totales guardados son siempre los del servidor, calculados con la misma calculadora que las
   cotizaciones.
5. Una factura `timbrada` o `cancelada` no puede editarse ni eliminarse; una `pendiente` sí puede
   eliminarse.
6. `/facturas` muestra solo las facturas del usuario, paginadas, filtrables por cliente, RFC,
   folio, UUID y estado, sin recargar y con los filtros en la URL.
7. Una factura `timbrada` puede cancelarse con un motivo; con `01` exige una sustituta propia y
   timbrada. Pasa a `cancelada` cuando facturapi.io confirma `accepted`, de inmediato o al reabrir
   el detalle.
8. Desde el detalle de una factura `timbrada` o `cancelada` se descarga el XML (en vivo desde
   facturapi.io) y el PDF (al vuelo, con QR del SAT, sellos y cadena original); ninguno se guarda.
9. Una factura `timbrada` se envía por correo con XML y PDF a uno o varios destinatarios,
   prellenados con el correo del cliente.
10. Una factura `timbrada` con PPD permite registrar un complemento de pago (fecha, monto editable,
    forma de pago) que se timbra por separado; no se permite un segundo complemento timbrado.
11. Con descuento global, el total mostrado, el IVA desglosado y el total timbrado por facturapi.io
    coinciden exactamente.
12. En cualquier factura con IVA, facturapi.io **suma** el IVA sobre el precio neto (no lo extrae).
13. Desde el detalle y desde el renglón del listado de una factura `timbrada` o `cancelada` se
    comparte el PDF, solo el PDF, sin texto, por el menú del sistema; el estado no cambia; cerrar
    el menú no muestra error.
14. En un navegador que no puede compartir archivos, el botón de compartir de facturas no aparece;
    quedan "Descargar PDF" y el correo. El compartir de cotizaciones sigue funcionando como antes.
15. Corregir el RFC de un cliente o la clave SAT de un artículo no altera las facturas ya timbradas.
16. Un doble clic en "Generar y timbrar" o "Reintentar timbrado" no produce dos CFDI.
17. Actuar sobre una factura ajena responde 404.
18. El menú muestra "Facturas" con su icono.
19. Pint corre sin cambios, la suite Pest pasa y `node --test "tests/js/*.test.js"` pasa.

## Supuestos asumidos (registro completo)

1. Esta spec es la reescritura local de [remotas/007](remotas/007-facturacion.md) para Laravel +
   Blade + JavaScript nativo; conserva las reglas de negocio y las lecciones de la API real.
2. Cada factura pertenece al usuario que la crea y a uno de sus clientes, con el mismo
   aislamiento por `user_id` que 004/005/007/011.
3. Toda línea viene de un artículo del catálogo (sin líneas libres): el CFDI necesita sus claves
   SAT.
4. Descripción, modelo y precio de la línea se precargan del artículo y son copias editables;
   las claves SAT también son copias, pero no editables en la línea.
5. Descuento por línea y descuento global (porcentaje o monto), con el algoritmo y la calculadora
   de 011; el global se prorratea y viaja dentro del `discount` de cada ítem.
6. Tasa de IVA por línea: 16%, 0% o exento. Un artículo que no es objeto de impuesto solo admite
   exento.
7. Uso de CFDI, forma y método de pago se capturan por factura. PPD implica forma `99`; PUE no la
   admite.
8. Tipo de comprobante siempre Ingreso; moneda siempre MXN.
9. No hay guardado manual de borrador: se timbra al enviar; si falla queda `pendiente`. `borrador`
   solo existe entre el alta y el primer intento.
10. Tras un error de datos se puede corregir y reintentar; tras un error del PAC o timeout, solo
    reintentar con los mismos datos (o eliminar y capturar de nuevo).
11. Las facturas `timbrada`/`cancelada` son inmutables y no se eliminan; `borrador`/`pendiente` se
    eliminan físicamente.
12. Folio interno consecutivo por usuario, que nunca se reutiliza, distinto del folio fiscal de
    facturapi.io, que es el que se muestra en el documento timbrado.
13. La cancelación puede no ser inmediata; `estado_cancelacion` refleja a facturapi.io y se
    refresca al abrir el detalle, sin botón manual ni proceso en segundo plano.
14. Ni el XML ni el PDF se guardan; el XML se pide en vivo, el PDF se arma con los datos guardados.
15. El PDF usa una plantilla propia (no el de facturapi.io), con QR del SAT.
16. Al timbrar se guardan copias del receptor y del emisor para que el PDF siempre coincida con el
    XML.
17. El correo es manual, síncrono, lleva XML y PDF, y no cambia el estado.
18. Compartir entrega solo el PDF al menú del sistema, sin texto, sin cambiar el estado; el botón
    se oculta si el navegador no puede compartir archivos.
19. Un solo complemento de pago por factura PPD, con monto editable no mayor al total; si su
    timbrado falla se puede reintentar.
20. La integración usa el cliente HTTP de Laravel con timeout explícito; un timeout es un error de
    timbrado de tipo `pac`.
21. Un candado por factura impide timbrar dos veces a la vez.
22. La validación fiscal fina (RFC, uso de CFDI vs régimen) la hace facturapi.io; el sistema solo
    valida contra sus enums.
23. La conversión desde cotización llegará con su propia spec.
24. Credenciales de facturapi.io solo en `.env`, seleccionadas por `FACTURAPI_ENV`.
