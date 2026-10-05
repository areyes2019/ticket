# Spec: Formato unificado de los PDF (cotización, factura y orden de compra)

**Referencia:** reescritura de [remotas/019-formato-pdf-documentos.md](remotas/019-formato-pdf-documentos.md),
que se diseñó para la arquitectura anterior (Vue 3 + API, carpeta `backend/`). Se conservan el
diseño de la hoja (estructura, paleta, tipografía, bloque de timbre, corte de sellos), la regla de
que un PDF **nunca** se bloquea por configuración incompleta y la idea de un emisor único para toda
la instalación. Cambia todo lo que en la remota dependía de la SPA o de cosas que aquí ya existen:

| Remota | Aquí | Razón |
|---|---|---|
| Dos logos que el usuario sube (endpoints, disco privado, vista previa) | **Un logo fijo**: `public/img/marca/logo-sello-pronto-600.png`, en el repositorio | La marca es una sola y no cambia desde la pantalla; subir archivos es complejidad sin uso |
| Emisor completo en tabla editable, también para la factura | La factura sigue imprimiendo el **emisor copiado al timbrar** (`Factura::emisor()`); la tabla `emisor` alimenta a cotización y orden de compra, y a los datos de contacto de las tres | Lo fiscal de un CFDI ya timbrado no puede depender de lo que alguien capture después |
| `GET/PUT /api/v1/emisor`, store de sesión, avisos en Vue | Formulario Blade en Configuración y un `@include` de aviso en dos detalles | Laravel + Blade; no hay frontend separado que sincronizar |
| View composer para que las rutas públicas reciban el emisor | Los tres `GeneradorPdf*` ya concentran toda generación (descarga, vista previa, correo, compartir, autofactura): pasan el encabezado ellos | Un solo punto de entrada explícito; nada se inyecta por convención |
| `QrTimbreFiscal` nuevo, QR que hoy se descarga de un tercero | `GeneradorPdfFactura::urlVerificacion()` y `codigoQr()` **ya existen** y ya usan `chillerlan/php-qrcode`; solo se agrega el respaldo en texto | No se reescribe lo que funciona |
| `SatDescripciones` sobre `phpcfdi/sat-catalogos` | Los enums `RegimenFiscal`, `UsoCfdi`, `FormaPago`, `MetodoPago` con su `descripcion()`/`etiqueta()` | Es lo que ya usan las vistas actuales |
| Cambio de Helvetica a DejaVu | Ya es DejaVu Sans; se agrega DejaVu Sans Mono para los sellos | — |
| Renglón "Ajuste al peso" (remota 030) | No existe | Esa historia no está implementada aquí |
| Hoja A4 | Carta, como hoy (`setPaper('letter')`) | — |

## Historia de usuario

Como usuario, quiero otro formato para mis documentos impresos: que las cotizaciones, las facturas y
las órdenes de compra salgan con el mismo diseño —encabezado con el logo de Sello Pronto, mis datos
como emisor, tabla de conceptos con bordes, totales a la derecha y, en la factura, el timbre fiscal
digital completo con su código QR—, con las diferencias que impone cada documento.

## Objetivo / Alcance

Reemplazar el diseño de [facturas/pdf.blade.php](../resources/views/facturas/pdf.blade.php),
[cotizaciones/pdf.blade.php](../resources/views/cotizaciones/pdf.blade.php) y
[ordenes-compra/pdf.blade.php](../resources/views/ordenes-compra/pdf.blade.php) por una plantilla
base compartida, y crear lo que ese formato necesita y hoy no existe: el logo en la hoja y los datos
del emisor para los documentos que no se timbran.

**No** cambia: los datos de los documentos (ningún campo nuevo en facturas, cotizaciones u órdenes),
el timbrado, el correo, el compartir por WhatsApp, las rutas de PDF, los nombres de archivo, la
**hoja HTML de la bandeja** (`_vista-previa`) ni ninguna pantalla salvo Configuración y el aviso de
emisor incompleto. El PDF cambia de aspecto; nada más cambia de comportamiento.

## El logo

Archivo fijo **`public/img/marca/logo-sello-pronto-600.png`** (600 × 360 px, PNG con transparencia,
136 KB). Hoy está sin versionar: entra al repositorio con esta spec. `logo-sello-pronto.png`
(1358 × 814, 600 KB) no se usa en el PDF: pesaría cuatro veces más en cada correo y cada WhatsApp
sin verse mejor a ese tamaño.

- **Se incrusta como data URI**, leído con PHP desde `public_path()`. Ninguna vista de PDF referencia
  archivos por URL ni por ruta relativa: dompdf no sale a la red y su `chroot` no se toca.
  (En producción `public_html` es un enlace a `ticket_factura/public`, así que `public_path()`
  apunta al mismo archivo; el data URI evita depender de eso.)
- **Se dibuja con medidas en milímetros calculadas en PHP**, no con `max-width`/`max-height`, que
  dompdf respeta a medias. Caja de **50 mm de ancho × 30 mm de alto**, proporción intacta: con
  600 × 360 el logo llena la caja exacta (50 × 30 mm). Si algún día se cambia el archivo por uno de
  otra proporción, el cálculo lo ajusta a la caja sin deformarlo.
- **Si el archivo no existe o no se puede leer**, la celda queda vacía, el resto del encabezado
  conserva su lugar y se registra un `Log::warning`. El PDF nunca falla por el logo.

Vive en `app/Services/Documentos/LogoDocumento.php`:

- `dataUri(): ?string` — el PNG en base64, o `null` si no se pudo leer. Memoizado por petición: el
  envío por correo genera el PDF varias veces y el archivo no cambia.
- `medidas(): array{ancho_mm: float, alto_mm: float}` — el ajuste a la caja.
- Ruta y caja como constantes de la clase.

## El emisor

### Uno solo para toda la instalación

El timbrado usa una única llave de facturapi.io (ver `config/services.php`), así que todos los
usuarios emiten con el mismo RFC. Los datos del emisor son del negocio, no de cada usuario: no van
al almacén clave→valor por usuario de `configuraciones` (con `unique(user_id, clave)` un dato global
no tiene dónde vivir sin duplicarse) ni se toman del usuario dueño del documento.

### Tabla `emisor`

Migración `2026_10_10_100000_create_emisor_table`. Una sola fila, **sin `user_id`**, todas las
columnas nullable (se llena por partes; un emisor incompleto avisa, no bloquea):

| Columna | Tipo | Notas |
|---|---|---|
| `nombre` | `string` | Razón social o nombre ante el SAT |
| `rfc` | `string(13)` | |
| `regimen_fiscal` | `string(3)` | Clave de `RegimenFiscal` |
| `domicilio` | `string` | Una línea libre: `38024, Celaya, Guanajuato` |
| `correo` | `string` | |
| `telefono` | `string(13)` | Mismo formato que clientes |
| `timestamps` | | |

La migración crea la tabla **vacía**: no se siembra ningún RFC.

Modelo `App\Models\Emisor` (`$table = 'emisor'`, cast de `regimen_fiscal` a `RegimenFiscal`):

- `Emisor::actual(): Emisor` — la fila única o una instancia vacía sin guardar. **Nunca `null`**: ni
  las vistas ni el generador preguntan.
- `estaCompleto(): bool` — `nombre`, `rfc` y `regimen_fiscal` llenos.

### Quién lo edita

Solo el **administrador** (`Rol::Administrador`), con un gate nuevo `editar-emisor` definido junto a
`ver-historial-accesos` en `AppServiceProvider`. Es un dato de toda la instalación: un usuario común
no debería poder cambiar el RFC que aparece en los documentos de los demás.

### Qué imprime cada documento

| | Factura | Cotización / orden de compra |
|---|---|---|
| Nombre, RFC, régimen | Copia del timbrado (`Factura::emisor()`) | `Emisor::actual()` |
| Lugar de expedición (CP) | Copia del timbrado | — |
| Domicilio, correo, teléfono | `Emisor::actual()` | `Emisor::actual()` |

Con el emisor vacío, cotización y orden de compra imprimen el bloque con lo que haya, omiten los
renglones vacíos y, si no hay nombre, ponen `config('app.name')` en su lugar, como hoy. **Los PDF se
generan y se envían igual.** La factura no depende de la tabla para nada fiscal, así que nunca le
falta su emisor.

## La plantilla base

`resources/views/layouts/pdf.blade.php` concentra los estilos, el encabezado, los bloques de emisor y
contraparte, el contenedor de la tabla, los totales y el pie. Cada vista de documento la extiende y
declara lo suyo:

```blade
@extends('layouts.pdf', [
    'titulo' => 'ORDEN DE COMPRA',
    'folio' => $orden->folio_formateado,
    'notaPie' => 'Este documento no es un comprobante fiscal (CFDI).',
])

@section('meta') ... @endsection          {{-- bajo el folio: fechas --}}
@section('contraparte') ... @endsection   {{-- proveedor --}}
@section('conceptos')
    <x-pdf.conceptos :lineas="$orden->lineas" etiqueta-precio="Costo unitario" />
@endsection
@section('totales') ... @endsection
@section('extras') ... @endsection        {{-- observaciones, importe con letra, timbre --}}
```

Los tres `GeneradorPdf*` siguen llamando a la misma vista (`facturas.pdf`, `cotizaciones.pdf`,
`ordenes-compra.pdf`) y agregan a los datos que ya pasan:

- `'logo' => $logo->dataUri()` y `'logoMedidas' => $logo->medidas()` (`LogoDocumento` inyectado).
- `'emisor' => Emisor::actual()` en cotización y orden de compra; en factura, `'emisorContacto' =>
  Emisor::actual()` junto al `'emisor' => $factura->emisor()` que ya pasa.

Ningún controlador cambia: descarga, vista previa, correo (`*Mail`), compartir y autofactura pasan
todos por el generador.

### Estilo

- Paleta de la referencia: `#2c3e50` para títulos y filetes, `#95a5a6` para bordes de tabla,
  `#f5f5f5` para encabezados de tabla y el renglón de Total. Verde `#27ae60` y rojo `#c0392b` solo
  para el estado de la factura.
- `body { font-family: "DejaVu Sans", sans-serif; }`; las cajas de sellos en **DejaVu Sans Mono**.
  Ambas vienen con dompdf. Se conserva `isFontSubsettingEnabled` (el archivo pesa decenas de KB y no
  ~900).
- Los valores se escriben en la plantilla: dompdf no lee `public/css/app.css`.

### Encabezado

Logo a la izquierda; a la derecha el título del documento en 18 pt sobre `#2c3e50`, el folio en
13 pt y debajo la sección `meta`. Filete inferior de 2 px.

- **Factura**: título `FACTURA`, folio `folioVisible()`; meta: folio interno, `Tipo I – Ingreso ·
  CFDI 4.0`, fecha y hora de timbrado en `config('app.zona_negocio')`.
- **Cotización**: título `COTIZACIÓN`, folio `folio_formateado`; meta: fecha.
- **Orden de compra**: título `ORDEN DE COMPRA`; meta: fecha y, si la hay, **entrega esperada** en
  negritas.

### Emisor y contraparte

Dos columnas lado a lado; la contraparte con filete de separación a la izquierda.

- **Emisor** (izquierda): nombre, RFC, régimen como `612 – Personas Físicas con Actividades
  Empresariales y Profesionales`, domicilio, correo, teléfono. En la factura, además, lugar de
  expedición y el **estado en color**: `Vigente` en verde si está timbrada, `Cancelada` en rojo si
  está cancelada.
- **Contraparte** (derecha):
  - *Factura (receptor)*: copia del timbrado (`receptor()`): razón social, RFC, régimen, código
    postal fiscal, correo; uso de CFDI, **forma y método de pago y moneda**, que hoy viven en la caja
    aparte "Comprobante".
  - *Cotización (cliente)*: razón social, RFC, régimen, código postal fiscal, dirección comercial,
    correo y teléfono, omitiendo los vacíos.
  - *Orden de compra (proveedor)*: nombre comercial, RFC, contacto, correo y teléfono, omitiendo los
    vacíos.

Una clave que no esté en el enum (`tryFrom` devuelve `null`) se imprime sola, sin descripción. Un
código raro es preferible a un PDF que no sale.

Cotización y orden de compra **no** imprimen su estado: borrador, enviada o pagada son estados de
trabajo interno, no algo que el cliente o el proveedor necesite leer.

### Tabla de conceptos: `<x-pdf.conceptos>`

Componente anónimo `resources/views/components/pdf/conceptos.blade.php`. Columnas:

**Cant. · Unidad · Clave SAT · Descripción · Modelo · Precio unitario · Desc. · IVA · Importe**

- **Unidad** solo en factura (`:con-unidad="true"`): es dato del CFDI; cotizaciones y órdenes no
  guardan unidad.
- **Clave SAT**: en factura, la copia de la línea (`clave_prod_serv`); en cotización y orden, la del
  artículo (`$linea->articulo?->clave_prod_serv`). La relación `articulo()` de ambas líneas ya trae
  `withTrashed()`, así que un artículo dado de baja conserva su clave. Una línea de texto libre
  (`articulo_id` nulo) deja la celda vacía.
- **Desc.**: en factura el monto de `descuentoCfdi()` (como hoy, porque es lo que va en el XML); en
  las otras dos, `descuentoTexto()`. Guion largo si no hay. El componente recibe
  `:descuento-cfdi="true"` en la factura.
- **IVA**: `tasa_iva->etiqueta()`.
- **Precio unitario** se titula **Costo unitario** en la orden de compra (`etiqueta-precio`).
- Bordes `#95a5a6` en todas las celdas, encabezado sobre `#f5f5f5`, números alineados a la derecha.

`GeneradorPdfCotizacion` y `GeneradorPdfOrdenCompra` cargan `lineas.articulo` en su `loadMissing`;
sin eso la tabla dispara una consulta por renglón.

### Totales

Tabla de 38 % del ancho alineada a la derecha, con bordes y el renglón de Total en negritas sobre
`#f5f5f5`. Los renglones son **los mismos de hoy** en cada documento:

- Subtotal · Descuento (solo si hay) · IVA 16% (en la orden: "IVA 16% (acreditable)") · Total.
- Cotización con pagos: Pagado y Saldo pendiente debajo del Total, como hoy.
- El Total lleva la moneda: `$1,234.00 MXN` (`Factura::MONEDA`; cotización y orden son MXN).

### Extras

- **Factura**: el importe con letra debajo de los totales; luego el timbre.
- **Orden de compra**: el bloque de Observaciones, como hoy.
- **Factura cancelada**: se conserva la marca de agua `CANCELADA` además del estado en rojo.

### Pie

- Factura: *Este documento es una representación impresa de un CFDI.*
- Cotización y orden de compra: *Este documento no es un comprobante fiscal (CFDI).*

## El Timbre Fiscal Digital

Solo en facturas con documento fiscal (la ruta ya responde con error si no lo tiene). Bloque con
`page-break-inside: avoid`:

- Izquierda (23 %): QR de 30 × 30 mm, **UUID**, **No. de certificado del SAT** y fecha de timbrado.
- Derecha (77 %): sello digital del CFDI, sello del SAT y cadena original del complemento de
  certificación, cada uno con su título y en `<x-pdf.mono-box>`.

Una factura cancelada conserva su timbre completo.

### El QR

No cambia de origen: `GeneradorPdfFactura::codigoQr()` ya lo genera en el servidor con
`chillerlan/php-qrcode` a partir de `urlVerificacion()` (la URL que devolvió facturapi.io o, si no
llegó, la del Anexo 20 armada con `http_build_query`). Lo nuevo es qué pasa cuando falla:

- `codigoQr()` envuelve el render en `try/catch (Throwable)`. Si falla, devuelve `null`, registra
  `Log::error` con el folio y el motivo, y el PDF **se genera igual**.
- Sin imagen, en el lugar del QR se imprime **la URL de verificación como texto** en una
  `<x-pdf.mono-box>`, para que el comprobante se pueda verificar a mano. El generador pasa
  `'urlVerificacion'` a la vista además de `'qr'`.

### `<x-pdf.mono-box>`

Componente anónimo para las tiras sin espacios (sellos de ~344 caracteres, cadena original). Dompdf
no parte palabras: lo que no cabe en el renglón **no se imprime**, sin error.

- **Corta primero y escapa después**: `str_split` del texto crudo y `e()` a cada fragmento, unidos
  con `<br>`. Escapar antes de cortar podría partir una entidad (`&amp;` en `&am` + `p;`), y la
  cadena original sí puede llevar `&`. Es lo que ya hace la vista actual con `$renglones`; se mueve
  al componente.
- **Fragmentos de 110 caracteres a 5.8 pt** de DejaVu Sans Mono (0.6 em por carácter ≈ 3.5 pt →
  ~385 pt, dentro de los ~410 pt de la columna del 77 % en carta con márgenes de 1.3 cm). Las dos
  cifras se declaran juntas al principio del componente con la advertencia: **quien agrande la letra
  debe bajar el número**, o el texto vuelve a salirse de la hoja.
- `word-wrap: break-word` en la caja como red adicional.

## Configuración (Blade)

[configuracion/edit.blade.php](../resources/views/configuracion/edit.blade.php) gana una sección
**Datos del emisor** arriba de los mensajes, **solo visible para el administrador**:

- **Formulario propio** (`PUT /configuracion/emisor` → `ConfiguracionController@actualizarEmisor`,
  ruta `configuracion.emisor`, middleware `can:editar-emisor`), independiente del de los mensajes:
  guardar el teléfono no reenvía los mensajes ni al revés.
- Campos: nombre, RFC, régimen fiscal (`<select>` con `RegimenFiscal::cases()`), domicilio, correo,
  teléfono, con `<x-campo>` como el resto del sistema.
- `ActualizarEmisorRequest`: `nombre` requerido `max:255`; `rfc` requerido con `RfcValido`;
  `regimen_fiscal` requerido `Rule::enum(RegimenFiscal::class)`; `domicilio` opcional `max:255`;
  `correo` opcional `email`; `telefono` opcional con la regla de clientes. El formulario se guarda
  completo o no se guarda; las columnas nullable son para la fila, no para el formulario.
- `Emisor::query()->firstOrNew()->fill(...)->save()`: crea la fila la primera vez y la actualiza
  después; **nunca inserta una segunda**.
- Mientras `! estaCompleto()`, aviso arriba de la sección: *"Tus cotizaciones y órdenes de compra se
  están imprimiendo sin datos fiscales del emisor."*
- Al guardar, redirige a `configuracion.edit#emisor` con el mensaje de éxito, para que la
  confirmación quede a la vista del formulario y no al principio de la página.
- La vista previa del logo se muestra en la sección como `<img src="{{ asset('img/marca/logo-sello-pronto-600.png') }}">`
  con la nota *"Logo que aparece en los documentos."* No se puede cambiar desde aquí.

## Aviso de emisor incompleto

Partial `resources/views/documentos/_aviso-emisor.blade.php`, incluido en el detalle (`show`) y en la
vista previa de la bandeja de **cotizaciones** y **órdenes de compra**, junto a los botones de PDF,
envío y WhatsApp. Se pinta solo si `! Emisor::actual()->estaCompleto()`:

- Administrador: *"Tus documentos se imprimen sin datos del emisor."* con enlace a
  `configuracion.edit#emisor`.
- Usuario: el mismo aviso con *"Pídele al administrador que los capture."*, sin enlace.

La factura no lleva aviso: su emisor fiscal viene del timbrado. Es una consulta de una fila por
página; no se cachea.

## Archivos

**Nuevos**

- `database/migrations/2026_10_10_100000_create_emisor_table.php`
- `app/Models/Emisor.php`
- `app/Services/Documentos/LogoDocumento.php`
- `app/Http/Requests/ActualizarEmisorRequest.php`
- `resources/views/layouts/pdf.blade.php`
- `resources/views/components/pdf/conceptos.blade.php`
- `resources/views/components/pdf/mono-box.blade.php`
- `resources/views/documentos/_aviso-emisor.blade.php`
- `resources/views/configuracion/_emisor.blade.php`
- `public/img/marca/logo-sello-pronto-600.png` (al repositorio)
- `tests/Feature/FormatoPdfTest.php`

**Modificados**

- Las tres vistas `pdf.blade.php`: de ~100–150 líneas a extender la base y declarar sus secciones.
- `GeneradorPdfFactura`: pasa logo, `emisorContacto` y `urlVerificacion`; `codigoQr()` con
  `try/catch` y log.
- `GeneradorPdfCotizacion`, `GeneradorPdfOrdenCompra`: pasan logo y emisor; `lineas.articulo` en el
  `loadMissing`.
- `ConfiguracionController`: `edit` pasa el emisor; nuevo `actualizarEmisor`.
- `routes/web.php`: `PUT configuracion/emisor`.
- `AppServiceProvider`: gate `editar-emisor`.
- `configuracion/edit.blade.php`, detalle y `_vista-previa` de cotizaciones y órdenes de compra: el
  `@include` correspondiente.

## Pruebas

Feature tests con Pest sobre la base de trabajo (`php artisan test`, nunca `migrate:fresh`). Las de
contenido renderizan la vista a HTML (`view('cotizaciones.pdf', …)->render()` con los mismos datos
que arma el generador, expuestos por un método `datos()` del generador); las de "se genera"
llaman a `contenido()` y verifican que empieza con `%PDF`. Una prueba extrae el texto con
`smalot/pdfparser` (ya instalado) para comprobar acentos.

1. Los tres PDF se generan con el emisor completo y el HTML contiene nombre, RFC y folio.
2. Los tres PDF se generan **con la tabla `emisor` vacía**; cotización y orden muestran
   `config('app.name')`.
3. Los tres llevan el logo como `data:image/png;base64`.
4. Si el archivo del logo no existe (ruta de la constante sustituida en la prueba), los tres se
   generan y queda un warning en el log.
5. `LogoDocumento::medidas()` da 50 × 30 mm para 600 × 360 y respeta la caja con otras proporciones.
6. La factura imprime nombre y RFC **del timbrado** aunque la tabla `emisor` tenga otros, y
   domicilio/teléfono de la tabla.
7. Una factura timbrada incluye UUID, no. de certificado, ambos sellos y la cadena original.
8. Una factura cancelada conserva su timbre, muestra `Cancelada` y la marca de agua.
9. Si el render del QR lanza excepción, el PDF se genera con la URL de verificación en texto y queda
   un `Log::error`.
10. La orden de un proveedor sin RFC, contacto ni correo se genera sin renglones vacíos.
11. Una línea de texto libre deja la Clave SAT vacía; una línea de un artículo borrado conserva la
    suya.
12. La orden titula "Costo unitario"; factura y cotización, "Precio unitario". Solo la factura tiene
    columna Unidad.
13. `<x-pdf.mono-box>` corta 500 caracteres sin espacios en fragmentos de 110 y un `&` sale como
    `&amp;` íntegro.
14. El texto extraído del PDF de cotización contiene "Cotización" y "Régimen" con sus acentos.
15. `PUT /configuracion/emisor` crea la fila la primera vez y la actualiza la segunda; nunca hay dos.
16. RFC inválido o régimen inexistente devuelven error de validación.
17. Un usuario sin rol de administrador recibe 403 en `PUT /configuracion/emisor` y no ve la sección.
18. El aviso aparece en el detalle de cotización y de orden con el emisor incompleto y desaparece al
    completarlo; el detalle de factura no lo lleva.
19. Las pruebas existentes de descarga, correo y compartir de los tres documentos siguen pasando sin
    cambios (mismos nombres de archivo, mismos adjuntos).

## Fuera de alcance

- **Subir o cambiar el logo** desde la aplicación, y un segundo logo.
- **Multiempresa**: un emisor por instalación.
- **Usar la tabla `emisor` para timbrar**: el timbrado sigue tomando el emisor de facturapi.io.
- **La hoja HTML de la bandeja** (`_vista-previa`): mantiene su diseño.
- Domicilio desglosado, colores o textos configurables, fecha de vigencia de cotizaciones, notas al
  pie por documento, renglón de ajuste al peso, PDF de complementos de pago, guardar el QR,
  comparación visual automatizada.

## Criterios de aceptación

1. Los tres documentos comparten encabezado con el logo de Sello Pronto, bloques de emisor y
   contraparte, tabla con bordes, totales a la derecha y pie, con la paleta gris azulada.
2. El logo se imprime a 50 × 30 mm sin deformarse; sin archivo, el documento sale igual y queda
   registro.
3. La factura imprime el emisor fiscal copiado al timbrar; cotización y orden de compra, el de
   Configuración.
4. La factura integra uso de CFDI, forma y método de pago y moneda en el bloque del receptor, y
   muestra su estado en verde o rojo.
5. Las claves del SAT salen con su descripción; una desconocida sale sola sin romper el documento.
6. La tabla muestra Clave SAT y Modelo en los tres; Unidad solo en factura; "Costo unitario" en la
   orden de compra.
7. Los totales conservan sus renglones actuales, con el Total sobre fondo gris y la moneda.
8. El timbre muestra QR, UUID, certificado, sellos y cadena original completos, sin tiras fuera de la
   hoja; si el QR falla, sale la URL en texto y la falla queda en el log.
9. Acentos y eñes correctos en los tres documentos.
10. Con el emisor sin capturar, los tres PDF se descargan y envían igual, y cotizaciones y órdenes
    avisan junto al botón de PDF.
11. Solo el administrador captura el emisor en Configuración; guardarlo dos veces no crea dos
    registros.
12. Descarga, correo, WhatsApp y autofactura siguen funcionando con los mismos nombres de archivo.

## Supuestos asumidos

1. El logo es fijo (`logo-sello-pronto-600.png`); no hay segundo logo ni carga desde la aplicación.
2. Lo fiscal de la factura sale de la copia del timbrado; la tabla `emisor` solo aporta contacto a
   la factura.
3. Solo el administrador edita el emisor; los usuarios ven el aviso sin enlace.
4. Cotización y orden de compra no imprimen su estado interno.
5. Se conservan la marca de agua `CANCELADA`, el importe con letra, Pagado/Saldo de la cotización,
   la entrega esperada y las observaciones de la orden.
6. Los totales no ganan renglones nuevos (IVA 0 % / exento, ajuste al peso).
7. Hoja carta, márgenes de 1.3 cm.
8. Paleta de la plantilla de referencia, no el naranja de la marca: el logo ya pone el color.

## Estado de implementación

Implementada el **2026-10-04**. `php artisan test` corre limpio (1138 pruebas, 18 nuevas en
`tests/Feature/FormatoPdfTest.php`); Pint también. Se generaron PDF reales de una factura timbrada,
una cotización y una orden de compra con datos de la base local y se revisaron renderizados a imagen.

### Detalles resueltos durante la implementación

- **`<x-pdf.emisor>` y `<x-pdf.clave-sat>`**: dos componentes más que la lista de archivos no
  contemplaba. El primero evita repetir el bloque del emisor en tres vistas; el segundo, la
  descripción del SAT con su caída a la clave sola.
- **`<x-pdf.mono-box>` acepta `corte`**: la URL de verificación cuando falla el QR va en la columna
  angosta (23 %), donde solo caben 32 caracteres; los sellos siguen en 110.
- **`LogoDocumento` se registra como `scoped`** en `AppServiceProvider`, para que la lectura del
  archivo se haga una vez por petición aunque el correo genere el PDF varias veces.
- **Totales al 38 %**, no al 35 %: con "IVA 16% (acreditable)" en un solo renglón la tabla se salía
  unos puntos del margen derecho.
- **Celda de descuento vacía con guion largo** también en cotización y orden (`descuentoTexto()`
  devuelve cadena vacía cuando no hay).
- **Peso del archivo**: el logo incrustado sube los PDF de unas decenas de KB a ~155 KB. Si llega a
  importar para WhatsApp, convertir el logo a JPEG sobre fondo blanco lo bajaría sin cambio visible.
