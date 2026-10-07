# Spec: Cotizaciones (captura, envío, pagos y caducidad)

**Referencia:** reescritura de [remotas/008-cotizaciones.md](remotas/008-cotizaciones.md), que se
diseñó para la arquitectura anterior (Vue 3 + API + Sanctum). Se conservan las reglas de negocio
(ciclo de estados, folio propio, líneas como copias desacopladas del catálogo, algoritmo de totales
de dos pasadas, reglas de pago, borrado físico, caducidad a los 30 días, aviso de artículo
duplicado) y las lecciones de la implementación remota (plural de "cotización", separación entre
pago de cotización y complemento de pago). La parte del navegador y el protocolo entre navegador y
servidor se rehicieron para Laravel + Blade + JavaScript nativo.

Se retiró lo que la spec remota había heredado de historias que aquí todavía no existen: la
conversión a factura y el vínculo `factura_id` (facturación), los movimientos de Tesorería de cada
pago ([remotas/010](remotas/010-tesoreria.md)), el ajuste al peso cerrado
([remotas/030](remotas/030-total-al-peso-cerrado.md)), el precio distribuidor
([remotas/033](remotas/033-precio-distribuidor.md)), el descuento permanente del cliente
([remotas/015](remotas/015-descuento-permanente-cliente.md)), el bloqueo por Orden de Trabajo
([remotas/038](remotas/038-produccion-ordenes-trabajo.md)) y el componente de líneas compartido con
órdenes de compra ([remotas/012](remotas/012-ordenes-compra.md)). Cada una, cuando llegue, extenderá
esta spec. Del WhatsApp por Twilio solo queda la regla vigente desde
[remotas/029](remotas/029-pwa-mostrador.md): se comparte desde el aparato del usuario.

Se conserva `costo_unitario` en las líneas (base de la futura utilidad de Tesorería): el dato ya
existe en el artículo (`costo_con_descuento`, [009](009-precio-proveedor-utilidad.md)) y no se
puede reconstruir después.

> **Desde [021](021-cotizacion-aceptada-a-venta.md)**: nace el estado
> `aceptada`. Aceptar una cotización sin pagos crea su venta, y el cobro, la entrega y la autofactura
> pasan a la venta. Las cotizaciones con pagos siguen el flujo de esta spec.

> **Desde [023](023-descuento-permanente-cliente.md)**: el descuento permanente del cliente se
> precarga en cada línea (editable), con un aviso sobre la tabla, y la cotización guarda una copia
> congelada del porcentaje (`descuento_cliente_porcentaje`).

> **Desde [029](029-pago-cotizacion-pedido-orden-trabajo.md)** (implementada el 2026-10-04): los pagos de la cotización vuelven a ser
> el flujo vigente y se quita "Aceptar". El **primer pago** (también en `borrador`) decide si nace la
> venta y su orden de trabajo: solo para un cliente que no es distribuidor y una cotización con algo
> de producción. En ese caso la cotización pasa a `aceptada` y sigue recibiendo pagos. Si no, sigue
> el flujo de esta spec hasta `producto_entregado`.

## Historia de usuario

Como usuario registrado, quiero generar cotizaciones para mis clientes, enviárselas por correo o
compartirlas por WhatsApp, registrar sus pagos y marcar la entrega del producto, para dar
seguimiento a cada venta desde la propuesta hasta la entrega.

## Objetivo / Alcance

Implementar el módulo de cotizaciones sobre la arquitectura monolítica Laravel + Blade + JavaScript
nativo de [001-inicio-proyecto.md](001-inicio-proyecto.md), con la sesión web de
[002-login.md](002-login.md), los componentes Blade de [003-estilo-uniforme.md](003-estilo-uniforme.md)
y las convenciones de [004-gestion-proveedores.md](004-gestion-proveedores.md),
[005-gestion-clientes.md](005-gestion-clientes.md) y [007-gestion-articulos.md](007-gestion-articulos.md).
Cada cotización pertenece a un `Cliente` (005) y sus líneas toman datos de los `Articulo` (007 y 009).

- Laravel resuelve el módulo completo: rutas web, controladores, Form Requests, Policy, cálculo de
  totales, PDF, correo y comando de caducidad.
- No se crea API REST, no se usa Sanctum ni API Resources, y no hay stores ni estado en el
  frontend. Las reglas que deciden qué botones se pintan viven en el modelo y Blade las consulta
  directamente.
- **Nace la calculadora de totales de documento** (`CalculadoraTotalesDocumento`), genérica, para
  que la futura facturación la reutilice sin cambios.
- **Nace la generación de PDF** del proyecto (`barryvdh/laravel-dompdf`).
- **Primer uso del scheduler** de Laravel (caducidad diaria).
- JavaScript nativo en tres lugares, todos justificados por interacción real: la **tabla de líneas**
  del formulario (agregar/quitar líneas, buscador de artículos, aviso de duplicado y resumen de
  totales en vivo), el **compartir por WhatsApp** (Web Share API) y la **búsqueda dinámica** del
  listado (reutiliza `busqueda-dinamica.js`). Los modales son `<dialog>` nativos con formularios
  normales. Todo, salvo el compartir y el buscador de artículos, tiene respaldo sin JavaScript.

**No** incluye: timbrado ni XML (una cotización no es CFDI), conversión a factura, cancelación de
cotizaciones, ni movimientos de Tesorería.

## Requisitos del entorno

- **`barryvdh/laravel-dompdf`** (dependencia nueva, autorizada). Antes de implementar hay que
  confirmar que la versión disponible declara compatibilidad con Laravel 13; si no, se usa
  `dompdf/dompdf` directamente detrás de un servicio propio (`GeneradorPdfCotizacion`) y el resto de
  la spec no cambia. Dompdf necesita las extensiones `dom`, `mbstring` y `gd` (ya presentes en
  Laragon, ver [010](010-imagenes-articulos.md)).
- **Correo**: SMTP de `.env` (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, Mailpit en desarrollo). El
  correo se envía **síncrono** (`Mail::send`, sin cola): `QUEUE_CONNECTION=database` pero no hay un
  worker corriendo en Laragon, así que un correo encolado nunca saldría. Si el SMTP falla, el error
  se muestra en la misma pantalla y el estado no cambia.
- **Scheduler**: en Windows/Laragon no hay cron. Para que la caducidad sea automática hay que dar de
  alta **una tarea programada de Windows** que ejecute `php artisan schedule:run` cada minuto en la
  carpeta del proyecto; los pasos quedan en el `README.md` del proyecto. Sin ella el comando existe
  y se puede correr a mano, pero nada lo dispara. Es el único punto de esta historia que depende de
  configuración fuera del repositorio.
- **Zona horaria del negocio**: `config/app.php` sigue en `UTC` (así se guardan las fechas). La zona
  del negocio (`America/Mexico_City`) vive en `config('app.zona_negocio')` y se usa solo para
  interpretar los filtros de fecha, mostrar fechas y contar los días del aviso de caducidad.

## Backend (Laravel)

### Enums (`app/Enums`)

- `EstadoCotizacion`: `borrador`, `enviada`, `pagada`, `producto_entregado`, con `etiqueta()` y
  `esEditable()` (verdadero en `borrador` y `enviada`).
- `TasaIva`: `16`, `0`, `exento`, con `factor()` (`0.16`, `0`, `0`).
- `TipoDescuento`: `porcentaje`, `monto`.
- `TipoPago`: `anticipo`, `saldo`, `pago_total`.
- `FormaPago`: catálogo SAT `c_FormaPago` completo (`01` Efectivo, `02` Cheque nominativo, `03`
  Transferencia electrónica de fondos, `04` Tarjeta de crédito, … `99` Por definir), con
  `etiqueta()`. Es un catálogo corto y estable: vive en un enum, igual que `RegimenFiscal`, no en una
  tabla. Solo informativo (no hay CFDI).

### Modelo `Cotizacion` (tabla `cotizaciones`)

- `protected $table = 'cotizaciones'`: `Str::plural` no conoce el español e inferiría
  `cotizacions` (lección de la remota).
- Pertenece a un `User` (`user_id`, **no** asignable; el alta se hace con
  `$request->user()->cotizaciones()->create(...)`) y a un `Cliente` (`cliente_id`, obligatorio,
  del mismo usuario). `Cotizacion::cliente()` incluye clientes eliminados (`->withTrashed()`), para
  que una cotización cuyo cliente se borró siga mostrando su nombre.
- **Sin soft delete**: el borrado es físico y se lleva sus líneas (FK en cascada). Los pagos
  **no** están en cascada: la regla de borrado exige que no haya pagos (FK `restrict`, como red de
  seguridad).
- **Campos**:
  - `folio`: entero, único por usuario (índice único `(user_id, folio)`). Ver "Folio".
  - `estado`: string, cast a `EstadoCotizacion`, por defecto `borrador`.
  - `descuento_global_tipo` (nullable, `TipoDescuento`) y `descuento_global_valor` (decimal(10,2),
    nullable).
  - Totales, todos decimal(14,2), **siempre calculados en el servidor** y nunca aceptados del
    formulario: `subtotal`, `total_descuento`, `base_iva_16`, `total_iva_16`, `base_iva_0`,
    `base_exento`, `total`.
  - `timestamps`.
- Relaciones: `User::cotizaciones()` y `Cliente::cotizaciones()` (`hasMany`); `lineas()` (`hasMany`,
  ordenadas por `orden`); `pagos()` (`hasMany`, ordenados por `fecha_pago` y `id`).
- Constantes: `DIAS_CADUCIDAD = 30` y `DIAS_AVISO_CADUCIDAD = 7`. Único lugar donde se definen.
- **Métodos de regla** (los usan controlador, Policy, comando y Blade; nadie reimplementa la
  condición):
  - `totalPagado(): string` y `saldoPendiente(): string` (`total - totalPagado`).
  - `tieneAnticipo(): bool`.
  - `esEditable(): bool` → `estado->esEditable()`.
  - `puedeEliminarse(): bool` → editable **y** sin pagos.
  - `puedeRegistrarPago(): bool` → estado `enviada` y saldo pendiente mayor a 0.
  - `puedeEntregarse(): bool` → estado `pagada`.
  - `caducaEl(): ?CarbonImmutable` → `updated_at + DIAS_CADUCIDAD` cuando la cotización caduca
    (editable y sin pagos); `null` en cualquier otro caso.
  - `diasParaCaducar(): ?int` → días calendario, en la zona del negocio, entre hoy y `caducaEl()`
    (0 = se elimina hoy); `null` si no caduca.
  - `mostrarAvisoCaducidad(): bool` → `diasParaCaducar() !== null && diasParaCaducar() <= DIAS_AVISO_CADUCIDAD`.
- **Scopes** (`#[Scope]`): `filtrar(array $filtros)` para el listado y `vencidas()` para la purga
  (editables, sin pagos —`doesntHave('pagos')`— y `updated_at < now() - DIAS_CADUCIDAD`).
- Índices: `(user_id, folio)` único, `(user_id, created_at)`, `(user_id, estado)` y `updated_at`.

### Folio

- Numeración propia por usuario, consecutiva, que **nunca se reutiliza**: si se borra la cotización
  12, la siguiente es la 13, porque un PDF con folio 12 ya pudo llegar al cliente.
- El contador vive en `users.ultimo_folio_cotizacion` (entero, por defecto 0). Al crear o duplicar,
  dentro de una transacción: `User::whereKey(...)->lockForUpdate()->first()`, el folio nuevo es
  `max(contador, folio máximo existente del usuario) + 1` y se guarda como nuevo contador. Así dos
  altas simultáneas no repiten folio, y si el contador se quedara atrás (datos cargados a mano) no
  hay choque. El índice único `(user_id, folio)` es la última red de seguridad.
- Se muestra con 4 dígitos (`COT-0012`) mediante un accessor `folio_formateado`.

### Modelo `CotizacionLinea` (tabla `cotizacion_lineas`)

- `cotizacion_id` (FK, `cascadeOnDelete`), `orden` (entero, posición en el documento).
- `articulo_id`: FK nullable a `articulos` (sin cascada; `nullOnDelete` no aplica porque los
  artículos usan soft delete). Null = **línea libre**: no viene del catálogo, no participa en el
  costo ni en la utilidad.
- `cantidad`: entero, mínimo 1.
- `descripcion` (string 255) y `modelo` (string 255, nullable solo en línea libre): **copias
  desacopladas** del artículo al agregarlo, editables en la línea; si el artículo cambia después,
  la cotización no cambia.
- `precio_unitario`: decimal(10,2), mayor a 0, sin IVA. Se precarga con
  `articulos.precio_unitario_sin_iva` y es editable.
- `descuento_tipo` (nullable, `TipoDescuento`) y `descuento_valor` (decimal(12,2), nullable).
- `tasa_iva`: `TasaIva`. Se precarga con `16` si el artículo tiene `objeto_imp = 02` y con
  `exento` en otro caso; editable. Una línea libre se precarga con `16`.
- `importe` (neto de la línea sin IVA, ya con su descuento y su parte del descuento global) e
  `iva_importe`: decimal(14,2), calculados en el servidor.
- `costo_unitario`: decimal(10,2), nullable, sin IVA. **Copia del `costo_con_descuento` del
  artículo en el momento en que la línea se guarda**, no un valor que se recalcule después:
  - Solo cuando hay `articulo_id`; en línea libre queda `null`.
  - Nunca se acepta del formulario: el servidor lo toma del artículo con una sola consulta
    (`Articulo::withTrashed()->whereIn('id', ...)->pluck('costo_con_descuento', 'id')`).
  - En cada edición las líneas se borran y se vuelven a crear, así que toma el costo vigente en ese
    instante, igual que `precio_unitario` toma el precio vigente al precargarse.
  - `duplicar` copia el `costo_unitario` de la línea original.
  - No se muestra en ninguna pantalla de cotización ni en el PDF: es un dato interno para la futura
    utilidad de venta.

### Modelo `CotizacionPago` (tabla `cotizacion_pagos`)

- `cotizacion_id` (FK, `restrictOnDelete`), `tipo` (`TipoPago`), `fecha_pago` (date), `monto`
  (decimal(14,2), mayor a 0), `forma_pago` (`FormaPago`), `timestamps`.
- `$touches = ['cotizacion']`: registrar o eliminar un pago cuenta como movimiento de la
  cotización.
- No genera documento fiscal: no es CFDI, no pasa por ningún PAC. Cuando exista facturación, su
  complemento de pago será un modelo aparte, sin acoplamiento con este.

### Calculadora de totales (`App\Services\Documentos\CalculadoraTotalesDocumento`)

Genérica: recibe un arreglo de líneas (`cantidad`, `precio_unitario`, `descuento_tipo`,
`descuento_valor`, `tasa_iva`) y el descuento global; devuelve el `importe` e `iva_importe` de cada
línea y los totales del documento. No conoce `Cotizacion`. Todas las cantidades viven en
**centavos enteros** (las sumas son exactas); los productos (porcentaje, prorrateo, IVA) se redondean
al centavo, mitad hacia arriba, quitando antes el ruido de punto flotante con un redondeo a 6
decimales, el mismo criterio que `CalculadoraPrecioArticulo` (009). Con los topes de validación
(ver "Validaciones") ningún producto intermedio pasa de 2^53, así que PHP y JavaScript dan
exactamente el mismo resultado.

**Primera pasada, por línea:**

1. `bruto = cantidad × precio_unitario`, redondeado a 2 decimales.
2. Descuento de línea: `porcentaje` → `bruto × valor / 100` redondeado a 2; `monto` → el valor tal
   cual. No puede exceder `bruto` (lo valida el Form Request).
3. `neto_linea = bruto - descuento_linea`.

**Segunda pasada, descuento global:**

4. `suma_netos = Σ neto_linea`. Descuento global: `porcentaje` → `suma_netos × valor / 100`
   redondeado a 2; `monto` → el valor tal cual (no puede exceder `suma_netos`).
5. Se **prorratea** entre las líneas en proporción a su `neto_linea`, redondeando cada parte a 2
   decimales; la diferencia de centavos que deja el redondeo se asigna a la línea de mayor
   `neto_linea` (la primera en caso de empate), para que la suma de partes sea exactamente el
   descuento global.
6. `importe = neto_linea - parte_global`; `iva_importe = importe × factor(tasa_iva)` redondeado a
   2 decimales, por línea.

**Totales:**

- `subtotal = Σ bruto`; `total_descuento = Σ descuento_linea + descuento_global`.
- `base_iva_16`, `base_iva_0`, `base_exento` = suma de `importe` por tasa.
- `total_iva_16 = Σ iva_importe`.
- `total = subtotal - total_descuento + total_iva_16`.

La misma cadena existe en JavaScript solo para el resumen en vivo (ver "Fuente de verdad única").

### Rutas (web)

En `routes/web.php`, dentro del grupo `['auth', AsegurarUsuarioActivo::class]`, **antes** del
`Route::resource` para que `buscar` no se confunda con un `{cotizacion}`:

```php
Route::get('articulos/sugerencias', [ArticuloController::class, 'sugerencias'])->name('articulos.sugerencias');

Route::get('cotizaciones/buscar', [CotizacionController::class, 'buscar'])->name('cotizaciones.buscar');
Route::post('cotizaciones/{cotizacion}/enviar', [EnvioCotizacionController::class, 'correo'])->name('cotizaciones.enviar');
Route::post('cotizaciones/{cotizacion}/marcar-enviada', [EnvioCotizacionController::class, 'marcarEnviada'])->name('cotizaciones.marcar-enviada');
Route::get('cotizaciones/{cotizacion}/pdf', [CotizacionController::class, 'pdf'])->name('cotizaciones.pdf');
Route::post('cotizaciones/{cotizacion}/entregar', [CotizacionController::class, 'entregar'])->name('cotizaciones.entregar');
Route::post('cotizaciones/{cotizacion}/duplicar', [CotizacionController::class, 'duplicar'])->name('cotizaciones.duplicar');
Route::post('cotizaciones/{cotizacion}/pagos', [CotizacionPagoController::class, 'store'])->name('cotizaciones.pagos.store');
Route::delete('cotizaciones/{cotizacion}/pagos/{pago}', [CotizacionPagoController::class, 'destroy'])
    ->scopeBindings()->name('cotizaciones.pagos.destroy');

Route::resource('cotizaciones', CotizacionController::class)
    ->parameters(['cotizaciones' => 'cotizacion']);
```

`->parameters(...)` es obligatorio: sin él el parámetro sería `{cotizacione}` y el binding implícito
fallaría (lección de la remota).

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/cotizaciones` | listado completo, filtros y página en la URL | `cotizaciones.index` |
| GET | `/cotizaciones/buscar` | fragmento HTML (filas y paginación) para la búsqueda dinámica | `cotizaciones.buscar` |
| GET | `/cotizaciones/crear` | formulario de alta | `cotizaciones.create` |
| POST | `/cotizaciones` | alta | `cotizaciones.store` |
| GET | `/cotizaciones/{cotizacion}` | detalle con pagos y acciones | `cotizaciones.show` |
| GET | `/cotizaciones/{cotizacion}/editar` | formulario de edición | `cotizaciones.edit` |
| PUT | `/cotizaciones/{cotizacion}` | edición | `cotizaciones.update` |
| DELETE | `/cotizaciones/{cotizacion}` | borrado físico | `cotizaciones.destroy` |
| POST | `/cotizaciones/{cotizacion}/enviar` | envía el correo con el PDF adjunto | `cotizaciones.enviar` |
| POST | `/cotizaciones/{cotizacion}/marcar-enviada` | marca como enviada tras compartir (AJAX, responde JSON) | `cotizaciones.marcar-enviada` |
| GET | `/cotizaciones/{cotizacion}/pdf` | PDF al vuelo (`?descargar=1` fuerza descarga) | `cotizaciones.pdf` |
| POST | `/cotizaciones/{cotizacion}/pagos` | registra un pago | `cotizaciones.pagos.store` |
| DELETE | `/cotizaciones/{cotizacion}/pagos/{pago}` | elimina el último pago | `cotizaciones.pagos.destroy` |
| POST | `/cotizaciones/{cotizacion}/entregar` | marca `producto_entregado` | `cotizaciones.entregar` |
| POST | `/cotizaciones/{cotizacion}/duplicar` | crea la copia y redirige a su detalle | `cotizaciones.duplicar` |
| GET | `/articulos/sugerencias?q=` | sugerencias de artículos para las líneas (JSON) | `articulos.sugerencias` |

### Controladores

- **`CotizacionController`**:
  - `index`: cotizaciones del usuario con `cliente` precargado y `withCount('pagos')` más
    `withSum('pagos', 'monto')` (para `puedeEliminarse()`, `caducaEl()` y el saldo sin una consulta
    por fila), filtradas con los parámetros de `ListadoCotizacionesRequest`, ordenadas por
    `created_at` descendente y `id`, 25 por página con `->withQueryString()`. Sin ningún parámetro
    de fecha en la URL se aplica "Este mes".
  - `buscar`: la misma consulta; devuelve el parcial `cotizaciones/_resultados.blade.php` con
    `->withPath(route('cotizaciones.index'))`, como en 005/007.
  - `create`: formulario vacío con los clientes del usuario (activos, por razón social).
  - `store`: en una transacción asigna folio, crea la cotización, guarda las líneas y los totales
    con la calculadora; redirige al detalle con mensaje flash.
  - `show`: detalle con `lineas`, `pagos` y `cliente`.
  - `edit`: solo si es editable (la Policy responde 403 con mensaje si no); reconstruye las líneas
    guardadas.
  - `update`: en una transacción borra las líneas, las vuelve a crear, recalcula totales y, si
    estaba `enviada`, la regresa a `borrador`. Redirige al detalle con un flash que lo avisa
    ("La cotización volvió a borrador: reenvíala para que el cliente vea los cambios.").
  - `destroy`: borrado físico si `puedeEliminarse()`; si no, redirige con el motivo (no se muestra
    el botón, pero la regla se vuelve a evaluar). Redirige al listado.
  - `pdf`: genera el PDF con `GeneradorPdfCotizacion` y lo devuelve `inline` (o `attachment` con
    `?descargar=1`), nombre `cotizacion-COT-0012.pdf`. No persiste copia.
  - `entregar`: solo si `puedeEntregarse()`.
  - `duplicar`: copia `cliente_id`, descuento global, líneas (incluidos `costo_unitario` y los
    importes) y totales; folio nuevo, `estado = borrador`, sin pagos. Redirige al detalle de la
    copia.
- **`EnvioCotizacionController`**:
  - `correo`: valida con `EnviarCotizacionRequest`, envía `CotizacionMail` (PDF adjunto) a los
    destinatarios, y pasa `borrador → enviada`. Si ya estaba `enviada`, `pagada` o
    `producto_entregado`, el estado no cambia pero se hace `touch()` (reenviar reinicia el plazo de
    caducidad). Redirige al detalle con flash.
  - `marcarEnviada`: igual que el cambio de estado anterior, sin correo; responde
    `{ estado, etiqueta }` en JSON para que el script actualice la pantalla. Se llama después de
    compartir desde el aparato.
- **`CotizacionPagoController`**: ver "Pagos".
- **`ArticuloController::sugerencias`**: artículos del usuario (sin eliminados) cuyo nombre o modelo
  contenga `q`, máximo 20, en JSON
  `[{ id, nombre, modelo, precio_unitario, tasa_iva }]`. Con `q` vacío responde `[]`.

### Pagos (`CotizacionPagoController`)

- **`store`**, en una transacción con `Cotizacion::lockForUpdate()` sobre la cotización (dos clics
  seguidos no generan sobrepago):
  - Solo si `puedeRegistrarPago()` (estado `enviada` y saldo pendiente > 0); si no, error de
    validación.
  - `anticipo`: monto libre elegido por el usuario; máximo **un** anticipo por cotización; no puede
    exceder el saldo pendiente. Un anticipo igual al saldo pendiente es válido y la deja `pagada`.
  - `saldo` y `pago_total`: el monto **siempre** se calcula en el servidor como el saldo pendiente;
    si el formulario manda uno, se ignora. `saldo` requiere un anticipo previo; `pago_total`
    requiere que no lo haya.
  - Tras guardar, si `totalPagado() >= total`, el estado pasa a `pagada`.
- **`destroy`**: solo se puede eliminar el **último** pago registrado (el de mayor `id`), y solo
  mientras la cotización no esté en `producto_entregado`. Eliminar siempre en orden inverso mantiene
  coherentes las reglas de anticipo/saldo. Si la cotización estaba `pagada` y deja de alcanzar el
  total, regresa a `enviada`. Confirmación previa (`data-confirmar`).

### PDF (`App\Services\Cotizaciones\GeneradorPdfCotizacion`)

- Vista `cotizaciones/pdf.blade.php`, con estilos propios dentro de la vista (Dompdf no lee la hoja
  del sitio), tomando los colores y la tipografía de los tokens de 003.
- Contenido: nombre del negocio (`config('app.name')`), folio, fecha, datos del cliente (razón
  social, RFC, correo, teléfono), tabla de líneas (cantidad, descripción, modelo, precio unitario,
  descuento, importe), desglose (subtotal, descuento, IVA 16%, total) y saldo pendiente si hay
  pagos.
- Tamaño carta, fuente DejaVu Sans (incluida en Dompdf, con acentos y `Ø`).

### Correo (`App\Mail\CotizacionMail`)

- Asunto "Cotización COT-0012 — {nombre del negocio}", cuerpo breve en Blade (saludo, total,
  folio) y el PDF adjunto generado al vuelo con el mismo servicio.
- Copia oculta al buzón del negocio (`negocio.copia_correos`, de fábrica `ventas@sellopronto.com.mx`)
  con el trait `CopiaAlNegocio`: ver [032](032-copia-correos-negocio.md).

### Caducidad automática (`cotizaciones:purgar-vencidas`)

Una cotización que el cliente nunca aprobó no se queda para siempre: **a los 30 días sin movimiento
en `borrador` o `enviada`, se elimina** (el mismo borrado físico que el manual).

- "Sin movimiento" se mide contra `updated_at`: crear, editar, enviar, reenviar, compartir o
  registrar/eliminar un pago reinicia el conteo.
- Nunca borra `pagada` ni `producto_entregado`, ni una cotización con pagos (aunque siga
  `enviada`: el caso de un anticipo y un cliente que desapareció queda a la vista hasta resolverlo a
  mano).
- Comando `cotizaciones:purgar-vencidas`: borra con el scope `vencidas()` y reporta cuántas
  eliminó. Es idempotente: una segunda corrida el mismo día no borra nada.
- Agendado en `routes/console.php` con `Schedule::command('cotizaciones:purgar-vencidas')->dailyAt('03:00')`
  en la zona del negocio.

### Autorización (`CotizacionPolicy`)

- `view`, `update`, `delete` y `operar` (una sola habilidad para enviar, compartir, pagar,
  eliminar pago, entregar, duplicar y descargar el PDF; la regla de estado de cada acción la revisa
  su controlador con los métodos del modelo): solo el dueño; a los demás `Response::denyAsNotFound()` (404), igual que 004/005/007. El
  administrador no tiene excepción.
- `update` y `delete` además exigen `esEditable()` / `puedeEliminarse()`; si la cotización es del
  usuario pero no cumple, `Response::deny('…motivo…')` (403 con mensaje).
- Blade usa `@can` para pintar los botones de editar y eliminar.
- Los Form Requests usan `Gate::inspect(...)` en `authorize()` (lección de 004: el Form Request
  valida antes que el controlador).

### Validaciones

- **`CotizacionRequest`** (alta y edición):
  - `cliente_id`: requerido, existe, es del usuario y no está eliminado.
  - `lineas`: array, mínimo 1, máximo 100.
  - `lineas.*.articulo_id`: nullable, existe, del usuario, sin eliminar, **`distinct`** (un
    artículo aparece una sola vez por documento; complemento en servidor del aviso de duplicado).
    En edición se acepta un artículo que se eliminó después de guardarse la línea.
  - `lineas.*.cantidad`: requerido, entero, mínimo 1, máximo 9999.
  - `lineas.*.descripcion`: requerido, string, max 255. `lineas.*.modelo`: requerido si hay
    `articulo_id`, string, max 255.
  - `lineas.*.precio_unitario`: requerido, numérico, `gt:0`, `decimal:0,2`, máximo `999999.99`.
    Con estos topes una línea vale como máximo ~$10,000 millones y un documento cabe en
    decimal(14,2).
  - `lineas.*.descuento_tipo`: nullable, `TipoDescuento`; `lineas.*.descuento_valor`: requerido si
    hay tipo, numérico, `gte:0`, `decimal:0,2`; con `porcentaje` máximo 100; con `monto` no mayor que
    `cantidad × precio_unitario` (regla con `Closure`).
  - `lineas.*.tasa_iva`: requerido, `TasaIva`.
  - `descuento_global_tipo` / `descuento_global_valor`: mismas reglas que a nivel línea; con `monto`
    no mayor que la suma de netos de línea.
  - Cualquier campo de totales, `costo_unitario`, `folio`, `estado` o `user_id` que llegue en la
    petición se ignora (se toma solo lo validado).
  - En edición, si ya hay pagos, el nuevo `total` no puede quedar por debajo del total pagado
    ("El total no puede ser menor a lo ya pagado ($X)").
- **`ListadoCotizacionesRequest`**: como `ListadoArticulosRequest`, no valida nada; sanea
  `cliente` (razón social o nombre comercial, parcial), `rfc` (parcial), `folio` (número exacto,
  acepta `COT-0012` o `12`), `estado` (lista blanca del enum), `fecha_desde`/`fecha_hasta`
  (`Y-m-d`; inválida se ignora), `periodo` (`hoy`, `semana`, `mes`) y `pagina`. Las fechas son días
  calendario completos en la zona del negocio, convertidos a UTC antes de comparar contra
  `created_at`.
- **`EnviarCotizacionRequest`**: `destinatarios`: array, 1 a 5, cada uno `email:rfc`. El formulario
  manda un solo campo (`destinatarios_texto`) separado por comas, punto y coma o espacios;
  `prepareForValidation()` lo parte y quita espacios. Sus errores van a la bolsa `envio` y los de
  `CotizacionPagoRequest` a la bolsa `pago`, para que el detalle reabra el diálogo que falló.
- **`CotizacionPagoRequest`**: `tipo` requerido (`TipoPago`); `fecha_pago` requerida, fecha, no
  futura; `forma_pago` requerida (`FormaPago`); `monto` requerido, numérico, `gt:0`, `decimal:0,2`
  solo si `tipo = anticipo`. Reglas `Closure`: `sinAnticipoPrevio`, `requiereAnticipo` (para
  `saldo`), `sinSobrepago` y `estadoPermiteCobro`. Las mismas reglas se vuelven a comprobar dentro
  de la transacción del controlador, con la fila bloqueada.
- `attributes()` en español en todos (`lineas.*.cantidad` → "cantidad de la línea :position").

## Vistas (Blade)

Todas extienden `layouts/app.blade.php` y usan los componentes de 003 (`x-card`, `x-campo`,
`x-boton`, `x-alerta`, `x-icono`, `x-paginacion`). Se agrega "Cotizaciones" al menú con el icono
`file-earmark-text`, después de "Artículos".

### `cotizaciones/index.blade.php` — listado

> Desde [014](014-cotizaciones-bandeja.md) el listado es una bandeja de tres columnas (carpetas por
> periodo, etiquetas por estado, lista y vista previa en HTML). Se retiraron la tabla, los filtros por
> columna, el rango de fechas y el botón de eliminar del listado. Lo que sigue es la versión original.

- Tabla: folio, cliente (razón social; nombre comercial debajo si existe), estado (etiqueta con
  color por estado), total, fecha (zona del negocio) y acciones (ver, eliminar).
- Una fila de filtros bajo los títulos: cliente, RFC, folio y estado (`select`), dentro del
  formulario `data-busqueda-dinamica` para filtrar sin recargar.
- Filtro de fecha sobre la tabla: tres enlaces "Hoy", "Esta semana", "Este mes" (con
  `data-busqueda-enlace`; el activo resaltado; por defecto "Este mes") y dos campos `date` "Desde" /
  "Hasta" para el rango personalizado. Un rango personalizado desactiva el atajo.
- Botón de eliminar (papelera, `data-confirmar`) solo si `puedeEliminarse()`.
- Aviso de caducidad: si `mostrarAvisoCaducidad()`, una etiqueta de advertencia en la fila, "Se
  elimina en 5 días" / "Se elimina mañana" / "Se elimina hoy".
- Parciales `_resultados`, `_filas` y `_paginacion`, como en 005/007.

### `cotizaciones/crear.blade.php` y `editar.blade.php` — formulario (`_formulario.blade.php`)

- Cliente: `select` con los clientes del usuario (razón social — RFC).
- **Tabla de líneas**: cantidad | descripción | modelo | precio unitario | descuento (tipo y valor)
  | IVA | importe | quitar. Cada campo se llama `lineas[i][campo]` e incluye `lineas[i][articulo_id]`
  oculto.
- Encima de la tabla: buscador de artículos (campo con sugerencias) y botón "Agregar línea libre".
- Descuento global (tipo y valor) y el resumen de totales (subtotal, descuento, IVA 16%, total),
  marcado como "estimado": el valor que cuenta es el que calcula el servidor al guardar.
- **Reconstrucción tras un error**: Blade dibuja las filas desde `old('lineas')` si existe, o desde
  las líneas guardadas en edición; cada error se muestra en su celda (`lineas.3.precio_unitario`).
  Un error de validación nunca borra lo capturado.
- **Sin JavaScript**: la tabla se dibuja con las filas existentes más 3 filas vacías para línea
  libre, y el servidor ignora las filas totalmente vacías (`prepareForValidation`). El buscador de
  artículos requiere JavaScript.
- En edición de una cotización `enviada`, un aviso arriba: "Al guardar, la cotización regresa a
  borrador y tendrás que reenviarla."
- Una plantilla `<template id="plantilla-linea">` con una fila vacía para el script.

### `cotizaciones/show.blade.php` — detalle

> Desde [014](014-cotizaciones-bandeja.md) el documento (cliente, líneas y totales) se dibuja con
> `<x-cotizaciones.hoja>`, la misma pieza del visor de la bandeja. Las acciones y los pagos no
> cambiaron.

- Encabezado: folio, estado, cliente, fechas, y el documento (líneas y totales) igual que en el PDF.
- **Historial de pagos**: fecha, tipo, forma de pago y monto; total pagado y saldo pendiente. El
  último pago tiene botón "Eliminar" (con confirmación) si la cotización no está en
  `producto_entregado`.
- **Aviso de caducidad** (`x-alerta` de advertencia) si `mostrarAvisoCaducidad()`: "Sin
  movimiento desde el 10/07/2026. Se eliminará automáticamente el 09/08/2026 (en 5 días). Editarla
  o reenviarla reinicia el plazo."
- **Acciones**, cada una visible según los métodos del modelo:
  - "Editar": `@can('update')`.
  - "Enviar por correo": abre un `<dialog>` con un formulario normal; destinatarios prellenados con
    el correo del cliente.
  - "Compartir por WhatsApp": botón con `data-compartir-cotizacion` (ver JavaScript); se oculta si
    el navegador no tiene JavaScript.
  - "Registrar anticipo": si `puedeRegistrarPago()` y no `tieneAnticipo()`. `<dialog>` con fecha
    (hoy por defecto), forma de pago y monto.
  - "Pago total": misma condición que "Registrar anticipo". `<dialog>` con fecha y forma de pago; el
    saldo pendiente como texto de confirmación, sin campo de monto.
  - "Registrar saldo": si `puedeRegistrarPago()` y `tieneAnticipo()`. Mismo `<dialog>` que "Pago
    total". Así solo aparece uno de los dos.
  - "Marcar como entregado": si `puedeEntregarse()`, con confirmación.
  - "Duplicar", "Ver PDF" (pestaña nueva) y "Descargar PDF".
  - "Eliminar": `@can('delete')`, con confirmación que advierte que el borrado es definitivo (se
    lleva las líneas y no hay papelera).
- Los botones que abren un diálogo son enlaces `href="#id-del-dialogo"` con `data-abrir-dialogo`:
  con JavaScript abren el `<dialog>` como modal (manejador genérico en `app.js`); sin él, el CSS
  muestra el diálogo señalado por la URL (`dialog:target`) dentro de la página, con el mismo
  formulario.

## JavaScript

### Tabla de líneas (`public/js/documento-lineas.js`)

Se activa en `[data-documento-lineas]`. Genérico (sin mencionar "cotización") para que facturación
lo reutilice.

- **Buscador de artículos**: consulta `articulos.sugerencias` con Axios (espera de 300 ms, cancela la
  petición anterior), muestra nombre, modelo y precio, y se maneja con teclado y mouse (mismas clases
  y roles ARIA que `autocompletar.js`). Al elegir:
  - Si el `articulo_id` **ya está** en alguna línea, no agrega nada: abre un `<dialog>` que avisa del
    duplicado, muestra la línea existente (número, descripción, modelo, cantidad actual) y ofrece
    **"Sumar a la línea existente"** con un campo "Cantidad a sumar" (entero, mínimo 1, por defecto
    1) o **"Cancelar"**. Sumar solo hace `cantidad += n`; precio, descripción, modelo, descuento e
    IVA quedan tal cual, incluidas las ediciones manuales. No hay opción "agregar aparte".
  - Si no, clona `#plantilla-linea` y la llena con los datos del artículo.
  - El aviso solo lo dispara el buscador; cargar líneas guardadas, duplicadas o reconstruidas desde
    `old()` nunca lo dispara.
- "Agregar línea libre" y "Quitar" (se puede quitar cualquier línea; si no queda ninguna, el
  servidor responde "Agrega al menos una línea"). Al agregar o quitar, reescribe
  los índices `lineas[i]` para que sean consecutivos.
- **Resumen en vivo**: al cambiar cualquier campo recalcula importes y totales con
  `public/js/totales-documento.js`. Informativo.

### Fórmula en el navegador (`public/js/totales-documento.js`)

Función pura `calcularTotalesDocumento(lineas, descuentoGlobal)` con la misma cadena que
`CalculadoraTotalesDocumento`, trabajando en centavos enteros. Se expone en `window` y con
`module.exports` para las pruebas de Node, como `precio-articulo.js` (009).

### Compartir por WhatsApp (`public/js/compartir-cotizacion.js`)

> Desde [012](012-facturacion.md) este script es `public/js/compartir-pdf.js` (botón
> `data-compartir-pdf`, etiqueta `data-estado-documento`), compartido con facturas. El
> comportamiento de la cotización descrito aquí no cambió.

- El botón `data-compartir-cotizacion` trae la URL del PDF, la de `marcar-enviada`, el nombre del
  archivo, el teléfono del cliente y un texto resumen ("Cotización COT-0012 por $1,148.40").
- Descarga el PDF con `fetch` (mismo origen, viaja la cookie de sesión) y, si
  `navigator.canShare({ files })`, llama a `navigator.share({ files, text })`: el usuario elige
  WhatsApp en su menú.
- Si no se pueden compartir archivos (escritorio): descarga el archivo y abre
  `https://wa.me/52{telefono}?text=…` (sin teléfono, `https://wa.me/?text=…`) para que el usuario lo
  adjunte.
- Al terminar (compartir resuelto o descarga hecha; cancelar el menú **no** cuenta) hace `POST` a
  `marcar-enviada` con Axios y actualiza la etiqueta de estado con la respuesta.
- Error de red o 401: mensaje en el `x-alerta` de la página; con 401 recarga para que el login
  aparezca, como `autocompletar.js`.

### Búsqueda dinámica del listado

> Desde [014](014-cotizaciones-bandeja.md) los atajos son las carpetas de la bandeja y sincronizan
> `periodo` y `estado`. `busqueda-dinamica.js` dispara `busqueda:actualizada` al terminar.

Reutiliza `busqueda-dinamica.js`: formulario `data-busqueda-dinamica` apuntando a
`cotizaciones.buscar`, y los atajos de fecha como `data-busqueda-enlace`. Extensión mínima y
genérica del script: al pulsar un enlace, los campos del formulario marcados con
`data-busqueda-sincronizar` (aquí `periodo`, `fecha_desde` y `fecha_hasta`) toman el valor que trae
el enlace, o quedan vacíos; así un atajo borra el rango personalizado y la siguiente búsqueda no lo
contradice. Los atajos se vuelven a pintar con cada respuesta (`#cotizaciones-atajos`) para llevar
los filtros actuales y marcar el activo. Si llegan fechas, mandan sobre el atajo.

### Confirmaciones

Manejador `data-confirmar` ya existente en `app.js` (eliminar cotización, eliminar pago, marcar
como entregado).

### Fuente de verdad única de la fórmula

Mismo mecanismo que 009: dos copias (PHP persiste, JavaScript resume en vivo) que no pueden divergir
en silencio.

- Fixture compartido `tests/Fixtures/totales-documentos.json`. Casos mínimos: una línea sin
  descuentos al 16%; descuento de línea por porcentaje y por monto; descuento global por porcentaje
  y por monto con prorrateo que deja centavo residual (tres líneas de $33.33 con $10 de descuento
  global); tasas mezcladas 16/0/exento; descuento de línea igual al bruto (importe 0); cantidades
  grandes (9999 × $999,999.99); medio centavo que se redondea hacia arriba; residual negativo del
  prorrateo.
- Pest (`tests/Unit/CalculadoraTotalesDocumentoTest.php`) y Node
  (`tests/js/totales-documento.test.js`, `node --test "tests/js/*.test.js"`) recorren el mismo
  fixture. Sin npm, sin `package.json`.

## Pruebas (Pest y Node)

`tests/Feature/CotizacionesTest.php`, `tests/Feature/CotizacionPagosTest.php`,
`tests/Feature/EnvioCotizacionTest.php`, `tests/Feature/PurgarCotizacionesTest.php`,
`tests/Unit/CalculadoraTotalesDocumentoTest.php`, `tests/js/totales-documento.test.js`:

- Invitado redirigido al login en todas las rutas; usuario suspendido no entra.
- Aislamiento: listado sin cotizaciones ajenas; ver, editar, eliminar, enviar, pagar, entregar,
  duplicar o descargar PDF de una ajena responde 404; cliente o artículo ajeno es error de
  validación; `articulos.sugerencias` solo devuelve artículos propios.
- Alta: queda `borrador`, folio consecutivo por usuario, totales y `costo_unitario` calculados en el
  servidor; totales enviados en la petición se ignoran; `articulo_id` repetido es error; línea libre
  con `costo_unitario` nulo.
- Folio: dos usuarios tienen numeraciones independientes; borrar la última no reutiliza su folio;
  duplicar asigna folio nuevo.
- Edición: `enviada` → `borrador`; `pagada`/`producto_entregado` responden 403; `costo_unitario`
  toma el costo vigente; con pagos, un total menor a lo pagado es error.
- Borrado: permitido en `borrador`/`enviada` sin pagos (se lleva las líneas); rechazado con pagos o
  en `pagada`/`producto_entregado`.
- Pagos: anticipo parcial deja `enviada`; anticipo + saldo deja `pagada`; pago total deja `pagada`;
  segundo anticipo rechazado; saldo sin anticipo rechazado; anticipo mayor al saldo rechazado;
  `monto` manipulado en `saldo` se ignora; pago en `borrador` rechazado; eliminar el último pago
  regresa `pagada` → `enviada`; eliminar un pago que no es el último rechazado; eliminar en
  `producto_entregado` rechazado.
- Entregar: solo desde `pagada`.
- Envío: `Mail::fake()`, el correo lleva el PDF adjunto y los destinatarios; `borrador` →
  `enviada`; reenviar una `enviada` actualiza `updated_at`; destinatario inválido es error;
  `marcar-enviada` responde JSON y no degrada una `pagada`.
- PDF: responde `application/pdf` con el folio en el nombre del archivo.
- Listado: filtros por cliente, RFC, folio (`12` y `COT-0012`) y estado, combinados; "Hoy", "Esta
  semana", "Este mes" y rango personalizado en la zona del negocio (una cotización creada a las
  23:30 del día 31 hora local cae en ese día aunque en UTC sea el siguiente); por defecto "Este mes";
  `/cotizaciones/buscar` devuelve el fragmento.
- Caducidad: el comando borra `borrador`/`enviada` con `updated_at` de hace más de 30 días y respeta
  las demás (otros estados, con pagos, tocadas dentro del plazo); segunda corrida borra 0;
  `caducaEl()`, `diasParaCaducar()` y el aviso aparecen a los 7 días o menos y no antes.
  `$this->travelTo(...)` para el tiempo.
- Calculadora: fixture compartido en Pest y en Node.
- `node --check` sobre los tres scripts nuevos. El comportamiento en el navegador (tabla de líneas,
  aviso de duplicado, compartir) se verifica a mano.
- `EstiloUniformeTest`, `ClienteTest` y `ArticuloTest` siguen pasando.

## Fuera de alcance

- Timbrado o XML de la cotización; conversión a factura y el vínculo cotización ↔ factura (llegan
  con la spec de facturación, que agregará su columna y su botón "Facturar").
- Movimientos de Tesorería al registrar o eliminar pagos, y el cálculo de utilidad de venta (se deja
  listo `costo_unitario`).
- Ajuste al peso cerrado ([remotas/030](remotas/030-total-al-peso-cerrado.md)), precio distribuidor
  ([remotas/033](remotas/033-precio-distribuidor.md)), descuento permanente del cliente
  ([remotas/015](remotas/015-descuento-permanente-cliente.md)), bloqueo por Orden de Trabajo
  ([remotas/038](remotas/038-produccion-ordenes-trabajo.md)), recibo de anticipo
  ([remotas/040](remotas/040-recibo-anticipo-cotizacion.md)), datos bancarios en el PDF
  ([remotas/026](remotas/026-datos-bancarios-cotizacion.md)), formato común de PDF
  ([remotas/019](remotas/019-formato-pdf-documentos.md)) y envío a domicilio
  ([remotas/041](remotas/041-envio-domicilio-direccion-y-distribuidor.md)): cada una tendrá su
  propia spec local.
- Cancelación de una cotización (no hay estado para "el cliente ya no la quiere"; se elimina o
  caduca).
- Notas de crédito y parcialidades más allá del anticipo + saldo.
- Envío por WhatsApp desde el servidor (Twilio u otro); validar que el teléfono tenga WhatsApp.
- Reenvío automático al cambiar de estado: enviar siempre es manual.
- Vigencia comercial de la cotización impresa en el PDF ("válida hasta…"), distinta de la
  caducidad interna.
- Envío de correo en cola.
- Roles/permisos diferenciados y multiempresa.

## Estado de implementación

Implementada el 2026-09-27.

- **Dompdf**: `barryvdh/laravel-dompdf` 3.1 declara compatibilidad con Laravel 13 y se instaló sin
  conflictos. Con la fuente DejaVu completa el PDF pesaba ~880 KB; activar
  `isFontSubsettingEnabled` (solo se incrustan las letras usadas) lo dejó en ~22 KB, importante
  para compartirlo por WhatsApp. Se revisó visualmente un PDF generado contra MySQL.
- **Campos dentro de celdas**: `EstiloUniformeTest` prohíbe `<input>`/`<select>` escritos a mano en
  las vistas. Nace el componente `<x-celda>` (campo sin etiqueta visible, con `aria-label`, sin
  `old()` porque la fila ya recibe su valor) para la tabla de líneas; el buscador y "Cantidad a
  sumar" usan `<x-campo>`.
- **Diálogos sin JavaScript**: en vez de duplicar cada formulario en un `<details>`, los botones son
  enlaces `#id` y el CSS muestra el diálogo con `dialog:target`. El manejador de
  `data-abrir-dialogo`/`data-cerrar-dialogo`/`data-abrir-al-cargar` vive en `app.js` y sirve a
  cualquier pantalla.
- **Topes numéricos**: cantidad ≤ 9999, precio ≤ $999,999.99 y ≤ 100 líneas (la versión inicial de
  esta spec decía 99999, $99,999,999.99 y 200). Con los topes anteriores un producto intermedio del
  cálculo podía pasar de 2^53 y PHP y JavaScript dejarían de coincidir. Los totales pasaron a
  decimal(14,2).
- **Aritmética**: en lugar de `bcmath`, centavos enteros con el mismo redondeo sin ruido de 009. El
  fixture (9 casos calculados a mano) pasa idéntico en Pest y en Node.
- **Folio**: además del contador del usuario, se toma el máximo existente como red de seguridad. Lo
  destapó la factory de pruebas, que creaba folios sin avanzar el contador.
- **Quitar líneas**: se puede quitar cualquier línea, incluida la última (la spec inicial lo
  impedía); el servidor exige al menos una. Es más simple de usar cuando el primer artículo
  agregado es el equivocado.
- **Sugerencias**: `articulos.sugerencias` no devuelve `imagen_url`; la tabla de líneas no la usa.
- **Verificación**: la suite Pest pasa (525 tests; 101 nuevos en `CotizacionesTest`,
  `CotizacionPagosTest`, `EnvioCotizacionTest`, `PurgarCotizacionesTest` y
  `CalculadoraTotalesDocumentoTest`), `node --test "tests/js/*.test.js"` pasa (32), Pint no reporta
  cambios y `node --check` valida los scripts. La migración corrió en MySQL, y las consultas del
  listado (`withSum`, `whereHas` sobre clientes), el scope `vencidas`, el PDF y el correo se
  ejecutaron contra MySQL dentro de una transacción revertida. **No se revisó la UI en un navegador
  real**: conviene abrir `/cotizaciones/crear` y confirmar el buscador de artículos, el aviso de
  duplicado, el resumen en vivo y que un error de validación conserva las líneas; en el detalle,
  los diálogos de envío y pagos, y "Compartir por WhatsApp" en un teléfono (menú de compartir) y en
  escritorio (descarga + wa.me). La tarea programada de Windows no se dio de alta: los pasos están
  en `README.md`.

## Criterios de aceptación

1. Un usuario autenticado puede crear una cotización eligiendo un cliente y una o varias líneas
   (del catálogo o libres), viendo los totales desglosados mientras captura; queda en `borrador`
   con folio consecutivo propio (`COT-0001`, `COT-0002`, …) que nunca se reutiliza.
2. Los totales guardados son siempre los que calcula el servidor; coinciden con los mostrados en
   vivo para los casos del fixture compartido.
3. Un error de validación en el formulario conserva todas las líneas capturadas y marca el campo de
   la línea con error.
4. Elegir en el buscador un artículo que ya está en el documento no agrega otra línea: abre un aviso
   con la línea existente y su cantidad, y ofrece sumar unidades (por defecto 1) o cancelar. Sumar
   solo cambia la cantidad y los totales; cancelar no cambia nada. El servidor rechaza un documento
   con el mismo artículo en dos líneas.
5. Cada línea con artículo guarda el costo con descuento vigente al guardarse (`costo_unitario`),
   que no cambia aunque el artículo cambie después; una línea libre no tiene costo.
6. Enviar por correo adjunta el PDF, llega a los destinatarios capturados (prellenados con el
   correo del cliente) y pasa la cotización a `enviada`. Compartir por WhatsApp entrega el PDF al
   menú del aparato (o lo descarga y abre WhatsApp en escritorio) y también la deja `enviada`.
7. Solo en `enviada` con saldo pendiente se pueden registrar pagos. "Registrar anticipo" y "Pago
   total" aparecen solo si no hay anticipo; "Registrar saldo" solo si lo hay. Los modales de "Pago
   total" y "Registrar saldo" muestran el saldo pendiente como texto, sin campo de monto.
8. Un pago que alcanza el total pasa la cotización a `pagada`; uno parcial no. Ningún pago puede
   hacer que lo pagado supere el total, y un segundo anticipo se rechaza.
9. Solo se puede eliminar el último pago registrado; si con eso la cotización deja de estar
   cubierta, regresa de `pagada` a `enviada`. En `producto_entregado` los pagos no se eliminan.
10. Una cotización `pagada` puede marcarse como `producto_entregado`; desde ningún otro estado.
11. Solo es editable en `borrador`/`enviada`; editar una `enviada` la regresa a `borrador` y lo
    avisa. Con pagos, el total editado no puede quedar por debajo de lo pagado.
12. Una cotización `borrador`/`enviada` sin pagos puede eliminarse desde el listado y desde el
    detalle, con confirmación; se lleva sus líneas. Con pagos o en otro estado no hay botón y el
    servidor lo rechaza.
13. "Duplicar" crea una copia en `borrador` con folio nuevo, mismo cliente, líneas y descuento
    global, sin pagos, y lleva a su detalle.
14. `/cotizaciones` filtra por cliente, RFC, folio y estado, combinables y sin recargar, y por
    fecha con "Hoy", "Esta semana", "Este mes" (por defecto) o rango personalizado, en la hora de
    México. Los filtros viven en la URL y funcionan sin JavaScript.
15. `php artisan cotizaciones:purgar-vencidas` elimina las `borrador`/`enviada` sin pagos cuyo último
    movimiento tiene más de 30 días, deja intactas las demás y reporta cuántas borró; correrlo dos
    veces seguidas no borra nada la segunda vez. Está agendado diariamente.
16. Faltando 7 días o menos para el borrado automático, el listado marca la fila y el detalle
    muestra la fecha de eliminación y cómo evitarla; editar o reenviar reinicia el plazo y el aviso
    desaparece.
17. "Ver PDF" y "Descargar PDF" generan el documento con folio, cliente, líneas y totales, sin
    guardarlo en disco.
18. Ver, editar o actuar sobre una cotización ajena responde 404.
19. El menú muestra "Cotizaciones" con su icono.
20. Pint corre sin cambios, la suite Pest pasa y `node --test "tests/js/*.test.js"` pasa.

## Supuestos asumidos (registro completo)

1. Esta spec es la reescritura local de [remotas/008](remotas/008-cotizaciones.md) para la
   arquitectura Laravel + Blade + JavaScript nativo; conserva sus reglas de negocio y retira lo que
   dependía de módulos que aquí aún no existen.
2. Cada cotización pertenece al usuario que la crea y a uno de sus clientes (obligatorio), mismo
   aislamiento por `user_id` que 004/005/007.
3. Los 4 estados (`borrador`, `enviada`, `pagada`, `producto_entregado`) avanzan en orden. Solo
   retroceden en dos casos: editar una `enviada` la regresa a `borrador`, y eliminar el pago que la
   cubría regresa una `pagada` a `enviada`.
4. `borrador` → `enviada` ocurre al enviar por correo o al compartir por WhatsApp desde el aparato.
   Reenviar o volver a compartir no cambia el estado pero reinicia el plazo de caducidad.
5. Los pagos (anticipo, saldo, pago total) son registros internos capturados a mano, sin documento
   fiscal. Máximo un anticipo; saldo y pago total siempre valen el saldo pendiente, calculado por el
   servidor; nunca hay sobrepago.
6. Solo se registran pagos en `enviada`. Si una cotización con anticipo se edita y vuelve a
   `borrador`, hay que reenviarla antes de registrar el saldo.
7. Se puede eliminar un pago, siempre el último registrado, mientras la cotización no esté
   entregada. Es la vía para poder borrar una cotización que tuvo pagos.
8. `pagada` → `producto_entregado` es manual, sin validar inventario.
9. El folio es consecutivo por usuario y nunca se reutiliza, aunque se borre la cotización que lo
   tenía; el contador vive en el usuario.
10. Las líneas guardan copias desacopladas del artículo (descripción, modelo, precio, costo); el
    precio se precarga con el precio de venta sin IVA del artículo y es editable.
11. Se admiten líneas libres (sin artículo del catálogo), sin costo.
12. La tasa de IVA de la línea se precarga según el objeto de impuesto del artículo (`02` → 16%,
    otro → exento) y es editable; la línea libre empieza en 16%.
13. Un artículo aparece una sola vez por documento; duplicarlo se resuelve sumando unidades. La
    regla se aplica en el navegador (aviso) y en el servidor (validación).
14. Los totales usan el algoritmo de dos pasadas con prorrateo del descuento global, sin ajuste al
    peso; el centavo residual del prorrateo va a la línea de mayor importe.
15. El servidor calcula y guarda los totales; el resumen del formulario es informativo y no se
    compara contra nada.
16. Se elimina físicamente (sin papelera) una cotización `borrador` o `enviada` sin pagos.
17. Una cotización `borrador` o `enviada` sin pagos se elimina sola a los 30 días sin movimiento,
    con aviso a partir de 7 días antes.
18. La caducidad corre con el scheduler de Laravel, que en Windows depende de una tarea programada
    documentada en el README.
19. El correo se envía síncrono por el SMTP configurado.
20. El PDF se genera al vuelo con Dompdf, sin guardar copia.
21. WhatsApp se comparte desde el aparato del usuario (Web Share API o `wa.me` en escritorio); el
    servidor nunca envía mensajes de WhatsApp.
22. Las fechas se guardan en UTC y se filtran y muestran en la zona `America/Mexico_City`.
23. La conversión a factura queda fuera: la spec de facturación agregará el vínculo y el botón.
24. `costo_unitario` se guarda desde ahora para la futura utilidad de Tesorería; no se muestra en
    pantalla ni en el PDF.
25. La calculadora de totales y el script de la tabla de líneas son genéricos para que facturación
    los reutilice.
