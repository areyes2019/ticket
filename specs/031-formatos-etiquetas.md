# Spec: Formatos de planilla para las etiquetas de producción

> **Estado: implementada** el 2026-10-06, el mismo día en que se definió con el usuario. Ver "Estado de
> implementación".

> **Corrección 1 (2026-10-07, implementada):** la letra de la etiqueta se escala con el alto. Reemplaza la
> asunción 12. Ver la sección "Corrección 1".

**Modifica:** [030-etiquetas-produccion.md](030-etiquetas-produccion.md). La planilla deja de ser
fija (60 × 30 mm, 3 × 8, centrada y sin separación): el usuario ajusta el tamaño de la etiqueta, la
separación entre etiquetas y los márgenes de la hoja, y guarda esas medidas como **formatos** con
nombre, para usar planillas de distintas marcas y modelos. Con los valores de fábrica la planilla
queda igual que en 030.

**No modifica:** el contenido de la etiqueta (030), el ajuste de letra (030, adición 2), la hoja de
producción (022) ni la etiqueta individual de la venta (019).

## Historia de usuario

Como usuario, quiero controles para hacer la etiqueta más alta, más baja, más ancha o más angosta, y
para abrir o cerrar el espacio entre etiquetas a los lados y arriba y abajo, para ajustar la
planilla a los formatos de varias marcas y modelos de hojas de etiquetas.

## Medidas

Seis medidas, todas en **milímetros con un decimal** (paso 0.1):

| Campo | Nombre en la petición | Fábrica | Mínimo | Máximo |
|---|---|---|---|---|
| Ancho de la etiqueta | `ancho` | 60.0 | 10.0 | 215.9 |
| Alto de la etiqueta | `alto` | 30.0 | 10.0 | 279.4 |
| Separación entre columnas | `separacion_horizontal` | 0.0 | 0.0 | 50.0 |
| Separación entre renglones | `separacion_vertical` | 0.0 | 0.0 | 50.0 |
| Margen superior | `margen_superior` | 19.7 | 0.0 | 100.0 |
| Margen izquierdo | `margen_izquierdo` | 17.9 | 0.0 | 100.0 |

Los márgenes de fábrica son los que centran la rejilla de 3 × 8 de 030 (redondeados hacia abajo a
0.1 mm), así que **sin formatos la planilla sale igual que hoy**.

### Distribución en la hoja carta (215.9 × 279.4 mm)

El sistema calcula cuántas columnas y renglones caben (asunción 5); no se capturan:

```
columnas  = piso((215.9 − margen_izquierdo + separacion_horizontal) / (ancho + separacion_horizontal))
renglones = piso((279.4 − margen_superior  + separacion_vertical)   / (alto  + separacion_vertical))
por_hoja  = columnas × renglones
```

Con una tolerancia de 0.001 mm contra el ruido de punto flotante. La primera etiqueta va en
(`margen_izquierdo`, `margen_superior`); las demás se recorren con `ancho + separacion_horizontal` y
`alto + separacion_vertical`. No se exige margen derecho ni inferior: el área que la impresora no
alcanza es cosa de la configuración de impresión.

Ejemplo: 66.7 × 25.4 mm, separación 3.2 / 0, márgenes 12.7 / 4.8 → 3 columnas × 10 renglones = 30.

Si `por_hoja` es 0, la página muestra el aviso **"Con estas medidas no cabe ninguna etiqueta en la
hoja carta."** y no pinta la planilla (asunción 16).

### Centrar (adición 2)

El botón **"Centrar"** calcula los dos márgenes para que el bloque de etiquetas que se ve ahora
(las columnas y renglones de las medidas actuales) quede en medio de la hoja. Si con las medidas
actuales no cabe nada, centra lo que cabría con margen 0:

```
bloque_ancho     = columnas × ancho + (columnas − 1) × separacion_horizontal
margen_izquierdo = piso_a_0.1((215.9 − bloque_ancho) / 2)
```

y lo mismo a lo alto. Solo llena los dos campos: el usuario puede corregirlos después.

## Formatos guardados

Un formato es un nombre más las seis medidas, guardado **en la cuenta del usuario** (asunción 9).

- El nombre es obligatorio, de hasta 60 caracteres, y **único por usuario**.
- **Uno solo** puede ser el predeterminado (asunción 10). Marcar uno desmarca los demás. Si se borra
  el predeterminado, no queda ninguno y se usan los valores de fábrica.
- No hay formatos precargados (asunción 11).
- **Duplicar (adición 3):** copia el formato con el nombre "Nombre (copia)". Si ese nombre ya
  existe, usa "Nombre (copia 2)", "(copia 3)", etc. La copia no es predeterminada y queda elegida en
  la página, lista para cambiarle nombre y medidas.

### Qué medidas se usan al abrir la página

En este orden, la primera que exista:

1. Las medidas que vienen en la dirección (después de "Aplicar" o de cambiar algo). Una medida
   ausente o fuera de rango se toma del paso siguiente, sin error.
2. El formato indicado en `formato` (si es del usuario).
3. El formato predeterminado.
4. Los valores de fábrica.

## Backend (Laravel)

### Migración `2026_10_15_100000_create_formatos_etiqueta_table.php`

`formatos_etiqueta`:

- `id`, `user_id` (FK, `cascadeOnDelete`), `nombre` `string(60)`.
- `ancho_mm`, `alto_mm`, `separacion_horizontal_mm`, `separacion_vertical_mm`,
  `margen_superior_mm`, `margen_izquierdo_mm`: `decimal(5,1)`.
- `es_predeterminado` `boolean` default `false`.
- `timestamps`. Índice único (`user_id`, `nombre`).

### Modelo `FormatoEtiqueta` (nuevo)

- `user()` y en `User`, `formatosEtiqueta()` (`hasMany`, ordenados por nombre).
- `marcarPredeterminado()`: en una transacción, desmarca los demás del usuario y marca este.
- `duplicar(): self`: la copia con el nombre libre siguiente.
- `medidas(): MedidasPlanilla`.

### `App\Services\Etiquetas\MedidasPlanilla` (nuevo, valor inmutable)

Las seis medidas más `columnas()`, `renglones()`, `porHoja()`, `cabe()` y `centrada(): self`.
`MedidasPlanilla::fabrica()` y `MedidasPlanilla::desdePeticion(array $datos, self $base)` (cada
medida válida reemplaza la de `$base`; las inválidas se ignoran).

Las cuentas tienen su espejo en JavaScript (ver abajo). Las dos se prueban contra el mismo archivo
`tests/Fixtures/planillas-etiquetas.json` (medidas → columnas, renglones y márgenes centrados), igual
que los precios (`precios-articulos.json`).

### Rutas

Dentro del grupo autenticado, junto a `pedidos.produccion.etiquetas`:

```php
Route::post('pedidos/produccion/etiquetas', [EtiquetasProduccionController::class, 'aplicar'])
    ->name('pedidos.produccion.etiquetas.aplicar');
Route::post('formatos-etiqueta', [FormatoEtiquetaController::class, 'store'])->name('formatos-etiqueta.store');
Route::post('formatos-etiqueta/{formatoEtiqueta}', [FormatoEtiquetaController::class, 'update'])->name('formatos-etiqueta.update');
Route::post('formatos-etiqueta/{formatoEtiqueta}/duplicar', [FormatoEtiquetaController::class, 'duplicar'])->name('formatos-etiqueta.duplicar');
Route::delete('formatos-etiqueta/{formatoEtiqueta}', [FormatoEtiquetaController::class, 'destroy'])->name('formatos-etiqueta.destroy');
```

`EtiquetasProduccionController` deja de ser invocable: `index` (el GET de 030, con el mismo nombre
de ruta `pedidos.produccion.etiquetas`) y `aplicar`.

### `EtiquetasProduccionController`

- **`index`:** resuelve las medidas (ver "Qué medidas se usan"), reparte las casillas en hojas de
  `por_hoja` y pasa a la vista las medidas, los formatos del usuario, el formato elegido, `inicio` y,
  si `prueba=1`, el modo de prueba.
  - `inicio` acepta de 1 a `por_hoja`; fuera de eso, 1 (asunción 6).
  - **Modo de prueba (adición 1):** una sola hoja con las `por_hoja` casillas vacías, cada una con
    su número (1, 2, 3…) centrado, sin datos de pedidos y aunque no haya órdenes en proceso. Esta
    vista sí se imprime sola al cargar (`imprimir-al-cargar.js`).
- **`aplicar`:** valida las seis medidas con `MedidasPlanillaRequest` y redirige al GET con ellas en
  la dirección (más `formato`, `inicio` y `prueba` si vienen). Con errores, regresa a la página con
  los mensajes junto a cada campo. Si viene `centrar=1`, redirige con los márgenes centrados.

### `FormatoEtiquetaController` (nuevo)

Todas las acciones redirigen a `pedidos.produccion.etiquetas` con `formato={id}` del formato
guardado, y un mensaje: "Formato guardado.", "Formato duplicado." o "Formato eliminado.".

- `store`: crea con `nombre`, las seis medidas y `es_predeterminado`.
- `update`: reemplaza nombre, medidas y `es_predeterminado` del formato elegido.
- `duplicar`: `FormatoEtiqueta::duplicar()`.
- `destroy`: lo borra y redirige sin `formato` (se usará el predeterminado o los de fábrica).

Validación (`FormatoEtiquetaRequest`, extiende las reglas de `MedidasPlanillaRequest`): `nombre`
`required|string|max:60` y único por usuario (ignorando el propio en `update`);
`es_predeterminado` `boolean`.

### Autorización: `FormatoEtiquetaPolicy` (nueva)

`update`, `duplicar` y `delete` solo para el dueño. Un formato ajeno responde **404**, igual que
`CuentaPolicy`. Un `formato` ajeno en la dirección del GET se ignora.

## Vista: `ordenes-trabajo/etiquetas`

### Barra (solo pantalla)

Ordenada en tres grupos:

1. **Formato:** un `select` con "Medidas de fábrica" más los formatos del usuario (el
   predeterminado con "★"). Al cambiarlo, la página se recarga con `formato={id}` (sin JavaScript,
   un botón "Cargar" de respaldo). Junto al select, **"Duplicar"** y **"Eliminar"** (este con
   confirmación), solo si hay un formato elegido. Son formularios propios, fuera del de medidas.
2. **Medidas:** un formulario `POST` con los seis campos numéricos (`step="0.1"`, con su mínimo y
   máximo), el botón **"Centrar"**, el nombre del formato, la casilla **"Predeterminado"** y
   "Empezar en la etiqueta" (máximo = `por_hoja`). Botones del formulario, cada uno con su
   `formaction`:
   - **"Aplicar"** → `aplicar` (solo hace falta sin JavaScript);
   - **"Guardar"** → `update` del formato elegido (solo si hay uno);
   - **"Guardar como nuevo"** → `store`;
   - **"Imprimir prueba"** → `aplicar` con `prueba=1`.
3. **Resumen:** "7 órdenes en proceso · 3 × 8 = 24 por hoja · 1 hoja", el botón **"Imprimir"** y,
   si no cabe nada, el aviso de la asunción 16 en lugar de la planilla.

Todos los controles usan `<x-campo>` y `<x-boton>`, como exige `EstiloUniformeTest`.

### Planilla

- La hoja sigue con `@page { size: letter; margin: 0; }`.
- La rejilla se dibuja con variables CSS en `.planilla-hoja`: `--ancho`, `--alto`, `--sep-h`,
  `--sep-v`, `--margen-sup`, `--margen-izq`, `--columnas`, `--renglones` (en mm). Ya no se centra:
  `padding` con los márgenes, `gap` con las separaciones y `justify-content: start`.
- Cada hoja lleva `por_hoja` casillas; las demás reglas de 030 (casillas vacías por "Empezar en la
  etiqueta", borde de corte siempre visible, cinco renglones) siguen igual.
- La letra no cambia de tamaño base con la etiqueta (asunción 12). Si la etiqueta es tan baja que
  no caben los cinco renglones, los de abajo se cortan (asunción 13): `overflow: hidden` en la
  etiqueta, como hoy.

## JavaScript: `public/js/etiquetas-produccion.js`

Se le agrega, además del ajuste de letra de 030:

- **Funciones puras**, espejo de `MedidasPlanilla` y probadas contra el mismo JSON:
  `distribucion(medidas)` → `{ columnas, renglones, porHoja }` y `centrar(medidas)` → márgenes.
- **Vista previa en vivo (asunción 7):** al escribir en cualquiera de los seis campos o en
  "Empezar en la etiqueta", recalcula la distribución, actualiza las variables CSS, **reparte de
  nuevo las etiquetas en hojas** (mueve los mismos nodos, sin pedir nada al servidor), actualiza el
  resumen y el máximo de "Empezar en la etiqueta", muestra u oculta el aviso de "no cabe" y vuelve a
  correr el ajuste de letra. Actualiza la dirección con `history.replaceState`, así "Imprimir" y
  recargar conservan las medidas. Un valor vacío o fuera de rango no se aplica hasta que sea válido.
- **"Centrar":** llena los dos márgenes al momento, sin enviar el formulario. Sin JavaScript, el
  botón envía `aplicar` con `centrar=1`.
- El `select` de formato recarga la página al cambiar.

## Pruebas

`tests/Unit/MedidasPlanillaTest.php`: recorre `planillas-etiquetas.json` (incluye fábrica = 3 × 8,
un formato con separaciones, uno donde no cabe nada y uno en el límite exacto de la hoja).

`tests/js/etiquetas-produccion.test.js`: `distribucion()` y `centrar()` contra el mismo JSON.

`tests/Feature/FormatosEtiquetaTest.php`:

1. Sin formatos, la planilla sale con 24 por hoja y los márgenes de fábrica (030 sigue verde).
2. Guardar como nuevo crea el formato; un nombre repetido o vacío da error de validación.
3. Guardar actualiza nombre y medidas; marcar predeterminado desmarca el anterior.
4. Al abrir sin `formato`, se usa el predeterminado; con `formato`, ese.
5. Las medidas en la dirección mandan sobre el formato; una medida inválida se ignora.
6. Duplicar crea "X (copia)" y luego "X (copia 2)", no predeterminados.
7. Eliminar el predeterminado deja los valores de fábrica.
8. Un formato ajeno responde 404 en `update`, `duplicar` y `destroy`, y se ignora en el GET.
9. Medidas con `por_hoja` = 0 muestran el aviso y no pintan la planilla.
10. 25 por hoja con 30 órdenes hacen 2 hojas; `inicio` mayor que `por_hoja` se toma como 1.
11. `prueba=1` pinta una hoja con las casillas numeradas de 1 a `por_hoja`, sin datos de pedidos,
    incluso sin órdenes, y carga `imprimir-al-cargar.js`.
12. `aplicar` con `centrar=1` redirige con los márgenes centrados.
13. Invitados redirigidos al login en todas las rutas nuevas.

## Fuera de alcance

- Otros tamaños de hoja además de carta (asunción 14).
- Formatos de fábrica de marcas comerciales (asunción 11).
- Cambiar el tamaño base de la letra o el contenido de la etiqueta (asunciones 12 y 13).
- Ocultar el borde de corte (asunción 15).
- Columnas o renglones capturados a mano (asunción 5).
- Compartir formatos entre usuarios.

## Criterios de aceptación

1. En la página de etiquetas se pueden cambiar ancho, alto, separaciones y márgenes en milímetros, y
   la vista previa cambia al momento.
2. El sistema calcula solo cuántas etiquetas caben por hoja y avisa si no cabe ninguna.
3. "Centrar" llena los márgenes para que el bloque quede en medio de la hoja.
4. Se pueden guardar, actualizar, duplicar y eliminar formatos con nombre, y uno queda como
   predeterminado al abrir la página, en cualquier computadora.
5. "Imprimir prueba" imprime solo los rectángulos numerados, sin datos.
6. Sin formatos guardados, la planilla sale igual que en 030.
7. `php artisan test`, `pint` y `node --test "tests/js/*.test.js"` en verde.

## Supuestos asumidos (registro completo)

Revisados con el usuario el 2026-10-06. Se aprobaron todos sin cambios.

1. Cuatro controles en la barra: ancho, alto, separación entre columnas y separación entre
   renglones.
2. En milímetros, con decimales de 0.1 mm.
3. Valores iniciales: 60 × 30 mm, separación 0 (como hoy).
4. Dos campos más: margen superior y margen izquierdo de la hoja.
5. Columnas y renglones se calculan solos con lo que cabe en la hoja carta.
6. "Empezar en la etiqueta" sigue, con máximo según las etiquetas por hoja.
7. La vista previa se actualiza al momento, sin "Aplicar".
8. Las medidas se guardan como formatos con nombre y se eligen de una lista.
9. Los formatos se guardan en el sistema, en la cuenta del usuario.
10. Un formato se marca como predeterminado y se carga al abrir la página.
11. No hay formatos de marcas precargados.
12. El tamaño base de la letra no cambia con la etiqueta; el ajuste de letra (mínimo 7 pt) sigue.
13. Mismos 5 datos; en una etiqueta muy baja se cortan los renglones que no quepan.
14. Solo hoja carta.
15. El borde de corte sigue siempre visible.
16. Si no cabe ninguna etiqueta, se avisa y no se pinta la planilla.

Decididos al redactar, sin revisión una por una:

- Mínimos y máximos de cada medida (tabla de "Medidas").
- Márgenes de fábrica 19.7 / 17.9 mm, para que sin formatos la planilla quede igual que en 030.
- Sin margen derecho ni inferior obligatorio.
- Prioridad de las medidas: dirección → `formato` → predeterminado → fábrica.
- El select ofrece también "Medidas de fábrica".
- Los formatos se ordenan por nombre.

### Adiciones técnicas

1. **Hoja de prueba:** aceptada. "Imprimir prueba" imprime solo los rectángulos vacíos, numerados.
2. **Centrar automáticamente:** aceptada. Botón "Centrar" que llena los márgenes, editables después.
3. **Duplicar un formato:** aceptada. Copia con el nombre "(copia)", lista para editar.

## Estado de implementación

Implementada el 2026-10-06.

- `App\Services\Etiquetas\MedidasPlanilla` cuenta en **décimas de milímetro** (enteros), así las
  divisiones son exactas y no hace falta la tolerancia de 0.001 mm. Su espejo en
  `public/js/etiquetas-produccion.js` (`distribucion()`, `centrar()`) y los dos recorren
  `tests/Fixtures/planillas-etiquetas.json`.
- **Diferencias con lo redactado:**
  - "Centrar" centra el bloque de columnas y renglones **que se ve ahora**, no el que cabría con
    margen 0. Así, con las medidas de fábrica, "Centrar" deja la misma planilla de 3 × 8 en vez de
    pasar a 3 × 9. Solo si no cabe nada usa lo que cabría con margen 0.
  - La edición de un formato es `POST formatos-etiqueta/{formatoEtiqueta}`, no `PUT`: "Guardar"
    sale del mismo formulario `POST` de medidas con `formaction`, y un `_method` oculto afectaría a
    todos sus botones.
  - "Aplicar" se ve siempre y es el primer botón del formulario, para que Enter no dispare
    "Centrar" ni "Guardar".
  - "Imprimir prueba" abre la hoja de prueba en otra pestaña (`formtarget="_blank"`).
  - La página no carga `app.js` (necesita Axios), así que la confirmación de "Eliminar" vive en
    `etiquetas-produccion.js`.
- `FormatoEtiqueta` (modelo), `FormatoEtiquetaPolicy`, `MedidasPlanillaRequest`,
  `FormatoEtiquetaRequest`, `FormatoEtiquetaController` y la migración
  `2026_10_15_100000_create_formatos_etiqueta_table`.
- Pruebas: `tests/Unit/MedidasPlanillaTest.php`, `tests/Feature/FormatosEtiquetaTest.php` y
  `tests/js/etiquetas-produccion.test.js`. En `EtiquetasProduccionTest` (030) cambió el texto del
  resumen ("3 × 8 = 24 por hoja · 1 hoja") y la página sin órdenes ahora carga el JavaScript. Suite
  completa, `pint` y `node --test` en verde.
- Revisado en Chrome sin interfaz: un formato de 66.7 × 25.4 mm (3 × 10), la hoja de prueba
  numerada, y la vista previa en vivo con "Centrar" (actualiza la planilla, el resumen y la
  dirección).
- **Al desplegar hay que correr la migración** (no usar `--sin-migrar`).

## Corrección 1: la letra se ajusta al alto de la etiqueta

### Historia de usuario

Como usuario, quiero que el tamaño de la letra se ajuste al tamaño de la etiqueta.

### Qué cambia

Reemplaza la asunción 12 ("la letra no cambia de tamaño base con la etiqueta"). Ahora los cinco
renglones crecen o se achican con el **alto** de la etiqueta, en la misma proporción entre ellos.

```
escala_letra = max(0, alto − 4 mm) / 26 mm
```

Los 4 mm son la orilla interior (2 mm arriba y 2 mm abajo). Con 30 mm de alto la escala es **1**, y
la letra queda como en 030: ticket 14 pt, saldo 11 pt, y cliente, teléfono y modelo 10 pt. Ejemplos:
25.4 mm → 0.8231; 50 mm → 1.7692.

- Cada renglón mide `max(7 pt, tamaño_base × escala_letra)`: nunca baja de 7 pt (asunción 4). En
  una etiqueta muy baja, los renglones de abajo se cortan.
- No hay tope (asunción 5).
- Después de escalar, el ajuste al ancho de 030 sigue igual: el renglón que no cabe a lo ancho se
  achica hasta 7 pt y, si aun así no cabe, se corta con "…".
- El número de cada rectángulo de la hoja de prueba también se escala (14 pt × escala).
- La vista previa en vivo actualiza la escala al cambiar el alto.
- No se guarda nada nuevo en los formatos: la escala se calcula de su alto.

### Implementación

- `MedidasPlanilla::escalaLetra(): float` y su espejo `escalaLetra(medidas)` en
  `etiquetas-produccion.js`, en décimas de milímetro: `max(0, alto − 40) / 260`.
- `planillas-etiquetas.json` gana `escala_letra` (cuatro decimales) en cada caso; las dos pruebas lo
  comparan.
- La vista pasa la escala como `--escala` en `#planilla` y los tamaños usan
  `max(7pt, calc(10pt * var(--escala)))` (14 pt y 11 pt en ticket y saldo; 14 pt en la prueba).

### Supuestos de la corrección

Revisados con el usuario el 2026-10-07. Se aprobaron todos sin cambios.

1. La letra se ajusta al **alto** de la etiqueta, para que los 5 renglones la llenen.
2. Se mantiene la proporción entre renglones (14 / 11 / 10 pt a 30 mm).
3. Si al crecer un renglón no cabe a lo ancho, se achica solo, como hoy.
4. Nunca baja de 7 pt; en una etiqueta muy baja, los renglones de abajo se cortan.
5. Sin tope de crecimiento.
6. Con 30 mm de alto queda igual que hoy.
7. La vista previa muestra el ajuste al momento.
8. El número de la hoja de prueba también se escala.
9. No se guarda nada nuevo en los formatos.

Sin adiciones técnicas.

### Estado de implementación de la corrección 1

Implementada el 2026-10-07 tal como está escrita. Suite completa, `pint` y `node --test` en verde.
Revisado en Chrome sin interfaz: 100 × 50 mm (escala 1.7692), 60 × 20 mm (la letra queda en el
mínimo de 7 pt y caben los cinco renglones) y la vista previa en vivo al pasar el alto a 40 mm
(escala 1.3846, ticket a 19.4 pt). El nombre largo se sigue achicando a lo ancho.
