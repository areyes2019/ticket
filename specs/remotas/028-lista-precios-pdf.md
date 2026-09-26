# Spec: Lista de precios en PDF desde el listado de artículos

## Historia de usuario

Como usuario, quiero marcar un grupo de artículos en `/articulos` —filtrando por catálogo y
usando "seleccionar todos", o marcándolos uno por uno— y generar con un clic una lista de precios
en PDF con la miniatura de su foto (si la tiene), su nombre, su modelo y su precio, para
compartirla de inmediato por el menú nativo de Windows 11 con un cliente, un distribuidor o el
público en general, sin editar cada artículo ni armar el documento a mano. Antes de generar el PDF
elijo qué precio lleva la lista —el de distribuidor o el precio general al público—, porque me
piden ambos tipos de lista y no quiero que se mezclen en un mismo documento. La miniatura ayuda a
reconocer cada artículo de un vistazo, sin depender de que el modelo por sí solo le diga algo a
quien recibe la lista.

## Objetivo / Alcance

Un botón nuevo, **"Compartir Lista"**, en la misma barra de selección que ya usan "Eliminar"
([021](021-mantenimiento-articulos-catalogos.md)) y "Mover a catálogo"
([034](034-filtro-catalogo-y-mover-lote-articulos.md)), junto a un selector de **tipo de
precio** (Distribuidor / Público, con "Distribuidor" preseleccionado): con uno o más artículos
marcados, genera un PDF con la miniatura de la foto (si el artículo tiene una, ver
[020](020-imagenes-articulos.md)), el nombre, el modelo y el precio elegido (con IVA incluido) de
cada artículo seleccionado, y lo entrega al menú de compartir del sistema operativo mediante el
mecanismo que [`lib/compartir.ts`](../frontend/src/lib/compartir.ts) ya resuelve para cotizaciones,
facturas y tickets.

Las dos listas nunca se mezclan: cada PDF lleva un único tipo de precio, nunca ambos en la misma
tabla o documento. No se crea ningún mecanismo nuevo de selección de artículos: se reutilizan los
checkboxes de la tabla, el filtro de catálogo de 034 y `compartirArchivo()` tal como están. Tampoco
se crea un documento persistente: la lista se genera al vuelo, no se guarda en base de datos ni se
asocia a ningún cliente.

## Backend (Laravel)

### Endpoint

| Método | Ruta | Qué hace |
| --- | --- | --- |
| `POST` | `/api/v1/articulos/lista-precios` | `{ "ids": [1, 2, 3], "tipo": "distribuidor" }` → PDF (`application/pdf`) |

Se registra junto a `eliminar-lote` y `mover-lote`, **antes** del `apiResource('articulos')`,
siguiendo la misma convención de rutas específicas primero (`routes/api.php:79-83`).

### `ListaPreciosArticulosRequest`

Mismas reglas que `EliminarLoteArticulosRequest` (`app/Http/Requests/Articulos/EliminarLoteArticulosRequest.php`)
para `ids`: requerido, array, mínimo 1; cada uno entero y existente en `articulos`, restringido al
`user_id` autenticado y no borrado. Un identificador ajeno o inexistente rechaza la petición
completa con `422` — no tiene sentido generar "lo que sí existe" en un documento que el usuario va
a compartir tal cual sale.

Se suma `tipo`: requerido, string, `in:distribuidor,publico`. No tiene un valor por defecto en el
servidor —lo decide siempre el frontend, preseleccionado a "distribuidor" en el selector— porque
depender de un default silencioso en dos capas distintas es más fácil de desincronizar que exigir
el dato explícito en cada petición. Un `tipo` fuera de esas dos opciones responde `422`.

No hay un tope máximo de `ids` en el servidor: el aviso por selección grande (ver "Frontend") es
solo del navegador, para no tener que sincronizar dos números si algún día cambia.

### `ArticuloController::listaPrecios`

```php
private const TITULOS_PRECIO = [
    'distribuidor' => 'Precio Distribuidor',
    'publico' => 'Precio Público',
];

public function listaPrecios(ListaPreciosArticulosRequest $request, ImagenArticuloService $imagenes): Response
{
    $ids = $request->validated()['ids'];
    $tipo = $request->validated()['tipo'];

    $articulos = $request->user()->articulos()
        ->whereIn('id', $ids)
        ->with('catalogo')
        ->get()
        ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE);

    // Miniatura y precio a mostrar, como atributos transitorios (no persistidos): la vista los
    // lee como cualquier otro campo del artículo, sin que el controlador pase un mapa aparte ni
    // la vista sepa nada de ImagenArticuloService ni decida qué precio corresponde a cada tipo.
    foreach ($articulos as $articulo) {
        $articulo->miniatura_base64 = $imagenes->miniaturaBase64($articulo, self::LADO_MINIATURA_LISTA_PRECIOS);
        $articulo->precio_lista = $tipo === 'distribuidor'
            ? $articulo->precio_distribuidor_con_iva
            : $articulo->precio_unitario_con_iva;
    }

    $secciones = $articulos
        ->groupBy(fn (Articulo $articulo) => $articulo->catalogo?->nombre ?? 'Sin catálogo')
        ->sortKeys();

    $pdf = app('dompdf.wrapper')->loadView('pdf.lista-precios', [
        'secciones' => $secciones,
        'mostrarSecciones' => $secciones->count() > 1,
        'tituloColumnaPrecio' => self::TITULOS_PRECIO[$tipo],
        'fecha' => now(),
    ]);

    return $pdf->stream("lista-precios-{$tipo}-".now()->format('Y-m-d').'.pdf');
}
```

- **Orden alfabético** (adición técnica 1): se ordena antes de agrupar, y `groupBy` conserva el
  orden relativo dentro de cada grupo, así que no hace falta ordenar dos veces.
- **Agrupar por catálogo solo si hay más de uno** (adición técnica 2): `mostrarSecciones` es
  `false` cuando todos los artículos seleccionados son del mismo catálogo, y la vista no imprime
  ningún subtítulo — mezclar todo bajo un único encabezado de sección sería ruido cuando ya se
  sabe de qué catálogo se trata.
- **El precio impreso depende de `tipo`**: con `distribuidor`, es
  `$articulo->precio_distribuidor_con_iva` (`Articulo.php:148-156`), el mismo accessor que ya usa
  la ficha compartida del artículo (`ArticuloDetalleDialog.vue`); con `publico`, es
  `$articulo->precio_unitario_con_iva` (`Articulo.php:134-142`), el precio general que ya existe en
  cada artículo. Ambos aplican el factor de IVA correcto según `objeto_imp` — un artículo que no
  causa IVA sale con su factor en 1.0, igual que en cualquier otra pantalla del sistema. El
  controlador resuelve cuál de los dos usar antes de renderizar; la plantilla solo imprime
  `precio_lista`, sin saber que existen dos tipos.
- El título de la columna de precio también depende de `tipo` (`self::TITULOS_PRECIO`): "Precio
  Distribuidor" o "Precio Público", para que quien reciba el PDF sepa de inmediato qué lista es sin
  tener que preguntar.
- Un artículo sin el precio correspondiente configurado (`precio_distribuidor_sin_iva` o
  `precio_unitario_sin_iva` en `0`) se imprime igual, con `$0.00`: no se excluye ni se oculta, para
  que la ausencia de precio sea visible y no un artículo que simplemente falta.
- El nombre del archivo servido incluye el tipo (`lista-precios-distribuidor-2026-08-25.pdf` /
  `lista-precios-publico-2026-08-25.pdf`), para que dos listas del mismo día no se confundan ni se
  sobrescriban en la carpeta de descargas de quien las recibe.
- No se persiste nada: no hay modelo, migración ni tabla nueva para esta lista. Es un documento
  efímero, igual de espíritu a como el ticket de mostrador se genera al vuelo
  (`TicketPedidoService`).

### La miniatura de la imagen

`ImagenArticuloService` (ver [020](020-imagenes-articulos.md)) gana un método nuevo,
`miniaturaBase64(Articulo $articulo, int $ladoMaximo): ?string`, hermano de `contenido()` que ya
tenía:

```php
public function miniaturaBase64(Articulo $articulo, int $ladoMaximo): ?string
{
    $ruta = $this->rutaSiExiste($articulo);

    if ($ruta === null) {
        return null;
    }

    try {
        $contenido = $this->procesador->procesar(Storage::disk('local')->path($ruta), $ladoMaximo);
    } catch (Throwable) {
        return null;
    }

    return 'data:image/webp;base64,'.base64_encode($contenido);
}
```

- **Es un segundo tamaño, no la foto completa.** La imagen guardada del artículo ya está reducida a
  `ImagenArticuloService::LADO_MAXIMO` (1200 puntos de lado largo), pensada para una ficha con una
  sola foto grande. Incrustar esa versión en una tabla que puede llevar decenas de artículos
  multiplicaría el peso del PDF y el tiempo de generación por cada fila. Se reprocesa con
  `ProcesadorImagen` —la misma pieza que ya usan `ImagenArticuloService` y `LogoBancoService`, cada
  una con su propio tamaño (ver [026](026-datos-bancarios-cotizacion.md))— a un lado máximo de
  **120 puntos**, con la constante `ArticuloController::LADO_MINIATURA_LISTA_PRECIOS`.
- **Se lee del disco por ruta, no por los bytes de `contenido()`.** `ProcesadorImagen::procesar()`
  necesita una ruta real de archivo (usa `getimagesize()` e `imagecreatefromwebp()`), así que se
  resuelve con `Storage::disk('local')->path($ruta)` en vez de pasar por `Storage::get()` y guardar
  un archivo temporal.
- **Sin imagen, sin archivo en disco o con un archivo dañado, devuelve `null` en silencio, sin
  `Log::warning`.** A diferencia del logo del emisor —que si falta se nota en cada documento fiscal
  y por eso 019 sí lo registra—, la miniatura de un artículo es puramente decorativa: perderla no
  cambia ningún dato del PDF, y un artículo de cada varios sin foto es el caso normal, no una falla
  que valga la pena anotar.
- Se calcula en el controlador y se cuelga como atributo transitorio del modelo
  (`$articulo->miniatura_base64 = ...`), sin persistirse: la vista lo lee igual que cualquier otro
  campo del artículo.

### Vista `resources/views/pdf/lista-precios.blade.php`

**No extiende `pdf.documento`.** Esa plantilla base asume una tabla de conceptos con cantidad,
descuento e IVA por línea y un bloque de totales (`documento.blade.php:163-239`) que una lista de
precios no tiene — forzar esos campos con valores inventados solo para que la vista no truene
sería peor que tener una plantilla propia. Lo que sí se reutiliza es la paleta y la tipografía: el
gris azulado `#2c3e50` para títulos, `#95a5a6` para bordes de tabla, `#f5f5f5` para el fondo de
cabecera, y DejaVu Sans, para que el documento se sienta de la misma familia que cotizaciones y
facturas sin ser una extensión forzada de esa plantilla.

Al vivir bajo `resources/views/pdf/`, la vista recibe `$emisor` y `$sat` gratis:
`View::composer('pdf.*', EmisorComposer::class)` (`AppServiceProvider.php:44`) ya se registró
sobre todo el namespace `pdf.*`, no solo sobre `pdf.documento` — no hace falta tocar el composer.

Estructura:

- **Encabezado**: los dos logos del emisor a la izquierda (`$emisor->logoBase64('principal')` /
  `'marca'`), misma caja de 55×40 mm que la plantilla base; a la derecha, "Lista de precios" en
  18pt sobre `#2c3e50` y debajo, en 13pt, la fecha de generación (adición técnica 5) en formato
  `d/m/Y`. Sin logos cargados, el encabezado no se descuadra, igual que en los otros tres
  documentos.
- **Por cada sección** (si `$mostrarSecciones`): un subtítulo con el nombre del catálogo, en el
  mismo estilo `.parte-titulo` de la plantilla base.
- **Tabla por sección**: columnas Miniatura, Nombre, Modelo, `$tituloColumnaPrecio` — con el mismo
  estilo de bordes y cabecera que `.items` en `documento.blade.php`. La cabecera de la última
  columna cambia según el tipo de lista ("Precio Distribuidor" o "Precio Público"); cada fila
  imprime `$articulo->precio_lista`, ya resuelto por el controlador según `tipo`, sin que la
  plantilla decida entre los dos campos. Sin columnas de cantidad, descuento ni IVA:
  esta lista no es una cotización. La columna de miniatura es la primera, a la izquierda, sin texto
  en su cabecera (una celda vacía, igual que la columna de la casilla de selección en la tabla de
  `/articulos`); su celda de datos queda vacía cuando el artículo no tiene `miniatura_base64`, sin
  ningún ícono de "sin foto" que la reemplace. La imagen se limita a 15×15 mm —en milímetros, no en
  píxeles, mismo criterio que los logos del encabezado— para que quepa cómoda en el ancho de la
  columna sin desbordar la fila.
- **Pie**: *"Precios sujetos a cambio sin previo aviso — vigentes al {fecha en d/m/Y}."* (adición
  técnica 3), en el mismo estilo `.nota` que el resto de los documentos.

### Tests

Feature tests sobre la base MySQL de trabajo con `php artisan test`, nunca `migrate:fresh`
([[feedback_nunca_migrate_fresh_en_dev]]):

1. `POST /articulos/lista-precios` con artículos de un solo catálogo devuelve un PDF sin secciones
   visibles (una sola, sin subtítulo).
2. Con artículos de dos catálogos distintos, el PDF agrupa cada uno bajo su propio subtítulo.
3. Los artículos salen ordenados alfabéticamente por nombre dentro de cada sección.
4. Con `tipo=distribuidor`, el precio impreso es el precio distribuidor con IVA incluido, no el
   directo ni el sin IVA.
5. Con `tipo=publico`, el precio impreso es el precio general (directo) con IVA incluido, no el
   distribuidor ni un precio sin IVA.
6. Un artículo con el precio correspondiente al tipo pedido en `0` se incluye en el PDF con
   `$0.00`.
7. Un artículo que no causa IVA (`objeto_imp` sin traslado) imprime su precio, del tipo que sea,
   sin el 16% adicional.
8. La cabecera de la columna de precio dice "Precio Distribuidor" con `tipo=distribuidor` y
   "Precio Público" con `tipo=publico`.
9. El nombre de archivo devuelto por el PDF incluye el tipo pedido
   (`lista-precios-distribuidor-{fecha}.pdf` / `lista-precios-publico-{fecha}.pdf`).
10. Un `tipo` distinto de `distribuidor` o `publico`, o ausente, responde `422` y no genera ningún
    PDF.
11. Un `id` ajeno o inexistente en la lista responde `422` y no genera ningún PDF.
12. El PDF se genera igual sin logos del emisor cargados y con el emisor vacío, sin lanzar
    excepción — mismo criterio de "nunca se bloquea" que 019.
13. Un artículo con imagen imprime su miniatura (una data URI `data:image/webp;base64,...`) en la
    primera columna de su fila.
14. Un artículo sin imagen no imprime ninguna miniatura, sin romper el PDF.
15. `ImagenArticuloService::miniaturaBase64()` reduce la imagen ya guardada a un lado máximo menor
    (probado con 120), sin importar el tamaño de la imagen original.
16. `miniaturaBase64()` devuelve `null` tanto si el artículo no tiene imagen como si el archivo
    referenciado por `imagen_ruta` ya no está en disco, sin lanzar ninguna excepción.

## Frontend (Vue 3)

La miniatura no toca el frontend: es enteramente responsabilidad del PDF generado en el servidor,
a partir de una imagen que ya existe (o no) por el flujo normal de carga de fotos de
[020](020-imagenes-articulos.md). No hay ningún control nuevo para subir, cambiar o desactivar la
miniatura desde `/articulos`.

### `stores/articulos.ts`

Nueva acción, junto a `removeLote` y `moverLoteCatalogo`:

```ts
export type TipoListaPrecios = 'distribuidor' | 'publico'

/** El PDF de la lista de precios, dibujado por el servidor, como Blob listo para compartir. */
async listaPreciosBlob(ids: number[], tipo: TipoListaPrecios): Promise<Blob> {
  const { data } = await http.post('/articulos/lista-precios', { ids, tipo }, { responseType: 'blob' })
  return new Blob([data], { type: 'application/pdf' })
},
```

Mismo patrón que `pedidos.ticketBlob()` (`stores/pedidos.ts:213-216`).

### `ArticulosListView.vue`

- Selector de **tipo de precio** en la barra de selección (`ArticulosListView.vue:539-557`), junto
  al botón "Compartir Lista": dos opciones, "Distribuidor" y "Público", con "Distribuidor"
  preseleccionado al aparecer la barra. Es un `ref<TipoListaPrecios>` local a la vista, no
  persistido — cada vez que la barra de selección vuelve a aparecer (nueva selección) arranca de
  nuevo en "Distribuidor".
- Botón **"Compartir Lista"**, junto a "Mover a catálogo" y "Eliminar", visible solo con
  `seleccionados.length > 0`.
- Al hacer clic:
  1. Si hay más de 100 artículos seleccionados (adición técnica 4), se muestra un aviso de
     confirmación —mismo componente de diálogo simple que ya usan Eliminar y Mover— con el conteo
     y un texto de "puede tardar unos segundos en generarse"; botones Cancelar / Continuar. Con
     100 o menos, se salta este paso y se continúa de inmediato. El aviso es el mismo sin importar
     el tipo de precio elegido.
  2. Se pide el PDF con el tipo elegido en el selector:
     `const blob = await articulos.listaPreciosBlob([...seleccionados.value], tipoPrecio.value)`.
  3. Se comparte de inmediato, **sin texto acompañante**:
     `await compartirArchivo(blob, `lista-precios-${tipoPrecio.value}-${fechaHoyISO}.pdf`)` — igual
     que el resto de los PDF de escritorio (cotización, factura): sin texto no hay canal que
     elegir, así que el respaldo es dejar el archivo descargado, sin abrir WhatsApp por su cuenta
     ([`compartir.ts:38-43`](../frontend/src/lib/compartir.ts)).
  4. Si el resultado es `'descargado'`, se muestra un aviso: "Lista descargada.". Si la generación
     falla en el servidor, se muestra "No se pudo generar la lista de precios." y la selección se
     conserva para reintentar.
- El botón muestra un estado de carga ("Generando…") mientras dura el paso 2, y queda deshabilitado
  ese rato para evitar clics repetidos; el selector de tipo también queda deshabilitado ese rato,
  para no cambiarlo a mitad de una generación en curso.
- **La selección no se limpia automáticamente al terminar**, a diferencia de Eliminar y Mover: esos
  dos cambian el listado en el servidor y por eso vacían la selección; compartir no cambia nada, y
  el usuario puede querer generar la lista de nuevo o ajustar qué artículos lleva.

## Fuera de alcance

- **Un mecanismo de selección propio de esta pantalla.** Se reutiliza tal cual el de
  [021](021-mantenimiento-articulos-catalogos.md): la selección sobrevive a cambios de página,
  búsqueda, orden o filtro de catálogo, y existe un botón "Seleccionar todo lo filtrado" para no
  recorrer página por página cuando lo que se busca comparte un filtro — ambos definidos en 021, no
  aquí.
- **Guardar o recordar listas de precios generadas.** Cada PDF es efímero y no deja rastro en base
  de datos.
- **Elegir qué columnas mostrar, o un tercer tipo de precio.** Siempre Miniatura, Nombre, Modelo y
  una sola columna de precio; solo se elige entre precio distribuidor o precio público, ambos con
  IVA incluido — no hay una variante sin IVA ni un precio distinto de esos dos.
- **Generar ambas listas (distribuidor y público) en una sola petición o un solo PDF.** Cada
  generación produce un documento con un único tipo de precio; para tener las dos, se genera dos
  veces.
- **Enviar la lista por correo o abrir WhatsApp con un texto propio.** Solo el menú nativo de
  compartir del sistema operativo, sin acompañarlo de un mensaje.
- **Un límite duro de artículos por lista.** Más de 100 solo dispara un aviso, nunca bloquea la
  generación.
- **Exportar a otro formato** (Excel, CSV, imagen). Solo PDF.
- **Marca de agua, contraseña o cualquier protección del PDF.**
- **Subir, cambiar o quitar la imagen del artículo desde esta pantalla.** La miniatura solo lee la
  que ya exista; para tenerla, gestionarla o reemplazarla se sigue usando el flujo de 020.
- **Un ícono o marcador genérico para "sin foto".** El artículo sin imagen deja la celda vacía, no
  una silueta ni un texto sustituto.
- **Recortar, encuadrar o editar la imagen para la miniatura.** Se usa la foto ya guardada tal cual,
  solo reducida de tamaño; no hay selección de la parte de la imagen que se muestra.
- **Registrar en el log una imagen faltante o dañada** al generar la lista de precios. Es
  decorativa: la ausencia de una miniatura no es un error que valga la pena anotar (a diferencia
  del logo del emisor en 019).
- Roles, permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Criterios de aceptación

1. Con uno o más artículos marcados en `/articulos`, la barra de selección muestra el selector de
   tipo de precio (Distribuidor / Público, con Distribuidor preseleccionado) y el botón "Compartir
   Lista", junto a "Mover a catálogo" y "Eliminar".
2. Al hacer clic con "Distribuidor" elegido, se genera un PDF con la miniatura de la foto (si la
   tiene), nombre, modelo y precio distribuidor con IVA de cada artículo seleccionado, con la
   columna encabezada "Precio Distribuidor".
3. Al hacer clic con "Público" elegido, se genera un PDF igual pero con el precio general (directo)
   con IVA de cada artículo, con la columna encabezada "Precio Público".
4. Los dos tipos de lista nunca aparecen mezclados en un mismo PDF: cada documento lleva una sola
   columna de precio, del tipo elegido.
5. Si todos los artículos seleccionados pertenecen al mismo catálogo, el PDF no muestra subtítulos
   de sección. Si pertenecen a más de un catálogo, aparecen agrupados bajo un subtítulo por
   catálogo, sin importar el tipo de precio elegido.
6. Dentro de cada sección (o de la lista completa, si no hay secciones), los artículos aparecen
   ordenados alfabéticamente por nombre.
7. Un artículo sin el precio correspondiente al tipo elegido aparece en la lista con `$0.00`, no se
   omite.
8. El PDF lleva el logotipo del emisor en el encabezado, igual que cotizaciones y facturas, y la
   fecha de generación visible.
9. El PDF incluye al pie la leyenda de vigencia de precios con la fecha de generación.
10. El nombre del archivo generado incluye el tipo de precio elegido
    (`lista-precios-distribuidor-{fecha}.pdf` o `lista-precios-publico-{fecha}.pdf`).
11. Al terminar de generarse, se invoca el menú nativo de compartir de Windows 11 con el PDF como
    único archivo adjunto, sin ningún texto acompañante.
12. Si el menú nativo no está disponible, el PDF se descarga y se muestra un aviso de que se
    descargó; no se abre WhatsApp automáticamente.
13. Seleccionar más de 100 artículos y pulsar "Compartir Lista" muestra un aviso de confirmación
    antes de generar el PDF, sin importar el tipo de precio elegido; seleccionar 100 o menos genera
    el PDF de inmediato sin ese aviso.
14. Un lote con un artículo ajeno o inexistente, o con un `tipo` distinto de "distribuidor" o
    "publico", responde `422` y no genera ningún PDF.
15. Tras compartir o descargar la lista, la selección de artículos y el tipo de precio elegido se
    mantienen tal como estaban.
16. El PDF se genera igual con el emisor vacío o sin logos cargados, sin error.
17. Un artículo con imagen aparece en el PDF con una miniatura de su foto en la primera columna de
    su fila, reducida (no la imagen a tamaño completo).
18. Un artículo sin imagen, o cuyo archivo de imagen ya no está en disco, deja esa celda vacía sin
    romper el PDF y sin dejar rastro en el log.
19. Pint corre sin errores sobre el backend, ESLint y Prettier sobre el frontend, la suite de Pest
    sigue pasando, y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

Los 11 primeros son las asunciones funcionales aceptadas al definir la historia; del 12 al 16, las
cinco adiciones técnicas resueltas; del 17 al 20, la miniatura de imagen agregada en una revisión
posterior; del 21 al 30, la extensión para incluir el precio público general.

1. La selección de artículos para la lista de precios usa los mismos checkboxes que ya existen en
   la tabla de artículos, sin crear un mecanismo de selección nuevo.
2. "Seleccionar un catálogo" se logra filtrando la tabla por ese catálogo y usando "seleccionar
   todos" de la cabecera, no con una opción independiente de "catálogo completo".
3. **(Revisado en 021, "Selección persistente entre páginas")** La selección sobrevive a cambiar de
   página, buscar, ordenar o cambiar el filtro de catálogo, igual que las demás acciones en lote;
   ya no queda limitada a la página visible.
4. El botón "Compartir Lista" vive en la misma barra de acciones en lote que "Mover a catálogo" y
   "Eliminar", visible solo con al menos un artículo marcado.
5. El precio impreso es el precio distribuidor con IVA incluido, no el precio directo ni un precio
   sin IVA.
6. Un artículo seleccionado sin precio distribuidor configurado aparece igual en la lista, con
   `$0.00`, no se excluye.
7. El PDF es una lista simple de artículo y precio, sin cantidades ni importes.
8. El PDF reutiliza el logotipo y el estilo de los demás documentos, con encabezado "Lista de
   precios" y sin folio ni datos de cliente.
9. La lista de precios es un documento efímero: no se guarda ningún registro ni se asocia a ningún
   cliente.
10. Compartir reutiliza tal cual `compartirArchivo()`, sin ningún flujo de compartir nuevo.
11. El nombre del archivo generado es genérico (`lista-precios-{fecha}.pdf`), no personalizado por
    cliente ni catálogo.
12. **(Adición técnica)** La lista se ordena automáticamente por nombre en vez de conservar el
    orden en que se fueron marcando los checkboxes.
13. **(Adición técnica)** Si la selección incluye artículos de más de un catálogo, el PDF los
    agrupa en secciones con un subtítulo por catálogo; si es de uno solo, no se muestra ningún
    subtítulo.
14. **(Adición técnica)** El PDF lleva al pie una leyenda de vigencia de precios con la fecha de
    generación.
15. **(Adición técnica)** Seleccionar más de 100 artículos muestra un aviso de confirmación antes
    de generar el PDF, sin bloquear la operación.
16. **(Adición técnica)** La fecha de generación del PDF se imprime de forma visible en el
    encabezado del documento.
17. Se agrega una miniatura de la fotografía del artículo (si la tiene) en una columna nueva, la
    primera de la tabla, a la izquierda del nombre.
18. La miniatura es una copia reducida de la imagen ya guardada del artículo (020), a un tamaño
    bastante menor que el de esa imagen, no la original a tamaño completo — para no inflar el peso
    ni el tiempo de generación del PDF cuando la lista lleva muchas fotos.
19. Un artículo sin imagen, o cuyo archivo de imagen ya no está en disco, deja esa celda vacía, sin
    ícono sustituto y sin registrar nada en el log: es decorativa, no algo que deba avisarse como
    lo hace un logo faltante.
20. No se ofrece ninguna forma de excluir la miniatura, elegir su tamaño o recortarla desde esta
    pantalla; aparece siempre que el artículo tenga una imagen guardada.
21. La elección entre lista de precios distribuidor y lista de precios al público se hace con un
    control antes de generar el PDF; nunca se generan ambos precios en el mismo documento.
22. La lista al público usa el precio general ya existente en el artículo (`precio_unitario_sin_iva`
    con IVA), igual que la de distribuidor usa `precio_distribuidor_sin_iva`.
23. El encabezado de la columna de precio en el PDF cambia según el tipo elegido: "Precio
    Distribuidor" o "Precio Público".
24. El nombre del archivo generado y el texto al compartir reflejan el tipo de lista.
25. Todos los artículos pueden incluirse en la lista al público, tengan o no precio distribuidor
    cargado, porque el precio general siempre existe en el artículo.
26. Si un artículo no tiene precio distribuidor cargado y se pide la lista distribuidor, se
    comporta igual que antes de esta extensión (aparece con `$0.00`), sin cambios en ese caso.
27. La opción de elegir tipo de lista está disponible para cualquier usuario que ya tenga acceso al
    botón "Compartir Lista", sin restricciones nuevas de permisos.
28. El límite y la confirmación al seleccionar más de 100 artículos aplican igual para ambos tipos
    de lista.
29. El diseño general del PDF (miniatura, nombre, modelo, agrupación por catálogo, orden
    alfabético) no cambia con esta extensión; solo cambia qué precio se muestra y el título de esa
    columna.
30. El selector de tipo de precio preselecciona "Distribuidor" por defecto, para mantener el
    comportamiento previo a esta extensión sin sorprender a quien ya usaba la función.

## Estado de implementación

Implementada el 2026-08-22, en dos partes.

- **Primera versión** (supuestos 1-16): endpoint, plantilla, botón "Compartir Lista" y su diálogo
  de aviso. Verificada en un navegador real (Playwright/Chromium contra `php artisan serve` y
  `npm run dev`, con usuario y artículos de prueba creados y eliminados al terminar): seleccionar
  artículos y pulsar "Compartir Lista" generó el PDF (`200`, `application/pdf`) y, sin menú nativo
  de compartir disponible en el entorno de prueba, se descargó mostrando "Lista descargada.".
  Desplegada a producción el mismo día (commit `d7af4b7`).
- **Miniatura de imagen** (supuestos 17-20), agregada el mismo día a partir de una petición de
  seguimiento: `ImagenArticuloService::miniaturaBase64()`, la columna nueva en la plantilla y el
  cambio en `ArticuloController::listaPrecios`. Sin cambios de frontend.

**Verificación**: `php artisan test` completo en verde (614 pruebas, incluidas las 4 nuevas de
`miniaturaBase64` en `ArticuloImagenesTest.php` y las 2 de contenido con/sin miniatura en
`ArticulosTest.php`); Pint limpio sobre los archivos tocados. No se generó un PDF real con una
fotografía para inspección visual en esta ronda —las pruebas comprueban que la miniatura se
incrusta como una data URI válida y que reduce el tamaño de la imagen original, no cómo se ve
maquetada la columna—, así que la maquetación final (si 15×15 mm y los anchos de columna quedan
bien a simple vista) queda pendiente de una revisión visual la próxima vez que se genere una lista
con artículos que sí tengan foto.
- **Precio público general** (supuestos 21-30): pendiente de implementar. Agrega el selector de
  tipo de precio en el frontend, el parámetro `tipo` en la petición y su validación, la resolución
  del precio y el título de columna en `ArticuloController::listaPrecios`, y el uso de
  `$tituloColumnaPrecio` en la plantilla.
