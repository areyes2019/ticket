# Spec: Imágenes de artículos (carga masiva y ficha visual)

**Referencia:** reescritura de [remotas/020-imagenes-articulos.md](remotas/020-imagenes-articulos.md),
que se diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan las reglas de
negocio (una imagen por artículo, emparejamiento por `modelo` dentro del catálogo, reporte archivo
por archivo, ficha con botón "Compartir") y las lecciones de la implementación remota (`max_file_uploads
= 20` descarta archivos en silencio, `imagescale` pierde la transparencia, WhatsApp trata el WEBP como
calcomanía, liberar los recursos de GD en cada imagen). La parte del navegador y el protocolo entre
navegador y servidor se rehicieron para Laravel + Blade + JavaScript nativo. Extiende
[007-gestion-articulos.md](007-gestion-articulos.md), [008-catalogos.md](008-catalogos.md) y
[009-precio-proveedor-utilidad.md](009-precio-proveedor-utilidad.md).

La revisión remota del 2026-08-18 (separar las dos cargas masivas que la remota 023 había fusionado
en un modal) no se traslada como historia: aquí la importación CSV ya es una pantalla propia
(`/articulos/importar`) y la carga de imágenes nace como otra pantalla propia. De esa revisión se
conservan sus decisiones de fondo: pantallas independientes, aviso de catálogo vacío que no bloquea y
encabezado del reporte cuando ninguna imagen empareja.

## Historia de usuario

Como usuario del sistema de facturación, quiero subir masivamente las imágenes de mis productos y que
cada una se asocie sola al artículo que le corresponde, para que al consultar mi listado de artículos
pueda ver la foto de cada producto sin haberlas capturado una por una. La subida de artículos (CSV) y
la de imágenes son dos procesos separados, cada uno con su archivo y su pantalla.

## Objetivo / Alcance

Agregar una imagen por artículo sobre la estructura actual de `Articulo`, con:

- Una **carga masiva** en su propia pantalla (`/articulos/imagenes`) que empareja archivos con
  artículos por el nombre del archivo, dentro del catálogo elegido.
- Una **ficha visual** en un `<dialog>` nativo que se abre desde el listado `/articulos`, con un botón
  "Compartir".
- Un campo de **imagen en el formulario del artículo** (alta y edición) para ver, reemplazar o quitar
  la foto de un solo producto.

Reparto de responsabilidades:

- Laravel resuelve todo: rutas web, controladores, validación, autorización, procesamiento de la
  imagen, emparejamiento, lectura del ZIP y entrega del archivo con la sesión web. No se crea API
  REST, no se usa Sanctum ni API Resources.
- JavaScript nativo solo donde el navegador aporta algo que el servidor no puede: **abrir la ficha**
  sin recargar, **compartir** la foto con el menú del aparato, **partir en tandas** una selección de
  más de 20 archivos, y mostrar el **aviso de catálogo vacío** al cambiar el selector. Todo tiene
  respaldo sin JavaScript.

**No** se agrega ningún campo de texto nuevo al artículo.

## Requisitos del entorno

La implementación depende de dos extensiones de PHP:

- **GD con soporte WEBP** (`imagewebp`, `imagecreatefromwebp`), para reducir las imágenes y guardarlas
  en WEBP.
- **ZipArchive**, para leer el `.zip`.

**Verificado en local el 2026-09-27** (PHP 8.3.30 de Laragon): `gd` sí, `imagewebp` sí, `ZipArchive`
sí, `max_file_uploads = 20`, `upload_max_filesize` y `post_max_size = 2G`. El servidor de producción
aún no está definido; hay que repetir la comprobación antes de desplegar. Qué hacer si falta cada una:

- Sin `ZipArchive`, la selección múltiple funciona igual y solo se cae el `.zip` (la pantalla lo
  oculta y el servidor lo rechaza con un mensaje claro).
- Sin soporte WEBP en GD, la salida cae a **JPEG calidad 82** y el resto de la spec no cambia (archivos
  25–35% más grandes y sin transparencia).
- Sin GD del todo, hay que reconsiderar la reducción de tamaño antes de implementar.

## Backend (Laravel)

### Dónde viven los archivos

En el **disco privado** (`Storage::disk('local')`, raíz `storage/app/private`), bajo
`Articulo::DIRECTORIO_IMAGENES` (`articulos`), y se entregan por una ruta web que exige sesión y
autoriza con `ArticuloPolicy`. Razones:

- **Todo el sistema está detrás del login**; una foto en `public/` sería alcanzable por cualquiera que
  adivine la dirección.
- **No depende de `php artisan storage:link`**, que algunos alojamientos compartidos no permiten
  (enlaces simbólicos desactivados).
- **`public/` se sobrescribe al desplegar** el código; `storage/` es el lugar de Laravel para datos
  que produce la aplicación y que no están en git.

### Esquema

- **Migración** `add_imagen_ruta_to_articulos_table`: columna `articulos.imagen_ruta`, `string`,
  nullable, sin índice (nunca se filtra por ella). Ruta relativa dentro del disco privado; `null`
  significa "sin imagen". `down()` la elimina.
- **Fuera de `#[Fillable]`**, igual que `costo_con_descuento` y `precio_unitario_sin_iva` (009): no
  es un dato que envíe el formulario, sino el resultado de guardar un archivo. Solo la escribe
  `ProcesadorImagenArticulo`.
- **Accessors nuevos en `Articulo`** (sustituyen al `ArticuloResource` de la remota):
  - `tiene_imagen`: `bool`.
  - `imagen_version`: `string|null`, los 8 caracteres al azar del nombre del archivo. Blade arma la
    URL con `route('articulos.imagen', [$articulo, 'v' => $articulo->imagen_version])`, de modo que
    reemplazar una foto cambia la dirección y el navegador va por la nueva sin vaciar su caché.
  - La ruta interna del archivo nunca se escribe en el HTML.
- `ArticuloFactory`: estado `conImagen()` para las pruebas.

### Normalización y guardado de cada imagen (`App\Services\Articulos\ProcesadorImagenArticulo`)

Toda imagen que entra, venga de la carga masiva o del formulario, pasa por este servicio y sale igual:

- **Se comprueba por contenido, no por la terminación.** Se confirma que sea JPEG, PNG o WEBP leyendo
  sus bytes (`getimagesize`/`finfo`) y que GD pueda decodificarla. Lo que no lo sea se descarta y se
  reporta.
- **Se reduce a 1200 puntos de lado largo** si los excede, conservando la proporción. Nunca se amplía
  una imagen chica.
- **Se reescribe siempre como WEBP** calidad 82, con extensión `.webp` y `Content-Type: image/webp`
  fijo al entregarla. WEBP es el formato en que ya viene la mayoría del material y conserva la
  transparencia.
- **Transparencia**: el redimensionado se hace con `imagecreatetruecolor` + `imagealphablending(false)`
  + `imagesavealpha(true)` + `imagecopyresampled`, no con `imagescale` (que deja fondo negro en los
  recortes transparentes; lección de la remota).
- **Los recursos de GD se liberan en `finally`**: una tanda procesa hasta 20 imágenes en la misma
  petición.
- Como la imagen se **regenera** en vez de copiarse, cualquier cosa escondida en el archivo original
  queda fuera.
- **Nombre generado por el sistema**: `{articulo_id}-{8 caracteres al azar}.webp`. El nombre que subió
  el usuario solo sirve para emparejar y para el reporte.
- **Orden de escritura seguro al reemplazar**: (1) se escribe el archivo nuevo, (2) se actualiza
  `imagen_ruta` con `saveQuietly()` (no dispara el recálculo de precio), (3) solo entonces se borra el
  archivo anterior. Si falla el paso 1 o 2, se borra el archivo nuevo y la columna sigue apuntando al
  anterior, que sigue existiendo. Nunca queda la columna apuntando a un archivo inexistente.
- **Quitar la imagen**: se pone `imagen_ruta = null` y después se borra el archivo.
- **Eliminar un artículo no borra su imagen**: el borrado es lógico (`SoftDeletes`) y el archivo se
  conserva por si se restaura.
- **Tamaño máximo por imagen individual: 10 MB.**

### Emparejamiento por nombre de archivo (`App\Services\Articulos\CargadorImagenesArticulos`)

La carga masiva asocia cada archivo al artículo cuyo **`modelo`** coincide con el nombre del archivo
sin su extensión: `A-1234.jpg` va al artículo de modelo `A-1234`.

- La comparación usa una **forma normalizada** de ambos lados: minúsculas, sin acentos, sin espacios
  al inicio y al final, y toda corrida de espacios, guiones y guiones bajos colapsada a un solo
  espacio. Así `a 1234.JPG`, `A-1234.jpg` y `A_1234.jpeg` encuentran al mismo artículo. La extensión
  nunca participa.
- **Solo dentro del catálogo elegido** y solo entre artículos **no eliminados** del usuario. Dos
  proveedores pueden usar el mismo modelo sin pisarse.
- **`modelo` no es único** (007). Si dentro del catálogo hay más de un artículo cuyo modelo
  normalizado coincide, **la imagen no se asigna a ninguno** y se reporta nombrando el modelo ambiguo.
- **Un archivo sin artículo se descarta y se reporta.** Nunca crea un artículo.
- **Un artículo sin imagen no es un error** y no aparece en el reporte.
- **Si dos archivos de la misma petición apuntan al mismo artículo, gana el último procesado** y el
  desplazado se reporta. Entre tandas distintas ocurre lo mismo de forma natural: la segunda
  reemplaza a la primera, igual que un reemplazo manual.
- Los artículos del catálogo se cargan **una sola vez por petición** (`modelo` normalizado → ids), no
  una consulta por archivo.

**Motivos concretos**, que nombran el archivo y la causa:

- `no hay ningún artículo con modelo "B-77" en este catálogo`
- `hay 2 artículos con modelo "B-77" en este catálogo`
- `no es una imagen JPG, PNG ni WEBP`
- `pesa más de 10 MB`
- `otro archivo de esta carga se asignó al mismo artículo`

Devuelve `array{asociadas: int, errores: list<array{archivo: string, motivo: string}>}`, la misma
forma que el reporte de `ImportadorArticulosCsv` cambiando `fila`/`modelo` por `archivo`.

### Límites de la carga masiva

- **Máximo 20 archivos por petición.** Es `max_file_uploads` de PHP: por encima de ese número PHP
  **descarta en silencio** los sobrantes y el servidor no puede detectarlo, porque ya no existen
  cuando llega la petición. `CargarImagenesRequest` rechaza con error de validación (422 en AJAX) una
  petición con más de 20, para que un cliente que ignore el límite falle de forma visible.
- **Selección múltiple de más de 20 archivos**: `carga-imagenes.js` la parte en **tandas** de 20
  archivos o 40 MB (lo que se alcance primero) y las manda una tras otra (ver JavaScript). Sin
  JavaScript, la pantalla indica que con más de 20 imágenes hay que usar un `.zip`.
- **ZIP: tope de 40 MB**, validado en el servidor y, con JavaScript, comprobado antes de enviar. Un
  comprimido no se puede partir; si se pasa, el mensaje pide dividirlo.

### Lectura del `.zip`

- **Debe venir plano.** Si alguna entrada trae `/` o `\` en su nombre, se rechaza el archivo completo
  (`ArchivoZipInvalido`, igual que `ArchivoCsvInvalido`) con el mensaje: "El ZIP tiene carpetas
  dentro. Comprime seleccionando los archivos, no la carpeta." No se guarda ninguna imagen.
- **De cada entrada se usa solo el nombre**, nunca la ruta escrita dentro; el contenido se lee con
  `ZipArchive::getStream()`/`getFromIndex()` sin extraer al disco.
- **Tope de expansión de 400 MB**, leyendo el tamaño declarado en el índice antes de leer nada.
- Las entradas que no sean JPEG, PNG o WEBP por contenido se reportan una por una con su nombre.
- Un ZIP corrupto o que `ZipArchive` no puede abrir se rechaza completo con un mensaje claro.

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]`, **antes** del
`Route::resource('articulos', …)`, junto a importar/exportar:

```php
Route::get('articulos/imagenes', [ImagenesArticulosController::class, 'create'])->name('articulos.imagenes');
Route::post('articulos/imagenes', [ImagenesArticulosController::class, 'store'])->name('articulos.imagenes.store');
Route::get('articulos/{articulo}/imagen', [ImagenesArticulosController::class, 'show'])->name('articulos.imagen');
```

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/articulos/imagenes` | pantalla de carga masiva y su reporte | `articulos.imagenes` |
| POST | `/articulos/imagenes` | procesa la carga (redirige con el reporte, o JSON si es AJAX) | `articulos.imagenes.store` |
| GET | `/articulos/{articulo}/imagen` | entrega el binario de la imagen | `articulos.imagen` |

- El catálogo destino viaja **en el formulario** (`catalogo_id`), no en la URL
  (`/api/v1/catalogos-proveedor/{catalogo}/articulos/imagenes` en la remota), igual que la
  importación CSV en 007/008.
- **No hay rutas `POST`/`DELETE` para la imagen individual**: se sube y se quita desde el formulario
  del artículo (`articulos.store` / `articulos.update`).

### Controladores

**`ImagenesArticulosController`** (nuevo):

- `create`: catálogos disponibles del usuario (`Catalogo::disponibles()`) con
  `withCount('articulos')`, y el reporte de la sesión. La pantalla arranca **sin catálogo elegido**.
- `store(CargarImagenesRequest, CargadorImagenesArticulos)`: busca el catálogo del usuario, procesa
  `archivos[]` o `archivo` (ZIP) y:
  - Sin AJAX: `redirect()->route('articulos.imagenes')->with('reporte', …)->withInput(['catalogo_id'
    => …])`, para que recargar no vuelva a subir (mismo patrón que `ImportacionArticulosController`).
    El reporte lleva también la etiqueta del catálogo para el encabezado.
  - Con AJAX (`$request->expectsJson()`): responde JSON `{ "asociadas": 12, "errores": [{ "archivo":
    "B-77.jpg", "motivo": "…" }] }`. Es el único JSON de la historia y existe solo para las tandas.
  - `ArchivoZipInvalido` → error en el campo `archivo` (`back()->withErrors()` o 422 en AJAX).
- `show(Articulo $articulo)`: `Gate::authorize('view', $articulo)`; `404` si no tiene imagen o el
  archivo no existe; si no, `Storage::disk('local')->response(...)` con `Content-Type: image/webp` y
  `Cache-Control: private, max-age=604800`. Responde también para artículos eliminados del usuario
  (con `withTrashed()` en el enlace de ruta), porque conservan su archivo.

**`ArticuloController`** (extiende 007):

- `store` y `update`: después de crear/actualizar el artículo, si llega `imagen` llama a
  `ProcesadorImagenArticulo::guardar()`; si llega `quitar_imagen` (y no `imagen`), a `quitar()`. En el
  alta, crear el artículo y guardar la imagen van en `DB::transaction`: si la imagen falla, no queda
  el artículo a medias.
- El mensaje flash agrega "Imagen actualizada." / "Imagen quitada." cuando aplica.

### Autorización (`ArticuloPolicy`)

- Nuevo método `view`: solo el dueño; a los demás `Response::denyAsNotFound()` (404), igual que
  `update`/`delete`.
- Subir o quitar la imagen desde el formulario queda cubierto por `ArticuloRequest::authorize()`
  (`Gate::inspect('update', …)`).
- La carga masiva valida el catálogo con `ArticuloRequest::reglaCatalogo()` y solo empareja contra
  artículos del usuario.

### Validaciones

- **`ArticuloRequest`** (extiende 007):
  - `imagen`: `nullable`, `file`, `mimes:jpg,jpeg,png,webp`, `max:10240`, y la regla nueva
    `App\Rules\ImagenLegible` (GD puede decodificarla), para que una imagen dañada se rechace
    **antes** de crear el artículo.
  - `quitar_imagen`: `boolean`.
  - Ninguno de los dos entra en `validated()` hacia `create`/`update` del modelo; el controlador los
    toma aparte.
  - `ImportadorArticulosCsv` no cambia: usa `ArticuloRequest::reglas()`, donde `imagen` es opcional.
- **`CargarImagenesRequest`** (nuevo):
  - `catalogo_id`: requerido, `ArticuloRequest::reglaCatalogo($usuario)`. Mensaje: "Selecciona uno de
    tus catálogos.".
  - Exactamente uno de `archivos` o `archivo` (`required_without` / `prohibits`).
  - `archivos`: `array`, `max:20` (mensaje: "Máximo 20 imágenes por envío; para más, usa un .zip.");
    `archivos.*`: `file`. La suma de tamaños no puede pasar de 40 MB (regla en `after()`).
  - `archivo`: `file`, `mimes:zip`, `max:40960` (mensaje: "El .zip pesa más de 40 MB; divídelo en
    varios.").
  - El tipo de cada imagen **no** se valida aquí: un archivo que no es imagen se reporta en su renglón
    sin tumbar la petición.

## Vistas (Blade)

Todas extienden `layouts.app` y usan los componentes de [003](003-estilo-uniforme.md) (esquinas
rectas, iconos Bootstrap Icons, `x-boton`, `x-campo`, `x-alerta`, `x-card`).

### `articulos/index.blade.php` — el listado

- Barra de acciones: se agrega **"Subir imágenes"** (`x-boton variante="secundario" icono="images"`,
  enlace a `articulos.imagenes`) junto a "Importar CSV". "Nuevo artículo" se queda primero y a la
  vista. No hay menú desplegable. La barra debe envolver (`flex-wrap`) y no desbordar en móvil.
- La tabla **no cambia de forma**: sin miniaturas ni columna de imagen.
- Un solo `<dialog id="ficha-articulo">` al final del contenido (ver Ficha).

### `articulos/_filas.blade.php`

- El nombre del producto pasa a ser un enlace (`<a>` dentro de la misma celda truncada):
  - `href` = `route('articulos.edit', $articulo)` (respaldo sin JavaScript).
  - `data-ficha`, `data-nombre`, `data-modelo`, `data-precio` (precio con IVA ya formateado),
    `data-imagen` (URL con `?v=` o vacío), `data-editar` (URL de edición).
  - Nunca lleva precio de proveedor, costo ni utilidad.
- Como `busqueda-dinamica.js` reemplaza el `<tbody>`, los enlaces nuevos funcionan sin volver a
  enlazar eventos (delegación en `ficha-articulo.js`).

### Ficha (`<dialog id="ficha-articulo">`, parcial `articulos/_ficha.blade.php`)

- Dos columnas en escritorio, apiladas en móvil:
  - **Izquierda: la foto** (`<img>` con `max-width: 100%`) o un marcador **"Sin imagen"**
    (`icono="image"`) que ocupa el mismo espacio, para que el diálogo no cambie de tamaño.
  - **Derecha: nombre, modelo y precio con IVA.**
- Pie: **"Editar"** (`icono="pencil"`), **"Compartir"** (`icono="share"`) y **"Cerrar"**
  (`icono="x-lg"`). Un campo de solo lectura oculto para el respaldo de copiado (ver JavaScript).
- Contenedores con `min-width: 0` para que un nombre largo o una foto ancha no desborden. Estilos en
  `public/css/app.css` con esquinas rectas; `::backdrop` semitransparente.

### `articulos/imagenes.blade.php` — carga masiva (nueva)

Gemela de `articulos/importar.blade.php`:

- Título "Subir imágenes", `articulos._mensajes`.
- **Reporte** (parcial `articulos/_reporte-imagenes.blade.php`), si hay:
  - `x-alerta tipo="exito"`: "N imágenes asociadas." (singular/plural).
  - **Encabezado cuando no emparejó ninguna** (`asociadas === 0` y hay errores), `x-alerta
    tipo="advertencia"`: "Ninguna imagen encontró su artículo en {catálogo}. Revisa que sea el
    catálogo correcto y que los nombres de archivo coincidan con el modelo de cada artículo."
  - Tabla "Archivos rechazados" (Archivo, Motivo) dentro de `x-card class="tabla-contenedor"`.
  - Botón **"Copiar reporte"** (`icono="copy"`), con atributo `hidden` que quita el JavaScript (sin
    JavaScript no aparece).
- Sin catálogos: `x-alerta` con enlace a "Nuevo catálogo", igual que importar.
- Formulario (`enctype="multipart/form-data"`, `data-carga-imagenes`):
  - `catalogo_id` (`x-campo tipo="select"`, arranca en "Selecciona un catálogo"). El mapa
    `{ catalogo_id: articulos_count }` viaja en `data-articulos` del formulario.
  - **Aviso de catálogo vacío** (`x-alerta tipo="advertencia"`, oculto por defecto): "**{catálogo} no
    tiene ningún artículo.** Ninguna de estas fotos va a encontrar a quién pertenecer. Empieza por
    importar los artículos." No dice cuántas fotos se descartarán. Con un catálogo elegido que sí
    tiene artículos, en su lugar se muestra "N artículos en este catálogo".
  - Dos campos de archivo: `archivos[]` (`multiple`, `accept=".jpg,.jpeg,.png,.webp"`) y `archivo`
    (`accept=".zip"`), con la ayuda: "Varias imágenes (hasta 20 sin JavaScript) o un .zip plano de
    hasta 40 MB. El nombre de cada archivo debe ser el modelo del artículo."
  - `<progress>` oculto para las tandas.
  - Botón "Subir imágenes" (`icono="upload"`), que cambia a **"Subir de todos modos"** con el catálogo
    vacío y **nunca se deshabilita** por eso. "Volver al listado".
- El aviso de catálogo vacío es solo del navegador. Sin JavaScript no se muestra, y el encabezado del
  reporte cubre ese caso después de subir.

### Formulario de artículo (`articulos/_formulario.blade.php`)

- El `<form>` pasa a `enctype="multipart/form-data"`.
- Bloque "Imagen":
  - Si el artículo tiene imagen, se muestra (`<img>` con la URL versionada) con la casilla "Quitar
    imagen" (`x-campo tipo="checkbox" nombre="quitar_imagen"`).
  - Campo `imagen` (`tipo="file"`, `accept=".jpg,.jpeg,.png,.webp"`), con la ayuda "JPG, PNG o WEBP de
    hasta 10 MB. Reemplaza la imagen actual.".
  - Disponible **también en el alta**.
- Si la validación falla, el navegador no conserva el archivo elegido; el mensaje de error del campo
  lo indica ("Vuelve a elegir la imagen").

### `/estilos`

Se agregan los iconos nuevos (`images`, `image`, `share`, `copy`) a la muestra y un ejemplo del
diálogo.

## JavaScript

Todo en `public/js/`, cargado solo en la pantalla que lo usa, sin compilación.

### Ficha (`public/js/ficha-articulo.js`)

- Delegación de `click` sobre `[data-ficha]` en el documento: evita la navegación, llena el
  `<dialog>` con los `data-*` y lo abre con `showModal()`. Cerrar con el botón, con Esc (nativo) o con
  clic en el fondo.
- Sin JavaScript, el enlace lleva a la edición del artículo.

### Compartir (en `ficha-articulo.js`)

- **Si el navegador puede compartir archivos** (`navigator.canShare?.({ files: [prueba] })`): descarga
  la imagen, la dibuja en un `<canvas>` y la exporta con `toBlob('image/jpeg', 0.9)` (WhatsApp trata
  el `.webp` como calcomanía), y llama a `navigator.share({ files, text })`. Si el usuario cancela
  (`AbortError`), no se muestra nada. Sin imagen, comparte solo el texto.
- **Si no**: copia el texto con `navigator.clipboard.writeText()` y avisa "Copiado".
- **Contexto no seguro** (HTTP fuera de `localhost`, como `http://ticket_factura.test`):
  `navigator.share` y `navigator.clipboard` no existen. En ese caso se muestra el texto en el campo de
  solo lectura del diálogo, ya seleccionado, con el aviso "Copia el texto". El botón nunca queda sin
  respuesta.
- La decisión se toma comprobando capacidades en tiempo real, no por el tamaño de la pantalla.
- Texto compartido: `{nombre} — Modelo {modelo} — ${precio con IVA}`.

### Carga masiva (`public/js/carga-imagenes.js`)

- **Aviso de catálogo vacío**: al cambiar `catalogo_id`, lee `data-articulos` y muestra el aviso o el
  conteo, y cambia el texto del botón. **Cambiar de catálogo limpia la pantalla**: los archivos
  elegidos y el reporte en pantalla.
- **ZIP de más de 40 MB**: lo rechaza antes de enviar, con el mismo mensaje que el servidor.
- **Tandas**: solo intercepta el envío si hay **más de 20 imágenes** o más de 40 MB en total. Con 20 o
  menos, o con un ZIP, deja el envío normal del formulario. Parte la selección con la función pura
  `partirEnTandas(archivos, 20, 40 MB)`, manda cada tanda con Axios (`FormData` con `catalogo_id` y
  `archivos[]`) una tras otra, avanza el `<progress>`, acumula `asociadas` y `errores`, y pinta **un
  solo reporte** con la misma estructura que el parcial de Blade (plantilla `<template>` en la vista).
  Si una tanda falla por red o por un error del servidor, se detiene, lo informa y conserva el reporte
  de las tandas ya procesadas.
- **"Copiar reporte"**: copia el reporte como texto (`archivo — motivo` por línea), con el mismo
  respaldo de contexto no seguro que Compartir.
- `partirEnTandas` se exporta con `module.exports` cuando existe, como `precio-articulo.js`, para
  probarla con `node --test`.

## Pruebas (Pest y Node)

`tests/Feature/ImagenesArticulosTest.php` (con `Storage::fake('local')` y `UploadedFile::fake()->image()`),
más ajustes en `ArticuloTest`:

- Invitado redirigido al login en las tres rutas; usuario suspendido no entra.
- **Entrega**: el dueño recibe `image/webp` y `Cache-Control: private`; un artículo ajeno responde
  404; sin imagen responde 404; un artículo eliminado conserva y entrega su imagen.
- **Procesamiento**: 3000×2000 se guarda en 1200×800; 800×600 no se amplía; un PNG transparente
  conserva el canal alfa (se lee un píxel del WEBP guardado); un `.jpg` que no es imagen se reporta;
  el nombre guardado sigue `{id}-{8}.webp`.
- **Formulario**: alta con imagen; edición que reemplaza la imagen y borra el archivo anterior;
  "Quitar imagen" deja `imagen_ruta` nula y borra el archivo; imagen de más de 10 MB o dañada es error
  de validación y en el alta no crea el artículo; `imagen_ruta` no se puede asignar desde el
  formulario.
- **Emparejamiento**: `a 1234.JPG`, `A-1234.jpg` y `A_1234.jpeg` encuentran `A-1234`; modelo ambiguo
  no se asigna; archivo sin artículo se reporta y no crea nada; artículo de otro catálogo o eliminado
  no se empareja; dos archivos al mismo artículo → gana el último y se reporta el otro; artículos sin
  imagen no aparecen en el reporte.
- **Límites**: 21 archivos → error de validación (y 422 en AJAX); `archivos[]` y `archivo` juntos →
  error; ninguno → error; catálogo ajeno → error.
- **ZIP**: plano se procesa igual que la selección múltiple; con carpetas se rechaza completo sin
  guardar nada; declarado de más de 400 MB se rechaza; entradas no imagen se reportan.
- **Respuestas**: sin AJAX redirige a `articulos.imagenes` con el reporte (recargar no vuelve a
  subir); con `Accept: application/json` responde `{asociadas, errores}`.
- Catálogo vacío: la carga se completa con cero asociadas (el servidor no la rechaza).
- `tests/js/carga-imagenes.test.js`: `partirEnTandas` corta en 20 archivos, corta por 40 MB, y un solo
  archivo de más de 40 MB va en su propia tanda.
- `node --check` sobre `ficha-articulo.js` y `carga-imagenes.js`.
- `EstiloUniformeTest`, `ArticuloTest`, `ImportacionArticulosTest` y `CatalogoTest` siguen pasando.

## Fuera de alcance

- **Varias imágenes por artículo** (galería, orden, imagen principal).
- **Imágenes en documentos PDF** (cotización, factura, lista de precios), que todavía no existen.
- **Catálogo público o página para el cliente**: ninguna dirección se abre sin sesión. Lo que sale
  hacia afuera sale por "Compartir", pieza por pieza.
- **Modo cuadrícula o miniaturas** en `/articulos`; se guarda una sola versión reducida.
- **Conservar la imagen original**, **recortar, rotar o editar** la imagen.
- **Campos nuevos de texto** en el artículo.
- **Que la importación CSV actualice artículos existentes** o referencie imágenes.
- **Emparejar por un campo distinto de `modelo`.**
- **Descargar la foto desde "Compartir" en escritorio.**
- **Guardar las fotos que no encontraron artículo** para asignarlas después, o **dar de alta un
  artículo en blanco** a partir de una foto.
- **Un solo envío que traiga CSV y fotos juntos**, o que una pantalla herede el catálogo de la otra u
  ofrezca continuar en la otra.
- **Que el servidor rechace una carga a un catálogo vacío**: el aviso es del navegador y se puede
  ignorar.
- **Menú desplegable "Archivos"** de la remota (ver supuesto 20).
- Roles/permisos diferenciados o multiempresa.

## Estado de implementación

Implementada el 2026-09-27.

- **Archivos nuevos**: `ProcesadorImagenArticulo`, `CargadorImagenesArticulos`, `ImagenInvalida` y
  `ArchivoZipInvalido` en `app/Services/Articulos/`; `App\Rules\ImagenLegible`;
  `CargarImagenesRequest`; `ImagenesArticulosController`; la migración
  `2026_09_27_220207_add_imagen_ruta_to_articulos_table`; las vistas `articulos/imagenes`,
  `articulos/_reporte-imagenes` y `articulos/_ficha`; `public/js/ficha-articulo.js`,
  `public/js/carga-imagenes.js`; `tests/Feature/ImagenesArticulosTest.php` y
  `tests/js/carga-imagenes.test.js`.
- **Orientación EXIF**: además de lo que pedía la spec, los JPEG se enderezan según su EXIF
  (orientaciones 3, 6 y 8) antes de reducirlos; sin eso las fotos de celular verticales saldrían
  acostadas (no es "rotar a mano", que sigue fuera de alcance). Si falta la extensión `exif`, se
  guardan como vienen. No hay prueba automática: GD no escribe EXIF.
- **Paletas**: los PNG de 8 bits se pasan a color verdadero (`imagepalettetotruecolor`) antes de
  redimensionar, para que conserven la transparencia.
- **Recursos de GD**: desde PHP 8 son objetos; se liberan soltando la referencia en `finally`
  (`imagedestroy` no hace nada y se depreca en 8.5).
- **Copiar texto**: `window.copiarTexto()` en `public/js/app.js` lo comparten la ficha y el reporte.
  El respaldo del reporte sin portapapeles es **dejar el reporte seleccionado** para Ctrl+C (un campo
  de una línea perdería los saltos de línea); el de la ficha es el campo de solo lectura.
- **Cerrar la ficha**: "Cerrar" es un botón dentro de un `<form method="dialog">`, que cierra el
  diálogo sin JavaScript propio; Esc es nativo; el clic en el fondo sí lo maneja el script.
- **Tope de expansión del ZIP inyectable**: `CargadorImagenesArticulos` recibe el tope (400 MB por
  defecto) en el constructor, para probarlo con un ZIP pequeño en lugar de generar 400 MB.
- **`asociadas` entre tandas**: se suma lo que responde cada tanda; si dos tandas traen imágenes para
  el mismo artículo, la segunda la reemplaza (como un reemplazo manual) y el total puede contarla dos
  veces. Dentro de una misma tanda se reporta el desplazado.
- **Validación en pruebas**: `UploadedFile::fake()` toma el tipo del nombre del archivo, no del
  contenido, así que en las pruebas un texto llamado `foto.jpg` pasa `mimes` y lo detiene
  `ImagenLegible`; en producción lo detiene `mimes`. Ambos mensajes piden volver a elegir la imagen.
- **Prueba ajustada**: la de celdas truncadas del listado ahora busca el enlace del nombre
  (`class="celda-truncada enlace-ficha"`).
- **Verificación**: la suite Pest pasa completa (424 tests), `node --test "tests/js/*.test.js"` pasa
  (21), Pint no reporta cambios y `node --check` valida los JavaScript nuevos. La migración se aplicó
  a la base local. **No se revisó la UI en un navegador real**: falta abrir `/articulos` (ficha,
  Compartir en un celular real y en escritorio, cierre con el fondo), `/articulos/imagenes` (aviso de
  catálogo vacío, tandas con más de 20 fotos y su barra de avance, "Copiar reporte") y el bloque de
  imagen del formulario. `navigator.share` y el portapapeles solo funcionan con HTTPS o `localhost`;
  en `http://ticket_factura.test` se verá el respaldo.

## Criterios de aceptación

1. Subir una imagen desde el formulario de un artículo (alta o edición) la guarda, y al abrir la ficha
   de ese artículo la foto se ve.
2. La imagen guardada mide como máximo 1200 puntos de lado largo, aunque se haya subido una de 4000, y
   se entrega como WEBP.
3. Una imagen de menos de 1200 puntos no se amplía, y un WEBP o PNG transparente conserva la
   transparencia.
4. La dirección de la imagen solo responde con sesión iniciada y al dueño del artículo; a otro
   usuario le responde 404, y ningún archivo de imagen es alcanzable dentro de `public/`.
5. Reemplazar la imagen borra el archivo anterior del disco y la ficha muestra la nueva de inmediato,
   sin vaciar la caché del navegador.
6. "Quitar imagen" deja al artículo sin foto y borra el archivo; la ficha muestra "Sin imagen".
7. Una imagen dañada o de más de 10 MB en el alta muestra un error y no crea el artículo.
8. La carga masiva por selección múltiple asocia cada archivo al artículo del catálogo cuyo modelo
   coincide con el nombre del archivo, ignorando mayúsculas, acentos y la diferencia entre espacios,
   guiones y guiones bajos.
9. Con JavaScript, una selección de más de 20 imágenes se completa en tandas con barra de avance y
   termina con **un solo** reporte que suma todas, sin perder ningún archivo. Una petición de más de
   20 archivos se rechaza con un error visible.
10. Un archivo cuyo nombre no corresponde a ningún artículo del catálogo no se guarda, no crea ningún
    artículo y aparece en el reporte con su nombre y el motivo.
11. Un archivo que corresponde a más de un artículo del catálogo no se asigna a ninguno y se reporta
    nombrando el modelo ambiguo.
12. Un archivo que no es una imagen real, aunque termine en `.jpg`, no se guarda y se reporta sin
    impedir que se procesen los demás.
13. Los artículos que no recibieron imagen no aparecen en el reporte.
14. Un `.zip` plano se procesa igual que la selección múltiple; uno con carpetas se rechaza completo
    con un mensaje que pide volver a comprimir, y no guarda ninguna imagen.
15. Recargar la pantalla después de una carga no vuelve a subir los archivos.
16. En `/articulos`, hacer clic en el nombre de un producto abre la ficha con la foto, el nombre, el
    modelo y el precio con IVA; la tabla no muestra miniaturas ni columna de imagen. Sin JavaScript,
    el enlace lleva a la edición.
17. La ficha nunca muestra el precio del proveedor, el costo ni la utilidad.
18. En un navegador capaz de compartir archivos, "Compartir" abre el menú del sistema con la foto en
    **JPEG** y el texto; en uno que no puede, copia el texto y lo avisa; en un contexto no seguro,
    muestra el texto seleccionado para copiarlo.
19. La ficha y la pantalla de carga se muestran completas, sin desbordar ni scroll horizontal, en
    escritorio (≥1280px) y en móvil, con nombres largos, fotos muy anchas y reportes de cien archivos.
20. La barra de `/articulos` muestra "Nuevo artículo", "Importar CSV", "Subir imágenes" y "Exportar
    CSV", y cabe en el ancho de un celular.
21. La pantalla de imágenes arranca sin catálogo elegido. Elegir un catálogo **sin artículos** muestra
    el aviso **sin deshabilitar nada**, y el botón dice "Subir de todos modos"; con artículos no hay
    aviso y el botón dice "Subir imágenes". El aviso no dice cuántos archivos se descartarán.
22. Cambiar el catálogo con archivos elegidos o un reporte en pantalla limpia la pantalla.
23. Subir imágenes a un catálogo vacío termina con cero asociadas y el encabezado que señala el
    catálogo y los nombres de archivo como causa probable.
24. Importar el CSV a un catálogo vacío y abrir después la pantalla de imágenes ya no muestra el aviso
    para ese catálogo.
25. El reporte de imágenes tiene su botón "Copiar reporte" (con JavaScript).
26. Eliminar un artículo (borrado lógico) conserva su archivo de imagen.
27. La importación y exportación CSV no cambian.
28. Pint no reporta cambios, la suite Pest pasa, `node --test "tests/js/*.test.js"` pasa y `node --check` valida
    los archivos JavaScript nuevos.

## Supuestos asumidos (registro completo)

1. La carga masiva de artículos sigue siendo la importación CSV de 007/008, sin cambios.
2. El CSV no cambia de columnas: la imagen no se referencia en el CSV.
3. Las imágenes se suben en una operación y una pantalla aparte del CSV. El orden (primero los
   artículos, después las fotos) sigue importando, porque una foto sin artículo se descarta; la
   pantalla lo avisa cuando puede detectarlo y lo explica en el reporte cuando no.
4. Se suben por **selección múltiple o por un `.zip` plano**; un ZIP con carpetas se rechaza completo
   ("Enviar a → Carpeta comprimida" sobre una carpeta en Windows produce un ZIP con carpeta dentro).
5. El nombre del archivo es lo único que decide el emparejamiento, contra `modelo`, con la
   normalización descrita, dentro del catálogo elegido.
6. Una imagen por artículo. Subir otra la reemplaza sin preguntar; en una misma carga gana el último
   archivo y el desplazado se reporta.
7. Una imagen sin artículo se descarta y se reporta; nunca crea un artículo ni se guarda para después.
8. Un artículo sin imagen no es un error.
9. El reporte tiene la misma forma que el del CSV: cuántas se asociaron y el detalle de las que no.
10. Formatos aceptados: JPG, PNG y WEBP.
11. No hay modo cuadrícula ni miniaturas; el nombre del producto abre la ficha, que lleva "Editar".
12. La ficha muestra foto, nombre, modelo y precio con IVA (`precio_unitario_con_iva`, con el formato
    actual de 2 decimales); nunca costo, precio de proveedor ni utilidad.
13. No hay catálogo para el cliente, ni público ni en PDF; lo único que sale es lo que se comparte.
14. "Compartir" usa el menú del aparato con foto y texto cuando se puede, y copia el texto cuando no;
    no abre WhatsApp directamente.
15. Cada pantalla de carga pregunta su propio catálogo y arranca vacía; el catálogo vacío avisa, no
    bloquea.
16. **(Cambio de arquitectura)** Rutas web con sesión en lugar de `/api/v1` con Sanctum; el catálogo
    viaja en el formulario, no en la URL.
17. **(Cambio de arquitectura)** `tiene_imagen` e `imagen_version` son accessors del modelo, no campos
    de un `ArticuloResource`.
18. **(Cambio de arquitectura)** La ficha es un `<dialog>` nativo llenado con `data-*` de la fila
    (sin endpoint ni AJAX), en lugar del componente Vue `ArticuloDetalleDialog`.
19. **(Cambio de arquitectura)** La carga masiva es una pantalla Blade con redirect + reporte en
    flash (gemela de `/articulos/importar`), en lugar de un modal Vue. El JSON existe solo para las
    tandas.
20. **(Cambio de arquitectura)** Un botón suelto "Subir imágenes" en la barra, en lugar del menú
    "Archivos" con `DropdownMenu`: no hay componente de menú en el proyecto, la barra tiene solo
    cuatro acciones y "Exportar CSV" se reemplaza por AJAX desde la búsqueda dinámica.
21. **(Cambio de arquitectura)** La imagen individual se sube y se quita **desde el formulario del
    artículo**, también en el alta, en lugar de endpoints propios con el bloque deshabilitado en el
    alta.
22. **(Cambio de arquitectura)** El disco privado se justifica por el login, la independencia de
    `storage:link` y la sobrescritura de `public/` al desplegar, no por las restricciones del
    despliegue remoto.
23. **(Cambio de arquitectura)** La autorización usa `articulos.user_id` y `ArticuloPolicy` (nuevo
    `view`), no la cadena catálogo → proveedor.
24. **(Decisión nueva)** Se conservan las tandas automáticas por JavaScript para más de 20 imágenes;
    sin JavaScript el límite es 20 por envío y para más se usa un `.zip`.
25. **(Decisión nueva)** "Copiar reporte" solo en la pantalla de imágenes; el reporte del CSV no
    cambia.
26. **(Decisión nueva)** El servidor de producción no está definido; se conserva la salida
    alternativa a JPEG si falta WEBP.
27. **(Adición técnica)** Una sola versión reducida (1200 px, WEBP 82) con nombre generado
    `{articulo_id}-{8 al azar}.webp`; la original se descarta. Archivos comprobados por contenido;
    del ZIP solo se usa el nombre de cada entrada; tope de 400 MB descomprimido.
28. **(Adición técnica)** "Compartir" convierte a JPEG en el navegador; el servidor guarda una sola
    copia en WEBP.
29. **(Adición técnica)** Respaldo para contexto no seguro en Compartir y Copiar (texto seleccionado
    en un campo de solo lectura).
30. **(Adición técnica)** La imagen del formulario se valida como legible antes de crear el artículo,
    y el alta con imagen va en una transacción.
31. **(Adición técnica)** Orden de escritura al reemplazar: archivo nuevo → columna → borrar el
    anterior; nunca queda la columna apuntando a un archivo inexistente.
32. **(Adición técnica)** El reporte viaja en la sesión (`SESSION_DRIVER=database`), que admite
    reportes largos; no cambiar a sesión por cookie sin revisarlo.
33. **(Adición técnica)** Migración `add_imagen_ruta_to_articulos_table` con columna nullable sin
    índice.
34. Iconos nuevos: `images` (barra), `image` (sin imagen), `share`, `copy`; se reutilizan `upload`,
    `pencil`, `x-lg`, `arrow-left`.
