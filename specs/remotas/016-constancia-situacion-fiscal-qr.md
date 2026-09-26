# Spec: Alta de clientes desde la Constancia de Situación Fiscal (QR del SAT)

**Alcance:** Extiende [004](004-gestion-clientes.md). No toca ninguna otra historia.

## Historia de usuario

Como usuario del sistema de facturación, quiero subir la Constancia de Situación Fiscal de un
cliente —en PDF, escaneada o fotografiada con el celular— y que el sistema lea el código QR que el
SAT imprime en ella, consulte los datos oficiales del contribuyente y rellene solo el formulario de
alta, para no tener que teclear a mano RFCs, razones sociales y domicilios largos ni arriesgarme a
equivocarme en un carácter.

## Objetivo / Alcance

Agregar a las pantallas de alta y edición de clientes una zona de carga de archivo que extrae los
datos fiscales del contribuyente y los precarga en el formulario, **sin guardar nada
automáticamente**: el usuario revisa, corrige si hace falta y guarda con el botón de siempre.

El camino principal es el **código QR**: la constancia trae impresa una dirección del SAT que
identifica al contribuyente sin ambigüedad. Leer esa dirección y preguntarle al SAT elimina de raíz
el problema de confundir una `O` con un `0` en un RFC.

Como la infraestructura del SAT se cae y entra en mantenimiento con frecuencia, el sistema **no
depende al 100% de ella**: cuando el SAT no responde —o cuando el QR no se puede leer— hay una
**Estrategia B** que extrae los datos del documento mismo y los entrega marcados para revisión.

Se implementa sobre la base ya existente de Laravel API + Vue 3 SPA + Sanctum (ver
[001](001-inicio-proyecto.md), [002](002-login-auth.md)) y el design system de
[003](003-design-system-tailwind.md).

**No** incluye validar la vigencia del contribuyente, archivar constancias, ni tocar
[Proveedores](005-gestion-proveedores.md), que también tiene RFC.

### El flujo completo de un vistazo

```
[ Usuario suelta la CSF (PDF / JPG / PNG) ]
              │
              ▼
  NAVEGADOR: si es PDF, dibuja la página 1 como imagen (pdf.js).
             Conserva también el PDF original.
              │
              ▼
  NAVEGADOR: ¿logra leer el QR con el lector nativo del dispositivo?
    │
    ├── SÍ ──► manda solo la dirección del QR (unos cientos de bytes)
    │
    └── NO ──► sube la imagen y el PDF, y el BACKEND busca el QR por su cuenta:
                 1º dentro del PDF, tomando el código impreso tal como está guardado ahí
                 2º si no, en la imagen que subió el navegador
              │
              ▼
  ¿la dirección apunta al dominio oficial del SAT?
    │
    ├── NO ──► rechazar: "este QR no es del SAT"
    │
    └── SÍ ──► del propio QR salen el idCIF y el RFC: la identidad del
               contribuyente queda fijada aunque el SAT no conteste
                 │
                 ▼
               BACKEND consulta al SAT (5 s, sin reintento)
                 │
                 ├── contesta y se entiende ──► DATOS OFICIALES ✅ (se cachean 24 h)
                 │
                 ├── contesta pero falta algo ─► se usa lo que sí vino y el resto
                 │                               lo completa la Estrategia B.
                 │                               NO se marca al SAT como caído.
                 │
                 └── no contesta ─────────────► se anota "SAT caído" por 2 min ──┐
                                                                                 │
                                                                                 ▼
                                                                         ESTRATEGIA B
                                                    ┌───────────────────────┴──────────────────┐
                                                    │                                          │
                                          PDF con texto copiable                    foto o escaneo
                                          BACKEND: Smalot PDFParser                 NAVEGADOR: OCR
                                                    │                                          │
                                                    ▼                                          ▼
                                          aviso ámbar:                         aviso ámbar + campos
                                          "verifica que la                     marcados + casilla
                                          constancia esté vigente"             "revisé estos datos"
```

La diferencia entre "no contesta" y "contesta pero falta algo" es la que sostiene todo lo demás. Un
campo que no se supo leer es un problema nuestro, no una caída del SAT: tratarlo como caída apaga la
consulta oficial durante dos minutos para **todos** los usuarios, y como el fallo se repite en cada
constancia, la consulta oficial deja de ocurrir nunca. La marca de caída se reserva para lo que de
verdad es una caída: tiempo agotado, error de red o respuesta con código de error.

### Los tres niveles de confianza

Es la decisión que gobierna toda la interfaz, y conviene fijarla antes del detalle técnico. Los
datos pueden llegar por tres caminos con distinta confiabilidad, y el usuario tiene que poder
distinguirlos de un vistazo:

| Fuente | Qué es | Confianza | Qué ve el usuario |
| --- | --- | --- | --- |
| `SAT_QR_DIRECT` | El SAT respondió en vivo | Oficial y al día | Nada especial: flujo normal |
| `PDF_TEXTO` | Texto copiado del PDF del SAT | Exacto, pero es lo que dice el papel | Aviso ámbar |
| `OCR_LOCAL` | Caracteres reconocidos de una imagen | Puede traer errores invisibles | Aviso ámbar, campos marcados y **casilla obligatoria** |

La diferencia entre las dos últimas no es cosmética. El texto de un PDF se copia carácter por
carácter y es exacto; su único riesgo es que la constancia sea vieja y el contribuyente ya se haya
mudado. El OCR, en cambio, adivina letras a partir de manchas: puede devolver un RFC con un
carácter mal y **ese error no se ve a simple vista** — es exactamente el problema que el QR venía a
eliminar. Por eso solo el tercer caso bloquea el botón de guardar hasta que el usuario confirme.

## Backend (Laravel)

### Dependencias nuevas

- **`chillerlan/php-qrcode`** — lectura del QR cuando el navegador no pudo.
- **`smalot/pdfparser`** — extracción del texto interno del PDF (Estrategia B).
- **`symfony/dom-crawler`** — recorrido del HTML que devuelve el SAT. `symfony/css-selector` ya
  está instalado.
- El cliente HTTP de Laravel (**Guzzle**) ya está disponible.

**No se usa `spatie/pdf-to-image` ni ImageMagick.** Se verificó el entorno: este PHP tiene `gd` pero
**no tiene `imagick`**, y no hay ni ImageMagick ni Ghostscript en el `PATH`. Esa ruta habría exigido
instalar dos programas externos en desarrollo y repetirlo en producción, con el riesgo de que el
hosting no lo permita. El dibujado del PDF se resuelve en el navegador, que ya sabe hacerlo.

### Endpoint único

```
POST /api/v1/clientes/constancia
```

Bajo `auth:sanctum` y **registrado antes** del `apiResource('clientes')`, siguiendo el mismo criterio
que las rutas de impacto de precios en [014](014-costo-elaboracion-goma.md).

Acepta `multipart/form-data` con tres partes, **todas opcionales pero al menos una obligatoria**:

| Parte | Tipo | Para qué |
| --- | --- | --- |
| `qr_url` | string | La dirección que el navegador ya leyó del QR |
| `imagen` | archivo JPG/PNG | Para que el backend intente leer el QR por su cuenta |
| `pdf` | archivo PDF | Para la Estrategia B (texto interno) |

**El frontend lo llama una o dos veces**, y esa es la razón de que el endpoint sea uno solo con
partes opcionales:

1. **Primera llamada, caso feliz**: si el navegador leyó el QR, manda **solo `qr_url`** — unos
   cientos de bytes. La imagen no se sube y el PDF tampoco.
2. **Segunda llamada, solo si hizo falta**: si la primera devolvió `503` (SAT caído), el frontend
   repite la llamada adjuntando los archivos para que el backend intente la Estrategia B.

Partirlo en dos endpoints habría duplicado validación, límite de uso y forma de respuesta para dos
variantes del mismo trámite.

### Orden de resolución en el servidor

1. **¿El SAT está marcado como caído?** Si la bandera de circuito abierto está puesta, se salta el
   paso 4 sin esperar.
2. **Obtener la dirección del QR.** Si vino `qr_url`, se usa. Si no, se busca por cuenta propia:
   primero **dentro del `pdf`** y después en la `imagen`.
3. **Validar el dominio.** La dirección debe ser `https` y su host debe terminar exactamente en
   `sat.gob.mx`. Cualquier otra cosa se rechaza con `422 QR_NO_OFICIAL`, sin consultarla. Es lo que
   impide que un QR falso apuntando a otro sitio dirija al servidor a hacer peticiones arbitrarias.
   Del QR válido se leen el **idCIF y el RFC**, que quedan como identidad del contribuyente pase lo
   que pase después.
4. **Consultar al SAT** y extraer los datos del HTML. Si sale bien → `200` con `SAT_QR_DIRECT`.
5. **Estrategia B**: si vino `pdf`, extraer del texto interno → `200` con `PDF_TEXTO`.
6. Si no hay nada más que intentar, responder el error correspondiente.

### El QR se busca dentro del PDF, no en una foto del PDF

El código QR de una constancia **viaja dentro del PDF como imagen guardada**, no dibujado con
líneas: se puede sacar tal cual está, sin convertir la página a foto y sin perder un solo punto. Se
verificó en constancias reales de persona física y de persona moral: en ambas el QR de la primera
página es una imagen cuadrada de 150 × 150 puntos que se lee sin dificultad.

Esto importa porque hasta ahora el único camino era que el navegador dibujara la página y encontrara
el código ahí, lo cual **depende del lector nativo del dispositivo**, que Firefox y Safari todavía no
traen. Con el PDF en la mano, el servidor no necesita ese lector ni necesita convertir nada: toma las
imágenes cuadradas que el PDF ya contiene y las lee con `chillerlan/php-qrcode`. Sigue sin hacer
falta ImageMagick ni Ghostscript.

Reglas de la búsqueda:

- Solo se examinan las imágenes **cuadradas** y de al menos 50 puntos por lado. Un QR lo es siempre,
  y así los logotipos y las firmas del documento se descartan sin gastar memoria en decodificarlos.
- **Una constancia trae más de un QR.** El de la primera página apunta al validador del SAT; el de
  la última codifica la cadena original del sello digital, que no sirve para identificar a nadie. Se
  distinguen por el contenido, no por la posición: vale el QR cuyo parámetro `D3` tiene la forma
  `idCIF_RFC`. Quedarse con "el primero que se pueda leer" tomaría el equivocado en cuanto el orden
  de las imágenes cambie.
- El navegador sigue intentándolo primero. Cuando lo logra no se sube archivo alguno, que es el
  motivo original de todo el diseño; el camino del servidor es la red de seguridad.

### La identidad sale del propio QR

La dirección del QR es de la forma:

```
https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=16040688444_OAMN910602UXA
```

`D3` son el **idCIF** y el **RFC** separados por un guion bajo. Se comprobó en constancias reales de
los dos tipos de contribuyente.

El RFC se toma de ahí y se valida con `phpcfdi/rfc`, igual que en el resto del sistema. Es el dato
más confiable de todo el trámite: viene codificado para que lo lea una máquina, no impreso para que
lo lea una persona, así que no hay forma de confundir una `O` con un `0` — que era exactamente el
problema que esta historia venía a resolver.

La consecuencia práctica es que **el RFC ya no depende de que el SAT conteste**. Cuando el SAT no
está y los datos se sacan del documento, el RFC sigue llegando del QR, exacto; solo el domicilio y
el régimen quedan sujetos a la Estrategia B y a su aviso ámbar. Si el HTML del SAT trae un RFC
distinto al del QR, manda el del QR y se avisa.

### Consulta al SAT

- **Espera máxima de 5 segundos, sin reintentos.** Los 10 segundos con reintento que serían
  razonables si fallar significara "captura todo a mano" no lo son cuando ya existe una alternativa
  lista: cada segundo extra es un segundo de rueda girando frente a un usuario que podría estar ya
  revisando datos.
- **Circuito abierto por 2 minutos, y solo por caídas de verdad.** El SAT no se cae para una
  persona: se cae para todos y dura un rato. Cuando una consulta falla **por tiempo agotado, error
  de red o código de error HTTP**, se anota en caché `csf:sat:caido` durante 120 segundos, y
  mientras dure, las constancias siguientes van directo a la Estrategia B **sin esperar los 5
  segundos**. Dar de alta cinco clientes durante una caída cuesta 5 segundos en total, no 25.
  Pasados los 2 minutos se vuelve a intentar solo.

  Que el SAT **conteste** y no se le entienda del todo **no abre el circuito**. Es un fallo de
  lectura nuestro, y su remedio es agregar un alias, no dejar de preguntar: un fallo de lectura se
  repite en todas las constancias, así que tratarlo como caída apagaría la consulta oficial de forma
  permanente en ventanas de dos minutos encadenadas, y el sistema entero funcionaría siempre en
  modo degradado sin que nada lo delatara. Cuando el SAT contesta se usa lo que se haya podido
  entender y lo que falte se completa con la Estrategia B.
- **Caché de 24 horas de las respuestas exitosas**, con clave derivada de la dirección del QR
  (que es única por contribuyente). Cubre el caso real de subir la constancia dos veces, equivocarse
  de pantalla o reintentar. **Los fallos no se cachean**: para eso está el circuito abierto, que
  tiene su propio vencimiento corto.
- **Límite de uso: 10 constancias por minuto por usuario** (`throttle:10,1`). No estorba al trabajo
  real —nadie da de alta 20 clientes por minuto a mano— y frena en seco una repetición
  descontrolada. Importa porque el bloqueo que aplicaría el SAT caería sobre la dirección IP del
  servidor completo, no sobre un usuario.

### Extracción del HTML del SAT (`ConstanciaFiscalService`)

Con `symfony/dom-crawler`. La página del validador se sirve con una cabecera de XML antes del
`<!DOCTYPE html>`, así que **hay que pedirle explícitamente que la lea como HTML**: si se deja
adivinar, la toma por XML, exige que cada etiqueta viva en su espacio de nombres y ningún `<tr>`
coincide. La lectura no falla ruidosamente, simplemente devuelve cero filas y todo el trabajo recae
en el respaldo de texto plano, que ve menos.

- **RFC**: lo pone el QR (ver arriba). En el HTML aparece dentro de una frase —*"El RFC: XXX, tiene
  asociada la siguiente información"*— y no como un par etiqueta/valor, así que de ahí solo se toma
  para contrastar.
- **Nombre / Razón social**: si el HTML trae denominación o razón social (persona moral) se toma
  ese campo; si trae nombre y apellidos por separado (persona física), se unen en el orden
  `nombre + apellido paterno + apellido materno`. Se conservan las mayúsculas y la ortografía del
  SAT, sin reacomodar ni recapitalizar.
- **Régimen fiscal**: ni el SAT ni la constancia impresa publican el código numérico. Los dos
  escriben la **descripción**: *"Régimen de las Personas Físicas con Actividades Empresariales y
  Profesionales"*. El código se obtiene buscando en `c_RegimenFiscal` la descripción que esté
  contenida en la del SAT, comparando ambas reducidas a su esqueleto —sin acentos, sin mayúsculas y
  sin espacios—, que es la misma normalización que se usa para las etiquetas. Así el "Régimen de
  las" que el SAT antepone deja de estorbar. Lo que se devuelve es el `id` del catálogo, que es lo
  que el `RegimenFiscalSelect` espera. Si el texto llegara con un código numérico, también se
  acepta.
- **Varios regímenes vigentes**: un contribuyente puede tener más de uno, y el SAT los publica como
  **filas repetidas con la misma etiqueta `Régimen:`**. Hay que recogerlas todas: quedarse con la
  primera aparición de cada etiqueta —que es lo correcto para el resto de los campos— aquí perdería
  los demás regímenes. Se devuelven **todos** en `regimenes_disponibles` y se propone el primero
  vigente en `regimen_fiscal`, junto con una advertencia para que el usuario confirme cuál
  corresponde.
- **Código postal**: se valida contra `c_CodigoPostal` con la regla `CodigoPostalValido` ya
  existente. Si el CP de la constancia no está en el catálogo, el campo se deja vacío y se avisa,
  en vez de precargar algo que el formulario va a rechazar al guardar. El SAT lo etiqueta
  simplemente **`CP:`**, de dos letras, así que la longitud mínima de una etiqueta reconocible es de
  **dos caracteres**. Lo que evita que una etiqueta corta traiga basura no es su longitud, sino que
  solo se usan las que están en la lista de alias.
- **Domicilio**: la constancia trae calle, número exterior, número interior, colonia, municipio y
  estado por separado. **El sistema guarda la dirección en un solo campo de texto**
  (`direccion_comercial`, `varchar(255)`), así que se arma una línea:

  ```
  AV TECNOLOGICO 105, COL INDUSTRIAL, CELAYA, GUANAJUATO
  ```

  Los componentes vacíos se omiten sin dejar comas sueltas, y el resultado se trunca a 255
  caracteres. **No se agregan columnas nuevas** de calle/colonia/municipio: separar el domicilio es
  una historia distinta, con su propia migración y su propio impacto en el PDF de la factura.

### Estrategia B: texto del PDF (`ConstanciaPdfExtractor`)

Cuando el SAT no está disponible y el archivo original es un PDF, se lee su **texto interno** con
`smalot/pdfparser` y se buscan los mismos campos por sus etiquetas (`RFC:`, `Denominación o Razón
Social:`, `Régimen:`, `Código Postal:`, …).

- Es exacto: las letras se copian, no se adivinan. Su límite es otro — es lo que decía el papel el
  día que se imprimió.

#### El texto se reconstruye por posición, no se lee de corrido

Un PDF no guarda renglones ni palabras: guarda trozos de letras con la coordenada donde va cada uno.
El texto "de corrido" que devuelve la librería es ya una interpretación de esas coordenadas, y en la
constancia del SAT se equivoca de dos maneras que se anulan mutuamente si no se miran juntas:

- **Pierde los espacios dentro de un valor.** "CIUDAD OLMECA" sale como `CIUDADOLMECA` y "VERACRUZ
  DE IGNACIO DE LA LLAVE" como `VERACRUZDEIGNACIODELA`. No es un defecto de configuración —se probó
  bajar el umbral de separación de la librería hasta el mínimo y no cambia nada—: es que cada
  palabra se dibuja como un trozo aparte colocado en su sitio, sin ningún espacio de por medio que
  copiar.
- **Junta las dos columnas del domicilio en un solo renglón.** La constancia acomoda el domicilio en
  una rejilla de dos columnas, así que un mismo renglón lleva dos pares: `Código Postal: 96535` y
  `Tipo de Vialidad: CALLE`.

Por eso el texto se arma a partir de los **trozos con su coordenada**, que la misma librería expone,
en vez de leer el resultado ya aplanado:

- Los trozos que comparten renglón se ordenan por su posición horizontal y se unen **con un
  espacio**, que es justamente el que el PDF no guardó.
- Cuando el hueco entre dos trozos es mucho mayor que la separación entre palabras, no es un espacio
  sino **el salto a la otra columna**, y ahí empieza una celda nueva. Cada celda aporta un par
  etiqueta/valor por su cuenta.
- Un renglón **sin dos puntos** que sigue a otro con un valor es la **continuación** de ese valor: es
  el caso de "VERACRUZ DE IGNACIO DE LA" partido de su "LLAVE" por el ancho de la caja.

Dentro de una celda, el valor termina donde termina la celda. Esa es la regla que faltaba: mientras
el valor se buscó hasta los dos puntos siguientes, se tragaba la etiqueta de al lado —el domicilio
salía como `JAGUARES Número Exterior, INT Nombre de la Colonia, …`— y de paso perdía el par que
venía después.

Las etiquetas no sufren ninguno de estos problemas, porque para reconocerlas ya se les quitan los
espacios: `NombredelaColonia` y `Nombre de la Colonia` son la misma llave.
- Si el PDF **no tiene texto copiable** (es un escaneo metido dentro de un PDF), el extractor
  devuelve vacío y el flujo cae al caso de OCR.
- Los campos que no se encuentren se devuelven vacíos: es preferible un campo en blanco a un dato
  inventado.
- El régimen y el código postal se validan contra los mismos catálogos que en el camino oficial.

### Detección de cliente duplicado

Con el RFC ya resuelto —venga del SAT o del PDF— se busca si el usuario autenticado ya tiene un
cliente con ese RFC. Si existe, la respuesta incluye:

```json
"cliente_existente": { "id": 42, "razon_social": "PANDA CONNECT LOGISTICS SA DE CV" }
```

El backend **no bloquea nada**: solo informa. La validación `unique` de 004 sigue siendo la que
impide el duplicado real al guardar; esto únicamente permite que el frontend ofrezca abrir la ficha
existente en lugar de empujar al usuario contra un error de validación.

### Nada se guarda en disco

`POST /api/v1/clientes/constancia` **no escribe archivos**. La imagen y el PDF se leen desde el
`UploadedFile` temporal de PHP, que el propio PHP descarta al terminar la petición, salga bien o
mal. No hay carpeta que limpiar, no hay tarea programada de barrido y no hay forma de que un
documento fiscal de un tercero se quede olvidado en el servidor. Es lo más limpio y encima lo más
rápido.

No se persiste **nada** de este trámite: ni el archivo, ni una bitácora de constancias procesadas,
ni una marca en el cliente sobre cómo se capturaron sus datos. Lo único que sobrevive a la petición
es la caché de 24 horas, que guarda la respuesta del SAT y no el documento del usuario.

### Validaciones (`AnalizarConstanciaRequest`)

- `qr_url`: opcional, `url`, máximo 2048 caracteres.
- `imagen`: opcional, archivo, `mimes:jpg,jpeg,png`, máximo **10 MB**.
- `pdf`: opcional, archivo, `mimes:pdf`, máximo **10 MB**.
- Regla de conjunto: al menos uno de los tres debe venir; si no, `422`.
- El tipo de archivo se valida por su contenido real (`mimes`, no `extension`): un `.exe`
  renombrado a `.png` se rechaza.

### Formas de respuesta

**Éxito (`200`)**

```json
{
  "fuente": "SAT_QR_DIRECT",
  "confianza": "oficial",
  "advertencias": [],
  "data": {
    "rfc": "PME120315AB9",
    "razon_social": "PANDA CONNECT LOGISTICS SA DE CV",
    "regimen_fiscal": "601",
    "regimenes_disponibles": [
      { "id": "601", "texto": "General de Ley Personas Morales" }
    ],
    "codigo_postal_fiscal": "38000",
    "direccion_comercial": "AV TECNOLOGICO 105, COL INDUSTRIAL, CELAYA, GUANAJUATO"
  },
  "cliente_existente": null
}
```

`confianza` es `"oficial"` para `SAT_QR_DIRECT` y `"documento"` para `PDF_TEXTO`. `advertencias` es
una lista de textos ya redactados para mostrar tal cual (varios regímenes vigentes, código postal
fuera del catálogo, campos que no se encontraron).

**SAT caído (`503`)**

```json
{ "error": "SAT_NO_DISPONIBLE", "estrategia_b": true }
```

Es la señal para que el frontend repita la llamada adjuntando los archivos. Si el frontend **ya**
había mandado los archivos y aun así no hubo forma, no llega este `503` sino el `422` de abajo.

**Sin datos (`422`)**

```json
{ "error": "QR_NO_LEGIBLE", "puede_ocr": true }
```

Códigos posibles: `QR_NO_LEGIBLE` (no se encontró el código), `QR_NO_OFICIAL` (apunta fuera de
`sat.gob.mx`), `PDF_SIN_TEXTO` (es un escaneo dentro de un PDF), `SIN_DATOS` (no se pudo extraer
ningún campo). `puede_ocr` le dice al frontend si tiene sentido intentar el reconocimiento local.

**Límite excedido (`429`)** — respuesta estándar de `throttle`.

### Tests

Fixtures en `tests/Fixtures/constancias/`, **con datos ficticios**, no de contribuyentes reales:

- `sat-moral.html` y `sat-fisica.html` — copias congeladas de lo que responde el SAT.
- `csf-moral.pdf` — PDF con texto copiable, para el extractor de la Estrategia B.
- `csf-escaneada.pdf` — PDF sin texto (imagen dentro), para el caso `PDF_SIN_TEXTO`.

**Ninguna prueba sale a internet.** Se usa `Http::fake()` sirviendo esos HTML. Una prueba que
consultara al SAT de verdad fallaría cuando el SAT esté caído, cuando no haya conexión o cuando el
contribuyente de prueba cambie sus datos —todo ello con el código en perfecto estado— y una alarma
que suena sin motivo enseña a ignorar las alarmas.

Casos a cubrir:

- **Persona moral**: del HTML sale la denominación social como `razon_social`.
- **Persona física**: nombre y apellidos se unen en un solo `razon_social`.
- **QR extraído del PDF**: de un PDF de constancia sale la dirección del validador sin que el
  navegador haya leído nada, y el RFC del `D3` coincide con el del documento.
- **La constancia trae dos QR**: se elige el del validador y no el del sello digital, aunque el del
  sello aparezca antes.
- **El SAT contesta algo que no se entiende del todo**: se responde con lo que sí se pudo leer y
  **la bandera de caída NO queda puesta** — la siguiente constancia vuelve a consultar al SAT.
- **Régimen por descripción**: "Régimen de las Personas Físicas con Actividades Empresariales y
  Profesionales" resuelve a `612`, y "Régimen de Sueldos y Salarios e Ingresos Asimilados a
  Salarios" a `605`, sin que aparezca ningún número en el texto.
- **Etiqueta `CP:`**: el código postal se reconoce aunque su etiqueta tenga dos letras.
- **Página del SAT con cabecera de XML**: las filas de las tablas se leen igual.
- **Domicilio en dos columnas**: de un PDF real salen vialidad, número exterior, colonia, municipio
  y estado en su campo, y ninguno arrastra la etiqueta del de al lado.
- **Valor partido en dos renglones**: "VERACRUZ DE IGNACIO DE LA" + "LLAVE" llega completo.
- **Espacios dentro de un valor**: la colonia sale como "CIUDAD OLMECA" y no como "CIUDADOLMECA".
- **Varios regímenes vigentes**: se devuelven todos, se propone el primero y viene la advertencia.
- **Código postal fuera del catálogo**: el campo llega vacío y con advertencia, no con basura.
- **QR que apunta a otro dominio**: `422 QR_NO_OFICIAL` y **`Http::assertNothingSent()`** — no basta
  con devolver el error, hay que probar que no se consultó la dirección.
- **`http://` en vez de `https://`**: se rechaza igual.
- **SAT que tarda más de 5 segundos**: `503`, y la bandera de caída queda puesta.
- **Segunda llamada durante la caída**: responde sin consultar al SAT (`Http::assertSentCount(1)`
  después de dos peticiones).
- **Caché**: dos llamadas seguidas con el mismo `qr_url` producen **una sola** consulta al SAT.
- **Estrategia B con PDF de texto**: `200` con `fuente: "PDF_TEXTO"` y `confianza: "documento"`.
- **Estrategia B con PDF escaneado**: `422 PDF_SIN_TEXTO` con `puede_ocr: true`.
- **Cliente duplicado**: si el usuario ya tiene ese RFC, viene `cliente_existente`; si el RFC es de
  un cliente de **otro** usuario, viene `null`.
- **Petición vacía** (sin ninguna de las tres partes): `422`.
- **Archivo de 11 MB**: `422`.
- **Once peticiones en un minuto**: la número once responde `429`.
- **Sin sesión**: `401`.
- **No se escribió nada en disco**: el `Storage` queda intacto después de una petición con archivos.

## Frontend (Vue 3)

### Dependencias nuevas

- **`pdfjs-dist`** — dibuja la primera página del PDF y también extrae su texto. Es la librería que
  usa el visor de PDF de Firefox; no requiere nada instalado en el servidor.
- **`tesseract.js`** — reconocimiento de caracteres, solo se carga cuando de verdad hace falta.

Ambas son pesadas, así que se importan de forma **diferida** (`import()` dinámico): quien nunca
suba una constancia no descarga ni un byte extra, y quien suba un PDF legible nunca descarga el
OCR.

### `src/lib/constanciaFiscal.ts`

Toda la lógica del navegador vive aquí, fuera del componente, para que `Vitest` la pueda probar
—`src/lib/` es justamente lo que la suite de Vitest ya cubre—:

- `renderizarPrimeraPagina(file)` → imagen PNG de la página 1.
- `leerQr(imagen)` → dirección o `null`, usando **`BarcodeDetector`**, el lector de códigos que el
  sistema operativo ya trae: el mismo que usa la cámara del celular cuando apuntas a un QR. Es
  notablemente mejor que cualquier librería con fotos inclinadas, con sombra o movidas, que es
  exactamente el caso difícil.
- `reconocerTexto(imagen)` → texto por OCR.
- `extraerCamposDeTexto(texto)` → los campos fiscales, con las mismas etiquetas que usa el
  extractor de PHP.

**Si `BarcodeDetector` no existe** —Safari y Firefox todavía no lo traen— la función devuelve `null`
sin ruido y el flujo continúa: se sube la imagen y el QR lo lee el backend con
`chillerlan/php-qrcode`. La función **nunca lanza**; un lector ausente es un camino previsto, no un
error.

### `ConstanciaFiscalDropzone.vue`

Zona de arrastrar y soltar (también clicable para elegir archivo), colocada **arriba del formulario**
en `/clientes/crear` y `/clientes/:id/editar`. Un archivo a la vez, PDF/JPG/PNG, máximo 10 MB.

Texto en reposo:

> Arrastra aquí la Constancia de Situación Fiscal, o haz clic para elegir el archivo.
> PDF, JPG o PNG · máximo 10 MB

Estados visibles mientras trabaja: *Leyendo el documento…* → *Buscando el código QR…* →
*Consultando al SAT…* → *Reconociendo texto…* (solo si llega el caso). Emite `datos-extraidos` con
los campos, la fuente y las advertencias.

**La carga es opcional en todo momento.** El formulario manual de 004 sigue funcionando exactamente
igual para quien prefiera teclear, y la zona se puede ignorar por completo.

### Secuencia en el navegador

1. Si el archivo es PDF: dibujar la página 1 y **conservar también el PDF original**.
2. Intentar leer el QR de esa imagen con `BarcodeDetector`.
3. Si se leyó: `POST` con **solo** `qr_url`.
4. Si respondió `503` con `estrategia_b: true`, o si el QR no se pudo leer: repetir el `POST`
   adjuntando `imagen` y `pdf`.
5. Si esa segunda llamada devuelve `422` con `puede_ocr: true`: correr OCR **en el navegador** sobre
   la imagen, extraer los campos localmente y usarlos con `fuente: "OCR_LOCAL"`.
6. Si tampoco así hay datos: mensaje claro y el usuario captura a mano.

El paso 3 es la razón de todo el diseño: en el caso común no se sube ni el PDF ni la imagen, solo
una dirección. Es más rápido, gasta menos datos del usuario y le da menos trabajo al servidor.

### Precarga del formulario

- Los campos que la constancia trae se **reemplazan**, incluso si el usuario ya había escrito algo:
  quien sube una constancia está pidiendo justamente eso.
- **`nombre_comercial`, `correo_contacto`, `telefono` y `descuento_permanente` no se tocan nunca.**
  La constancia no trae esos datos, y borrar lo que el usuario ya escribió a mano sería destruir
  trabajo suyo.
- Todos los campos precargados quedan **normalmente editables**. Nada queda bloqueado ni de solo
  lectura: el sistema propone, el usuario decide.
- **Nunca se guarda solo.** El alta sigue ocurriendo con el botón Guardar de siempre.

### Los avisos según la fuente

Con el componente `Alert` de 003, sobre el formulario:

- **`SAT_QR_DIRECT`** — sin aviso. Los datos son oficiales y el flujo se siente normal.
- **`PDF_TEXTO`** — variante de advertencia:

  > ⚠ Estos datos se tomaron de la constancia que subiste y no se confirmaron con el SAT; verifica
  > que sea reciente y que el domicilio siga vigente.

  El aviso **no afirma que el SAT esté caído**, porque no siempre es el motivo: también se llega
  aquí cuando el QR no se pudo leer y nunca hubo a quién preguntarle. Decirle al usuario que el SAT
  falló cuando el problema fue el documento lo manda a esperar a que "se componga" algo que nunca
  estuvo roto.

- **`OCR_LOCAL`** — misma variante, más contundente, con los campos leídos marcados y una casilla
  obligatoria:

  > ⚠ El SAT no respondió y la constancia es una imagen, así que los datos se reconocieron
  > automáticamente y **pueden tener errores**. Revisa carácter por carácter el RFC y el código
  > postal antes de guardar.
  >
  > ☐ Revisé estos datos y son correctos

  El botón **Guardar permanece deshabilitado** hasta que la casilla se marque. Es medio segundo de
  fricción puesto exactamente donde el error es caro e invisible: un RFC con un carácter mal no se
  nota al leerlo y termina en un CFDI rechazado.

Las `advertencias` que devuelve el backend (varios regímenes, CP fuera de catálogo) se muestran
como puntos dentro del mismo `Alert`, y también aparecen cuando la fuente es `SAT_QR_DIRECT` — ahí
es el único aviso que se ve.

### Cliente ya registrado

Cuando la respuesta trae `cliente_existente`, en vez de precargar el formulario se muestra:

> Ya tienes registrado a **PANDA CONNECT LOGISTICS SA DE CV** con este RFC.
> [Abrir su ficha] [Precargar de todos modos]

**Abrir su ficha** navega a `/clientes/{id}/editar`. **Precargar de todos modos** continúa con la
precarga normal —útil si lo que el usuario quiere es actualizar el domicilio de un cliente que se
mudó, que es justo el escenario de subir una constancia nueva—. En `/clientes/:id/editar`, si el
cliente existente **es el que ya se está editando**, no se muestra nada: se precarga y ya.

### Mensajes de error

| Situación | Mensaje |
| --- | --- |
| `QR_NO_OFICIAL` | El código QR de este documento no apunta al SAT. Verifica que sea una Constancia de Situación Fiscal oficial. |
| `QR_NO_LEGIBLE` sin OCR posible | No se pudo leer el código QR. Intenta con el PDF original, o con una foto más de frente y con buena luz. |
| `SIN_DATOS` | No se pudieron extraer los datos de este documento. Captúralos manualmente. |
| `429` | Vas muy rápido. Espera un momento antes de subir otra constancia. |
| Archivo muy grande o tipo no permitido | Se rechaza en el navegador antes de subir nada, con el límite en el mensaje. |

En todos los casos el formulario queda intacto y utilizable: un fallo de la constancia **nunca**
deja al usuario peor de como estaba.

## Fuera de alcance

- **Proveedores**: también tienen RFC, pero su ficha no guarda régimen fiscal ni código postal
  fiscal (ver [005](005-gestion-proveedores.md)); aplicarles esto es otra historia.
- **Archivar la constancia**: el documento no se guarda ni queda asociado al cliente. No hay
  expediente digital ni descarga posterior.
- **Bitácora** de constancias procesadas, y marca persistente en el cliente sobre el origen de sus
  datos o sobre si fue verificado contra el SAT.
- **Botón "reverificar con el SAT"** sobre un cliente ya guardado.
- **Carga masiva** de varias constancias a la vez.
- **Escaneo con la cámara en vivo** (apuntar a la constancia impresa sin tomar foto).
- **Separar el domicilio** en columnas de calle, número, colonia, municipio y estado.
- **Validar la vigencia** del contribuyente o la antigüedad de la constancia. Los datos oficiales se
  consultan en vivo, así que da igual de cuándo sea el papel; en la Estrategia B, esa evaluación
  queda en manos del usuario y por eso existe el aviso.
- **Validar el dígito verificador del RFC**: se mantiene el criterio de 004, que documenta que hay
  RFC reales cuyo dígito no coincide.
- **OCR en el servidor** (Tesseract instalado en la máquina): sería el mismo problema de
  dependencias externas que se evitó con ImageMagick.
- **Servicios de nube** para convertir PDF o reconocer texto: cuestan, dependen de internet y
  supondrían mandarle el documento fiscal de un tercero a una empresa ajena.
- **Usar el idCIF para algo más que identificar la consulta**: no se guarda en el cliente ni se
  muestra. Sirve para armar la dirección del validador y para reconocer cuál de los QR de la
  constancia es el bueno.

## Estado de implementación

Implementada el 2026-08-08.

- **Los fixtures del HTML del SAT están reconstruidos, no capturados.** Es la limitación más
  importante de esta entrega y conviene no perderla de vista: no se tuvo acceso a una constancia
  real, así que `sat-moral.html` y `sat-fisica.html` se armaron a partir de la estructura
  documentada de esa página. Las pruebas verifican que **el mecanismo** funciona —recorrer el HTML,
  reconocer etiquetas, validar catálogos, armar el domicilio— pero **no** que los nombres de las
  etiquetas coincidan con los que el SAT usa hoy. Antes de dar la historia por probada hay que subir
  una constancia de verdad y, si algún campo llega vacío, agregar su etiqueta real a la constante
  `ALIAS` de `MapeadorCampos` (y a la del frontend, que es su espejo). El diseño anticipa
  precisamente eso: se buscan alias en vez de una etiqueta exacta y se recogen los pares de todo el
  documento en vez de atar selectores CSS a la maquetación.
- **La extracción no usa selectores CSS.** `SatHtmlExtractor` recoge todos los pares
  "etiqueta: valor" que encuentre —en filas de dos celdas, y como respaldo en el texto plano— y
  luego busca por nombre. Un rediseño del SAT que mueva las cajas de sitio sigue funcionando; uno
  que renombre las etiquetas se resuelve agregando un alias.
- **Dos veces el mismo error de expresión regular**, en el extractor de HTML y en el de PDF: usar
  `\s*` alrededor de los dos puntos hace que la búsqueda cruce el salto de línea, y una etiqueta sin
  valor (`Número Interior:`) se queda con el contenido del renglón siguiente. La dirección salía
  como `AV TECNOLOGICO 105 INT Nombre de la Colonia:INDUSTRIAL, …`. Se corrigió a espacios
  horizontales (`[ \t]`) en ambos lados, y hay una prueba de Vitest dedicada a ese caso.
- **El código postal inexistente de la prueba es `00000`, no `99999`**: se verificó contra el
  catálogo real y `99999`, `99998` y `12345` **sí existen** en `c_CodigoPostal`. La primera versión
  de esa prueba fallaba por eso.
- **El RFC de ejemplo cambió de `PME120315ABC` a `PME120315AB9`**: `phpcfdi/rfc` rechaza el primero
  porque el último carácter de la homoclave solo puede ser un dígito o `A`. Un RFC que no parsea se
  devuelve como `null` y el flujo termina en `SIN_DATOS`, en vez de precargar algo que el guardado
  rechazaría.
- **`chillerlan/php-qrcode` v6** expone la lectura como `(new QRCode)->readFromBlob()`, que trabaja
  sobre GD —presente en este PHP— y no necesita `imagick`. Se envuelve en un `try` porque "esta
  imagen no trae un QR legible" es un resultado previsto del flujo, no una falla.
- **`Http::fake()` no registra las peticiones que lanzan excepción**, así que la prueba del circuito
  abierto no puede usar `Http::assertSentCount()`. Se resolvió con un contador dentro del `fake`, y
  de paso quedó una prueba más fuerte: el SAT falla la primera vez y **respondería bien la segunda**,
  de modo que el `503` de la segunda constancia solo se explica si el circuito impidió salir.
- **Los PDF de prueba se generaron con dompdf**, que ya era dependencia del proyecto: `csf-moral.pdf`
  (1.7 KB, con texto copiable) y `csf-escaneada.pdf` (una imagen sin texto). Se generaron con la
  fuente Helvetica y no con DejaVu porque esta última se incrusta completa y dejaba un fixture de
  878 KB; los resultados de extracción son idénticos.
- **El texto de un PDF puede llegar en Windows-1252 y no en UTF-8**, y entonces cada acento es un
  byte suelto que `mb_strtolower` no sabe tratar: "Denominación" se normalizaría sin la "o" y ningún
  alias coincidiría. Se corrige en `MapeadorCampos::aUtf8`, por donde pasan todas las etiquetas.
- **No se agregó el componente `Checkbox` de shadcn-vue**: la casilla de revisión del OCR es un
  `<input type="checkbox">` nativo con clases de Tailwind. Correr `npx shadcn-vue add` reescribe
  `src/style.css` —el problema documentado en [003](003-design-system-tailwind.md) y vuelto a sufrir
  en [004](004-gestion-clientes.md)— y no vale la pena por un control.
- **El camino de OCR no puede avisar de un cliente duplicado**: al no pasar por el backend, no hay
  quién consulte el RFC. La regla `unique` de 004 sigue impidiendo el duplicado real al guardar.
- **`pdfjs-dist` quedó en su propio trozo de 427 KB** (127 KB comprimido) y `tesseract.js` en el
  suyo, ambos fuera del paquete inicial gracias a los `import()` diferidos: quien nunca sube una
  constancia no los descarga.
- **Verificado**: **299 tests de Pest en verde** (20 nuevos en `ConstanciaFiscalTest.php`), **50 de
  Vitest** (11 nuevos en `constanciaFiscal.test.ts`), `vue-tsc --noEmit` sin errores, `npm run build`
  exitoso, Pint y ESLint limpios.
- **Pendiente**: la verificación visual en un navegador real (misma limitación de entorno que el
  resto de las historias). Falta confirmar a ojo, con una constancia de verdad: la zona de arrastre
  en `/clientes/crear`, la lectura del QR con `BarcodeDetector` en Chrome/Edge, el aviso ámbar con
  la casilla obligatoria cuando el SAT está caído y la constancia es una foto, y el aviso de cliente
  ya registrado con sus dos botones.

### El camino del PDF nunca funcionó en producción (2026-08-19)

Al probarlo con una constancia real, soltar un **PDF** dejaba la zona de carga en *"Leyendo el
documento…"* para siempre. No era un fallo de esta historia: **el servidor servía el *worker* de
`pdfjs-dist` con el tipo equivocado**, y el navegador se niega a ejecutar un módulo que no llega
como JavaScript. Está diagnosticado y corregido en
[018](018-despliegue-hostinger.md#el-tipo-de-los-módulos-mjs), donde vive todo lo que es del
servidor.

Tres consecuencias que sí son de esta historia:

- **Solo afectaba a los PDF.** Una constancia en JPG o PNG no pasa por `renderizarPrimeraPagina`, así
  que ese camino siempre estuvo bien. Es la asimetría que hacía difícil de leer el síntoma.
- **El fallo no llegaba a ser un error.** `procesar()` atrapa lo que se lance y muestra "No se pudo
  leer el documento", pero aquí no se lanzaba nada: la promesa simplemente no se resolvía, y el
  estado se quedaba en `preparando`. Un flujo que depende de una librería que se carga sola desde la
  red puede quedarse esperando, y el `try/catch` no cubre eso.
- **No se puede cambiar de librería para esquivarlo.** `pdfjs-dist` publica su worker únicamente en
  `.mjs`; no hay una variante `.js` a la que apuntar. El arreglo tenía que ser del servidor.

### Contraste con constancias reales (2026-08-10)

Se subieron constancias de verdad —una de persona física y una de persona moral— y se contrastó
contra la respuesta real del validador del SAT. **El aviso ámbar salía siempre**, en todas las
constancias, y la dirección llegaba armada mal. Los dos síntomas resultaron tener causas
independientes, y ninguna de las dos era el SAT.

Lo que se comprobó:

- **El SAT sí contesta, y rápido**: `200` en medio segundo, con todos los datos del contribuyente.
  Nunca hubo caída. La prevención de 016 contra la fragilidad del SAT era correcta, pero acabó
  tapando un fallo propio: como el error se presentaba como "el SAT no respondió", el sistema
  llevaba trabajando en modo degradado desde el primer día sin que nada lo delatara.
- **El RFC no se reconocía**, y el RFC es el único campo del que el sistema no puede prescindir. En
  la página del SAT no aparece como par etiqueta/valor sino dentro de una frase, *"El RFC:
  OAMN910602UXA, tiene asociada la siguiente información"*, así que la etiqueta capturada era
  `El RFC` y el valor arrastraba el resto de la oración. Sin RFC, la respuesta se consideraba
  inservible; y como "inservible" se trataba igual que "no contestó", **cada intento dejaba el SAT
  marcado como caído durante dos minutos**. Con eso, todas las constancias siguientes se resolvían
  por la Estrategia B sin siquiera preguntar. De ahí el "en todos los casos".
- **Ninguna fila de tabla se leía.** El validador antepone una cabecera `<?xml …?>` al
  `<!DOCTYPE html>`, y `DomCrawler`, al adivinar, lo toma por XML: pide espacios de nombres y ningún
  `<tr>` coincide. No lanza error, devuelve cero filas. Todo caía en el respaldo de texto plano, que
  ve menos: por eso también se perdía el `CP:`, cuya etiqueta de dos letras quedaba además por
  debajo del mínimo de tres.
- **El régimen nunca llega como número.** Ni el SAT ni el papel publican `601` o `612`: publican la
  descripción. Se buscaba un código de tres dígitos que no existe en ninguna de las dos fuentes, así
  que el régimen quedaba siempre vacío y siempre con advertencia.
- **La dirección `JAGUARES Número Exterior, INT Nombre de la Colonia, VERACRUZ DE IGNACIO DE LA`**
  tiene tres causas sumadas: el domicilio del PDF viene en dos columnas y el valor se buscaba hasta
  los dos puntos siguientes, con lo que se comía la etiqueta vecina y perdía el par que venía
  después; el PDF no guarda los espacios dentro de un valor; y "LLAVE" quedaba en el renglón de
  abajo. La corrección de renglones de 016 —espacios horizontales alrededor de los dos puntos— iba
  en la dirección correcta pero se quedó corta: resolvía el salto de línea, no la segunda columna.
- **El QR se puede sacar del PDF sin convertir nada.** Es una imagen cuadrada de 150 × 150 guardada
  dentro del archivo, en las dos constancias probadas. Se leyó con `chillerlan/php-qrcode` sobre GD,
  sin ImageMagick y sin Ghostscript.
- **`D3` trae el idCIF y el RFC** separados por un guion bajo, en ambas constancias. Era el supuesto
  que 016 había dejado fuera de alcance por no estar verificado; ya lo está.
- **La constancia de persona moral trae dos QR** y el segundo, el del sello digital, tiene un `D3`
  completamente distinto. Elegir "el primero que se lea" habría funcionado por casualidad.
- **El umbral de espaciado de la librería de PDF no arregla las palabras pegadas.** Se probó de −50
  a −1 sin diferencia: las palabras se dibujan como trozos colocados por coordenada, sin espacio que
  copiar. La reconstrucción por posición no es una preferencia, es el único camino.

### Corrección implementada el 2026-08-10

- **Clases nuevas**: `IdentidadQr` (idCIF y RFC del `D3`) y `ParesPorPosicion` (reconstrucción del
  texto del PDF a partir de coordenadas). `QrLector` gana `leerDePdf`; el resto son ajustes en el
  mapeador, los dos extractores, el servicio del SAT y el controlador.
- **Resultado con las constancias reales**: de las dos sale el domicilio completo y correcto, y de
  la de persona física la dirección extraída del PDF coincide **carácter por carácter** con la que
  devuelve el SAT en vivo: `JAGUARES 5208, COL CIUDAD OLMECA, COATZACOALCOS, VERACRUZ DE IGNACIO DE
  LA LLAVE`. Los regímenes se resuelven a `612` y `605` en una y a `603` en la otra.
- **Se agregó `Denominación/Razón Social`** a los alias: la constancia de persona moral la escribe
  con barra y no con la "o" que ya estaba contemplada. Es exactamente el tipo de ajuste que el
  diseño de alias anticipaba.
- **Los umbrales de la reconstrucción por posición tienen un margen enorme**, y conviene saberlo por
  si algún día hay que tocarlos: en una constancia real el hueco entre dos palabras ronda las 5
  unidades y el salto a la otra columna las 180. El corte está en 30. El ancho de carácter se
  **sobreestima a propósito** (7 unidades): equivocarse por exceso acerca los trozos y los deja en
  la misma celda, mientras que quedarse corto partiría un valor en dos.
- **Hubo que deshacer la predicción PNG de las imágenes.** El SAT guarda su QR con los puntos en
  crudo, pero cualquier PDF reimpreso o vuelto a guardar con otra herramienta usa predictor —el
  fixture generado con dompdf, sin ir más lejos— y sin descomprimirlo el código sale ilegible. Son
  treinta líneas y hacen que la lectura funcione con constancias que el usuario haya pasado por otro
  programa, que es un caso real.
- **El aviso duplicado del código postal**: cuando el CP existía pero no estaba en el catálogo se
  emitían dos advertencias contradictorias, "no existe en el catálogo" y "no se encontró". Ahora
  cada motivo tiene su mensaje y solo uno se emite.
- **Un `?>` dentro de un comentario `//` cierra el bloque de PHP.** Un comentario de prueba que
  citaba la cabecera de XML de la página del SAT rompió el archivo entero con un error de sintaxis a
  trescientas líneas de distancia. Se cita en palabras.
- **Verificado**: **313 tests de Pest en verde** (7 nuevos en `ParesPorPosicionTest.php` y 7 en
  `ConstanciaFiscalTest.php`), **50 de Vitest**, `vue-tsc --noEmit` sin errores, `npm run build`
  exitoso, Pint y ESLint limpios. Ninguna prueba sale a internet.
- **Fixtures nuevos**, con datos ficticios: `sat-validador-fisica.html` reproduce las tres rarezas
  de la página real —cabecera de XML, RFC dentro de una frase, etiqueta `CP:` de dos letras— y los
  regímenes como filas repetidas con la descripción en palabras; `sat-parcial.html` es la respuesta
  a la que le falta casi todo, que no debe marcar caída; `csf-con-qr.pdf` trae **dos** códigos QR
  con el del sello digital primero, para probar que se elige por contenido.

## Criterios de aceptación

1. En `/clientes/crear` y `/clientes/:id/editar` hay una zona para arrastrar o elegir una
   Constancia de Situación Fiscal en PDF, JPG o PNG, de hasta 10 MB.
2. Subir la constancia es **opcional**: el formulario manual de 004 funciona exactamente igual que
   antes para quien no la use.
3. Con un PDF legible y el SAT en línea, se rellenan RFC, razón social, régimen fiscal, código
   postal fiscal y dirección comercial, y no se muestra ningún aviso.
4. Nombre comercial, correo, teléfono y descuento permanente **nunca** se sobrescriben.
5. Todos los campos precargados siguen siendo editables, y el cliente **no se guarda solo**: hace
   falta el clic en Guardar.
6. De una constancia de persona moral sale la denominación social; de una de persona física, el
   nombre unido a sus apellidos.
7. Si el contribuyente tiene varios regímenes vigentes, se propone uno y se avisa al usuario para
   que confirme cuál corresponde.
8. Un QR que apunta fuera del dominio `sat.gob.mx` se rechaza con un mensaje claro, **y el servidor
   no consulta esa dirección**.
9. Si el SAT no responde en 5 segundos y el archivo es un PDF con texto, los datos se extraen del
   documento y se muestra el aviso ámbar de verificar vigencia.
10. Durante una caída del SAT, las constancias siguientes van directo a la Estrategia B sin volver a
    esperar los 5 segundos, y pasados 2 minutos se reintenta el SAT automáticamente.
11. Subir dos veces la misma constancia en el mismo día produce **una sola** consulta al SAT.
12. Si el SAT no responde y la constancia es una foto o un escaneo, los datos se reconocen en el
    navegador, los campos aparecen marcados y **el botón Guardar está deshabilitado** hasta que se
    marque la casilla de revisión.
13. Si no se puede leer el QR ni extraer datos por ningún camino, se muestra un mensaje claro y el
    formulario queda intacto para capturar a mano.
14. Si el RFC leído ya corresponde a un cliente del usuario, se ofrece abrir su ficha en vez de
    crear un duplicado; el mismo RFC en la cuenta de otro usuario no dispara el aviso.
15. Superar 10 constancias en un minuto responde con un mensaje de "vas muy rápido" y no consulta al
    SAT.
16. Ningún archivo queda escrito en el servidor después de procesar una constancia, ni cuando el
    proceso falla a la mitad.
17. Las pruebas automáticas de esta historia corren sin conexión a internet y sin consultar al SAT
    real.
18. Un usuario sin sesión no puede usar el endpoint.
19. Con una constancia real y el SAT en línea, los datos llegan por el camino oficial y **no aparece
    ningún aviso ámbar**: el aviso solo se ve cuando de verdad no se pudo confirmar con el SAT.
20. De un PDF de constancia el servidor obtiene la dirección del QR **por su cuenta**, sin depender
    del lector de códigos del navegador, y sin ImageMagick ni Ghostscript instalados.
21. Cuando la constancia trae varios códigos QR, se usa el del validador del SAT y no el del sello
    digital.
22. El RFC se toma del propio QR, así que llega correcto también cuando el SAT no contesta.
23. Que el SAT conteste algo que no se entienda del todo **no lo marca como caído**: la siguiente
    constancia vuelve a consultarlo.
24. El régimen fiscal se resuelve a su código del catálogo a partir de la descripción, que es lo
    único que el SAT y la constancia publican.
25. De una constancia real, la dirección comercial sale con vialidad, número, colonia, municipio y
    estado completos, cada uno en su lugar, con sus espacios y sin arrastrar etiquetas.
26. Pint, ESLint/Prettier y las suites de Pest y Vitest corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. Aplica **solo a Clientes** (alta y edición). Proveedores no se toca, aunque también tenga RFC.
2. La carga de la constancia es **opcional**: el formulario manual sigue existiendo tal cual.
3. El punto de entrada es una zona de arrastrar y soltar en la pantalla de **crear cliente**.
4. La misma zona aparece en **editar cliente**, para actualizar los datos de un cliente cuya
   constancia cambió.
5. Se acepta **un solo archivo a la vez**, en PDF, JPG o PNG.
6. Tamaño máximo **10 MB**.
7. Del PDF solo se examina la **primera página**, que es donde el SAT imprime el QR.
8. El archivo **no se guarda**: se procesa y se descarta. No hay expediente de constancias.
9. El proceso es **inmediato y en pantalla**, con indicador de avance; no es una tarea en segundo
   plano que avise después.
10. Los datos llegan como **propuesta editable** y el cliente **no se guarda automáticamente**.
11. Los campos que la constancia trae **reemplazan** lo que hubiera escrito en el formulario.
12. Se rellenan RFC, razón social, régimen fiscal, código postal fiscal y dirección. **No** se
    rellenan nombre comercial, correo ni teléfono, porque la constancia no los trae.
13. El domicilio se arma como **una sola línea** en el campo `direccion_comercial` que ya existe; no
    se agregan columnas de calle, colonia ni municipio.
14. Para persona física, la razón social se forma uniendo nombre y apellidos tal como los publica el
    SAT, sin reacomodar.
15. Con **varios regímenes fiscales vigentes** se propone el primero y se avisa para que el usuario
    confirme.
16. Si el RFC ya existe como cliente del usuario, se avisa y se ofrece abrir su ficha, en lugar de
    crear un duplicado.
17. **(Redefinido)** Cuando el QR no se puede leer o el SAT no responde, el sistema **intenta
    extraer los datos del documento mismo** —texto interno si es PDF, reconocimiento de caracteres
    si es imagen— y los entrega marcados para revisión. La versión anterior de este supuesto decía
    que en ese caso solo quedaba la captura manual; se cambió porque la infraestructura del SAT se
    cae con frecuencia y un sistema que se inhabilita cada vez que eso pasa no es utilizable.
18. Si no hay forma de obtener datos por ningún camino, se muestra un mensaje claro y el usuario
    captura a mano.
19. El QR solo se acepta si apunta al **dominio oficial del SAT**; cualquier otro se rechaza sin
    consultarlo.
20. **No se verifica la antigüedad** de la constancia en el camino oficial, porque los datos se
    consultan en vivo. En la Estrategia B esa evaluación queda en el usuario, y por eso existe el
    aviso.
21. No se lleva bitácora ni historial de constancias procesadas.
22. **(Adición técnica)** El **navegador** convierte la primera página del PDF en imagen con
    `pdfjs-dist`, y **sube tanto la imagen como el PDF original** cuando hace falta la Estrategia B.
    No se usa `spatie/pdf-to-image` ni ImageMagick: se verificó que este entorno no tiene `imagick`
    ni Ghostscript, y esa ruta habría exigido instalar dos programas externos aquí y en producción.
23. **(Adición técnica, redefinido)** El **navegador lee el QR primero**, con el lector nativo del
    dispositivo (`BarcodeDetector`), que es el mismo de la cámara del celular y es mucho mejor con
    fotos inclinadas o con sombra. Si lo logra, manda solo la dirección y no sube archivo alguno. Si
    el navegador no tiene ese lector —Firefox y Safari todavía no— o no lo logra, suben los archivos
    y el backend busca el QR **primero dentro del PDF**, donde está guardado como imagen y se lee sin
    pérdida, y solo después en la foto. La versión anterior de este supuesto dejaba el caso sin
    lector dependiendo de una foto de la página; se cambió porque el PDF ya trae el código y leerlo
    de ahí no cuesta nada.
24. **(Adición técnica)** La consulta al SAT espera **5 segundos sin reintentos**, y una falla marca
    el SAT como caído por **2 minutos**, durante los cuales las constancias siguientes van directo a
    la Estrategia B sin esperar. Insistir tiene sentido cuando fallar significa "captura todo a
    mano"; deja de tenerlo cuando ya hay una alternativa lista.
25. **(Adición técnica)** Las respuestas exitosas del SAT se **cachean 24 horas** por dirección de
    QR. Los fallos no se cachean: de eso se encarga el circuito abierto, con su vencimiento corto.
26. **(Adición técnica)** **Límite de 10 constancias por minuto por usuario**, porque el bloqueo que
    aplicaría el SAT caería sobre la IP del servidor completo y dejaría la función inservible para
    todos.
27. **(Adición técnica)** Las pruebas usan **copias congeladas** del HTML del SAT (persona moral y
    persona física, con datos ficticios) y nunca salen a internet. Una prueba que consultara al SAT
    real fallaría por causas ajenas al código, y una alarma que suena sin motivo enseña a ignorar
    las alarmas.
28. **(Adición técnica)** Los archivos **nunca se escriben en disco**: se procesan en memoria desde
    el archivo temporal de PHP, que se descarta solo al terminar la petición. Si nunca tocó el
    disco, no hay nada que limpiar ni nada que se pueda quedar olvidado.
29. **(Adición técnica)** El OCR corre **en el navegador** (`tesseract.js`), no en el servidor:
    evita instalar Tesseract en desarrollo y en producción, igual que con ImageMagick. Se carga de
    forma diferida, así que solo lo descarga quien de verdad llega a ese caso.
30. **(Adición técnica)** La interfaz distingue **tres niveles de confianza** —oficial, texto del
    documento y reconocimiento automático— y solo el tercero exige marcar una casilla antes de
    guardar. Tratar igual el texto exacto de un PDF que una adivinanza de OCR dejaría al usuario sin
    saber cuánto desconfiar, y un aviso que siempre está se vuelve paisaje.
31. **(Adición técnica)** La **identidad del contribuyente sale del QR**, no de la lectura del
    documento ni de la respuesta del SAT: `D3` trae el idCIF y el RFC, verificado en constancias
    reales de persona física y de persona moral. Es el único dato del trámite pensado para que lo
    lea una máquina, así que es también el único que no puede salir mal por una letra confundida.
32. **(Adición técnica)** **Solo se marca al SAT como caído cuando no contesta.** Una respuesta que
    llega y no se entiende del todo es un fallo de lectura propio y se resuelve agregando un alias,
    no dejando de preguntar durante dos minutos. Sin esta distinción, un solo campo mal reconocido
    apaga la consulta oficial de forma permanente y el sistema trabaja degradado sin avisar.
33. **(Adición técnica)** El texto del PDF se reconstruye **a partir de la posición de cada trozo**,
    no del texto ya aplanado que devuelve la librería: es la única forma de recuperar los espacios
    que el PDF no guarda y de separar las dos columnas del domicilio. Un valor termina donde termina
    su celda, nunca en los dos puntos siguientes.
