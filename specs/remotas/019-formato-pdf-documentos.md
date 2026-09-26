# Spec: Formato unificado de los PDF (cotización, factura y orden de compra)

## Historia de usuario

Como usuario, quiero otro tipo de formato para mis documentos impresos. Que las cotizaciones, las
facturas y las órdenes de compra salgan con el mismo diseño —encabezado con mis logos, mis datos
fiscales como emisor, tabla de conceptos con bordes, bloque de totales a la derecha y, en el caso
de la factura, el timbre fiscal digital completo con su código QR— con las diferencias obvias que
impone la necesidad de cada documento.

El formato de referencia es una plantilla escrita en CodeIgniter 4 que el usuario entregó junto con
la historia. Esta spec la adapta; no la copia literalmente. Las secciones "Lo que se toma" y "Lo que
no se toma" dicen exactamente qué se conserva y qué se cambia, y por qué.

## Objetivo / Alcance

Reemplazar el diseño de los tres PDF que hoy genera el sistema
—[factura](../backend/resources/views/pdf/factura.blade.php),
[cotización](../backend/resources/views/pdf/cotizacion.blade.php) y
[orden de compra](../backend/resources/views/pdf/orden-compra.blade.php)— por un formato único y
compartido, y crear lo que ese formato necesita y hoy no existe: los datos fiscales del emisor, sus
logos y el código QR de verificación del SAT.

Incluye: el registro del emisor y sus dos logos, editables desde
[Configuración](014-costo-elaboracion-goma.md); una plantilla base compartida por los tres
documentos; el bloque de Timbre Fiscal Digital con QR generado en el propio servidor; el cambio de
tipografía a DejaVu; y pruebas automáticas que generan los tres documentos, incluidos sus casos con
datos faltantes.

**No** incluye: cambios en los datos que llevan los documentos (ningún campo nuevo en facturas,
cotizaciones u órdenes), cambios en el timbrado, en el envío por correo o WhatsApp, en las pantallas
de los tres módulos, ni multiempresa. El PDF cambia de aspecto; nada más cambia de comportamiento.

### Lo que se toma del formato de referencia

- La estructura completa: encabezado con logos a la izquierda y nombre del documento a la derecha;
  emisor y contraparte lado a lado; tabla de conceptos; totales alineados a la derecha; timbre al
  pie.
- La paleta: gris azulado `#2c3e50` para títulos y filetes, `#95a5a6` para los bordes de tabla,
  `#f5f5f5` para fondos de encabezado y del renglón de total.
- La familia tipográfica DejaVu Sans, y DejaVu Sans Mono para los sellos.
- El bloque de Timbre Fiscal Digital: QR, UUID y serie del CSD a la izquierda; sellos y cadena
  original a la derecha, en cajas monoespaciadas.
- La técnica de corte de los sellos, con la corrección de orden que se explica en "El corte de los
  sellos".
- La dirección del QR de verificación del SAT, armada con los mismos cinco datos.

### Lo que no se toma, y por qué

| Del formato de referencia | Aquí | Razón |
|---|---|---|
| Emisor escrito dentro de la plantilla | Registro editable desde Configuración | Un cambio de domicilio o de régimen no debe exigir tocar código, y el dato quedaría copiado tres veces |
| Descripciones de régimen y uso de CFDI en un arreglo escrito a mano (9 usos, 9 regímenes) | Catálogo SAT que el sistema ya consulta | La lista de referencia está incompleta y se desactualiza sola; `phpcfdi/sat-catalogos` ya está instalado y ya alimenta los `<select>` del sistema |
| QR descargado de `api.qrserver.com` en cada impresión | QR generado en el servidor | Ver "El código QR" |
| `IVA (16%)` fijo | Los cinco renglones de totales que el sistema ya calcula | Aquí el IVA es por línea: 16%, 0% o exento |
| Logos leídos de `public/img/` por ruta relativa | Archivos leídos por PHP e incrustados en base64 | En producción `public/` no existe: el docroot es `public_html/`, hermano de la aplicación (ver [018](018-despliegue-hostinger.md) y el comentario de `config/dompdf.php`) |
| Cadena original armada en la plantilla | La `cadena_original_sat` que ya devuelve el PAC | Armarla a mano exige el RFC del proveedor de certificación, que el sistema no guarda; y una cadena reconstruida que no coincida con la real es peor que no tenerla |
| Columnas Cant. / Clave SAT / Descripción / P/U / Desc. / Importe | Las mismas, más Modelo e IVA | Ver "La tabla de conceptos" |

## El emisor

### Un solo emisor para toda la instalación

Los datos fiscales del emisor **no son de cada usuario: son del negocio**. La razón no es de gusto,
es de hecho: el timbrado usa una única llave de Facturapi tomada del entorno
(`FACTURAPI_LIVE_KEY`, ver `config/services.php`), así que **todos los usuarios del sistema timbran
con el mismo certificado y por tanto con el mismo emisor**. Un emisor por usuario permitiría que un
PDF dijera un RFC y el CFDI timbrado detrás llevara otro.

### Por qué una tabla propia y no el almacén clave→valor

El docblock de [`ClaveConfiguracion`](../backend/app/Enums/ClaveConfiguracion.php) anticipa que "los
datos fiscales del emisor" entrarían al almacén clave→valor de
[014](014-costo-elaboracion-goma.md). **Esta spec se aparta de esa previsión**, por tres razones
concretas que no se veían cuando se escribió:

1. El almacén es **por usuario** (`unique(user_id, clave)`), y el emisor es uno solo. Hacer
   `user_id` nullable rompe esa unicidad: MySQL considera distintos dos `NULL`, así que la misma
   clave global podría insertarse dos veces sin que el índice lo impida.
2. El valor es `string` (255). El domicilio cabe; **la referencia a un logo con su ruta también**,
   pero mezclar un archivo con tres ajustes de texto en el mismo renglón hace que el pizarrón deje
   de ser un pizarrón.
3. El emisor no es un ajuste suelto: es **un registro** cuyos campos se validan juntos y se guardan
   juntos. Cargarlo a medias no tiene sentido; cargar un costo de goma sí.

Los tres costos de goma se quedan donde están. El almacén clave→valor sigue siendo el lugar correcto
para lo que es escalar y por usuario.

Se descartó también leer el emisor del usuario dueño del documento (`$documento->user`). Funciona
—incluso en la ruta pública firmada, porque el documento sí trae `user_id`—, pero produce justo lo
que el punto de arriba prohíbe: dos usuarios con emisores distintos timbrando con el mismo
certificado.

### Tabla `emisor`

Una sola fila. Sin `user_id`. **Todas las columnas son nullable.**

- `nombre`: `string`, nullable. Razón social o nombre de la persona física, tal como está ante el
  SAT.
- `rfc`: `string(13)`, nullable.
- `regimen_fiscal`: `string(3)`, nullable. Clave del catálogo `c_RegimenFiscal`.
- `domicilio`: `string`, nullable. Una línea de texto libre, como en la referencia
  (`38024, Celaya, Celaya, Guanajuato, MEX`). No se desglosa en calle, número, colonia y estado
  porque el PDF lo imprime en un renglón y el desglose no se usa para nada más.
- `correo`, `telefono`: `string`, nullable. Se imprimen bajo el domicilio cuando existen.
- `logo_ruta`, `logo_marca_ruta`: `string`, nullable. Ruta relativa del archivo dentro del disco
  privado. `null` significa "sin logo".
- `timestamps`.

La migración **crea la tabla vacía**, sin sembrar nada: no hay datos de producción que rescatar
(ver [[project_sistema_sin_produccion]]) y sembrar el emisor de la plantilla de referencia dejaría
el RFC de otra persona escrito en la base de datos de cualquiera que instale el sistema.

Modelo `Emisor` con un método `Emisor::actual()`: devuelve la fila única o una instancia vacía sin
guardar. **Nunca devuelve `null`**, para que ni la plantilla ni el frontend tengan que preguntar.

**Por qué los tres campos fiscales son nullable.** El emisor se llena por partes y en el orden que
el usuario quiera: quien sube su logo antes de capturar su RFC está haciendo algo perfectamente
razonable, y con columnas `NOT NULL` esa fila no se puede crear. Poner el `NOT NULL` en la base
tampoco garantizaría nada útil —una fila con `nombre = ''` pasaría igual—, y contradiría al resto
del diseño: `Emisor::actual()` devuelve una instancia vacía a propósito, `estaCompleto()` existe
justamente para preguntar por lo que falta, y toda la spec descansa en que un emisor incompleto
avisa en vez de bloquear.

Quien sí exige los tres campos es `UpdateEmisorRequest`: el formulario de datos fiscales se guarda
completo o no se guarda. La diferencia es deliberada — la validación pertenece al formulario que
captura esos datos, no a la fila que además guarda dos logos.

### Los logos

Se guardan en el disco privado, bajo `storage/app/private/emisor/`, con nombre generado. **No van al
disco público ni necesitan enlace simbólico**: la plantilla no los referencia por URL, los lee con
PHP y los incrusta en el HTML como base64, igual que la referencia. Eso mantiene cierto el comentario
de `config/dompdf.php` —ninguna vista de `pdf/` referencia archivos por ruta relativa— y evita de
raíz la trampa de producción que documenta [018](018-despliegue-hostinger.md).

- Formatos aceptados: PNG y JPEG. **No SVG**: dompdf no lo dibuja de forma confiable y el usuario se
  quedaría mirando un hueco sin saber por qué.
- Tamaño máximo 2 MB por archivo. Un logo pesado se incrusta entero en cada PDF y engorda cada
  correo que se manda.
- Al reemplazar un logo, el archivo anterior se borra en el mismo acto. Sin eso, el directorio
  acumula todos los logos que el usuario haya probado.
- Si el archivo referenciado por `logo_ruta` no existe en disco, la plantilla imprime el hueco vacío
  y **registra un warning**. El PDF nunca falla por un logo.
- Los dos se imprimen dentro de una caja de **40 mm de alto por 55 mm de ancho**, con la proporción
  del archivo intacta: un logo cuadrado sale de 4 × 4 cm y uno alargado se detiene en los 55 mm de
  ancho. La medida se escribe en **milímetros, no en píxeles**, para que el tamaño impreso sea el
  que dice y no dependa del `dpi` con el que dompdf traduzca los píxeles. Los 55 mm son el techo que
  impone la celda del encabezado: 30 % de los 193 mm de área útil de una A4 con los márgenes de esta
  plantilla. Ambos logos comparten la misma caja porque van lado a lado y uno más grande que el otro
  descuadra el encabezado.

### Qué pasa si el emisor está vacío

El emisor aparece en **los tres** documentos, no solo en la factura. Si nadie lo ha capturado:

- Los PDF **se generan igual**. Nunca se bloquea la descarga ni el envío de un documento por una
  configuración incompleta; un usuario que necesita mandar una cotización ahora no puede quedar
  detenido por una pantalla que no sabe que existe.
- El bloque del emisor imprime lo que haya y omite los renglones vacíos.
- El frontend avisa: la pantalla de Configuración muestra el aviso mientras el emisor esté
  incompleto, y los detalles de cotización, factura y orden de compra lo muestran junto al botón de
  descargar PDF. Ver "Frontend".

El QR de la factura sí depende del RFC del emisor. Ver "Cuando el QR no se puede generar".

## La plantilla base compartida

`resources/views/pdf/documento.blade.php` concentra todo lo común: los estilos, el encabezado con
logos, el bloque del emisor, la tabla de conceptos, el bloque de totales y el pie. Las tres vistas
existentes se reducen a declarar lo que cambia:

```blade
@extends('pdf.documento', [
    'titulo' => 'ORDEN DE COMPRA',
    'folio' => $orden->folioFormateado(),
    'fecha' => $orden->created_at,
    'estado' => $orden->estado,
    'lineas' => $orden->lineas,
    'documento' => $orden,
    'etiquetaPrecio' => 'Costo unitario',
    'notaPie' => 'Este documento no es un comprobante fiscal (CFDI)',
])
```

y a llenar las secciones que les son propias: `contraparte`, `meta-extra`, `extras` y `timbre`. El
paso de datos por `@extends` evita inventar un objeto intermedio para tres documentos que solo se
diferencian en el nombre de una columna y en dos bloques.

El **emisor lo inyecta un view composer** registrado sobre `pdf.documento`, no los controladores.
Así entra igual en las tres rutas autenticadas y en las dos rutas públicas firmadas
(`cotizaciones/{id}/pdf-publico` y `ordenes-compra/{id}/pdf-publico`), que no tienen usuario y desde
las que Twilio descarga el adjunto de WhatsApp.

Ninguno de los tres controladores cambia. `FacturaController::pdf` sigue llamando a
`loadView('pdf.factura', ...)` y `EnvioDocumentoService` sigue resolviendo la vista por
`DocumentoEnviable::vistaPdf()`. La spec cambia el contenido de las vistas, no cómo se invocan.

### La tabla de conceptos

Columnas, en este orden: **Cant. · Clave SAT · Descripción · Modelo · P/U · Desc. · IVA · Importe**.

Son las seis de la referencia más `Modelo` e `IVA`, que el sistema ya tiene y que la referencia no
podía tener porque asume 16% para todo. Quitarlas sería perder información real del negocio a cambio
de parecerse más a una plantilla.

La **Clave SAT** sale de `linea.articulo->clave_prod_serv`. Las líneas no guardan copia propia de esa
clave —guardan `descripcion` y `modelo`, pero no la clave—, así que:

- Una línea de texto libre (`articulo_id` en `null`, permitido desde [012](012-ordenes-compra.md))
  imprime la celda vacía.
- La relación se carga **con `withTrashed()`**: los artículos usan borrado lógico, y sin eso una
  factura vieja de un artículo dado de baja perdería su clave al reimprimirse.

`OrdenCompra::datosPdf()` hoy carga `['proveedor', 'lineas']` y necesita `lineas.articulo`; se
agrega, o la tabla dispara una consulta por renglón.

En la orden de compra la columna de precio se titula **Costo unitario**; en factura y cotización,
**Precio unitario**. Es la única diferencia de la tabla entre los tres documentos.

### Los totales

Bloque de ancho `32%` alineado a la derecha, con bordes, y el renglón de Total sobre fondo gris.
Renglones: Subtotal · Descuento · IVA 16% · IVA 0% / Exento · Ajuste al peso · Total. El de
Descuento aparece solo cuando hay descuento; los dos de IVA, solo cuando su importe es distinto de
cero, porque una cotización sin nada exento no gana nada mostrando un renglón en `$0.00`; el de
Ajuste al peso ([030](030-total-al-peso-cerrado.md)), solo cuando es mayor a cero, y por eso no
aparece nunca en una orden de compra, que no redondea.

La moneda se imprime junto al total: `$1,234.56 MXN`. La factura usa `factura.moneda`; cotización y
orden de compra siguen siendo MXN fijo, como hoy.

### Encabezado, emisor y contraparte

- **Encabezado**: los dos logos a la izquierda (el de la empresa y el de marca), cada uno hasta
  4 × 4 cm, y a la derecha el nombre del documento en 18pt sobre `#2c3e50` con el folio debajo en
  13pt. Si no hay logos, la celda queda vacía y el resto no se recorre.
- **Emisor** (izquierda): nombre, RFC, domicilio, régimen con su descripción, correo y teléfono.
  Debajo, la fecha del documento y su estado **en color**: verde `#27ae60` cuando está vigente,
  rojo `#c0392b` cuando está cancelado.
- **Contraparte** (derecha, con filete de separación):
  - *Factura*: razón social, RFC, domicilio comercial, código postal fiscal, uso de CFDI con
    descripción, régimen fiscal con descripción, correo. Aquí se integran también **forma de pago y
    método de pago**, que hoy viven en una caja aparte llamada "Datos del comprobante".
  - *Cotización*: los mismos datos del cliente, sin uso de CFDI, forma ni método de pago, porque una
    cotización no los tiene.
  - *Proveedor* (orden de compra): nombre comercial, RFC si lo hay, persona de contacto, correo y
    teléfono.

Las claves del SAT se imprimen como `612 - Personas Físicas con Actividades Empresariales y
Profesionales`, resolviendo la descripción contra `phpcfdi/sat-catalogos` a través de un método del
`CatalogoController` extraído a un servicio reutilizable, `SatDescripciones`, con **una consulta por
clave y memoria dentro de la misma generación**. Si una clave no está en el catálogo se imprime sola,
sin descripción y sin error: un código raro es preferible a un PDF que no sale.

### El pie

- Factura: *Este documento es una representación impresa de un CFDI*.
- Cotización y orden de compra: *Este documento no es un comprobante fiscal (CFDI)*.

## El Timbre Fiscal Digital

Solo en facturas **timbradas** (con `uuid_fiscal`). Una factura en `pendiente` o con
`error_timbrado` imprime el resto del documento sin el bloque, no un bloque vacío.

Estructura, igual que la referencia: a la izquierda (23%) el QR, el UUID y la serie del CSD del SAT;
a la derecha (77%) el sello CFDI, el sello SAT y la cadena original en cajas monoespaciadas. El
bloque lleva `page-break-inside: avoid` para que no se parta a la mitad entre dos páginas.

Una factura cancelada conserva su timbre completo. El estado en rojo del encabezado es lo único que
la distingue: el CFDI existió y su constancia impresa sigue siendo válida como representación.

### El código QR

Se genera **en el servidor**, con `chillerlan/php-qrcode`, que ya está instalado y en uso por el
[lector de constancias fiscales](016-constancia-situacion-fiscal-qr.md). No se instala nada nuevo y
no se sale a internet.

La referencia descarga la imagen de `api.qrserver.com` en cada impresión, con `@file_get_contents`.
Se descarta por cuatro motivos, en orden de gravedad:

1. **Falla en silencio.** La `@` significa literalmente "si esto falla, no digas nada": el PDF sale
   sin QR y nadie se entera. Se podrían mandar facturas mudas durante semanas.
2. **Filtra el contenido de cada factura a un tercero.** La dirección que se le manda al servicio
   incluye RFC emisor, RFC receptor, UUID e importe total. Ese servicio ve —y puede guardar— quién
   le factura a quién y por cuánto.
3. **Bloquea la generación** mientras el tercero responde, y depende de que el servidor tenga salida
   a internet en ese instante.
4. **Es un servicio gratuito sin compromiso.** Puede limitarse, cobrar o desaparecer.

Contenido del QR, idéntico al de la referencia y al que exige el Anexo 20:

```
https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx
    ?id={uuid_fiscal}
    &re={rfc del emisor}
    &rr={rfc del cliente}
    &tt={total con 6 decimales, sin separador de miles}
    &fe={últimos 8 caracteres del sello_cfdi}
```

Vive en `app/Services/QrTimbreFiscal.php`, con dos métodos: uno que arma la dirección y otro que
devuelve el PNG como data URI. Se separan porque el primero es lo que se prueba y lo que se imprime
cuando el segundo falla.

### Cuando el QR no se puede generar

Pasa si falta el RFC del emisor, si falta el sello, o si la librería lanza una excepción. En ese
caso:

- El PDF **se genera igual**. Nunca se le niega a un usuario su factura por un QR.
- En lugar de la imagen se imprime **la dirección de verificación como texto**, en la misma caja
  monoespaciada de los sellos, para que siga siendo posible verificar el comprobante a mano.
- Se registra un `Log::error` con el folio de la factura y el motivo.

Eso es lo contrario del `@` de la referencia: el PDF nunca queda mudo y la falla siempre deja rastro.

## Tipografía y corte de los sellos

### DejaVu en todo el documento

Las tres plantillas actuales piden `'Helvetica'` como primera opción. En dompdf, Helvetica es una de
las fuentes "de fábrica" del formato PDF, sin archivo propio y con repertorio de caracteres limitado:
los acentos y la eñe dependen de la codificación y pueden salir mal. **DejaVu Sans** viene incluida
con dompdf (`vendor/dompdf/dompdf/lib/fonts/`), no hay que instalarla, y trae el repertorio completo.
Con "Cotización", "Régimen" y apellidos con eñe en el papel, la diferencia importa.

Se declara `body { font-family: 'DejaVu Sans', sans-serif; }` en la plantilla base, y **DejaVu Sans
Mono** —también incluida— en las cajas de sellos, donde el ancho fijo de cada carácter permite cotejar
una tira a mano sin perder el renglón.

### El corte de los sellos

Los sellos y la cadena original son tiras de 350 a 500 caracteres **sin un solo espacio**. Un
maquetador corta renglones en los espacios: sin ellos, concluye que es una sola palabra, la escribe
de corrido y **lo que se sale de la caja no se imprime**, sin error ni aviso. Es exactamente el
problema que la plantilla de referencia marca con el comentario `🔥 FIX REAL DOMPDF`.

La técnica que se adopta es la suya, y conviene dejar escrito **por qué funciona**, porque parece
redundante y alguien va a querer "limpiarla":

1. Se inserta un salto de línea cada **120 caracteres** con `chunk_split`. En HTML ese salto no
   dibuja nada: se colapsa como espacio en blanco. Lo que hace es **crear una oportunidad de corte**
   donde no había ninguna.
2. Se acompaña con `word-wrap: break-word` en la caja, como red por si un fragmento aún no cabe.

Ninguna de las dos basta sola. Las dos juntas son las que hacen que el sello salga completo.

**Corrección respecto de la referencia: se corta primero y se escapa después.** La referencia hace
`chunk_split(esc($x), 120)`, es decir escapa y luego corta, y eso puede meter un salto **dentro** de
una entidad HTML (`&amp;` partido en `&am` + `p;`), que se imprimiría literal. Con los sellos no pasa
—son base64, sin caracteres que se escapen— pero la cadena original sí lleva texto y sí puede llevar
`&`. Se implementa como componente `<x-pdf.mono-box>`, que corta el texto crudo y deja que Blade
escape el resultado.

El **120 va amarrado al tamaño de letra** (5.8pt): a ese tamaño, 120 caracteres llenan justo el ancho
de la caja. Quien agrande la letra sin bajar el número volverá a tener texto fuera de la hoja. Las dos
constantes se declaran juntas en el componente, con esa advertencia escrita al lado.

## Backend (Laravel)

### Archivos nuevos

- `database/migrations/*_create_emisor_table.php`.
- `app/Models/Emisor.php`, con `Emisor::actual()`.
- `app/Http/Controllers/EmisorController.php`: `show`, `update`, `subirLogo`, `eliminarLogo`.
- `app/Http/Requests/Emisor/UpdateEmisorRequest.php` y `SubirLogoEmisorRequest.php`.
- `app/Http/Resources/EmisorResource.php`.
- `app/Services/QrTimbreFiscal.php`.
- `app/Services/SatDescripciones.php`.
- `app/View/Composers/EmisorComposer.php`, registrado en `AppServiceProvider` sobre `pdf.documento`.
- `resources/views/pdf/documento.blade.php` (plantilla base).
- `resources/views/components/pdf/mono-box.blade.php`.

### Endpoints (bajo `auth:sanctum`)

- `GET /api/v1/emisor` — el emisor actual; devuelve la estructura completa aunque esté vacía, con
  banderas `tiene_logo` / `tiene_logo_marca` en vez del contenido de las imágenes.
- `PUT /api/v1/emisor` — crea la fila única la primera vez y la actualiza después; nunca inserta
  una segunda.
- `GET /api/v1/emisor/logo/{tipo}` — sirve el archivo para la vista previa de Configuración. Los
  logos viven en el disco privado y no tienen URL propia; esta es la única forma de mirarlos.
- `POST /api/v1/emisor/logo` — multipart, campo `archivo` y campo `tipo` (`principal` | `marca`).
- `DELETE /api/v1/emisor/logo/{tipo}` — quita el logo y borra el archivo.

Van junto a `configuracion` en `routes/api.php`, dentro del mismo grupo autenticado.

**No se scopean por usuario**: el emisor es del sistema. Es la única excepción al patrón de
004/005/006/007/008/009/010/012/017, y existe por lo dicho en "Un solo emisor para toda la
instalación".

### Validaciones

- `nombre`: requerido, string, `max:255`.
- `rfc`: requerido, `RfcValido` — la misma regla que ya usan clientes y proveedores.
- `regimen_fiscal`: requerido, 3 caracteres, existente en `c_RegimenFiscal`.
- `domicilio`, `correo` (formato de correo), `telefono`: opcionales.
- `archivo` (logo): requerido, `image`, `mimes:png,jpg,jpeg`, `max:2048` (KB).
- `tipo` (logo): requerido, `in:principal,marca`.

### Vistas modificadas

- `resources/views/pdf/factura.blade.php`, `pdf/cotizacion.blade.php`, `pdf/orden-compra.blade.php`:
  pasan de ~100 líneas cada una a extender la base y declarar sus diferencias.

### Modelos modificados

- `OrdenCompra::datosPdf()`: agrega `lineas.articulo` a `loadMissing`.
- `Cotizacion::datosPdf()`: ya carga `lineas.articulo`; se le agrega `withTrashed` en la relación de
  la línea.
- `Factura`: `FacturaController::pdf` carga hoy sus relaciones a mano; se le suma `lineas.articulo`
  con `withTrashed`.

### Tests

Feature tests sobre la base MySQL de trabajo con `php artisan test`, nunca `migrate:fresh` (ver
[[feedback_nunca_migrate_fresh_en_dev]]):

1. Los tres PDF se generan con el emisor completo y contienen su nombre, RFC y folio.
2. Los tres PDF se generan **con el emisor vacío**, sin lanzar excepción.
3. Los tres PDF se generan **sin logos cargados**, sin lanzar excepción.
4. Una factura timbrada incluye UUID, sellos y cadena original en el PDF.
5. Una factura sin timbrar **no** incluye el bloque de timbre y se genera igual.
6. Una factura cancelada se genera con su timbre completo.
7. Una orden de compra de un proveedor sin contacto, sin RFC y sin correo se genera igual.
8. Una línea de texto libre (`articulo_id` en `null`) se imprime con la Clave SAT vacía.
9. Una línea de un artículo borrado lógicamente conserva su Clave SAT en el PDF.
10. `QrTimbreFiscal` arma la dirección del SAT con los cinco parámetros y el total con 6 decimales.
11. Si falta el RFC del emisor, el PDF se genera con la dirección de verificación como texto y deja
    registro en el log.
12. `<x-pdf.mono-box>` corta un texto de 500 caracteres sin espacios en fragmentos de 120 y escapa
    correctamente un `&`.
13. `PUT /api/v1/emisor` crea la fila la primera vez y la actualiza la segunda; nunca hay dos.
14. `PUT /api/v1/emisor` con un RFC inválido responde `422`.
15. Subir un logo lo guarda en el disco privado y borra el anterior; `DELETE` lo quita del disco.
16. Subir un SVG o un archivo de más de 2 MB responde `422`.
17. **Subir un logo sin haber capturado nunca los datos fiscales** crea la fila y guarda el archivo,
    sin error, y el emisor sigue reportándose como incompleto.
18. La ruta pública firmada del PDF de cotización incluye el emisor (el composer también corre sin
    usuario autenticado).

## Frontend (Vue 3)

### Configuración

La pantalla de [Configuración](014-costo-elaboracion-goma.md) gana una sección **Datos del emisor**,
arriba de los costos de goma:

- Campos: nombre, RFC, régimen fiscal (`<select>` alimentado por `GET /api/v1/catalogos/regimenes-fiscales`,
  que ya existe), domicilio, correo y teléfono.
- Dos cargadores de imagen —**Logo** y **Logo de marca**— con vista previa del archivo actual y
  botón para quitarlo.
- Aviso permanente mientras falten nombre, RFC o régimen: *"Tus documentos se están imprimiendo sin
  datos fiscales del emisor."* Va arriba de la sección: es contexto, no respuesta a una acción.
- Se guarda con su propio botón, independiente del de los costos de goma: son dos formularios
  distintos y mezclarlos obligaría a confirmar el recálculo de precios para cambiar un teléfono.
- **La confirmación de guardado y los errores se muestran junto al botón, no al principio de la
  tarjeta.** El formulario del emisor es largo: un mensaje en la cabecera queda fuera de la pantalla
  en el momento en que el usuario acaba de pulsar Guardar, y desde donde está mirando la pantalla no
  cambia nada. La confirmación desaparece en cuanto se vuelve a editar cualquier campo, para que
  nunca afirme algo que ya dejó de ser cierto.

### Avisos en los tres módulos

Los detalles de cotización, factura y orden de compra muestran una nota junto al botón de descargar
PDF cuando el emisor está incompleto, con enlace directo a Configuración. Sin ese aviso, un documento
sin emisor es un error silencioso que se descubre cuando ya lo recibió el cliente.

El estado del emisor se consulta una vez y se guarda en el store de la sesión; no se pide en cada
pantalla.

### Lo que no cambia

Ninguna otra pantalla. Los formularios, los listados, los diálogos de envío por correo y WhatsApp y
los botones de PDF siguen exactamente como están.

## Fuera de alcance

- **Multiempresa.** Un emisor por instalación. El día que haya dos, esta tabla gana un `user_id` o
  una tabla de empresas, y no antes.
- **Domicilio desglosado** del emisor en calle, número, colonia, municipio, estado y país. Una línea
  de texto libre, como en la referencia.
- **Personalización del formato por el usuario**: colores, tipografías, orden de columnas, textos del
  pie. El formato es uno y está en código.
- **Cambios en los datos de los documentos.** Ni fecha de vigencia en cotizaciones, ni condiciones de
  pago, ni notas al pie configurables, ni leyendas por documento. Nada nuevo se captura.
- **Plantillas de correo.** Los tres mailables siguen igual; solo cambia el PDF adjunto.
- **Complementos de pago.** Su PDF, si algún día lo tiene, es spec aparte.
- **Cadena original reconstruida** en el sistema. Se imprime la que devuelve el PAC.
- **Guardar la imagen del QR** junto a la factura. Se recalcula siempre a partir de los mismos datos.
- **Verificación visual automatizada** del PDF (comparación de imágenes). Las pruebas comprueban que
  el documento se genera y qué dice; cómo se ve lo juzga una persona.
- **Exportación masiva** de documentos a PDF.

## Criterios de aceptación

1. Los tres documentos comparten encabezado, bloque de emisor, tabla de conceptos, bloque de totales
   y pie, con la paleta gris azulada del formato de referencia.
2. El encabezado muestra los dos logos a la izquierda —cada uno hasta 4 × 4 cm, con su proporción
   intacta— y el nombre del documento con su folio a la derecha; sin logos cargados, el resto del
   encabezado conserva su posición.
3. El bloque del emisor imprime nombre, RFC, domicilio y régimen con su descripción, tomados de
   Configuración, en los tres documentos.
4. Factura y cotización muestran al cliente; la orden de compra muestra al proveedor. La factura
   incluye además uso de CFDI, forma y método de pago dentro de ese bloque.
5. Las claves del SAT se imprimen con su descripción, resuelta contra el catálogo del sistema; una
   clave desconocida se imprime sola, sin romper el documento.
6. La tabla de conceptos muestra Cant., Clave SAT, Descripción, Modelo, precio unitario, descuento,
   IVA e importe. La orden de compra titula esa columna "Costo unitario"; los otros dos, "Precio
   unitario".
7. Una línea sin artículo asociado imprime la Clave SAT vacía; una línea de un artículo dado de baja
   conserva la suya.
8. El bloque de totales aparece a la derecha con Subtotal, Descuento, IVA 16%, IVA 0%/Exento, Ajuste
   al peso y Total, omitiendo los renglones en cero, con el Total sobre fondo gris y con la moneda.
9. Una factura timbrada imprime el Timbre Fiscal Digital con QR, UUID, serie del CSD, ambos sellos y
   la cadena original, sin que ninguna tira se salga de la hoja ni quede cortada.
10. El QR se genera sin salida a internet y apunta a la página de verificación del SAT con los cinco
    parámetros correctos.
11. Si el QR no se puede generar, el PDF sale con la dirección de verificación en texto y la falla
    queda en el log.
12. Una factura sin timbrar se genera sin el bloque de timbre; una cancelada lo conserva y muestra su
    estado en rojo.
13. La factura cierra con la leyenda de representación impresa de un CFDI; cotización y orden de
    compra, con la de "no es un comprobante fiscal".
14. Los acentos y las eñes se imprimen correctamente en los tres documentos.
15. Con el emisor sin capturar, los tres PDF se descargan y se envían igual, y el sistema avisa en
    Configuración y en el detalle de cada documento.
16. Configuración permite capturar los datos del emisor y subir, ver y quitar los dos logos; guardar
    el emisor no dispara el recálculo de precios de los costos de goma.
17. Guardar el emisor dos veces no crea dos registros.

## Supuestos asumidos (registro completo)

Los 22 primeros son las asunciones funcionales aceptadas al definir la historia; del 23 al 27, las
cinco adiciones técnicas resueltas.

1. Los tres documentos comparten una sola plantilla base; solo cambian título, contraparte y
   secciones exclusivas.
2. Se reemplaza por completo la apariencia actual (azul/morado) por la del formato de referencia.
3. Siguen siendo hoja carta vertical, generados con dompdf como hoy.
4. El encabezado lleva dos imágenes a la izquierda y el nombre del documento con su folio a la
   derecha.
5. Ambas imágenes las carga el usuario; sin ellas el formato no se descuadra.
6. Los datos fiscales del emisor se capturan en Configuración, no se escriben en el código.
7. La contraparte es el cliente en factura y cotización, y el proveedor en orden de compra.
8. Las claves del SAT se imprimen con su descripción, tomada del catálogo del sistema.
9. La fecha y el estado van en el bloque del emisor, con el estado en verde o rojo.
10. La tabla conserva Modelo e IVA además de las seis columnas de la referencia, porque aquí el IVA
    es por línea.
11. El descuento por línea se muestra como hoy —porcentaje o monto—, con guion largo cuando no hay.
12. El bloque de totales va a la derecha, a un tercio del ancho, con el Total sobre fondo gris.
13. Se conservan los cinco renglones de totales del sistema en lugar del IVA fijo de la referencia.
14. La moneda se imprime junto al total.
15. El Timbre Fiscal Digital aparece solo en facturas timbradas, con QR, UUID y serie a la izquierda
    y sellos y cadena original a la derecha.
16. El QR lleva a la página de verificación del SAT con los datos de esa factura.
17. La factura cierra con la leyenda de CFDI; cotización y orden de compra, con la de documento no
    fiscal.
18. La cotización no imprime fecha de vigencia, porque el sistema no la registra.
19. La orden de compra conserva su fecha de entrega esperada y su bloque de Observaciones.
20. Los datos del comprobante de la factura se integran en el bloque del receptor.
21. Correo y WhatsApp no cambian: solo cambia el PDF adjunto.
22. La única pantalla que cambia es Configuración, más el aviso de emisor incompleto en los tres
    detalles.
23. **El emisor y los logos se capturan en Configuración** (adición 1). El emisor es **uno solo para
    toda la instalación**, en tabla propia y no en el almacén clave→valor, porque el timbrado usa una
    única llave de Facturapi y todos los usuarios emiten con el mismo certificado.
24. **El QR se genera en el propio servidor** con la librería ya instalada (adición 2). Nunca se sale
    a internet, y cuando no se puede generar el PDF sale con la dirección en texto y la falla queda
    registrada.
25. **Una plantilla base compartida más tres vistas cortas** (adición 3). Los datos se pasan por
    `@extends` y el emisor lo inyecta un view composer, para que las rutas públicas firmadas también
    lo reciban.
26. **DejaVu Sans en todo el documento y DejaVu Sans Mono en los sellos** (adición 4), ambas incluidas
    con dompdf. El corte se hace insertando una oportunidad de salto cada 120 caracteres **antes** de
    escapar el texto, más `word-wrap` como red; el 120 y el tamaño de letra van amarrados.
27. **Pruebas automáticas que generan los tres PDF**, incluidos los casos con emisor vacío, sin
    logos, sin timbrar, sin contacto y con artículos borrados (adición 5). Las pruebas verifican que
    el documento se genera y qué contiene; el aspecto visual lo aprueba una persona.
28. Los PDF **nunca se bloquean** por configuración incompleta: siempre se generan, y el sistema
    avisa por otro lado.
29. Los logos se leen de disco y se incrustan en base64; no se referencian por URL ni por ruta
    relativa, para no depender de un `public/` que en producción no existe.
30. No se agrega ni un solo campo nuevo a facturas, cotizaciones u órdenes de compra. Esta spec
    cambia cómo se ve lo que ya se captura.

## Estado de implementación

Implementada el **2026-08-12**. `php artisan test` corre limpio (367 tests, 18 de ellos nuevos en
`tests/Feature/FormatoPdfTest.php`); Pint, ESLint, Prettier, Vitest y `npm run build` también.

Se generó un PDF real de factura timbrada y de orden de compra y se extrajo su texto para
comprobar lo que sí se puede comprobar sin ojos: los acentos salen correctos (*Régimen*, *Físicas*,
*Código*, *Método*, *certificación*, *Cía*), los sellos de 344 caracteres salen completos y
partidos en tres renglones de 120, y el `&` de la cadena original se imprime literal. **No se pudo
verificar visualmente en un visor de PDF** (limitación de entorno: no hay poppler, Ghostscript ni
Imagick disponibles). Queda por aprobar a ojo la maquetación: posición del bloque de totales
flotado, altura de los logos y si el timbre cabe en la primera página en facturas largas.

### Corrección: los campos fiscales tenían que ser nullable

La primera versión de la tabla declaraba `nombre`, `rfc` y `regimen_fiscal` como `NOT NULL`. Subir
un logo sin haber capturado antes los datos fiscales —lo primero que hizo el usuario— reventaba con
`SQLSTATE[HY000] 1364 Field 'nombre' doesn't have a default value`: `subirLogo` intenta crear la
fila con la única columna que conoce.

Las tres columnas pasaron a nullable, por las razones de "Por qué los tres campos fiscales son
nullable". La migración se corrigió en su sitio en vez de encimarle un `ALTER`: la tabla estaba
vacía y la migración no se había commiteado todavía, así que no había historia que respetar.

Las 17 pruebas originales no lo detectaron porque todas creaban el emisor completo antes de tocar
los logos. Se agregó la que faltaba, que es exactamente el camino del usuario.

### Detalles resueltos durante la implementación

- **Se agregó `GET /emisor/logo/{tipo}`**, que la lista de endpoints de esta spec no contemplaba.
  Los logos viven en el disco privado y no tienen URL propia, así que sin esa ruta la vista previa
  de Configuración no tendría de dónde leerlos. El `EmisorResource` devuelve banderas
  (`tiene_logo`, `tiene_logo_marca`) y no el contenido: el emisor se consulta desde las tres
  pantallas de detalle para el aviso, y arrastrar dos imágenes en base64 en cada una costaría
  megabytes por un dato que casi nadie mira ahí.
- **`PUT /emisor` responde `201` la primera vez y `200` después.** Es el comportamiento propio de
  `JsonResource` cuando el modelo acaba de crearse; se dejó como está porque describe con precisión
  lo que pasó.
- **El `withTrashed` va en la carga, no en la relación `articulo()`.** Modificar la relación habría
  hecho que el listado y la API empezaran a devolver artículos borrados; la restricción se aplica
  con un `loadMissing(['lineas.articulo' => fn ($r) => $r->withTrashed()])` en cada `datosPdf()` y
  en las dos cargas de `FacturaController`.
- **La dirección del QR se arma con `http_build_query`, no concatenando.** Un RFC con Ñ —los hay—
  produce una URL inválida si no se codifica, que es lo que hacía el formato de referencia.
- **El composer se registró sobre `pdf.*` y no solo sobre `pdf.documento`.** La vista de factura
  necesita `$emisor` en su propio ámbito para armar el QR, y ese código corre antes de que la
  plantilla base se renderice.
- **La confirmación de guardado se movió junto al botón.** Estaba en la cabecera de la tarjeta,
  copiando el patrón de la sección de costos de goma, pero ese formulario es corto y el del emisor
  no: al guardar, el mensaje aparecía fuera de la pantalla y el usuario no tenía forma de saber si
  había pasado algo. Además ahora desaparece al volver a editar cualquier campo.
- **`SatDescripciones` memoiza también las claves desconocidas.** Con `??=`, una clave que no está
  en el catálogo guarda `null` y se vuelve a consultar en cada llamada, que es justo el caso que
  más conviene recordar.
