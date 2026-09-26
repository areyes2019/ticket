# Spec: Filtro de catálogo, cabeceras cortas y mover artículos de catálogo en lote

## Historia de usuario

Como usuario que trabaja con varios catálogos a la vez, quiero volver a acotar el listado de
`/articulos` por catálogo sin que la tabla se desborde, y quiero poder mover en lote un grupo de
artículos de un catálogo a otro —con el mismo gesto de checkbox que ya uso para eliminar—, para
reorganizar mi inventario cuando un proveedor cambia de lista o un grupo de artículos pasó al
catálogo equivocado, sin editarlos uno por uno.

## Objetivo / Alcance

Tres cambios sobre `/articulos`, que comparten pantalla y se resuelven juntos:

1. **El filtro de catálogo vuelve a la fila de filtros del listado**, como una lista desplegable
   con "Todos los catálogos". No vuelve la columna que mostraba el nombre del catálogo por fila
   ([025](025-filtros-columna-listado-articulos.md) la quitó a propósito): solo el mecanismo de
   filtrado, que ocupa su propia columna nueva en la cabecera.
2. **Las cabeceras "Precio de venta" y "Precio distribuidor" se acortan a "P Directo" y "P
   Dist"**, solo en esta tabla. Es lo que libera el ancho que necesita la columna nueva sin romper
   el ancho fijo, sin scroll horizontal, que fijó 025.
3. **Selección múltiple para mover de catálogo**, con el mismo patrón de checkboxes y barra de
   acciones que ya existe para "Eliminar" ([021](021-mantenimiento-articulos-catalogos.md)): un
   botón nuevo junto a Eliminar que pide el catálogo destino y reasigna en lote.

El backend del filtro de catálogo **ya existe y no se toca**: 025 dejó `filtro_catalogo_id`
funcionando y con pruebas en el servidor a propósito, "para el día que una historia futura pida
'los artículos de este catálogo'". Ese día es hoy. Mover en lote sí es funcionalidad nueva, sin
precedente en frontend ni en backend.

## Backend (Laravel)

### El filtro de catálogo no cambia

`ArticuloController::filtrarPorColumna` ya aplica `filtro_catalogo_id` como igualdad exacta contra
`catalogo_id` (`ArticuloController.php:539-542`), con sus pruebas intactas en
`ArticulosTest.php`. Esta historia no toca una sola línea de ese código: lo que vuelve es
únicamente el lado del navegador que dejó de mandarlo.

### Mover artículos de catálogo en lote

Mismo molde que `eliminarLote` ([021](021-mantenimiento-articulos-catalogos.md)): una sola
petición con la lista de identificadores, todo dentro de una transacción, todo o nada.

- `POST /api/v1/articulos/mover-lote`, con `{ "ids": [1, 2, 3], "catalogo_id": 7 }`.
- Se registra **antes** del `apiResource('articulos')`, junto a `eliminar-lote`, siguiendo la
  misma convención de rutas específicas primero.
- **Todos los `ids` deben pertenecer al usuario autenticado**, misma regla que
  `EliminarLoteArticulosRequest`. **`catalogo_id` debe existir y pertenecer también al usuario
  autenticado.** Si algo no cuadra, se rechaza la petición completa con `422` y no se mueve
  ninguno.
- **Se puede mover a un catálogo de un proveedor distinto** al que tenían los artículos antes: no
  hay validación de "mismo proveedor". El catálogo es la unidad de precio (descuento y utilidad);
  el proveedor es solo quien lo vende.
- **Los precios se recalculan al mover**, con el mismo criterio que ya usan el aumento de costo y
  la importación CSV: para cada artículo, se llama a
  `PrecioArticuloCalculator::calcularCadena()` con el `precio_proveedor` y `costo_goma` que ya
  tenía el artículo, el `descuento` y las dos utilidades del **catálogo destino**, y la utilidad
  propia del artículo si la tiene (de cualquiera de los dos tipos, herencia igual que en
  [011](011-precio-proveedor-utilidad.md) y [033](033-precio-distribuidor.md)). Se persisten
  `catalogo_id`, `costo_con_descuento`, `precio_unitario_sin_iva` y `precio_distribuidor_sin_iva`.

  Mover un artículo sin recalcular sus precios lo dejaría con el costo y el margen del catálogo
  viejo, mostrando un precio que no corresponde a ningún descuento ni utilidad configurados en
  ningún catálogo — el mismo tipo de inconsistencia que 011 y 033 ya evitan en todos los demás
  puntos donde cambia el costo o el catálogo de un artículo.
- **No se usa un `UPDATE` masivo en SQL**, por la misma razón que ya document 021 para el aumento
  de costo: el techo a 2 decimales del markup no es portable entre MySQL y SQLite, y un precio
  calculado por un camino distinto produce diferencias de un centavo según por dónde se entró a
  cambiarlo.
- **Todo en una transacción.**
- Respuesta: `{ "movidos": 12 }`.

### `MoverLoteArticulosRequest`

- `ids`: igual que en `EliminarLoteArticulosRequest` — requerido, array, mínimo 1, cada uno entero
  y existente en `articulos` restringido al `user_id` autenticado y no borrado.
- `catalogo_id`: requerido, entero, existente en `catalogos` restringido al `user_id` autenticado
  y no borrado.
- Mensajes en español, mismo criterio que el de eliminar: el mensaje por defecto de Laravel no le
  dice nada a quien lo lee en pantalla.

### Endpoints

| Método | Ruta | Qué hace |
| --- | --- | --- |
| `POST` | `/api/v1/articulos/mover-lote` | `{ "ids": [...], "catalogo_id": N }` → `{ "movidos": N }` |

## Frontend (Vue 3)

### La fila de filtros gana la columna "Catálogo"

La tabla pasa de siete a ocho columnas: casilla, Nombre, Modelo, **Catálogo**, Costo, P Directo, P
Dist, Acciones. "Catálogo" es una columna de filtro, no de datos: su celda en la fila de
cabeceras dice "Catálogo" y su celda en la fila de filtros lleva el `<select>`; **la fila de datos
de cada artículo deja esa columna vacía**, igual que hoy las columnas de dinero dejan vacía su
celda en la fila de filtros. La tabla sigue sin mostrar el catálogo de cada artículo por fila —
sigue siendo consulta de la ficha, como fijó 025 — pero ahora hay un control para acotar por él.

```
[ ] │ Nombre     │ Modelo     │ Catálogo        │ Costo │ P Directo │ P Dist │ Acciones
    │ [contiene] │ [contiene] │ [Todos los... ▾]│       │           │        │
```

- El `<select>` reutiliza `CatalogoSelect.vue`, el mismo componente que ya usan los modales de
  importar CSV y subir imágenes, con dos props nuevos que no afectan a esos usos:
  - `placeholder` (por defecto `"Selecciona un catálogo"`, sin cambio para quien no lo pasa).
  - `incluir-todos` (por defecto `false`): cuando es `true`, agrega una opción **real y
    seleccionable** "Todos los catálogos" que representa "sin filtro" (`v-model` en `null`), en
    vez de un simple placeholder sin seleccionar. Con `incluir-todos`, el filtro se ve
    explícitamente en "Todos los catálogos" cuando no hay ninguno elegido, no como una caja vacía
    que invita a pensar que hace falta llenarla.
- Elegir un catálogo dispara la recarga **de inmediato**, sin rebote: a diferencia de Nombre y
  Modelo, que se tecleen letra por letra, un `<select>` no genera una petición por cada carácter,
  así que no necesita compartir el temporizador de 300 ms de 025.
- Como cualquier otro filtro de columna, cambiarlo vuelve la paginación a la página 1 y cuenta
  para "Limpiar filtros" y para la línea de "N artículos con los filtros aplicados".

### `stores/articulos.ts`

- `ArticuloFiltros` gana `catalogoId: number | null` (`null` = sin filtro), junto a `nombre` y
  `modelo`.
- `filtrosVacios()` inicializa `catalogoId: null`.
- `paramsListado()` manda `filtro_catalogo_id: filtros.catalogoId ?? undefined`.
- `hayFiltros` deja de ser un `.some(valor => valor !== '')` sobre todo el objeto (`catalogoId` es
  numérico, no cadena) y comprueba cada campo por su tipo.
- Nueva acción `moverLoteCatalogo(ids: number[], catalogoId: number): Promise<number>`, que llama
  a `POST /articulos/mover-lote` con `{ ids, catalogo_id: catalogoId }` y devuelve
  `data.movidos`. No actualiza `items` localmente —a diferencia de `removeLote`—: los artículos
  movidos pueden dejar de cumplir el filtro de catálogo activo, así que quien la llama vuelve a
  pedir la página, igual que ya hace `confirmarEliminarLote`.

### Cabeceras de precio abreviadas

`columnasNumericas` (`ArticulosListView.vue:180-184`) cambia sus dos etiquetas:

| Antes | Ahora |
| --- | --- |
| Precio de venta | P Directo |
| Precio distribuidor | P Dist |
| Costo | Costo *(sin cambio)* |

Es solo texto de cabecera: la clave de ordenación (`precio_unitario_sin_iva`,
`precio_distribuidor_sin_iva`), el filtro de rango del servidor y el resto del comportamiento no
cambian. **El cambio es exclusivo de esta tabla** — el nombre completo "Precio distribuidor" se
queda tal cual en el formulario de artículo, en el de catálogo, en la ficha que se comparte al
cliente y en cualquier otro lugar del sistema que ya lo use (033): ahí el espacio no está
apretado y el nombre corto perdería claridad sin necesidad.

Con el texto corto, las tres columnas de dinero dejan de necesitar `whitespace-normal` (hoy
envuelven "Precio distribuidor" en dos líneas dentro de `w-40`): pasan a una sola línea y su ancho
se reduce, liberando el espacio que ocupa la columna "Catálogo" nueva sin agrandar la tabla ni
traer de vuelta el scroll horizontal que 025 eliminó.

### Selección múltiple: mover en lote

Junto al botón "Eliminar" de la barra que aparece con al menos un artículo marcado
(`ArticulosListView.vue:487-493`), un botón nuevo **"Mover a catálogo"**.

- Al hacer clic, abre un `Dialog` con:
  - El conteo: "Mover N artículo(s) a:".
  - Un `CatalogoSelect` (sin `incluir-todos`: aquí hace falta elegir uno concreto) para el
    catálogo destino.
  - Botón "Cancelar" y botón "Mover", deshabilitado mientras no haya catálogo elegido o mientras
    la operación está en curso.
- **No pide confirmación adicional de "¿estás seguro?"** más allá de elegir el catálogo y pulsar
  "Mover": a diferencia de eliminar, que es irreversible y por eso avisa explícitamente, mover
  artículos entre catálogos es una acción de bajo riesgo y reversible con el mismo gesto (volver a
  moverlos).
- Al confirmar, llama a `articulos.moverLoteCatalogo(ids, catalogoDestino)`, cierra el diálogo y
  recarga la página actual del listado — mismo patrón que `confirmarEliminarLote`. Al terminar con
  éxito, la selección completa se vacía a propósito (incluida la parte guardada en
  `sessionStorage`), no como efecto colateral de recargar la tabla: la selección ahora sobrevive a
  cualquier recarga salvo cuando la propia acción en lote termina bien
  ([021](021-mantenimiento-articulos-catalogos.md), "Selección persistente entre páginas").
- Un error del servidor (por ejemplo, un artículo que ya no existe porque otra pestaña lo borró)
  se muestra en el diálogo, igual que en el de eliminar en lote, y no cierra el diálogo ni vacía
  la selección.

## Fuera de alcance

- **La columna "Catálogo" con el nombre del catálogo por fila.** Sigue sin mostrarse en la tabla;
  se consulta en la ficha del artículo, como fijó 025.
- **Renombrar "Precio distribuidor" en cualquier otra pantalla** (formulario de artículo, de
  catálogo, ficha para compartir). Solo cambian las cabeceras de esta tabla.
- **Mover artículos de catálogo uno por uno desde el formulario de edición.** Ya es posible hoy
  editando el campo de catálogo del artículo; esta historia agrega la vía en lote, no toca la
  individual.
- **Deshacer un movimiento en lote**, o cualquier historial de a qué catálogo pertenecía antes un
  artículo.
- **Un mecanismo de selección propio de esta pantalla.** Se reutiliza tal cual el de
  [021](021-mantenimiento-articulos-catalogos.md): la selección sobrevive a la paginación, la
  búsqueda, el orden y el filtro de catálogo, definido ahí, no aquí.
- **Mover en lote a más de un catálogo a la vez**, o repartir la selección entre varios destinos.
  Un solo catálogo destino por operación.
- **Restringir el catálogo destino al mismo proveedor** de los artículos movidos.
- **Filtrar por más de un catálogo a la vez** (multiselección). El filtro sigue siendo de un
  catálogo o "Todos".
- **Guardar o recordar el filtro de catálogo** entre visitas, en `localStorage` o en la URL —
  mismo criterio que el resto de los filtros de columna (025).
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Criterios de aceptación

1. La fila de filtros de `/articulos` tiene una columna "Catálogo" con una lista desplegable que
   incluye "Todos los catálogos" y cada catálogo del usuario.
2. Elegir un catálogo en ese filtro acota el listado a los artículos de ese catálogo, de
   inmediato, sin esperar una pausa de tecleo.
3. El filtro de catálogo se combina con Y con el buscador global, con los filtros de Nombre y
   Modelo, y con la ordenación, igual que los demás filtros de columna.
4. Elegir "Todos los catálogos" quita el filtro y vuelve a mostrar el listado completo (sujeto a
   los demás filtros activos).
5. Cambiar el filtro de catálogo devuelve la paginación a la página 1 y cuenta para el botón
   "Limpiar filtros" y para el conteo de "N artículos con los filtros aplicados".
6. La tabla no muestra el nombre del catálogo en ninguna fila de datos: la columna "Catálogo" solo
   existe en la cabecera y en la fila de filtros.
7. Las cabeceras de precio de `/articulos` dicen "P Directo" y "P Dist" en vez de "Precio de
   venta" y "Precio distribuidor". Ordenar por esas columnas sigue funcionando igual que antes.
8. Ningún otro lugar del sistema (formulario de artículo, de catálogo, ficha compartida) cambia el
   texto "Precio distribuidor" por su abreviatura.
9. En escritorio (≥1280px), con las ocho columnas y su fila de filtros, la tabla se sigue viendo
   completa **sin barra de desplazamiento horizontal**.
10. Con al menos un artículo marcado, la barra de selección muestra el botón "Eliminar" (sin
    cambios) y un botón nuevo "Mover a catálogo".
11. Al hacer clic en "Mover a catálogo" se abre un diálogo con el conteo de artículos y un
    selector de catálogo destino; el botón "Mover" está deshabilitado hasta elegir uno.
12. Confirmar el movimiento reasigna el catálogo de todos los artículos seleccionados en **una
    sola petición**, recarga la tabla y limpia la selección.
13. Un lote que incluya un artículo ajeno o inexistente, o un catálogo destino ajeno o
    inexistente, se rechaza completo con `422` y no mueve ninguno de los artículos del lote.
14. Se puede mover artículos a un catálogo de un proveedor distinto al que tenían.
15. Al mover un artículo, su `costo_con_descuento`, `precio_unitario_sin_iva` y
    `precio_distribuidor_sin_iva` quedan recalculados con el descuento y las utilidades del
    catálogo destino, respetando la utilidad propia del artículo si la tiene (de cualquiera de los
    dos tipos) y sin modificar su `costo_goma` ni su `precio_proveedor`.
16. El precio resultante de mover un artículo es idéntico, al centavo, al que se obtendría
    editando a mano su catálogo en el formulario del artículo y guardando.
17. Mover no pide una confirmación de "¿estás seguro?" adicional a elegir el catálogo y pulsar
    "Mover".
18. Pint corre sin errores sobre el código de backend, ESLint y Prettier sobre el de frontend, la
    suite de Pest sigue pasando, y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

1. El filtro de catálogo se restaura como una lista desplegable para elegir un catálogo específico
   o "Todos los catálogos" — no se restaura la columna que mostraba el nombre del catálogo en cada
   fila.
2. El filtro de catálogo ocupa una columna nueva en la tabla, no reutiliza el espacio de una
   columna de precio existente.
3. Elegir un catálogo en el filtro actualiza la tabla de inmediato, sin botón "Buscar" aparte.
4. El renombre de "Precio Venta"/"Precio Distribuidor" a "P Directo"/"P Dist" es solo texto visible
   en la cabecera de esta tabla; el comportamiento de ordenar por esa columna no cambia.
5. El botón para mover en lote aparece junto al de "Eliminar" en la barra de selección, visible
   solo con al menos un artículo marcado.
6. Mover a catálogo pide elegir el catálogo destino, en una ventana emergente, antes de confirmar.
7. Se puede mover artículos hacia un catálogo de un proveedor distinto al que tenían.
8. Al mover un artículo a otro catálogo, sus precios se recalculan según las reglas del catálogo
   destino, igual que al editar un catálogo existente.
9. La operación de mover en lote es todo o nada.
10. Solo se pueden mover artículos que pertenecen al usuario actual.
11. Tras mover en lote, la tabla se recarga y la selección se limpia.
12. Mover no pide una confirmación adicional de "¿estás seguro?": se considera una acción de bajo
    riesgo y reversible, a diferencia de eliminar.
13. **(Adición técnica)** El filtro de catálogo no requiere ningún cambio de backend:
    `filtro_catalogo_id` ya funciona y tiene pruebas desde 025, que lo dejó a propósito por si
    hacía falta después.
14. **(Adición técnica)** `CatalogoSelect.vue` se reutiliza tanto para el filtro como para el
    selector de catálogo destino, con dos props nuevos (`placeholder`, `incluir-todos`) que no
    afectan a los usos que ya existen (importar CSV, subir imágenes), en vez de construir un
    segundo componente.
15. **(Adición técnica)** `incluir-todos` agrega una opción real y seleccionable "Todos los
    catálogos" (mapeada a `null`), no solo un placeholder sin elegir: así el filtro se lee
    explícitamente "sin filtro" en vez de parecer una caja vacía a medio llenar.
16. **(Adición técnica)** El nuevo endpoint `POST /articulos/mover-lote` sigue el mismo molde que
    `eliminar-lote`: una sola petición con la lista de ids, transacción, todo o nada, y la
    pertenencia al usuario validada en el Form Request antes de abrir la transacción.
17. **(Adición técnica)** El recálculo de precios al mover usa
    `PrecioArticuloCalculator::calcularCadena()` artículo por artículo, la misma pieza que ya usan
    el alta, la edición, la importación CSV, el aumento de costos (021) y el recálculo en bloque de
    `Catalogo::booted()` (011, 033) — no una fórmula duplicada ni un `UPDATE` masivo en SQL, que no
    sería portable por el techo a 2 decimales del markup.
18. **(Adición técnica)** Las columnas de dinero reducen su ancho porque sus cabeceras cortas ya no
    necesitan envolver en dos líneas; ese ancho liberado es el que ocupa la columna "Catálogo"
    nueva, para que la tabla de ocho columnas siga sin scroll horizontal.
19. **(Adición técnica)** El store no actualiza `items` localmente tras mover en lote (a diferencia
    de `removeLote`, que sí filtra localmente): los artículos movidos pueden dejar de cumplir el
    filtro de catálogo activo, así que hace falta volver a pedir la página desde el servidor.

## Estado de implementación

Implementada el 2026-08-20.

- **Archivos nuevos**: `app/Http/Requests/Articulos/MoverLoteArticulosRequest.php`.
- **Archivos modificados**: `app/Http/Controllers/ArticuloController.php` (`moverLote`),
  `routes/api.php`, `tests/Feature/ArticulosTest.php` (ocho pruebas nuevas), y en el frontend
  `frontend/src/components/CatalogoSelect.vue` (props `incluirTodos`, `placeholder`, `size`),
  `frontend/src/stores/articulos.ts` (`ArticuloFiltros.catalogoId`, `moverLoteCatalogo`) y
  `frontend/src/views/ArticulosListView.vue`.
- **`CatalogoSelect` ganó `size` además de los dos props previstos en la spec**: el `SelectTrigger`
  de shadcn ya traía un tamaño `sm` (`h-8`) pensado exactamente para celdas angostas como la fila
  de filtros, así que se expuso ese prop en vez de forzar la altura con una clase `[&_button]`
  desde fuera, que además no habría atravesado el componente.
- **`CatalogoSelect` con `size="sm"` en una celda `w-52`** deja "Todos los catálogos" completo, sin
  truncar, dentro de la fila de filtros.
- **Verificación**: Pint limpio; la suite de Pest completa pasa (596 tests, 825 069 aserciones,
  incluidas las 108 de `ArticulosTest.php`); ESLint y Prettier limpios sobre los archivos
  modificados; Vitest en verde (95 tests); `npm run build` compila la SPA completa con `vue-tsc`.
  **Se verificó visualmente en un navegador real** (Playwright/Chromium contra `php artisan serve`
  y `npm run dev` levantados para la ocasión, con un usuario y datos de prueba creados y
  eliminados al terminar, mismo criterio que 021): las ocho columnas y su fila de filtros se ven
  completas sin scroll horizontal a 1440px; el filtro de catálogo recarga la tabla de inmediato al
  elegir una opción y vuelve a mostrar todo al elegir "Todos los catálogos"; la barra de selección
  muestra "Mover a catálogo" junto a "Eliminar"; el diálogo cuenta correctamente los artículos
  seleccionados y deshabilita "Mover" sin catálogo elegido; y mover 2 artículos de un catálogo con
  0%/50%/25% a uno con 10%/50%/25% devolvió `{"movidos":2}` y dejó costo y ambos precios
  recalculados exactamente como predice la cadena de `PrecioArticuloCalculator` (verificado al
  centavo contra los valores mostrados en pantalla).
