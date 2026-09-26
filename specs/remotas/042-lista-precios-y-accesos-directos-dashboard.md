# Spec: Lista de precios y accesos directos de venta/cotización en la página principal

## Historia de usuario

Como usuario, quiero tener en la página principal la lista de precios que hoy solo veo en
`/articulos`, para consultarla sin tener que entrar al módulo de Inventario. También quiero un botón
de **Nueva Venta** y otro de **Nueva Cotización** ahí mismo, como accesos directos para vender o
cotizar más rápido sin pasar primero por el listado correspondiente.

## Objetivo / Alcance

Dos cambios sobre `DashboardView.vue`, únicamente en el modo normal (no mostrador, ver
[029](029-pwa-mostrador.md)):

1. Sustituir el placeholder actual (el texto "pagina de inicio") por una **lista de precios**: los
   mismos artículos de `/articulos` (Nombre, Modelo, Precio, Precio distribuidor), con buscador y
   paginación, pero de solo consulta — sin edición, selección ni acciones en lote. Al hacer clic en
   un artículo se abre la misma ficha (`ArticuloDetalleDialog`, ver
   [020](020-imagenes-articulos.md)/[033](033-precio-distribuidor.md)) que ya existe en `/articulos`,
   para verla en grande y compartirla, pero sin el botón Editar.
2. Agregar dos botones, **"Nueva Venta"** y **"Nueva Cotización"**, con la misma jerarquía visual,
   que llevan directo a `/pedidos/crear` y `/cotizaciones/crear` respectivamente (en este sistema
   "Venta" es el Pedido de mostrador, ver [027](027-venta-mostrador-ticket.md)).

El modo mostrador (`CuatroAccesos`) y la tarjeta de "Instalar aplicación" no se tocan.

## Backend (Laravel)

Ninguno. Se reutiliza tal cual `GET /api/v1/articulos`, que ya usa `/articulos`; no hay parámetros,
permisos ni endpoints nuevos.

## Frontend (Vue 3)

### `ArticuloDetalleDialog.vue`

Gana una prop `mostrarEditar?: boolean` (default `true`). Con `false`, el botón Editar no se
renderiza; el resto (foto, precio, precio distribuidor, Compartir/Compartir distribuidor) queda
igual. `ArticulosListView.vue` no pasa la prop (sigue viendo Editar, sin cambios).

### Componente nuevo: `components/ListaPreciosDashboard.vue`

Usa el mismo `useArticulosStore()` que `ArticulosListView.vue` (mismo `items`, `meta`, `search`,
`fetchList`), sin tocar `filtros` (columna/catálogo) ni `sort`, que quedan reservados a la vista
completa:

- Buscador de texto único, ligado a `store.search`, con el mismo rebote de 300ms que ya usa
  `ArticulosListView.vue` antes de llamar `fetchList(1)`.
- Tabla reducida a **Nombre, Modelo, Precio, Precio distribuidor** (con IVA, mismas etiquetas
  condicionadas a `objeto_imp` que usa `ArticuloDetalleDialog`) — sin Costo, Utilidad, checkboxes ni
  columna de Acciones.
- Paginación Anterior/Siguiente con `meta.current_page`/`meta.last_page`, igual que
  `ArticulosListView.vue`.
- El nombre de cada artículo es un botón que abre `ArticuloDetalleDialog` con `:mostrar-editar="false"`.
- Un enlace **"Ver todos"** que navega a `{ name: 'articulos' }`.

Al montarse llama `articulos.fetchList()` (sin limpiar `search`: si el usuario ya había buscado algo
y usa "Ver todos", `ArticulosListView.vue` abre con esa misma búsqueda ya aplicada, en vez de
perderla).

### `DashboardView.vue`

Reemplaza la `Card` del placeholder por:

- Una fila superior con los botones **"Nueva Venta"** (`RouterLink` a `{ name: 'pedidos-crear' }`,
  ícono `TicketIcon`) y **"Nueva Cotización"** (`RouterLink` a `{ name: 'cotizaciones-crear' }`,
  ícono `DocumentDuplicateIcon`) — mismos íconos que usa el menú principal
  (`config/navegacion.ts`) para esas mismas secciones, mismo tamaño y variante de botón entre los
  dos.
- Debajo, `<ListaPreciosDashboard />`.

La tarjeta de "Instalar aplicación" sigue después, sin cambios. `CuatroAccesos` (modo mostrador)
sigue siendo la única rama cuando `mostrador` es `true`.

## Fuera de alcance

- Resumen o top-N fijo de artículos: se pagina igual que `/articulos`, mostrando el total.
- Filtro por columna (Nombre/Modelo por separado) o por catálogo, exportar/importar CSV, subir
  imágenes, mover en lote, eliminar, o compartir la lista completa en PDF — todo eso sigue existiendo
  únicamente en `/articulos`.
- Mostrar Costo o Utilidad en este listado — es información interna que ni la ficha del artículo le
  muestra al cliente.
- Cualquier esquema de permisos por módulo: el sistema no tiene hoy control de permisos granular, así
  que la lista de precios y los dos botones son visibles para cualquier usuario autenticado, igual
  que el resto del menú.
- Cambiar el comportamiento de `/articulos` o de su modal de ficha (ahí Editar se sigue mostrando).
- Tocar el modo mostrador (`CuatroAccesos`, [029](029-pwa-mostrador.md)) o la tarjeta de "Instalar
  aplicación".

## Criterios de aceptación

1. La página principal, fuera de modo mostrador, muestra una lista de artículos con Nombre, Modelo,
   Precio y Precio distribuidor, paginada, sin Costo ni Utilidad.
2. Un buscador filtra esa lista por texto, igual que el buscador global de `/articulos`.
3. Un enlace "Ver todos" navega a `/articulos`.
4. Al hacer clic en el nombre de un artículo en la página principal se abre la ficha del artículo sin
   el botón Editar; Compartir y Compartir distribuidor siguen funcionando igual.
5. En `/articulos`, la misma ficha se sigue abriendo con el botón Editar visible, sin cambios.
6. La página principal muestra los botones "Nueva Venta" y "Nueva Cotización" con la misma jerarquía
   visual entre ambos.
7. "Nueva Venta" navega a `/pedidos/crear`; "Nueva Cotización" navega a `/cotizaciones/crear`.
8. El modo mostrador (`CuatroAccesos`) sigue funcionando exactamente igual: no muestra la lista de
   precios ni los botones nuevos.
9. ESLint/Prettier corren sin errores y `npm run build` compila sin errores de tipos sobre el código
   nuevo.

## Supuestos asumidos (registro completo)

1. "Página principal" es el Dashboard general (`/dashboard`) fuera de modo mostrador; el modo
   mostrador (`CuatroAccesos`) no se modifica.
2. La lista de precios reutiliza los mismos datos y el mismo store (`useArticulosStore`) que ya usa
   `/articulos`, mostrando menos columnas (Nombre, Modelo, Precio, Precio distribuidor) y sin Costo
   ni Utilidad.
3. La lista es de solo lectura: sin edición, eliminación, selección, acciones en lote, exportar,
   importar ni compartir la lista completa en PDF.
4. Incluye buscador de texto y paginación igual que `/articulos`, mostrando el total de artículos, no
   un top-N fijo.
5. Al hacer clic en un artículo se abre el mismo modal de ficha (`ArticuloDetalleDialog`) que ya
   existe, reutilizado, con Compartir y Compartir distribuidor disponibles.
6. En este contexto (página principal) el modal oculta el botón Editar; en `/articulos` se sigue
   mostrando igual que hoy.
7. Un enlace "Ver todos" lleva a la vista completa `/articulos` para quien necesite las funciones
   avanzadas.
8. El botón "Nueva Venta" crea un Pedido de mostrador (en este sistema "Venta" es el Pedido) y
   navega a `/pedidos/crear`.
9. El botón "Nueva Cotización" navega a `/cotizaciones/crear`.
10. Ambos botones se muestran con la misma jerarquía visual, arriba en la página principal, sin que
    uno domine sobre el otro.
11. No se agrega ningún control de permisos nuevo: el sistema no tiene hoy un esquema de permisos
    granular por módulo, así que estos accesos son visibles para cualquier usuario autenticado, igual
    que el resto del menú.
12. Este contenido nuevo sustituye por completo el placeholder actual ("pagina de inicio"); la
    tarjeta de "Instalar aplicación" se mantiene sin cambios.
