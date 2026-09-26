# Spec: Filtro de catálogo fuera de la tabla, columna de utilidad y nombre completo en artículos

## Historia de usuario

Como usuario que trabaja todos los días con el listado de `/articulos`, quiero acotarlo por
catálogo sin que ese control ocupe una columna de la tabla, quiero ver de un vistazo cuántos pesos
de utilidad deja cada artículo sin tener que abrir su ficha, quiero leer el nombre completo de cada
artículo aunque sea largo, en vez de que se corte con puntos suspensivos, quiero que la columna "P
Directo" muestre el precio que de verdad paga el público —con IVA incluido— para poder tasar mis
precios y ver los porcentajes de ganancia que puedo dar, quiero exactamente lo mismo del lado del
precio distribuidor —verlo con IVA incluido y ver cuántos pesos de utilidad me deja— para saber si
me conviene el precio que estoy estableciendo para ese tipo de cliente, y quiero que el filtro de
catálogo no se pierda si recargo la página.

## Objetivo / Alcance

Siete cambios sobre `/articulos`, que comparten pantalla y se resuelven juntos:

1. **El filtro de catálogo sale de la tabla** y se coloca junto al buscador global, arriba de la
   tabla. Deja de existir la columna "Catálogo" que agregó
   [034](034-filtro-catalogo-y-mover-lote-articulos.md): ni su cabecera ni su celda de filtro. El
   mecanismo de filtrado no cambia en nada más que su ubicación en la pantalla — sigue siendo el
   mismo `<select>` con "Todos los catálogos", sigue recargando de inmediato al elegir una opción y
   sigue contando para "Limpiar filtros" y para "N artículos con los filtros aplicados".
2. **Columna nueva "Utilidad"**, con el monto en pesos de la utilidad directa de cada artículo:
   precio directo (P Directo) menos costo total (Costo) — el mismo número que el sistema ya calcula
   y expone desde hace tiempo, solo que hasta ahora ninguna pantalla le ponía una columna. Se agrega
   después de "P Dist" y antes de "Acciones", y es ordenable por su cabecera igual que Costo, P
   Directo y P Dist.
3. **El nombre del artículo se muestra completo**, envuelto en las líneas que haga falta, en vez de
   recortado con "...". La columna conserva el mismo ancho que tiene hoy; lo que cambia es que una
   fila con un nombre largo crece de alto en vez de recortar el texto.
4. **La columna "P Directo" pasa a mostrar el precio con IVA incluido** (`precio_unitario_con_iva`),
   el que de verdad paga el público, en vez del precio sin IVA que mostraba hasta ahora. Es el
   número que hace falta para tasar precios y calcular márgenes de ganancia contra lo que paga el
   cliente.
5. **El filtro de catálogo sobrevive a un recargado del navegador** (F5): se refleja en la URL como
   parámetro de consulta, así que al recargar la página el filtro se vuelve a aplicar solo, sin que
   el usuario tenga que elegirlo de nuevo.
6. **La columna "P Dist" pasa a mostrar el precio distribuidor con IVA incluido**
   (`precio_distribuidor_con_iva`), el mismo cambio que el punto 4 le aplica a "P Directo": el
   distribuidor también paga el IVA cuando el artículo lo causa, y ese es el número que hace falta
   para tasar el precio distribuidor contra lo que en verdad se le cobraría.
7. **Columna nueva "Util Dist"**, con el monto en pesos de la utilidad que deja el precio
   distribuidor de cada artículo: precio distribuidor (sin IVA) menos el costo del artículo **sin
   goma** — el distribuidor nunca la paga, la misma regla que ya rige para calcular su precio
   ([033](033-precio-distribuidor.md)). Se agrega después de "Utilidad" y antes de "Acciones", y es
   ordenable por su cabecera igual que las demás columnas de dinero.

**No se toca la columna "Costo"** (costo interno del artículo: lo que cuesta el aparato con
descuento más la goma), ni se agrega ninguna columna nueva de precio con IVA: los valores con IVA
reemplazan, en las mismas columnas, a los que ya mostraban "P Directo" y "P Dist".

Con estos cambios la tabla queda en 9 columnas: casilla, Nombre, Modelo, Costo, P Directo, P Dist,
Utilidad, Util Dist, Acciones — una más que las 8 de antes de esta revisión, porque "Util Dist" es
columna nueva mientras que "P Dist" solo cambia de valor, igual que ya le pasó a "P Directo". La
tabla se verifica de nuevo en escritorio; si las nueve columnas no caben sin scroll horizontal con
los anchos actuales, se acortan los que haga falta, sin quitar ninguna columna.

## Backend (Laravel)

### Ordenar por "Utilidad" no necesita ningún cambio en el servidor

`ArticuloController::ORDENACIONES` (`ArticuloController.php:59-64`) **ya tiene**, desde antes de
esta historia, una clave `utilidad`:

```php
'utilidad' => 'precio_unitario_sin_iva - (costo_con_descuento + costo_goma)',
```

Es exactamente el número que pide esta columna (precio directo menos costo total), calculado sobre
columnas propias de `articulos`, sin tocar `catalogos` — nunca necesitó `JOIN` ni subconsulta. Lo
único que faltaba era que el frontend la ofreciera como opción de orden en esta tabla; nunca antes
tuvo una cabecera clicable.

- **No se agrega filtro de rango para "Utilidad"**: no hay una entrada nueva en `RANGOS`
  (`ArticuloController.php:74-79`). Igual que Costo, P Directo y P Dist, la columna se puede
  ordenar pero no acotar por un mínimo/máximo.
- `ArticuloResource` no cambia: `utilidad` ya viaja sin condición en la respuesta
  (`ArticuloResource.php:58`, `'utilidad' => $this->utilidad`), a diferencia del porcentaje
  efectivo (`utilidad_porcentaje_efectivo`) que sí depende de que la relación `catalogo` esté
  cargada. No hace falta ningún `->with()` adicional para que el dato llegue.

### El filtro de catálogo no cambia de lado del servidor

Sigue siendo `filtro_catalogo_id`, aplicado exactamente igual que desde 025/034
(`ArticuloController.php:589-592`). Esta historia no toca una sola línea de ese código. Reflejarlo
en la URL del navegador es un cambio exclusivamente de enrutamiento del frontend
(`vue-router`); el servidor sigue recibiendo el mismo parámetro de siempre.

### La columna "P Directo" con IVA tampoco necesita ningún cambio en el servidor

`ArticuloResource` ya expone `precio_unitario_con_iva` desde
[033](033-precio-distribuidor.md) (`ArticuloResource.php:55`), calculado por el accessor
`precioUnitarioConIva` de `Articulo` (`app/Models/Articulo.php:134-140`). `ArticuloController::index`
ya lo devuelve para cada artículo del listado; el cambio es únicamente qué campo lee la celda en el
frontend.

**La ordenación y el filtro de rango de esa columna siguen sobre el valor sin IVA.**
`ORDENACIONES` y `RANGOS` (`ArticuloController.php:61-79`) no cambian: la clave
`precio_unitario_sin_iva` sigue siendo la que ordena y filtra por rango al hacer clic en "P
Directo" o al escribir un mínimo/máximo. No se agrega una ordenación ni un filtro de rango nuevos
sobre el valor con IVA.

### La columna "P Dist" con IVA tampoco necesita ningún cambio en el servidor

`ArticuloResource` ya expone `precio_distribuidor_con_iva` desde
[033](033-precio-distribuidor.md) (`ArticuloResource.php:57`), calculado por el accessor
`precioDistribuidorConIva` de `Articulo` (`app/Models/Articulo.php:148-156`) — espejo exacto de
`precioUnitarioConIva`, mismo `PrecioArticuloCalculator::factorIva($this->objeto_imp)`.
`ArticuloController::index` ya lo devuelve para cada artículo del listado; el cambio es
únicamente qué campo lee la celda en el frontend.

**La ordenación de esa columna sigue sobre el valor sin IVA**, mismo criterio que "P Directo": la
clave `precio_distribuidor_sin_iva` de `ORDENACIONES` sigue siendo la que ordena al hacer clic en
"P Dist". No se agrega una ordenación nueva sobre el valor con IVA. "P Dist" no tiene filtro de
rango expuesto en pantalla, así que no aplica ningún cambio ahí.

### Utilidad distribuidor: cálculo nuevo, espejo de "Utilidad"

`Articulo` gana un accessor **`utilidadDistribuidor`**, espejo exacto de `utilidad`
(`app/Models/Articulo.php:179-187`), pero medido contra `costo_con_descuento` en vez de
`costo_total`, porque el precio distribuidor tampoco lleva el costo de la goma (033):

```php
protected function utilidadDistribuidor(): Attribute
{
    return Attribute::make(
        get: fn (): float => PrecioArticuloCalculator::utilidad(
            (float) $this->precio_distribuidor_sin_iva,
            (float) $this->costo_con_descuento,
        ),
    );
}
```

Reutiliza `PrecioArticuloCalculator::utilidad()` sin cambios: la función ya es una resta genérica
(precio de venta menos costo), así que no hace falta ningún método nuevo en el calculador — solo
qué par de valores se le pasan.

`ArticuloResource` agrega `utilidad_distribuidor` junto a `utilidad`, sin condición (mismo
criterio: no depende de que la relación `catalogo` esté cargada, a diferencia de los porcentajes
efectivos).

`ArticuloController::ORDENACIONES` gana una clave nueva, paralela a `utilidad`:

```php
'utilidad_distribuidor' => 'precio_distribuidor_sin_iva - costo_con_descuento',
```

Igual que `utilidad`, no gana entrada en `RANGOS`: se puede ordenar por su cabecera, no acotar por
un mínimo/máximo — la columna en pantalla no lleva caja de filtro.

## Frontend (Vue 3)

### El filtro de catálogo sale de la tabla

- Se quita de `ArticulosListView.vue` la columna "Catálogo" completa: su `<TableHead>` en la fila
  de cabeceras (`ArticulosListView.vue:577`) y su `<TableHead>` con el `CatalogoSelect` en la fila
  de filtros (`ArticulosListView.vue:612-620`). La tabla vuelve a tener el número de columnas que
  tenía antes de 034 más la nueva de Utilidad.
- El `CatalogoSelect` se mueve al bloque de arriba de la tabla, junto al `Input` del buscador
  global (`ArticulosListView.vue:499-504`), en el mismo renglón. Usa los mismos props que ya tenía
  en la tabla (`incluir-todos`, `placeholder="Todos los catálogos"`), pero **sin** `size="sm"`: ese
  tamaño se eligió en 034 porque la celda de la fila de filtros era angosta
  (`ArticulosListView.vue:617`); fuera de la tabla no hay esa restricción, así que usa el tamaño
  por defecto del componente, igual que el buscador de al lado.
- `onFiltroCatalogo` (`ArticulosListView.vue:208-211`) no cambia: sigue siendo la misma función,
  solo cambia desde qué elemento del template se dispara.
- La celda vacía que dejaban las columnas de dinero en la fila de filtros
  (`ArticulosListView.vue:624-628`) ya no necesita contar la columna de Catálogo, solo una celda
  menos.

### Columna nueva "Utilidad"

- `columnasNumericas` (`ArticulosListView.vue:192-196`) gana un cuarto elemento:
  `{ clave: 'utilidad', etiqueta: 'Utilidad' }`. Como la cabecera y la fila de filtros de las
  columnas de dinero ya se dibujan iterando esa lista, la cabecera ordenable y la celda vacía de
  filtro de "Utilidad" salen solas, sin tocar el template de esas dos filas.
- La fila de datos de cada artículo gana una celda nueva junto a "P Dist" y antes de "Acciones",
  con el mismo ancho (`w-24`) y el mismo formato que las otras tres columnas de dinero:
  `${{ pesos(articulo.utilidad) }}` — "$" más dos decimales, igual que Costo, P Directo y P Dist. No
  es porcentaje ni lleva ningún símbolo distinto.
- El `colspan="8"` de la fila de "sin resultados" (`ArticulosListView.vue:634`) **no cambia**: la
  tabla sigue teniendo 8 columnas, solo cambió cuál es la octava.

### `stores/articulos.ts`

- `ArticuloSort` (`articulos.ts:134`) gana `'utilidad'` como cuarto valor posible, para que
  `toggleSort` y el resto del tipado acepten ordenar por la columna nueva. Es una clave que el
  servidor ya reconocía desde antes de esta historia (ver Backend); lo nuevo es solo agregarla al
  tipo del frontend.
- Nada más cambia en el store: `catalogoId` sigue viviendo en `ArticuloFiltros` exactamente igual
  que hoy, `paramsListado()` sigue mandando `filtro_catalogo_id` igual, y `hayFiltros` sigue
  contando igual. Mover el `<select>` en el template no mueve nada de estado.

### Nombre completo

- En la celda de nombre (`ArticulosListView.vue:657-664`) se quita la clase `truncate` del botón
  que abre la ficha del artículo. Sin esa clase el texto envuelve de forma normal en las líneas que
  haga falta, en vez de recortarse con elipsis.
- El atributo `title="articulo.nombre"` se quita junto con `truncate`: existía para poder leer el
  nombre completo al pasar el mouse sobre un texto recortado: con el texto ya completo en pantalla
  deja de tener función.
- El resto de columnas de esa fila (casilla, Modelo, Costo, P Directo, P Dist, Utilidad, Acciones)
  no cambia: cuando una fila crece de alto porque su nombre ocupa dos o más líneas, esas celdas
  siguen con su contenido de una sola línea, solo se estira la fila completa.

### La columna "P Directo" muestra el precio con IVA incluido

- La celda de cada fila (`ArticulosListView.vue:744`, hoy `${{ pesos(articulo.precio_unitario_sin_iva) }}`)
  pasa a leer `articulo.precio_unitario_con_iva`, campo que la API ya entrega en cada artículo del
  listado. No hace falta ningún cálculo en el frontend: el valor ya viene calculado del servidor.
- **Ordenar y filtrar por rango en esa columna siguen funcionando sobre el valor sin IVA** (ver
  "Backend"): el número que se ve en la celda cambia, pero el clic en la cabecera y el rango de la
  fila de filtros siguen ordenando/filtrando contra `precio_unitario_sin_iva` en el servidor, sin
  cambio de comportamiento respecto a hoy.
- **La columna "Utilidad" no cambia de cálculo**: sigue siendo precio directo sin IVA menos costo
  total, el mismo número que mostraba antes de este cambio. Como consecuencia, "P Directo" (ahora
  con IVA) menos "Costo" deja de coincidir a simple vista con "Utilidad" — Utilidad sigue midiendo
  el margen sin impuesto, que es lo que de verdad gana el negocio; el IVA es dinero que se entrega
  al SAT, no utilidad.

### La columna "P Dist" muestra el precio distribuidor con IVA incluido

- La celda de cada fila (hoy `${{ pesos(articulo.precio_distribuidor_sin_iva) }}`) pasa a leer
  `articulo.precio_distribuidor_con_iva`, campo que la API ya entrega en cada artículo desde 033.
  Mismo criterio que "P Directo": ningún cálculo en el frontend, el valor ya viene del servidor.
- **Ordenar en esa columna sigue funcionando sobre el valor sin IVA** (ver "Backend"): el clic en
  la cabecera sigue ordenando contra `precio_distribuidor_sin_iva` en el servidor, sin cambio de
  comportamiento — solo cambió el número visible en la celda.
- **La columna "Util Dist" no se ve afectada por este cambio**: sigue siendo precio distribuidor
  sin IVA menos costo sin goma, el mismo criterio que "Utilidad" aplica del lado directo.

### Columna nueva "Util Dist"

- `columnasNumericas` (`ArticulosListView.vue:253-258`) gana un quinto elemento:
  `{ clave: 'utilidad_distribuidor', etiqueta: 'Util Dist' }`. Como la cabecera y la fila de
  filtros de las columnas de dinero ya se dibujan iterando esa lista, la cabecera ordenable y la
  celda vacía de filtro salen solas, sin tocar el template de esas dos filas.
- La fila de datos de cada artículo gana una celda nueva junto a "Utilidad" y antes de "Acciones",
  con el mismo ancho (`w-24`) y el mismo formato que las otras columnas de dinero:
  `${{ pesos(articulo.utilidad_distribuidor) }}` — "$" más dos decimales. No es porcentaje.
- El `colspan` de la fila de "sin resultados" sube de 8 a 9: la tabla queda en nueve columnas.
- `ArticuloSort` (`stores/articulos.ts`) gana `'utilidad_distribuidor'` como quinto valor posible,
  mismo patrón que ganó `'utilidad'` en 035: es una clave que el servidor ya reconoce (ver
  "Backend"), lo nuevo es solo agregarla al tipo del frontend para que `toggleSort` la acepte.

### El filtro de catálogo se conserva en la URL al recargar

- Al elegir un catálogo en el `CatalogoSelect` de arriba de la tabla, la URL de la pantalla gana un
  parámetro de consulta (por ejemplo, `/articulos?catalogo=7`). Elegir "Todos los catálogos" quita
  el parámetro de la URL.
- El cambio de URL usa `router.replace()`, no `router.push()`: cambiar de catálogo no agrega una
  entrada nueva al historial del navegador, así que el botón "Atrás" saca de `/articulos` en un
  solo paso, sin tener que pasar por cada catálogo que se probó antes.
- Al entrar a `/articulos` —incluida una recarga completa del navegador—, si la URL trae
  `?catalogo=`, ese valor se usa para inicializar `articulos.filtros.catalogoId` antes de la
  primera petición al servidor, así que el listado sale ya filtrado sin mostrar primero todos los
  artículos.
- **Un `catalogo` en la URL que no existe o no pertenece al usuario se ignora en silencio.** Se
  valida contra la lista de catálogos del usuario que ya carga `CatalogoSelect` (`catalogos`
  store): si el id de la URL no está en esa lista, el filtro queda en "Todos los catálogos" y no se
  muestra ningún error — en vez de dejar la tabla filtrada a cero resultados sin ninguna
  explicación, que es lo que pasaría si el id inválido se mandara tal cual al servidor.

## Fuera de alcance

- **Una columna nueva de "costo total".** Se planteó durante esta historia y se descartó: la
  columna "Costo" que ya existe (costo interno del artículo) se queda exactamente como está, sin
  ningún cambio ni columna adicional. El precio con IVA no es una columna nueva: reemplaza el valor
  que ya mostraba "P Directo".
- **Filtro de rango sobre "Utilidad"** (mínimo/máximo). Solo se puede ordenar por esa columna,
  igual que Costo, P Directo y P Dist.
- **Recordar cualquier filtro que no sea el de catálogo, entre recargas de página** (Nombre,
  Modelo, rangos de precio, orden o página). Esos siguen reiniciándose al recargar, mismo criterio
  que antes (025).
- **Guardar el filtro de catálogo en `localStorage`.** Se refleja en la URL, no en el
  almacenamiento del navegador, para que además el enlace sea compartible y quede marcable.
- **Limitar el nombre a un máximo de líneas** (por ejemplo, con `line-clamp-2`). El nombre se
  envuelve en las líneas que necesite, sin tope.
- **Exportar "Utilidad" al CSV.** El CSV de exportación (`exportarCsv`) sigue con las mismas
  columnas de siempre; esta historia no le agrega ninguna.
- **Mostrar el porcentaje de utilidad**, del lado directo o del distribuidor. Tanto "Utilidad" como
  "Util Dist" son siempre un monto en **pesos**, nunca un porcentaje.
- **Filtro de rango sobre "Util Dist"**. Igual que "Utilidad", solo se puede ordenar.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Estado de implementación

Implementada el 2026-08-21.

- **Archivos modificados**: `app/Http/Controllers/ArticuloController.php`,
  `tests/Feature/ArticulosTest.php`, y en el frontend `frontend/src/stores/articulos.ts`
  (`ArticuloSort`) y `frontend/src/views/ArticulosListView.vue`. El estado final de estos archivos
  ya no incluye la subconsulta ni las pruebas descritas más abajo en "Corregido": se revirtieron el
  mismo día.
- **Corregido en verificación visual**: quitar la clase `truncate` de la celda de nombre no bastaba
  para que el texto envolviera. `TableCell` (`components/ui/table/TableCell.vue`) aplica
  `whitespace-nowrap` a **toda** celda por defecto desde 006, así que un nombre largo seguía en una
  sola línea y se desbordaba encima de las columnas vecinas en vez de recortarse o envolver. Se
  agregó `class="whitespace-normal"` explícito en esa `TableCell` (`ArticulosListView.vue`), que
  gana sobre la clase base porque `cn()` usa `tailwind-merge`.
- **Verificación**: Pint limpio; la suite de Pest completa pasa (599 tests, incluidas las 111 de
  `ArticulosTest.php`); ESLint y Prettier limpios; Vitest en verde (95 tests); `npm run build`
  compila la SPA completa con `vue-tsc`. **Se verificó visualmente en un navegador real**
  (Playwright/Chromium contra `php artisan serve`, `npm run dev` y un `mysqld` levantados para la
  ocasión, con un usuario, un proveedor, dos catálogos y tres artículos de prueba —uno con nombre
  largo— creados y eliminados al terminar, mismo criterio que 021/034): el filtro de catálogo
  aparece junto al buscador global y ya no dentro de la tabla; elegirlo acota el listado
  correctamente; el nombre largo se envuelve en tres líneas sin desbordar ni empujar las demás
  columnas; la tabla no genera scroll horizontal a 1440px
  (`document.body.scrollWidth === document.body.clientWidth`); y la columna "Costo" no cambió de
  formato ni de valor.
- **Corregido (reportado el mismo 2026-08-21, corregido el mismo día)**: la primera versión de la
  columna nueva mostraba un **porcentaje** de utilidad, y para poder ordenar por él el servidor
  necesitó una subconsulta correlacionada contra `catalogos`. El usuario señaló que un porcentaje
  de utilidad no dice nada por sí solo sin conocer la base sobre la que se calcula, y que lo útil es
  el **monto en pesos**. Se corrigió a la columna "Utilidad" descrita en el resto de esta spec, lo
  que de paso **simplificó** la implementación: la clave `utilidad` (precio directo menos costo
  total) ya existía en `ORDENACIONES` desde 011, calculada solo con columnas propias de
  `articulos`, así que se revirtió la subconsulta agregada para el porcentaje —ya no hace falta— y
  las tres pruebas que la cubrían se reemplazaron, porque el orden por `utilidad` ya tenía cobertura
  desde antes (caso `'utilidad'` del test parametrizado de ordenación). Verificado de nuevo con
  Pint limpio, 596 tests de Pest, ESLint/Prettier limpios, 95 tests de Vitest, `npm run build`
  limpio, y visualmente en un navegador real: la cabecera dice "Utilidad", la celda muestra "$" con
  dos decimales (por ejemplo "$0.05", igual a P Directo menos Costo), y ordena correctamente al
  hacer clic.

### Revisión del 2026-08-24: "P Dist" con IVA y la columna "Util Dist"

Implementada el 2026-08-24 (supuestos 24 a 33).

- **Archivos modificados**: backend — `app/Models/Articulo.php` (accessor `utilidadDistribuidor`),
  `app/Http/Resources/ArticuloResource.php` (`utilidad_distribuidor`),
  `app/Http/Controllers/ArticuloController.php` (`ORDENACIONES`),
  `tests/Feature/ArticulosTest.php`; frontend — `frontend/src/stores/articulos.ts` (`Articulo`,
  `ArticuloSort`) y `frontend/src/views/ArticulosListView.vue` (`columnasNumericas`, celdas de la
  fila, `colspan`).
- `precio_distribuidor_con_iva` no necesitó ningún cambio de backend: ya lo calculaba y entregaba
  desde 033; el cambio fue solo qué campo lee la celda de "P Dist".
- **Verificación**: Pint limpio; la suite de Pest completa pasa (619 tests, incluida la nueva
  variante `utilidad distribuidor` del test parametrizado de ordenación y la aserción de
  `utilidad_distribuidor` agregada al test de utilidad distribuidor propia); ESLint sin
  advertencias; Prettier limpio; Vitest en verde (95 tests); `npm run build` compila la SPA
  completa con `vue-tsc`. **Se verificó visualmente en un navegador real** (Playwright/Chromium
  contra el `php artisan serve` y el `npm run dev` que ya estaban levantados en el entorno, con un
  usuario, un proveedor, un catálogo y dos artículos de prueba —uno con utilidad distribuidor
  propia— creados y eliminados al terminar): la tabla muestra las nueve columnas en el orden
  esperado (casilla, Nombre, Modelo, Costo, P Directo, P Dist, Utilidad, Util Dist, Acciones); no
  hay barra de desplazamiento horizontal a 1440px
  (`document.body.scrollWidth === document.body.clientWidth`); "P Dist" muestra el precio
  distribuidor con IVA; "Util Dist" muestra la utilidad distribuidor en pesos; y hacer clic en la
  cabecera "Util Dist" la ordena correctamente (flecha ascendente, filas en orden creciente).

## Criterios de aceptación

1. La fila de filtros de `/articulos` ya no tiene columna "Catálogo": ni cabecera ni celda de
   filtro. La tabla tiene 9 columnas: casilla, Nombre, Modelo, Costo, P Directo, P Dist, Utilidad,
   Util Dist, Acciones.
2. El filtro de catálogo aparece junto al buscador global, arriba de la tabla, como lista
   desplegable con "Todos los catálogos" y cada catálogo del usuario.
3. Elegir un catálogo en ese filtro acota el listado de inmediato, sin esperar una pausa de tecleo,
   se combina con Y con el resto de filtros y con la búsqueda, y regresa la paginación a la página
   1.
4. El filtro de catálogo sigue contando para "Limpiar filtros" y para "N artículos con los filtros
   aplicados", igual que antes de moverse.
5. La tabla tiene una columna "Utilidad" entre "P Dist" y "Util Dist", con el monto en pesos de la
   utilidad directa de cada artículo, mostrado con "$" y dos decimales como las demás columnas de
   dinero.
6. El valor de "Utilidad" de cada artículo es igual a su precio directo **sin IVA** menos su
   "Costo", tanto si su precio viene de una utilidad propia como si viene de la utilidad del
   catálogo — no coincide con "P Directo" (que muestra el precio con IVA) menos "Costo".
7. Hacer clic en la cabecera "Utilidad" ordena el listado por ese monto, ascendente y luego
   descendente, igual que las demás columnas de dinero.
8. "Utilidad" no tiene caja de filtro en la fila de filtros: solo se puede ordenar.
9. El nombre de un artículo con texto largo se muestra completo, en dos o más líneas si hace falta,
   sin recortarse con "...".
10. La columna "Nombre" conserva el mismo ancho que tenía antes de este cambio.
11. La columna "Costo" no cambia: sigue mostrando el mismo valor (costo interno del artículo) que
    mostraba antes de esta historia.
12. En escritorio (≥1280px), con las nueve columnas y su fila de filtros, la tabla se sigue viendo
    completa **sin barra de desplazamiento horizontal**.
13. La columna "P Directo" muestra el precio con IVA incluido de cada artículo
    (`precio_unitario_con_iva`), no el precio sin IVA que mostraba antes de este cambio.
14. Ordenar por "P Directo" y filtrar por su rango en la fila de filtros siguen operando sobre el
    precio sin IVA en el servidor, con el mismo resultado de orden y de filtrado que antes de este
    cambio.
15. La columna "Utilidad" no cambia de valor tras este cambio.
16. La columna "P Dist" muestra el precio distribuidor con IVA incluido de cada artículo
    (`precio_distribuidor_con_iva`), no el precio sin IVA que mostraba antes de este cambio.
17. Ordenar por "P Dist" sigue operando sobre el precio distribuidor sin IVA en el servidor, con el
    mismo resultado de orden que antes de este cambio.
18. La tabla tiene una columna "Util Dist" entre "Utilidad" y "Acciones", con el monto en pesos de
    la utilidad que deja el precio distribuidor de cada artículo, mostrado con "$" y dos decimales
    como las demás columnas de dinero.
19. El valor de "Util Dist" de cada artículo es igual a su "P Dist" **sin IVA** menos el costo del
    artículo **sin goma** (`costo_con_descuento`), tanto si su precio distribuidor viene de una
    utilidad propia como si viene de la del catálogo.
20. Hacer clic en la cabecera "Util Dist" ordena el listado por ese monto, ascendente y luego
    descendente, igual que las demás columnas de dinero.
21. "Util Dist" no tiene caja de filtro en la fila de filtros: solo se puede ordenar.
22. Elegir un catálogo en el filtro agrega o actualiza un parámetro de catálogo en la URL de la
    pantalla; elegir "Todos los catálogos" lo quita.
23. Recargar el navegador (F5) con un parámetro de catálogo en la URL vuelve a aplicar ese filtro de
    inmediato, sin que el usuario tenga que elegirlo de nuevo.
24. Cambiar el catálogo filtrado varias veces seguidas no agrega una entrada nueva al historial del
    navegador por cada cambio: un solo "Atrás" saca de `/articulos`.
25. Un parámetro de catálogo en la URL que no existe o no pertenece al usuario se ignora: el
    listado se muestra sin filtrar ("Todos los catálogos"), sin ningún error visible.
26. Pint corre sin errores sobre el código de backend, ESLint y Prettier sobre el de frontend, la
    suite de Pest sigue pasando, y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

1. El filtro de catálogo se saca de la fila de filtros de la tabla y se coloca en la misma fila
   donde ya está el buscador global, como lista desplegable.
2. El comportamiento del filtro de catálogo no cambia en nada más que su posición en la pantalla:
   sigue recargando de inmediato, sigue regresando a la página 1, sigue contando para "Limpiar
   filtros" y para "N artículos con los filtros aplicados".
3. Al sacar la columna "Catálogo", la tabla queda en 7 columnas base antes de sumar "Utilidad".
4. La columna "Costo" que ya existe no cambia, y no se agrega ninguna columna nueva de "costo
   total": en vez de eso, la columna "P Directo" que ya existe pasa a mostrar el precio con IVA
   incluido en lugar del precio sin IVA, para que refleje lo que de verdad paga el público.
5. La columna nueva "Utilidad" muestra el monto en **pesos** de la utilidad **directa** del
   artículo (P Directo menos Costo) — no un porcentaje, y no la utilidad del distribuidor. Se
   planteó primero como porcentaje y se corrigió: un porcentaje de utilidad no dice nada por sí
   solo sin saber sobre qué base se calcula, mientras que el monto en pesos es el número que
   importa para decidir cuánto gana el negocio por artículo.
6. "Utilidad" se coloca después de "P Dist" y antes de "Acciones".
7. "Utilidad" es ordenable por su cabecera, igual que Costo, P Directo y P Dist.
8. "Utilidad" no lleva filtro de rango en la fila de filtros, solo ordenación.
9. El nombre del artículo se envuelve en las líneas que haga falta (sin límite de dos), sin cambiar
   el ancho de columna que tiene hoy.
10. Al envolver el nombre en varias líneas, la fila crece de alto para acomodarlo; el resto de
    columnas de esa fila no cambia su comportamiento.
11. Con Catálogo fuera (−1) y Utilidad dentro (+1), la tabla se queda en 8 columnas, las mismas que
    tiene hoy, así que sigue sin scroll horizontal sin necesidad de acortar ninguna cabecera más.
12. **(Adición técnica)** Ordenar por "Utilidad" no necesita ningún cambio en el servidor: la clave
    `utilidad` ya existía en `ORDENACIONES` desde antes de esta historia (agregada para otra
    pantalla), calculada solo con columnas propias de `articulos` — nunca necesitó cruzar con
    `catalogos`. Lo único que faltaba era ofrecerla como opción de orden en el tipo del frontend;
    nunca antes tuvo una cabecera clicable en esta tabla.
13. **(Adición técnica)** El monto en pesos de "Utilidad" ya viaja sin condición en la respuesta del
    listado para cada artículo (a diferencia del porcentaje efectivo, que sí dependía de que la
    relación `catalogo` estuviera cargada), así que tampoco hace falta ningún cambio en el recurso
    ni en las relaciones que carga el listado.
14. **(Adición técnica)** Mover el filtro de catálogo fuera de la tabla no toca el servidor en
    absoluto: es la misma casilla, el mismo parámetro `filtro_catalogo_id`, solo cambia su lugar en
    la pantalla.

Los supuestos 15 a 23 se agregaron en una revisión posterior, para que "P Directo" muestre el
precio con IVA y el filtro de catálogo sobreviva a un recargado de página.

15. La columna "P Directo" muestra el precio con IVA incluido (`precio_unitario_con_iva`), no el
    precio sin IVA que mostraba hasta ahora — es el precio que de verdad paga el público, el que
    hace falta para tasar precios y ver márgenes de ganancia.
16. La columna "P Dist" no cambia: sigue mostrando el precio distribuidor sin IVA, tal como hoy.
    Solo se pidió cambiar "P Directo".
17. El campo `precio_unitario_con_iva` ya lo calcula y entrega el servidor desde 033; no hace falta
    ningún cambio de backend, solo cambiar qué campo lee la celda en el frontend.
18. Ordenar por "P Directo" y filtrar por su rango siguen operando sobre el valor sin IVA en el
    servidor: no se cambia la clave de ordenación ni el filtro de rango existentes.
19. La columna "Utilidad" no cambia de cálculo (sigue siendo precio directo sin IVA menos costo
    total), aunque como consecuencia deje de coincidir a simple vista con "P Directo" (ahora con
    IVA) menos "Costo".
20. Solo se persiste el filtro de catálogo entre recargas de página; los demás filtros (Nombre,
    Modelo, rangos de precio), el orden y la página siguen reiniciándose al recargar, igual que hoy.
21. El filtro de catálogo se persiste en la URL como parámetro de consulta, no en `localStorage`,
    para que además el enlace sea compartible.
22. **(Adición técnica)** Cambiar el catálogo filtrado actualiza la URL con `router.replace()`, no
    con `router.push()`, para no acumular una entrada de historial por cada catálogo que se prueba.
23. **(Adición técnica)** Un id de catálogo en la URL que no existe o no pertenece al usuario se
    ignora: el filtro queda en "Todos los catálogos" sin mostrar ningún error, en vez de dejar la
    tabla filtrada a cero resultados sin explicación.

Los supuestos 24 a 33 se agregaron en una revisión posterior, para que "P Dist" también muestre el
precio con IVA y para agregar la utilidad que deja el precio distribuidor.

24. La columna "P Dist" deja de mostrar el precio distribuidor sin IVA y pasa a mostrar el precio
    distribuidor con IVA incluido (`precio_distribuidor_con_iva`) — el mismo cambio que el supuesto
    15 ya le había aplicado a "P Directo". No se agrega una columna aparte para el precio con IVA:
    se reemplaza el valor de la que ya existe.
25. Se agrega una columna nueva "Util Dist" que muestra, en pesos, la utilidad que deja vender al
    precio distribuidor: precio distribuidor sin IVA menos el costo del artículo **sin goma** — el
    distribuidor nunca la paga, la misma regla que ya rige para calcular su precio
    ([033](033-precio-distribuidor.md)).
26. "Util Dist" se coloca después de "Utilidad" (la directa) y antes de "Acciones".
27. "Util Dist" es ordenable por su cabecera, igual que las demás columnas de dinero, y no lleva
    caja de filtro por rango.
28. Ordenar por "P Dist" sigue ordenando por el valor sin IVA en el servidor, aunque en pantalla
    ahora se vea con IVA — mismo criterio que ya aplica a "P Directo" (supuesto 18).
29. Ninguna otra pantalla cambia: ni el formulario de artículo o catálogo, ni la ficha que se
    comparte al cliente, ni el CSV de importación/exportación. Esto es solo sobre la tabla de
    `/articulos`.
30. **(Adición técnica)** El precio distribuidor con IVA no necesita ningún cambio en el servidor:
    `precio_distribuidor_con_iva` ya lo calcula y entrega desde 033 en cada artículo del listado;
    solo cambia qué campo lee la celda en el navegador.
31. **(Adición técnica)** La utilidad distribuidor es un accessor nuevo en el backend
    (`utilidadDistribuidor`), espejo de `utilidad` pero medido contra `costo_con_descuento` en vez
    de `costo_total`, reutilizando `PrecioArticuloCalculator::utilidad()` sin cambios: la función ya
    es una resta genérica, solo cambia qué par de valores se le pasan.
32. **(Adición técnica)** `ORDENACIONES` gana la clave `utilidad_distribuidor`, paralela a
    `utilidad`, sin entrada nueva en `RANGOS` porque la columna no lleva filtro de rango.
33. **(Adición técnica)** La tabla pasa de 8 a 9 columnas; se verifica visualmente que sigue sin
    necesitar barra de desplazamiento horizontal en escritorio, y si no cabe se ajustan anchos de
    columna en vez de quitar alguna.
