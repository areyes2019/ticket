# Spec: Facturación (generación y timbrado CFDI vía facturapi.io)

## Historia de usuario

Como usuario registrado, quiero generar una factura seleccionando un cliente y uno o varios
artículos, ver las sumas desglosadas al final, y timbrarla con los sellos fiscales del SAT por
medio del PAC facturapi.io, para poder emitir CFDI válidos a mis clientes y quedarme con los sellos
guardados en la base de datos.

## Objetivo / Alcance

Implementar el módulo de facturación sobre la base ya existente de Laravel API + Vue 3 SPA +
Sanctum (ver [001](001-inicio-proyecto.md), [002](002-login-auth.md)), el design system de
[003](003-design-system-tailwind.md), y los catálogos de [Cliente](004-gestion-clientes.md) y
[Artículo](006-gestion-articulos.md) ya implementados. Incluye: captura de la factura (cliente +
líneas de artículo con precio/descuento/IVA editables), cálculo y desglose de totales, timbrado
síncrono vía la API de facturapi.io, un flujo básico de cancelación de CFDI, descarga de XML/PDF
(con plantilla propia), envío de la factura por correo, compartir su PDF por el menú de
compartir del sistema operativo, y un complemento de pago básico para método PPD. **No** incluye
notas de crédito/egreso, parcialidades múltiples de pago, ni multiempresa.

## Backend (Laravel)

### Modelo `Factura`

- Pertenece a un `User` (`user_id`) y a un `Cliente` (`cliente_id`, obligatorio, debe pertenecer
  al mismo usuario).
- `folio`: entero autoincremental **por usuario**, asignado desde que se crea la factura (incluso
  en `borrador`/`pendiente`, antes de timbrar) — sirve para identificarla en pantalla mientras no
  tiene folio fiscal todavía. Es un identificador **interno** del sistema, no el folio fiscal (ver
  `facturapi_serie`/`facturapi_folio` más abajo).
- **Estado** (`estado`, string/enum): `borrador` → `pendiente` → `timbrada` | `cancelada`.
  - `borrador`: factura recién creada, antes del primer intento de timbrado (transición casi
    instantánea a `pendiente` o `timbrada`, ya que no hay guardado manual de borrador).
  - `pendiente`: el timbrado fue intentado y falló (error de datos, error del PAC o timeout);
    reintentable.
  - `timbrada`: timbrado exitoso, con sellos/UUID persistidos.
  - `cancelada`: CFDI cancelado ante el PAC — solo se alcanza cuando `estado_cancelacion =
    accepted` (ver más abajo); mientras esté en `pending`/`verifying`, la factura sigue como
    `timbrada` con la cancelación en curso.
- **Datos fiscales de cabecera**: `uso_cfdi` (catálogo SAT `c_UsoCFDI`), `forma_pago` (catálogo SAT
  `c_FormaPago`), `metodo_pago` (`PUE`|`PPD`, enum fijo de backend, sin catálogo externo — mismo
  patrón que `objeto_imp` en [006](006-gestion-articulos.md)), `moneda` (fijo `MXN`),
  `tipo_comprobante` (fijo `I` de Ingreso, sin otros tipos en esta historia).
- **Descuento global**: `descuento_global_tipo` (`porcentaje`|`monto`, nullable),
  `descuento_global_valor` (nullable) — adicional a los descuentos por línea, no los sustituye.
  **(Corregido — ver "Cálculo de totales e IVA con descuento global" más abajo)**: el CFDI (y por
  lo tanto facturapi.io) no tiene un concepto de "descuento a nivel factura" — el nodo
  `Descuento` del SAT solo existe por concepto/línea. Por eso el monto del descuento global se
  **prorratea entre las líneas antes de calcular el IVA de cada una**, de modo que (a) el IVA
  interno ya refleje el descuento global (correcto fiscalmente: el descuento reduce la base
  gravable antes del impuesto, no después) y (b) el campo `discount` que se envía a facturapi.io
  por cada línea incluya, además de su descuento manual, su parte prorrateada del descuento
  global — así el total que muestra el sistema y el total que queda timbrado en el CFDI siempre
  coinciden exactamente.
- **Totales** (siempre recalculados en backend, nunca persistidos tal cual los mande el frontend):
  `subtotal`, `total_descuento` (líneas + global), `total_iva_16`, `total_iva_0`,
  `total_exento`, `ajuste_al_peso`, `total`. `total_iva_16`/`total_iva_0` se calculan **después** de
  aplicar tanto el descuento de línea como la porción prorrateada del descuento global a cada línea
  (ver "Cálculo de totales e IVA con descuento global" más abajo). `ajuste_al_peso` son los centavos
  que suben el total al peso cerrado, el último eslabón del cálculo
  ([030](030-total-al-peso-cerrado.md)).
- **Sellos/timbrado** (nullable hasta timbrarse; mapeados de la respuesta real de facturapi.io al
  timbrar — `uuid`, `folio_number`, `series`, `status`, objeto `stamp{...}`):
  - `facturapi_invoice_id` (campo `id` de la respuesta): identificador propio de facturapi.io para
    esta factura, usado en las llamadas posteriores a su API (cancelación, descarga de XML/PDF,
    consulta de estado) — es distinto del `uuid_fiscal` del SAT.
  - `uuid_fiscal` (`uuid`): folio fiscal (UUID) del CFDI ante el SAT.
  - `facturapi_serie` (`series`) y `facturapi_folio` (`folio_number`): la serie/folio **fiscal
    real** que asigna facturapi.io al timbrar, mostrados en el detalle/PDF de la factura ya
    timbrada. Distintos del `folio` interno (ver punto anterior).
  - `sello_cfdi` (`stamp.signature`), `sello_sat` (`stamp.sat_signature`), `no_certificado_sat`
    (`stamp.sat_cert_number`), `fecha_timbrado` (`stamp.date`), `version_comprobante`
    (`cfdi_version`, a nivel raíz de la respuesta, no dentro de `stamp`), `cadena_original_sat`
    (`stamp.complement_string`) — nombres verificados con una llamada real al ambiente de pruebas
    de facturapi.io.
  - No se guarda ninguna ruta de archivo (ni XML ni PDF): esta historia no persiste ningún
    documento físico en el sistema. El XML se consulta en vivo a facturapi.io cada vez que se
    descarga (`GET /v2/invoices/{facturapi_invoice_id}/xml`, endpoint dedicado confirmado por los
    métodos `downloadXml`/`DownloadXmlAsync` de los SDKs oficiales), y el PDF se genera al vuelo a
    partir de los campos ya guardados en esta tabla (ver Frontend/PDF más abajo).
- **Error de timbrado**: `error_timbrado` (texto, nullable) — mensaje devuelto por facturapi.io en
  el último intento fallido, mostrado en pantalla al reintentar.
- **Cancelación**: `motivo_cancelacion` (catálogo SAT `c_MotivoCancelacion`, nullable),
  `factura_sustituta_id` (FK nullable a otra `Factura` propia y timbrada, obligatorio solo si
  `motivo_cancelacion = 01`), `fecha_cancelacion` (nullable).
  - `estado_cancelacion` (`none`|`pending`|`verifying`|`accepted`, nullable): refleja el
    `cancellation_status` que devuelve facturapi.io — la cancelación de un CFDI puede requerir
    aceptación del receptor o validación del SAT, no siempre es inmediata. `none` es el valor real
    que trae toda factura timbrada antes de intentar cancelarla; en la práctica no llega a
    persistirse (`estado_cancelacion` solo se escribe a partir de que se llama `cancelar()`), pero
    el enum lo contempla para no romper si la API lo devolviera en una consulta de refresco. El
    campo `estado` de la factura solo pasa a `cancelada` cuando `estado_cancelacion = accepted`.
- Sin soft deletes: solo se permite `DELETE` físico mientras el estado es `borrador`/`pendiente`
  (antes de timbrar no hay nada fiscal que preservar); `timbrada`/`cancelada` nunca se eliminan.

### Cálculo de totales e IVA con descuento global

**(Corrección — bug detectado el 2026-07-31: el descuento global no se timbraba en el CFDI real,
solo restaba del total mostrado en el sistema; ver "Estado de implementación" para el detalle del
hallazgo.)**

El CFDI no tiene un concepto de "descuento a nivel factura" — el nodo `Descuento` del SAT solo
existe por concepto/línea. `FacturaTotalesCalculator` calcula los totales en dos pasadas para que
el descuento global sí quede reflejado, tanto en el IVA interno como en lo que se timbra:

1. **Primera pasada, por línea**: para cada línea, `bruto = cantidad × precio_unitario`;
   `descuento_línea` se calcula de `descuento_tipo`/`descuento_valor` de esa línea (igual que
   antes); `importe_pre_global = bruto − descuento_línea`. La suma de todos los `importe_pre_global`
   da el `subtotal` de la factura.
2. **Prorrateo del descuento global**: se calcula el monto monetario del descuento global
   (`descuento_global_tipo`/`valor` aplicado sobre `subtotal`, igual que antes). Ese monto se
   reparte entre las líneas **proporcionalmente a su `importe_pre_global`**
   (`parte_línea = round(descuento_global_monto × importe_pre_global / subtotal, 2)`), con la
   última línea absorbiendo el residuo de redondeo para que la suma de las partes sea exactamente
   igual al monto del descuento global (evita descuadres de centavos).
3. **Segunda pasada, IVA por línea**: `importe_neto_final_línea = importe_pre_global −
   parte_línea`; el IVA de cada línea se calcula sobre `importe_neto_final_línea` según su
   `tasa_iva`. `total_iva_16`/`total_iva_0`/`total_exento` son la suma de esos IVA por línea.
4. **Total**: `subtotal − descuento_global_monto + total_iva_16 + total_iva_0`, más el
   `ajuste_al_peso` que cierra el total en pesos cerrados ([030](030-total-al-peso-cerrado.md)) — un
   eslabón posterior que no toca ninguno de los sumandos anteriores.
5. **Payload a facturapi.io**: el `discount` de cada ítem que arma `FacturapiService` pasa a ser
   `descuento_línea + parte_línea` (antes solo era `descuento_línea`) — así facturapi.io calcula
   el IVA sobre la misma base neta final que ya calculó el backend, y el CFDI timbrado coincide
   con el total mostrado en el sistema. Cuando hay `ajuste_al_peso`, el payload lleva además un
   ítem final "Ajuste al peso" sin traslados ([030](030-total-al-peso-cerrado.md)).
6. Si una factura no tiene descuento global (`descuento_global_valor` nulo), `parte_línea = 0`
   para todas las líneas y el comportamiento es idéntico al actual (sin cambios para el caso sin
   descuento global).

**(Adición técnica)** El formulario `/facturas/crear`/`editar` (frontend) replica este mismo
algoritmo en TypeScript para el desglose de totales en tiempo real, antes de enviar la factura
(ver sección Frontend más abajo). Backend (PHP, `FacturaTotalesCalculator`) y frontend
(TypeScript, `FacturaFormView.vue`) son dos implementaciones independientes del mismo cálculo —
no hay una fuente única compartida entre ambos lenguajes — así que **cualquier cambio futuro a
este algoritmo debe aplicarse en los dos lados**, o el backend rechazará el `total` que envíe el
frontend (ver criterio de aceptación #4). Este desincronizado fue justo la causa de un bug
detectado el 2026-07-31: se corrigió el cálculo en el backend pero no en el frontend, dejando
imposible timbrar cualquier factura con descuento global hasta corregir ambos lados.

### `tax_included: false` explícito por línea

**(Bug separado, detectado el 2026-07-31 al verificar en vivo el prorrateo del descuento
anterior — no tiene relación con el descuento en sí, afecta a toda factura con IVA, tenga o no
descuento global.)**

facturapi.io trata el `price` de cada ítem como si **ya incluyera el IVA** por defecto
(`tax_included: true`), y por lo tanto **extrae** el impuesto de adentro del precio en vez de
sumarlo encima. Todo el diseño de esta historia (`FacturaTotalesCalculator`, el formulario, la
columna "Total" por línea) asume lo contrario: que `precio_unitario` es **antes** de IVA y el
impuesto se suma encima. Verificado en vivo contra el sandbox: con `price=700`, `discount=350`
(base neta $350) y sin declarar `tax_included`, facturapi.io timbró un total de **$350.01**
(extrajo el 16% de $350 en vez de sumarlo); con `tax_included: false` declarado explícitamente,
timbró **$406.00** (16% de $350 sumado encima — el resultado correcto).

**Corrección**: `FacturapiService::construirPayloadFactura()` debe declarar
`'tax_included' => false` dentro del `product` de cada ítem del payload, siempre — no solo
cuando hay descuento. Sin esto, **toda** factura timbrada por este sistema (con descuento o sin
él) queda estampada por un monto menor al que el sistema muestra internamente. El complemento de
pago (`construirPayloadComplementoPago`) no se ve afectado: no envía un arreglo `items` propio
con precio (facturapi.io genera automáticamente un ítem interno tipo "Pago" sin intervención
nuestra).

### Modelo `FacturaLinea`

- Pertenece a una `Factura` (`factura_id`) y a un `Articulo` (`articulo_id`, debe pertenecer al
  mismo usuario).
- `cantidad`: entero, obligatorio, **mínimo 1**.
- `descripcion`: string, obligatorio. Se precarga con `Articulo.nombre` al agregar la línea, pero
  es **editable in-place** — una copia propia de la línea, desacoplada del catálogo (editarla no
  modifica el `Articulo` original).
- `modelo`: string, obligatorio. Se precarga con `Articulo.modelo` al agregar la línea, mismo
  criterio que `descripcion` (copia editable, no referencia viva).
- `precio_unitario`: decimal, obligatorio, mayor a 0. Se precarga con
  `Articulo.precio_unitario_sin_iva` al agregar la línea, pero es **editable** — tampoco es una
  referencia viva al artículo. Si el cliente elegido es distribuidor, se precarga con
  `Articulo.precio_distribuidor_sin_iva` en su lugar
  ([033](033-precio-distribuidor.md)).
- `descuento_tipo` (`porcentaje`|`monto`, nullable), `descuento_valor` (nullable; si
  `porcentaje`, entre 0 y 100).
- `tasa_iva` (`16`|`0`|`exento`, obligatorio, por línea).
- `importe` e `iva_importe`: calculados en backend. `importe` = `cantidad * precio_unitario` menos
  el descuento de línea (**importe neto, antes de IVA** — es el valor que se muestra como "Total"
  de la fila en el frontend); `iva_importe` es el IVA de esa línea según `tasa_iva`, desglosado
  aparte en los totales generales de la factura, no sumado al "Total" de la fila.

### Modelo `ComplementoPago`

- Pertenece a una `Factura` con `metodo_pago = PPD` y estado `timbrada`. Relación 1:1 (una sola
  factura no puede tener más de un complemento de pago en esta historia — sin parcialidades
  múltiples).
- `fecha_pago`, `monto` (decimal, editable, precargado con el `total` de la factura pero no
  forzado a coincidir con él), `forma_pago` (catálogo SAT `c_FormaPago`).
- `estado` (`pendiente`|`timbrado`|`error`), `facturapi_invoice_id`, `uuid_fiscal`, `sello_cfdi` —
  mismo patrón de timbrado que `Factura`, vía su propia llamada a facturapi.io (un complemento de
  pago es también un tipo de CFDI, con su propio `facturapi_invoice_id`). Tampoco guarda ruta de
  archivo: mismo criterio que `Factura`, sin XML/PDF persistidos localmente.

### Catálogos SAT

Se amplía la base SQLite reducida de catálogos SAT creada en 004 y ampliada en 006
(`storage/app/sat-catalogos.sqlite`, comando `catalogos-sat:actualizar`) con dos tablas nuevas:

- `GET /api/v1/catalogos/usos-cfdi?q=...` — búsqueda sobre `c_UsoCFDI`. Desde
  [029](029-pwa-mostrador.md), `q` es opcional: sin él se devuelve el catálogo completo ordenado por
  clave, que es lo que la pantalla de opciones del mostrador necesita para abrir con la lista a la
  vista.
- `GET /api/v1/catalogos/formas-pago?q=...` — búsqueda sobre `c_FormaPago`.
- `GET /api/v1/catalogos/motivos-cancelacion` — catálogo `c_MotivoCancelacion` (solo 4 valores
  fijos: 01–04), se sirve completo sin `?q=` por su tamaño reducido.
- `metodo_pago` (PUE/PPD) no requiere endpoint: son 2 valores fijos embebidos en frontend, igual
  que `objeto_imp` en 006.

### Integración con facturapi.io

- Se usa el **SDK oficial de PHP** (`facturapi/facturapi-php`, vía Composer, requiere PHP 8.2+)
  en vez de armar las llamadas HTTP a mano — cubre autenticación, serialización y excepciones
  tipadas (`FacturapiException`).
- Service class dedicado (`app/Services/FacturapiService.php` o similar) que envuelve el SDK y
  arma el payload del CFDI a partir de la `Factura`/`FacturaLinea`s (cliente y artículos enviados
  **inline** en cada solicitud — no requieren pre-registro/sincronización previa en facturapi.io).
- Credenciales por ambiente: `FACTURAPI_TEST_KEY`/`FACTURAPI_LIVE_KEY` en `.env`, seleccionadas
  según `FACTURAPI_ENV` (`test`|`live`). Ambas apuntan al mismo dominio base
  (`https://www.facturapi.io/v2`), solo cambia la API key. Los clientes/productos/facturas creados
  con la key de test no se comparten con la de live (catálogos independientes dentro de la misma
  cuenta).
- Llamada **síncrona**, con **timeout explícito** (ej. 30s). Un timeout se trata igual que un
  error de timbrado: la factura queda en `pendiente` con `error_timbrado` describiendo el
  timeout, reintentable.
- Se crea la factura en modo de timbrado inmediato (sin `status: draft`, que es un modo nativo de
  facturapi.io no usado en esta historia) para mantener el comportamiento síncrono ya definido.
- Al timbrar con éxito: se guardan sellos/UUID/`facturapi_invoice_id` en la base de datos. No se
  descarga ni se persiste ningún archivo (ni XML ni PDF) en ese momento — ver "Recuperación de
  XML/PDF" más abajo.

### Recuperación de XML/PDF

- No se guarda ningún PDF ni XML físico en el sistema en ningún momento del flujo.
- **PDF**: se genera siempre **al vuelo**, en cada solicitud, usando únicamente los datos ya
  guardados localmente en `Factura`/`FacturaLinea` (UUID, folio/serie fiscal, sellos, cliente,
  líneas, totales) y la plantilla propia del sistema — no requiere el XML ni una llamada a
  facturapi.io.
- **XML**: no se guarda copia local. Cada solicitud de descarga hace una llamada en vivo a
  `GET /v2/invoices/{facturapi_invoice_id}/xml` de facturapi.io y sirve el resultado directo al
  usuario. Si esa llamada falla (PAC caído, timeout), se responde un error simple de descarga
  ("no se pudo obtener el XML, intenta de nuevo"), sin reintento automático ni caché de respaldo.
- Mismo criterio aplica al complemento de pago: tampoco persiste XML/PDF, aunque esta historia no
  expone un botón de descarga para él en el frontend (solo se timbra y se guardan sus sellos).

### Endpoints (bajo `auth:sanctum`, scopeados al usuario autenticado)

- `GET /api/v1/facturas` — listado paginado, `?search=` (cliente, folio, UUID), `?estado=`.
- `POST /api/v1/facturas` — crea la factura (con sus líneas) y **de inmediato** intenta timbrarla
  contra facturapi.io. Si tiene éxito, queda `timbrada`; si falla (datos, PAC o timeout), queda
  persistida en `pendiente` con `error_timbrado` (no se pierde la captura).
  - El backend recalcula subtotal/descuentos/IVA/total a partir de las líneas recibidas; si el
    total que manda el frontend no coincide con el recalculado, responde `422` (protege contra
    bugs de cálculo del frontend, no solo se ignora en silencio).
- `GET /api/v1/facturas/{id}` — detalle. Si la factura tiene `estado_cancelacion` en `pending` o
  `verifying`, el backend re-consulta a facturapi.io (`GET /v2/invoices/{facturapi_invoice_id}`)
  automáticamente antes de responder, y refresca `estado_cancelacion`/`estado` si ya cambió.
- `PUT /api/v1/facturas/{id}` — edición; solo permitida si el estado es `borrador` o `pendiente`
  (`422` si `timbrada`/`cancelada`).
- `DELETE /api/v1/facturas/{id}` — solo permitida si el estado es `borrador` o `pendiente`
  (`422` si `timbrada`/`cancelada`); borrado físico (sin soft delete).
- `POST /api/v1/facturas/{id}/timbrar` — reintento de timbrado de una factura `pendiente`. Si el
  `error_timbrado` previo fue de validación de datos, el frontend permite editar antes de
  reintentar; si fue error del PAC/timeout, solo reenvía los mismos datos.
- `POST /api/v1/facturas/{id}/cancelar` — body `{ motivo_cancelacion, factura_sustituta_id? }`;
  llama a `DELETE /v2/invoices/{facturapi_invoice_id}?motive=XX` (con
  `substitution=<uuid_fiscal de la factura sustituta>` si el motivo es `01`). Guarda el
  `estado_cancelacion` que responda facturapi.io (`pending`/`verifying`/`accepted`); la factura
  solo pasa a `cancelada` cuando ese valor es `accepted`. Solo aplicable a facturas `timbrada`.
- `GET /api/v1/facturas/{id}/xml` — hace proxy en vivo a `GET /v2/invoices/{facturapi_invoice_id}/xml`
  de facturapi.io y devuelve el archivo directo al usuario (solo si `timbrada` o `cancelada`); no
  lee ni escribe nada en storage. Si la llamada a facturapi.io falla, responde un error simple.
- `GET /api/v1/facturas/{id}/pdf` — genera el PDF **al vuelo** a partir de los campos ya guardados
  en `Factura`/`FacturaLinea` (no llama a facturapi.io ni depende del XML), usando la plantilla
  propia del sistema (ver Frontend/PDF más abajo); no se guarda copia en storage.
- `POST /api/v1/facturas/{id}/enviar-correo` — body `{ destinatarios: [string, ...] }`; envía un
  correo con el XML y el PDF adjuntos a los destinatarios indicados (vía Mailpit en desarrollo,
  igual que [002](002-login-auth.md)). Solo aplicable a facturas `timbrada`.
- `POST /api/v1/facturas/{id}/complemento-pago` — crea y timbra el complemento de pago de una
  factura `timbrada` con `metodo_pago = PPD` (`422` si `metodo_pago = PUE` o si ya existe un
  complemento para esa factura).

### Validaciones (Form Requests)

- `cliente_id`: requerido, debe existir y pertenecer al usuario autenticado.
- `lineas`: array, mínimo 1 elemento.
  - `articulo_id`: requerido, existe y pertenece al usuario.
  - `cantidad`: requerida, entero, mínimo 1.
  - `descripcion`: requerida, string (precargada del artículo, editable).
  - `modelo`: requerido, string (precargado del artículo, editable).
  - `precio_unitario`: requerido, numérico, mayor a 0.
  - `descuento_tipo`: opcional, uno de `porcentaje`/`monto` (requerido si se manda
    `descuento_valor`).
  - `descuento_valor`: opcional, numérico ≥ 0 (≤ 100 si `descuento_tipo = porcentaje`).
  - `tasa_iva`: requerida, uno de `16`/`0`/`exento`.
- `uso_cfdi`: requerido, debe existir en el catálogo `c_UsoCFDI`.
- `forma_pago`: requerido, debe existir en el catálogo `c_FormaPago`.
- `metodo_pago`: requerido, uno de `PUE`/`PPD`.
- `descuento_global_tipo`/`descuento_global_valor`: opcionales, mismas reglas que a nivel línea.
- Cancelación: `motivo_cancelacion` requerido, existe en `c_MotivoCancelacion`;
  `factura_sustituta_id` requerido (y debe ser una factura propia `timbrada`) solo si
  `motivo_cancelacion = 01`.
- Complemento de pago: `fecha_pago` requerida; `monto` requerido, numérico, mayor a 0;
  `forma_pago` requerida, existe en `c_FormaPago`.
- Correo: `destinatarios` requerido, array con al menos 1 elemento, cada uno con formato de email
  válido.
- Respuestas mediante Laravel API Resources (`FacturaResource`, `FacturaLineaResource`,
  `ComplementoPagoResource`), consistente con la convención de 001/004/005/006.

## Frontend (Vue 3)

- **`/facturas`** (protegida): listado paginado de facturas en tabla (folio, cliente, total,
  estado, fecha), con buscador (`?search=` por cliente/folio/UUID) y filtro por estado.
  - Cada renglón de una factura `timbrada` o `cancelada` lleva su propio **botón de compartir**,
    con el mismo comportamiento del detalle: el PDF solo, al menú del sistema. Compartirle su
    factura a un cliente es lo que más se hace desde esta pantalla, y obligar a abrir el detalle
    para eso es un paso de más.
  - El PDF de un renglón **se empieza a bajar cuando el puntero llega a su botón** (o cuando el
    botón recibe el foco por teclado), no al hacer clic: así, para cuando llega el clic, el
    archivo ya está en memoria y el menú del sistema abre de inmediato. Si no alcanzó, el botón
    dice "Preparando..." y el menú abre al terminar la descarga. Cada PDF se baja **una sola vez**
    por página; no se bajan por adelantado los de toda la lista, que son archivos que casi nadie
    va a compartir.
- **`/facturas/crear`**:
  - Selector de cliente (combobox con búsqueda, reutilizando el patrón de 004).
  - Tabla de líneas: el usuario elige un artículo de una lista/combobox con búsqueda entre sus
    artículos propios; al seleccionarlo, el sistema agrega una fila nueva con cantidad
    precargada en 1 (mínimo 1). Cada fila tiene las columnas, siempre visibles:
    **cantidad | descripción | modelo | precio unitario | descuento | tasa IVA | total**.
    - Un artículo aparece **como máximo en una línea** de la factura: si el elegido ya está
      capturado, el sistema avisa del duplicado y ofrece sumar unidades a la línea existente o
      cancelar, en vez de agregar una fila nueva (regla completa en
      [008](008-cotizaciones.md), sección Frontend — vive en la tabla de líneas compartida por
      factura, cotización y orden de compra).
    - `cantidad`, `descripción` y `modelo` son **editables directamente en la fila**
      (`descripción`/`modelo` se precargan del artículo pero son texto libre editable por línea,
      sin afectar el catálogo de Artículos).
    - `precio unitario`: precargado del artículo, editable.
    - `descuento`: tipo (%/monto) + valor, opcional por línea.
    - `tasa IVA`: `Select` 16%/0%/exento, por línea.
    - `total`: de solo lectura, calculado como importe neto de la línea (cantidad × precio
      unitario − descuento), **sin IVA incluido** — el IVA de cada línea se desglosa aparte en
      los totales generales.
  - Desglose de totales en tiempo real (subtotal, descuentos por línea, descuento global,
    IVA por tasa, ajuste al peso, total), recalculado en el navegador conforme se editan las líneas.
    **(Corregido — bug detectado el 2026-07-31)**: este cálculo en el navegador debe replicar
    **exactamente** el mismo algoritmo de dos pasadas que `FacturaTotalesCalculator` en el
    backend (ver "Cálculo de totales e IVA con descuento global" en el modelo `Factura`): primero
    el importe neto de cada línea (bruto − descuento de línea), luego el descuento global se
    prorratea entre las líneas proporcional a ese importe (con la última línea absorbiendo el
    residuo de redondeo), y **solo entonces** se calcula el IVA de cada línea sobre el importe ya
    neto de esa porción. Calcular el IVA sobre el importe bruto de línea (sin restar la porción
    prorrateada del descuento global) da un `total` que el backend rechaza al enviarse (ver
    criterio de aceptación #4: la petición se rechaza si el total no coincide con el recalculado
    en el servidor) — el formulario quedaría, en la práctica, imposibilitado de timbrar cualquier
    factura con descuento global.
  - Selector de descuento global (tipo + valor), opcional.
  - Selector de Uso de CFDI (combobox con búsqueda), Forma de pago (combobox con búsqueda),
    Método de pago (`Select` PUE/PPD).
  - Botón "Generar y timbrar": envía la factura; si facturapi.io responde éxito, redirige a
    `/facturas/{id}` (representación de la factura ya timbrada); si responde error, permanece en
    la pantalla mostrando el mensaje de error de facturapi.io sin perder los datos capturados (la
    factura ya quedó guardada como `pendiente`, visible también desde el listado).
- **`/facturas/{id}/editar`**: mismo formulario que crear, precargado; solo accesible si el
  estado es `borrador`/`pendiente` (muestra el `error_timbrado` previo si existe). Al guardar,
  reintenta el timbrado.
- **`/facturas/{id}`** (detalle, tras timbrar): representación visual de la factura timbrada
  (folio, UUID, sellos, cliente, líneas, totales), con botones:
  - **"Enviar"**: abre un modal con el correo del cliente prellenado (editable) y soporte para
    agregar múltiples destinatarios; al confirmar, llama a
    `POST /api/v1/facturas/{id}/enviar-correo`.
  - **"Descargar XML"** / **"Descargar PDF"**.
  - **"Compartir PDF"**: entrega el PDF al **menú de compartir del sistema operativo** —en Windows
    11, el catálogo de envío con Drive, WhatsApp, Correo, Bluetooth y lo que el usuario tenga
    instalado— con `compartirArchivo` de `lib/compartir.ts`, el mismo camino que ya usa el
    mostrador (ver [031](031-mostrador-consulta.md)). No hay modal propio ni selector de canal: el
    catálogo del sistema ya es el selector.
    - Va **únicamente el PDF, sin texto de acompañamiento**. El destino puede ser una carpeta o
      Drive, donde un mensaje pegado sobra, y quien lo mande por WhatsApp escribe lo suyo. El
      archivo sale como `factura-{folio}.pdf`, el mismo nombre de "Descargar PDF".
    - **El XML no sale por aquí**, y la pantalla lo dice bajo el botón en letra chica: "Por aquí va
      el PDF; el XML se manda por correo". No es un detalle técnico de más: es la diferencia entre
      creer que se le mandó el CFDI completo al contador del cliente y habérselo mandado. El
      navegador no admite archivos XML en su menú de compartir, con ningún tipo MIME (ver
      [029](029-pwa-mostrador.md), "El XML no cabe en el menú del aparato").
    - Aparece con la factura **`timbrada` o `cancelada`** —las dos tienen PDF, y una cancelada a
      veces hay que enseñarla—, y no con `pendiente`, que todavía no es comprobante.
    - **Si el navegador no puede compartir archivos, el botón no se muestra.** Quedan "Descargar
      PDF" y "Enviar" por correo, que siempre funcionan. Un botón que promete el catálogo de
      Windows y termina dejando el archivo en la carpeta de descargas es peor que no estar.
    - El PDF **se baja al entrar a la pantalla**, no al apretar el botón, que mientras tanto dice
      "Preparando...": el menú del sistema solo se abre mientras el gesto del usuario sigue vivo, y
      esperar ahí a una descarga lo agota (ver [029](029-pwa-mostrador.md)). Si aun así el menú se
      rechaza, el PDF queda descargado y la pantalla lo dice; **no** se abre WhatsApp, porque aquí
      nadie eligió ese canal.
    - **Compartir no cambia el estado de la factura** ni registra el envío: una timbrada ya está
      timbrada y no hay un "enviada" que mover como en la cotización. El envío por correo, con su
      XML, sigue siendo el otro camino y no lo sustituye.
  - **"Cancelar"** (solo si `timbrada`): abre un modal con selector de motivo de cancelación
    (`c_MotivoCancelacion`, 4 opciones); si el motivo es `01`, aparece además un combobox para
    elegir la factura sustituta entre las propias facturas `timbrada`. Tras confirmar, si
    `estado_cancelacion` queda en `pending`/`verifying`, el detalle lo muestra como "cancelación
    en proceso" (se refresca solo al volver a abrir la pantalla, vía el `GET` que auto-consulta).
  - **"Registrar complemento de pago"** (solo si `metodo_pago = PPD`, `timbrada`, y sin
    complemento existente): abre un modal con fecha de pago, monto (precargado con el total,
    editable) y forma de pago.
- **Plantilla del PDF**: diseño propio construido en esta historia, usando los tokens del design
  system de [003](003-design-system-tailwind.md) (colores, tipografía, logo placeholder),
  generado server-side **al vuelo** únicamente a partir de los datos de la factura ya guardados en
  la base de datos (folio/serie fiscal, UUID, sellos, cliente, líneas, totales) — no depende del
  XML ni de una llamada a facturapi.io, y no se usa el PDF genérico que también genera
  facturapi.io. El XML sí requiere una llamada en vivo a facturapi.io al descargarse (ver
  "Recuperación de XML/PDF" en Backend), ya que no se guarda copia local de ningún documento.
- Enlace a `/facturas` agregado a la navegación del `AppLayout`.

## Fuera de alcance

- Notas de crédito/Egreso, comprobantes de Traslado o Nómina — solo se emite tipo "Ingreso".
- Parcialidades múltiples de pago sobre una misma factura PPD — solo un complemento de pago por
  factura en esta historia.
- Series/folios personalizables o múltiples por el usuario — un folio autoincremental simple por
  usuario, sin series configurables.
- Reenvío automático de correo al timbrar — el envío siempre es una acción manual desde el botón
  "Enviar".
- Roles/permisos diferenciados y multiempresa (mismo patrón que 004/005/006).
- Validación de catálogos/RFC contra el webservice real del SAT (solo contra catálogos locales y
  la respuesta real de facturapi.io al timbrar).
- Webhooks o procesamiento asíncrono del resultado del timbrado (se asume respuesta síncrona con
  timeout).

## Estado de implementación

Implementada el 2026-07-31. **El compartir del PDF por el menú del sistema** —el botón del detalle y
el del listado— se definió e implementó el 2026-08-19:

- **Archivos tocados**: `frontend/src/lib/compartir.ts` (el texto de acompañamiento pasa a ser
  opcional, y sin él el respaldo es solo descargar), `frontend/src/stores/facturas.ts`
  (`archivoParaWhatsapp` renombrado a `archivoPdf`, que ahora sirve a las dos salidas, y el nuevo
  `compartirPdf`), `frontend/src/views/FacturaDetalleView.vue`,
  `frontend/src/views/FacturasListView.vue` y `frontend/src/lib/compartir.test.ts` (nuevo). Los dos
  llamados del mostrador siguieron el renombre, sin cambio de comportamiento.
- **Sin cambios en el backend**: el PDF sale del mismo `GET /api/v1/facturas/{id}/pdf`.
- **Verificado** con `npm test` (92 pruebas, incluidas las tres nuevas del compartir),
  `npm run build` y `npm run lint`. El catálogo de envío de Windows 11 en sí no se puede probar
  automáticamente: lo que se prueba es lo que el sistema le entrega y lo que hace con su respuesta.
- **Paquetes instalados**: `facturapi/facturapi-php` (SDK oficial) y `barryvdh/laravel-dompdf`
  vía Composer. `FACTURAPI_ENV`/`FACTURAPI_TEST_KEY`/`FACTURAPI_LIVE_KEY` agregados a `.env` y
  `.env.example`; `FACTURAPI_TEST_KEY` se configuró con una clave real de
  `sandbox.facturapi.io` proporcionada durante la implementación (no se commitea a
  `.env.example`, que queda vacío).
- **Catálogos SAT ampliados**: `catalogos-sat:actualizar` ahora reconstruye 6 tablas (antes 4),
  agregando `cfdi_40_usos_cfdi` (24 entradas) y `cfdi_40_formas_pago` (22 entradas). Se ejecutó
  manualmente para regenerar `storage/app/sat-catalogos.sqlite` (ahora ~13 MB).
- **Payload y respuesta de facturapi.io verificados con llamadas reales** (modo test, vía
  `php artisan tinker`, con limpieza de datos por rollback de transacción): se creó, canceló y
  descargó el XML de una factura real de prueba, y se timbró un complemento de pago real. Esto
  reveló varias diferencias con lo que documentaba la referencia pública y obligó a corregir el
  código:
  - Los campos reales dentro de `stamp{}` son `signature` (Sello CFDI, no `cfdi_sign`),
    `sat_signature` (Sello SAT, no `sat_sign`), `sat_cert_number` (correcto), `date` (correcto).
    No existe `stamp.version`; la versión del comprobante viene en `cfdi_version` a nivel raíz
    de la respuesta (se corrigió `version_comprobante` para leerlo de ahí).
  - `use`, `payment_form`, `payment_method` y `discount` (por línea) en el payload de creación sí
    son los nombres correctos — confirmados tal cual estaban implementados.
  - La cancelación (`DELETE /v2/invoices/{id}?motive=...`) responde con `cancellation_status`
    (no `estado_cancelacion` como se había asumido); se corrigió `FacturaController::cancelar()`
    y `refrescarEstadoCancelacion()`. Antes de cualquier cancelación, `cancellation_status` vale
    `"none"` (no contemplado originalmente) — se agregó ese caso al enum `EstadoCancelacion`.
  - El payload del complemento de pago es distinto al de la documentación pública: es
    `complements: [{ type: 'pago', data: { date, payment_form, related_documents: [{ uuid,
    installment, last_balance, amount, taxes: [{ type, rate, factor, base }] }] } }]` (no
    `complements[].payments[]` con `payment_amount`/`new_balance` como se había asumido). El
    campo `taxes.0.base` es obligatorio (el monto gravable antes de IVA); por simplicidad se
    calcula a partir del `subtotal` de la factura completa, sin prorratear entre tasas mixtas si
    el monto pagado difiere del total o hay líneas con tasas distintas — limitación conocida.
  - `GET /v2/invoices/{id}/xml` funciona tal como se documentó (confirmado descargando un XML
    real de 4.5 KB).
  - Se descubrió que `stamp.complement_string` **sí** existe y tiene el formato de una cadena
    original del SAT (`||1.1|uuid|fecha|sello|no_certificado||`) — contradice el supuesto #32
    ("esa respuesta no la incluye"). **(Aprobado y agregado)**: se agregó la columna
    `cadena_original_sat` (`text`, nullable) a `facturas` y a `complementos_pago`
    (migración `2026_07_31_170000_...`), mapeada desde `stamp.complement_string` tanto en
    `FacturaController::intentarTimbrar()` como en `complementoPago()`. Se expone en
    `FacturaResource`/`ComplementoPagoResource`, se muestra en el detalle del frontend y en la
    plantilla del PDF junto a los sellos.
- **`FacturaTotalesCalculator` — bug de backend corregido el 2026-07-31**: la primera versión
  implementada calculaba el IVA de cada línea sobre el importe **antes** del descuento global, y
  `FacturapiService` nunca enviaba el descuento global a facturapi.io en absoluto (CFDI real
  timbrado sin el descuento) — reportado por el usuario al comparar una factura con descuento
  global de $150 contra el PDF real generado por facturapi.io. Corregido en backend según el
  diseño de "Cálculo de totales e IVA con descuento global" (prorrateo entre líneas antes de
  calcular IVA, envío de la porción prorrateada dentro del `discount` de cada ítem), y verificado
  en vivo contra el sandbox (ver más abajo).
- **Bug de frontend corregido el 2026-07-31**: al corregir el backend, el cálculo espejo del
  navegador (`FacturaFormView.vue`, desglose de totales en tiempo real) no se había actualizado
  — seguía calculando el IVA sobre el importe bruto de cada línea, sin restar la porción
  prorrateada del descuento global, lo que hacía que **toda factura con descuento global fuera
  rechazada con error 422** al intentar timbrarse (el `total` del frontend ya no coincidía con el
  recalculado por el backend). Se agregó `prorrateoDescuentoGlobal` (computed) en
  `FacturaFormView.vue`, replicando línea por línea el mismo algoritmo de
  `FacturaTotalesCalculator::prorratear()` (proporcional al importe neto de cada línea, última
  línea absorbe el residuo de redondeo), y `ivaLinea()` ahora calcula el IVA sobre el importe ya
  neto de esa porción. Verificado manualmente con los números del reporte original (subtotal
  $450, descuento $100 monto fijo): antes mostraba IVA $72.00 / total $422.00 (rechazado por el
  backend); ahora calcula IVA $56.00 / total $406.00, coincidiendo con lo que valida y timbra el
  backend. `vue-tsc`, ESLint, Prettier y `vite build` corren limpios.
- **Folio interno**: `Factura::folio` se calcula con `max('folio') + 1` por usuario dentro de una
  transacción de base de datos al crear; no tiene un lock explícito adicional, así que dos
  solicitudes concurrentes del mismo usuario en un margen muy estrecho podrían, en teoría, competir
  por el mismo folio — no se contempló en el diseño original y queda como riesgo menor conocido.
- **PDF generado con `dompdf`**: la plantilla (`resources/views/pdf/factura.blade.php`) usa los
  colores de la paleta de 003 (`#4F46E5`/`#7C3AED`) pero fuentes del sistema (Helvetica/DejaVu
  Sans) en vez de Roboto/Open Sans reales, porque dompdf requiere registrar los archivos de fuente
  localmente para usar tipografías web — se simplificó para esta historia; usar las fuentes reales
  queda como mejora pendiente.
- **Envío de correo**: `FacturaEnviadaMail` usa el sistema de mailables Markdown de Laravel
  (`x-mail::message`) con el XML y el PDF adjuntos, vía Mailpit en desarrollo (mismo patrón que
  002).
- **Verificación end-to-end**: la suite Pest (8 tests nuevos del módulo Facturación, 67 en total)
  pasa contra los catálogos SAT reales, con `FacturapiService` mockeado (Mockery, sin llamadas de
  red reales) para no depender de la disponibilidad del sandbox en cada corrida. Se corrieron
  `vue-tsc`, ESLint y Prettier limpios, y `vite build` compila la SPA completa sin errores. Pint
  no reportó cambios de estilo pendientes. Las migraciones se corrieron con éxito contra MySQL
  real (Laragon). Además, se ejecutó una prueba real end-to-end contra el sandbox de
  facturapi.io usando el código de la app tal cual (`FacturapiService`, `FacturaTotalesCalculator`,
  modelos reales, dentro de una transacción revertida al final para no dejar datos de prueba en
  la base): crear factura → timbrar (UUID/sellos reales guardados correctamente) → descargar XML
  en vivo (4,562 bytes) → generar PDF al vuelo con la plantilla propia (`dompdf`, 4,849 bytes) —
  las cuatro operaciones fueron exitosas. **Corrección sobre esa prueba**: en ese momento solo se
  verificó que el timbrado no fallara y que el `total` guardado en la base de datos coincidiera
  con lo calculado localmente — **no** se comparó contra el `total` que confirmó facturapi.io en
  su respuesta. Al repetir esa comparación explícitamente (ver bug de `tax_included` documentado
  arriba en el modelo `Factura`), se detectó que el total real timbrado no coincidía. Corregido
  (`tax_included: false` explícito) y re-verificado en vivo dos veces: (1) factura de 2 líneas
  ($700+$300) con descuento global de $500 — total local $580.00, total real confirmado por
  facturapi.io **$580.00**, coincide exacto; (2) factura simple sin descuento (1 línea de $100,
  IVA 16%) — total local $116.00, total real confirmado por facturapi.io **$116.00**, coincide
  exacto. Ambas pruebas se hicieron con el código real de la app (`FacturaTotalesCalculator` +
  `FacturapiService`), dentro de transacciones revertidas al final para no dejar datos de prueba
  en la base. Se agregaron 4 tests unitarios nuevos (`tests/Unit/FacturaTotalesCalculatorTest.php`,
  71 tests en total del proyecto) que cubren el prorrateo del descuento global (incluyendo el
  caso exacto reportado: subtotal $1000, descuento $500, IVA $80, total $580) y el manejo de
  residuo de redondeo entre líneas. **No se pudo verificar visualmente la UI en un navegador
  real** (misma limitación de entorno Windows que en
  004/005/006) — se recomienda abrir `/facturas` manualmente para confirmar el formulario, la
  tabla de líneas, el detalle y los modales de cancelación/complemento de pago antes de dar la
  funcionalidad por completamente probada visualmente.

## Criterios de aceptación

1. Un usuario autenticado puede crear una factura seleccionando un cliente y una o varias líneas
   de artículo (cantidad, precio unitario editable, descuento opcional, tasa de IVA), viendo los
   totales desglosados (subtotal, descuentos, IVA por tasa, total) en tiempo real antes de enviar.
2. Al presionar "Generar y timbrar" con datos completos y válidos, la factura se envía a la API
   de facturapi.io, se timbra, y los sellos/UUID se guardan en la base de datos (sin persistir
   ningún archivo XML/PDF localmente); el usuario es redirigido al detalle de la factura ya
   timbrada.
3. Si los datos están incompletos o son erróneos y facturapi.io responde con un mensaje de error,
   ese mensaje se muestra en pantalla, la factura queda guardada en estado `pendiente` (visible
   en el listado) sin perder los datos capturados, y puede reintentarse.
4. Si el total recalculado por el backend no coincide con el enviado por el frontend, la petición
   se rechaza con un error de validación.
5. Una factura en estado `timbrada` o `cancelada` no puede editarse ni eliminarse.
6. El listado `/facturas` muestra las facturas del usuario autenticado, paginadas, filtrables por
   estado, con búsqueda por cliente/folio/UUID.
7. Una factura `timbrada` puede cancelarse capturando un motivo; si el motivo es "01", requiere
   seleccionar una factura sustituta propia y timbrada; tras cancelar, su estado pasa a
   `cancelada` cuando facturapi.io confirma `estado_cancelacion = accepted` (de inmediato o al
   reabrir el detalle, si quedó `pending`/`verifying`).
8. Desde el detalle de una factura timbrada se puede descargar el XML (consultado en vivo a
   facturapi.io) y el PDF (generado al vuelo con la plantilla propia del sistema); ninguno de los
   dos se guarda como archivo físico en el sistema.
9. Desde el detalle de una factura timbrada se puede enviar por correo (XML + PDF adjuntos) a uno
   o varios destinatarios, con el correo del cliente prellenado por defecto.
10. Una factura `timbrada` con `metodo_pago = PPD` permite registrar un complemento de pago
    (fecha, monto editable, forma de pago), que se timbra por separado; no se permite un segundo
    complemento sobre la misma factura.
11. Al aplicar un descuento global, el `total` mostrado por el sistema, el IVA desglosado
    (`total_iva_16`) y el total que efectivamente queda timbrado en el CFDI real (verificable
    contra la respuesta de facturapi.io o el PDF/XML descargado) **coinciden exactamente** — el
    descuento global se refleja en el documento fiscal, no solo en la pantalla.
12. En cualquier factura con IVA (tenga o no descuento global), el `total` que timbra facturapi.io
    resulta de **sumar** el IVA sobre el precio (menos descuentos), no de extraerlo del precio —
    verificable comparando el `total` de la respuesta de facturapi.io contra el `total` calculado
    por el sistema.
13. Desde el detalle de una factura `timbrada` o `cancelada`, y desde su renglón en el listado
    `/facturas`, se comparte **el PDF y solo el PDF** por el menú de compartir del sistema —en
    Windows 11, el catálogo de envío con Drive, WhatsApp y los demás destinos instalados—, sin
    texto de acompañamiento y sin que el estado de la factura cambie. Cerrar el menú sin elegir
    destino no muestra error.
14. En un navegador que no puede compartir archivos, el botón de compartir no aparece —ni en el
    detalle ni en el listado—, y el PDF sigue disponible por "Descargar PDF" y por correo.
15. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. "Factura" es una entidad propia del usuario dueño de la cuenta (mono-usuario, sin multiempresa
   ni facturas compartidas), consistente con 004/005/006.
2. Una factura se compone de un cliente (del catálogo de 004) y una o más líneas de artículo (del
   catálogo de 006), cada una con cantidad capturable.
3. **(Redefinido)** El precio unitario de cada línea se precarga del artículo pero es **editable**
   por el usuario al facturar; el descuento se captura en un campo aparte, sin pisar el precio
   base.
4. **(Redefinido, corregido)** El descuento por línea se expresa como porcentaje o monto fijo, a
   elección del usuario; adicionalmente existe un descuento global a nivel factura (mismo esquema
   porcentaje/monto), aplicado sobre el subtotal ya con los descuentos de línea. Como el CFDI no
   tiene un nodo de descuento a nivel factura, el descuento global **se prorratea entre las
   líneas** (proporcional a su importe) antes de calcular el IVA de cada una y antes de enviarlo a
   facturapi.io como parte del `discount` de cada ítem — ver "Cálculo de totales e IVA con
   descuento global" en el modelo `Factura`. Sin este prorrateo, el descuento global no se
   reflejaba en el CFDI real (bug detectado el 2026-07-31).
5. **(Redefinido)** La tasa de IVA es seleccionable por línea entre 16%, 0% y exento (en vez de
   asumir siempre 16% general como en 006).
6. Los totales (subtotal, descuentos, IVA por tasa, total) se calculan y muestran en tiempo real
   en el frontend conforme se editan las líneas, pero el backend siempre los recalcula de forma
   independiente al persistir.
7. El Uso de CFDI (`c_UsoCFDI`), la Forma de pago (`c_FormaPago`) y el Método de pago (`PUE`/`PPD`)
   se capturan por factura (no en el cliente, tal como quedó documentado en 004).
8. **(Confirmado)** El tipo de comprobante es siempre "Ingreso"; no se contemplan notas de
   crédito/Egreso, Traslado ni Nómina en esta historia.
9. La moneda es siempre MXN, sin tipo de cambio ni facturación en otra divisa.
10. **(Redefinido)** No existe un guardado manual de "borrador" antes de timbrar: al presionar el
    botón se intenta timbrar de inmediato. Si el timbrado falla (datos, PAC o timeout), la
    factura se guarda automáticamente en estado `pendiente` (en vez de no persistir nada), para
    poder reintentar sin recapturar todo.
11. **(Redefinido)** Si facturapi.io responde éxito, se persisten los sellos fiscales y el UUID;
    no se descarga ni se persiste el XML en ese momento (ver supuesto #40).
12. **(Redefinido)** Una factura `timbrada` o `cancelada` es inmutable a nivel backend (`422` si
    se intenta editar/eliminar); solo es editable o eliminable mientras está en
    `borrador`/`pendiente`.
13. Existe una pantalla de listado de facturas (folio/UUID, cliente, total, estado, fecha) para el
    usuario autenticado, con búsqueda y filtro por estado.
14. **(Redefinido)** La cancelación de CFDI ante el PAC/SAT sí se incluye en esta historia, con
    motivo del catálogo `c_MotivoCancelacion` y folio sustituto (elegido entre facturas propias
    timbradas) cuando el motivo lo exige (`01`).
15. **(Redefinido)** La descarga de la factura timbrada sí se incluye en esta historia, pero **no
    se guarda ningún PDF ni XML físico en el sistema** (ver supuestos #40–43): el PDF se genera al
    vuelo con una plantilla propia (ver supuesto #17); el XML se consulta en vivo a facturapi.io en
    cada descarga.
16. **(Redefinido)** Se incluye un complemento de pago básico para facturas con `metodo_pago =
    PPD`: un único complemento por factura, con monto editable (sin manejo de parcialidades
    múltiples ni de saldos pendientes).
17. **(Adición)** La plantilla del PDF de la factura es propia, construida en esta misma historia
    con el design system de [003](003-design-system-tailwind.md) (colores, tipografía, logo
    placeholder), en vez de usar el PDF genérico que también genera facturapi.io.
18. **(Adición)** Al timbrar exitosamente, se abre la pantalla de detalle de la factura (con su
    representación ya timbrada), desde donde se puede enviar por correo: modal con el correo del
    cliente prellenado (editable) y soporte para múltiples destinatarios; el envío adjunta XML y
    PDF, vía Mailpit en desarrollo (mismo patrón que [002](002-login-auth.md)). No hay envío
    automático al timbrar, siempre es una acción manual desde el botón "Enviar".
19. No hay roles ni permisos diferenciados todavía (cualquier usuario autenticado gestiona solo
    sus propias facturas), consistente con las specs anteriores.
20. **(Adición técnica)** Estados de `Factura`: `borrador` → `pendiente` → `timbrada` |
    `cancelada`, como máquina de estados explícita en el modelo.
21. **(Adición técnica)** `c_UsoCFDI`, `c_FormaPago` y `c_MotivoCancelacion` se agregan como
    catálogos SAT reales (ampliando la base SQLite de 004/006); `metodo_pago` (`PUE`/`PPD`) es un
    enum fijo de backend, sin catálogo externo (mismo patrón que `objeto_imp` en 006).
22. **(Adición técnica)** La integración con facturapi.io usa el SDK oficial de PHP
    (`facturapi/facturapi-php`) envuelto en un service class dedicado, con credenciales test/live
    configurables por variable de entorno (`FACTURAPI_ENV`, `FACTURAPI_TEST_KEY`/
    `FACTURAPI_LIVE_KEY`, mismo dominio base para ambas), y llamada síncrona con timeout explícito
    (ej. 30s); un timeout se trata igual que un error de timbrado.
23. **(Adición técnica)** El reintento de timbrado de una factura `pendiente` permite editar los
    datos antes de reintentar solo si el error previo fue de validación de datos; si fue error del
    PAC/timeout, solo se reenvían los mismos datos.
24. **(Adición técnica)** El backend recalcula siempre los totales a partir de las líneas
    recibidas y valida que coincidan con los que envía el frontend, respondiendo `422` si no
    coinciden (en vez de solo ignorar el total del frontend en silencio).
25. **(Adición técnica)** Las credenciales reales de facturapi.io (test/live) no forman parte de
    esta especificación; se proporcionan al momento de implementar y se configuran directamente en
    `.env` (nunca committeadas al repositorio).
26. No se persiste un "folio" ni "serie" configurable por el usuario más allá de un folio
    autoincremental simple por usuario (una sola serie implícita, no expuesta en el frontend).
27. La validación de los catálogos SAT (`c_UsoCFDI`, `c_FormaPago`, `c_MotivoCancelacion`) es solo
    contra el catálogo local descargado, no contra el webservice real del SAT — la validación
    fiscal real ocurre en la respuesta de facturapi.io al momento de timbrar.
28. **(Redefinido)** La `cantidad` de cada línea es un entero con mínimo 1 (no decimal, no
    permite 0 ni negativos).
29. **(Adición)** Cada línea de factura tiene sus propios campos `descripcion` y `modelo`,
    precargados de `Articulo.nombre`/`Articulo.modelo` al agregarla pero **editables in-place**
    por línea — copias propias desacopladas del catálogo, igual que ya ocurre con
    `precio_unitario` (editar la línea no modifica el `Articulo` original).
30. **(Adición)** La tabla de líneas de factura muestra siempre 7 columnas visibles en la misma
    fila: cantidad, descripción, modelo, precio unitario, descuento, tasa de IVA y total — sin
    mover descuento/tasa IVA a un detalle o modal aparte.
31. **(Aclarado)** La columna "Total" de cada fila es el importe neto de la línea (cantidad ×
    precio unitario − descuento), **sin IVA incluido**; el IVA por línea se desglosa únicamente
    en los totales generales de la factura, no en la columna "Total" de la fila.
32. **(Redefinido, verificado con una llamada real)** Los campos de sellos se mapean de la
    respuesta de éxito del PAC (`uuid`, `folio_number`, `series`, `status`, objeto `stamp{...}`).
    A diferencia de lo asumido originalmente, sí se guarda la "cadena original del SAT": viene en
    `stamp.complement_string` y se persiste en `cadena_original_sat` (tanto en `Factura` como en
    `ComplementoPago`).
33. **(Adición)** Se agrega `facturapi_invoice_id` (campo `id` de la respuesta), el identificador
    propio de facturapi.io para la factura, usado en las llamadas posteriores a su API
    (cancelación, descarga de XML/PDF, consulta de estado) — el `uuid_fiscal` del SAT no sirve
    para eso.
34. **(Adición)** Se guardan **ambos** identificadores de folio: el `folio` interno autoincremental
    por usuario (existe desde que se crea la factura, incluso sin timbrar) y `facturapi_serie`/
    `facturapi_folio` (la serie/folio fiscal real que asigna facturapi.io al timbrar, nulos hasta
    entonces). El detalle/PDF de una factura ya timbrada muestra la serie/folio fiscal de
    facturapi.io, no el folio interno.
35. **(Adición)** El cliente y los artículos se envían completos **inline** en cada solicitud de
    factura a facturapi.io, sin necesitar pre-registrarlos ni sincronizar un identificador de
    antemano — no se agrega ningún campo de "UID en el PAC" al modelo `Cliente` (004).
36. **(Adición)** Autenticación de facturapi.io vía Bearer token con dos tipos de API key
    (`sk_test_...`/`sk_live_...`) sobre el mismo dominio base; solo cambia la key usada según el
    ambiente.
37. **(Adición)** Se crea la factura en modo de timbrado inmediato (comportamiento por defecto de
    facturapi.io); no se usa su modo nativo `status: draft` (crear borrador + timbrar en un paso
    separado) en esta historia, para mantener el flujo síncrono de un solo paso ya definido en el
    supuesto #10.
38. **(Adición, verificado con una llamada real)** La cancelación ante facturapi.io puede no ser
    inmediata: se agrega el campo `estado_cancelacion` (`none`/`pending`/`verifying`/`accepted`)
    en `Factura`, separado del `estado` general; este último solo pasa a `cancelada` cuando
    `estado_cancelacion = accepted`. El valor viene del campo real `cancellation_status` de la
    respuesta de facturapi.io (no `estado_cancelacion`, que era el nombre asumido antes de
    verificar). Si queda en un estado intermedio, se refresca automáticamente la próxima vez que
    se abre el detalle de esa factura (`GET /api/v1/facturas/{id}` re-consulta a facturapi.io
    antes de responder), sin necesidad de un botón manual ni de un proceso de polling en segundo
    plano.
39. **(Adición técnica)** El service class de facturapi.io usa el SDK oficial de PHP
    (`facturapi/facturapi-php`, Composer, PHP 8.2+) en vez de llamadas HTTP armadas a mano.
40. **(Adición)** No se guarda ningún PDF ni XML físico en el sistema en ningún momento del flujo
    (ni al timbrar, ni al cancelar, ni al descargar) — ni para `Factura` ni para `ComplementoPago`.
    No existe campo `xml_path` (ni equivalente de PDF) en ninguno de los dos modelos.
41. **(Adición)** El PDF de una factura se genera **al vuelo** en cada solicitud, usando
    exclusivamente los campos ya guardados en `Factura`/`FacturaLinea` (folio/serie fiscal, UUID,
    sellos, cliente, líneas, totales) — no depende del XML ni requiere llamar a facturapi.io en
    ese momento.
42. **(Adición)** El XML de una factura no se cachea ni se guarda: cada descarga hace una llamada
    en vivo a `GET /v2/invoices/{facturapi_invoice_id}/xml` de facturapi.io y sirve el resultado
    directo al usuario.
43. **(Adición)** Si la llamada en vivo a facturapi.io para obtener el XML falla (PAC caído,
    timeout), se responde un error simple de descarga sin reintento automático; el usuario puede
    volver a intentarlo manualmente.
44. **(Adición)** El criterio de "no se guarda XML/PDF localmente" aplica igual al
    `ComplementoPago` (también es un CFDI propio en facturapi.io); esta historia no expone,
    sin embargo, un botón de descarga de XML/PDF para el complemento de pago en el frontend.

45. **(Adición)** Compartir el PDF por el menú del sistema entra también en el escritorio, con el
    mismo `compartirArchivo` de `lib/compartir.ts` que ya usan el mostrador y las cotizaciones. No
    se escribe un compartir aparte para escritorio: serían dos códigos casi iguales que corregir
    dos veces.
46. **(Adición)** `compartirArchivo` pasa a recibir el texto de acompañamiento como **opcional**.
    Sin texto comparte solo el archivo y, si el menú del sistema no está o rechaza la llamada, el
    respaldo es descargar el archivo y avisarlo — no abrir WhatsApp, que solo tiene sentido cuando
    hay un mensaje que llevar. El mostrador y las cotizaciones siguen mandando su texto y su
    respaldo por WhatsApp, sin cambio alguno.
47. **(Adición)** El botón de compartir se muestra u oculta según `puedeCompartirArchivos()`, no
    según el ancho de la pantalla ni el sistema operativo detectado: lo que decide es si este
    navegador tiene el menú, y esa es la única pregunta que responde bien.
48. **(Adición)** En el listado, la descarga anticipada del PDF se dispara con `mouseenter` y con
    el foco de teclado sobre el botón, una sola vez por factura, y el resultado queda en memoria
    mientras dure la página. Un segundo clic sobre la misma factura no vuelve a pedirle el PDF al
    servidor.
49. **(Adición)** Las pruebas del compartir (Vitest, con `navigator.share`/`canShare` simulados)
    cubren tres casos: que salga el PDF y nada más —sin texto—, que cerrar el menú del sistema no
    deje error ni descargue nada, y que sin menú el respaldo sea solo la descarga, sin abrir
    WhatsApp. El tercero prueba `puedeCompartirArchivos()`, que es la puerta que el botón consulta
    para mostrarse: el alcance de Vitest en este proyecto es de módulos, sin jsdom ni componentes
    montados (ver [011](011-precio-proveedor-utilidad.md)).
50. **(Adición)** El backend no cambia: compartir usa el mismo `GET /api/v1/facturas/{id}/pdf` que
    ya alimenta a "Descargar PDF", con la sesión del propio usuario.
