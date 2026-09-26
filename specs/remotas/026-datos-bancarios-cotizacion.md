# Spec: Datos bancarios en la cotización

## Historia de usuario

Como usuario único del sistema, quiero guardar en Configuración los datos bancarios de mi negocio
—nombre del banco, número de cuenta, tarjeta y clave interbancaria— y que aparezcan **únicamente en
la cotización**, para que el cliente que la recibe sepa a dónde pagarme sin tener que pedírmelo.

**Requisitos explícitos del usuario:**

- Se pueden agregar varios bancos.
- Cada cuenta puede llevar el **logo de su banco**, reducido hasta quedar como un icono pequeño.

## Objetivo / Alcance

Agregar a Configuración una sección nueva, **Datos bancarios**, que es una **lista** de cuentas del
negocio, y un bloque en el **encabezado del PDF de cotización** que las imprime.

Se apoya en lo que ya existe: la pantalla de Configuración es un tablero de secciones hermanas
([014-costo-elaboracion-goma.md](014-costo-elaboracion-goma.md),
[019-formato-pdf-documentos.md](019-formato-pdf-documentos.md)), y el PDF de cotización hereda de la
plantilla común `pdf/documento.blade.php` de [019](019-formato-pdf-documentos.md).

**No se toca ninguna otra parte del sistema.** Factura y orden de compra no cambian; la tesorería no
cambia; el cálculo de precios y totales no cambia.

### Estos bancos NO son las Cuentas de Tesorería

El sistema ya tiene un modelo `Cuenta` ([010-tesoreria.md](010-tesoreria.md)): "Caja General",
"BBVA", "Mercado Pago". Esas cuentas son **dónde está el dinero** —tienen saldo, reciben
movimientos, cuadran contra los pagos— y existen para la contabilidad interna del negocio.

Los datos bancarios de esta historia son otra cosa: son **lo que se le dice al cliente para que
pague**. No tienen saldo, no reciben movimientos, no aparecen en ningún reporte de Tesorería y nadie
las concilia. Un banco de esta lista puede no tener Cuenta de Tesorería, y una Cuenta de Tesorería
—la caja de efectivo, por ejemplo— nunca sería un dato bancario.

Se implementan por separado, con su propia tabla, y **no se relacionan entre sí en el esquema**.
Colgarlas de `cuentas` habría metido saldos en una pantalla que no habla de dinero y datos de
clientes en una que sí, y habría dejado a la caja de efectivo con un campo CLABE que no tiene
sentido llenar.

## Backend (Laravel)

### Esquema: tabla `datos_bancarios`

Tabla propia y no una clave del almacén de configuración
([014](014-costo-elaboracion-goma.md)/`ClaveConfiguracion`): ese almacén guarda **una casilla con un
valor** ("costo_goma_chica = 6.00"), y aquí hacen falta cinco datos que van juntos, repetidos N
veces y con un orden propio. Meterlos ahí obligaría a inventar claves numeradas
(`banco_1_clabe`…) y a manejar la lista con nombres de clave, que es exactamente el trabajo que hace
una tabla.

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | `id` | |
| `nombre_banco` | `string(100)` | Obligatorio |
| `beneficiario` | `string(150)` nullable | A nombre de quién está la cuenta |
| `numero_cuenta` | `string(20)` nullable | Solo dígitos |
| `tarjeta` | `string(16)` nullable | Solo dígitos |
| `clabe` | `string(18)` nullable | Solo dígitos, 18 exactos |
| `logo_ruta` | `string` nullable | Ruta del icono en el disco privado; `null` es "sin logo" |
| `visible_en_cotizaciones` | `boolean` | Default `true` |
| `orden` | `unsignedInteger` | Posición en la lista y en el PDF |
| `timestamps` | | |

- **No lleva `user_id` y sus endpoints no se scopean por usuario.** Es la misma excepción, y por la
  misma razón, que el emisor de [019](019-formato-pdf-documentos.md): los datos bancarios son del
  negocio que emite, que es **uno solo para toda la instalación**. Serían el único caso del sistema
  en que un dato del emisor se partiera por usuario.
- **Los números se guardan como texto, nunca como número.** Un número de cuenta puede empezar con
  cero, y guardado como entero `0123456789` se convierte en `123456789`, que es una cuenta
  distinta. Además nunca se suman ni se comparan: son etiquetas, no cantidades.
- **Modelo `DatoBancario` con `$table = 'datos_bancarios'` explícito.** Eloquent inferiría
  `dato_bancarios`; misma lección ya pagada en 005, 008, 012, 017 y 019.
- `orden` lo asigna el sistema: un banco nuevo entra al final (`max(orden) + 1`). El usuario no lo
  captura.
- **`logo_ruta` va fuera de `#[Fillable]`**, igual que `imagen_ruta` en
  [020](020-imagenes-articulos.md): no es un dato que el cliente mande en el `PUT` del banco, sino
  el resultado de haber guardado un archivo. La asigna el servicio de logos y nadie más.

### El logo del banco

Cada cuenta puede llevar el logotipo de su banco. Es opcional: un banco sin logo se captura, se
guarda y se imprime igual, solo sin icono.

- **Lo elige el usuario de su computadora.** El sistema no trae un catálogo de logos de bancos
  mexicanos ni los descarga de internet: serían marcas registradas de terceros distribuidas dentro
  del proyecto, y la lista quedaría desactualizada en cuanto un banco cambiara de imagen.
- **Se comprueba por contenido, no por la terminación del archivo**: JPEG, PNG o WEBP reales.
- **Máximo 2 MB de entrada.** Es un logotipo, no una fotografía de producto.
- **Se guarda reducido a 64 puntos de lado largo**, en WEBP calidad 82, y el original se descarta.
  Un PNG de 1000 puntos entra y sale pesando unos pocos kilobytes. El requisito del usuario —"que
  se reduzca hasta ser un icono pequeño"— se cumple **al guardar**, no al imprimir: si se guardara
  el original y solo se encogiera en el PDF, cada cotización cargaría el archivo completo, el
  correo con el adjunto engordaría por cada banco, y el trabajo se repetiría en cada impresión.
- **Nunca se amplía** un logo más chico que 64 puntos: agrandarlo solo lo volvería borroso.
- **La transparencia se conserva.** Un PNG recortado sobre fondo transparente saldría con un
  recuadro blanco encima del papel si se aplanara; se preserva el canal alfa hasta la codificación
  WEBP, con el mismo cuidado que documenta [020](020-imagenes-articulos.md) (`imagealphablending`
  apagado y `imagesavealpha` encendido, porque `imagescale` no permite apagar la mezcla de capas y
  devuelve fondo negro).
- **Un logo por banco**: subir otro reemplaza al anterior y **borra su archivo en el mismo acto**,
  igual que los logos del emisor en [019](019-formato-pdf-documentos.md). Sin eso el directorio
  acumula todos los que el usuario haya probado.
- **El nombre del archivo lo genera el sistema**: `{id}-{8 caracteres al azar}.webp`, bajo
  `DatoBancario::DIRECTORIO_LOGOS` (`datos-bancarios`) en el **disco privado**. Los 8 caracteres al
  azar cumplen aquí el mismo papel que en [020](020-imagenes-articulos.md): reemplazar un logo
  cambia la dirección y el navegador va por el nuevo sin que nadie vacíe su caché.
- **Al eliminar un banco su archivo de logo NO se borra.** Es la excepción deliberada a la regla del
  párrafo anterior: las cotizaciones ya creadas guardan en su foto la ruta de ese archivo, y
  borrarlo las dejaría imprimiendo el nombre del banco sin su icono. Es el mismo criterio que
  [020](020-imagenes-articulos.md) aplica al artículo dado de baja.

### Qué se valida al guardar un banco

- `nombre_banco`: **requerido**, máximo 100 caracteres.
- `beneficiario`: opcional, máximo 150.
- `numero_cuenta`: opcional, **solo dígitos, entre 6 y 20**.
- `tarjeta`: opcional, **solo dígitos, 15 o 16** (15 cubre American Express).
- `clabe`: opcional, **exactamente 18 dígitos y con dígito verificador válido** (ver abajo).
- **Al menos uno de `numero_cuenta`, `tarjeta` o `clabe` debe venir lleno.** Un banco sin ningún
  número no le sirve de nada al cliente, que es la única razón por la que este registro existe. El
  mensaje lo dice así: *"Captura al menos un número de cuenta, tarjeta o CLABE."*
- Los números se **normalizan antes de validar**: se quitan espacios y guiones, para que pegar
  `4152 3133 1234 5678` desde la banca en línea funcione sin que el usuario tenga que limpiarlo. Lo
  que se guarda son solo los dígitos.
- **Se permite repetir banco.** Dos cuentas en BBVA son dos renglones legítimos y no hay ninguna
  regla de unicidad sobre `nombre_banco`.

### De dónde sale el redimensionado: se generaliza el servicio que ya existe

[020](020-imagenes-articulos.md) ya resolvió "comprobar por contenido, reducir conservando la
proporción sin ampliar nunca, preservar la transparencia y reescribir en WEBP", con todas las
trampas de GD ya pagadas. Lo único que aquí cambia es el número: 64 puntos en vez de 1200.

- Se **extrae de `ImagenArticuloService` un servicio sin dueño**, `ProcesadorImagen`, con un solo
  método: recibe la ruta de un archivo y el lado máximo, y devuelve el contenido ya en WEBP —o
  lanza `RuntimeException` con un motivo legible si no es una imagen aceptada.
- `ImagenArticuloService` **conserva su interfaz y su comportamiento**: sigue siendo la puerta única
  de las imágenes de artículo (guardar, eliminar, servir, nombrar el archivo) y delega en
  `ProcesadorImagen` la parte que ahora comparte. Su constante `LADO_MAXIMO` sigue valiendo 1200 y
  la carga masiva no cambia en nada.
- El logo del banco entra por **`LogoBancoService`**, hermano del anterior: guarda, elimina y sirve
  el archivo del logo, y delega el procesamiento en el mismo sitio.

Copiar el procesamiento en vez de extraerlo habría duplicado precisamente el código que costó
descubrir —el canal alfa, la liberación de los recursos de GD en `finally`, la comprobación por
contenido—, y la copia se habría quedado atrás la primera vez que se corrigiera un caso en el
original.

### Dígito verificador de la CLABE

Una CLABE son 18 dígitos y el último no es libre: se calcula a partir de los 17 anteriores. Se
comprueba con una regla de validación propia (`ClabeValida`):

1. Se recorren los 17 primeros dígitos multiplicando cada uno por el peso que le toca, ciclando
   `3, 7, 1`.
2. De cada producto se toma **solo su último dígito** (`% 10`) y se van sumando.
3. El verificador esperado es `(10 - (suma % 10)) % 10`, y debe coincidir con el dígito 18.

Un solo dedo chueco al teclear rompe la cuenta y se detecta al instante, antes de que la CLABE
equivocada salga impresa en una cotización y un cliente mande dinero a ninguna parte. Es la única
validación del sistema que puede atrapar ese error: la longitud correcta no dice nada, y el banco no
avisa hasta que la transferencia ya se intentó.

La validación se aplica **solo a la CLABE**. El número de cuenta no tiene un formato común entre
bancos, y la tarjeta sí tiene su propia comprobación (Luhn) pero no se implementa: los datos de
tarjeta se capturan una vez y se revisan a ojo, y una regla de más sobre un campo opcional no paga
su costo.

### Congelado: la cotización guarda una foto de los datos bancarios

- **Nueva columna `cotizaciones.datos_bancarios`**: `json`, nullable. Contiene la lista de bancos
  **tal como estaban al crearse la cotización**: para cada uno, `nombre_banco`, `beneficiario`,
  `numero_cuenta`, `tarjeta`, `clabe` y `logo_ruta`, en el orden en que se imprimirán.
- **Del logo se guarda la ruta, no la imagen.** Meter el WEBP en base64 dentro del JSON engordaría
  cada cotización con una copia del mismo icono —y con varios bancos, varias copias por
  documento—, cuando el archivo al que apunta la ruta ya no cambia nunca: reemplazar un logo
  escribe un archivo **nuevo** con otro nombre y deja el anterior intacto, y eliminar el banco no lo
  borra. La ruta congelada es, en la práctica, tan inmutable como lo sería la copia.
- **Fuera de `#[Fillable]`**, igual que `imagen_ruta` en [020](020-imagenes-articulos.md): no es un
  dato que el cliente mande en el `POST`; lo pone el controlador al crear.
- La foto se toma **al crear** la cotización, con los bancos que en ese momento tenían
  `visible_en_cotizaciones = true`, en su orden. **No se vuelve a tomar nunca**: ni al editar un
  borrador, ni al enviar, ni al reimprimir.
- **`duplicar` toma foto nueva**, no copia la del original: una copia es una cotización que sale
  hoy, con el folio de hoy, y debe llevar los datos con los que hoy se cobra. Es distinto de
  `descuento_cliente_porcentaje`, que sí se copia congelado porque tiene que cuadrar con las líneas
  que se copiaron junto a él.
- **Una cotización sin foto (`null`) o con foto vacía (`[]`) se imprime sin el bloque**, exactamente
  como se imprime hoy. `null` solo aparece en las cotizaciones anteriores a esta historia, que son
  datos de prueba: no hay producción que rescatar
  ([018-despliegue-hostinger.md](018-despliegue-hostinger.md)), así que la migración **no rellena
  nada hacia atrás**.

**Por qué congelar.** El PDF de una cotización se vuelve a generar cada vez que se abre o se
reenvía, y es un documento que ya salió del sistema: el cliente lo tiene en su correo o en su
WhatsApp. Si los datos se leyeran vigentes, cambiar de banco en marzo haría que la cotización de
enero se reimprimiera con la cuenta nueva, y el papel que tiene el cliente dejaría de coincidir con
el que ve el usuario en pantalla —sin que nada avise—. Al congelarlos, cada cotización sigue diciendo
lo que dijo el día que se mandó, que es lo que el sistema ya hace con los precios y descuentos de sus
líneas.

El costo aceptado es el reverso: **corregir un dato bancario no arregla las cotizaciones ya
creadas**. Si se capturó una CLABE mal y se detecta después, el camino es duplicar la cotización, no
editarla. Se asume porque el dígito verificador vuelve raro ese caso, y porque la alternativa
—reimprimir con datos distintos a los enviados— falla en silencio.

### Endpoints

Todos bajo `auth:sanctum`. **No se scopean por usuario**, por la misma razón que los del emisor en
[019](019-formato-pdf-documentos.md), y como esos van comentados en `routes/api.php` para que la
excepción no parezca un olvido.

- `GET /api/v1/datos-bancarios` — lista completa, ordenada por `orden`. Incluye los ocultos: la
  pantalla de Configuración los administra, no solo los muestra.
- `POST /api/v1/datos-bancarios` — alta. Entra al final de la lista.
- `PUT /api/v1/datos-bancarios/{dato}` — edición, incluido el interruptor
  `visible_en_cotizaciones`.
- `DELETE /api/v1/datos-bancarios/{dato}` — baja definitiva. Las cotizaciones ya creadas no se ven
  afectadas, porque llevan su propia foto.
- `PUT /api/v1/datos-bancarios/orden` — recibe `{ "ids": [3, 1, 2] }` y reasigna `orden` según esa
  secuencia, dentro de una transacción. Rechaza con `422` si la lista no contiene exactamente los
  ids existentes: un reordenamiento parcial dejaría huecos o posiciones repetidas.
- `POST /api/v1/datos-bancarios/{dato}/logo` — sube o reemplaza el logo
  (`multipart/form-data`, campo `archivo`). Devuelve el banco actualizado.
- `DELETE /api/v1/datos-bancarios/{dato}/logo` — quita el logo y borra su archivo. Devuelve
  `{ "eliminado": true }`, igual que `EmisorController::eliminarLogo`.
- `GET /api/v1/datos-bancarios/{dato}/logo` — devuelve el binario con `Content-Type: image/webp`, o
  `404` si el banco no tiene logo o el archivo ya no está en disco. Los archivos viven en el disco
  privado y **no tienen URL propia**: ésta es la única forma de mirarlos, y va bajo `auth:sanctum`
  como todo lo demás. Mismo patrón que `GET /api/v1/emisor/logo/{tipo}` y que la imagen de artículo
  de [020](020-imagenes-articulos.md).

`DatoBancarioResource` expone todos los campos tal como se guardaron, más `tiene_logo` (bandera) y
`logo_version` (los 8 caracteres al azar del nombre del archivo, para que el navegador no muestre un
logo reemplazado). **La ruta interna no se expone** y **nada se enmascara**: el objetivo del dato es
que se pueda usar para pagar, y la pantalla está detrás del login.

### El interruptor "mostrar en cotizaciones"

Cada banco tiene un switch. Apagado, el banco sigue guardado y visible en Configuración, pero deja de
imprimirse en las cotizaciones que se creen a partir de ese momento.

Existe porque el caso real no es "ya no uso este banco nunca" sino "este mes cobro por el otro":
borrarlo obligaría a recapturar 18 dígitos de CLABE al volver, con el riesgo de error que eso
implica. El borrado definitivo se conserva para el banco que de verdad se cerró.

## PDF de la cotización

El bloque va en el **encabezado, columna derecha, debajo del folio** —es decir, dentro de la tercera
celda de la tabla `.encabezado` de `pdf/documento.blade.php`, la que hoy solo lleva el título y el
número.

- Se declara en la plantilla base como un **`@yield('encabezado-extra')` nuevo**, y **solo
  `cotizacion.blade.php` lo llena**. Factura y orden de compra heredan el hueco vacío y salen
  idénticas a como salen hoy. Es el mismo mecanismo con el que la 019 resolvió `@yield('extras')` y
  `@yield('timbre')`: lo común vive arriba, lo propio de cada documento se declara abajo, y un
  documento no puede heredar por accidente algo que no le toca.
- **Formato:** un título "Datos bancarios" con el mismo tratamiento que los títulos de bloque del
  documento (versalitas, azul `#2c3e50`, línea inferior), y después cada banco uno bajo otro:

  ```
                                    COTIZACIÓN
                                          1042

                                  DATOS BANCARIOS
                                  ──────────────────
                                  [icono] BBVA
                                  Cta: 0123456789
                                  CLABE: 012180001234567890
                                  Tarjeta: 4152313312345678

                                  [icono] Santander
                                  Rosa Martínez
                                  CLABE: 014180009876543210
  ```

- El nombre del banco en negritas, **con su icono a la izquierda en el mismo renglón**; debajo, el
  beneficiario si lo hay, y luego un renglón por número con su etiqueta: `Cta:`, `Tarjeta:`,
  `CLABE:`.
- **El icono se imprime a 5 mm de alto**, la altura del renglón, con el ancho que le toque por
  proporción. En milímetros y no en píxeles, por la misma razón que los logos del encabezado en
  [019](019-formato-pdf-documentos.md): así mide lo que dice sobre el papel sin depender del dpi con
  el que dompdf traduzca los píxeles.
- **El renglón del nombre se arma con una tabla de dos celdas** (icono y nombre) y no con un `<img>`
  suelto: el bloque va alineado a la derecha, y dompdf no coloca de forma fiable una imagen en línea
  dentro de un párrafo alineado así. Todo el documento ya resuelve sus alineaciones con tablas
  ([019](019-formato-pdf-documentos.md)) y ésta no es la excepción que valga la pena.
- **Un banco sin logo imprime solo su nombre**, sin celda vacía ni hueco reservado.
- **Si el archivo del logo ya no está en disco**, se imprime el nombre sin icono, se deja un aviso
  en el log y el PDF sale igual. Un documento nunca falla por un logo — misma regla que
  `Emisor::contenidoLogo` en [019](019-formato-pdf-documentos.md).
- **El icono se incrusta en base64**, como el resto de las imágenes del documento: los archivos
  están en el disco privado, no tienen URL, y dompdf tiene que recibir el contenido. Lo resuelve un
  método del modelo `Cotizacion` que lee la foto congelada y le agrega a cada banco su `logo_base64`
  (o `null`), para que la plantilla no toque el disco por su cuenta.
- **Un campo vacío no imprime su renglón.** Un banco al que solo se le capturó CLABE ocupa dos
  renglones, no cinco con tres guiones.
- **Los números se imprimen completos, sin enmascarar.** Una tarjeta con asteriscos no sirve para
  pagar, que es lo único para lo que está ahí.
- **Alineado a la derecha**, como el título y el folio que tiene encima, y a **7.5pt**: los 18
  dígitos de una CLABE con su etiqueta caben en el 40% de ancho de esa celda (unos 77 mm) sin
  partirse en dos renglones.
- **Si la cotización no trae bancos, no se imprime ni el título ni el marco**, y el encabezado queda
  exactamente como está hoy. Un documento nunca se bloquea ni avisa por esto, igual que no se
  bloquea por un emisor incompleto ([019](019-formato-pdf-documentos.md)).
- Aplica a **todos los caminos** por los que sale el PDF de cotización, incluida la **ruta pública
  firmada** que Twilio usa para adjuntar el PDF al WhatsApp y el envío por correo: el bloque sale del
  propio documento, no del usuario en sesión, así que llega solo a los seis caminos que enumera
  `EmisorComposer`.

**Por qué en el encabezado y no al pie.** Es donde el cliente ya está mirando cuando busca el folio y
el total, y no depende de que llegue al final de una hoja que puede tener dos páginas de conceptos.
El costo es que el encabezado crece con cada banco; se acepta porque la lista de bancos de un negocio
es corta y el usuario controla cuáles se muestran con el interruptor.

## Frontend (Vue 3)

### `DatosBancariosForm.vue` (componente nuevo)

Sección hermana dentro de `/configuracion`, debajo de `EmisorForm.vue` y arriba de "Costos de
elaboración". **Con su propio guardado**, como el emisor: agregar un banco no puede arrastrar el
recálculo de precios de los costos de goma ni al revés.

- **Lista de tarjetas**, una por banco, cada una con: **el icono del banco junto al nombre**, los
  números capturados, el switch "Mostrar en cotizaciones", y los botones de editar y eliminar. El
  icono se pinta con un `<img>` apuntando al endpoint autenticado, más `?v={logo_version}` para que
  reemplazarlo se vea de inmediato.
- **Botón "Agregar banco"** que abre un `Dialog` ([003](003-design-system-tailwind.md)) con los
  cinco campos y el selector de logo. El mismo diálogo sirve para editar.

### El logo dentro del diálogo

El bloque del logo muestra la vista previa actual, un botón para elegir imagen y uno para quitarla,
como los logos del emisor.

**En el alta hay un orden obligado**: el logo se guarda contra un banco que ya existe, así que al
pulsar "Guardar" el diálogo **primero crea el banco y después sube el archivo elegido**, en dos
peticiones. Si la segunda falla, el banco queda creado sin logo y se avisa; nunca al revés, porque
un archivo subido contra un banco que no llegó a existir sería basura en el disco sin nada que lo
reclame.

Se aparta a propósito de lo que hizo [020](020-imagenes-articulos.md), donde el bloque de imagen
queda deshabilitado hasta que el artículo exista: allá el formulario es una pantalla completa que
sigue abierta después de guardar, y aquí es un diálogo que se cierra. Deshabilitarlo obligaría a
guardar, reabrir y volver a entrar solo para poner el icono.
- **Los campos numéricos son `type="text"` con `inputmode="numeric"`, no `type="number"`.** Un
  `number` con 18 dígitos pierde precisión, muestra flechitas de incremento sobre algo que no es una
  cantidad, y el cero inicial de un número de cuenta desaparece.
- El error de "captura al menos un número" se muestra **al pie del diálogo**, no colgado de un campo:
  no es culpa de ninguno de los tres en particular.
- **Confirmación al eliminar**, nombrando el banco, y advirtiendo que las cotizaciones ya creadas lo
  conservan.
- Un banco oculto se ve **atenuado** en la lista, con la etiqueta "No se muestra en cotizaciones",
  para que no parezca que se perdió.
- **Estado vacío**: si no hay ningún banco, la sección explica en una línea qué hace y para qué
  sirve, en vez de mostrar una lista vacía.

### Reordenar

Se puede **arrastrar** cada tarjeta para cambiar su posición, con los eventos de arrastre nativos del
navegador (`draggable`, `dragstart`, `dragover`, `drop`). **No se agrega ninguna librería**: es una
lista corta en una pantalla de configuración, y una dependencia nueva para esto no se justifica.

El arrastre nativo **no funciona en pantallas táctiles**, así que cada tarjeta lleva además **botones
de subir y bajar**. No es un adorno: sin ellos, reordenar sería imposible desde un celular. Los dos
caminos llaman al mismo endpoint de orden.

El orden se guarda al soltar (o al pulsar la flecha), sin botón de confirmar: es una acción que se ve
de inmediato y se deshace igual de fácil.

### `stores/datosBancarios.ts` (store nuevo)

Store de Pinia con la lista y las cuatro operaciones, siguiendo el patrón de `stores/emisor.ts`. No
se toca `stores/configuracion.ts`: ese store es el del almacén clave→valor, y estos datos no viven
ahí.

### Lo que NO cambia en el frontend

La pantalla de detalle de una cotización (`CotizacionDetalleView.vue`) **no muestra los datos
bancarios**. Son para el cliente que recibe el PDF, no para el usuario que ya los tiene en
Configuración; agregarlos ahí sería ruido en una pantalla que se usa para revisar líneas y totales.

## Fuera de alcance

- **Datos bancarios en la factura y en la orden de compra.** Solo la cotización, como pidió la
  historia.
- **Datos bancarios en la pantalla de detalle de la cotización**, o en el correo/WhatsApp fuera del
  PDF adjunto.
- **Elegir por cotización qué bancos mostrar.** Se imprimen todos los visibles; el control es global
  y vive en Configuración.
- **Relación con las Cuentas de Tesorería** ([010](010-tesoreria.md)): ni saldos, ni movimientos, ni
  conciliación, ni un selector que las vincule.
- **Actualizar la foto de una cotización ya creada**, ni siquiera en borrador. Para eso se duplica.
- **Rellenar hacia atrás** la foto de las cotizaciones existentes.
- **Enmascarar los números** o cualquier forma de cifrado en la base: la pantalla está detrás del
  login y el dato existe para imprimirse completo.
- **Validación Luhn de la tarjeta**, códigos SWIFT/BIC, moneda de la cuenta, sucursal o cuentas en el
  extranjero.
- **Catálogo de logos de bancos** dentro del sistema, ni empaquetado ni descargado de internet: el
  logo lo sube el usuario.
- **Deducir el banco por los primeros dígitos de la CLABE** para ponerle su logo solo.
- **Recortar, rotar o editar el logo** dentro del sistema, o elegir el recorte del icono.
- **Conservar el archivo original** del logo: se descarta después de generar el icono de 64 puntos.
- **Logo en la factura o en la orden de compra**, que siguen sin bloque bancario.
- **QR de pago** (CoDi o similar) en el PDF.
- **Bancos distintos por usuario**: el emisor es uno solo para toda la instalación.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Estado de implementación

Implementada el 2026-08-14, en dos entregas: primero los datos bancarios y después el logo.

- **Archivos nuevos**: `app/Models/DatoBancario.php`, `app/Rules/ClabeValida.php`,
  `app/Http/Controllers/DatoBancarioController.php`,
  `app/Http/Requests/DatosBancarios/GuardarDatoBancarioRequest.php` y
  `ReordenarDatosBancariosRequest.php`, `app/Http/Resources/DatoBancarioResource.php`,
  `database/factories/DatoBancarioFactory.php`,
  `database/migrations/2026_08_14_000200_create_datos_bancarios_table.php` y
  `..._000300_add_datos_bancarios_a_cotizaciones_table.php`,
  `tests/Feature/DatosBancariosTest.php`, `frontend/src/stores/datosBancarios.ts`,
  `frontend/src/components/DatosBancariosForm.vue`.
- **Un solo Form Request para alta y edición**: las reglas son idénticas y no hay unicidad que
  dependa del registro que se edita, así que dos clases habrían sido la misma clase dos veces.
- **Los comentarios CSS del `<style>` viajan literales a los tres documentos.** El comentario que
  describía el bloque nuevo contenía la frase "Datos bancarios", y por eso la prueba de que la
  factura **no** imprime datos bancarios fallaba: encontraba el texto dentro de la hoja de estilos
  compartida. El comentario está reescrito para no repetir la frase que imprime el bloque, con la
  advertencia anotada en su lugar.
- **El interruptor se guarda con una sola pulsación**, sin abrir el diálogo de edición: pedir
  "editar → cambiar → guardar" para apagar un banco habría convertido un gesto en tres.
### Segunda entrega: el logo

- **Archivos nuevos**: `app/Services/ProcesadorImagen.php` (extraído de `ImagenArticuloService`),
  `app/Services/LogoBancoService.php`,
  `app/Http/Requests/DatosBancarios/SubirLogoBancoRequest.php`,
  `database/migrations/2026_08_14_000400_add_logo_a_datos_bancarios_table.php`.
- **`ImagenArticuloService` conservó su interfaz** al extraer el procesamiento: recibe
  `ProcesadorImagen` por constructor y llama `procesar($ruta, self::LADO_MAXIMO)`. Como todo se
  inyecta por contenedor, ni el controlador de imágenes ni la carga masiva necesitaron un cambio, y
  sus 20 pruebas pasan sin tocarse.
- **`Cotizacion::datosBancariosParaPdf()`** resuelve el base64 de cada icono fuera de la plantilla,
  con la misma lógica de aviso al log que `Emisor::contenidoLogo`: un archivo que ya no está deja
  el icono en `null` y el PDF sale igual.
- **La asimetría del borrado quedó explícita en el código**: quitar el logo borra su archivo, pero
  eliminar el banco entero no. Sin ella, borrar un banco vaciaría el icono de todas las
  cotizaciones que ya lo imprimían.
- **Verificación**: la suite Pest completa pasa (504 tests, 40 de `DatosBancariosTest` —13 nuevos
  del logo, con PNG reales generados en la prueba para medir la reducción a 64 puntos y comprobar
  que el canal alfa sobrevive—); Pint, ESLint, Prettier y Vitest (65 tests) corren limpios, y
  `npm run build` compila la SPA con `vue-tsc` sin errores. Las tres migraciones se aplicaron a la
  base de trabajo. **No se verificó visualmente en un navegador real** (misma limitación de entorno
  que el resto de las historias): falta abrir `/configuracion` para confirmar la lista, el arrastre,
  el diálogo y la vista previa del logo, y mirar un PDF de cotización para aprobar cómo quedan el
  bloque y el icono a 5 mm en el encabezado.

## Criterios de aceptación

1. En `/configuracion` existe una sección "Datos bancarios", separada del emisor y de los costos de
   elaboración, con su propio guardado.
2. Se pueden dar de alta varios bancos, y se pueden editar y eliminar uno por uno.
3. Un banco con nombre pero sin ningún número no se guarda, y el mensaje pide capturar al menos uno
   de los tres.
4. Un banco sin nombre no se guarda.
5. Una CLABE de 18 dígitos con el verificador incorrecto se rechaza; la misma CLABE con el
   verificador correcto se guarda.
6. Una CLABE de 17 o de 19 dígitos se rechaza.
7. Pegar `4152 3133 1234 5678` en el campo de tarjeta lo guarda como `4152313312345678`.
8. Un número de cuenta que empieza con cero conserva el cero al guardarse y al imprimirse.
9. Dos bancos con el mismo nombre se pueden guardar sin error.
10. El PDF de una cotización creada con bancos visibles muestra el bloque "Datos bancarios" en el
    encabezado, a la derecha, debajo del folio, con cada banco en su bloque y un renglón etiquetado
    por dato capturado.
11. Un banco al que solo se le capturó CLABE imprime su nombre y un solo renglón, sin renglones
    vacíos ni guiones.
12. Los bancos se imprimen en el orden en que aparecen en Configuración.
13. Un banco con el interruptor apagado no aparece en las cotizaciones creadas después de apagarlo.
14. Una cotización creada sin ningún banco visible se imprime igual que hoy, sin bloque y sin aviso.
15. Cambiar la CLABE de un banco **no** cambia el PDF de una cotización creada antes: se reimprime
    con la CLABE que tenía al crearse.
16. Eliminar un banco **no** cambia el PDF de las cotizaciones creadas antes.
17. Duplicar una cotización produce una copia con los datos bancarios **vigentes**, no con los del
    original.
18. Los PDF de factura y de orden de compra salen sin ningún bloque de datos bancarios y sin ningún
    otro cambio respecto de [019](019-formato-pdf-documentos.md).
19. El PDF que descarga la ruta pública firmada (adjunto de WhatsApp) y el que se manda por correo
    llevan el mismo bloque que el descargado con sesión iniciada.
20. Arrastrar una tarjeta a otra posición cambia el orden y ese orden se refleja en el siguiente PDF;
    los botones de subir/bajar hacen lo mismo.
21. La pantalla de detalle de la cotización no muestra datos bancarios.
22. Los endpoints de datos bancarios responden `401` sin sesión.
23. Se puede subir un logo a un banco, verlo en la lista de Configuración, reemplazarlo y quitarlo.
24. El logo guardado mide como máximo 64 puntos de lado largo aunque se haya subido una imagen de
    1000, y se sirve como WEBP.
25. Un logo que ya medía menos de 64 puntos no se amplía, y un PNG con fondo transparente conserva
    la transparencia después de subirse.
26. Un archivo que no es una imagen real —aunque termine en `.png`— se rechaza y no se guarda.
27. Reemplazar el logo de un banco borra el archivo anterior del disco, y la lista muestra el nuevo
    sin vaciar la caché del navegador.
28. Eliminar un banco **no** borra el archivo de su logo, y las cotizaciones creadas antes lo siguen
    imprimiendo.
29. El PDF de una cotización cuyo banco tenía logo imprime el icono a la izquierda del nombre; el de
    un banco sin logo imprime solo el nombre, sin hueco.
30. Si el archivo del logo se borra del disco a mano, la cotización se imprime sin icono, con el
    nombre del banco intacto, y no falla.
31. Cambiar el logo de un banco no cambia el PDF de una cotización creada antes.
32. La dirección del logo solo responde con sesión iniciada; sin autenticar devuelve `401`.
33. La imagen de artículo de [020](020-imagenes-articulos.md) sigue funcionando igual después de
    extraer el procesamiento compartido: su suite de pruebas pasa sin cambios.
34. Pint y ESLint/Prettier corren sin errores sobre el código nuevo, y `npm run build` compila la SPA
    completa.

## Supuestos asumidos (registro completo)

1. Los datos bancarios son una sección nueva e independiente en Configuración, sin relación con las
   Cuentas de Tesorería de [010](010-tesoreria.md): no suman saldos ni generan movimientos.
2. La sección es una lista: se agregan bancos uno por uno, se editan y se eliminan, sin límite.
3. Los datos pertenecen al negocio emisor, no a cada cotización: se capturan una vez y valen para
   todas.
4. Los cuatro campos pedidos son nombre del banco, número de cuenta, tarjeta y clave interbancaria.
5. `nombre_banco` es obligatorio; los tres números son opcionales, para poder registrar un banco del
   que solo se quiere dar la CLABE.
6. Debe capturarse al menos uno de los tres números: un banco sin ningún número no le sirve al
   cliente.
7. Se agrega un quinto campo opcional, **beneficiario/titular**, porque el cliente lo necesita al
   hacer la transferencia.
8. Los números se guardan solo en dígitos, sin separadores; se limpian al capturar para que pegar
   desde la banca en línea funcione.
9. CLABE de 18 dígitos, tarjeta de 15 o 16, cuenta de 6 a 20. Fuera de eso no deja guardar.
10. Se puede repetir el mismo banco: dos cuentas en BBVA son dos renglones.
11. Aparecen únicamente en el PDF de la cotización; factura y orden de compra no los llevan.
12. Se imprimen todos los bancos visibles, en el orden de la lista; no se elige por cotización.
13. **(Redefinido)** El bloque va en el **encabezado, columna derecha, debajo del folio**, con el
    título "Datos bancarios" y cada banco uno bajo otro con un renglón etiquetado por dato. Se
    descartaron el pie del documento, el costado de los totales, el bloque del emisor y la variante
    de "solo el principal arriba y el resto abajo". El costo asumido es que el encabezado crece con
    cada banco; se compensa con el interruptor del punto 25.
14. De cada banco se imprime solo lo que tiene lleno: un campo vacío no deja el renglón con guion.
15. La tarjeta se imprime completa, no enmascarada: el objetivo es que el cliente pueda pagar.
16. Sin bancos, la cotización se imprime igual que hoy, sin bloque, sin aviso y sin bloqueo.
17. **(Invertido por el punto 26)** Ya no se imprimen los datos vigentes: cada cotización imprime la
    foto que guardó al crearse.
18. La cotización enviada por enlace público (adjunto de WhatsApp) y por correo muestra el mismo
    bloque.
19. La pantalla de detalle de la cotización dentro del sistema no muestra los datos bancarios.
20. **(Reemplazado por el punto 25)** El borrado definitivo se conserva, pero el camino normal para
    "ya no lo uso" es apagar el interruptor.
21. Al eliminar un banco se pide confirmación; las cotizaciones ya creadas no se ven afectadas,
    porque llevan su foto.
22. **(Adición técnica)** Los bancos viven en su **propia tabla** `datos_bancarios`, no como claves
    del almacén de configuración de [014](014-costo-elaboracion-goma.md): ese almacén guarda una
    casilla con un valor, y aquí hacen falta cinco datos que van juntos y repetidos N veces.
23. **(Adición técnica)** La tabla **no lleva `user_id`** y sus endpoints no se scopean por usuario,
    igual que el emisor de [019](019-formato-pdf-documentos.md): el negocio que emite es uno solo
    para toda la instalación.
24. **(Adición técnica)** Los números se guardan como **texto**: un número de cuenta que empieza con
    cero perdería el cero como entero, y estos valores nunca se suman ni se comparan.
25. **(Adición técnica)** Cada banco tiene un interruptor **"mostrar en cotizaciones"**. Apagado,
    sigue guardado pero deja de imprimirse. Evita recapturar 18 dígitos de CLABE cuando se vuelve a
    usar una cuenta que se dejó en pausa.
26. **(Adición técnica)** La cotización **congela** los datos bancarios al crearse, en la columna
    `cotizaciones.datos_bancarios`. Reimprimir un documento viejo da el mismo papel que recibió el
    cliente. `duplicar` toma foto nueva, porque una copia es una cotización que sale hoy. El costo
    aceptado es que corregir un dato no arregla las cotizaciones ya creadas: para eso se duplica.
27. **(Adición técnica)** La CLABE se valida con su **dígito verificador** (pesos 3-7-1 sobre los 17
    primeros dígitos), única forma de atrapar un dedo chueco antes de que el dato salga impreso. La
    tarjeta no se valida con Luhn y el número de cuenta no tiene formato común entre bancos.
28. **(Adición técnica)** Los bancos se **reordenan arrastrándolos**, con los eventos nativos del
    navegador y **sin agregar ninguna librería**. Como el arrastre nativo no funciona en pantallas
    táctiles, cada tarjeta lleva además botones de subir y bajar; ambos caminos usan el mismo
    endpoint `PUT /api/v1/datos-bancarios/orden`.
29. La migración **no rellena hacia atrás** la foto de las cotizaciones existentes: son datos de
    prueba y no hay producción que rescatar. Una cotización sin foto se imprime sin bloque.

### Segunda entrega: el logo del banco

30. Cada banco puede llevar **un logo, opcional**; sin él todo funciona igual, solo sin icono.
31. Se sube desde el **mismo diálogo** de alta/edición, con vista previa y botón de quitar.
32. **Uno por banco**: subir otro reemplaza al anterior sin preguntar.
33. Formatos JPG, PNG y WEBP, **comprobados por contenido** y no por la terminación del archivo.
34. Máximo **2 MB** de entrada. Es un logotipo, no una fotografía.
35. Se guarda **reducido a 64 puntos de lado largo**, en WEBP, y el original se descarta. La
    reducción ocurre **al guardar**, no al imprimir: encoger el original en el PDF dejaría el
    archivo completo dentro de cada cotización y engordaría cada correo por cada banco.
36. Un PNG con **fondo transparente conserva la transparencia**, para que el icono no salga con un
    recuadro blanco sobre el papel.
37. El sistema **no trae catálogo de logos** de bancos ni los descarga: serían marcas de terceros
    distribuidas dentro del proyecto, y la lista envejecería sola.
38. El icono se imprime **a la izquierda del nombre del banco**, en el mismo renglón.
39. Se imprime a **5 mm de alto**, la altura del renglón: un icono, no una imagen.
40. Un banco **sin logo** imprime su nombre igual, sin hueco ni marcador.
41. Si el archivo **ya no está en disco**, la cotización se imprime sin icono, sin fallar y sin
    avisar al usuario (queda anotado en el log), igual que hace hoy el logo del emisor.
42. En Configuración el icono se ve junto al nombre, para reconocer cada cuenta de un vistazo.
43. El logo entra en la **foto congelada**: una cotización vieja reimpresa muestra el que tenía.
44. **Al eliminar un banco su archivo de logo se conserva**, para que las cotizaciones ya creadas lo
    sigan imprimiendo. Reemplazarlo por otro sí borra el anterior.
45. **(Adición técnica)** Se **extrae `ProcesadorImagen` de `ImagenArticuloService`** en vez de
    escribir un segundo redimensionador. [020](020-imagenes-articulos.md) ya pagó las trampas de GD
    —canal alfa, liberación de recursos, comprobación por contenido— y una copia se habría quedado
    atrás en la primera corrección. `ImagenArticuloService` conserva su interfaz y su `LADO_MAXIMO`
    de 1200; el logo entra por `LogoBancoService`, su hermano.
46. **(Adición técnica)** El logo vive en el **disco privado** y se sirve por una ruta autenticada
    (`GET /api/v1/datos-bancarios/{dato}/logo`), como los logos del emisor de
    [019](019-formato-pdf-documentos.md) y la imagen de artículo de
    [020](020-imagenes-articulos.md). El nombre del archivo lo genera el sistema, con 8 caracteres
    al azar que se exponen como `logo_version` para que el navegador nunca muestre uno reemplazado.
47. **(Adición técnica)** La foto congelada guarda la **ruta** del logo, no la imagen en base64.
    Meter el archivo dentro del JSON pondría una copia del mismo icono en cada cotización —varias
    por documento si hay varios bancos— cuando la ruta ya es inmutable en la práctica: reemplazar
    un logo escribe un archivo nuevo con otro nombre, y eliminar el banco no borra el viejo.
48. **(Adición técnica)** El icono se **incrusta en base64** al generar el PDF, resuelto por un
    método del modelo `Cotizacion` y no dentro de la plantilla, para que la vista no toque el disco.
    El renglón se arma con una **tabla de dos celdas**, porque dompdf no coloca de forma fiable una
    imagen en línea dentro de un párrafo alineado a la derecha.
49. **(Divergencia consciente con 020)** En el alta, el diálogo **crea el banco y después sube el
    logo**, en dos peticiones, en vez de deshabilitar el bloque hasta que el banco exista. Allá el
    formulario es una pantalla que sigue abierta tras guardar; aquí es un diálogo que se cierra, y
    deshabilitarlo obligaría a guardar, reabrir y volver a entrar solo para poner el icono.
