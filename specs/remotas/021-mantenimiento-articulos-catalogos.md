# Spec: Mantenimiento masivo de artículos y catálogos

## Historia de usuario

Como usuario registrado, quiero eliminar artículos en lote, eliminar catálogos completos con todo su
contenido, y aumentar el costo de los artículos de un catálogo por porcentaje, para poder mantener
la lista de precios cuando un proveedor cambia sus condiciones sin tener que tocar los artículos uno
por uno.

El caso de uso que origina la tercera parte es literal: *"vamos a aumentar el costo un 5% sobre el
catálogo XX"*.

## Objetivo / Alcance

Tres operaciones masivas sobre la estructura ya existente de `Articulo` y `Catalogo`
([006-gestion-articulos.md](006-gestion-articulos.md), [009-catalogos.md](009-catalogos.md),
[011-precio-proveedor-utilidad.md](011-precio-proveedor-utilidad.md)):

1. **Borrado en lote de artículos** desde el listado `/articulos`, con selección por casillas.
2. **Borrado de un catálogo completo**, arrastrando a sus artículos. Sustituye la restricción actual
   que lo impide.
3. **Aumento porcentual del costo** de todos los artículos de un catálogo, con vista previa.

No se agrega ningún campo de datos nuevo, ni cambia la cadena de cálculo de precios de
[011](011-precio-proveedor-utilidad.md) y [014](014-costo-elaboracion-goma.md): la tercera operación
mueve el punto de partida de esa cadena y la vuelve a recorrer con la misma calculadora.

## La cadena de precios, y qué significa "aumentar el costo"

La cadena vigente ([011](011-precio-proveedor-utilidad.md),
[014](014-costo-elaboracion-goma.md), `PrecioArticuloCalculator`):

```
precio_proveedor          (capturado)              $200.00
  ↓ × (1 − descuento / 100)                        descuento del catálogo
costo_con_descuento       (calculado, persistido)  $180.00
  ↓ + costo_goma                                   goma propia, no del proveedor
costo_total               (calculado al leer)      $190.00
  ↓ × (1 + utilidad / 100)                         markup
precio_unitario_sin_iva   (calculado, persistido)  $237.50
```

**"Aumentar el costo un 5%" es subir un 5% el `precio_proveedor`**, que es el único eslabón
capturado a mano, y volver a recorrer la cadena entera desde ahí. Es la lectura correcta del caso de
uso: cuando el proveedor sube su lista, lo que subió es lo que él te cobra, y el descuento negociado
y tu margen siguen aplicándose igual sobre la base nueva.

Las otras dos lecturas posibles se descartan explícitamente:

- **Subir `costo_con_descuento` directamente** dejaría el `precio_proveedor` guardado mintiendo
  sobre la lista del proveedor, y la primera vez que alguien editara ese artículo o cambiara el
  descuento del catálogo, el recálculo pisaría el aumento y lo devolvería al costo viejo. El aumento
  duraría hasta el siguiente toque, en silencio.
- **Subir el descuento a la baja** (bajar el descuento para que suba el costo) mezcla dos conceptos
  distintos y no permite un aumento arbitrario: con 0% de descuento no hay margen de maniobra.

Consecuencias, todas deliberadas:

- **El margen se conserva proporcionalmente.** Como el markup es un porcentaje sobre el costo, subir
  el costo 5% sube el precio de venta ~5% y la utilidad en pesos ~5%; el *porcentaje* de utilidad no
  se mueve. El usuario no queda vendiendo con menos margen del que tenía.
- **El descuento del catálogo y los porcentajes de utilidad no se tocan.** Los artículos con
  porcentaje propio conservan el suyo; los que heredan el del catálogo siguen heredándolo.
- **El `costo_goma` no se aumenta.** Es un costo de elaboración propio
  ([014](014-costo-elaboracion-goma.md)), no de la lista del proveedor. Sube por su propio camino,
  el pizarrón de configuración.

## Backend (Laravel)

### Borrado en lote de artículos

**Una sola petición con la lista de identificadores**, no una por artículo. Con N peticiones, una
conexión que se cae a la mitad deja un subconjunto arbitrario borrado y sin forma de saber cuál; con
una, o entró completa o no entró.

- `POST /api/v1/articulos/eliminar-lote`, con `{ "ids": [1, 2, 3] }`. Se usa `POST` y no `DELETE`
  con cuerpo porque el cuerpo de un `DELETE` no está garantizado de punta a punta (proxies y
  servidores intermedios pueden descartarlo), y perder los identificadores por el camino aquí
  significaría no borrar nada o —peor— fallar de forma difícil de leer.
- Se registra **antes** del `apiResource('articulos')`, por la misma convención que
  `articulos/exportar-csv` en [`routes/api.php`](../backend/routes/api.php): las rutas específicas
  primero, sin excepciones que haya que recordar.
- **Todo dentro de una transacción.** Es lo que hace real el "todo o nada": si algún artículo no se
  puede eliminar, la base de datos deshace lo que llevaba y queda exactamente como estaba.
- **Todos los identificadores deben pertenecer al usuario autenticado.** Si alguno no existe o no es
  suyo, se rechaza la petición completa con `422` y no se borra nada. No se borra "lo que sí se
  pudo": un borrado parcial silencioso es justo lo que la transacción está evitando.
- **Borrado lógico** (`SoftDeletes`), igual que el individual de `ArticuloController::destroy`.
- **No se comprueba si el artículo aparece en cotizaciones, facturas u órdenes de compra.** Esos
  documentos copian los datos del artículo al emitirse y no dependen de que siga existiendo; es el
  mismo criterio que ya rige el borrado individual, que tampoco lo comprueba.
- **Las imágenes no se borran del disco**, igual que en el borrado individual
  ([020-imagenes-articulos.md](020-imagenes-articulos.md)).
- Respuesta: `{ "eliminados": 12 }`.

### Borrado de un catálogo completo

**Se retira la restricción actual.** Hoy `CatalogoProveedorController::destroy` responde `409` con
"No se puede eliminar: el catálogo tiene artículos asociados", lo que deja al usuario sin salida:
para borrar un catálogo de 800 artículos tendría que vaciarlo a mano primero. Pasa a eliminarse el
catálogo **junto con todos sus artículos**.

- **Borrado lógico en cascada**, catálogo y artículos.
- **Los artículos se marcan de una sola instrucción** (`$catalogo->articulos()->delete()`, que con
  `SoftDeletes` es un único `UPDATE ... SET deleted_at`), no recorriéndolos uno por uno. Con 800
  artículos la diferencia es entre milisegundos y una petición que se agota.

  El matiz de hacerlo así: un borrado masivo **no dispara los eventos de modelo** de cada artículo.
  Hoy no hay ningún oyente enganchado al borrado de `Articulo` —el único `booted()` en juego es el
  de `Catalogo`, y escucha `updated`, no `deleted`—, así que no se pierde nada. Queda anotado aquí
  para quien agregue uno en el futuro: este camino lo esquiva.
- **Ambas cosas en la misma transacción.** O se van el catálogo y su contenido, o no se va ninguno.
- **El proveedor no se toca**: sigue existiendo con sus demás catálogos.
- **Las imágenes de los artículos arrastrados se quedan en disco.** El borrado es lógico; si soporte
  técnico restaura el catálogo, los artículos deben volver con su foto. Borrarlas ahorraría unos
  megabytes a cambio de que la recuperación fuera incompleta y sin remedio, porque las fotos no
  están en git ni en ningún respaldo ([020](020-imagenes-articulos.md)).
- **Un catálogo a la vez**; no hay borrado en lote de catálogos.
- `CatalogoResource` agrega **`articulos_count`** (entero), cargado con `withCount('articulos')` en
  el listado y en el detalle. Es lo que permite que la confirmación diga cuántos artículos se lleva
  por delante antes de que el usuario decida.

### Aumento porcentual del costo

- `POST /api/v1/catalogos-proveedor/{catalogo}/aumentar-costos`, con
  `{ "aumento_porcentaje": 5 }`.
- **Recorre los artículos del catálogo y usa `PrecioArticuloCalculator`**, la misma pieza que usan
  el alta, la edición, la importación CSV y el recálculo por cambio de descuento de
  `Catalogo::booted()`. **No se hace con un `UPDATE ... SET precio_proveedor = precio_proveedor *
  1.05` en SQL**, que sería más corto de escribir: el techo a 2 decimales del markup (`CEIL`) no es
  portable entre MySQL y SQLite —la misma razón que ya obligó a resolver en PHP el recálculo por
  descuento— y, sobre todo, un precio calculado por un camino distinto al de todos los demás
  produce diferencias de un centavo según por dónde se entró a cambiarlo.
- Para cada artículo:
  1. `precio_proveedor` nuevo = `redondeo2(precio_proveedor × (1 + aumento / 100))`.
  2. La cadena completa se recalcula con `PrecioArticuloCalculator::calcularCadena()`, pasando el
     descuento del catálogo, la utilidad efectiva del artículo (la propia si la tiene, si no la del
     catálogo) y su `costo_goma` sin modificar.
  3. Se persisten `precio_proveedor`, `costo_con_descuento` y `precio_unitario_sin_iva`.
- **Todo en una transacción**, por lo mismo que las otras dos operaciones.
- Respuesta: `{ "actualizados": 240 }`.

#### Redondeo

**Redondeo matemático a centavos** (`PrecioArticuloCalculator::redondeo2`), el mismo que ya usa
`costo_con_descuento`. $199.99 + 5% = $209.9895 → **$209.99**.

Dos consecuencias que se documentan porque van a aparecer y sin esta nota parecerían defectos:

- **Aplicar 5% y luego 5% no da lo mismo que aplicar 10.25% de una vez.** Cada paso redondea a
  centavos. Las diferencias son de uno o dos centavos por artículo.
- **Un aumento pequeño sobre un precio pequeño puede no mover nada.** 0.4% sobre $1.00 son cuatro
  décimas de centavo, que redondean a $1.00: el artículo queda igual. (Medio centavo justo sí sube:
  0.5% sobre $1.00 da $1.005 y `round` lo lleva a $1.01.) No es un error, y la vista previa lo
  muestra tal cual —precio actual y nuevo idénticos— en lugar de esconderlo.

#### Validación del porcentaje

`aumento_porcentaje`: requerido, numérico, **mayor que 0**, máximo **100**, hasta **2 decimales**
(`gt:0`, `lte:100`, `decimal:0,2`).

- **Dos decimales** porque los proveedores no suben en números redondos (7.25% es un aumento real),
  y es el mismo límite que ya tienen `descuento` y `utilidad_porcentaje`.
- **Mayor que 0**: esta operación solo sube. Bajar costos se hace por los caminos que ya existen
  (editar el artículo, o el descuento del catálogo).
- **Tope de 100%**: duplicar el costo de un catálogo entero ya es un movimiento extremo; el tope
  está para que un dedazo tipo "500" no se aplique. Si alguna vez hiciera falta más, se aplica en
  dos pasos.

### Vista previa

El endpoint `POST /api/v1/catalogos-proveedor/{catalogo}/impacto-precios` **ya existe** desde
[011](011-precio-proveedor-utilidad.md): recibe un descuento y una utilidad hipotéticos y devuelve,
por artículo, el costo y el precio que tendría, sin persistir nada.

**Lo que no existe es la pantalla**: hoy el frontend nunca lo llama, y editar el descuento de un
catálogo guarda a ciegas. La vista previa que sí está construida es la de
[`ConfiguracionView.vue`](../frontend/src/views/ConfiguracionView.vue) (costo de goma,
[014](014-costo-elaboracion-goma.md)), sobre otro endpoint.

Así que "reutilizar la vista previa" significa aquí: **extender el endpoint existente y construir la
pantalla que le faltaba**, de modo que sirva a las dos cosas a la vez.

- `impacto-precios` agrega un parámetro **`aumento_porcentaje`** opcional (mismas reglas que arriba;
  ausente o `null` = sin aumento, comportamiento idéntico al de hoy).
- `descuento` y `utilidad_porcentaje` pasan a ser opcionales: si no vienen, se usan los del catálogo
  guardado. Sin esto, pedir la vista previa de solo un aumento obligaría al cliente a reenviar
  valores que no está cambiando.
- Cada artículo del resultado incluye ahora también **`precio_proveedor`** (el nuevo, ya
  aumentado y redondeado), además de `costo_con_descuento`, `costo_total` y
  `precio_unitario_sin_iva` que ya devolvía.
- **Si en la misma pantalla se mueven el aumento y el descuento a la vez, la vista previa muestra el
  resultado de aplicar ambos**, que es lo que ocurriría al guardar. Se señala porque no es evidente
  al mirar la tabla.
- El cálculo de la vista previa usa **exactamente la misma función** que el aumento real. Una vista
  previa que no coincide con el resultado es peor que no tenerla.

### Seleccionar todo lo que coincide con el filtro

Para que "Seleccionar todo lo filtrado" pueda marcar de un clic todos los artículos que coinciden
con la búsqueda o el filtro de catálogo activos, sin recorrer página por página, un endpoint nuevo
devuelve identificador, nombre y modelo de **todos** los artículos que resultarían del filtro
actual, sin paginar.

- `GET /api/v1/articulos/ids-filtrados`, con los mismos parámetros de consulta que ya acepta
  `index` (búsqueda, filtros de columna, catálogo). Reutiliza `filtrarBusqueda()` y `ordenar()`, las
  mismas funciones privadas que ya usan `index` y `exportarCsv`, solo que sin el `paginate()` final.
- Devuelve `{ "articulos": [{ "id": 1, "nombre": "...", "modelo": "..." }, ...] }`, no el recurso
  completo del artículo: es lo mínimo que el frontend necesita para sumarlos a la selección y
  mostrarlos por nombre en el panel de seleccionados, sin volver a pedir cada fila.
- **Sin límite ni paginación**, igual que `exportarCsv`: si el filtro activo devuelve 3 000
  artículos, se devuelven los 3 000. No hay un tope distinto al aviso ya existente de más de 100
  artículos al compartir la lista de precios ([028](028-lista-precios-pdf.md)).
- Se registra junto a `exportar-csv`, **antes** del `apiResource('articulos')`.
- Sin `FormRequest` propio: los parámetros de filtro son los mismos, ya validados de forma implícita
  por `filtrarBusqueda()` (que ignora lo que no reconoce), igual que hoy hace `index`.

### Endpoints

Todos bajo `auth:sanctum` y scopeados al usuario autenticado, como el resto del sistema.

| Método | Ruta | Qué hace |
| --- | --- | --- |
| `POST` | `/api/v1/articulos/eliminar-lote` | Borra en lote. `{ "ids": [...] }` → `{ "eliminados": N }` |
| `GET` | `/api/v1/articulos/ids-filtrados` | Todos los `id`/`nombre`/`modelo` que coinciden con el filtro activo, sin paginar |
| `DELETE` | `/api/v1/catalogos-proveedor/{catalogo}` | **Cambia de comportamiento**: ya no responde `409`; borra el catálogo y sus artículos |
| `POST` | `/api/v1/catalogos-proveedor/{catalogo}/aumentar-costos` | `{ "aumento_porcentaje": N }` → `{ "actualizados": N }` |
| `POST` | `/api/v1/catalogos-proveedor/{catalogo}/impacto-precios` | **Se extiende**: acepta `aumento_porcentaje`, y `descuento`/`utilidad_porcentaje` pasan a opcionales |

### Validaciones (Form Requests)

- **`EliminarLoteArticulosRequest`**: `ids` requerido, array, mínimo 1 elemento; cada `ids.*` entero
  y existente en `articulos` **restringido al usuario autenticado**. El scopeo va en la regla, no en
  el controlador, para que un identificador ajeno se rechace antes de abrir la transacción.
- **`AumentarCostosRequest`**: `aumento_porcentaje` requerido, numérico, `gt:0`, `lte:100`,
  `decimal:0,2`.
- El `{catalogo}` de ambas rutas de catálogo: debe existir y pertenecer al usuario autenticado
  (`abort_unless($catalogo->user_id === $request->user()->id, 404)`), igual que el resto del
  controlador.

## Frontend (Vue 3)

### `/articulos` — selección múltiple, persistente entre páginas

- **Casilla por fila**, en una columna nueva al inicio de la tabla, más una **casilla en el
  encabezado** que marca y desmarca todas las de la página visible. La casilla del encabezado
  muestra estado indeterminado cuando hay algunas marcadas pero no todas.
- **La selección sobrevive a cambiar de página, buscar, ordenar o cambiar el filtro de catálogo.**
  Se guarda como un `Map<id, { id, nombre, modelo }>` local a la vista, no como un simple arreglo de
  ids: guardar también el nombre y el modelo en el momento de marcar permite mostrarlos después en
  el panel de seleccionados sin volver a pedirlos al servidor, aunque el artículo ya no esté en la
  página visible. Un artículo marcado en la página 1 sigue marcado si se vuelve a esa página después
  de recorrer la 2 y la 3, buscar algo distinto, u ordenar por otra columna.
- **Persistida en `sessionStorage`** (clave `articulos-seleccion`), para que un refresco accidental
  de la pestaña (F5) no la borre. `sessionStorage` es una caja de almacenamiento del navegador que
  vive mientras la pestaña sigue abierta: sobrevive a recargar la página, pero se vacía sola al
  cerrar la pestaña y no se comparte con otras pestañas ni con otra visita más adelante.
- **El contador ("3 seleccionados") pasa a ser un botón** que abre un panel desplegable con el
  nombre (y modelo) de cada artículo marcado, con una "×" para quitarlo uno por uno sin tener que
  volver a la página donde se marcó. Con muchos artículos, el panel se desplaza dentro de su propio
  contenedor en vez de crecer sin límite.
- **Botón "Seleccionar todo lo filtrado"**, junto al aviso de "N artículos con los filtros
  aplicados" que ya muestra la barra de filtros activos
  ([025](025-filtros-columna-listado-articulos.md)): pide
  `GET /articulos/ids-filtrados` con la búsqueda y los filtros activos, y suma de una vez **todos**
  los artículos que coinciden, sin importar en cuántas páginas queden repartidos. Solo aparece
  cuando hay al menos un filtro puesto —mismo criterio que ya decide si se muestra esa barra—; sin
  ningún filtro activo, "seleccionar todo" marcaría el catálogo entero de un clic por accidente.
- **"Seleccionar todos" del encabezado sigue afectando solo la página visible**: agrega o quita del
  conjunto acumulado los artículos de esa página. No reemplaza al botón anterior, que cubre todas
  las páginas del filtro activo de una vez.
- **Barra de acciones**, visible solo cuando hay al menos un artículo marcado: el botón/panel de
  seleccionados y el botón **Eliminar**, además de "Mover a catálogo"
  ([034](034-filtro-catalogo-y-mover-lote-articulos.md)) y "Compartir Lista"
  ([028](028-lista-precios-pdf.md)), que ya reutilizan este mismo mecanismo de selección. Sin nada
  marcado no aparece, para no dejar en pantalla un botón permanentemente deshabilitado.
- **Confirmación con el conteo**, sin listar los artículos uno por uno: "¿Eliminar 12 artículos?
  Podrás recuperarlos solo por soporte técnico." (el detalle de cuáles son ya está a un clic, en el
  panel de seleccionados). El texto de recuperación es el mismo que ya usa el borrado individual.
- **"Quitar selección" vacía el conjunto completo**, sin importar en cuántas páginas estuviera
  repartido, y limpia también la copia en `sessionStorage`.
- **Al terminar Eliminar o Mover con éxito, la selección completa se vacía** (y `sessionStorage` con
  ella): esos artículos ya fueron eliminados o cambiaron de catálogo, y si el filtro activo ya no
  los incluye no tendría sentido dejarlos marcados. Compartir Lista (028) no vacía la selección,
  porque no cambia nada en el servidor.

### `/catalogos` — borrado del catálogo completo

El diálogo de confirmación que ya existe cambia según el catálogo tenga o no artículos
(`articulos_count`):

- **Catálogo vacío**: el diálogo de hoy, sin cambios.
- **Catálogo con artículos**: el texto dice **cuántos artículos se van con él** ("Se eliminarán
  también 240 artículos"), y el botón de confirmar **permanece deshabilitado hasta que el usuario
  escriba el nombre exacto del catálogo** en una caja de texto.

  Es la única fricción que esta historia agrega a propósito. Un clic accidental se lleva cientos de
  artículos, y aunque el borrado sea lógico, recuperarlos exige soporte técnico. Escribir el nombre
  es imposible de hacer por inercia. Es el mismo cerrojo que usan GitHub o Stripe para borrar algo
  que contiene cosas.

  La comparación del nombre es exacta salvo espacios sobrantes al inicio y al final.

### `/catalogos/:id/editar` — aumento de costo y vista previa

Se agrega al formulario de catálogo, junto al descuento y la utilidad que ya están:

- **Campo "Aumentar costo (%)"**, numérico, `step="0.01"`, `min` por encima de 0 y `max="100"`.
  Vacío es el estado normal: el campo no forma parte del guardado del catálogo, es una acción
  aparte.
- **Botón "Ver impacto"**, que consulta `impacto-precios` con lo que haya en el formulario (aumento,
  descuento y utilidad) y despliega una **tabla de vista previa**: por artículo, nombre, modelo,
  precio de proveedor actual → nuevo, costo total actual → nuevo, y precio de venta actual → nuevo.
  La tabla es desplazable dentro de su propio contenedor, con las reglas de layout de
  [003](003-design-system-tailwind.md) (`min-w-0`, `overflow-x-auto`), para que un catálogo de
  cientos de artículos no desborde la página.
- **Botón "Aplicar aumento"**, que abre la confirmación y llama a `aumentar-costos`. Queda
  deshabilitado si el campo de aumento está vacío o fuera de rango.
- **La confirmación incluye el aviso de inventario** (ver abajo) y advierte que **el cambio no se
  puede deshacer**: no queda registro del costo anterior.
- Un catálogo sin artículos muestra la vista previa vacía y el botón de aplicar deshabilitado.

### Aviso sobre la valuación del inventario

El módulo de inventario valúa las existencias **al costo de hoy**, no al costo al que entró cada
pieza ([017-inventario.md](017-inventario.md), "Valuación al costo de hoy"). Es una decisión ya
tomada y sigue siendo la correcta.

La consecuencia aquí es inmediata y hay que decirla antes de aplicar: **en cuanto se aplica el
aumento, el "dinero invertido" y el "beneficio potencial" que muestra inventario suben en la misma
proporción**, aunque esas piezas lleven meses en bodega y hayan costado menos. No es un efecto
secundario del aumento, es cómo está definida la valuación; pero visto sin contexto parece que el
sistema inventó dinero.

Va como una línea de texto en el diálogo de confirmación. No cambia ningún cálculo.

## Fuera de alcance

- **Deshacer un aumento**, o cualquier historial de precios anteriores. Para revertir un 5% no basta
  con bajar 5% (no es la operación inversa por el redondeo); haría falta guardar el costo previo de
  cada artículo, que es una historia distinta.
- **Aumentos programados o con fecha de vigencia.** Se aplica en el momento.
- **Aumentar el costo de una selección parcial de artículos**, o de todos los catálogos de un
  proveedor de una vez. El aumento es por catálogo completo, que es la unidad en la que el proveedor
  manda su lista.
- **Bajar costos por porcentaje.** El campo solo acepta aumentos.
- **Aumentar el `costo_goma`** desde aquí: sube por el pizarrón de configuración
  ([014](014-costo-elaboracion-goma.md)).
- **Selección compartida entre pestañas, o que sobreviva a cerrar la pestaña.**
  `sessionStorage` es por pestaña: abrir `/articulos` en una pestaña nueva, o volver otro día,
  empieza siempre con la selección vacía.
- **Guardar la selección en el servidor**, o asociarla al usuario de forma permanente.
- **Buscar dentro del panel de seleccionados.** Con una lista larga se hace scroll; no hay una caja
  de texto para filtrar lo ya marcado.
- **Deshacer selectivamente lo que agregó "Seleccionar todo lo filtrado".** Para quitar parte de lo
  que ese botón sumó, se destilda a mano desde el panel o la tabla; no existe un "deshacer" que
  distinga lo que vino de ese botón de lo marcado uno por uno.
- **Borrado en lote de catálogos, proveedores o clientes.**
- **Borrado definitivo (`forceDelete`) o papelera con restauración desde la interfaz.** Todo el
  borrado del sistema sigue siendo lógico y la recuperación sigue siendo por soporte técnico.
- **Borrar del disco las imágenes de los artículos eliminados.**
- **Bloquear el borrado de artículos referenciados en documentos emitidos.** No se hace hoy en el
  borrado individual y no se agrega ahora.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Estado de implementación

Implementada el 2026-08-12.

- **Archivos nuevos**: `app/Http/Requests/Articulos/EliminarLoteArticulosRequest.php`,
  `app/Http/Requests/Catalogos/AumentarCostosRequest.php`.
- **Archivos modificados**: `PrecioArticuloCalculator` (nuevo `precioProveedorAumentado`),
  `ArticuloController` (`eliminarLote`), `CatalogoProveedorController` (`aumentarCostos`, el
  `destroy` en cascada, el `impactoPrecios` extendido y el privado `proyectar`), `CatalogoResource`
  (`articulos_count`), `routes/api.php`, y en el frontend los stores de artículos y catálogos más
  las tres vistas.
- **`proyectar()` es la pieza que hace cumplible el criterio 20**: la vista previa y el aumento real
  llaman al mismo método privado, así que no hay dos caminos que puedan divergir.
- **El ejemplo del redondeo en la spec estaba mal y lo corrigió el test.** Decía que 0.5% sobre
  $1.00 dejaba el precio en $1.00; medio centavo justo **sube** (`round(1.005, 2) = 1.01`). La regla
  implementada —"un aumento cuyo efecto no llega a medio centavo no mueve nada"— nunca cambió; el
  ejemplo ahora usa 0.4%, que sí redondea hacia abajo.
- **Mensajes de validación en español para el lote**: el de Laravel ("The selected ids.2 is
  invalid") no le dice nada a quien lo lee en pantalla, y el caso real es una página abierta hace
  rato con artículos que ya no están.
- **Verificación end-to-end**: la suite Pest completa pasa (412 tests, 23 416 aserciones; 21
  nuevos), con Pint, ESLint y Prettier limpios, Vitest en verde (50 tests) y `npm run build`
  compilando la SPA con `vue-tsc` sin errores. Se levantó `php artisan serve` real y se probó por
  HTTP con un usuario y token de Sanctum de prueba (creados y **eliminados** al terminar): vista
  previa del 5% sobre un catálogo con 10% de descuento y 25% de utilidad, coincidiendo al centavo
  con lo que quedó guardado al aplicarla —incluidos el artículo con porcentaje propio (50%, precio
  $283.50) y el de $199.99 que redondea a $209.99—; el catálogo conservando su descuento y su
  utilidad; `422` para 0, 150 y 5.005; lote con un id inexistente rechazado sin borrar ninguno de
  los demás; lote válido de 2 devolviendo `{"eliminados":2}`; borrado de un catálogo con artículo
  dentro respondiendo `204` en vez del `409` de antes y arrastrando su artículo, con el otro
  catálogo del mismo proveedor y sus artículos intactos; y `401` sin token.
  **No se pudo verificar visualmente la UI en un navegador real** (misma limitación de entorno que
  el resto de las historias) — falta abrir `/articulos` para confirmar las casillas y la barra de
  selección, y `/catalogos/:id/editar` para la tabla de vista previa y el diálogo de confirmación.
- **Selección persistente entre páginas** (supuestos 34-42): pendiente de implementar. Cambia el
  `ref<number[]>` de `seleccionados` en `ArticulosListView.vue` por un `Map` persistido en
  `sessionStorage`, agrega el panel de seleccionados, el botón "Seleccionar todo lo filtrado" y el
  endpoint `GET /articulos/ids-filtrados` en `ArticuloController`. También ajusta 028 y 034, que
  hasta ahora describían la selección como limitada a la página visible.

## Criterios de aceptación

1. En `/articulos`, cada fila tiene una casilla y el encabezado una que marca y desmarca todas las
   de la página visible; con algunas marcadas, la del encabezado se muestra en estado
   indeterminado.
2. La barra con el conteo y el botón Eliminar aparece solo cuando hay al menos un artículo marcado.
3. Cambiar de página, buscar u ordenar vacía la selección.
4. Eliminar un lote de artículos pide confirmación indicando cuántos son, sin listarlos, y al
   aceptar los borra lógicamente en **una sola petición**.
5. Un lote que incluya el identificador de un artículo inexistente o de otro usuario se rechaza
   completo con `422` y **no borra ninguno** de los artículos del lote.
6. Un fallo a media operación deja la base de datos exactamente como estaba: no quedan artículos
   borrados a medias.
7. Los artículos eliminados en lote conservan su archivo de imagen en disco.
8. Borrar un catálogo que tiene artículos ya **no** responde `409`: elimina el catálogo y todos sus
   artículos, todos con borrado lógico.
9. El diálogo de borrado de un catálogo con artículos indica cuántos artículos se eliminarán y
   mantiene el botón de confirmar deshabilitado hasta que se escriba el nombre exacto del catálogo.
10. Borrar un catálogo vacío sigue funcionando con el diálogo de siempre, sin pedir que se escriba
    nada.
11. Borrar un catálogo no elimina ni modifica a su proveedor, ni a los demás catálogos de ese
    proveedor.
12. Aplicar un aumento del 5% a un catálogo sube un 5% el `precio_proveedor` de todos sus artículos
    y recalcula `costo_con_descuento` y `precio_unitario_sin_iva` en consecuencia.
13. El descuento del catálogo, el `utilidad_porcentaje` de cada artículo y el `costo_goma` quedan sin
    cambios después del aumento.
14. Un artículo con porcentaje de utilidad propio conserva el suyo; uno que hereda el del catálogo
    lo sigue heredando. El porcentaje de utilidad de ambos no cambia, aunque su utilidad en pesos
    suba.
15. El nuevo `precio_proveedor` queda redondeado a centavos: $199.99 con 5% da $209.99.
16. El precio resultante del aumento es idéntico, al centavo, al que se obtendría capturando a mano
    ese mismo `precio_proveedor` en el formulario del artículo.
17. Un aumento cuyo efecto es menor a medio centavo deja el artículo sin cambios, y la vista previa
    lo muestra con el precio actual y el nuevo iguales.
18. `aumento_porcentaje` rechaza con `422` el 0, los negativos, los mayores a 100 y los de más de dos
    decimales.
19. La vista previa muestra, por artículo, el precio de proveedor, el costo total y el precio de
    venta actuales y los que tendría, sin persistir nada; volver a consultar el catálogo devuelve los
    valores originales.
20. Los valores que muestra la vista previa coinciden exactamente con los que quedan guardados al
    aplicar el aumento.
21. `impacto-precios` sigue funcionando igual que antes cuando se le manda solo `descuento` y
    `utilidad_porcentaje`, sin `aumento_porcentaje`.
22. Pedir `impacto-precios` con solo `aumento_porcentaje` usa el descuento y la utilidad guardados
    del catálogo.
23. El diálogo de confirmación del aumento advierte que el cambio no se puede deshacer y que la
    valuación del inventario subirá en la misma proporción.
24. Las cotizaciones, facturas y órdenes de compra ya emitidas conservan sus precios después de un
    aumento; solo los documentos nuevos toman los precios nuevos.
25. Pint y ESLint/Prettier corren sin errores sobre el código nuevo, y `npm run build` compila la
    SPA completa.
26. Marcar un artículo, cambiar de página, buscar, ordenar o cambiar el filtro de catálogo, y volver
    a la página original: el artículo sigue marcado.
27. La casilla "seleccionar todos" del encabezado solo agrega o quita de la selección los artículos
    de la página visible, sin tocar lo marcado en otras páginas.
28. El botón/contador de seleccionados abre un panel con el nombre de cada artículo marcado y una
    forma de quitarlo uno por uno sin salir de la página actual.
29. Con al menos un filtro activo (búsqueda o catálogo), aparece "Seleccionar todo lo filtrado"; sin
    ningún filtro, no aparece. Al hacer clic, se suman a la selección todos los artículos que
    coinciden con el filtro activo, aunque ocupen más de una página.
30. `GET /articulos/ids-filtrados` devuelve únicamente `id`, `nombre` y `modelo`, solo de artículos
    del usuario autenticado, aplicando los mismos filtros que `index`, sin paginar.
31. Recargar la pestaña (F5) con artículos seleccionados conserva la selección tal como estaba.
32. "Quitar selección" vacía todo lo seleccionado, incluido lo marcado en páginas que ya no están
    visibles, y limpia la copia guardada en `sessionStorage`.
33. Al completar Eliminar o Mover a catálogo con éxito, la selección completa queda vacía. Al
    generar una lista de precios (Compartir Lista), la selección se mantiene igual que antes de
    generarla.

## Supuestos asumidos (registro completo)

### Borrado en lote de artículos

1. Los checkboxes aparecen en el listado de Artículos, una casilla por fila más una en el encabezado
   que marca y desmarca toda la página visible.
2. **(Revisado, ver "Selección persistente entre páginas" más abajo)** La selección sobrevive a
   cambiar de página, buscar, ordenar o cambiar el filtro de catálogo; ya no se pierde al hacerlo.
3. Con al menos un artículo marcado aparece una barra con el conteo y el botón Eliminar; sin nada
   marcado, la barra no se muestra.
4. La confirmación indica cuántos artículos se van a borrar, sin listarlos uno por uno.
5. El borrado en lote es lógico, igual que el individual: recuperable solo por soporte técnico.
6. Es todo o nada: si alguno no se puede eliminar, no se elimina ninguno y se explica el motivo.
7. No se comprueba si el artículo aparece en cotizaciones, facturas u órdenes de compra ya emitidas;
   esos documentos conservan sus datos y no se ven afectados.

### Borrado de catálogos completos

8. La acción vive en el módulo de Catálogos, no en el de Artículos.
9. Se elimina el catálogo junto con todos sus artículos; se retira la restricción actual que lo
   impide.
10. La confirmación dice explícitamente cuántos artículos se van a eliminar con él.
11. El borrado es lógico, tanto del catálogo como de sus artículos.
12. Se elimina un catálogo a la vez; no hay selección múltiple de catálogos.
13. El proveedor dueño del catálogo no se toca.

### Aumento porcentual del costo

14. "Aumentar el costo un 5%" significa subir un 5% el **precio de proveedor** de cada artículo del
    catálogo y recalcular la cadena completa desde ahí.
15. El descuento del catálogo y los porcentajes de utilidad no se modifican: solo se mueve el punto
    de partida, y el margen se conserva proporcionalmente.
16. Los artículos con porcentaje de utilidad propio conservan el suyo; los que heredan el del
    catálogo lo siguen heredando.
17. El costo de la goma no se aumenta: es un costo propio de elaboración, no del proveedor.
18. Se aplica a todos los artículos del catálogo; no se pueden excluir algunos ni aplicarlo a una
    selección parcial.
19. El campo acepta solo aumentos (porcentaje mayor que cero); no sirve para bajar costos.
20. Antes de aplicar se muestra una vista previa con el precio actual y el nuevo de cada artículo.
21. La acción se dispara desde la edición del catálogo, junto al descuento y la utilidad.
22. El cambio es definitivo: no queda registro del costo anterior ni existe un "deshacer".
23. Las cotizaciones, facturas y órdenes de compra ya emitidas conservan los precios con los que se
    generaron.

### Adiciones técnicas

24. **(Adición técnica)** El borrado en lote viaja en **una sola petición** con la lista de
    identificadores, no en una petición por artículo. Se usa `POST .../eliminar-lote` y no `DELETE`
    con cuerpo, porque el cuerpo de un `DELETE` no está garantizado de punta a punta.
25. **(Adición técnica)** Las tres operaciones se ejecutan dentro de una **transacción**, que es lo
    que hace real el "todo o nada" del supuesto 6 y lo extiende al borrado en cascada y al aumento.
26. **(Adición técnica)** Los artículos de un catálogo que se borra se marcan **de una sola
    instrucción**, no uno por uno: con cientos de artículos, la diferencia es entre milisegundos y
    una petición agotada. Queda anotado que ese camino no dispara eventos de modelo por artículo;
    hoy no hay ninguno enganchado al borrado de `Articulo`.
27. **(Adición técnica)** **Las imágenes de los artículos borrados no se eliminan del disco.** El
    borrado es lógico, así que borrar las fotos haría la recuperación incompleta y sin remedio: no
    están en git ni en ningún respaldo ([020](020-imagenes-articulos.md)). Se descartó la
    alternativa de borrarlas para recuperar espacio.
28. **(Adición técnica)** **Confirmación reforzada** al borrar un catálogo con artículos: el botón
    de confirmar sigue deshabilitado hasta que se escriba el nombre del catálogo. Se descartó la
    confirmación normal con solo el conteo, porque un doble clic mal puesto bastaría para vaciar un
    catálogo.
29. **(Adición técnica)** El nuevo `precio_proveedor` se **redondea a centavos** con
    `PrecioArticuloCalculator::redondeo2`. Documentado que dos aumentos encadenados no equivalen a
    su suma, y que un aumento menor a medio centavo deja el artículo igual.
30. **(Adición técnica)** El aumento se calcula **artículo por artículo con
    `PrecioArticuloCalculator`**, no con un `UPDATE` masivo en SQL. El techo a 2 decimales del
    markup no es portable entre MySQL y SQLite —misma razón que ya obligó a resolver en PHP el
    recálculo por descuento de `Catalogo::booted()`— y un precio calculado por un camino distinto
    produciría diferencias de un centavo según por dónde se entró a cambiarlo.
31. **(Adición técnica, precisada al implementar la spec)** Se **extiende el endpoint
    `impacto-precios` que ya existe** con `aumento_porcentaje`, en vez de crear un segundo endpoint
    de vista previa.

    Al revisar el código se encontró que **el endpoint existe desde [011](011-precio-proveedor-utilidad.md)
    pero el frontend nunca lo llama**: hoy se edita el descuento de un catálogo y se guarda a ciegas.
    La vista previa que sí está construida es la de configuración del costo de goma
    ([014](014-costo-elaboracion-goma.md)), sobre otro endpoint. Así que esta historia **construye la
    pantalla que faltaba**, y al hacerlo cubre también el caso del descuento, que llevaba sin ella
    desde 011.
32. **(Adición técnica)** El porcentaje de aumento acepta **dos decimales**, debe ser **mayor que
    0** y tiene un **tope de 100%**. El tope existe para que un dedazo tipo "500" no se aplique; si
    hiciera falta más, se hace en dos pasos.
33. **(Adición técnica)** El diálogo de confirmación **avisa que el aumento cambia la valuación del
    inventario**: como [017](017-inventario.md) valúa al costo de hoy, el dinero invertido y el
    beneficio potencial suben en la misma proporción de inmediato. Es solo texto; no cambia ningún
    cálculo.

### Selección persistente entre páginas

Agregado a partir de una petición de seguimiento: el usuario necesitaba armar una lista de precios
con artículos repartidos por toda la tabla ("sellos esparcidos"), y el checkbox por página no lo
permitía — al cambiar de página se perdía todo menos lo marcado en la última.

34. La selección de artículos sobrevive a cambiar de página, buscar, ordenar o cambiar el filtro de
    catálogo; deja de vaciarse en cualquiera de esos casos.
35. Esta persistencia aplica a las tres acciones en lote que comparten el mecanismo de checkboxes
    (Eliminar, Mover a catálogo, Compartir Lista), no solo a una de ellas.
36. La casilla "seleccionar todos" del encabezado sigue actuando solo sobre la página visible; no
    existe un "seleccionar todo lo que coincide con el filtro" a través de todas las páginas
    **mediante esa misma casilla** (ver adición técnica 39, que sí lo cubre con un botón aparte).
37. El contador de seleccionados cuenta el total acumulado en todas las páginas, no solo lo visible.
38. "Quitar selección" vacía todo el conjunto acumulado, no solo lo visible.
39. Un artículo que se vuelve inaccesible mientras está seleccionado (por ejemplo, otra pestaña lo
    borra) no se limpia de la selección de forma especial: si al usarlo en una acción en lote su id
    ya no existe, esa acción se rechaza completa, igual que ya ocurre hoy.
40. **(Adición técnica)** Un panel con la lista de artículos ya seleccionados, con su nombre y una
    forma de quitar cada uno sin volver a su página. Sin esto, marcar veinte artículos repartidos en
    ocho páginas no deja forma de ver de un vistazo cuáles llevas — solo el número total.
41. **(Adición técnica)** La selección se guarda en `sessionStorage` del navegador, para que un
    refresco accidental de la pestaña no la borre. No sobrevive a cerrar la pestaña ni se comparte
    entre pestañas: sigue siendo un estado de esa visita, no algo guardado en el servidor.
42. **(Adición técnica)** Un botón "Seleccionar todo lo filtrado", visible solo con al menos un
    filtro activo, que marca de un clic todos los artículos que coinciden con la búsqueda o el
    catálogo activos, sin importar cuántas páginas ocupen — para cuando lo que se busca sí comparte
    un filtro, en vez de tener que recorrer página por página. Se apoya en el endpoint nuevo
    `GET /articulos/ids-filtrados`, que reutiliza el mismo filtrado y ordenado que ya usa `index`.
