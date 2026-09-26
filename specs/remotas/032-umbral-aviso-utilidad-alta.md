# Spec: El aviso de utilidad alta empieza en 400%

## Historia de usuario

Como usuario que vende artículos con márgenes de tres cifras, quiero capturar una utilidad de 250%
o 300% sin que el formulario me marque en ámbar que revise lo que escribí, porque esos porcentajes
son mi operación normal y no un error de captura.

## Objetivo / Alcance

Una sola cosa: **el umbral del aviso de utilidad alta pasa de 200% a 400%**, en las dos pantallas
donde se captura un porcentaje de utilidad —el formulario de artículo y el de catálogo—.

Todo lo demás del aviso se queda igual: sigue siendo un texto ámbar que aparece debajo del campo,
sigue diciendo por cuánto se multiplica el costo, y sigue **sin impedir que se guarde**.

El límite duro de **999.99%** no se toca. Es una regla de validación del backend, distinta del
aviso: por encima de ese número el sistema rechaza y no guarda, y esa red se conserva porque es la
que atrapa el dedazo de teclear 10000 en lugar de 1000.

### El problema que resuelve

El aviso se definió en [011](011-precio-proveedor-utilidad.md) con un umbral de 200% bajo el
supuesto de que a partir de ahí es más probable un cero de más que un markup real. Ese supuesto no
describe este negocio: los márgenes de 200% a 400% son cotidianos, así que el aviso salta en la
captura normal y no en el error.

Un aviso que aparece cuando todo está bien deja de leerse. El costo no es la molestia visual sino que
el día que de verdad se teclee un cero de más, el ámbar ya no significará nada porque se habrá visto
cientos de veces sobre porcentajes correctos.

Nada de esto bloqueaba el trabajo: el aviso nunca impidió guardar, y hoy mismo un 350% se captura y
se registra sin problema. Lo que se corrige es **cuándo** avisa, no **si** avisa.

## Frontend (Vue 3)

### El umbral se declara una vez, en `lib/`

Hoy la constante está escrita dos veces, una en cada formulario:

- [ArticuloFormView.vue:146](../frontend/src/views/ArticuloFormView.vue#L146)
- [CatalogoFormView.vue:49](../frontend/src/views/CatalogoFormView.vue#L49)

Pasa a `frontend/src/lib/precioArticulo.ts`, que es donde ya vive todo lo que sabe interpretar un
porcentaje de utilidad:

```ts
export const UMBRAL_PORCENTAJE_ALTO = 400

export function porcentajeUtilidadAlto(utilidadPorcentaje: number): boolean {
  return utilidadPorcentaje > UMBRAL_PORCENTAJE_ALTO
}
```

Los dos formularios importan la función y borran su constante local. El valor deja de estar
duplicado, que es lo que permitiría que mañana uno de los dos quedara en 400 y el otro en 200 sin que
nadie lo note.

`porcentajeUtilidadAlto` recibe un número y **un valor no numérico devuelve `false`**: mientras el
usuario teclea, `parseFloat('')` da `NaN`, y un aviso que parpadea sobre un campo a medio escribir es
ruido. La comparación es estrictamente **mayor que**, no mayor o igual: 400% exacto no avisa.

En el formulario de artículo se evalúa sobre la **utilidad efectiva** —la del artículo si la tiene,
si no la heredada del catálogo—, igual que hoy. En el de catálogo, sobre el valor del campo.

### El texto del aviso no cambia

Sigue siendo el mismo, con el mismo color ámbar y en el mismo lugar:

> Una utilidad del 450% multiplica el costo por 5.50. Verifica que sea el valor que querías.

No se convierte en diálogo de confirmación, ni en casilla de "sí, estoy seguro", ni en error. Es
exactamente lo que era, disparándose 200 puntos más arriba.

## Backend (Laravel)

**Sin cambios.** El aviso nunca existió en el backend: `utilidad_porcentaje` se valida con
`gte:0 | lte:999.99 | decimal:0,2` en `StoreArticuloRequest`, `UpdateArticuloRequest`,
`StoreCatalogoRequest`, `UpdateCatalogoRequest`, `ArticuloController::importar` y
`CatalogoProveedorController`, y esas seis reglas quedan tal cual.

El atributo `max="999.99"` de los dos campos también se queda: refleja el límite duro, no el umbral
del aviso.

## Fuera de alcance

- **Cambiar el límite duro de 999.99%.** Sigue siendo el tope que el backend rechaza.
- **Hacer el umbral configurable** desde la pantalla de Configuración. Es un número de negocio que
  cambia cada varios años; un ajuste con su columna, su formulario y su carga al arrancar cuesta más
  de lo que ahorra.
- **Quitar el aviso por completo.** Se conserva: sigue siendo la única defensa contra el cero de más
  dentro del rango que el backend acepta.
- **Convertirlo en bloqueo o en confirmación.** Sigue sin impedir guardar.
- **Tocar el cálculo de precios.** Descuento, goma, markup y redondeo al peso entero
  ([024](024-precios-sin-centavos.md)) quedan idénticos.
- **Recalcular artículos existentes.** Ninguna fila de la base de datos se toca: esto es un cambio de
  lo que se muestra en pantalla.
- **Avisos de utilidad en otras pantallas** —listado de artículos, mostrador, aumento masivo de
  costos ([021](021-mantenimiento-articulos-catalogos.md))—, que hoy no muestran ninguno y siguen sin
  mostrarlo.
- **Un aviso por utilidad demasiado baja** o negativa. La validación ya impide negativos y esta
  historia no agrega el extremo contrario.

## Criterios de aceptación

1. En el formulario de artículo, capturar una utilidad de 250% no muestra ningún aviso ámbar.
2. Lo mismo con 300% y con 400% exacto: el aviso aparece sólo **por encima** de 400.
3. Capturar 450% sí muestra el aviso, con el texto y el color de siempre.
4. En el formulario de catálogo rigen los mismos tres puntos anteriores: 250% y 400% callados, 450%
   con aviso.
5. Un artículo sin utilidad propia hereda la del catálogo, y el aviso se decide sobre esa utilidad
   heredada: si el catálogo está en 450%, el artículo que la hereda muestra el aviso.
6. El aviso sigue sin impedir guardar: con 450% capturado, el botón de guardar funciona y el
   artículo queda registrado con ese porcentaje.
7. Capturar más de 999.99% sigue siendo rechazado por el backend con su mensaje de validación, como
   antes de esta historia.
8. El campo vacío o a medio escribir no muestra el aviso ni ningún error de cálculo.
9. El precio que calcula el formulario para un mismo porcentaje es idéntico al de antes de esta
   historia: no cambia ningún importe.
10. El umbral está declarado en un solo lugar del código; los dos formularios lo consumen de ahí.
11. `npm run build` compila la SPA completa con `vue-tsc`, ESLint y Prettier quedan limpios, y la
    suite de Vitest pasa incluyendo las pruebas nuevas de `porcentajeUtilidadAlto`.
12. La suite de Pest sigue pasando sin modificaciones, porque el backend no se tocó.

## Supuestos asumidos (registro completo)

1. Lo que molesta es el mensaje, no un bloqueo: el sistema ya permitía guardar por encima del 200%.
2. El aviso se conserva; lo que cambia es a partir de qué porcentaje aparece.
3. El nuevo umbral es 400%, elegido por el usuario sobre las alternativas de 300%, 500% y 1000%.
4. El cambio aplica a las dos pantallas por igual: artículo y catálogo comparten el número.
5. El límite duro de 999.99% se queda como está.
6. El porcentaje conserva su significado de markup sobre el costo: 400% es el costo multiplicado
   por 5.
7. El cálculo de precios no cambia en ningún eslabón.
8. Los artículos ya guardados no se tocan ni se recalculan.
9. El aviso no se reemplaza por un diálogo de confirmación ni por una casilla de aceptación.
10. La línea que dice por cuánto se multiplica el costo sigue siendo parte del aviso, no un dato
    informativo permanente aparte.
11. Ninguna otra pantalla del sistema muestra este aviso, así que no hay nada más que ajustar.
12. **(Adición técnica)** La constante deja de estar duplicada en los dos formularios y pasa a
    `lib/precioArticulo.ts`, junto con la función que decide si un porcentaje es alto. Con el valor
    escrito dos veces, un cambio futuro aplicado a la mitad de los lugares es un error que nadie
    detecta hasta que alguien compara las dos pantallas.
13. **(Adición técnica)** Esa función se cubre con pruebas de Vitest en `precioArticulo.test.ts`. Los
    formularios `.vue` no tienen pruebas de componente en este proyecto, así que sacar la regla a
    `lib/` es también lo que la vuelve verificable de forma automática.
14. **(Adición técnica)** La función devuelve `false` ante un valor no numérico, para que el aviso no
    parpadee mientras se teclea.
15. **(Adición técnica)** La especificación [011](011-precio-proveedor-utilidad.md) se actualiza en
    sus cuatro menciones del umbral. Un documento que describa un comportamiento que el sistema ya no
    tiene induce al error a quien lo lea después, incluidos nosotros mismos.

## Estado de implementación

Implementada el 2026-08-17.

- **Archivos modificados**: `frontend/src/lib/precioArticulo.ts` (la constante
  `UMBRAL_PORCENTAJE_ALTO` y la función `porcentajeUtilidadAlto`),
  `frontend/src/lib/precioArticulo.test.ts`, `frontend/src/views/ArticuloFormView.vue`,
  `frontend/src/views/CatalogoFormView.vue` y `specs/011-precio-proveedor-utilidad.md`.
- **El backend no se tocó**, como anticipaba la spec: el aviso siempre vivió en el navegador y las
  seis reglas `lte:999.99` quedaron intactas.
- **`porcentajeUtilidadAlto` filtra con `Number.isFinite`**, no sólo con `Number.isNaN`. Un campo
  vacío da `NaN`, pero la guarda cubre además `Infinity`, que es lo que devuelve `parseFloat` de una
  entrada como `1e999`: sin ella, el único valor que jamás debería pasar callado —un número que se
  salió de escala— habría entrado por la puerta de al lado.
- **Prettier reformateó de paso dos renglones del resumen de precios** en `ArticuloFormView.vue`
  (dos `<dd>` que colapsan a una línea). El archivo ya venía sin formatear en `main`, así que la
  corrección es anterior a esta historia y no toca comportamiento.
- **Verificación**: `npm run build` compila con `vue-tsc`, ESLint sin advertencias, Prettier limpio
  sobre los cuatro archivos, Vitest 89 tests en verde —9 aserciones nuevas repartidas en 3 pruebas
  de `porcentajeUtilidadAlto`— y la suite de Pest completa pasa (570 tests, 824 885 aserciones),
  confirmando que el backend quedó igual.

### Pendiente de verificación visual

**No se abrió el navegador** (misma limitación de entorno que el resto de las historias) — falta
confirmar en pantalla que capturar 300% en el formulario de artículo no pinta el aviso ámbar y que
450% sí lo pinta, y lo mismo en el de catálogo.
