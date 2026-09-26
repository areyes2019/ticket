# Spec: Alta de clientes desde la Constancia de Situación Fiscal (QR del SAT)

**Alcance:** Extiende [005-gestion-clientes.md](005-gestion-clientes.md). No toca ninguna otra
historia.

**Referencia:** reescritura de [remotas/016-constancia-situacion-fiscal-qr.md](remotas/016-constancia-situacion-fiscal-qr.md),
que se diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). La lógica del servidor y las
lecciones aprendidas con constancias reales se conservan; la parte del navegador y el protocolo
entre navegador y servidor se rehicieron para Laravel + Blade + JavaScript nativo.

## Historia de usuario

Como usuario del sistema de facturación, quiero subir la Constancia de Situación Fiscal de un
cliente —en PDF, o fotografiada con el celular— y que el sistema lea el código QR que el SAT
imprime en ella, consulte los datos oficiales del contribuyente y rellene solo el formulario de
alta, para no tener que teclear a mano RFCs, razones sociales y domicilios largos ni arriesgarme a
equivocarme en un carácter.

## Objetivo / Alcance

Agregar a las pantallas de alta y edición de clientes una zona de carga de archivo que extrae los
datos fiscales del contribuyente y los precarga en el formulario, **sin guardar nada
automáticamente**: el usuario revisa, corrige si hace falta y guarda con el botón de siempre.

Se implementa sobre la arquitectura monolítica de [001-inicio-proyecto.md](001-inicio-proyecto.md):

- **Laravel resuelve todo el trámite**: leer el QR, validar su dominio, consultar al SAT, leer el
  texto del PDF, resolver catálogos, armar el domicilio y detectar duplicados.
- **JavaScript solo hace tres cosas**: recibir el archivo (arrastrar o elegir), intentar leer el QR
  de una **foto** con el lector nativo del navegador, y escribir en el formulario lo que devolvió
  el servidor. Es un solo archivo en `public/js/`, sin compilación.
- **Una sola petición AJAX** (Axios) a una ruta web con sesión. No hay API, ni Sanctum, ni Vue, ni
  npm.

El camino principal es el **código QR**: la constancia trae impresa una dirección del SAT que
identifica al contribuyente sin ambigüedad. Leer esa dirección y preguntarle al SAT elimina de raíz
el problema de confundir una `O` con un `0` en un RFC.

Como la infraestructura del SAT se cae y entra en mantenimiento con frecuencia, el sistema **no
depende al 100% de ella**: cuando el SAT no responde y el archivo es un PDF, el servidor extrae los
datos del texto del propio documento y los entrega marcados para revisión (**Estrategia B**).

**No** incluye validar la vigencia del contribuyente, archivar constancias, reconocimiento de
caracteres (OCR), ni tocar [Proveedores](004-gestion-proveedores.md).

### El flujo completo de un vistazo

```
[ Usuario suelta la CSF (PDF / JPG / PNG) ]
              │
              ▼
  NAVEGADOR: ¿es una foto y el navegador tiene BarcodeDetector?
    ├── SÍ ──► intenta leer el QR; si lo logra, lo agrega como qr_url
    └── NO ──► no hace nada más
              │
              ▼
  UNA petición: POST /clientes/constancia  (archivo + qr_url opcional)
              │
              ▼
  SERVIDOR: obtiene la dirección del QR
    1º qr_url si vino
    2º dentro del PDF (imagen del QR guardada tal cual)
    3º en la foto (chillerlan/php-qrcode sobre GD)
              │
              ▼
  ¿https y host terminado en sat.gob.mx?
    ├── NO ──► 422 QR_NO_OFICIAL (sin consultar la dirección)
    └── SÍ ──► del D3 salen idCIF y RFC: la identidad queda fijada
                 │
                 ▼
               ¿SAT marcado como caído? ── SÍ ──┐
                 │ NO                           │
                 ▼                              │
               consulta al SAT (5 s, sin reintento)
                 ├── contesta y se entiende ──► SAT_QR_DIRECT ✅ (caché 24 h)
                 ├── contesta pero falta algo ─► lo que vino + Estrategia B para el resto
                 │                               (NO se marca caído; se deja log)
                 └── no contesta ─► marca "caído" 2 min ─┤
                                                         ▼
                                              ESTRATEGIA B (servidor)
                                   ┌──────────────────┴──────────────────┐
                              es PDF con texto                     es foto / PDF escaneado
                              PDF_TEXTO + aviso ámbar              QR_RFC: solo el RFC del QR
                                                                   + aviso "captura el resto"
```

La diferencia entre "no contesta" y "contesta pero falta algo" es la que sostiene todo lo demás. Un
campo que no se supo leer es un problema nuestro, no una caída del SAT: tratarlo como caída apaga la
consulta oficial durante dos minutos para **todos** los usuarios, y como el fallo se repite en cada
constancia, la consulta oficial deja de ocurrir nunca. La marca de caída se reserva para lo que de
verdad es una caída: tiempo agotado, error de red o respuesta con código de error HTTP.

### Los niveles de confianza

| Fuente | Qué es | Confianza | Qué ve el usuario |
| --- | --- | --- | --- |
| `SAT_QR_DIRECT` | El SAT respondió en vivo | `oficial` | Nada especial: flujo normal |
| `PDF_TEXTO` | Texto copiado del PDF del SAT | `documento` | Aviso ámbar: verificar vigencia |
| `QR_RFC` | Solo el RFC, leído del QR | `parcial` | Aviso ámbar: capturar el resto a mano |

El texto de un PDF se copia carácter por carácter y es exacto; su único riesgo es que la constancia
sea vieja y el contribuyente ya se haya mudado. El RFC del QR es exacto siempre, porque viene
codificado para que lo lea una máquina. Por eso ninguna fuente exige confirmación adicional antes
de guardar: no hay datos adivinados.

## Backend (Laravel)

### Dependencias nuevas (Composer)

- **`chillerlan/php-qrcode`** (v6) — lectura del QR sobre **GD** (`(new QRCode)->readFromBlob()`).
- **`smalot/pdfparser`** — texto interno del PDF y trozos con su coordenada.
- **`symfony/dom-crawler`** — recorrido del HTML del SAT. `symfony/css-selector` ya está instalado.
- El cliente HTTP de Laravel ya está disponible.

Se verificó el entorno: el PHP de Laragon tiene `gd`, `zlib`, `mbstring` y `fileinfo`, y **no**
tiene `imagick`. No se usa `spatie/pdf-to-image`, ImageMagick, Ghostscript ni Tesseract: el QR se
saca del PDF sin convertir la página a imagen.

**No se agrega ninguna dependencia de frontend.**

### Ruta (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]` y **antes** de
`Route::resource('clientes', …)`, junto a `clientes/buscar`:

```php
Route::post('clientes/constancia', ConstanciaController::class)
    ->middleware('throttle:10,1')
    ->name('clientes.constancia');
```

| Método | URL | Acción | Nombre |
|---|---|---|---|
| POST | `/clientes/constancia` | analiza la constancia y devuelve JSON | `clientes.constancia` |

- Es un endpoint AJAX específico de la pantalla, como permite 001; responde JSON.
- Usa la sesión web y el token CSRF que Axios ya envía (`public/js/app.js`).
- `throttle:10,1` limita por usuario autenticado: 10 constancias por minuto. Importa porque el
  bloqueo que aplicaría el SAT caería sobre la IP del servidor completo.

### Controlador (`ConstanciaController`, invocable)

Delgado: recibe el `AnalizarConstanciaRequest`, llama a `ConstanciaFiscalService::analizar()` y
convierte el resultado en respuesta JSON. No contiene reglas de negocio.

No requiere Policy: el endpoint no lee ni modifica ningún `Cliente`; la búsqueda de duplicados se
hace siempre sobre `$request->user()->clientes()`.

### Servicios (`app/Services/Constancia/`)

| Clase | Responsabilidad |
|---|---|
| `ConstanciaFiscalService` | Orquesta el orden de resolución, el circuito abierto y la caché |
| `QrLector` | `leerDePdf()` y `leerDeImagen()`; devuelve la dirección del validador o `null` |
| `IdentidadQr` | Valida dominio y esquema; extrae idCIF y RFC del parámetro `D3` |
| `SatHtmlExtractor` | Recoge los pares etiqueta/valor del HTML del SAT |
| `ConstanciaPdfExtractor` | Estrategia B: pares etiqueta/valor del texto del PDF |
| `ParesPorPosicion` | Reconstruye renglones y celdas del PDF a partir de coordenadas |
| `MapeadorCampos` | Alias de etiquetas, normalización, régimen, CP y domicilio |
| `ResultadoConstancia` | Objeto con `fuente`, `confianza`, `advertencias`, `data` |

`MapeadorCampos` es el **único** lugar donde viven los alias de etiquetas. No existe una copia en
JavaScript.

### Configuración (`config/services.php`, clave `sat`)

```php
'sat' => [
    'dominio' => 'sat.gob.mx',
    'timeout' => 5,          // segundos, sin reintentos
    'caida_segundos' => 120, // circuito abierto
    'cache_horas' => 24,
],
```

### Orden de resolución en el servidor

1. **Obtener la dirección del QR.** Si vino `qr_url`, se usa. Si no, se busca **dentro del PDF** y
   después en la **foto**. Si no aparece: `422 QR_NO_LEGIBLE`.
2. **Validar el dominio.** Debe ser `https` y su host debe ser `sat.gob.mx` o terminar exactamente
   en `.sat.gob.mx`. Cualquier otra cosa: `422 QR_NO_OFICIAL`, **sin consultarla**. Es lo que
   impide que un QR falso dirija al servidor a hacer peticiones arbitrarias.
3. **Fijar la identidad.** Del `D3` se leen idCIF y RFC; el RFC se valida con `phpcfdi/rfc`
   (`Rfc::parseOrNull`). Si no parsea: `422 QR_NO_LEGIBLE`.
4. **¿El SAT está marcado como caído?** Si la bandera `csf:sat:caido` está en caché, se salta el
   paso 5 sin esperar.
5. **Consultar al SAT** (o tomar la respuesta de la caché) y extraer los datos del HTML.
   - Todo lo necesario vino → `200` con `SAT_QR_DIRECT`.
   - Contestó pero faltan campos → se usa lo que vino y se completa con el paso 6.
6. **Estrategia B**: si el archivo es un PDF con texto, se extraen los campos que falten →
   `200` con `PDF_TEXTO` (si el SAT no aportó nada) o `SAT_QR_DIRECT` con advertencias (si
   aportó parte).
7. **Solo el RFC**: si no hay PDF con texto (foto, o PDF escaneado) → `200` con `QR_RFC`, solo
   `rfc` en `data` y advertencia.
8. Siempre que haya RFC, se busca **cliente duplicado** del usuario.

### El QR se busca dentro del PDF, no en una foto del PDF

El código QR de una constancia **viaja dentro del PDF como imagen guardada**: se saca tal cual, sin
convertir la página a foto y sin perder un solo punto. En constancias reales de persona física y
moral, el QR de la primera página es una imagen cuadrada de 150 × 150.

- Solo se examinan las imágenes **cuadradas** de al menos 50 puntos por lado; logotipos y firmas
  se descartan sin decodificarlos.
- **Una constancia trae más de un QR.** El de la primera página apunta al validador; el de la
  última codifica la cadena original del sello digital. Se distinguen **por el contenido**: vale el
  QR cuyo `D3` tiene la forma `idCIF_RFC`. Quedarse con "el primero que se lea" fallaría en cuanto
  cambie el orden de las imágenes.
- Hay que **deshacer la predicción PNG** de las imágenes (unas treinta líneas): el SAT guarda el QR
  en crudo, pero cualquier PDF reimpreso o guardado con otra herramienta usa predictor, y sin
  deshacerlo el código sale ilegible.
- La lectura se envuelve en `try`: "esta imagen no trae un QR legible" es un resultado previsto,
  no una falla.

### La identidad sale del propio QR

```
https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=16040688444_OAMN910602UXA
```

`D3` son el **idCIF** y el **RFC** separados por guion bajo (verificado en constancias reales de
los dos tipos de contribuyente). El RFC ya **no depende de que el SAT conteste**. Si el HTML del SAT
trae un RFC distinto al del QR, manda el del QR y se agrega una advertencia.

El idCIF no se guarda ni se muestra: solo sirve para la consulta y para reconocer el QR correcto.

### Consulta al SAT

- `Http::timeout(5)` y `connectTimeout(5)`, **sin reintentos**: ya existe una alternativa lista.
- **Circuito abierto por 2 minutos, solo por caídas de verdad**: `ConnectionException`, tiempo
  agotado o código HTTP de error → `Cache::put('csf:sat:caido', true, 120)`. Mientras dure, las
  constancias siguientes van directo a la Estrategia B sin esperar. Pasados los 2 minutos se
  vuelve a intentar solo.
- Que el SAT **conteste** y no se le entienda del todo **no abre el circuito**. Se registra un
  `Log::warning('Constancia: respuesta del SAT incompleta', [...])` con las etiquetas que no se
  reconocieron (nunca con el RFC ni datos del contribuyente). Es lo que delata a tiempo un cambio
  de etiquetas del SAT, cuyo remedio es agregar un alias en `MapeadorCampos`.
- **Caché de 24 horas** de las respuestas exitosas, con clave derivada del hash de la dirección del
  QR. Los fallos no se cachean. Se usa el `CACHE_STORE` actual (`database`).

### Extracción del HTML del SAT (`SatHtmlExtractor`)

- **Se lee explícitamente como HTML** (`$crawler->addHtmlContent($html, 'UTF-8')`): el validador
  antepone una cabecera `<?xml …?>` al `<!DOCTYPE html>`, y si se deja adivinar, `DomCrawler` lo
  toma por XML, ningún `<tr>` coincide y devuelve cero filas **sin error**.
- **No usa selectores atados a la maquetación**: recoge todos los pares "etiqueta: valor" de las
  filas de dos celdas y, como respaldo, del texto plano; luego busca por alias.
- **RFC**: aparece dentro de una frase (*"El RFC: XXX, tiene asociada la siguiente información"*),
  no como par; de ahí solo se toma para contrastar con el del QR.
- **Nombre / Razón social**: persona moral → denominación o razón social; persona física →
  `nombre + apellido paterno + apellido materno`. Se conservan mayúsculas y ortografía del SAT.
- **Varios regímenes**: el SAT los publica como filas repetidas con la etiqueta `Régimen:`. Se
  recogen **todos** (para los demás campos vale la primera aparición).

### Estrategia B: texto del PDF (`ConstanciaPdfExtractor` + `ParesPorPosicion`)

El texto "de corrido" que devuelve Smalot se equivoca de dos maneras en la constancia:

- **Pierde los espacios dentro de un valor** ("CIUDAD OLMECA" → `CIUDADOLMECA`). No se arregla con
  el umbral de espaciado de la librería (se probó de −50 a −1): cada palabra es un trozo colocado
  por coordenada, sin espacio que copiar.
- **Junta las dos columnas del domicilio** en un renglón (`Código Postal: 96535` y `Tipo de
  Vialidad: CALLE`).

Por eso el texto se reconstruye a partir de los **trozos con su coordenada**:

- Los trozos del mismo renglón se ordenan por X y se unen **con un espacio**.
- Un hueco mucho mayor que el de una palabra es **el salto a la otra columna**: empieza una celda
  nueva. Umbrales medidos en constancias reales: entre palabras ≈ 5 unidades, entre columnas ≈ 180;
  el corte está en **30**. El ancho de carácter se sobreestima a propósito (**7**): pasarse junta
  trozos en la misma celda; quedarse corto partiría un valor.
- Un renglón **sin dos puntos** que sigue a otro con valor es su **continuación** ("VERACRUZ DE
  IGNACIO DE LA" + "LLAVE").
- **El valor termina donde termina su celda**, nunca en los dos puntos siguientes.
- Alrededor de los dos puntos se usan espacios horizontales (`[ \t]`), **no** `\s*`: este último
  cruza el salto de línea y una etiqueta sin valor (`Número Interior:`) se queda con el renglón
  siguiente.
- El texto puede llegar en **Windows-1252**; `MapeadorCampos::aUtf8` lo convierte antes de
  normalizar, o "Denominación" no coincidiría con ningún alias.
- Si el PDF **no tiene texto** (escaneo dentro de un PDF), el extractor devuelve vacío.
- Los campos no encontrados se devuelven vacíos: mejor un campo en blanco que un dato inventado.

### Mapeo de campos (`MapeadorCampos`)

- **Etiquetas**: se comparan reducidas a su esqueleto (sin acentos, sin mayúsculas, sin espacios),
  así `NombredelaColonia` y `Nombre de la Colonia` son la misma llave. Solo se aceptan etiquetas de
  la lista `ALIAS`; la longitud mínima es **2** por `CP:`. Alias ya conocidos que deben estar:
  `Denominación o Razón Social`, `Denominación/Razón Social`, `CP`, `Código Postal`, `Régimen`.
- **Régimen fiscal**: ni el SAT ni la constancia publican el código numérico, solo la descripción
  (*"Régimen de las Personas Físicas con Actividades Empresariales y Profesionales"*). Se busca el
  caso del enum `App\Enums\RegimenFiscal` cuya `descripcion()` esté **contenida** en la del SAT,
  comparando esqueletos; se devuelve `->value` (`'612'`). Si el texto trae un código numérico
  válido, también se acepta. Varios regímenes: se devuelven todos en `regimenes_disponibles`, se
  propone el primero en `regimen_fiscal` y se agrega una advertencia.
- **Código postal**: se valida **solo el formato** (5 dígitos), igual que `ClienteRequest`. La
  validación contra `c_CodigoPostal` sigue diferida a facturación (ver 005). Si no son 5 dígitos, se
  deja vacío y se avisa.
- **Domicilio**: vialidad + número exterior + número interior + colonia + municipio + estado en una
  línea para `direccion_comercial`:

  ```
  JAGUARES 5208, COL CIUDAD OLMECA, COATZACOALCOS, VERACRUZ DE IGNACIO DE LA LLAVE
  ```

  Sin comas sueltas por componentes vacíos, truncada a 255. **No se agregan columnas nuevas.**

### Detección de cliente duplicado

Con el RFC resuelto se busca en `$request->user()->clientes()` (sin borrados). Si existe:

```json
"cliente_existente": {
  "id": 42,
  "razon_social": "PANDA CONNECT LOGISTICS SA DE CV",
  "url_editar": "https://…/clientes/42/editar"
}
```

`url_editar` se genera con `route('clientes.edit', $cliente)`: el JavaScript no arma URLs. El
backend **no bloquea nada**; la regla `unique` de `ClienteRequest` sigue impidiendo el duplicado
real al guardar.

### Nada se guarda en disco

La petición **no escribe archivos**: se leen desde el `UploadedFile` temporal de PHP, que se
descarta al terminar la petición, salga bien o mal. No se persiste bitácora ni marca en el cliente.
Lo único que sobrevive es la caché de 24 h de la respuesta del SAT y la bandera de caída.

### Validaciones (`AnalizarConstanciaRequest`)

- `authorize()`: `true` (la ruta ya exige sesión).
- `archivo`: `nullable`, `file`, `mimes:pdf,jpg,jpeg,png` (por contenido real, no por extensión),
  `max:10240`.
- `qr_url`: `nullable`, `string`, `url`, `max:2048`.
- `after()`: si no viene ninguno de los dos, error "Sube la Constancia de Situación Fiscal.".
- `attributes()` en español.

### Formas de respuesta

Todos los errores de negocio incluyen `mensaje`, ya redactado en el servidor, para que el
JavaScript solo lo muestre.

**Éxito (`200`)**

```json
{
  "fuente": "SAT_QR_DIRECT",
  "confianza": "oficial",
  "aviso": null,
  "advertencias": [],
  "data": {
    "rfc": "PME120315AB9",
    "razon_social": "PANDA CONNECT LOGISTICS SA DE CV",
    "regimen_fiscal": "601",
    "regimenes_disponibles": [
      { "clave": "601", "texto": "601 – General de Ley Personas Morales" }
    ],
    "codigo_postal_fiscal": "38000",
    "direccion_comercial": "AV TECNOLOGICO 105, COL INDUSTRIAL, CELAYA, GUANAJUATO"
  },
  "cliente_existente": null
}
```

`confianza`: `oficial` (`SAT_QR_DIRECT`), `documento` (`PDF_TEXTO`), `parcial` (`QR_RFC`).
`aviso` es el texto del aviso ámbar según la fuente (ver "Los avisos según la fuente"), ya
redactado en el servidor; es `null` para `SAT_QR_DIRECT`.
Los campos no obtenidos se omiten de `data`. `advertencias` son textos listos para mostrar (varios
regímenes, CP inválido, RFC del SAT distinto al del QR, campos no encontrados).

**Sin datos (`422`)**

```json
{ "error": "QR_NO_LEGIBLE", "mensaje": "No se pudo leer el código QR. …" }
```

| Código | Cuándo | Mensaje |
| --- | --- | --- |
| `QR_NO_LEGIBLE` | No se encontró el QR o su `D3` no trae un RFC válido | No se pudo leer el código QR. Intenta con el PDF original, o con una foto más de frente y con buena luz. |
| `QR_NO_OFICIAL` | Apunta fuera de `sat.gob.mx` o no es `https` | El código QR de este documento no apunta al SAT. Verifica que sea una Constancia de Situación Fiscal oficial. |

Los errores de validación del request usan el formato estándar de Laravel (`422` con `errors`).
El `429` es la respuesta estándar de `throttle`.

## Vistas (Blade)

### `clientes/_constancia.blade.php`

Parcial nuevo, incluido en `clientes/_formulario.blade.php` **entre** `_mensajes` y el `<form>`
del cliente, así aparece en `/clientes/crear` y `/clientes/{cliente}/editar`. Queda **fuera** del
`<form>` del cliente para que el archivo nunca se envíe al guardar.

```blade
<div hidden
     data-constancia="{{ route('clientes.constancia') }}"
     @isset($cliente) data-cliente-id="{{ $cliente->id }}" @endisset>
    <x-card titulo="Constancia de Situación Fiscal">
        {{-- zona de arrastre + <x-campo tipo="file" …> --}}
        {{-- estado (aria-live) --}}
        {{-- <x-alerta tipo="advertencia" hidden data-constancia-aviso> --}}
        {{-- <x-alerta tipo="error" hidden data-constancia-error> --}}
        {{-- aviso de cliente existente con dos <x-boton> --}}
    </x-card>
</div>
```

- Empieza con `hidden`; el JavaScript lo quita al cargar. **Sin JavaScript no se ve** y el
  formulario de 005 funciona igual.
- Solo componentes de 003 (`x-card`, `x-campo`, `x-alerta`, `x-boton`, `x-icono`): lo verifica
  `EstiloUniformeTest`. El `<input type="file">` se pinta con `<x-campo tipo="file"
  accept=".pdf,.jpg,.jpeg,.png">` (la rama genérica del componente ya lo soporta).
- Texto en reposo:

  > Arrastra aquí la Constancia de Situación Fiscal, o haz clic para elegir el archivo.
  > PDF, JPG o PNG · máximo 10 MB

- Estados, en un `<p aria-live="polite">`: *Leyendo el documento…* → *Consultando al SAT…*.
- Los avisos y el bloque de cliente existente están **pre-renderizados y ocultos**; el JavaScript
  solo cambia su texto y los muestra u oculta (mismo patrón que `data-busqueda-error` de 005).
- Los estilos nuevos (zona de arrastre, estado "arrastrando encima", campo precargado) van en
  `public/css/app.css`, con esquinas rectas según 003.
- `clientes/crear.blade.php` y `clientes/editar.blade.php` agregan
  `@push('scripts') <script src="{{ asset('js/constancia-fiscal.js') }}"></script> @endpush`.

## JavaScript (`public/js/constancia-fiscal.js`)

Archivo nuevo, IIFE como `busqueda-dinamica.js`, activado por `[data-constancia]`. Sin librerías
nuevas: usa el Axios ya configurado en `app.js`.

1. **Recibir el archivo** por arrastre o por el `<input type="file">`. Uno a la vez. Si no es
   PDF/JPG/PNG o pesa más de 10 MB, se rechaza **antes de subir**, con el límite en el mensaje.
2. **Solo si es foto** y existe `window.BarcodeDetector`: intentar leer el QR
   (`createImageBitmap` + `detect`). Si lo logra, se agrega `qr_url`. Nunca lanza: un lector
   ausente es un camino previsto. Para PDF no se intenta nada en el navegador; el servidor lee el
   QR de dentro del archivo.
3. **Una petición**: `axios.post(url, FormData{ archivo, qr_url? })`.
4. **Cliente existente**: si viene `cliente_existente` y su `id` es distinto de `data-cliente-id`,
   se muestra el bloque "Ya tienes registrado a **{razón social}** con este RFC." con
   **Abrir su ficha** (`url_editar`) y **Precargar de todos modos**. Si es el mismo cliente que se
   edita, se precarga sin preguntar.
5. **Precarga**: por cada clave de `data` se escribe el valor en el campo del formulario con ese
   `name` (`rfc`, `razon_social`, `regimen_fiscal`, `codigo_postal_fiscal`,
   `direccion_comercial`) y se dispara `input`/`change`. Los campos precargados reciben una clase
   que los resalta; la pierden al editarlos.
6. **Avisos** según `fuente` (ver abajo) y `advertencias` como lista dentro del mismo aviso.
7. **Errores**:
   - `422` con `error`: mostrar `mensaje`.
   - `422` con `errors` (validación): mostrar el primero.
   - `429`: "Vas muy rápido. Espera un momento antes de subir otra constancia."
   - `401` / `419`, o respuesta redirigida al login (usuario suspendido): recargar la página, igual
     que `busqueda-dinamica.js`.
   - Cualquier otro: "No se pudo leer el documento. Captura los datos manualmente."

En todos los casos el formulario queda intacto y utilizable.

### Precarga del formulario

- Los campos que la constancia trae se **reemplazan**, incluso si el usuario ya había escrito algo.
- **`nombre_comercial`, `nombre_contacto`, `correo` y `telefono` no se tocan nunca.**
- Todos los campos quedan **editables**. **Nunca se guarda solo.**

### Los avisos según la fuente

Con `<x-alerta tipo="advertencia">`, dentro de la card de la constancia:

- **`SAT_QR_DIRECT`** — sin aviso propio; solo las `advertencias`, si hay.
- **`PDF_TEXTO`**:

  > Estos datos se tomaron de la constancia que subiste y no se confirmaron con el SAT; verifica
  > que sea reciente y que el domicilio siga vigente.

  No afirma que el SAT esté caído: también se llega aquí cuando el SAT respondió a medias.

- **`QR_RFC`**:

  > No se pudo consultar al SAT y el documento no trae texto legible. El RFC se tomó del código QR
  > y es correcto; captura el resto de los datos a mano.

## Pruebas (Pest)

`tests/Feature/ConstanciaFiscalTest.php` y `tests/Unit/ParesPorPosicionTest.php`. **Ninguna prueba
sale a internet**: `Http::fake()` sirve los fixtures y `Http::preventStrayRequests()` lo garantiza.

Fixtures en `tests/Fixtures/constancias/`, **con datos ficticios**:

- `sat-fisica.html`: reproduce las rarezas reales —cabecera XML, RFC dentro de una frase,
  etiqueta `CP:`, regímenes como filas repetidas con la descripción en palabras.
- `sat-moral.html`.
- `sat-parcial.html`: respuesta a la que le falta casi todo.
- `csf-fisica.pdf` y `csf-moral.pdf`: PDF con texto palabra por palabra, domicilio en dos
  columnas, valor partido en dos renglones y tabla de regímenes; QR del validador sin predictor.
- `csf-con-qr.pdf`: **dos** QR, con el del sello digital primero y con predictor PNG.
- `csf-escaneada.pdf`: PDF sin texto.
- `csf-foto.png`: imagen con el QR del validador.

Casos:

- Persona moral → `razon_social` = denominación; persona física → nombre + apellidos.
- QR extraído del PDF sin `qr_url`; el RFC del `D3` coincide con el del documento.
- Dos QR en el PDF: se elige el del validador.
- QR leído de una foto (`csf-foto.png`) por el servidor.
- SAT con respuesta parcial: `200` con lo que se leyó, **la bandera de caída NO queda puesta**, y
  se registra el `Log::warning`.
- Régimen por descripción: "…Actividades Empresariales y Profesionales" → `612`; "…Sueldos y
  Salarios e Ingresos Asimilados a Salarios" → `605`.
- Etiqueta `CP:` reconocida; página con cabecera XML: las filas se leen.
- Domicilio en dos columnas sin arrastrar la etiqueta vecina; valor partido en dos renglones;
  espacios dentro de un valor ("CIUDAD OLMECA").
- `Número Interior:` vacío no se come el renglón siguiente.
- Varios regímenes: todos en `regimenes_disponibles`, el primero propuesto y advertencia.
- CP que no son 5 dígitos: vacío y advertencia.
- QR a otro dominio, y a `sat.gob.mx.evil.com`: `422 QR_NO_OFICIAL` y `Http::assertNothingSent()`.
- `http://` en vez de `https://`: rechazado igual.
- SAT que no responde: bandera puesta y Estrategia B (`PDF_TEXTO`).
- Segunda constancia durante la caída: no consulta al SAT. `Http::fake()` no registra las
  peticiones que lanzan excepción, así que se usa un contador dentro del `fake`, y el SAT
  respondería bien la segunda vez (la única explicación de que no se use es el circuito).
- Caché: dos llamadas con el mismo QR producen una sola consulta.
- SAT caído + PDF escaneado o foto: `200` con `QR_RFC` y solo `rfc`.
- Cliente duplicado: viene `cliente_existente` con `url_editar`; RFC de otro usuario → `null`;
  cliente eliminado (soft delete) → `null`.
- Petición vacía: `422`; archivo de 11 MB: `422`; `.exe` renombrado a `.png`: `422`.
- Once peticiones en un minuto: la once responde `429`.
- Invitado: `401` con cabeceras AJAX; usuario suspendido no entra.
- Nada en disco: `Storage::fake()` queda vacío tras una petición con archivo.
- `EstiloUniformeTest` pasa con el parcial nuevo; `EstructuraTest` incluye
  `js/constancia-fiscal.js`.

Para el JavaScript: `node --check public/js/constancia-fiscal.js`.

## Fuera de alcance

- **Proveedores** (ver 004): su ficha no guarda régimen ni CP fiscal.
- **OCR** de fotos o escaneos, en el navegador o en el servidor.
- **Archivar la constancia**, bitácora de constancias procesadas o marca de origen en el cliente.
- Botón "reverificar con el SAT", carga masiva, escaneo con la cámara en vivo.
- Separar el domicilio en columnas.
- Validar la vigencia del contribuyente o la antigüedad de la constancia.
- Validar el CP contra `c_CodigoPostal` (diferido a facturación, ver 005).
- Validar el dígito verificador del RFC (criterio de 005).
- Servicios de nube para convertir PDF o reconocer texto.
- Usar el idCIF para algo más que identificar la consulta.

## Estado de implementación

Implementada el 2026-09-25.

- **Dependencias**: `chillerlan/php-qrcode` 6.0, `smalot/pdfparser` 2.12, `symfony/dom-crawler` 7.4.
- **Clases**: `app/Services/Constancia/` (`ConstanciaFiscalService`, `QrLector`, `IdentidadQr`,
  `SatHtmlExtractor`, `ConstanciaPdfExtractor`, `ParesPorPosicion`, `MapeadorCampos`,
  `ResultadoConstancia`, `ConstanciaException`), enum `App\Enums\FuenteConstancia`,
  `ConstanciaController` (invocable) y `AnalizarConstanciaRequest`.
- **Se consulta la dirección canónica del validador**, armada con el idCIF y el RFC del QR
  (`services.sat.validador_url`), y no la dirección tal como viene en el QR. Así, aunque el QR sea
  de `sat.gob.mx`, el servidor no pide rutas arbitrarias de ese dominio.
- **La caché guarda el HTML crudo del SAT**, no los datos ya interpretados: si se agrega un alias,
  aplica también a las respuestas cacheadas.
- **`ConstanciaException::render()`** devuelve el `422` con `error` y `mensaje`; el controlador no
  atrapa excepciones.
- **Los avisos se redactan en el servidor** (`aviso`); el JavaScript solo los muestra.
- **Tabla de regímenes del PDF**: sus filas no llevan "Régimen:"; `ParesPorPosicion` toma como
  régimen toda celda sin dos puntos que empiece con "Régimen" y sea más larga que el encabezado.
- **Una continuación debe ir en mayúsculas** (y a no más de 14 unidades del renglón anterior) para
  que un encabezado como "Datos del domicilio registrado" no se pegue al valor de arriba.
- **Los fixtures se generaron con un script propio** (PDF escrito a mano con Helvetica y
  WinAnsiEncoding, QR creados con `chillerlan/php-qrcode`); no se agregó ninguna dependencia para
  ello. El texto en Windows-1252 del fixture ejercita `MapeadorCampos::aUtf8`.
- **Prueba del tipo de archivo**: `UploadedFile::fake()` deduce el tipo por el nombre, así que la
  prueba del `.exe` renombrado usa un `UploadedFile` real sobre un archivo temporal.
- **Verificación**: la suite Pest pasa (205 tests; 31 en `ConstanciaFiscalTest` y 21 en
  `ParesPorPosicionTest`), Pint limpio y `node --check` valida `constancia-fiscal.js`. Ninguna
  prueba sale a internet (`Http::preventStrayRequests()`).
- **Pendiente**: probar en un navegador real con una constancia de verdad (ver la última nota de
  implementación) y contrastar las etiquetas reales del validador del SAT con `ALIAS`.

## Notas para la implementación

- **Los fixtures del SAT son reconstruidos.** Antes de dar la historia por probada hay que subir
  una constancia real; si algún campo llega vacío, se agrega su etiqueta real a `ALIAS` de
  `MapeadorCampos` y el `Log::warning` ayudará a detectarlo.
- **Un `?>` dentro de un comentario `//` cierra el bloque de PHP.** Al citar la cabecera XML del SAT
  en un comentario o prueba, escribirla en palabras.
- **RFC de ejemplo**: `PME120315AB9`, no `PME120315ABC`; `phpcfdi/rfc` rechaza el segundo porque el
  último carácter de la homoclave solo puede ser dígito o `A`.
- **Nuevas dependencias de Composer** requieren aprobación antes de instalarse.
- **Verificación en navegador pendiente** hasta que se haga a mano: arrastre en `/clientes/crear`,
  `BarcodeDetector` con una foto en Chrome/Edge, PDF en Firefox (sin `BarcodeDetector`), aviso de
  cliente registrado y sus dos botones.

## Criterios de aceptación

1. En `/clientes/crear` y `/clientes/{cliente}/editar` hay una zona para arrastrar o elegir una
   Constancia de Situación Fiscal en PDF, JPG o PNG, de hasta 10 MB.
2. Subir la constancia es **opcional**; sin JavaScript la zona no aparece y el formulario de 005
   funciona igual.
3. Con un PDF y el SAT en línea se rellenan RFC, razón social, régimen fiscal, código postal fiscal
   y dirección comercial, y no se muestra ningún aviso ámbar.
4. Nombre comercial, nombre de contacto, correo y teléfono **nunca** se sobrescriben.
5. Los campos precargados son editables y el cliente **no se guarda solo**.
6. De una constancia de persona moral sale la denominación social; de una de persona física, el
   nombre unido a sus apellidos.
7. Con varios regímenes vigentes se propone uno y se avisa para confirmar.
8. Un QR fuera de `sat.gob.mx` se rechaza con mensaje claro **y el servidor no consulta esa
   dirección**.
9. Si el SAT no responde en 5 segundos y el archivo es un PDF con texto, los datos se extraen del
   documento y se muestra el aviso ámbar.
10. Durante una caída del SAT, las constancias siguientes no vuelven a esperar los 5 segundos, y
    pasados 2 minutos se reintenta automáticamente.
11. Subir dos veces la misma constancia en el mismo día produce **una sola** consulta al SAT.
12. Si el SAT no responde y la constancia es una foto o un escaneo, se precarga solo el RFC del QR
    con un aviso para capturar el resto.
13. Si no se puede leer el QR, se muestra un mensaje claro y el formulario queda intacto.
14. Si el RFC ya es de un cliente del usuario, se ofrece abrir su ficha; el mismo RFC en otra
    cuenta no dispara el aviso.
15. Más de 10 constancias en un minuto responde "Vas muy rápido…" y no consulta al SAT.
16. Ningún archivo queda escrito en el servidor, ni cuando el proceso falla a la mitad.
17. Las pruebas corren sin conexión a internet.
18. Un usuario sin sesión o suspendido no puede usar el endpoint.
19. Con una constancia real y el SAT en línea, **no aparece ningún aviso ámbar**.
20. De un PDF, el servidor obtiene el QR **por su cuenta**, sin lector del navegador y sin
    ImageMagick ni Ghostscript.
21. Con varios QR se usa el del validador, no el del sello digital.
22. El RFC se toma del QR y llega correcto también cuando el SAT no contesta.
23. Que el SAT conteste algo que no se entiende del todo **no lo marca como caído** y deja registro
    en el log.
24. El régimen se resuelve a su clave del enum a partir de la descripción.
25. De una constancia real, la dirección comercial sale completa, cada parte en su lugar, con sus
    espacios y sin arrastrar etiquetas.
26. No se agrega `package.json`, Vite, Vue ni librerías de JavaScript; Pint corre sin cambios y la
    suite Pest pasa, incluidas `EstiloUniformeTest` y `EstructuraTest`.

## Supuestos asumidos (registro completo)

1. Aplica **solo a Clientes** (alta y edición).
2. La carga es **opcional**; el formulario manual sigue igual.
3. La zona aparece en **crear** y **editar** cliente.
4. **Un archivo a la vez**, PDF/JPG/PNG, máximo **10 MB**.
5. Del PDF solo importa el QR del validador y el texto de la constancia.
6. El archivo **no se guarda**; no hay expediente ni bitácora.
7. El proceso es **inmediato y en pantalla**, no una tarea en segundo plano.
8. Los datos llegan como **propuesta editable**; nunca se guarda automáticamente.
9. Los campos que la constancia trae **reemplazan** lo escrito; los comerciales no se tocan.
10. El domicilio se arma en **una línea** en `direccion_comercial`.
11. Persona física: razón social = nombre + apellidos, tal como los publica el SAT.
12. Varios regímenes: se propone el primero y se avisa.
13. RFC ya registrado: se avisa y se ofrece abrir la ficha.
14. El QR solo se acepta si apunta al **dominio oficial del SAT** por `https`.
15. **(Arquitectura)** Ruta **web** con sesión (`POST /clientes/constancia`), no API ni Sanctum.
16. **(Arquitectura)** La interfaz es un **parcial Blade** + `public/js/constancia-fiscal.js`, sin
    Vue, TypeScript, npm ni compilación.
17. **(Arquitectura)** **No se usa `pdfjs-dist`**: el servidor lee el QR y el texto directamente
    del PDF.
18. **(Arquitectura, a confirmar)** **No hay OCR.** `tesseract.js` exigiría copiar más de 10 MB de
    WASM y datos de idioma en `public/vendor` (001 prohíbe CDN y npm). Si una foto o escaneo no
    puede resolverse con el SAT, se precarga solo el RFC del QR (`QR_RFC`) y el resto se captura a
    mano. Desaparecen la casilla "Revisé estos datos" y el bloqueo del botón Guardar.
19. **(Arquitectura, a confirmar)** **Una sola petición**, que siempre sube el archivo (más
    `qr_url` si el navegador leyó el QR de una foto). El servidor decide el camino completo; se
    elimina el `503` + reintento con archivos de la spec remota.
20. **(Arquitectura)** Toda la interpretación de etiquetas vive en PHP (`MapeadorCampos`); no hay
    copia en JavaScript.
21. **(Alineación con 005)** Campos reales: `correo` y `nombre_contacto` (no `correo_contacto`);
    no existe `descuento_permanente`.
22. **(Alineación con 005)** Régimen desde el enum `RegimenFiscal`; CP validado solo por formato.
23. **(Técnica)** Consulta al SAT: 5 s sin reintentos; circuito abierto 2 min solo por caídas
    reales; caché 24 h de éxitos; `throttle:10,1` por usuario.
24. **(Técnica)** La identidad del contribuyente sale del `D3` del QR.
25. **(Técnica)** El texto del PDF se reconstruye por posición de cada trozo.
26. **(Técnica)** Una respuesta incompleta del SAT deja un `Log::warning` sin datos personales.
27. **(Técnica)** Valores del SAT (dominio, timeout, caída, caché) en `config/services.php`.
28. **(Técnica)** Pruebas con fixtures ficticios, sin internet (`Http::preventStrayRequests()`).
