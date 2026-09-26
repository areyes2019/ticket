# Spec: Imágenes de artículos (carga masiva y ficha visual)

## Historia de usuario

Como usuario único del sistema, quiero subir masivamente las imágenes de mis productos y que cada
una se asocie sola al artículo que le corresponde, para que al abrir la lista de precios pueda ver
la foto de cada producto sin haberlas capturado una por una.

*Ampliada el 2026-08-18:* y quiero que **la subida de artículos y la de imágenes sean dos procesos
separados**, cada uno con su archivo y su pantalla, en vez de dos pasos de uno solo donde el segundo
depende de que el primero se haya hecho.

## Objetivo / Alcance

Agregar una imagen por artículo sobre la estructura ya existente de `Articulo`
([006-gestion-articulos.md](006-gestion-articulos.md), [009-catalogos.md](009-catalogos.md),
[011-precio-proveedor-utilidad.md](011-precio-proveedor-utilidad.md)), con una **carga masiva** que
empareja archivos con artículos por el nombre del archivo, y una **ficha visual** en modal que se
abre desde el listado `/articulos` — que es la lista de precios del sistema (ver
[011](011-precio-proveedor-utilidad.md), supuesto 1).

La carga masiva de **artículos** ya existe: es la importación CSV por catálogo de
[009](009-catalogos.md). Esta historia agrega la mitad que falta, la de las imágenes, y el lugar
donde se ven.

**Revisión del 2026-08-18 — las dos cargas masivas se separan.** El emparejamiento, el formato de
salida, el disco privado y la ficha visual se quedan exactamente como estaban: **el backend no
cambia en nada**. Lo que cambia es dónde se hace cada carga.
[023](023-carga-masiva-por-pasos.md) las había fusionado en un solo modal, con el catálogo elegido
una vez y dos pasos numerados, y con un candado que impedía subir fotos a un catálogo que todavía no
tenía artículos. Ahora son **dos pantallas independientes**, cada una con su propio catálogo, y el
candado se vuelve un aviso que se puede ignorar. Está todo en *Las dos cargas masivas son dos
pantallas aparte*, más abajo.

No se agrega ningún campo de datos nuevo al artículo, ni las imágenes aparecen en los PDF de
[019](019-formato-pdf-documentos.md).

## Requisitos del entorno

La implementación depende de dos extensiones de PHP que **deben confirmarse antes de escribir
código**, en local y en el servidor de producción:

- **GD con soporte WEBP** (`gd_info()['WebP Support']`, `imagewebp`), para reducir las imágenes al
  subirlas y guardarlas en WEBP.
- **ZipArchive**, para leer el `.zip`.

**Verificado en local el 2026-08-12** (PHP 8.3.30 de Laragon): `gd` sí, `WebP Support` sí,
`imagewebp`/`imagecreatefromwebp` sí, `zip` sí, `fileinfo` sí. Falta comprobarlo en Hostinger antes
de desplegar. La misma comprobación reveló `max_file_uploads = 20`, que fija el tamaño de tanda de
la carga masiva (ver más abajo).

Ambas son estándar y vienen habilitadas en la mayoría de las instalaciones, pero el plan compartido
de Hostinger ya demostró tener funciones desactivadas que rompen supuestos razonables (ver
[018-despliegue-hostinger.md](018-despliegue-hostinger.md): `proc_open`, `exec` y `symlink`). Qué
hacer si falta cada una:

- Sin `ZipArchive`, la carga por selección múltiple funciona igual y solo se cae el `.zip`.
- Sin soporte WEBP en GD, el formato de salida cae a **JPEG calidad 82** y el resto de la spec no
  cambia. El costo es real —archivos entre 25% y 35% más grandes, transparencia perdida y una
  recompresión de más sobre material que ya venía en WEBP— pero no bloquea nada.
- Sin `GD` del todo, hay que reconsiderar la reducción de tamaño, que es lo que hace viable mostrar
  fotos de celular en un plan compartido.

## Backend (Laravel)

### Dónde viven los archivos

En el **disco privado** (`Storage::disk('local')`), bajo `Articulo::DIRECTORIO_IMAGENES`
(`articulos`), y se sirven a través de una ruta de Laravel que primero valida la sesión. Es
exactamente el patrón que ya usa el logo del emisor en [019](019-formato-pdf-documentos.md)
(`EmisorController::verLogo`, `Emisor::contenidoLogo`), y se elige por dos razones que en este
proyecto no son teóricas:

- **`php artisan storage:link` no funciona en producción.** Hostinger tiene `symlink` desactivada
  ([018](018-despliegue-hostinger.md)), así que el mecanismo normal de Laravel para publicar
  archivos subidos no existe aquí.
- **Cualquier archivo dentro del docroot se borra en el siguiente despliegue.**
  [`deploy/deploy-frontend.sh`](../deploy/deploy-frontend.sh) corre `borrar_sobrantes` sobre
  `public_html/` y elimina todo lo que no venga en el build recién subido, salvo `.htaccess`,
  `index.php` y `robots.txt`. Las fotos no están en git ni en ningún respaldo: guardarlas ahí las
  condena a desaparecer la primera vez que se despliegue un cambio del SPA, sin nada de dónde
  recuperarlas.

Vivir fuera del docroot resuelve las dos de un golpe, y de paso mantiene la regla de que todo el
sistema está detrás del login.

### Esquema

- **Nueva columna `articulos.imagen_ruta`**: `string`, nullable. Ruta relativa del archivo dentro
  del disco privado; `null` significa "sin imagen". Mismo criterio y mismo nombre de sufijo que
  `emisor.logo_ruta`.
- **Deliberadamente fuera de `#[Fillable]`**, igual que `precio_con_descuento` en
  [009](009-catalogos.md) y las columnas de inventario en [017](017-inventario.md): no es un dato
  que el cliente envíe en el `POST`/`PUT` del artículo, sino el resultado de haber guardado un
  archivo. La asigna el controlador de imágenes y nadie más.
- No se agregan campos de descripción, medida ni ningún otro texto: el modal muestra lo que el
  artículo ya tiene.

### Normalización y guardado de cada imagen

Toda imagen que entra, venga de donde venga (carga masiva o formulario individual), pasa por el
mismo servicio y sale igual:

- **Se comprueba por contenido, no por la terminación del archivo.** Un archivo llamado
  `producto.jpg` puede ser cualquier cosa; se confirma que realmente sea JPEG, PNG o WEBP leyendo
  sus bytes (`getimagesize`/`finfo`). Lo que no lo sea se descarta y se reporta.
- **Se reduce a 1200 puntos de lado largo** si los excede, conservando la proporción. Nunca se
  amplía una imagen chica. Una foto de celular ronda los 4000 puntos y 4 MB; a 1200 puntos ocupa
  una fracción de eso y sigue viéndose bien en el modal y al compartirla. La lista de precios
  cargaría decenas de megas por página sin esta reducción, y el plan compartido paga ese tráfico
  en lentitud para todo el sitio.
- **Se reescribe siempre como WEBP** (calidad 82), con extensión `.webp` y `Content-Type`
  `image/webp` fijo. Un solo formato de salida mantiene el servicio del archivo simple, y **WEBP es
  el formato en el que ya viene la mayoría del material**: convertirlo a JPEG lo haría entre 25% y
  35% más pesado, lo pasaría por una segunda compresión con pérdida sobre una que ya la tenía, y le
  quitaría la transparencia a cualquier recorte. WEBP conserva la transparencia y lo soporta
  cualquier navegador vigente.

  El formato de salida no obliga a husmear los bytes del archivo al servirlo, como hace el logo del
  emisor en [019](019-formato-pdf-documentos.md): aquí el nombre del archivo lo genera el sistema,
  así que la terminación la fija el propio proyecto y el `Content-Type` sale de ahí.
- Como la imagen se **regenera** en vez de copiarse, cualquier cosa escondida dentro del archivo
  original queda fuera del que se guarda.
- **El nombre con el que se guarda lo genera el sistema**: `{articulo_id}-{8 caracteres al
  azar}.webp`. El nombre que subió el usuario cumple su único trabajo —decir a qué artículo
  pertenece— y después se olvida. Guardar el nombre original tendría dos costos: dos proveedores
  distintos pueden mandar `1.jpg` y el segundo pisaría al primero, y sobre todo, al reemplazar una
  foto por otra con el mismo nombre **el navegador seguiría mostrando la vieja**, porque la tiene
  guardada y no tiene forma de enterarse de que cambió. Un nombre nuevo por cada subida vuelve
  imposible ese caso.
- **Al reemplazar una imagen, el archivo anterior se borra en el mismo acto**, igual que los logos
  del emisor en [019](019-formato-pdf-documentos.md); sin eso el directorio acumula todas las fotos
  que el usuario haya probado.
- **Eliminar un artículo no borra su imagen.** El borrado es lógico (`SoftDeletes`): el archivo se
  queda por si el artículo se restaura.
- **Tamaño máximo por imagen individual: 10 MB.** Una fotografía de producto que pese más no es una
  fotografía de producto.

### Emparejamiento por nombre de archivo

La carga masiva asocia cada archivo al artículo cuyo **`modelo`** coincide con el nombre del
archivo sin su extensión: `A-1234.jpg` va al artículo de modelo `A-1234`.

- La comparación se hace sobre una **forma normalizada** de ambos lados: minúsculas, sin acentos,
  sin espacios sobrantes al inicio y al final, y con toda corrida de espacios, guiones y guiones
  bajos colapsada a un solo espacio. Así `a 1234.JPG`, `A-1234.jpg` y `A_1234.jpeg` encuentran al
  mismo artículo. La extensión no participa nunca de la comparación.
- **El emparejamiento se hace dentro del catálogo elegido antes de subir**, no contra todos los
  artículos del sistema. Dos proveedores pueden usar el mismo modelo sin pisarse, y el usuario sabe
  siempre contra qué se está comparando.
- **`modelo` no es único** ([006](006-gestion-articulos.md) lo define como texto libre). Si dentro
  del catálogo hay más de un artículo cuyo modelo normalizado coincide con el archivo, **la imagen
  no se asigna a ninguno** y se reporta el caso nombrando el modelo ambiguo. Elegir uno al azar
  dejaría la foto en el artículo equivocado sin que nadie se entere, que es peor que no ponerla.
- **Un archivo que no encuentra artículo se descarta y se reporta.** Nunca crea un artículo nuevo.
- **Un artículo sin imagen no es un error** y no aparece en el reporte: la ficha simplemente muestra
  el marcador de "sin imagen".
- **Si dos archivos de la misma carga apuntan al mismo artículo, gana el último procesado** y el
  desplazado se reporta con su nombre. Es la misma regla que el reemplazo manual (subir una imagen
  para un artículo que ya tenía la sustituye), y por eso se comporta igual aunque los dos archivos
  caigan en tandas distintas.

### Límites de la carga masiva

El servidor tiene topes que no fija el proyecto: cuántos megas acepta de un jalón
(`upload_max_filesize`, `post_max_size`) y cuánto puede durar una petición (`max_execution_time`).
Doscientas fotos de celular son unos 800 MB y los rebasan varias veces; lo que ocurre entonces no es
un mensaje claro, es una subida cortada a la mitad sin saber qué alcanzó a entrar.

- **Selección múltiple: el frontend la parte en tandas automáticas** de **20 archivos** o 40 MB, lo
  que se alcance primero, y las manda una tras otra. Cada tanda es una petición independiente al
  mismo endpoint; el frontend acumula los resultados y muestra **un solo reporte** al final. El
  usuario selecciona todas las fotos que quiera y no se entera de las tandas.

  **El tope de 20 no es arbitrario: es `max_file_uploads` de PHP**, cuyo valor por defecto es
  exactamente 20 (confirmado en el entorno local, PHP 8.3.30). Lo grave de rebasarlo es cómo falla:
  PHP **descarta en silencio** los archivos que pasan de ese número — no hay error, no hay aviso, y
  el reporte diría "20 asociadas" sobre una tanda de 50 sin mencionar las 30 que nunca llegaron. Una
  tanda más grande que este tope no puede detectarse desde el servidor, porque los archivos de más
  ya no existen cuando la petición llega.

  El backend **rechaza con `422` cualquier petición que traiga más de 20 archivos**, para que un
  cliente que ignore el límite falle de forma visible en vez de perder fotos calladamente.
- **ZIP: tope duro de 40 MB por archivo**, comprobado en el navegador antes de mandar nada y
  revalidado en el servidor. Un comprimido viaja entero o no viaja: no se puede partir. Si se pasa,
  se rechaza con un mensaje que pide dividirlo, sin intentar la subida.
- El backend no asume nada del frontend: cada petición valida sus propios límites y responde su
  propio reporte.

### Lectura del `.zip`

- **El ZIP debe venir plano.** Si alguna entrada trae `/` o `\` en su nombre —es decir, si hay
  carpetas dentro—, se rechaza el archivo completo con `422` y un mensaje que pide volver a
  comprimir seleccionando los archivos, no la carpeta. Se rechaza entero en vez de procesar lo que
  se pueda, para que el resultado no dependa de cómo quedó armado el comprimido.
- **De cada entrada se usa únicamente el nombre del archivo, nunca la ruta que trae escrita
  dentro.** Un ZIP guarda, junto a cada archivo, dónde iba, y esa ruta puede pedir escribir fuera
  del directorio de destino. Como además el ZIP plano ya está garantizado por la regla anterior,
  son dos cierres independientes sobre el mismo hueco.
- **Tope de expansión.** Se descarta el ZIP cuyo contenido descomprimido supere los 400 MB, leyendo
  el tamaño declarado en su índice antes de extraer nada. Protege del accidente de comprimir la
  carpeta equivocada y llenar el disco del plan compartido.
- Las entradas que no resulten ser JPEG, PNG o WEBP al comprobarse por contenido se ignoran y se
  reportan, una por una, con su nombre.

### Endpoints

Todos bajo `auth:sanctum` y scopeados al usuario autenticado, como el resto del sistema.

- `GET /api/v1/articulos/{articulo}/imagen` — devuelve el binario de la imagen con `Content-Type:
  image/webp`, o `404` si el artículo no tiene. Mismo patrón que `GET /api/v1/emisor/logo/{tipo}`.
  Responde `Cache-Control: private, max-age=604800`: el navegador puede guardarla una semana sin
  riesgo, porque cada reemplazo cambia la dirección (ver `imagen_version` abajo).
- `POST /api/v1/articulos/{articulo}/imagen` — sube o reemplaza la imagen de un artículo
  (`multipart/form-data`, campo `archivo`). Devuelve el `ArticuloResource` actualizado.
- `DELETE /api/v1/articulos/{articulo}/imagen` — quita la imagen y borra el archivo. Devuelve
  `{ "eliminado": true }`, igual que `eliminarLogo`.
- `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/imagenes` — **carga masiva**. Acepta o bien
  un campo `archivos[]` con varias imágenes, o bien un campo `archivo` con un `.zip`; nunca los dos
  en la misma petición. Empareja contra los artículos de `{catalogo}` y responde:

  ```json
  { "asociadas": 12, "errores": [{ "archivo": "B-77.jpg", "motivo": "..." }] }
  ```

  Misma forma que el reporte de la importación CSV de [009](009-catalogos.md)
  (`{ "importados": ..., "errores": [{ "fila": ..., "motivo": ... }] }`), cambiando `fila` por
  `archivo` porque aquí lo que identifica al elemento rechazado es su nombre.

  El endpoint vive bajo el catálogo, junto a
  `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv`, porque es la misma idea: una
  carga masiva dirigida a un catálogo concreto.

- **El motivo de un archivo rechazado nombra el archivo y la causa concreta**, no la regla genérica
  que falló: `no hay ningún artículo con modelo "B-77" en este catálogo`, `hay 2 artículos con
  modelo "B-77" en este catálogo`, `no es una imagen JPG, PNG ni WEBP`, `otro archivo de esta carga
  se asignó al mismo artículo`. Una carga de decenas de archivos con el mismo defecto produce
  decenas de motivos, y sin el dato concreto el usuario no tiene cómo saber qué corregir — es la
  misma lección que dejó la importación CSV en [006](006-gestion-articulos.md).

### Recursos

- **`ArticuloResource`** agrega dos campos:
  - `tiene_imagen`: `bool`.
  - `imagen_version`: `string|null`, los 8 caracteres al azar del nombre del archivo. El frontend lo
    agrega como parámetro a la dirección de la imagen (`?v=...`), de modo que reemplazar una foto
    cambia la dirección y el navegador va por la nueva sin que nadie tenga que vaciar su caché.

  No se expone la ruta interna del archivo: es un detalle del servidor y el cliente no la necesita
  para nada.

### Validaciones (Form Requests)

- `articulo`: debe existir y pertenecer (vía catálogo → proveedor) al usuario autenticado.
- `catalogo` de la carga masiva: mismo criterio, igual que en la importación CSV.
- Subida individual: `archivo` requerido, imagen real de máximo 10 MB.
- Carga masiva: exactamente uno de `archivos[]` o `archivo`; `archivos[]` con máximo **20**
  elementos (`max_file_uploads`) y 40 MB en total; `archivo` con un `.zip` de máximo 40 MB.

## Frontend (Vue 3)

### `/articulos` — la lista de precios

La tabla **no cambia de forma**: sigue mostrando solo datos (nombre, modelo, catálogo, costo,
precio, utilidad y acciones) y **no lleva miniaturas ni columna de imagen**. No hay modo cuadrícula:
meter fotos en la tabla le quitaría la densidad que la hace útil para trabajar.

Lo único que cambia es que **el nombre del producto pasa a ser un enlace** que abre la ficha en un
modal. Es un enlace de texto en la celda que ya existía, sin elementos nuevos en la fila.

### `ArticuloDetalleDialog.vue` (componente nuevo)

Modal sobre el `Dialog` del design system ([003](003-design-system-tailwind.md)), en dos columnas
en escritorio y en una sola apiladas en móvil:

- **Izquierda: la foto**, en grande. Si el artículo no tiene imagen, un marcador de "Sin imagen" que
  ocupa el mismo espacio, para que el modal no cambie de tamaño según haya o no foto.
- **Derecha: los datos** — nombre, modelo y **precio con IVA** (`precio_unitario_con_iva`). Nunca el
  precio del proveedor, el costo ni la utilidad: la ficha es lo que se le enseña o se le manda a un
  cliente.
- **Abajo a la derecha: el botón "Compartir"** (ver siguiente sección).
- Un botón **"Editar"** que lleva a `/articulos/:id/editar`, para no perder el acceso que antes daba
  el clic en la fila.
- Se aplican las reglas de `Dialog` con contenido dinámico de
  [003](003-design-system-tailwind.md): contenedores con `min-w-0` y la imagen con `max-w-full`, de
  modo que un nombre largo o una foto muy ancha no desborden el modal.

### El botón "Compartir"

Se comporta distinto según el aparato, porque las dos plataformas ofrecen cosas distintas:

- **En celular**: abre el menú de compartir del propio sistema (`navigator.share`), llevando **la
  foto y el texto**. Es el único camino por el que una imagen puede salir hacia WhatsApp desde una
  página web; una página no puede adjuntarle archivos a WhatsApp por su cuenta.

  **La foto se convierte a JPEG justo antes de compartirla**, en el navegador (se dibuja en un
  `<canvas>` y se exporta con `toBlob('image/jpeg', 0.9)`); el archivo guardado en el servidor sigue
  siendo el WEBP. La razón es concreta: **WhatsApp trata los archivos `.webp` como calcomanías**, no
  como fotos, así que compartir el WEBP tal cual le llegaría al cliente como sticker en vez de como
  la imagen del producto que se le quiere enseñar. Convertir del lado del navegador evita guardar
  una segunda copia de cada foto en el servidor solo para este caso.
- **En escritorio**: copia **el texto** al portapapeles y avisa "Copiado". No se descarga la foto.
  Se elige esto en vez de intentar el menú nativo porque en escritorio muchos navegadores no lo
  tienen y el resultado sería un botón que a veces hace algo y a veces no.

La decisión se toma comprobando en tiempo real si el navegador puede compartir archivos
(`navigator.canShare({ files })`), no adivinando por el tamaño de la pantalla.

El texto compartido es, en ambos casos: `{nombre} — Modelo {modelo} — ${precio con IVA}`.

### Las dos cargas masivas son dos pantallas aparte

*Revisión del 2026-08-18.* Esta sección reemplaza al modal fusionado de
[023](023-carga-masiva-por-pasos.md) y a la que esta misma spec traía antes bajo el título "Modal de
carga masiva de imágenes".

#### Qué se deshace y qué se rescata

[023](023-carga-masiva-por-pasos.md) juntó las dos cargas porque el orden —CSV primero, fotos
después— **no es una preferencia sino un requisito**: una foto que no encuentra artículo se descarta,
y quien subía las fotos primero no recibía un aviso, recibía doscientos errores idénticos. La
observación era correcta y **sigue siéndolo**; lo que cambia es quién carga con ella.

El modal fusionado hizo que **una sola pantalla tuviera dos trabajos**: dos archivos, dos botones,
dos reportes largos capaces de estar en pantalla al mismo tiempo, y un cuerpo con scroll propio para
que el pie no se fuera de la vista. Y el candado del paso 2 —el que se apagaba con el catálogo
vacío— resolvía un caso a costa de volver imposible un acto que el usuario podía tener razones para
querer: mandar el ZIP y leer el reporte.

Se separan, entonces, y **lo que 023 dejó de útil no se pierde**, porque nunca dependió de que las
dos cargas vivieran juntas:

- El **`modelo` en la fila rechazada del CSV**, que es lo que permite cruzar los dos reportes: el de
  imágenes nombra archivos y el del CSV nombraba solo renglones.
- El botón **"Copiar reporte"** en ambas pantallas, porque el reporte sigue siendo efímero y no hay
  ninguna otra pantalla donde volver a verlo.
- El **encabezado del reporte cuando ninguna imagen emparejó**, que interpreta el resultado en vez
  de enumerarlo doscientas veces.

#### El botón de la barra

`/articulos` quedaría con cuatro entradas —importar, subir fotos, exportar y dar de alta— y esa
barra ya se apretó cuando el mostrador entró al celular ([029](029-pwa-mostrador.md),
[031](031-mostrador-consulta.md)). Se resuelve con **un solo botón, "Archivos", que despliega tres
opciones**:

- **Importar artículos (CSV)**
- **Subir imágenes (ZIP)**
- **Exportar CSV**

Se usa el `DropdownMenu` del design system ([003](003-design-system-tailwind.md)), que ya está en el
proyecto.

**"Nuevo artículo" se queda suelto y a la vista.** Es la acción de todos los días y la única que no
tiene que ver con archivos; esconderla detrás de un menú sería pagar el desorden de la barra con la
tarea más frecuente.

**"Exportar CSV" entra al menú** aunque sea una salida y no una entrada, y aunque
[023](023-carga-masiva-por-pasos.md) lo hubiera dejado deliberadamente fuera de su modal. Ahí la
razón era que el modal ordenaba dos actos en secuencia y exportar no tiene orden respecto de nada;
un menú no ordena nada, solo agrupa. Y lo que agrupa a las tres no es la dirección en que viaja el
archivo, sino que ninguna de las tres es aquello a lo que se entra a esta pantalla el resto del
tiempo.

#### Dos pantallas que no se conocen

Cada opción del menú abre **su propio `Dialog`**, con su propio selector de catálogo, su propio
archivo, su propio botón y su propio reporte. **Ninguna de las dos hereda nada de la otra**: el
selector arranca vacío en ambas, elegir catálogo en una no lo elige en la otra, y ninguna ofrece al
terminar continuar hacia la otra.

Que el catálogo no se herede es deliberado y **es lo que hace que las pantallas sean de verdad
independientes**. Un selector que llega con algo puesto obliga a leerlo antes de usarlo; uno que
llega vacío obliga a llenarlo, que es más trabajo pero no admite el error de no haberlo mirado.

- **Importar artículos (CSV)**: selector de catálogo, las columnas esperadas, el `<input
  type="file">` y el reporte de [009](009-catalogos.md) —`importados` y una línea por fila
  rechazada, con su número de fila, su **modelo** y su motivo.
- **Subir imágenes (ZIP)**: selector de catálogo, la elección de archivos —**un `.zip` o varias
  imágenes a la vez**, las dos formas que ya existían—, la **barra de avance** mientras se mandan
  las tandas de 20, y **un solo reporte al terminar** aunque hayan sido varias tandas.

Los dos modales siguen las reglas de layout de [003](003-design-system-tailwind.md) y
[006](006-gestion-articulos.md): contenedores con `min-w-0`, las columnas del CSV en un `<code>` con
`overflow-x-auto`, los `<input type="file">` truncados y **el cuerpo con scroll propio**, con el
selector de catálogo y el pie siempre visibles. Cada modal carga ahora con un solo reporte en vez de
dos, pero un reporte de cien archivos rechazados sigue siendo capaz de empujar el botón de cerrar
fuera de la pantalla.

**Cambiar de catálogo reinicia la pantalla**: archivo elegido, errores y reporte. Un reporte que
sobrevive al cambio de catálogo afirma algo cierto sobre un catálogo que ya no es el que está en
pantalla.

#### El hueco que 023 había cerrado, y dónde se cierra ahora

Dos selectores independientes vuelven posible **importar el CSV a un catálogo y las fotos a otro**.
El sistema hace exactamente lo que se le pidió, el reporte dice "0 imágenes asociadas" y nada en la
pantalla se parece a un error. Es el mismo hueco que la fusión de 023 había tapado, y separar las
pantallas lo vuelve a abrir. No se pretende lo contrario.

Se cierra en los dos únicos momentos en que es detectable sin estorbar: **antes de mandar el ZIP**,
con el aviso del catálogo vacío, y **después**, con el encabezado del reporte cuando ninguna imagen
emparejó. El primero atrapa el caso más común —el catálogo cuyo CSV todavía no se importó— y el
segundo atrapa el resto, incluido el catálogo equivocado que sí tenía artículos.

#### El aviso del catálogo vacío

El candado de [023](023-carga-masiva-por-pasos.md) —el paso 2 deshabilitado con el mensaje "empieza
por el paso 1"— **se quita**. En su lugar, elegir en la pantalla de imágenes un catálogo **sin
ningún artículo** muestra, junto al selector y antes de mandar nada:

> **Sellos Colop no tiene ningún artículo.** Ninguna de estas fotos va a encontrar a quién
> pertenecer. Empieza por importar los artículos.

El botón de subir **sigue habilitado** y dice **"Subir de todos modos"**.

Tres razones para avisar en vez de bloquear:

- **El mensaje del candado ya no apunta a nada.** Dentro del modal fusionado, "empieza por el paso
  1" señalaba algo que estaba ahí, dos centímetros más arriba. Con las pantallas separadas señala
  otra pantalla, y un bloqueo que remite a un lugar que no se ve es una puerta cerrada con un
  letrero que hay que ir a buscar.
- **El aviso dice exactamente lo mismo que decía el candado, pero no necesita tener razón.** El
  conteo puede venir de hace un minuto; el usuario puede querer ver el reporte; puede estar
  probando. Un bloqueo apuesta a que el sistema sabe más que quien lo usa, y aquí no es cierto.
- **Lo que se pierde por equivocarse es una subida, no un dato.** El ZIP sigue en su carpeta y
  ninguna foto llegó a escribirse en el servidor. El aviso protege del desperdicio, no de una
  pérdida.

**El aviso no dice cuántas fotos se van a descartar.** Con un `.zip` el navegador no puede saber
cuántas imágenes trae adentro sin abrirlo, así que un aviso que prometiera un número tendría que
callarse justo en el caso principal, o decirlo solo a veces. Dice qué va a pasar, no a cuántas.

El conteo sale de `articulos_count`, que ya viaja en el listado de catálogos que
`CatalogoSelect.vue` consulta ([023](023-carga-masiva-por-pasos.md)); **no hace falta ningún
endpoint nuevo**.

### Formulario de artículo (`/articulos/crear` y `/articulos/:id/editar`)

Se agrega un bloque de imagen que permite **ver la foto actual, reemplazarla y quitarla**. La carga
masiva resuelve el volumen; esto resuelve el caso de una sola foto que salió mal, sin obligar a
armar un ZIP para corregir un producto.

En el alta, el bloque queda deshabilitado hasta que el artículo exista: la imagen se guarda contra
un artículo ya creado, y hacerlo de otro modo obligaría a sostener archivos huérfanos.

## Fuera de alcance

- **Varias imágenes por artículo** (galería, orden manual, imagen principal entre varias). Una por
  artículo.
- **Imágenes en los PDF** de cotización, factura y orden de compra —
  [019](019-formato-pdf-documentos.md) no cambia.
- **Catálogo público o página para el cliente**: no hay ninguna dirección que se pueda abrir sin
  iniciar sesión. Lo que sale hacia afuera sale por el botón "Compartir", pieza por pieza.
- **Modo cuadrícula o galería en `/articulos`**: el listado sigue siendo la tabla.
- **Miniaturas**: como la tabla no muestra imágenes, no hay dónde usarlas. Se guarda una sola
  versión reducida por artículo.
- **Conservar la imagen original** subida por el usuario: se descarta después de generar la versión
  reducida.
- **Recortar, rotar o editar la imagen** dentro del sistema.
- **Campos nuevos de texto** en el artículo (descripción, medidas, dimensiones).
- **Que la importación CSV actualice artículos existentes**: sigue dando de alta solamente, como en
  [006](006-gestion-articulos.md)/[009](009-catalogos.md).
- **Emparejar por un campo distinto de `modelo`**, o por una columna del CSV que nombre el archivo.
- **Descargar la foto desde el botón Compartir en escritorio.**
- **Guardar las fotos que no encontraron artículo** para pegarlas después, cuando el artículo exista.
  Se evaluó y se descartó: una foto sin dueño tendría que vivir en algún lado, aparecer en alguna
  pantalla para poder borrarla, caducar en algún plazo y decidir qué hacer cuando el modelo que
  esperaba se importa mal escrito. Todo eso para ahorrar volver a arrastrar un ZIP que sigue en su
  carpeta.
- **Que una foto sin artículo dé de alta un artículo en blanco** con solo el modelo. El CSV es la
  única puerta de alta masiva y sigue siéndolo.
- **Un solo archivo que traiga el CSV y las fotos juntos**, o una pantalla que reciba los dos y los
  procese en orden con un solo botón.
- **Que una pantalla herede el catálogo de la otra**, o que al terminar ofrezca continuar en la otra.
- **Un tercer acto de "emparejar" con su propio botón.** El emparejamiento ocurre dentro de la
  subida de imágenes, como siempre; no es un paso que el usuario dispare.
- **Que el servidor rechace una carga de imágenes a un catálogo vacío.** El aviso es del navegador y
  se puede ignorar a propósito; el servidor reporta archivo por archivo, como con cualquier otro
  catálogo.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Estado de implementación

Implementada el 2026-08-12; la revisión que separa las dos cargas masivas, el 2026-08-18. Lo que
sigue describe primero la implementación original —vigente en todo lo que la revisión no toca— y
después la revisión.

- **Entorno verificado antes de escribir código**, como pedía la sección de requisitos: PHP 8.3.30
  con `gd` (WebP Support activo, `imagewebp` e `imagecreatefromwebp` disponibles), `zip` y
  `fileinfo`. La salida WEBP no necesitó el respaldo a JPEG. **Falta comprobarlo en Hostinger.**
- **`max_file_uploads = 20` cambió el tamaño de tanda**, que la spec fijaba en 50. Se descubrió en
  esa misma comprobación y está documentado arriba: con 50, PHP habría descartado 30 archivos por
  tanda sin error ni mención en el reporte.
- **Archivos nuevos**: `app/Services/ImagenArticuloService.php` (puerta única de entrada de toda
  imagen), `app/Services/CargaMasivaImagenesService.php` (emparejamiento y ZIP),
  `app/Http/Controllers/ArticuloImagenController.php`,
  `app/Http/Requests/Articulos/SubirImagenArticuloRequest.php` y `CargarImagenesRequest.php`,
  `database/migrations/2026_08_12_000100_add_imagen_a_articulos_table.php`,
  `tests/Feature/ArticuloImagenesTest.php`, `frontend/src/components/ArticuloDetalleDialog.vue`.
- **Transparencia conservada con redimensionado manual**: `imagescale` no permite apagar la mezcla
  de capas, y un recorte con fondo transparente salía con **fondo negro** al reducirse. Se hace con
  `imagecreatetruecolor` + `imagealphablending(false)` + `imagesavealpha(true)` +
  `imagecopyresampled`, que sí preserva el canal alfa hasta la codificación WEBP.
- **Los recursos de GD se liberan en `finally`**: una carga masiva procesa hasta veinte imágenes en
  la misma petición, y una fuga por cada archivo agotaría la memoria antes del final.
- **`API_URL` pasó a exportarse desde `frontend/src/lib/http.ts`**: la imagen se pinta con un `<img>`
  apuntando al endpoint, no con una descarga por axios, así que la URL hay que componerla fuera del
  cliente HTTP. La cookie de sesión viaja igual en desarrollo (Vite en 5173 y la API en 8000 son el
  mismo *site*, el puerto no cuenta) y en producción, donde todo comparte origen.
- **Verificación end-to-end**: la suite Pest completa (387 tests, 20 nuevos de
  `ArticuloImagenesTest`) pasa; Pint, ESLint, Prettier y Vitest (50 tests) corren limpios, y
  `npm run build` compila la SPA con `vue-tsc` sin errores. Se levantó `php artisan serve` real y se
  probó por HTTP con un usuario y token de Sanctum de prueba (creados y eliminados al terminar):
  carga masiva emparejando `e2e 1.JPG` contra el modelo `E2E-1` y `E2E-2.webp` contra `E2E-2`,
  reporte del archivo sin artículo, entrega de la imagen con `Content-Type: image/webp` y
  `Cache-Control: private` reducida de 3000×2000 a 1200×800 (92 KB → 2 KB), `401` sin token válido,
  ZIP plano que reemplaza la imagen anterior y borra su archivo del disco, ZIP con carpetas
  rechazado con `422` y su mensaje, subida y borrado individual con `tiene_imagen` e
  `imagen_version` en el recurso, y un archivo con extensión de imagen pero contenido que no lo es
  reportado sin tumbar la tanda. **No se pudo verificar visualmente la UI en un navegador real**
  (misma limitación de entorno que el resto de las historias) — falta abrir `/articulos` para
  confirmar el modal de ficha, el botón "Compartir" en un celular real, la barra de avance de la
  carga masiva y el bloque de imagen del formulario.

### Cargas separadas (2026-08-18)

- **Un solo archivo modificado**: `frontend/src/views/ArticulosListView.vue`. **El backend no se
  tocó** —ni un controlador, ni un servicio, ni un request, ni una migración, ni una prueba de
  Pest— y `CatalogoSelect.vue` tampoco hizo falta: desde [023](023-carga-masiva-por-pasos.md) ya
  expone el catálogo elegido completo, que es de donde sale `articulos_count`.
- **El conteo se refresca solo, sin código.** [023](023-carga-masiva-por-pasos.md) necesitaba
  `catalogoSelect.recargar()` tras importar, porque el modal fusionado seguía abierto con el mismo
  `CatalogoSelect` montado. Con las pantallas separadas, `DialogContent` va dentro de un
  `DialogPortal` que se desmonta al cerrar, así que el `CatalogoSelect` de la pantalla de imágenes se
  monta cada vez que se abre y pide el listado entonces. Se quitó la llamada y el `ref` que la
  sostenía; `defineExpose({ recargar })` se deja en el componente, que no es de esta pantalla.
- **El botón de la barra avisa que está exportando.** Exportar dejó de ser un botón con texto propio
  y pasó a ser una opción de un menú que se cierra al elegirla, así que el "Exportando..." se quedó
  sin dónde vivir. Se pasó al disparador del menú, que además se deshabilita mientras tanto: sin eso,
  exportar sería la única de las tres operaciones sin ninguna señal de que arrancó.
- **Un separador entre las dos entradas y la salida** dentro del menú. La spec no lo fijaba; agrupa
  sin ordenar, que es justo lo que se le pide al menú.
- **La cuenta de artículos del catálogo se muestra solo en la pantalla de imágenes**, debajo del
  selector, y solo cuando no es cero —cuando es cero, su lugar lo ocupa el aviso—. En la pantalla del
  CSV no se muestra: ahí el dato no decide nada y quedaría viejo en cuanto la importación terminara.
- **Verificación**: Prettier y ESLint limpios sobre el archivo modificado, `npm run build` compila la
  SPA con `vue-tsc` sin errores, los 89 tests de Vitest pasan y la suite de Pest pasa completa (571
  tests) **sin haber tocado ninguna prueba**, que es la comprobación de que el backend quedó igual.

#### Pendiente de verificación visual

**No se pudo verificar en un navegador real** (misma limitación de entorno que el resto de las
historias) — falta abrir `/articulos` para confirmar el menú "Archivos" en escritorio y en móvil, que
los dos modales arrancan sin catálogo, el aviso del catálogo vacío con su botón "Subir de todos
modos", y que el scroll del cuerpo deja alcanzable el pie con un reporte largo.

## Criterios de aceptación

1. Subir una imagen desde el formulario de un artículo la guarda, y al abrir la ficha de ese
   artículo la foto se ve.
2. La imagen guardada mide como máximo 1200 puntos de lado largo, aunque se haya subido una foto de
   4000 puntos, y se sirve como WEBP.
3. Una imagen que ya medía menos de 1200 puntos no se amplía, y un WEBP con fondo transparente
   conserva la transparencia después de subirse.
4. La dirección de la imagen solo responde con sesión iniciada; sin autenticar devuelve `401`, y
   nunca es alcanzable escribiendo una dirección dentro del sitio público.
5. Reemplazar la imagen de un artículo borra el archivo anterior del disco y la ficha muestra la
   nueva foto de inmediato, sin vaciar la caché del navegador.
6. Quitar la imagen de un artículo deja al artículo sin foto y borra el archivo; la ficha muestra el
   marcador "Sin imagen".
7. Una carga masiva por selección múltiple asocia cada archivo al artículo del catálogo cuyo modelo
   coincide con el nombre del archivo, ignorando mayúsculas, acentos, y la diferencia entre
   espacios, guiones y guiones bajos.
8. Una carga masiva de más de 20 archivos se completa igual, en tandas, y termina con **un solo**
   reporte que suma todas las tandas, sin perder ningún archivo por el camino. Una petición que
   traiga más de 20 archivos se rechaza con `422`.
9. Un archivo cuyo nombre no corresponde a ningún artículo del catálogo no se guarda, no crea ningún
   artículo, y aparece en el reporte con su nombre y el motivo.
10. Un archivo cuyo nombre corresponde a más de un artículo del catálogo no se asigna a ninguno y se
    reporta nombrando el modelo ambiguo.
11. Un archivo que no es una imagen real —aunque termine en `.jpg`— no se guarda y se reporta.
12. Los artículos del catálogo que no recibieron ninguna imagen no aparecen en el reporte ni
    producen error.
13. Un `.zip` plano con imágenes se procesa igual que la selección múltiple.
14. Un `.zip` que contiene carpetas se rechaza completo, con un mensaje que pide volver a
    comprimirlo sin carpetas, y no guarda ninguna de sus imágenes.
15. En `/articulos`, hacer clic en el nombre de un producto abre el modal con la foto, el nombre, el
    modelo y el precio con IVA; la tabla no muestra miniaturas ni columna de imagen.
16. El modal no muestra en ningún caso el precio del proveedor, el costo ni la utilidad.
17. En un navegador capaz de compartir archivos, el botón "Compartir" abre el menú del sistema con
    la foto y el texto; en uno que no puede, copia el texto al portapapeles y lo avisa. El archivo
    que sale por el menú de compartir es **JPEG**, no WEBP, para que WhatsApp lo reciba como foto y
    no como calcomanía.
18. El modal se muestra completo dentro de los límites del `Dialog`, sin desbordar ni requerir
    scroll horizontal, en viewports de escritorio estándar (≥1280px) y en móvil, tanto con un nombre
    de artículo largo como con una foto muy ancha.
19. Un artículo eliminado (soft delete) conserva su archivo de imagen en disco.
20. Pint y ESLint/Prettier corren sin errores sobre el código nuevo, y `npm run build` compila la
    SPA completa.

### De la revisión del 2026-08-18

21. `/articulos` muestra un botón "Archivos" que despliega tres opciones —importar artículos, subir
    imágenes y exportar CSV— y "Nuevo artículo" sigue suelto y a la vista. En la barra ya no queda
    ningún botón "Carga masiva", "Importar CSV", "Subir imágenes" ni "Exportar CSV".
22. Cada opción del menú abre su propio modal, con su propio selector de catálogo, su propio archivo
    y su propio reporte. Ningún modal muestra los dos pasos numerados de
    [023](023-carga-masiva-por-pasos.md).
23. Los dos selectores arrancan sin catálogo elegido, y elegir un catálogo en una pantalla no lo
    elige en la otra, ni en el momento ni al abrirla después.
24. Ninguna de las dos pantallas ofrece continuar hacia la otra al terminar, ni deja nada marcado
    como pendiente al cerrarse.
25. Cambiar el catálogo con un archivo ya elegido o un reporte en pantalla limpia esa pantalla.
26. Elegir en la pantalla de imágenes un catálogo **sin artículos** muestra el aviso junto al
    selector **sin deshabilitar nada**: el archivo se puede elegir y el botón, que pasa a decir
    "Subir de todos modos", se puede apretar.
27. El aviso no menciona cuántos archivos se van a descartar.
28. Subir imágenes a un catálogo vacío se completa y termina con el reporte de cero asociadas,
    encabezado por la línea que señala el catálogo y los nombres de archivo como causa probable.
29. Elegir un catálogo con al menos un artículo no muestra el aviso, y el botón dice "Subir
    imágenes".
30. Importar el CSV a un catálogo que estaba vacío y abrir después la pantalla de imágenes ya no
    muestra el aviso para ese catálogo.
31. Cada reporte conserva su botón "Copiar", y el del CSV sigue incluyendo el modelo de cada fila
    rechazada, como quedó en [023](023-carga-masiva-por-pasos.md).
32. Las dos formas de subir fotos siguen funcionando igual: el `.zip` y la selección múltiple en
    tandas de 20 con su barra de avance y su reporte único.
33. Cada modal se muestra completo dentro de los límites del `Dialog`, sin desbordar ni requerir
    scroll horizontal, en escritorio (≥1280px) y en móvil, incluso con un reporte de cien archivos
    rechazados; con ese reporte en pantalla, el pie del modal sigue alcanzable.
34. La barra de `/articulos` cabe en el ancho de un celular sin desbordarse.
35. **Ningún endpoint cambia**: la importación CSV y la carga de imágenes responden exactamente lo
    mismo que antes de esta revisión, y la suite de Pest pasa **sin haber tocado ninguna prueba de
    backend**. ESLint, Prettier y `npm run build` corren limpios sobre el frontend modificado.

## Supuestos asumidos (registro completo)

1. "Subir masivamente artículos" se resuelve extendiendo la importación CSV que ya existe, no con un
   importador nuevo en paralelo.
2. La importación de artículos sigue siendo por catálogo: se elige un catálogo destino y todas las
   filas quedan en él.
3. La importación de artículos sigue dando de alta solamente; una fila con el nombre de un artículo
   que ya existe en ese proveedor se reporta como duplicado y no lo actualiza.
4. El CSV no cambia de columnas: la imagen no se referencia dentro del CSV.
5. **(Precisado 2026-08-18)** Las imágenes se suben en una operación aparte de la del CSV, y el
   orden —primero los artículos, después las fotos— **sigue siendo un requisito del sistema**, porque
   la foto que no encuentra artículo se descarta. Lo que cambió es que la pantalla ya no lo impone:
   lo avisa cuando puede detectarlo y lo explica en el reporte cuando no.
6. **(Redefinido)** Se suben muchas de una vez, por **selección múltiple de archivos o por un
   `.zip`**. El ZIP debe venir **plano**: si trae carpetas dentro, se rechaza el archivo completo
   con un mensaje que pide volver a comprimirlo. Se prefirió el rechazo explícito a que el sistema
   entrara solo a las subcarpetas, para que el resultado no dependa de cómo quedó armado el
   comprimido. Queda advertido que "Enviar a → Carpeta comprimida" sobre una carpeta en Windows
   produce un ZIP con esa carpeta dentro, y que hay que comprimir la selección de archivos.
7. El nombre del archivo es lo único que decide el emparejamiento; no hay que capturar nada más.
8. El emparejamiento es contra el campo `modelo` del artículo.
9. La comparación ignora mayúsculas, acentos, espacios sobrantes y la diferencia entre espacio,
   guion y guion bajo.
10. El emparejamiento se hace dentro del catálogo elegido antes de subir, no contra todos los
    artículos del sistema.
11. Una imagen por artículo; no hay galería.
12. **(Precisado)** Si dos archivos de la misma carga apuntan al mismo artículo, gana el último
    procesado y el desplazado se reporta. Se eligió "el último gana" y no "el primero gana" para que
    el comportamiento sea idéntico dentro de una tanda y entre tandas, y coherente con la regla de
    reemplazo del punto 13.
13. Subir una imagen para un artículo que ya tenía la reemplaza, sin preguntar.
14. Una imagen que no encuentra artículo se descarta y se reporta; nunca crea un artículo.
15. Un artículo sin imagen no es un error: se muestra con un marcador de "sin imagen".
16. Al terminar la carga se muestra un reporte con la misma forma que el del CSV: cuántas se
    asociaron y el detalle archivo por archivo de las que no.
17. Formatos aceptados: JPG, PNG y WEBP.
18. Desde el formulario de edición de un artículo se puede ver, reemplazar y quitar su imagen.
19. **(Redefinido)** **No hay modo cuadrícula** en `/articulos`. El listado sigue siendo la tabla de
    siempre; lo único que cambia es que el nombre del producto abre un modal.
20. **(Redefinido)** El modal muestra **foto grande a la izquierda; nombre, modelo y precio con IVA
    a la derecha; y abajo a la derecha un botón "Compartir"**. "Precio público" es el precio de
    venta **con IVA** (`precio_unitario_con_iva`), el mismo que ya muestra la tabla. Nunca se
    muestran el precio del proveedor, el costo ni la utilidad.
21. *(Caída con el punto 19)* Buscador, filtros y paginación funcionan igual en ambos modos — ya no
    aplica, porque solo hay un modo.
22. *(Caída con el punto 19)* El sistema recuerda el modo elegido — ya no aplica.
23. **(Redefinido)** **No hay miniatura en la tabla.** El enlace que abre el modal es el **nombre
    del producto**, para no restarle densidad ni estética a la tabla de datos.
24. **(Redefinido)** El clic en el nombre abre el **modal**, no el formulario de edición; el modal
    lleva un botón "Editar" para llegar al formulario.
25. **(Descartado)** No hay página de catálogo para el cliente, ni pública ni en PDF. Las imágenes
    tampoco aparecen en los PDF de [019](019-formato-pdf-documentos.md). Se evaluó (página interna,
    página pública con enlace, PDF tipo folleto, o ambas) y se decidió no hacerlo en esta historia.
26. El catálogo es interno: requiere sesión iniciada. Lo único que sale hacia afuera es lo que el
    usuario mande con el botón "Compartir".
27. **(Redefinido)** El botón "Compartir" usa **el menú del propio aparato en celular** (llevando
    foto y texto) y **copia el texto al portapapeles en escritorio**, sin descargar la foto. Se
    descartó abrir WhatsApp directamente, porque una página web no puede adjuntarle imágenes y el
    cliente recibiría texto sin ver el producto.
28. **(Adición técnica)** Las imágenes viven en el **disco privado** y se sirven por una ruta de
    Laravel que valida la sesión. La alternativa de dejarlas en el docroot se descartó al comprobar
    que [`deploy/deploy-frontend.sh`](../deploy/deploy-frontend.sh) borra del docroot todo lo que no
    venga en el build, lo que habría eliminado todas las fotos en el siguiente despliegue; sostener
    esa opción habría exigido además una cuarta excepción permanente en el script de despliegue.
29. **(Adición técnica)** Se guarda **una sola versión reducida** (máximo 1200 puntos de lado largo,
    **WEBP** calidad 82) y se descarta la original. No se generan miniaturas, porque la tabla no
    muestra imágenes. Requiere **GD con soporte WEBP**; sin él, la salida cae a JPEG calidad 82.

    Se eligió WEBP y no JPEG porque **la mayoría del material del usuario ya viene en WEBP**:
    convertirlo lo haría entre 25% y 35% más pesado, agregaría una segunda compresión con pérdida y
    le quitaría la transparencia a los recortes. La objeción a WEBP es que **WhatsApp lo trata como
    calcomanía**, y se resuelve en el punto siguiente sin renunciar al formato.
30. **(Adición técnica)** El botón "Compartir" **convierte la foto a JPEG en el navegador** justo
    antes de abrir el menú del sistema (`<canvas>` + `toBlob`), para que WhatsApp la reciba como
    foto. El servidor guarda una sola copia, en WEBP; no se genera ni se almacena una segunda
    versión para compartir.
31. **(Adición técnica)** La selección múltiple se manda en **tandas automáticas** de **20** archivos
    o 40 MB, con barra de avance y un solo reporte final. El `.zip` no se puede partir: tiene un tope
    duro de 40 MB comprobado antes de subir.

    El 20 sale de `max_file_uploads` de PHP, cuyo valor por defecto es exactamente ese. Se descubrió
    al comprobar el entorno antes de implementar: la spec decía 50, y con 50 PHP habría **descartado
    en silencio** 30 archivos por tanda, sin error y sin que el reporte lo mencionara. El backend
    rechaza con `422` las peticiones de más de 20 archivos para que el fallo sea visible.
32. **(Adición técnica)** Los archivos se comprueban **por contenido**, no por su terminación, y del
    ZIP se usa solo el nombre de cada entrada, nunca la ruta que trae escrita dentro. Se agregó
    además un tope de 400 MB de contenido descomprimido.
33. **(Adición técnica)** Cada imagen se guarda con un **nombre generado por el sistema**
    (`{articulo_id}-{8 caracteres al azar}.webp`); el nombre original solo sirve para emparejar y se
    reporta en el resultado. `ArticuloResource` expone `imagen_version` para que el navegador nunca
    muestre una foto reemplazada.

### De la revisión del 2026-08-18

34. **(Redefinido)** Las dos cargas masivas son **dos pantallas independientes**, cada una con su
    archivo, su botón y su reporte. Reemplaza al modal único con dos pasos numerados de
    [023](023-carga-masiva-por-pasos.md).
35. **(Redefinido)** **Cada pantalla pregunta su propio catálogo y arranca vacía.** Se acepta a
    sabiendas el riesgo que [023](023-carga-masiva-por-pasos.md) había cerrado —importar el CSV a un
    catálogo y subir las fotos a otro— a cambio de que ninguna pantalla dependa de la otra. Se
    compensa con el aviso del catálogo vacío antes de subir y con el encabezado del reporte cuando
    ninguna imagen empareja.
36. La foto que no encuentra artículo **se sigue descartando**, como en el supuesto 14: no se guarda
    esperando a que su artículo exista, ni da de alta un artículo en blanco. Las dos alternativas se
    evaluaron y se descartaron.
37. Por lo anterior, **el orden sigue importando** y el sistema sigue sin poder hacerlo indiferente;
    lo único que cambia es que ya no lo impone con un candado.
38. El emparejamiento **no es un acto con botón propio**: ocurre dentro de la subida de imágenes,
    como siempre.
39. **(Redefinido)** El catálogo vacío **avisa, no bloquea**, y el botón pasa a decir "Subir de
    todos modos". El candado duro de [023](023-carga-masiva-por-pasos.md) desaparece.
40. El aviso **no dice cuántos archivos se van a descartar**, porque con un `.zip` el navegador no
    puede saber cuántas imágenes trae sin abrirlo.
41. **Se conservan las dos formas de subir fotos** —`.zip` y selección múltiple— aunque la historia
    se pidió en términos de ZIP: la selección múltiple ya funciona y resuelve el caso de unas pocas
    fotos sueltas sin obligar a comprimir.
42. **(Adición técnica)** **El backend no se toca en nada.** Ni endpoints, ni formatos, ni reglas de
    emparejamiento, ni pruebas de backend.
43. **(Adición técnica)** El conteo del que sale el aviso es `articulos_count`, que ya viaja en el
    listado de catálogos que consulta `CatalogoSelect.vue`; no hace falta ningún endpoint nuevo.
44. **(Adición técnica)** La barra usa el `DropdownMenu` del design system
    ([003](003-design-system-tailwind.md)), que ya está en el proyecto.
45. **(Adición técnica)** De [023](023-carga-masiva-por-pasos.md) sobreviven el botón "Copiar
    reporte", el **modelo** en la fila rechazada del CSV y el encabezado del reporte cuando ninguna
    imagen empareja. Ninguno de los tres dependía de que las dos cargas vivieran en el mismo modal.
