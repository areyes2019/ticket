# Spec: Orden de captura y filtros por columna en el listado de artículos

## Historia de usuario

Como usuario que acaba de subir un CSV de doscientos artículos, quiero ver esos artículos juntos y
en el mismo orden que traía mi hoja de cálculo —sin tener que pedirlo, sin ordenar nada a mano— y
quiero poder acotar la tabla por columna —este nombre, este modelo— para revisar lo que cargué sin
recorrer catorce páginas, en una tabla corta que me deje ver de un vistazo lo único que consulto a
diario: qué es, qué modelo es, qué me cuesta y en cuánto lo vendo.

## Objetivo / Alcance

Tres cosas sobre el listado de `/articulos`, que se resuelven juntas porque tocan exactamente el
mismo código —`filtrarBusqueda` y `ordenar` en `ArticuloController`, y el estado de la tabla en
`stores/articulos.ts`— y comparten pantalla:

1. **El listado sale siempre en orden de captura**: el orden en que los artículos entraron al
   sistema, que para una importación es el orden de las filas del archivo. No es una opción que se
   elige; es como sale la tabla.
2. **La tabla muestra cuatro columnas de datos**: Nombre, Modelo, Costo y Precio de venta. Ni
   catálogo ni utilidad, que se consultan en la ficha del artículo.
3. **Un filtro de texto en las cabeceras de Nombre y de Modelo**, siempre visible, que se combina
   con el buscador global y con la ordenación.

**El buscador global de arriba se queda.** Sigue siendo la vía rápida para una palabra suelta y los
filtros por columna se combinan con él, no lo sustituyen.

Todo el filtrado y toda la ordenación ocurren **en el backend**. No hay ningún filtrado en el
navegador.

### El problema que resuelve

#### El orden no es el de la hoja

Al terminar una carga masiva, los artículos aparecen "revueltos" respecto del Excel. No lo están: la
importación crea las filas en el orden del archivo, una por una, y quedan en secuencia. Lo que
reordena es el listado cuando ordena alfabéticamente sobre **todo** el catálogo, no sobre lo recién
importado.

Combinado con la paginación de 15, el efecto es peor que un simple reordenamiento: los doscientos
artículos nuevos quedan repartidos entre las páginas de los que ya estaban, intercalados con ellos.
Revisar lo que acaba de entrar exige recorrer el listado completo.

**El orden de captura se necesita por omisión, no bajo petición.** Un orden que hay que pedir cada
vez que se entra a la pantalla no resuelve "quiero ver lo que subí": obliga a recordar que existe, a
saber cuál es la columna correcta y a hacer un clic antes de poder trabajar. La tabla sale en el
orden en que se cargaron los artículos y ya.

#### Un solo buscador no alcanza

El buscador global es una caja de texto que pega contra nombre, modelo y proveedor a la vez. Sirve
para "búscame el Printer 38" y no sirve para nada más: **no distingue columna**. Un término que
aparece en el nombre de un artículo y en el nombre comercial de un proveedor devuelve las dos cosas
mezcladas, y no hay forma de decir "el que traiga esto en el modelo, sin importar el nombre", que es
justo como se revisa una carga recién subida.

Las columnas de dinero no se acotan, se ordenan: un clic en Costo o en Precio deja juntos los
artículos del tramo que interesa, sin agregar controles a la cabecera.

#### La tabla se volvió larga de leer

Ocho columnas, seis campos de rango y un selector en la fila de cabeceras fue el punto en que la
tabla dejó de leerse de un vistazo. Cada columna se paga en dos monedas: **ancho**, que sale de
Nombre —la columna que de verdad se lee—, y **atención**, porque quien viene a mirar un precio tiene
que descartar tres números antes de llegar al que buscaba.

Catálogo y Utilidad son las dos que menos trabajo hacen ahí. El catálogo rara vez es la pregunta:
quien revisa una carga ya sabe en qué catálogo la subió. Y la utilidad es un número para **decidir**
precios, no para consultarlos: se mira al capturar el artículo, con la cadena de cálculo completa
delante ([011](011-precio-proveedor-utilidad.md)), no leyendo una columna en una lista de mil
renglones.

Los rangos desde–hasta de dinero se van con ellas y por lo mismo: costaban dos campos por columna en
una cabecera ya llena, y la pregunta que contestaban —"¿qué tengo por este precio?"— la contesta
igual de bien ordenar por esa columna.

## Backend (Laravel)

### El orden de captura es el orden base

`ordenar()` **cae a `orderBy('id')` ascendente** cuando no se pide otra cosa, y el desempate de las
ordenaciones que sí se piden también es por `id`.

`ORDENACIONES` contiene las tres columnas de dinero —`costo_total`, `precio_unitario_sin_iva` y
`utilidad`—, de las que la cabecera expone dos: Costo y Precio de venta. `utilidad` se queda porque
es la expresión con la que el servidor entiende ese número y de ella depende su filtro de rango; lo
que no existe es una forma de pedir ese orden desde la pantalla. **No hay una clave `id`**: ordenar por captura no es algo que el cliente pida, sino lo que el servidor hace por
su cuenta cuando nadie pide nada. Dos caminos para llegar al mismo orden serían dos caminos que
mantener de acuerdo.

**El orden alfabético por nombre desaparece del listado**, tanto por omisión como bajo petición. Para
llegar a un artículo por su nombre están el buscador global y el filtro de la columna Nombre, que es
lo que realmente se usa: nadie recorre un catálogo alfabético de mil renglones buscando una "P".

El desempate por `id` en lugar de por `nombre` mantiene una sola idea de orden en toda la tabla: dos
artículos del mismo precio salen en el orden en que se cargaron, igual que salen cuando no hay
ninguna ordenación pedida.

### El id no se expone

`ArticuloResource` sigue enviando el `id` porque es lo que identifica al artículo para editarlo,
borrarlo y marcarlo con la casilla de selección. **Lo que no existe es ninguna forma de verlo ni de
usarlo como criterio**: ni columna en la tabla, ni control de ordenación, ni filtro. Un filtro de
"búscame el artículo número 47" con el número escondido en toda la aplicación no lo puede usar
nadie, porque no hay dónde leer el 47.

### Los filtros

Todos viven en `filtrarBusqueda`, que ya recibe la `Request` y ya es el único punto por el que pasan
tanto el listado como la exportación CSV. Cada filtro es un `when()` más, y **todos se combinan con
Y** entre sí y con el `search` global y el `proveedor_id` que ya existían.

| Parámetro | Columna | Comportamiento |
| --- | --- | --- |
| `filtro_nombre` | `nombre` | `LIKE %valor%` |
| `filtro_modelo` | `modelo` | `LIKE %valor%` |
| `filtro_catalogo_id` | `catalogo_id` | Igualdad exacta contra el catálogo del artículo. |
| `costo_min` / `costo_max` | costo total | Rango cerrado sobre `costo_con_descuento + costo_goma`. |
| `precio_min` / `precio_max` | `precio_unitario_sin_iva` | Rango cerrado. |
| `utilidad_min` / `utilidad_max` | utilidad | Rango cerrado sobre `precio_unitario_sin_iva - (costo_con_descuento + costo_goma)`. |

Los nombres llevan prefijo `filtro_` en las columnas de texto para no chocar con `search`, y las de
rango llevan sufijo `_min`/`_max` porque el par es el filtro: no son dos filtros distintos.

**La pantalla usa dos de los seis.** Desde que la tabla se redujo a cuatro columnas de datos, el
listado solo manda `filtro_nombre` y `filtro_modelo`. Los otros cuatro —el de catálogo y los tres
rangos— siguen aquí, funcionando y con sus pruebas, sin que ninguna pantalla los pida. **Es una
decisión, no un olvido**: están cubiertos por tests que corren solos, no le estorban a quien venga a
editar otra cosa del controlador, y el día que una historia futura pida "los artículos de este
catálogo" o "de tal a tal precio", el trabajo ya está hecho. Lo que sí se borró es su rastro del
lado del navegador, que sí estaba a la vista de cualquiera que abriera el listado.

**Cada extremo de un rango es independiente.** Mandar solo `precio_min` significa "de ahí para
arriba", solo `precio_max` significa "de ahí para abajo", y los dos juntos delimitan. Es lo que hace
que el filtro sea usable escribiendo un solo número, que es como se usa la mitad de las veces.

**Un extremo vacío, no numérico o negativo se ignora en silencio**, igual que se ignora un `sort` que
no se reconoce. Un filtro a medio escribir no debe producir un error en pantalla ni vaciar la tabla:
mientras el usuario teclea "1", "12", "125", los tres estados son legítimos.

**Un rango invertido —mínimo mayor que máximo— devuelve cero resultados** y no se corrige ni se
advierte. Es lo que literalmente se pidió, la tabla vacía lo comunica de inmediato, y adivinar que
el usuario quiso decir lo contrario sería peor que obedecerle.

#### Costo y utilidad no son columnas de la tabla

`costo_total` y `utilidad` **no están persistidos**: se calculan. Sus filtros de rango van con
`whereRaw` sobre **exactamente las mismas expresiones** que ya usa `ORDENACIONES` para ordenarlos
([014](014-costo-elaboracion-goma.md)). Las expresiones se declaran una sola vez y las consumen
ordenación y filtro, para que no puedan divergir: una tabla donde ordenar por costo y filtrar por
costo entendieran cosas distintas sería indefendible.

### La exportación CSV hereda todo

`exportarCsv` ya reusa `filtrarBusqueda` y `ordenar`, así que **exporta exactamente lo que la tabla
está mostrando, en el mismo orden**, sin una línea de código nueva. Es la propiedad que hace que los
filtros valgan más de lo que cuestan: acotar en pantalla y bajarse ese subconjunto ya es un flujo
completo.

El archivo conserva sus ocho columnas de siempre —`nombre`, `modelo`, `clave_prod_serv`,
`clave_unidad`, `objeto_imp`, `precio_proveedor`, `utilidad_porcentaje`, `tamano_goma`— para seguir
siendo reimportable ([011](011-precio-proveedor-utilidad.md)). El `id` nunca ha viajado en él y sigue
sin hacerlo.

## Frontend (Vue 3)

### Seis columnas y ninguna de más

La tabla tiene seis: la casilla de selección, **Nombre**, **Modelo**, **Costo**, **Precio de venta**
y **Acciones**. Cuatro de datos y dos de servicio —marcar para borrar en lote
([021](021-mantenimiento-articulos-catalogos.md)), y editar o eliminar la fila—, que se quedan
porque son lo que se *hace* desde el listado.

**El número interno del artículo no se dibuja en ninguna parte** —ni en el listado, ni en la ficha,
ni en el formulario de edición— y no hay preferencia ni interruptor que lo devuelva. Un dato que no
le sirve al usuario para nada ocupa ancho de pantalla y le hace creer que significa algo.

**Catálogo y utilidad se consultan en el artículo, no en la lista.** Los dos están en el formulario
de edición, y la utilidad ahí sale acompañada de la cadena de cálculo que la explica
([011](011-precio-proveedor-utilidad.md)), que es más de lo que jamás dijo una columna.

**El CSV exportado no los suple**, y conviene tenerlo escrito para no descubrirlo el día que haga
falta: sus ocho columnas son las reimportables, sin catálogo y con `utilidad_porcentaje` —el
porcentaje capturado, vacío cuando el artículo hereda el de su catálogo— en vez de la utilidad en
pesos ([011](011-precio-proveedor-utilidad.md)). **Ordenar todo el catálogo por utilidad deja de ser
posible**, y se acepta: es una pregunta de análisis, no de trabajo diario, y el precio de tenerla
era una columna permanente en la pantalla que más se usa.

**Nombre se queda con todo el ancho que las demás no reclaman.** Es la única columna sin ancho
declarado y la que más se recortaba con elipsis, así que es donde se nota cada columna que sale.

Costo y Precio de venta conservan su control de ordenación en la cabecera; quitarlo devuelve la
tabla al orden de captura. **`ArticuloSort` cubre esas dos y ninguna más**: sin columna de Utilidad
no hay dónde hacer clic para pedir ese orden, y un modo de ordenación que nada en la pantalla puede
activar es de lo que más tiempo hace perder a quien lee el código después.

### La fila de filtros

Debajo de la fila de cabeceras, dentro del mismo `<TableHeader>`, va **una segunda fila con un
campo de texto en Nombre y otro en Modelo**:

```
[ ] │ Nombre     │ Modelo     │ Costo │ Precio de venta │ Acciones
    │ [contiene] │ [contiene] │       │                 │
```

- **Nombre** y **Modelo**: campos de texto, "contiene", sin distinguir mayúsculas.
- **Costo** y **Precio de venta** no llevan filtro: se ordenan, no se acotan.
- Las columnas de selección y de acciones tampoco.

**Los filtros no se ocultan detrás de un menú por columna.** Están siempre visibles en su fila. Un
filtro escondido tras un ícono obliga a abrir menús para saber qué está aplicado, y el principal
riesgo de esta historia es exactamente ese: no entender por qué la tabla muestra menos renglones de
los que debería.

**El estado del listado guarda solo esos dos filtros.** En `stores/articulos.ts` no quedan campos
para el catálogo ni para los rangos, aunque el servidor los siga aceptando: un filtro que ninguna
caja de la pantalla puede llenar, que viaja vacío en cada consulta y que obliga a buscar en qué
pantalla se escribe es exactamente el tipo de resto que hace ilegible un archivo.

### Cuándo se dispara la consulta

- Los dos campos de texto reutilizan el **rebote de 300 ms** que ya tiene el buscador global, para
  no lanzar una petición por tecla. Comparten temporizador con él a propósito: escribir en uno y
  enseguida en el otro es una sola intención y merece una sola consulta.
- **Cualquier cambio de filtro vuelve a la página 1.** Filtrar y quedarse en la página 7 de un
  resultado que ahora tiene 2 páginas deja la tabla vacía por una razón que no es la que el usuario
  cree.
- La selección múltiple se vacía al cambiar las filas, como ya ocurre hoy: el `watch` sobre
  `articulos.items` cubre el caso sin tocarlo ([021](021-mantenimiento-articulos-catalogos.md)).

### Filtros activos y cómo quitarlos

Cuando hay al menos un filtro de columna aplicado, encima de la tabla aparece una línea con el
conteo de resultados y un botón **"Limpiar filtros"** que borra todos los de columna de un golpe.

**El buscador global no se limpia con ese botón** y tiene el suyo propio: son dos cosas que el
usuario puso por separado y en momentos distintos, y borrar de más es tan molesto como no poder
borrar.

### Layout

**La pantalla de artículos usa el ancho de lectura de siempre**, el mismo que el resto del sistema.
El contenedor amplio de [003](003-design-system-tailwind.md) existió aquí por una razón concreta
—ocho columnas más seis campos de rango no cabían de ninguna otra forma— y esa razón se fue con las
columnas. Una excepción de ancho para una sola pantalla se sostiene mientras su contenido la
necesite, no después. El `ancho` `amplio` de `AppLayout` **se queda como capacidad del layout**, sin
pantalla que lo pida por ahora: es tres líneas con valor por omisión, y el próximo listado denso lo
va a querer.

**En escritorio la tabla no lleva barra de desplazamiento horizontal.** Ninguna. Una barra dentro de
la tabla es la peor forma de esconder algo: la columna de acciones queda fuera de la vista y nada en
pantalla dice que está ahí. Es el desborde que hubo que corregir en
[006](006-gestion-articulos.md) el 2026-08-03 y que volvió el 2026-08-14 por la vía del contenedor;
volver al ancho de lectura con cuatro columnas de datos es lo contrario de aquello, pero se verifica
igual.

Para que eso se sostenga sin depender de qué tan largo sea el contenido:

- **La tabla es de ancho fijo** (`table-fixed`): los anchos los mandan las clases de la fila de
  cabeceras y ningún dato puede ensanchar su columna. Sin eso, un nombre de artículo largo empuja al
  resto y el desborde vuelve por donde vino.
- **Nombre es la única columna sin ancho declarado**: se queda con lo que sobre, que es donde mejor
  se aprovecha. Se recorta con elipsis y expone el texto completo en el `title`, igual que Modelo.
- **Los botones de acciones van en su propio contenedor dentro de la celda**, no en la celda misma:
  una celda de tabla en modo flex deja de respetar el ancho de su columna.

En móvil la tabla se desplaza dentro de su tarjeta si hace falta, y **el `<body>` no hace scroll
horizontal nunca**: lo que se desplaza es la tabla, jamás la página.

### El orden alcanza a todas las pantallas que leen la lista

El orden de captura no es una preferencia del listado de artículos: es el orden en que el sistema
entrega los artículos. Alcanza también al buscador de artículos de facturas, cotizaciones y órdenes
de compra ([007](007-facturacion.md), [008](008-cotizaciones.md), [012](012-ordenes-compra.md)) y al
catálogo del mostrador ([031](031-mostrador-consulta.md)), que consumen el mismo endpoint. No hay
nada que hacer para conseguirlo —heredan el orden por omisión— y tampoco hay nada que hacer para
evitarlo: un mismo catálogo que saliera en dos órdenes distintos según la pantalla sería más difícil
de explicar que cualquiera de los dos órdenes por separado.

## Fuera de alcance

- **Un orden alfabético opcional.** No queda ninguno: ni por omisión, ni como columna ordenable, ni
  como opción en un menú.
- **Mostrar el id en cualquier otro lugar** de la aplicación, o una preferencia para devolver la
  columna. Se va de la pantalla y no vuelve.
- **Invertir el orden de captura** para ver primero lo más reciente. La tabla sale de viejo a nuevo y
  no hay control para voltearla.
- **Llevar al usuario a la página del artículo que acaba de crear.** Un artículo nuevo cae al final
  del listado, en la última página, y encontrarlo es trabajo del buscador.
- **Filtros en otros listados** (clientes, proveedores, catálogos, facturas, cotizaciones, órdenes de
  compra). Esta historia toca únicamente `/articulos`. Si el patrón demuestra servir, extenderlo es
  otra historia con su propio spec.
- **Guardar o recordar los filtros** entre visitas a la pantalla, en `localStorage` o en la URL.
  Cada entrada al listado empieza sin filtros.
- **Filtros combinables con O**, negaciones ("que no contenga"), o comodines dentro del texto. Todo
  se combina con Y y el texto es siempre "contiene".
- **Rangos de fecha** por `created_at` o `updated_at`. El orden de captura ya cubre el caso real
  —"lo que acabo de subir"— sin agregar dos campos de calendario a la cabecera.
- **Devolver al listado las columnas de Catálogo o de Utilidad**, ni de fijo ni tras un interruptor.
  Un selector de columnas visibles es una preferencia más que mantener y una tabla que ya no se
  puede describir de memoria.
- **Ordenar el listado por utilidad.** Sin columna no hay control, y no se agrega ninguna otra vía.
- **Filtros de rango desde–hasta en la pantalla.** Las columnas de dinero se ordenan; no se acotan.
- **Agregar el catálogo o la utilidad en pesos al CSV exportado.** El archivo conserva sus ocho
  columnas reimportables ([011](011-precio-proveedor-utilidad.md)); separar el formato de
  exportación del de importación es otra historia con su propio spec.
- **Filtrar desde la pantalla por cualquier dato sin columna propia** —`clave_prod_serv`,
  `clave_unidad`, `objeto_imp`, `tamano_goma`, proveedor, catálogo o utilidad—. Sin columna visible
  no hay dónde poner el filtro.
- **Filtrar por precio con IVA.** La columna que la tabla muestra es la de sin IVA.
- **Seleccionar todos los resultados del filtro** para el borrado en lote. La selección sigue siendo
  de la página visible ([021](021-mantenimiento-articulos-catalogos.md)).
- **Un buscador global que entienda sintaxis** tipo `precio:>500`. Se filtra con controles, no con
  lenguaje.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Criterios de aceptación

1. Al entrar a `/articulos` sin tocar nada, los artículos salen en el orden en que fueron creados,
   del más viejo al más nuevo, de modo que los de una importación CSV aparecen consecutivos y en el
   orden de las filas del archivo.
2. El listado no muestra en ninguna parte el número interno del artículo: no hay columna de id, ni
   control para ordenar por él, ni casilla para filtrarlo.
3. La tabla tiene seis columnas: selección, Nombre, Modelo, Costo, Precio de venta y Acciones. No
   hay columna de Catálogo ni de Utilidad, ni interruptor que las devuelva.
4. Costo y Precio de venta son ordenables y alternan ascendente → descendente → sin ordenar; al
   quedar sin ordenar, la tabla vuelve al orden de captura.
5. No existe forma de ordenar el listado por utilidad ni por nombre: ni columna, ni control, ni
   opción en un menú.
6. Con una ordenación por Costo o por Precio de venta, los artículos con el mismo valor salen entre
   sí en orden de captura.
7. Debajo de las cabeceras hay una fila de filtros con un campo de texto en Nombre y otro en Modelo.
   Ninguna otra columna lleva control: ni las de dinero, ni la de selección, ni la de acciones.
8. Escribir en el filtro de Nombre acota el listado a los artículos cuyo nombre contiene ese texto,
   sin distinguir mayúsculas ni exigir que sea el principio del nombre. Lo mismo para Modelo.
9. Los dos filtros se combinan con Y entre sí y con el buscador global, que sigue en su lugar y
   sigue pegando contra nombre, modelo y proveedor.
10. Los filtros se combinan con la ordenación y con el orden de captura: filtrar conserva el orden, y
    ordenar conserva el filtro.
11. Cambiar cualquier filtro devuelve la paginación a la página 1.
12. Escribir en un filtro no lanza una petición por tecla: la consulta sale tras una pausa, y
    escribir en Nombre y enseguida en Modelo produce una sola consulta, no dos.
13. Con al menos un filtro de columna aplicado aparece un botón "Limpiar filtros" que los borra
    todos; el buscador global conserva su contenido.
14. Exportar CSV descarga exactamente los artículos que la tabla está mostrando, en el mismo orden,
    con las ocho columnas reimportables de siempre y sin ninguna columna de id.
15. La selección múltiple y el borrado en lote siguen funcionando sobre la página visible del
    resultado filtrado, y la selección se vacía cuando el filtro cambia las filas.
16. Editar un artículo no cambia su posición en el listado.
17. El buscador de artículos de facturas, cotizaciones y órdenes de compra y el catálogo del
    mostrador entregan los artículos en orden de captura, y `proveedor_id` sigue acotando el selector
    de artículos de Orden de compra ([012](012-ordenes-compra.md)).
18. El servidor sigue atendiendo `filtro_catalogo_id`, `costo_min`/`costo_max`,
    `precio_min`/`precio_max` y `utilidad_min`/`utilidad_max` aunque ninguna pantalla los mande, con
    su comportamiento y sus pruebas intactos: rango con los dos extremos acota por ambos lados, un
    extremo solo acota por ese lado, un rango invertido devuelve cero resultados, y un extremo vacío,
    no numérico o negativo se ignora en silencio.
19. El navegador no manda esos parámetros: una petición del listado con el buscador global y los dos
    filtros llenos lleva `search`, `filtro_nombre` y `filtro_modelo`, y nada más.
20. `/articulos` se ve con el mismo ancho de página que el resto de las pantallas del sistema.
21. En escritorio (≥1280px) la tabla y su fila de filtros se ven completas **sin barra de
    desplazamiento horizontal**, con los dos botones de acciones enteros dentro de la vista.
22. Un artículo con nombre largo (más de 60 caracteres) se recorta con elipsis y no ensancha su
    columna ni empuja a las demás; el nombre completo se ve en el `title`.
23. En móvil la página no desborda horizontalmente: si algo se desplaza es la tabla dentro de su
    tarjeta, nunca el `<body>`.
24. Pint corre sin errores sobre el código de backend modificado, ESLint y Prettier sobre el de
    frontend, la suite de Pest sigue pasando y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

1. Los artículos ya se crean en el orden del archivo importado; lo que hay que arreglar es la forma
   de ver ese orden, no la importación.
2. El orden de captura es el orden base del listado, no una opción que se pide.
3. "Orden de captura" es de lo más viejo a lo más nuevo: lo primero que se subió sale primero.
4. El orden alfabético por nombre desaparece: ni por omisión, ni como columna ordenable.
5. El número interno del artículo deja de mostrarse por completo, y no queda ninguna forma de volver
   a verlo.
6. Al desaparecer de la vista, desaparecen también su control de ordenación y su filtro exacto.
7. Costo y Precio de venta son ordenables; quitar la ordenación devuelve al orden de captura. La
   utilidad no lo es: se quedó sin columna, y con ella sin control donde hacer clic.
8. Los empates de una ordenación por dinero se desempatan por orden de captura.
9. El ancho que liberan las columnas retiradas se lo queda Nombre.
10. El orden alcanza a todas las pantallas que leen la lista de artículos, no solo a `/articulos`.
11. Ningún otro listado del sistema cambia de orden.
12. Un artículo nuevo cae al final del listado y nada lleva al usuario hasta ahí.
13. Editar un artículo no lo mueve de su posición.
14. El buscador global se queda y convive con los filtros de columna; no lo sustituyen.
15. Todos los filtros se combinan con Y, entre ellos y con el buscador global.
16. Los filtros de texto son "contiene", sin distinguir mayúsculas.
17. La pantalla no filtra por catálogo: quien revisa una carga ya sabe en qué catálogo la subió.
18. Las columnas de dinero no se filtran desde la pantalla, se ordenan. La pregunta "¿qué tengo por
    este precio?" la contesta un clic en la cabecera sin costar dos campos de texto por columna.
19. El servidor sí conserva los rangos desde–hasta, con cada extremo opcional por separado.
20. Un filtro mal escrito o a medio escribir se ignora en silencio; nunca produce error en pantalla
    ni vacía la tabla por un motivo que el usuario no pidió.
21. Un rango invertido devuelve cero resultados y no se corrige solo.
22. Los filtros no se recuerdan entre visitas ni viajan en la URL: cada entrada empieza limpia.
23. "Limpiar filtros" borra los de columna y no el buscador global.
24. Los filtros están siempre visibles en su fila, no escondidos tras un menú por columna.
25. La exportación CSV respeta filtros y orden, porque exportar lo que se está viendo es el
    comportamiento esperado, y conserva sus ocho columnas reimportables: no gana el catálogo ni la
    utilidad en pesos por el hecho de que hayan dejado de tener columna en pantalla.
26. La selección múltiple sigue siendo de la página visible, también con filtros aplicados.
27. Solo se filtra por las columnas que la tabla muestra.
28. Esta historia toca únicamente el listado de artículos; los demás listados del sistema quedan
    como están.
29. **(Adición técnica)** El número interno sigue viajando del servidor al navegador, porque es lo
    que identifica al artículo para editarlo, borrarlo y marcarlo. Lo que se quita es su
    presentación, no el dato.
30. **(Adición técnica)** El servidor deja de aceptar "ordéname por id" como petición: el orden de
    captura es lo que hace cuando no se le pide nada. Un solo camino en vez de dos que mantener de
    acuerdo.
31. **(Adición técnica)** El filtro por id exacto se quita también del servidor: con el número
    escondido en toda la aplicación, nadie puede saber cuál escribir.
32. **(Adición técnica)** Todo el filtrado y la ordenación ocurren en el servidor, nunca en el
    navegador: la tabla está paginada de 15 en 15, y un filtro que corriera sobre los datos ya
    descargados solo acotaría los quince renglones a la vista.
33. **(Adición técnica)** Costo total y utilidad no son columnas de la base de datos, sino
    expresiones calculadas. Sus filtros usan las mismas expresiones que ya usa la ordenación
    ([014](014-costo-elaboracion-goma.md)), declaradas en un solo lugar para que filtrar y ordenar
    no puedan entender cosas distintas.
34. **(Adición técnica)** Los parámetros de filtro son aditivos: una petición sin ellos responde el
    listado completo en orden de captura, así que lo que hoy consume el endpoint —incluido el
    `proveedor_id` de Orden de compra— sigue funcionando.
35. **(Adición técnica)** La exportación CSV no necesita código nuevo: ya reusa el mismo filtrado y
    la misma ordenación que el listado.
36. **(Adición técnica)** Los dos campos de filtro comparten el rebote de 300 ms del buscador
    global, y el mismo temporizador que él: escribir en dos de ellos seguidos es una sola intención
    y sale como una sola consulta.
37. **(Adición técnica)** El listado de artículos vuelve al ancho de lectura del resto del sistema
    al quedarse en cuatro columnas de datos. La prop `ancho` de `AppLayout`
    ([003](003-design-system-tailwind.md)) se queda con `normal` por omisión y sin pantalla que pida
    `amplio`: es una capacidad del layout, no un resto de esta pantalla.
38. **(Adición técnica)** La tabla es de ancho fijo y sus columnas de texto se recortan con elipsis.
    Es lo que impide que el ancho de la tabla dependa de qué tan largo sea el nombre de un artículo,
    y por lo tanto lo que hace que "sin barra horizontal" sea una propiedad y no una casualidad del
    catálogo que se tenga cargado.
39. **(Adición técnica)** Cuando la fila de filtros tuvo campos numéricos, fueron de texto y no
    `<input type="number">`: las flechitas del control nativo no caben en una celda angosta. La
    regla quedó en [003](003-design-system-tailwind.md) para la siguiente tabla que los necesite.
40. **(Adición técnica)** El estado del listado en el navegador guarda únicamente los filtros que la
    pantalla puede llenar. Un campo que nadie puede escribir, que viaja vacío en cada consulta y que
    obliga a buscar en qué pantalla se llena cuesta más de lo que ahorra.
41. **(Adición técnica)** El servidor conserva los cuatro filtros que la pantalla dejó de usar, con
    sus pruebas. Es la decisión opuesta a la del navegador y por un motivo simétrico: ahí no están a
    la vista de nadie, corren solos y ahorran el trabajo entero si vuelven a hacer falta.
42. **(Adición técnica)** `ArticuloSort` pierde `utilidad` en el frontend aunque `ORDENACIONES` la
    conserve en el backend. El tipo del navegador describe lo que la pantalla puede pedir; la
    constante del servidor, lo que el servidor sabe hacer.

## Estado de implementación

Los filtros por columna se implementaron el 2026-08-14; el orden de captura como orden base, sin
columna de id, el 2026-08-18.

- **Archivos con los filtros**: `app/Http/Controllers/ArticuloController.php` (las constantes
  `RANGOS` y `FILTROS_TEXTO`, y los métodos privados `filtrarPorColumna` y `numeroDeFiltro`),
  `frontend/src/stores/articulos.ts`, `frontend/src/views/ArticulosListView.vue`,
  `frontend/src/components/ColumnaOrdenable.vue` y `tests/Feature/ArticulosTest.php`.
- **El `CAST` del extremo de rango es obligatorio, no adorno.** Sin él los filtros de costo y de
  utilidad quedaban silenciosamente rotos: **todo mínimo no filtraba nada y todo máximo dejaba pasar
  el catálogo entero**. PDO no tiene parámetro de punto flotante, así que el extremo viaja al motor
  como texto; una suma como `costo_con_descuento + costo_goma` no arrastra la afinidad numérica de
  sus columnas, y comparado contra texto SQLite da el número por menor siempre. Se comparaba número
  contra cadena, no número contra número. El filtro de precio no lo destapó porque ahí la expresión
  es una columna sola, que sí conserva su afinidad — de no haber probado las tres columnas, el error
  habría llegado a producción por la mitad de los filtros.
- **Las tres columnas de dinero pasan por el mismo `whereRaw`**, incluida `precio_unitario_sin_iva`
  que sí es columna real: el bucle sobre `RANGOS` las trata igual y así comparten el `CAST`.
- **La tabla vacía distingue "no hay artículos" de "ningún artículo coincide"**: con filtros puestos,
  el mensaje de siempre —"No hay artículos registrados todavía"— afirmaría algo falso justo cuando el
  usuario más necesita entender por qué no ve nada.

### El ancho de la tabla (2026-08-14, tras la verificación visual del usuario)

La primera implementación dejó la tabla en el contenedor de lectura de siempre, `max-w-5xl`, y
confió en el `overflow-x-auto` de la tarjeta para lo que no cupiera. En pantalla eso resultó ser
inaceptable: las columnas más la fila de filtros no caben ni de lejos en ese ancho, así que la tabla
apareció comprimida, **con los botones de acciones fuera de la vista tras una barra de
desplazamiento** y con los campos de rango tan angostos que no se podía escribir un importe. Es el
mismo desborde que ya se había corregido en [006](006-gestion-articulos.md) el 2026-08-03,
reaparecido por la vía del ancho del contenedor en vez de por la del contenido de una celda.

- **`AppLayout` ganó la prop `ancho`** con `normal` por omisión, de modo que ninguna otra pantalla
  cambia. La clase se aplica a la barra superior, al menú móvil y al `<main>` a la vez.
- **La tabla pasó a `table-fixed`** con anchos declarados por columna y elipsis en Nombre, Modelo y
  Catálogo. Sin ancho fijo, un nombre largo vuelve a ensanchar la tabla y la barra regresa.
- **Los botones de acciones se envolvieron en un `div`**. Estaban en un `td` con `display:flex`, que
  saca a la celda del algoritmo de la tabla: dejaba de respetar el ancho de su columna, que es
  precisamente lo que los empujaba fuera.
- **Los campos numéricos de los filtros dejaron de ser `type="number"`**: las flechitas del control
  nativo se comían un tercio de cada celda. La tolerancia a lo que no sea un número ya estaba en el
  servidor, así que no se pierde nada.

### El orden de captura como orden base (2026-08-18)

La columna `id` ordenable resolvió el problema a medias: el orden de captura existía, pero había que
pedirlo con un clic cada vez que se entraba a la pantalla, y el número que había que ordenar no le
dice nada al usuario. El orden pasó a ser el que sale sin pedir nada y la columna desapareció.

- **Archivos modificados**: `app/Http/Controllers/ArticuloController.php`,
  `tests/Feature/ArticulosTest.php`, `frontend/src/stores/articulos.ts`,
  `frontend/src/views/ArticulosListView.vue` y `frontend/src/components/ColumnaOrdenable.vue`.
- **`ordenar()` cambió en dos líneas**: la caída pasó de `orderBy('nombre')` a `orderBy('id')`, y el
  desempate de las ordenaciones de dinero, de `nombre` a `id`. La clave `'id' => 'id'` salió de
  `ORDENACIONES`, así que `?sort=id` dejó de reconocerse y degrada al mismo orden de captura en vez
  de invertirlo: se prueba con un dataset que incluye `sort=id&direction=desc` y `sort=nombre`.
- **El filtro `filtro_id` se retiró del controlador**, y su caso pasó del dataset de "filtros
  inválidos" al de "parámetros que se ignoran".
- **Media docena de aserciones de orden de otros tests cambiaron de alfabético a captura.** No son
  ajustes cosméticos: son la prueba de que el orden nuevo alcanza al filtro por catálogo, a los
  rangos, a la exportación CSV y al `proveedor_id` de Orden de compra, que comparten `ordenar()`.
- **`ColumnaOrdenable` se quedó** aunque desapareció el motivo por el que se extrajo —el control ya
  no se dibuja en dos puntos del `<thead>`—: sigue concentrando el estado del control (qué flecha va,
  qué dice `aria-sort`) fuera de una cabecera que ya carga con su fila de filtros. Su comentario se
  corrigió para que no siga justificándose con una columna que ya no existe.
- **Verificación**: Pint pasa, la suite de Pest completa pasa (571 tests, 824 885 aserciones), ESLint
  y Prettier limpios, `npm run build` compila con `vue-tsc` y los 89 tests de Vitest siguen pasando.

### La tabla reducida a cuatro columnas de datos (2026-08-19)

Ocho columnas y una cabecera con seis campos de rango y un selector resultaron demasiado para la
pantalla que más se usa. La tabla quedó en Nombre, Modelo, Costo y Precio de venta, con filtro de
texto solo en las dos primeras.

- **Archivos modificados**: `frontend/src/views/ArticulosListView.vue` y
  `frontend/src/stores/articulos.ts`. **Nada del backend**, a propósito.
- **El servidor no se tocó.** Sigue aceptando `filtro_catalogo_id` y los tres rangos, con sus
  pruebas, aunque ninguna pantalla los mande. La asimetría con el frontend —donde sí se borraron— es
  deliberada y está razonada arriba, en "Los filtros": en el controlador no estorban y ahorran el
  trabajo entero si vuelven a hacer falta; en el navegador estaban en el archivo que hay que abrir
  para tocar la tabla.
- **`ArticuloFiltros` pasó de nueve campos a dos**, y con ellos se fueron `paramsListado`,
  `hayFiltros` —que ya no necesita tratar aparte el catálogo, porque todos los filtros son cadenas—
  y la carga de catálogos que la vista hacía en `onMounted` solo para llenar el selector.
- **`columnasNumericas` pasó de tres entradas a dos y perdió sus claves `min`/`max`.** Sigue
  dibujando su celda en las dos filas de la cabecera —vacía en la de filtros— para que no puedan
  quedar con distinto número de celdas.
- **La pantalla volvió al ancho de lectura**: se le quitó el `ancho="amplio"` a `AppLayout`. La prop
  se quedó en el layout, con `normal` por omisión y sin nadie que pida `amplio`.
- **Verificación**: `npm run build` compila con `vue-tsc`, ESLint y Prettier limpios, 92 tests de
  Vitest pasando, y la suite de Pest completa sigue pasando sin cambios (571 tests, 824 885
  aserciones) — que es justamente la prueba de que el backend quedó intacto.

### Pendiente de verificación visual

**No se pudo verificar visualmente en un navegador real** (misma limitación de entorno que el resto
de las historias; el proyecto no tiene herramienta de automatización de navegador instalada) — falta
abrir `/articulos` y confirmar que las seis columnas se ven completas en el ancho de lectura, que no
queda barra de desplazamiento horizontal, que los dos botones de acciones se ven enteros y que un
nombre largo se recorta con elipsis en vez de empujar a las demás columnas.
