# Spec: Convertir cotización en factura y duplicar con cambio de cliente

**Modifica:** [011-cotizaciones.md](011-cotizaciones.md) (duplicar, reglas de edición y caducidad),
[012-facturacion.md](012-facturacion.md) (formulario de alta, detalle y listado) y
[014-cotizaciones-bandeja.md](014-cotizaciones-bandeja.md) (dos etiquetas nuevas).

**Relación con [remotas/043](remotas/043-facturas-parciales-cotizacion.md):** aquí una cotización
tiene **una sola factura vigente, por el total**. El vínculo se guarda del lado de la factura
(`facturas.cotizacion_id`), igual que en 043, para que las facturas parciales lleguen después sin
voltear la relación ni migrar datos.

## Historia de usuario

Como usuario quiero convertir una cotización en factura, ya sea desde la cotización o jalándola desde
facturación, y quiero duplicar cotizaciones y facturas eligiendo el cliente, para no volver a capturar
las mismas líneas.

- **Proceso 1:** creo una cotización y, desde ella, genero la factura. La cotización queda como
  "Facturada".
- **Proceso 2:** abro facturación, jalo una cotización y se crea la factura a partir de ella.
- **Proceso 3:** duplico una cotización → elijo un cliente → se genera.
- **Proceso 4:** duplico una factura → elijo un cliente → se genera.

## Objetivo / Alcance

- Una cotización Enviada, Pagada o Entregada se convierte en factura. La conversión abre el formulario
  de alta de factura **ya lleno** con los datos de la cotización. La factura se guarda y se timbra con
  el botón de siempre, "Generar y timbrar" (012 no tiene guardado de borrador, y esto no cambia).
- "Facturada" **no es un estado** de la cotización. Es una marca que se calcula a partir de la factura
  vigente y convive con Enviada, Pagada o Entregada.
- "Duplicar" de cotizaciones pregunta ahora el cliente. Las facturas ganan su propio "Duplicar".
- Cada copia recuerda de qué documento salió ("Duplicada de…").
- La bandeja de cotizaciones gana las etiquetas **Facturadas** y **Por facturar**.

**No** incluye facturas parciales ni más de una factura vigente por cotización (ver "Fuera de
alcance").

## Backend (Laravel)

### Migración `..._vinculos_cotizacion_factura_y_duplicados.php`

| Tabla | Columna | Definición |
|---|---|---|
| `facturas` | `cotizacion_id` | `foreignId` nullable → `cotizaciones`, `nullOnDelete`, índice |
| `facturas` | `duplicada_de_id` | `foreignId` nullable → `facturas`, `nullOnDelete` |
| `cotizaciones` | `duplicada_de_id` | `foreignId` nullable → `cotizaciones`, `nullOnDelete` |

- `cotizacion_id` **no es única**: una cotización puede tener facturas canceladas y, además, una
  vigente.
- No hay datos que rescatar: hoy ninguna factura sale de una cotización.
- Ninguna de las tres columnas es asignable desde un formulario. Las escribe el controlador después
  de validar.

### Modelo `Cotizacion`

- `facturas(): HasMany` y `facturaVigente(): HasOne`, que es la factura con `estado ≠ cancelada`.
  Una factura `borrador` o `pendiente` también es vigente: la cotización queda facturada desde que
  se crea la factura (asunción 9).
- `duplicadaDe(): BelongsTo` a `Cotizacion`.
- **Métodos de regla** (nadie reimplementa la condición):
  - `estaFacturada()` → existe `facturaVigente`.
  - `esFacturable()` → estado `enviada`, `pagada` o `producto_entregado` y no `estaFacturada()`.
  - `lineasNoFacturables(): Collection` → las líneas libres (`articulo_id` nulo) y las de artículos
    eliminados. Para facturar tiene que salir vacía.
  - `motivoNoFacturable(): ?string` → `null` si se puede facturar; si no, el primer motivo:
    "Una cotización en borrador no se puede facturar", "Ya tiene la factura FAC-0012" o "Tiene líneas
    que no vienen del catálogo: 2, 5. Corrígelas para poder facturar."
- **Cambian** (asunción 11):
  - `esEditable()` → además exige `! estaFacturada()`, y lo mismo `puedeEliminarse()`.
  - Los scopes `porCaducar()` y `vencidas()` excluyen las facturadas (`doesntHave('facturaVigente')`).
    Una cotización Enviada y facturada **no caduca**. Si no se excluyera, el comando de caducidad la
    borraría y el vínculo se perdería.
- **Scopes nuevos** de la bandeja:
  - `facturadas()` → `has('facturaVigente')`.
  - `porFacturar()` → estado en `enviada`, `pagada` o `producto_entregado` y
    `doesntHave('facturaVigente')`. Incluye las que tienen líneas libres, para que se vean y se
    corrijan.
- Constantes `FACTURADAS = 'facturadas'` y `POR_FACTURAR = 'por_facturar'`: valores del filtro de
  estado que no son estados, igual que `POR_CADUCAR`.

### Modelo `Factura`

- `cotizacion(): BelongsTo` y `duplicadaDe(): BelongsTo` a `Factura`.
- Al eliminarse (solo `borrador` o `pendiente`) o al pasar a `cancelada`, la cotización deja de estar
  facturada sin hacer nada más: `facturaVigente` ya no la encuentra (asunción 10).

### Rutas (web)

Nuevas, antes de los `Route::resource`:

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/facturas/cotizaciones` | cotizaciones facturables (página sin JS, o fragmento con `?fragmento=1`) | `facturas.cotizaciones` |

Cambian de parámetros:

| Ruta | Parámetros nuevos |
|---|---|
| `GET /facturas/crear` (`facturas.create`) | `?cotizacion={id}` (convertir) o `?duplicar={id}&cliente_id={id}` (duplicar factura) |
| `POST /facturas` (`facturas.store`) | campos ocultos `cotizacion_id` o `duplicada_de_id` |
| `POST /cotizaciones/{cotizacion}/duplicar` (`cotizaciones.duplicar`) | `cliente_id` |

### Controladores

- **`FacturaController::create`**:
  - Con `?cotizacion=`: la cotización tiene que ser del usuario (si es ajena o no existe → 404). Si
    `motivoNoFacturable()` no es `null`, regresa al detalle de la cotización con ese motivo como error.
    Si sí se puede, llena el formulario:
    - `cliente_id`, descuento global y líneas (artículo, cantidad, descripción, modelo, precio,
      descuento y tasa de IVA) **tal como están en la cotización**;
    - uso de CFDI, método y forma de pago con los valores por defecto del formulario (Gastos en
      general, PUE, forma sin elegir). Los clientes no guardan preferencias fiscales;
    - campo oculto `cotizacion_id`;
    - **aviso de precios** (adición 4): las líneas cuyo `precio_unitario` es distinto del
      `precio_unitario_sin_iva` actual del artículo, por ejemplo: "Tornillo M8: $100.00 en la
      cotización, $120.00 hoy en el catálogo". Solo avisa: la línea conserva el precio cotizado.
  - Con `?duplicar=&cliente_id=`: la factura original tiene que ser del usuario, en cualquier estado
    (si no → 404). El formulario se llena con:
    - el cliente elegido (si es ajeno o eliminado, queda sin elegir),
    - uso de CFDI, método y forma de pago, descuento global y líneas de la original,
    - campo oculto `duplicada_de_id`.

    Las líneas cuyo artículo ya fue eliminado **se omiten**, con el aviso "Se omitieron N líneas
    porque su artículo ya no existe: …" (asunción 28). No se copian el folio, el estado, los sellos,
    las copias fiscales, la cancelación, el complemento ni `cotizacion_id` (asunción 24).
  - `old()` gana sobre la precarga, como hoy: un error de validación no vuelve a cargar la cotización.
- **`FacturaController::store`**, dentro de la transacción que ya existe:
  - Con `cotizacion_id` (adición 1, primera parte): se bloquea la fila de la cotización con
    `lockForUpdate()` y se vuelve a revisar `esFacturable()`. Si otra petición ya creó la factura, no
    se crea otra: se hace rollback y se redirige a la factura existente con el aviso "Esta cotización
    ya se facturó en FAC-0012". Cualquier otro motivo regresa al formulario con el error. Si todo
    está bien, se guarda `cotizacion_id`.
  - Con `duplicada_de_id`: se guarda tal cual (la validación ya revisó que la factura sea del usuario).
  - Después del commit se timbra igual que hoy. Si el timbrado falla, la factura queda `pendiente` y
    la cotización sigue facturada hasta que esa factura se elimine o se cancele.
  - Las líneas se validan con las reglas de siempre: el usuario puede cambiar cliente, líneas y montos
    antes de timbrar (asunción 7). El vínculo con la cotización se conserva aunque los totales ya no
    coincidan.
- **`FacturaController::cotizaciones`**: las cotizaciones del usuario con `porFacturar()` y **sin**
  líneas no facturables (asunción 16), con el filtro `q` de la bandeja (folio, cliente o RFC), las 20
  más recientes. Con `?fragmento=1` devuelve solo `facturas/_cotizaciones-facturables`; sin él, la
  página completa, para que funcione sin JavaScript.
- **`CotizacionController::duplicar`**: valida con `DuplicarCotizacionRequest`. Copia como hoy (líneas
  con su costo original, descuento global y totales; folio nuevo, `borrador`, sin pagos) y además
  cambia `cliente_id` por el elegido y guarda `duplicada_de_id`. Se puede duplicar en cualquier
  estado, incluso facturada (asunción 20). La copia nace sin factura. Mensaje: "Se creó COT-0031 como
  copia de COT-0012 para {razón social}."
- **`CotizacionController::show`** y la vista previa de la bandeja cargan `facturaVigente` y
  `duplicadaDe`.

### Validaciones

- **`DuplicarCotizacionRequest`** (nuevo, bolsa de errores `duplicar`): `cliente_id` requerido,
  cliente del usuario sin eliminar. Mensaje: "Elige el cliente de la copia". Duplicar una factura es
  un GET al formulario y no usa Form Request: un cliente ajeno o eliminado solo queda sin elegir.
- **`FacturaRequest`** gana dos campos:
  - `cotizacion_id`: nullable, cotización del usuario (`Rule::exists` con `user_id`). La regla de
    "se puede facturar" la revisa `store` dentro del bloqueo, no aquí, para no tener dos respuestas
    distintas en una carrera.
  - `duplicada_de_id`: nullable, factura del usuario. Prohibido si viene `cotizacion_id`
    (`prohibits`).
  - En `update` los dos se ignoran: el origen se fija al crear y no cambia.

### Autorización

- `CotizacionPolicy::update` y `delete` ya dependen de `esEditable()` y `puedeEliminarse()`, así que
  heredan la regla de facturada. Motivo nuevo (403): "Una cotización facturada no se puede modificar".
- Convertir usa `view` sobre la cotización. Duplicar usa `operar`, que ya existe en las dos
  Policies: una cotización o factura ajena responde 404.

## Vistas (Blade)

### Cotización: detalle (`cotizaciones/show.blade.php`)

- Junto a la etiqueta de estado, si `estaFacturada()`: la etiqueta **"Facturada"**
  (`etiqueta-facturada`) con liga a la factura ("FAC-0012", o su folio fiscal si ya se timbró).
- Si `duplicadaDe`: "Duplicada de COT-0012", con liga.
- Botón **"Facturar"** (`bi-receipt`) → `facturas.create?cotizacion={id}`. Solo se muestra si
  `esFacturable()`. Si la cotización se puede facturar por estado pero tiene líneas no facturables,
  el botón no aparece y se muestra un `x-alerta` de advertencia con `motivoNoFacturable()`.
- "Editar" y "Eliminar" desaparecen de una cotización facturada (lo decide la Policy). Pagos,
  entrega, envío, WhatsApp, PDF y "Duplicar" siguen.
- **"Duplicar"** deja de ser un formulario directo: abre `#dialogo-duplicar` (`<dialog>` con el
  manejador de `app.js`), con un `select` de clientes del usuario sin eliminar, el cliente original
  preseleccionado y el botón "Duplicar" (`data-enviar-una-vez`). Si el cliente original está
  eliminado, el `select` queda sin elegir.

### Cotización: bandeja (014)

- `ListadoCotizacionesRequest::etiquetas()` agrega **"Facturadas"** y **"Por facturar"** después de
  "Por caducar". Se combinan con la carpeta de periodo y con la búsqueda, como las demás etiquetas.
- Colores: `etiqueta-facturada` (verde de facturas, `--color-etiqueta-facturas`) y
  `etiqueta-por-facturar` (color informativo).
- La fila (`<x-cotizaciones.fila>`) y la vista previa muestran la etiqueta "Facturada" junto al
  estado. La vista previa **no** gana el botón "Facturar": sigue solo en el detalle (014).

### Factura: listado (`facturas/index.blade.php`)

- Botón **"Desde cotización"** (`bi-file-earmark-arrow-down`) junto a "Nueva factura". Abre
  `#dialogo-cotizaciones`:
  - un buscador ("Folio, cliente o RFC"), que es un formulario GET a `facturas.cotizaciones`;
  - la lista `_cotizaciones-facturables`: folio, cliente, fecha, estado y total. Cada renglón es un
    enlace a `facturas.create?cotizacion={id}`;
  - sin resultados: "No hay cotizaciones por facturar".
- Sin JavaScript, el botón lleva a la página `facturas.cotizaciones`, con la misma lista.

### Factura: formulario (`facturas/_formulario.blade.php`)

- Arriba, según el origen:
  - una nota (`p.ayuda`) "Factura a partir de la cotización COT-0012", con liga, o "Copia de la
    factura FAC-0003";
  - `x-alerta` de advertencia con el aviso de precios, uno por línea;
  - `x-alerta` de advertencia con las líneas omitidas al duplicar.
- Campos ocultos `cotizacion_id` o `duplicada_de_id`. Se reconstruyen desde `old()`.
- El resto del formulario no cambia.

### Factura: detalle (`facturas/show.blade.php`)

- "Origen: COT-0012" con liga si tiene `cotizacion` (asunción 13), y "Duplicada de FAC-0003" con liga
  si tiene `duplicadaDe`. Si la cotización ya no existe, no se muestra nada.
- Botón **"Duplicar"** en cualquier estado. Abre `#dialogo-duplicar` con el mismo `select` de
  clientes. El formulario es **GET** a `facturas.create` con `duplicar` y `cliente_id`: no crea nada,
  solo abre el formulario lleno (asunción 29).

### Parcial compartido

`documentos/_dialogo-duplicar.blade.php`: título, `select` de clientes, acción, método y campos
ocultos por parámetro. Lo usan el detalle de la cotización (POST) y el de la factura (GET).

## JavaScript

### `public/js/elegir-cotizacion.js` (nuevo)

- Se activa en `[data-elegir-cotizacion]` (el diálogo del listado de facturas).
- Al escribir en el buscador (espera de 300 ms) pide `facturas.cotizaciones?fragmento=1&q=…` con
  Axios, cancela la petición anterior y reemplaza la lista. No toca la URL de la página.
- Al abrir el diálogo, carga la lista sin filtro y pone el foco en el buscador.
- Errores: 401/419 o una redirección recargan la página. Cualquier otro error muestra "No se pudieron
  cargar las cotizaciones. Intenta de nuevo."

### Doble clic (adición 1, segunda parte)

"Generar y timbrar" y los botones "Duplicar" usan `data-enviar-una-vez` (012), que desactiva el botón
al enviar. El bloqueo de `store` cubre el caso de dos pestañas.

## Pruebas (Pest)

- **`CotizacionAFacturaTest`** (nuevo):
  - `create?cotizacion=` llena cliente, líneas y descuento global, y pone `cotizacion_id`;
  - una cotización en borrador, una ya facturada o una con línea libre (o con artículo eliminado)
    regresa al detalle con el motivo;
  - aviso de precios: aparece si el precio del catálogo cambió y no aparece si es igual;
  - `store` con `cotizacion_id` guarda el vínculo y la cotización queda `estaFacturada()`;
  - segunda factura sobre la misma cotización → redirige a la existente y no crea otra;
  - timbrado fallido (`pendiente`) → la cotización sigue facturada;
  - eliminar esa factura, o cancelarla con `accepted` → la cotización vuelve a ser facturable;
  - una cotización facturada no se edita ni se elimina (403), pero sí registra pagos y se entrega;
  - una cotización Enviada y facturada no aparece en `porCaducar` ni en `vencidas`, y el comando de
    caducidad no la borra;
  - facturar no cambia el estado ni los pagos de la cotización;
  - cotización ajena por `create?cotizacion=` o por `cotizacion_id` → 404 o error de validación;
  - `facturas.cotizaciones` solo lista cotizaciones propias por facturar sin líneas libres, filtra
    por `q` y responde fragmento con `?fragmento=1`;
  - detalle de la factura con "Origen", y detalle de la cotización con "Facturada" y el botón
    "Facturar" solo cuando corresponde.
- **`DuplicarDocumentosTest`** (nuevo):
  - duplicar cotización con otro cliente: copia líneas, costos y descuento, folio nuevo, `borrador`,
    sin pagos, `duplicada_de_id`;
  - `cliente_id` faltante, ajeno o eliminado → error de validación;
  - duplicar una cotización facturada → la copia nace sin factura;
  - `create?duplicar=` llena el formulario con el cliente elegido, los datos fiscales y las líneas,
    en cualquier estado (incluso cancelada);
  - una línea con artículo eliminado se omite con aviso;
  - `store` con `duplicada_de_id` guarda el vínculo y no copia `cotizacion_id`;
  - factura ajena → 404;
  - "Duplicada de…" en los dos detalles.
- **`CotizacionesTest`**, bloque `bandeja`: etiquetas "Facturadas" y "Por facturar", combinadas con
  carpeta y búsqueda; la fila muestra "Facturada".
- Que una Enviada vencida y facturada no se borre se prueba en `CotizacionAFacturaTest` (comando
  `cotizaciones:purgar-vencidas` incluido), junto con los scopes.
- Las pruebas de 011, 012 y 014 siguen pasando. La prueba de `duplicar` de 011 se actualiza: ahora
  exige `cliente_id`.

## Fuera de alcance

- Facturas parciales o varias facturas vigentes por cotización
  ([remotas/043](remotas/043-facturas-parciales-cotizacion.md)).
- Ver en la cotización el historial de sus facturas canceladas (solo se muestra la vigente).
- Botón "Facturar" o "Duplicar" en la vista previa de la bandeja.
- Preferencias fiscales por cliente (uso de CFDI, método y forma de pago por defecto).
- Actualizar precios con un botón desde el aviso de precios.
- Convertir una factura en cotización.
- Estilo bandeja en facturas.

## Estado de implementación

Implementada el 2026-09-30.

- **Archivos nuevos**:
  - migración `2026_09_30_100000_vinculos_cotizacion_factura_y_duplicados` (corrida en MySQL local),
  - `DuplicarCotizacionRequest`,
  - `documentos/_dialogo-duplicar`, `facturas/cotizaciones` y `facturas/_cotizaciones-facturables`,
  - `public/js/elegir-cotizacion.js`,
  - pruebas `CotizacionAFacturaTest` (27) y `DuplicarDocumentosTest` (15).
- **Archivos modificados**:
  - modelos `Cotizacion` (relaciones, `estaFacturada`, `esFacturable`, `lineasNoFacturables`,
    `motivoNoFacturable`, scopes `facturadas`, `porFacturar`, `soloLineasDeCatalogo`, y
    `porCaducar`/`vencidas` sin facturadas) y `Factura` (`cotizacion`, `duplicadaDe`),
  - `CotizacionPolicy` (motivos de facturada), `FacturaRequest` (`cotizacion_id`,
    `duplicada_de_id`, `origen()`), `ListadoCotizacionesRequest` (etiquetas),
  - `CotizacionController` (`duplicar`, cargas de `facturaVigente`, `withExists` en la bandeja) y
    `FacturaController` (`create`, `store`, `cotizaciones`, precargas),
  - `routes/web.php`, `app.css`, y las vistas `cotizaciones/show`, `_carpetas`, `_vista-previa`,
    `components/cotizaciones/fila`, `facturas/_formulario`, `show` e `index`.
- **Decisiones al implementar**:
  - `facturaVigente()` es un `hasOne` con `estado ≠ cancelada`, sin `latestOfMany`: nunca hay más
    de una vigente.
  - `estaFacturada()` usa `factura_vigente_exists` si el listado lo precargó (`withExists`), igual que
    `tienePagos()` con `pagos_count`. Así la bandeja no hace una consulta por fila.
  - El formulario de factura recibe los datos de cabecera en `$cabecera` (factura guardada,
    precarga o valores por defecto) en lugar de leerlos de `$factura`.
  - La ventana "Desde cotización" es un enlace a `facturas.cotizaciones` que el script intercepta.
    Con Ctrl o Cmd + clic abre la página.
- **Pruebas existentes ajustadas**: la de duplicar de 011 manda `cliente_id`. La de datos fiscales
  timbrados de 012 revisa el `<dd>` del receptor, porque la ventana "Duplicar" lista los clientes
  con su nombre actual.
- **Verificación**:
  - la suite Pest pasa completa (669 pruebas),
  - `node --test "tests/js/*.test.js"` pasa (36),
  - Pint no reporta cambios y `node --check` valida `elegir-cotizacion.js`,
  - las consultas nuevas se ejecutaron contra MySQL local.

  **No se revisó la UI en un navegador real.** Falta probar "Facturar" en una cotización, la ventana
  "Desde cotización" (búsqueda mientras se escribe), las ventanas "Duplicar" y las etiquetas nuevas
  de la bandeja, en escritorio y en celular.

## Criterios de aceptación

1. En el detalle de una cotización Enviada, Pagada o Entregada, sin factura vigente y con todas sus
   líneas del catálogo, aparece "Facturar". Lleva al formulario de factura lleno con el cliente, las
   líneas y el descuento de la cotización.
2. En una cotización en borrador, ya facturada o con líneas libres, "Facturar" no aparece. Si tiene
   líneas libres, un aviso dice cuáles hay que corregir.
3. Si un precio del catálogo cambió desde la cotización, el formulario lo avisa y conserva el precio
   cotizado.
4. Al pulsar "Generar y timbrar", la factura queda vinculada: la cotización muestra "Facturada" con
   liga a la factura, y la factura muestra "Origen: COT-…" con liga a la cotización.
5. En el listado de facturas, "Desde cotización" abre una ventana que busca por folio, cliente o RFC
   y solo lista cotizaciones que se pueden facturar. Elegir una abre el mismo formulario lleno.
6. Un doble clic o dos pestañas no crean dos facturas de la misma cotización: la segunda lleva a la
   primera.
7. Una cotización facturada no se edita, no se elimina y no caduca. Sus pagos, entrega, envío y PDF
   funcionan igual, y su estado no cambia por facturarla.
8. Si la factura se elimina (borrador o pendiente) o se cancela, la cotización vuelve a poder
   facturarse.
9. "Duplicar" en una cotización abre una ventana con los clientes y el original preseleccionado. La
   copia nace en Borrador, para el cliente elegido, con las mismas líneas y precios, sin pagos ni
   factura, y dice "Duplicada de COT-…".
10. "Duplicar" en una factura, en cualquier estado, abre la misma ventana y lleva al formulario lleno
    con el cliente elegido. Al timbrar, la nueva factura dice "Duplicada de FAC-…". Las líneas con
    artículos eliminados se omiten con aviso.
11. La bandeja de cotizaciones tiene las etiquetas "Facturadas" y "Por facturar", que se combinan con
    las carpetas y la búsqueda.
12. Nada de esto funciona con documentos ajenos (404).
13. Pint no reporta cambios, la suite Pest pasa y `node --check` valida `elegir-cotizacion.js`.

## Supuestos asumidos (registro completo)

1. Una cotización se convierte en una sola factura, por el total completo; las parciales quedan fuera.
2. Solo se facturan cotizaciones Enviada, Pagada o Entregada, no Borrador.
3. La conversión no timbra de inmediato: abre el formulario de la factura para revisar uso de CFDI,
   forma y método de pago antes de timbrar. *Precisión al redactar:* en 012 no existe guardar
   borrador, así que "la factura en borrador" es el formulario de alta ya lleno. La factura se guarda
   al pulsar "Generar y timbrar".
4. La factura toma de la cotización el cliente, las líneas y el descuento global.
5. Si la cotización tiene líneas libres, la conversión se bloquea y dice cuáles. *Precisión:* también
   las líneas cuyo artículo fue eliminado, porque 012 no acepta artículos eliminados en el alta.
6. Uso de CFDI, forma y método de pago con los valores del cliente o, si no tiene, los del formulario.
   *Precisión:* hoy los clientes no guardan esos valores, así que siempre se usan los del formulario.
7. En el formulario se pueden cambiar líneas y montos, aunque vengan de la cotización.
8. "Facturada" no es un estado nuevo: es una marca aparte, con liga a la factura.
9. La cotización queda facturada desde que se crea la factura, aunque no esté timbrada.
10. Si la factura se elimina o se cancela, la cotización deja de estar facturada.
11. Una cotización facturada no se edita ni se elimina; sí se ve, se envía, se descarga y registra
    pagos. *Consecuencia:* tampoco caduca.
12. Facturar no cambia los pagos ni el paso a Pagada.
13. La factura muestra de qué cotización vino, con liga.
14. En el detalle de la cotización aparece "Facturar" solo cuando se puede facturar.
15. En el listado de facturas hay un botón "Desde cotización" que abre una ventana de búsqueda.
16. Esa ventana solo muestra cotizaciones que se pueden facturar.
17. Al elegir una, sigue el mismo camino que el Proceso 1.
18. **(Cambiada)** Se queda un solo botón "Duplicar", que ahora pregunta el cliente con el original
    preseleccionado. Si no se cambia, la copia queda igual que antes. Aplica también a facturas.
19. La ventana de Duplicar trae el cliente original preseleccionado.
20. Se puede duplicar una cotización en cualquier estado, incluso facturada.
21. La copia conserva líneas, precios y descuentos de la original, sin tomar precios actuales.
22. La copia nace en Borrador, con folio nuevo, sin pagos ni marca de facturada.
23. Se puede duplicar una factura en cualquier estado, incluso cancelada.
24. La copia de factura es nueva: sin timbrar, sin cancelación, sin complemento y sin vínculo con la
    cotización.
25. Se elige el cliente igual que en la asunción 19.
26. Uso de CFDI, forma y método de pago se copian de la factura original.
27. Las claves SAT de cada línea se vuelven a leer del artículo.
28. Si un artículo de la original fue eliminado, esa línea se omite con aviso.
29. Al duplicar una factura se abre su formulario para revisarla antes de timbrar.

**Adiciones técnicas aprobadas:**

1. Protección contra doble clic completa: bloqueo de la cotización en el servidor al crear la factura,
   más el botón que se desactiva al primer clic.
2. Etiquetas "Facturadas" y "Por facturar" en la bandeja de cotizaciones.
3. "Duplicada de…" con liga al original, en cotizaciones y en facturas.
4. Aviso de precios del catálogo que cambiaron desde la cotización, sin cambiar la línea.
