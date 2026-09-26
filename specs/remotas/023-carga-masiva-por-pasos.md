# Spec: Carga masiva en un solo modal por pasos

> **Superada en parte el 2026-08-18.** El modal fusionado que describe esta historia —un solo
> `Dialog`, un catálogo para los dos pasos y el candado del paso 2 en catálogo vacío— **se deshizo**:
> las dos cargas masivas volvieron a ser dos pantallas independientes. Lo vigente está en
> [020](020-imagenes-articulos.md), sección *Las dos cargas masivas son dos pantallas aparte*.
>
> Lo que **sigue vigente de esta spec** es todo lo que no dependía de la fusión: el `modelo` en la
> fila rechazada del CSV, el botón "Copiar reporte", el encabezado del reporte cuando ninguna imagen
> empareja, y `CatalogoSelect.vue` exponiendo el catálogo elegido completo.

## Historia de usuario

Como usuario del sistema que no lo construyó, quiero que la pantalla me diga en qué orden se cargan
los artículos y sus fotos, para no tener que adivinar cuál de los dos botones va primero ni
descubrirlo por un reporte lleno de errores.

## Objetivo / Alcance

Fusionar los dos modales de carga masiva de `/articulos` —"Importar CSV" de
[006](006-gestion-articulos.md)/[009](009-catalogos.md) y "Subir imágenes" de
[020](020-imagenes-articulos.md)— en **un solo modal con el catálogo elegido una vez y dos pasos
numerados**.

Es casi toda de frontend. Del backend se toca **una sola cosa**: que el error de una fila rechazada
del CSV diga de qué artículo se trata y no solo en qué renglón iba. No cambia ningún endpoint,
ningún formato de CSV ni ninguna regla de emparejamiento de imágenes: el resto del backend de
[009](009-catalogos.md) y [020](020-imagenes-articulos.md) se queda como está, y las dos operaciones
siguen siendo dos peticiones independientes.

### El problema que resuelve

El orden correcto —primero el CSV, después las fotos— **no es una preferencia, es un requisito**: la
carga masiva de imágenes solo empareja contra artículos que ya existen, y un archivo que no
encuentra artículo se descarta y se reporta, nunca crea nada
([020](020-imagenes-articulos.md), supuesto 14). Quien suba las fotos primero no obtiene un aviso:
obtiene doscientos errores idénticos.

Hoy nada en la pantalla comunica eso. Los dos botones son hermanos indistinguibles en la barra de
`/articulos`, junto a "Exportar CSV" y "Nuevo artículo", y **cada modal pide su propio catálogo por
separado**. El orden vive en la spec y en la cabeza de quien la escribió.

Ese segundo detalle abre un error más silencioso que el del orden: importar el CSV a un catálogo y
las fotos a otro. El sistema hace exactamente lo que se le pidió, el reporte dice "0 imágenes
asociadas" y no hay nada que sugiera que el problema fue el selector de arriba.

## Backend (Laravel)

### La fila rechazada dice cuál artículo es

El caso que lo destapó: se importan 50 modelos, 3 filas fallan, entran 47 artículos, y después se
suben las 50 imágenes. Tres fotos no encuentran artículo.

Lo primero que hay que decir es que **esas tres imágenes no quedan huérfanas en ninguna parte**. El
emparejamiento ocurre antes de escribir nada: `CargaMasivaImagenesService::procesar` busca el
artículo y solo llama a `guardar()` después de encontrar exactamente uno, así que un archivo sin
artículo se descarta y se reporta sin haber tocado el disco. No hay basura que limpiar ni un caso
nuevo que atender del lado del servidor.

Lo que sí queda es trabajo del usuario, y es ahí donde el sistema lo deja solo: **los dos reportes
hablan idiomas distintos**. El de imágenes nombra archivos (`Printer 38.webp`); el del CSV nombra
renglones (`Fila 12: el campo nombre ya ha sido tomado`). Para saber cuál de las tres fotos
corresponde a cuál fila rechazada hay que abrir el CSV y contar renglones a mano.

Por eso el error de importación agrega el **`modelo`** de la fila:

```json
{ "importados": 47, "errores": [{ "fila": 12, "modelo": "Printer 38", "motivo": "..." }] }
```

- Se elige `modelo` y no `nombre` porque **`modelo` es el campo con el que se emparejan las
  imágenes** ([020](020-imagenes-articulos.md)): es exactamente el dato que conecta el renglón
  rechazado con la foto que se va a quedar sin artículo.
- Si la celda de `modelo` viene vacía, se cae a `nombre`; si tampoco hay, va `null` y el reporte
  muestra solo la fila, como hasta ahora.
- **El valor va en su propia clave, no dentro de `motivo`.** El motivo sigue siendo la causa y nada
  más; quién compone la línea que se lee es el frontend. Es una clave nueva en un objeto que ya
  existía, así que nada de lo que hoy consume el reporte se rompe.
- El texto se toma **de la celda tal como venía**, sin normalizar: si el modelo estaba mal escrito,
  verlo mal escrito en el reporte es justamente lo que permite corregirlo.

## Frontend (Vue 3)

### La barra de `/articulos`

Los botones "Importar CSV" y "Subir imágenes" se reemplazan por **uno solo: "Carga masiva"**.

"Exportar CSV" **se queda como está, aparte**: es una salida, no una entrada, y no tiene orden
respecto de nada. Meterlo en el mismo modal solo agregaría una tercera cosa que decidir a quien
entra a cargar.

### El modal

Un solo `Dialog` con esta estructura:

1. **Selector de catálogo**, arriba y fuera de los pasos — el mismo `CatalogoSelect.vue` de
   [009](009-catalogos.md). Es el catálogo de los dos pasos.
2. **Paso 1 — Artículos (CSV)**, con las mismas instrucciones, el mismo `<input type="file">` y el
   mismo reporte que hoy.
3. **Paso 2 — Imágenes**, con las mismas instrucciones, el mismo selector múltiple/ZIP, la misma
   barra de avance y el mismo reporte que hoy.

**Los dos pasos se ven al mismo tiempo**; el modal no es un asistente que esconda el paso 1 hasta
terminarlo. La razón es que el caso más frecuente en el día a día no es la carga inicial completa,
sino volver a subir un puñado de fotos a un catálogo que ya tiene sus artículos desde hace semanas.
Obligar a esa persona a pasar por una pantalla de CSV que no va a usar cambiaría un problema por
otro. Ver los dos pasos numerados enseña el flujo completo sin imponer que se recorra entero.

**Mientras no haya catálogo elegido, los dos pasos están deshabilitados**, con el selector como
única cosa accionable de la pantalla. Es lo que hace evidente, sin texto explicativo, que el
catálogo manda sobre ambos.

**Cambiar de catálogo reinicia los dos pasos**: archivos elegidos, errores y reportes. Un reporte que
sobrevive al cambio de catálogo es peor que no tener reporte, porque afirma algo cierto sobre un
catálogo que ya no es el que está en pantalla.

### La guarda del paso 2

Con un catálogo elegido que **no tiene ningún artículo**, el paso 2 se muestra deshabilitado con el
mensaje: *"Este catálogo todavía no tiene artículos. Empieza por el paso 1."*

Es un bloqueo duro, no una advertencia, y se puede permitir precisamente porque no hay ningún caso
legítimo del otro lado: en un catálogo vacío **toda** imagen fallaría por definición. Un aviso que se
puede ignorar aquí solo serviría para dejar pasar el error que la pantalla acaba de detectar.

**El conteo se vuelve a leer cuando el paso 1 termina bien**, para que quien acaba de importar sus
artículos no se encuentre el paso 2 bloqueado por un dato viejo de hace treinta segundos.

Esto no necesita nada nuevo del backend: `GET /api/v1/catalogos-proveedor` ya devuelve
`articulos_count` en cada catálogo (`CatalogoResource`, `withCount('articulos')`), y
`CatalogoSelect.vue` ya consume ese endpoint — hoy simplemente ignora el dato. El componente
**mantiene su apariencia y su `v-model` intactos** y solo agrega la forma de conocer el catálogo
elegido completo, no únicamente su `id`, para no tocar los demás formularios que ya lo usan.

### El encadenamiento entre pasos

Cuando el paso 1 termina con al menos un artículo importado, su reporte remata con un botón
**"Continuar con las imágenes →"** que lleva la atención al paso 2, con el catálogo ya puesto porque
nunca dejó de estarlo.

El empujón llega en el único instante en que el usuario tiene fresco qué acaba de hacer y sobre qué
catálogo. Un texto de ayuda al abrir el modal se lee antes de que nada de eso signifique algo.

**Es un ofrecimiento, no un paso pendiente**, y el modal no debe dar a entender otra cosa. Hay
artículos que no llevan foto y nunca la van a llevar —"Maquila de sellos" y demás servicios—, y
existen catálogos enteros que son solo eso. Terminar en el paso 1 y cerrar el modal es un final
completo y correcto, no una carga a medias: por eso el botón invita a continuar y no advierte que
falte algo, el paso 2 nunca se marca como incompleto, y **el sistema no lleva en ningún lado la
cuenta de los artículos sin imagen** ([020](020-imagenes-articulos.md), criterio 12 y supuesto 15).

### El reporte de la carga de imágenes cuando nada empareja

Si el paso 2 termina con **cero imágenes asociadas y al menos un error**, la lista de motivos se
encabeza con una línea que interpreta el resultado en vez de solo enumerarlo:

*"Ninguna de las N imágenes encontró artículo. Revisa que el catálogo sea el correcto y que el
nombre de cada archivo coincida con el modelo del artículo."*

El detalle archivo por archivo se conserva sin cambios debajo — es lo que permite corregir un caso
concreto ([020](020-imagenes-articulos.md)). Lo que se agrega es la lectura del conjunto: doscientos
motivos idénticos describen doscientas veces el síntoma y ninguna la causa probable, que casi
siempre es una sola y está en el selector de arriba.

### Copiar el reporte

Cada reporte —el del paso 1 y el del paso 2— lleva un botón **"Copiar"** que pone su contenido
completo en el portapapeles como texto plano.

El reporte es lo único que dice qué quedó pendiente, y hoy **es efímero**: se pierde al cerrar el
modal, al cambiar de catálogo o al volver a correr el paso, y no hay ninguna otra pantalla donde
consultarlo. Quien importó 50 filas y vio fallar 3 tiene que anotarlas a mano antes de cerrar, o
repetir la carga entera para volver a verlas.

Lo que se copia se explica solo, porque va a terminar pegado en una hoja de cálculo o en un mensaje,
lejos de la pantalla que lo produjo: el paso, el catálogo, el conteo y una línea por elemento
rechazado.

```
Importación de artículos — Sellos Colop
47 artículo(s) importado(s), 3 fila(s) con errores.
Fila 12 (Printer 38): el nombre ya ha sido tomado.
...
```

Se copia en vez de descargarse: el destino casi siempre es pegarlo en otro lado, y un archivo en la
carpeta de descargas por cada intento de carga sería un rastro que después hay que limpiar. El
listado en pantalla sigue estando, así que copiar es una ayuda, no el único camino.

### Layout

El modal crece: dos pasos y hasta dos reportes largos en la misma pantalla. Se sostienen las reglas
de `Dialog` con contenido dinámico de [003](003-design-system-tailwind.md) que ya cumplían ambos
modales por separado —contenedores con `min-w-0`, las columnas del CSV en un `<code>` con
`overflow-x-auto`, los `<input type="file">` truncados— y se agrega que **el cuerpo del modal tenga
su propio scroll vertical**, con el selector de catálogo y los botones del pie siempre visibles. Sin
eso, un reporte de cien archivos rechazados empujaría el botón de cerrar fuera de la pantalla.

## Fuera de alcance

- **Cambios en el backend más allá del `modelo` en el error de una fila rechazada.** Ningún
  endpoint, formato de CSV ni regla de emparejamiento se toca. Las dos cargas siguen siendo dos
  peticiones independientes con sus reportes propios.
- **Guardar los reportes**: no hay historial de cargas ni pantalla donde volver a verlos. El botón
  "Copiar" existe justamente para que el usuario se lleve el dato antes de cerrar.
- **Descargar el reporte como archivo.** Se copia al portapapeles y nada más.
- **Reintentar automáticamente** las filas o las imágenes que fallaron. Corregir el CSV y volver a
  correr los dos pasos ya funciona: la importación rechaza los nombres repetidos fila por fila y
  sigue, y volver a subir una imagen que ya estaba la reemplaza por sí misma.
- **Unificar las dos operaciones en una sola petición** o en un solo formulario que acepte CSV e
  imágenes juntos: siguen siendo dos actos, con dos reportes.
- **Mover "Exportar CSV"** al modal nuevo.
- **Que la importación CSV actualice artículos existentes**, que sigue dando de alta solamente
  ([006](006-gestion-articulos.md)/[009](009-catalogos.md)). Es la limitación que hace que corregir
  un `modelo` mal escrito exija el formulario, y esta historia no la cambia.
- **Emparejar imágenes por algo distinto del `modelo`**, o aceptar sufijos en el nombre del archivo
  (`Printer 10 G7 (1).jpg`). Las reglas de nombre de [020](020-imagenes-articulos.md) siguen
  idénticas.
- **Recordar el último catálogo usado** entre aperturas del modal.
- **Distinguir "le falta la foto" de "no lleva foto"** con un campo nuevo en el artículo. Hoy la
  distinción no tendría quién la consuma: nada en el sistema presenta la ausencia de imagen como un
  pendiente. Agregar el campo antes de que exista ese lugar sería pedirle al usuario que capture un
  dato que nadie lee.
- **Un asistente por pasos que oculte el paso 1** hasta completarlo.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Criterios de aceptación

1. `/articulos` muestra un solo botón "Carga masiva" en lugar de "Importar CSV" y "Subir imágenes";
   "Exportar CSV" y "Nuevo artículo" siguen donde estaban.
2. El modal presenta un único selector de catálogo y, debajo, dos pasos numerados y rotulados:
   artículos por CSV primero, imágenes después.
3. Sin catálogo elegido, ambos pasos están deshabilitados.
4. Elegir un catálogo con artículos habilita los dos pasos.
5. Elegir un catálogo sin ningún artículo habilita el paso 1 y deja el paso 2 deshabilitado con el
   mensaje que remite al paso 1.
6. Importar un CSV en un catálogo que estaba vacío habilita el paso 2 sin cerrar ni reabrir el
   modal.
7. Cambiar el catálogo con archivos ya elegidos o reportes en pantalla limpia ambos pasos.
8. Una importación CSV con al menos un artículo importado ofrece continuar hacia el paso 2, y
   hacerlo conserva el catálogo elegido.
9. Cerrar el modal después del paso 1, sin subir ninguna imagen, no produce advertencia alguna ni
   deja nada marcado como pendiente: es el caso de un catálogo de servicios que no llevan foto.
10. Importar un CSV desde el paso 1 da de alta los mismos artículos que antes de esta historia, con
    el mismo `importados` y una entrada de error por cada fila rechazada.
11. Cada fila rechazada del CSV se reporta con el **modelo** que traía esa fila, además del número
    de fila y del motivo; una fila sin modelo cae al nombre, y una sin ninguno de los dos se reporta
    como antes, solo con la fila.
12. Subir imágenes desde el paso 2 produce el mismo resultado y el mismo reporte
    (`asociadas` + `errores` con archivo y motivo) que antes de esta historia, incluidas las tandas
    automáticas de 20 archivos y la barra de avance.
13. Una carga de imágenes que termina con cero asociadas y al menos un error muestra el encabezado
    que señala el catálogo y los nombres de archivo como causa probable, sin perder el detalle por
    archivo.
14. Una carga de imágenes con al menos una asociada no muestra ese encabezado.
15. Cada reporte tiene un botón "Copiar" que deja en el portapapeles el paso, el catálogo, el conteo
    y una línea por elemento rechazado, y avisa que copió.
16. El texto copiado del reporte del CSV incluye el modelo de cada fila rechazada, de modo que se
    entienda pegado en otro lado sin tener el modal a la vista.
17. El modal se muestra completo dentro de los límites del `Dialog`, sin desbordar ni requerir
    scroll horizontal, en escritorio (≥1280px) y en móvil, incluso con las columnas del CSV, nombres
    de archivo largos y un reporte de cien archivos rechazados.
18. Con un reporte largo en pantalla, los botones del pie del modal siguen alcanzables.
19. `CatalogoSelect.vue` sigue viéndose y comportándose igual en los demás formularios que lo usan.
20. Pint corre sin errores sobre el código de backend modificado, ESLint y Prettier sobre el de
    frontend, la suite de Pest sigue pasando y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

1. El orden correcto es CSV primero, imágenes después, y es un requisito del sistema, no una
   preferencia de quien lo usa.
2. La forma de comunicarlo es la propia pantalla, no documentación ni un texto de ayuda que haya que
   leer antes de empezar.
3. Un solo botón de entrada para las dos cargas; "Exportar CSV" no entra.
4. Un solo selector de catálogo para los dos pasos, porque cargar el CSV en un catálogo y las fotos
   en otro nunca es lo que se quiso hacer.
5. Los dos pasos se ven simultáneamente; el paso 1 no se oculta al terminarse ni hay que recorrerlo
   para llegar al 2.
6. Se puede usar solo el paso 2 —sin tocar el CSV— siempre que el catálogo ya tenga artículos: es el
   caso frecuente de agregar fotos que faltaron.
7. Se puede usar solo el paso 1 y cerrar: hay artículos que no llevan foto (servicios como "Maquila
   de sellos") y catálogos que son solo de esos. Terminar sin subir imágenes es un final correcto, y
   el sistema no lo señala como pendiente ni cuenta en ningún lado los artículos sin imagen.
8. El paso 2 se bloquea, no se advierte, cuando el catálogo está vacío.
9. Cambiar de catálogo reinicia archivos y reportes de ambos pasos.
10. El modal no recuerda nada entre aperturas: cada vez se empieza eligiendo catálogo.
11. Los reportes conservan su forma actual (cuántos entraron y el detalle uno por uno de los que
    no); lo único que se agrega es una línea de interpretación cuando ninguna imagen emparejó.
12. Del backend solo cambia el texto con el que se reporta una fila rechazada del CSV, que ahora
    dice también de qué modelo se trata. Ningún endpoint cambia de forma ni de contrato.
13. Una imagen que no encuentra artículo **no queda huérfana en el servidor**: nunca llega a
    escribirse. Lo que queda pendiente es trabajo del usuario, no basura que limpiar.
14. Corregir el CSV y volver a correr los dos pasos completos es un camino válido y no necesita
    herramienta nueva: las filas repetidas se rechazan una por una sin detener la importación, y una
    imagen que ya estaba se reemplaza por sí misma.
15. El reporte se puede copiar al portapapeles, pero no se guarda en ningún lado: no hay historial
    de cargas.
13. **(Adición técnica)** El conteo de artículos por catálogo no requiere un endpoint nuevo: ya
    viaja como `articulos_count` en el listado de catálogos que `CatalogoSelect.vue` consulta. Se
    vuelve a leer tras una importación exitosa para que el dato no quede viejo dentro del mismo
    modal.
14. **(Adición técnica)** `CatalogoSelect.vue` conserva su apariencia y su `v-model` y solo agrega la
    forma de exponer el catálogo elegido completo, para no obligar a revisar los demás formularios
    que ya lo usan.
15. **(Adición técnica)** El cuerpo del modal lleva scroll vertical propio, con selector y pie
    fijos, porque ahora puede contener dos reportes largos a la vez.
16. **(Adición técnica)** El `modelo` de la fila rechazada viaja en su propia clave del objeto de
    error, no concatenado dentro de `motivo`: el motivo sigue siendo la causa, y quién arma la línea
    que se lee es el frontend.

## Estado de implementación

Implementada el 2026-08-13, en dos tandas: primero el modal fusionado y después —tras la prueba con
50 modelos que dejó ver que los dos reportes no se podían cruzar— el modelo en el error del CSV y el
botón "Copiar".

### Modal fusionado (2026-08-13)

- **Archivos modificados**: `frontend/src/views/ArticulosListView.vue` (los dos `Dialog` fusionados
  en uno) y `frontend/src/components/CatalogoSelect.vue`.
- **Cada paso lleva su propio botón de acción** ("Importar artículos" y "Subir imágenes") y el pie
  del modal se queda solo con "Cerrar". La spec no lo fijaba: son dos operaciones independientes
  contra dos endpoints distintos, y un único botón en el pie habría tenido que adivinar cuál de las
  dos ejecutar.
- **El reinicio distingue "cambió el catálogo" de "se releyó el catálogo"**. Releer el conteo tras
  importar vuelve a emitir el mismo catálogo, y reiniciar ahí habría borrado el reporte recién
  producido —justo el que lleva el botón "Continuar con las imágenes"—. `onCatalogoSeleccionado`
  compara el `id` anterior y solo limpia cuando de verdad cambió.
- **Los dos `<input type="file">` llevan `:key` con el catálogo**: poner en `null` la referencia del
  archivo no vacía el control nativo, que seguiría mostrando el nombre del archivo del catálogo
  anterior. Cambiar el `key` lo vuelve a montar vacío.
- **Scroll del cuerpo**: `DialogContent` es `display: grid`, así que se le fijaron las cuatro filas
  (`grid-rows-[auto_auto_minmax(0,1fr)_auto]`) y un `max-h-[90dvh]`; el `minmax(0,1fr)` es lo que
  deja encogerse a la fila del cuerpo en vez de empujar el pie fuera de la pantalla.
- **Verificación**: ESLint y Prettier limpios sobre los dos archivos, `npm run build` compila la SPA
  con `vue-tsc` sin errores, y los 50 tests de Vitest siguen pasando. No se corrió la suite de Pest
  porque en esta tanda no se modificó backend.

### Modelo en el error del CSV y botón "Copiar" (2026-08-13)

- **Archivos modificados**: `app/Http/Controllers/ArticuloController.php` (clave `modelo` en el
  error y el método privado `identidadDeLaFila`), `frontend/src/stores/articulos.ts` (el tipo
  `ImportarCsvReporte`), `frontend/src/views/ArticulosListView.vue` (render del modelo y los dos
  botones "Copiar") y `tests/Feature/ArticulosTest.php`.
- **La clave nueva no rompió ninguna prueba existente**: las de la importación afirman sobre rutas
  concretas del JSON (`errores.0.fila`, `errores.0.motivo`), no sobre la forma completa del objeto.
- **Prueba nueva** con los tres casos de la caída en cascada: fila con modelo, fila sin modelo que
  cae al nombre, y fila sin ninguno de los dos que reporta `null`.
- **El "Copiado" del botón dura dos segundos** y vuelve a "Copiar reporte". Es la única señal de que
  algo pasó: `navigator.clipboard.writeText` no cambia nada en pantalla.
- **Verificación**: Pint pasa, la suite de Pest completa pasa (413 tests), ESLint y Prettier limpios,
  `npm run build` compila con `vue-tsc` y los 50 tests de Vitest siguen pasando.

### Pendiente de verificación visual

**No se pudo verificar visualmente en un navegador real** (misma limitación de entorno que el resto
de las historias) — falta abrir `/articulos` para confirmar el modal fusionado, el bloqueo del paso 2
con un catálogo vacío, el desbloqueo inmediato tras importar, el scroll del cuerpo con un reporte
largo y que el botón "Copiar" deje en el portapapeles lo que se espera.
