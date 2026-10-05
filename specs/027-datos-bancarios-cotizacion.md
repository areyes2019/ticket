# Spec: Datos bancarios en la cotización

**Referencia:** reescritura de [remotas/026-datos-bancarios-cotizacion.md](remotas/026-datos-bancarios-cotizacion.md),
que se diseñó para la arquitectura anterior (Vue 3 + API). Se conservan la tabla propia, las reglas
de captura (normalización, CLABE con dígito verificador, "al menos un número"), el interruptor de
visibilidad, la foto congelada en la cotización, el logo reducido a icono y el bloque en el
encabezado del PDF. Cambia lo que en la remota dependía de la SPA:

| Remota | Aquí | Razón |
|---|---|---|
| `GET/POST/PUT/DELETE /api/v1/datos-bancarios`, `DatoBancarioResource`, store de Pinia | Rutas web bajo `configuracion/datos-bancarios`, controlador que redirige, formulario Blade | Laravel + Blade; no hay frontend que sincronizar |
| `DatosBancariosForm.vue` con un `Dialog` de alta/edición | Páginas `crear` / `editar` con `<x-campo>`, como las cuentas de Tesorería | Un formulario normal no necesita JavaScript |
| Alta en **dos peticiones** (crear el banco, luego subir el logo) | **Una sola** petición `multipart`: banco y logo se guardan juntos en una transacción, como el alta de artículo | El problema de las dos peticiones era del diálogo; un formulario que envía el archivo con los datos no lo tiene |
| Endpoints `POST/DELETE …/logo` | Campo `logo` y casilla `quitar_logo` en el mismo formulario | Mismo patrón que la imagen de artículo |
| Arrastrar tarjetas (eventos nativos) **y** botones subir/bajar, `PUT …/orden` con la lista completa | **Solo botones subir/bajar**, cada uno un formulario que intercambia la posición con el vecino | Los botones ya resuelven todo (y funcionan en el celular); el arrastre sería JavaScript que no aporta nada que falte |
| Extraer `ProcesadorImagen` de `ImagenArticuloService` | Generalizar `GuardadorImagenWebp`, que **ya es** el servicio compartido (artículos y órdenes de trabajo): acepta el lado máximo y la columna | No se extrae lo que ya está extraído |
| Endpoints sin scope, pero con sesión | Mismo criterio, y además **solo el administrador** (gate `editar-emisor` de 026) | Son datos del emisor, uno para toda la instalación; un usuario común no debe cambiar la CLABE de los documentos de los demás |
| `@yield('encabezado-extra')` en `pdf/documento.blade.php` | El mismo `@yield` en [layouts/pdf.blade.php](../resources/views/layouts/pdf.blade.php) | Es la plantilla base de 026 aquí |
| "No hay producción que rescatar" | Sí hay producción (app.prosello.com.mx), pero la regla no cambia: la migración no rellena hacia atrás | Las cotizaciones viejas se imprimen como hoy, sin bloque |

## Historia de usuario

Como usuario del sistema, quiero guardar en Configuración los datos bancarios de mi negocio —nombre
del banco, número de cuenta, tarjeta y clave interbancaria— y que aparezcan **únicamente en la
cotización**, para que el cliente que la recibe sepa a dónde pagarme sin tener que pedírmelo.

- Se pueden agregar varios bancos.
- Cada cuenta puede llevar el **logo de su banco**, reducido hasta quedar como un icono pequeño.

## Objetivo / Alcance

Una sección nueva **Datos bancarios** en [configuracion/edit.blade.php](../resources/views/configuracion/edit.blade.php),
debajo de los datos del emisor, con la lista de cuentas del negocio, y un bloque en el **encabezado
del PDF de cotización** que las imprime.

**No** cambia: factura, orden de compra, Tesorería, cálculo de precios y totales, la pantalla de
detalle de la cotización, la hoja HTML de la bandeja (`_vista-previa`) ni el correo/WhatsApp fuera del
PDF adjunto.

### Estos bancos NO son las Cuentas de Tesorería

`Cuenta` (Tesorería) es **dónde está el dinero**: tiene saldo y movimientos. Los datos bancarios son
**lo que se le dice al cliente para que pague**: no tienen saldo, ni movimientos, ni conciliación.
Tabla propia y **sin relación** con `cuentas`.

## Esquema

### Tabla `datos_bancarios`

Migración `2026_10_11_100000_create_datos_bancarios_table`.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `id` | |
| `nombre_banco` | `string(100)` | Obligatorio |
| `beneficiario` | `string(150)` nullable | A nombre de quién está la cuenta |
| `numero_cuenta` | `string(20)` nullable | Solo dígitos |
| `tarjeta` | `string(16)` nullable | Solo dígitos |
| `clabe` | `string(18)` nullable | Solo dígitos, 18 exactos |
| `logo_ruta` | `string` nullable | Ruta del icono en el disco privado (`local`) |
| `visible_en_cotizaciones` | `boolean` | Default `true` |
| `orden` | `unsignedInteger` | Posición en la lista y en el PDF |
| `timestamps` | | |

- **Sin `user_id`**: como el emisor de 026, es del negocio, uno para toda la instalación.
- **Los números son texto**: un número de cuenta puede empezar con cero, y nunca se suman.
- Modelo `App\Models\DatoBancario` con **`$table = 'datos_bancarios'` explícito** (Eloquent inferiría
  `dato_bancarios`).
- `#[Fillable]`: `nombre_banco`, `beneficiario`, `numero_cuenta`, `tarjeta`, `clabe`,
  `visible_en_cotizaciones`. **Fuera**: `orden` (lo asigna el sistema: `max(orden) + 1` al crear) y
  `logo_ruta` (la escribe solo `GuardadorImagenWebp`).
- Constantes `DIRECTORIO_LOGOS = 'datos-bancarios'`, `LADO_LOGO = 64`, `TAMANO_LOGO_KB = 2048`.
- Atributos `tiene_logo` y `logo_version` (los 8 caracteres al azar del nombre del archivo), como
  `tiene_imagen` / `imagen_version` de `Articulo`.
- Scope `ordenados()` (`orderBy('orden')->orderBy('id')`) y `paraCotizacion()` (visibles, ordenados).

### Columna `cotizaciones.datos_bancarios`

Migración `2026_10_11_100001_add_datos_bancarios_to_cotizaciones_table`: `json` nullable, cast
`array`. La **foto** de los bancos visibles al crear la cotización, en su orden: por banco
`nombre_banco`, `beneficiario`, `numero_cuenta`, `tarjeta`, `clabe`, `logo_ruta`.

- **Fuera de `#[Fillable]`**: la escribe solo `Cotizacion::congelarDatosBancarios()`.
- Se toma **al crear** (`CotizacionController::store`) y **al duplicar** (foto nueva, no la del
  original: la copia sale hoy y cobra con los datos de hoy). **Nunca** al editar, enviar o reimprimir.
- `null` (cotizaciones anteriores a esta spec) o `[]` (creada sin bancos visibles): el PDF sale sin
  bloque, exactamente como hoy. La migración **no rellena hacia atrás**.
- **Del logo se guarda la ruta, no la imagen**: el archivo al que apunta no cambia nunca (reemplazar
  un logo escribe uno nuevo con otro nombre) y eliminar el banco no lo borra.

Costo aceptado: corregir un dato bancario no arregla las cotizaciones ya creadas; para eso se
duplica. El dígito verificador de la CLABE vuelve raro ese caso.

## Captura

### Rutas (web, `auth` + `can:editar-emisor`)

Prefijo `configuracion/datos-bancarios`, nombre `configuracion.datos-bancarios.*`, parámetro
`{datoBancario}`, controlador `DatoBancarioController`:

| Método | URI | Acción |
|---|---|---|
| GET | `crear` | `create` |
| POST | `/` | `store` |
| GET | `{datoBancario}/editar` | `edit` |
| PUT | `{datoBancario}` | `update` |
| DELETE | `{datoBancario}` | `destroy` |
| PATCH | `{datoBancario}/visible` | `alternarVisible` |
| PATCH | `{datoBancario}/mover/{direccion}` | `mover` (`arriba` \| `abajo`) |
| GET | `{datoBancario}/logo` | `logo` |

No hay `index`: la lista vive en Configuración. Todas las acciones regresan a
`configuracion.edit#datos-bancarios` con su mensaje.

### Validación: `DatoBancarioRequest` (alta y edición)

- **Normaliza antes de validar**: a `numero_cuenta`, `tarjeta` y `clabe` se les quitan espacios y
  guiones (pegar `4152 3133 1234 5678` funciona); cadena vacía → `null`.
- `nombre_banco`: requerido, `max:100`. Se permite repetir banco.
- `beneficiario`: opcional, `max:150`.
- `numero_cuenta`: opcional, `digits_between:6,20`.
- `tarjeta`: opcional, `digits_between:15,16` (15 cubre American Express).
- `clabe`: opcional, `digits:18` y `ClabeValida`.
- `visible_en_cotizaciones`: casilla; si no llega, desmarcada.
- `logo`: `bail`, opcional, `file`, `mimes:jpg,jpeg,png,webp`, `max:2048`, `ImagenLegible`
  (comprobado por contenido).
- `quitar_logo`: booleano.
- **Al menos uno** de `numero_cuenta`, `tarjeta` o `clabe` (en `after()`): *"Captura al menos un
  número de cuenta, tarjeta o CLABE."*, en la clave `numeros`, no colgado de un campo.
- `datos()` devuelve lo validado sin `logo` ni `quitar_logo`.

### `ClabeValida` (`app/Rules`)

Sobre los 17 primeros dígitos, pesos `3, 7, 1` cíclicos; de cada producto se suma su último dígito
(`% 10`); el verificador esperado es `(10 - suma % 10) % 10` y debe coincidir con el dígito 18. Si el
valor no son 18 dígitos no opina (eso lo dice `digits:18`). Mensaje: *"La CLABE no es válida: revisa
que esté bien escrita."* Solo la CLABE: la tarjeta no lleva Luhn y la cuenta no tiene formato común.

### El logo

- Lo sube el usuario; el sistema no trae catálogo de logos.
- Se procesa con **`GuardadorImagenWebp`**, que se generaliza:
  - `guardar(Model $modelo, string $directorio, string $contenido, int $ladoMaximo = self::LADO_MAXIMO, string $columna = 'imagen_ruta')`
  - `quitar(Model $modelo, string $columna = 'imagen_ruta')`

  Artículos y órdenes de trabajo siguen llamándolo igual (valores por defecto) y no cambian.
- Se guarda a **64 puntos de lado largo** en WEBP calidad 82, sin ampliar nunca y con la
  transparencia intacta (lo que ya hace el guardador). El original se descarta.
- Nombre `{id}-{8 al azar}.webp` bajo `datos-bancarios/` en el disco privado.
- **Reemplazar** o **quitar** el logo borra el archivo anterior. **Eliminar el banco NO lo borra**:
  las cotizaciones ya creadas lo tienen en su foto.
- Una imagen nueva gana aunque también se marque "Quitar logo", como en artículos.
- **Alta y logo van en una transacción**: si el logo no se puede guardar, el banco no se crea.
- `GET …/logo` sirve el archivo con `Content-Type: image/webp` y `Cache-Control: private`; `404` si
  no tiene logo o el archivo no está. La URL lleva `?v={logo_version}` para que un reemplazo se vea
  sin vaciar la caché.

### Reordenar

Cada banco tiene botones **subir** y **bajar** (el primero no tiene subir; el último no tiene bajar).
`mover` intercambia `orden` con el vecino inmediato en esa dirección, dentro de una transacción. Sin
vecino, no hace nada. Se guarda al pulsar; no hay botón de confirmar.

### El interruptor

`alternarVisible` cambia `visible_en_cotizaciones` con una sola pulsación. Apagado, el banco sigue en
la lista pero deja de imprimirse en las cotizaciones **que se creen** a partir de entonces. Existe
para "este mes cobro por el otro" sin recapturar 18 dígitos de CLABE.

### Eliminar

Borrado físico con confirmación (`data-confirmar`): *"¿Eliminar {banco}? Las cotizaciones ya creadas
lo conservan."* No borra el archivo del logo.

## Pantalla de Configuración

Partial `configuracion/_datos-bancarios.blade.php` dentro de `@can('editar-emisor')`, debajo del
emisor: `<x-card titulo="Datos bancarios" id="datos-bancarios">`.

- Ayuda: *"Se imprimen en el encabezado del PDF de las cotizaciones nuevas."*
- Botón **Agregar banco** → `crear`.
- **Estado vacío**: una línea que explica para qué sirve, en vez de una lista vacía.
- Una fila por banco, en orden: icono (si hay) junto al nombre, beneficiario, los números con su
  etiqueta, y las acciones: subir, bajar, mostrar/ocultar, editar, eliminar.
- Un banco oculto se ve **atenuado** y con la etiqueta *"No se muestra en cotizaciones"*.

Formulario (`crear` / `editar`, `enctype="multipart/form-data"`): los cinco campos con `<x-campo>`;
los numéricos `type="text"` con `inputmode="numeric"` (un `number` pierde el cero inicial y precisión
con 18 dígitos); la casilla "Mostrar en cotizaciones" (marcada por defecto en el alta); el logo
actual con la casilla "Quitar logo" si lo tiene, y el selector de archivo. Los errores salen en el
`_mensajes` de arriba, incluido el de "al menos un número".

## PDF de la cotización

`layouts/pdf.blade.php` gana **`@yield('encabezado-extra')`** debajo de `@yield('meta')`, en la celda
derecha del encabezado. **Solo `cotizaciones/pdf.blade.php` lo llena**; factura y orden de compra
heredan el hueco vacío y salen idénticas.

```
                                  COTIZACIÓN
                                    COT-0042
                                  Fecha 04/10/2026

                                  DATOS BANCARIOS
                              ──────────────────
                              [icono] BBVA
                              Rosa Martínez
                              Cta: 0123456789
                              Tarjeta: 4152313312345678
                              CLABE: 012180001234567890
```

- Título "Datos bancarios" como `.titulo-seccion` (versalitas, `#2c3e50`) con filete inferior.
- Por banco: nombre en negritas con el **icono a la izquierda en una tabla de dos celdas** (dompdf no
  alinea bien una imagen en línea dentro de un párrafo a la derecha); debajo beneficiario si lo hay y
  un renglón por número capturado: `Cta:`, `Tarjeta:`, `CLABE:`. Un campo vacío no imprime renglón.
- Icono a **5 mm de alto** con ancho proporcional (en milímetros, calculados en PHP como el logo de
  026). Sin logo, solo el nombre, sin celda vacía.
- Números completos, sin enmascarar. Alineado a la derecha, **7.5 pt**.
- **El icono se incrusta en base64**, resuelto por `Cotizacion::datosBancariosParaPdf()` (la vista
  no toca el disco): devuelve la foto con `logo` (data URI) y `logo_ancho_mm` por banco, o `null`.
  Si el archivo no está, el banco sale sin icono, queda un `Log::warning` y el PDF se genera igual.
- Sin bancos en la foto: ni título ni filete; el encabezado queda como hoy.
- Llega a todos los caminos (descarga, vista previa, correo, compartir) porque sale de la propia
  cotización, vía `GeneradorPdfCotizacion::datos()` (`'datosBancarios' =>`).
- **Ningún comentario CSS del `<style>` de la base menciona "Datos bancarios"**: la hoja viaja a los
  tres documentos y la prueba de que la factura no lo imprime lo encontraría ahí (lección de la
  remota).

## Archivos

**Nuevos**

- `database/migrations/2026_10_11_100000_create_datos_bancarios_table.php`
- `database/migrations/2026_10_11_100001_add_datos_bancarios_to_cotizaciones_table.php`
- `app/Models/DatoBancario.php`, `database/factories/DatoBancarioFactory.php`
- `app/Rules/ClabeValida.php`
- `app/Http/Requests/DatoBancarioRequest.php`
- `app/Http/Controllers/DatoBancarioController.php`
- `resources/views/configuracion/_datos-bancarios.blade.php`
- `resources/views/configuracion/datos-bancarios/{crear,editar,_formulario}.blade.php`
- `tests/Feature/DatosBancariosTest.php`

**Modificados**

- `GuardadorImagenWebp`: lado máximo y columna como parámetros con valor por defecto.
- `Cotizacion`: cast, `congelarDatosBancarios()`, `datosBancariosParaPdf()`.
- `CotizacionController`: `store` y `duplicar` congelan.
- `GeneradorPdfCotizacion::datos()`: pasa `datosBancarios`.
- `layouts/pdf.blade.php` (`@yield` y estilos), `cotizaciones/pdf.blade.php` (la sección).
- `ConfiguracionController::edit` (lista), `configuracion/edit.blade.php` (`@include`).
- `routes/web.php`, `public/css/app.css` (fila atenuada, icono).

## Pruebas

Pest sobre SQLite en memoria (`RefreshDatabase`), `Storage::fake('local')` en las de logo.

1. Alta con todos los campos; entra al final de la lista (`orden`).
2. Sin nombre no se guarda; sin ningún número no se guarda y el mensaje pide al menos uno.
3. CLABE con verificador incorrecto se rechaza; con el correcto se guarda. 17 o 19 dígitos se
   rechazan.
4. `4152 3133 1234 5678` se guarda como `4152313312345678`; una cuenta con cero inicial lo conserva.
5. Dos bancos con el mismo nombre se guardan.
6. Edición, interruptor, eliminación; subir/bajar intercambian con el vecino y no hacen nada en los
   extremos.
7. Un usuario no administrador recibe 403 en todas las rutas y no ve la sección; sin sesión, a login.
8. Logo: un PNG de 1000 px se guarda como WEBP de 64 px de lado largo; uno de 40 px no se amplía; un
   PNG transparente conserva el alfa; un archivo que no es imagen (aunque termine en `.png`) se
   rechaza y el banco no se crea; reemplazar borra el anterior; "quitar" lo borra; eliminar el banco
   no lo borra; la ruta del logo responde 404 sin logo.
9. Crear una cotización congela los visibles en orden, sin los ocultos; editarla no cambia la foto;
   duplicar toma foto nueva.
10. El HTML del PDF muestra el bloque con renglones solo de lo capturado; sin bancos no hay bloque;
    cambiar o eliminar el banco no cambia una cotización creada antes.
11. Con logo, el HTML lleva `data:image/webp;base64`; si el archivo se borró, sale el nombre sin icono
    y queda un warning.
12. Factura y orden de compra no contienen "Datos bancarios".
13. El PDF de cotización con bancos se genera (`%PDF`).
14. Las pruebas existentes de imágenes de artículo y órdenes de trabajo pasan sin cambios.

## Fuera de alcance

Datos bancarios en factura u orden de compra, en el detalle de la cotización o en el texto del
correo/WhatsApp; elegir bancos por cotización; relación con Tesorería; actualizar la foto de una
cotización ya creada; rellenar hacia atrás; enmascarar o cifrar; Luhn, SWIFT, moneda, sucursal;
catálogo de logos o deducir el banco por la CLABE; recortar el logo; arrastrar para reordenar; bancos
por usuario.

## Criterios de aceptación

1. En Configuración (administrador) existe la sección "Datos bancarios", separada del emisor y de
   los mensajes, con su propio guardado.
2. Se dan de alta varios bancos y se editan y eliminan uno por uno, con confirmación al eliminar.
3. Sin nombre o sin ningún número no se guarda; la CLABE se valida con su dígito verificador.
4. Los números se limpian de espacios y guiones y conservan el cero inicial.
5. El PDF de una cotización creada con bancos visibles muestra el bloque en el encabezado, a la
   derecha bajo el folio, en el orden de Configuración y con renglón solo por dato capturado.
6. Un banco oculto no aparece en las cotizaciones creadas después de ocultarlo.
7. Sin bancos visibles, la cotización se imprime como hoy.
8. Cambiar, ocultar o eliminar un banco no cambia el PDF de una cotización ya creada; duplicar toma
   los datos vigentes.
9. Factura y orden de compra no cambian.
10. Subir/bajar cambia el orden y se refleja en la siguiente cotización.
11. El logo se guarda como icono WEBP de 64 px máximo con su transparencia; se reemplaza y se quita
    desde el formulario; el PDF lo imprime a 5 mm a la izquierda del nombre; si el archivo falta, el
    PDF sale igual sin icono.
12. Solo el administrador captura los datos bancarios.

## Supuestos asumidos

1. Solo el administrador los edita (mismo gate que el emisor); cualquier usuario ve el resultado en
   sus PDF.
2. Formulario en página propia, no en diálogo: es la forma en que ya se capturan las cuentas.
3. Reordenar solo con subir/bajar.
4. La foto incluye la ruta del logo; el banco eliminado conserva su archivo.
5. Las cotizaciones de producción anteriores a esta spec se imprimen sin bloque.

## Estado de implementación

Implementada el **2026-10-04**. `php artisan test` corre limpio (1179 pruebas, 41 en
`tests/Feature/DatosBancariosTest.php`); Pint también. Las dos migraciones se aplicaron a la base de
trabajo. Se generó un PDF real de cotización con dos bancos (uno con logo PNG transparente) y se
revisó renderizado a imagen.

### Detalles resueltos durante la implementación

- **`orden` lo asigna el evento `creating` del modelo** (`max(orden) + 1`), no el controlador: así
  la fábrica de pruebas y cualquier alta futura entran al final sin repetir la regla.
- **`mover` renumbera toda la lista** (1, 2, 3…) tras el intercambio, en vez de solo cruzar dos
  valores: si alguna vez dos bancos quedaran con el mismo `orden`, el intercambio no tendría efecto.
- **Bloque al 55 % derecho de la celda** (`margin-left: 45%`): con el ancho completo, el filete del
  título cruzaba casi toda la hoja y parecía una segunda línea del encabezado.
- **`DatoBancario::CAMPOS_FOTO`** fija qué se copia a la foto; la prueba lo compara con las llaves
  guardadas.
- **No verificado en navegador**: la lista de Configuración, los botones subir/bajar y el formulario
  con el logo están cubiertos por pruebas de HTTP, pero falta mirarlos en pantalla.
