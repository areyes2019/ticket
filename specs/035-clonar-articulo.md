# Spec: Clonar artículo

> **Estado: implementada** el 2026-10-12. Las 15 asunciones funcionales y las dos adiciones técnicas
> las aprobó el usuario el mismo día.

**Modifica:** [007-gestion-articulos.md](007-gestion-articulos.md) (el listado y la edición ganan
"Clonar"), [010-imagenes-articulos.md](010-imagenes-articulos.md) (la ficha gana el botón).

## Historia de usuario

Como usuario, quiero la opción de clonar un artículo de la lista de artículos y, luego de clonarlo,
ir a la página de edición del artículo para poder hacer ajustes.

## Objetivo / Alcance

Dar de alta un artículo parecido a uno existente sin capturar todo de nuevo: un clic crea la copia
y abre su edición.

**Ninguna tabla ni columna nueva.** Una ruta `POST` nueva y un método en el controlador. Sin
JavaScript nuevo: el botón usa el `data-enviar-una-vez` que ya existe en `public/js/app.js`, y la
ficha solo agrega la dirección del formulario a lo que ya llena `ficha-articulo.js`.

**Fuera de alcance:** clonar varios artículos a la vez, clonar a otro catálogo o proveedor desde el
mismo paso (se cambia después en la edición), copiar la imagen o el inventario, y guardar el vínculo
"clonado de…".

## Reglas de negocio

### Dónde y cómo se clona

1. Cada fila de la lista de artículos lleva un botón **"Clonar"** (icono `copy`, variante `suave`,
   solo icono con `descripcion="Clonar {nombre}"`), entre "Editar" y "Eliminar".
2. Al pulsarlo, el clon se crea **sin confirmación** y la respuesta redirige a la **edición del
   clon** (`articulos.edit` del artículo nuevo), no del original.
3. La edición muestra, con el `exito` de siempre (`articulos._mensajes`), el aviso:
   *"Artículo clonado de «{nombre original}». Ajusta lo que necesites."*
4. La **ficha** del artículo (el `<dialog>` que se abre con el nombre) también lleva "Clonar", junto
   a "Editar".

### Qué se copia

5. Se copian los campos asignables del artículo: `catalogo_id` (y con él, `proveedor_id`), `modelo`,
   `clave_prod_serv`, `clave_unidad`, `objeto_imp`, `precio_proveedor`, `utilidad_porcentaje` y
   `utilidad_distribuidor_porcentaje`. Las utilidades se copian **tal cual, incluso `null`**: un
   original que hereda del catálogo da un clon que también hereda.
6. Costo y precios **no se copian**: los recalcula `Articulo::booted()` al guardar, igual que en el
   alta. Como parten de los mismos datos, quedan iguales a los del original.
7. El nombre lleva el sufijo **" (copia)"**. Si ese nombre ya existe para el mismo proveedor, se
   prueba con " (copia 2)", " (copia 3)", etc., hasta encontrar uno libre. Se sigue la misma regla
   de unicidad que `ArticuloRequest`: por `proveedor_id` y sin contar los eliminados. Si el nombre
   resultante pasa de 255 caracteres, se recorta el **nombre original** para que el sufijo quepa
   completo.
8. El `modelo` se copia sin cambios (no es único).
9. La **imagen no se copia**: `imagen_ruta` queda en `null`.

### Qué no se copia

10. **Ni existencias ni movimientos de inventario**: el clon no tiene fila en `existencias`. En la
    lista sale con "No" en la columna de existencias.
11. **Ni historial**: el clon no aparece en cotizaciones, facturas, pedidos ni órdenes, porque esas
    líneas apuntan al `articulo_id` del original.
12. **Ningún vínculo con el original**: no se guarda de dónde salió. El aviso de la regla 3 solo se
    ve una vez.

### Comportamiento posterior

13. El clon es un artículo como cualquier otro. Si en la edición se pulsa **"Cancelar"**, el clon
    **se queda guardado**; para quitarlo se elimina como cualquier otro.
14. Solo se clonan artículos **propios y no eliminados**. Si el artículo es ajeno o está eliminado,
    responde `404`, igual que editar.
15. El clon queda al final del listado en el orden por defecto (por `id`), como cualquier artículo
    recién creado.

## Backend (Laravel)

### Ruta

En `routes/web.php`, junto a las demás de artículos y **antes** del `Route::resource`:

```php
Route::post('articulos/{articulo}/clonar', [ArticuloController::class, 'clonar'])->name('articulos.clonar');
```

Sin `withTrashed()`: un artículo eliminado no resuelve y responde `404` (regla 14).

### `ArticuloPolicy`

Método nuevo `clonar(User, Articulo)` que reutiliza `esDueno()` (`denyAsNotFound` para los ajenos).

### `Articulo::clonar(): Articulo`

En el modelo, para que la regla de qué se copia viva en un solo lugar:

- Crea el artículo nuevo con `$this->user->articulos()->create([...])`, con los campos de la regla 5
  (`$this->only($this->getFillable())`) y el nombre de la regla 7. `user_id` llega por la relación,
  como en el alta.
- No toca `imagen_ruta`, `existencia` ni `movimientosInventario`.
- **Nombre libre** en un método privado `nombreDeCopia()`: consulta en una sola vez los nombres que
  empiezan con "{nombre} (copia" del mismo `proveedor_id` (sin eliminados) y elige el primer sufijo
  libre: " (copia)", " (copia 2)", " (copia 3)"… Recorta el nombre base con `mb_substr` si hace falta
  para no pasar de 255.

### `ArticuloController::clonar(Articulo $articulo): RedirectResponse`

```php
Gate::authorize('clonar', $articulo);

$clon = DB::transaction(fn () => $articulo->clonar());

return redirect()->route('articulos.edit', $clon)
    ->with('exito', "Artículo clonado de «{$articulo->nombre}». Ajusta lo que necesites.");
```

La transacción cubre que el guardado del clon recalcula precios y lee el catálogo.

**Catálogo eliminado:** si el catálogo del original está eliminado, el clon se crea igual (el
`booted()` lee el catálogo con `withTrashed()`). En la edición pasa lo mismo que hoy con el original:
el catálogo no está entre los disponibles y hay que elegir otro para guardar. No se agrega ninguna
regla nueva para este caso.

## Frontend (Blade)

### `articulos/_filas.blade.php`

Entre "Editar" y el formulario de "Eliminar":

```blade
<form method="POST" action="{{ route('articulos.clonar', $articulo) }}">
    @csrf
    <x-boton variante="suave" icono="copy" title="Clonar" descripcion="Clonar {{ $articulo->nombre }}" data-enviar-una-vez />
</form>
```

El enlace del nombre gana `data-clonar="{{ route('articulos.clonar', $articulo) }}"` para la ficha.

### `articulos/_ficha.blade.php` y `public/js/ficha-articulo.js`

- La ficha gana un formulario `POST` oculto (`id="ficha-clonar"`, `data-ficha-clonar`, con `@csrf`),
  fuera del `<form method="dialog">` de los botones porque no se pueden anidar formularios. El botón
  "Clonar" (`variante="secundario"`, `icono="copy"`, `data-enviar-una-vez`) va junto a "Editar",
  dentro de la fila de botones, y envía ese formulario con el atributo `form="ficha-clonar"`.
- `ficha-articulo.js`, donde hoy hace `editar.href = enlace.href`, también pone
  `formularioClonar.action = enlace.dataset.clonar`.

### Doble clic (adición técnica 1)

Ambos botones llevan `data-enviar-una-vez`: el manejador global de `app.js` los deshabilita al
enviar y los reactiva si se vuelve con "Atrás". No se escribe JavaScript nuevo para esto.

## Pruebas (adición técnica 2)

En `tests/Feature/ArticuloClonarTest.php` (Pest, con `sembrarCatalogosSat()` en `beforeEach`), una
prueba por regla:

1. La lista muestra el botón "Clonar" con la ruta de cada artículo, y la ficha recibe `data-clonar`.
2. `POST articulos.clonar` crea **un** artículo nuevo y redirige a `articulos.edit` del clon.
3. La edición del clon muestra el aviso "Artículo clonado de «…»".
4. (cubierta por 1: la dirección de la ficha).
5. El clon tiene los mismos `catalogo_id`, `proveedor_id`, `modelo`, claves SAT, `objeto_imp`,
   `precio_proveedor` y utilidades. Un original con utilidades `null` da un clon con `null`.
6. `costo_con_descuento`, `precio_unitario_sin_iva` y `precio_distribuidor_sin_iva` coinciden con los
   del original.
7. Nombres: "X" → "X (copia)"; con "X (copia)" existente → "X (copia 2)"; con "X (copia)" **eliminado**
   → "X (copia)"; un "X (copia)" de **otro proveedor** no cuenta; un nombre de 255 caracteres da un
   clon de 255 con el sufijo completo.
8. El `modelo` es idéntico.
9. Original con imagen → clon con `imagen_ruta` `null` (con `Storage::fake`, el archivo del original
   sigue intacto).
10. Original con existencia y movimientos → el clon no tiene `existencia` ni movimientos.
11. Original usado en una línea de cotización → la línea sigue apuntando al original.
12. (sin columna que probar: la tabla no cambia).
13. Volver al listado sin guardar la edición deja el clon en la base.
14. Clonar un artículo ajeno → `404`; uno eliminado → `404`; sin sesión → redirige al login. En
    ninguno se crea nada.
15. En el orden por defecto el clon queda después del original (id mayor).

Además, la suite existente de `ArticuloTest.php` debe seguir pasando sin cambios.

## Estado de implementación

Implementada el 2026-10-12: `Articulo::clonar()`, `ArticuloPolicy::clonar`,
`ArticuloController::clonar`, ruta `articulos.clonar`, botón en `_filas` y en `_ficha` (con
`ficha-articulo.js`) y `tests/Feature/ArticuloClonarTest.php` (17 pruebas). La suite completa pasa.
