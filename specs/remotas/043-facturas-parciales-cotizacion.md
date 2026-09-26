# Spec: Facturas por monto parcial sobre una cotización

## Historia de usuario

Como usuario quiero poder generar más de una factura (CFDI) sobre una misma cotización, cada una
por un monto menor al total, para poder timbrarle a mi cliente conforme va abonando en distintas
fechas, en vez de esperar a que pague todo para facturar una sola vez.

**Caso de uso guía:** la cotización 025, por $400.00, genera la factura F0025 por $200.00 y,
después, la factura 0026 por $200.00, con fechas distintas. Los folios pueden ser cualquiera —no
hay una numeración especial para estas facturas.

**Reglas explícitas del usuario**, ya resueltas más abajo en una sola validación (ver "Por qué las
dos reglas del usuario son la misma regla"):

- No se factura un monto parcial de una cotización que ya está completamente facturada.
- No se factura un monto que exceda lo que le falta por facturar a la cotización.
- Cada factura se genera como pago en una sola exhibición (PUE), nunca como parcialidades dentro de
  un mismo CFDI ni con el complemento de anticipo del SAT.

## Objetivo / Alcance

Permitir que una cotización tenga **varias facturas** (en vez de como máximo una, como hoy), cada
una por el monto que el usuario decida, sin que la suma exceda el total de la cotización. Cada
factura sigue siendo un CFDI de ingreso normal —las mismas pantallas, el mismo timbrado contra
Facturapi, el mismo PDF— solo que ahora puede representar una parte del total en vez de forzosamente
el todo.

**Solo aplica a Cotización.** El Pedido de mostrador (
[027-venta-mostrador-ticket.md](027-venta-mostrador-ticket.md)) usa el mismo patrón de vínculo
1:1 con Factura y **no se toca**: la autofactura de mostrador sigue siendo una sola factura por un
solo pedido.

### Esto NO cambia el estado de la cotización ni el sistema de pagos existente

El sistema ya tiene, desde [008-cotizaciones.md](008-cotizaciones.md), un mecanismo de pagos **no
fiscales** por cotización (`CotizacionPago`: anticipo/saldo/pago total) que sí genera movimientos
reales en Tesorería y que es lo único que hoy decide cuándo una cotización pasa a estado `pagada`.

Esta historia agrega un mecanismo **fiscal** completamente aparte: cuánto se ha facturado (CFDI)
contra esa misma cotización. Son dos números independientes —"cuánto ha pagado el cliente" y
"cuánto se le ha facturado"— que pueden no coincidir en ningún momento, y **ninguno de los dos
cambia por causa del otro**:

- El monto de una factura parcial **no** se toma ni se calcula de los pagos ya registrados en
  Tesorería: el usuario lo captura a mano, igual que captura las líneas de cualquier factura.
- Facturar —parcial o completo— **no** mueve el estado de la cotización. `pagada` sigue
  dependiendo únicamente de la suma de `CotizacionPago`, exactamente como hoy. Se decidió así
  explícitamente: dejar que la suma de facturas también disparara `pagada` habilitaría el botón
  "Marcar como entregado" sin que hubiera entrado dinero a Tesorería, rompiendo la garantía que el
  sistema da hoy entre "pagada" y "cobrada".
- Registrar un pago en Tesorería **no** descuenta nada del saldo pendiente por facturar, ni al
  revés.

Es la misma separación de conceptos que ya documentó 008 entre `CotizacionPago` y
`ComplementoPago`: "registrar un pago sobre una cotización no crea ni precarga nada en el
complemento de pago de la factura resultante". Aquí aplica igual, en la otra dirección.

### Por qué las dos reglas del usuario son la misma regla

Las reglas "no facturar una cotización ya pagada [léase: ya facturada]" y "no facturar una
cotización con parcial ya generado" parecen, leídas por separado, contradecir el propio caso de
uso (que genera **dos** facturas parciales sobre la misma cotización). Se resuelven con una sola
validación: **el monto de una factura nunca puede exceder el saldo pendiente por facturar de la
cotización.**

- Cuando el saldo pendiente por facturar es $200 de $400 (ya se facturaron los primeros $200), se
  puede facturar hasta $200 más —el caso de uso, permitido.
- Cuando el saldo pendiente por facturar llega a $0, ninguna factura nueva pasa —la primera regla,
  en su caso límite.

No hacen falta dos reglas de negocio distintas: la segunda es la primera, aplicada después de la
primera factura.

## Backend (Laravel)

### El vínculo Cotización↔Factura pasa de 1:1 a 1:N

Hoy `cotizaciones.factura_id` guarda el id de **la** factura de esa cotización (columna única,
`nullOnDelete`), y `Factura::cotizacion()` es un `HasOne` que lee esa columna desde el otro lado.
Para permitir varias facturas por cotización, el vínculo se **voltea**: pasa a vivir en
`facturas.cotizacion_id`, y una cotización tiene ahora `facturas(): HasMany`.

**Dos migraciones**, no una, porque **ya hay datos reales que proteger**: el sistema está en
producción desde el 2026-08-18 ([018-despliegue-hostinger.md](018-despliegue-hostinger.md)) y las
cotizaciones que ya tienen `factura_id` son facturas timbradas de verdad. A diferencia de historias
anteriores, aquí **sí hay que rescatar el vínculo**:

1. `..._add_cotizacion_id_a_facturas_table.php`: agrega `facturas.cotizacion_id`
   (`foreignId nullable → cotizaciones, nullOnDelete`, con índice), y en el mismo `up()` **rellena**
   esa columna leyendo `cotizaciones.factura_id` fila por fila (`UPDATE facturas SET cotizacion_id
   = cotizaciones.id WHERE facturas.id = cotizaciones.factura_id`, o su equivalente en query
   builder). El `down()` no necesita deshacer el backfill, solo tirar la columna.
2. `..._elimina_factura_id_de_cotizaciones_table.php`: tira la columna `factura_id` y su llave
   foránea de `cotizaciones`, **solo después** de que la anterior corrió y copió el dato.

Las dos corren en el mismo despliegue; separarlas dentro del mismo lote deja explícito que copiar
tiene que pasar antes de tirar, y facilita revisar el backfill sola en la revisión de código.

### `Cotizacion`

- Se quita `factura_id` de `#[Fillable]` y el método `factura(): BelongsTo`.
- Se agrega `facturas(): HasMany` (`hasMany(Factura::class)`).
- Se agregan dos métodos, hermanos de `totalPagado()`/`saldoPendiente()` que ya existen para los
  pagos no fiscales:
  ```php
  public function totalFacturado(): float
  {
      return (float) $this->facturas()->where('estado', '!=', EstadoFactura::Cancelada->value)->sum('total');
  }

  public function saldoPendienteFacturar(): float
  {
      return round(max(0, (float) $this->total - $this->totalFacturado()), 2);
  }
  ```
  **Cuentan todas las facturas salvo las canceladas** —no solo las timbradas. El vínculo se escribe
  desde que la factura se crea (antes de intentar timbrar, igual que hoy hace `factura_id`), así
  que una factura recién creada, aunque el timbrado todavía esté en curso o haya fallado
  (`pendiente`), ya reserva su monto contra el saldo: sin esto, dos facturas fallidas por $200 cada
  una, ambas reintentables, podrían timbrarse las dos y sumar $400 más allá de lo que quedaba. Al
  cancelar una factura, su monto **vuelve a estar disponible** automáticamente, porque deja de
  cumplir el filtro —sin código adicional.
- `puedeEliminarse()`: cambia `$this->factura_id === null` por `! $this->facturas()->exists()`.
- El scope `vencidas()` (purga automática de cotizaciones sin movimiento) cambia
  `whereNull('factura_id')` por `whereDoesntHave('facturas')`.

### `Factura`

- Se agrega `cotizacion_id` a `#[Fillable]`.
- `cotizacion(): HasOne` pasa a ser `cotizacion(): BelongsTo` (`belongsTo(Cotizacion::class)`),
  ahora que la columna vive en la propia tabla `facturas`.
- `mueveInventario()` **no cambia una sola línea**: sigue siendo `!
  $this->cotizacion()->exists() && ! $this->pedido()->exists()`, y sigue siendo correcto, porque
  evalúa si **esta** factura tiene cotización vinculada, sin que le importe cuántas otras facturas
  tenga esa misma cotización. El inventario se sigue descontando una sola vez, al marcar la
  cotización como entregada (ver [017-inventario.md](017-inventario.md)), sin importar en cuántas
  facturas se haya repartido el cobro.

### Validación al crear una factura (`FacturaController::store`)

`StoreFacturaRequest` pierde la condición `whereNull('factura_id')` sobre `cotizacion_id` (esa
columna ya no existe): la regla se reduce a que la cotización exista y sea del usuario.

`lineas.*.articulo_id` pasa de `required` a `nullable`, con la misma regla que ya usa
`StorePedidoRequest` (027) para su **línea libre**: un renglón sin artículo del catálogo,
identificado solo por su `descripcion`. Una factura por monto parcial es exactamente eso —"Anticipo
cotización 0025"—, y `FacturapiService` ya sabe timbrar una línea sin artículo, con las claves SAT
genéricas de respaldo (`product_key`/`unit_key`) que usa desde 012 y 027. No hace falta inventar
nada nuevo del lado fiscal, solo dejar de exigir un artículo donde nunca hizo falta.

La regla de saldo —la única que de verdad importa— se valida en el controlador, junto a
`calcularYValidarTotal()` y con el mismo estilo (`ValidationException` antes de abrir la
transacción):

```php
if (! empty($datos['cotizacion_id'])) {
    $cotizacion = Cotizacion::where('id', $datos['cotizacion_id'])
        ->where('user_id', $request->user()->id)
        ->firstOrFail();

    $saldo = $cotizacion->saldoPendienteFacturar();

    if ($saldo <= 0) {
        throw ValidationException::withMessages([
            'cotizacion_id' => 'Esta cotización ya no tiene saldo pendiente por facturar.',
        ]);
    }

    if ($calculo['total'] - $saldo > 0.01) {
        throw ValidationException::withMessages([
            'total' => "El total de la factura no puede exceder el saldo pendiente por facturar de la cotización (\${$saldo}).",
        ]);
    }
}
```

Dentro de la transacción, el vínculo se escribe con `$factura->update(['cotizacion_id' =>
$datos['cotizacion_id']])` en vez del `Cotizacion::where(...)->update(['factura_id' =>
$factura->id])` de hoy —mismo momento (antes de `intentarTimbrar()`, por la misma razón de
inventario que ya explica el código—, solo que ahora escribe del lado de la factura.

**Esta validación es una sola, sin distinguir "factura completa" de "factura parcial".** Ambas
pasan por el mismo endpoint; lo único que cambia es si el monto enviado agota el saldo pendiente
(cierra la cotización) o lo deja abierto (parcial). El backend no necesita saber cuál de las dos
cree el usuario que está haciendo.

### `CotizacionController`

- `destroy()`: `abort_if($cotizacion->factura_id !== null, ...)` pasa a
  `abort_if($cotizacion->facturas()->exists(), 422, 'No se puede eliminar una cotización que ya generó alguna factura.')`.
- `show()` (y cualquier otro `load()` que hoy pida `'factura'`) pide `'facturas'` en su lugar.
- **Toda respuesta que el detalle de la cotización usa para reemplazar la que tiene en pantalla
  carga las mismas relaciones que `show()`**, `facturas` incluida: registrar pago, marcar como
  entregado, deshacer la entrega, crear el envío a domicilio y marcar el envío como entregado
  (`CotizacionEnvioController`). La lista vive en una sola constante,
  `Cotizacion::RELACIONES_DETALLE`, que todas esas acciones y `show()` comparten, para que una
  relación nueva no se agregue en una y se olvide en las demás. Sin `facturas` en la respuesta, la
  pantalla —que da por hecho que la lista existe para calcular el botón "Facturar"— se quedaría en
  blanco justo después de la acción.

### `CotizacionResource`

Sustituye `factura_id`/`factura_estado` por:

```php
'total_facturado' => $this->totalFacturado(),
'saldo_pendiente_facturar' => $this->saldoPendienteFacturar(),
'facturas' => FacturaResumenResource::collection($this->whenLoaded('facturas')),
```

`FacturaResumenResource` es un recurso nuevo, deliberadamente chico (no el `FacturaResource`
completo, que trae líneas y complemento de pago que aquí no hacen falta y obligarían a cargar más
relaciones de las necesarias): `id`, `folio`, `estado`, `total`, `uuid_fiscal`, `fecha_timbrado`,
`error_timbrado`. Es lo mínimo para que el detalle de la cotización pinte una lista y un enlace por
factura.

## Frontend (Vue 3)

### `CotizacionDetalleView.vue`

**Nueva sección "Facturas"**, hermana de la de pagos, que lista cada factura asociada (folio,
estado, total, fecha, con enlace a su detalle). Hoy la pantalla no muestra ninguna lista porque
asumía como máximo una factura; con varias, sin esta sección no habría forma de verlas ni de
llegar a ellas desde la cotización.

**El botón "Facturar" se recalcula** a partir de `facturas` y `saldo_pendiente_facturar` en vez de
`factura_id`/`factura_estado`:

```ts
const facturaPendiente = computed(
  () => cotizacion.value?.facturas.find((f) => f.estado === 'pendiente') ?? null,
)
const facturarEstado = computed<'sin-factura' | 'disponible' | 'pendiente' | 'agotado'>(() => {
  if (facturaPendiente.value) return 'pendiente'
  if ((cotizacion.value?.saldo_pendiente_facturar ?? 0) <= 0) return 'agotado'
  return (cotizacion.value?.facturas.length ?? 0) === 0 ? 'sin-factura' : 'disponible'
})
```

| Estado | Etiqueta | Acción |
| --- | --- | --- |
| `sin-factura` | "Facturar" | Va a `/facturas/crear?cotizacion_id={id}`, igual que hoy |
| `disponible` | "Facturar saldo restante" | Mismo destino; ya hay al menos una factura y queda saldo |
| `pendiente` | "Reintentar factura" | Va a editar **esa** factura pendiente en particular, no una nueva |
| `agotado` | "Facturada" (deshabilitado) | Sin saldo pendiente por facturar |

### `FacturaFormView.vue` — precarga desde cotización

Hoy, con `cotizacion_id` en la URL, el formulario siempre precarga el cliente fijo y **todas las
líneas** de la cotización (comportamiento que se conserva tal cual para facturar el total). Se
agrega la posibilidad de facturar un monto parcial:

- **Si la cotización todavía no tiene ninguna factura** (`facturas` vacío al cargarla): se muestra
  un interruptor, "El total de la cotización" (activado por defecto, comportamiento actual e
  idéntico: precarga todas las líneas) frente a "Un monto parcial".
- **Si la cotización ya tiene alguna factura** (parcial o completa) con saldo aún pendiente: no se
  ofrece la opción "el total" —ya no cabría en el saldo—; el formulario arranca directo en modo
  "monto parcial".

**"Un monto parcial" no es una tabla de artículos.** El usuario captura dos campos, nada más:

- **Monto** (con IVA incluido, a la tasa general de 16%): lo que va a facturar, p. ej. $500.00 de
  una cotización de $1,000.00. No elige productos ni arma líneas para llegar a esa cifra.
- **Descripción**: precargada como *"Anticipo cotización {folio}"*, editable.

Con esos dos datos el formulario arma, en el momento, una sola línea **libre** —sin artículo del
catálogo, igual que ya admite `StorePedidoRequest` (027)— con `cantidad: 1` y el precio unitario
sin IVA que, a la tasa general, cierra en el monto capturado
(`redondeo2(monto / 1.16)`). Esa línea alimenta el mismo cálculo de totales que usa el resto del
formulario (`calcularTotales(..., redondearAlPeso: true)`), así que el peso cerrado que ya existe
desde [030](030-total-al-peso-cerrado.md) hace que un monto en pesos exactos ($500.00) cierre
exacto también después de sacarle el IVA y volver a sumarlo.

**El descuento global de la cotización, si tenía uno, se apaga mientras dura el modo "parcial"** y
se recupera al volver a "el total": el monto que escribe el usuario ya es el total exacto a
facturar, aplicarle además el descuento global de la cotización lo movería sin que nadie lo pidiera.

El tope real lo pone el backend (`saldo_pendiente_facturar`, ver la validación de arriba); en
pantalla se muestra el saldo como referencia y se deshabilita el botón de guardar si el monto
capturado ya lo excede, pero quien de verdad rechaza el exceso es el servidor.

**Descartado durante la redacción de esta historia:** un primer intento reutilizó el componente de
líneas existente (`DocumentoLineas.vue`) dejándolo vacío para que el usuario eligiera artículos del
catálogo hasta sumar el monto deseado. El usuario lo corrigió explícitamente: eso obliga a armar el
monto a partir de productos reales —"3 sellos" en vez de un número—, cuando lo que quiere es
escribir *"factura $500.00"* directo. La línea libre (sin artículo) es lo que permite esa captura de
dos campos sin inventar un artículo "de sistema" para anticipos.

## Fuera de alcance

- **Pedido de mostrador** ([027](027-venta-mostrador-ticket.md)): sigue siendo 1:1 con Factura, sin
  parcialidades. Nada de esta historia lo toca.
- **Complemento de anticipo del SAT, CFDI relacionados o `c_TipoRelacion`.** Cada factura parcial es
  un CFDI de ingreso (`I`) independiente, sin relación fiscal declarada con las demás facturas de la
  misma cotización ante el SAT.
- **Facturas en parcialidades dentro de un mismo CFDI o método de pago PPD para este flujo.** Cada
  factura, completa o parcial, se sigue emitiendo como PUE, igual que cualquier otra factura hoy;
  esta historia no cambia esa elección, la reafirma.
- **Descomposición proporcional de las líneas de producto** en una factura parcial: no se reparte
  automáticamente "2 de 5 piezas" entre dos facturas; el usuario decide qué línea(s) y montos
  factura cada vez.
- **Tocar el estado `pagada` de la cotización o el sistema de `CotizacionPago`/Tesorería.** Quedan
  exactamente como están.
- **Límite al número de facturas por cotización.** El caso de uso muestra dos; el sistema no impone
  un máximo, solo el tope del saldo pendiente.
- **Un artículo o catálogo "de sistema" para anticipos.** El usuario usa su propio catálogo, como
  para cualquier otra línea.
- **Cambios al Pedido/mostrador, a Tesorería o a los reportes existentes.**

## Estado de implementación

Implementada el 2026-09-04.

- **Archivos nuevos**: `database/migrations/2026_09_04_000000_add_cotizacion_id_a_facturas_table.php`
  (agrega la columna y hace el backfill), `..._000100_elimina_factura_id_de_cotizaciones_table.php`
  (tira la columna vieja), `app/Http/Resources/FacturaResumenResource.php`.
- **El backfill sí tenía datos reales que copiar**: al aplicar la migración en la base de trabajo
  había 8 cotizaciones con `factura_id` ya asignado. Las 8 conservaron el vínculo tras la migración
  (verificado con una consulta aparte antes de dar por buena la migración).
- **La validación de saldo vive en `FacturaController::validarSaldoPendienteFacturar()`**, un método
  privado nuevo junto a `calcularYValidarTotal()`, tal como planeaba la spec: una sola regla, sin
  distinguir "completa" de "parcial".
- **Mostrador (`MostradorCotizacionDetalleView.vue`) no ofrece elegir "monto parcial".** La spec
  solo detallaba el formulario de escritorio (`FacturaFormView.vue`); la pantalla de mostrador —
  touch, para cobrar rápido— se dejó facturando siempre el saldo completo pendiente, igual que
  antes. Si ese saldo ya no cabe (porque se generó una parcial desde escritorio), el backend
  rechaza con el mismo mensaje de siempre y aparece donde ya se mostraban los errores del
  formulario. Es una simplificación de alcance, no un olvido.
- **Pruebas nuevas** en `CotizacionesTest.php`: saldo agotado rechaza factura nueva, segunda
  factura parcial permitida mientras quede saldo, monto que excede el saldo se rechaza, cancelar
  una factura parcial libera su monto. Las pruebas ya existentes que creaban `factura_id` a mano
  (duplicar, no reutilizar cotización facturada, purga de vencidas, cancelación sin devolver
  inventario) se adaptaron a `facturas()`/`cotizacion_id` sin cambiar lo que verifican.
- **Verificación**: la suite Pest completa pasa (691 tests); Pint corre limpio sobre los archivos
  nuevos y modificados. En frontend, `vue-tsc -b && vite build` compila sin errores de tipos,
  Vitest (96 tests) y ESLint pasan limpios, y Prettier se aplicó sobre los dos archivos que lo
  necesitaban. **No se verificó visualmente en un navegador real** (misma limitación de entorno que
  el resto de las historias): falta abrir una cotización con más de una factura y confirmar en
  pantalla la sección "Facturas", el interruptor "el total"/"un monto parcial" y los mensajes de
  saldo pendiente.
- **Corrección tras el primer despliegue (mismo día):** el modo "monto parcial" reutilizaba
  `DocumentoLineas.vue` vacío, dejando que el usuario eligiera artículos del catálogo hasta sumar
  el monto deseado. El usuario lo corrigió: quería escribir el monto directo, sin armar la cifra a
  partir de productos. Se reemplazó por un mini-formulario (Monto + Descripción) que arma una sola
  línea **libre** (`articulo_id: null`), lo que exigió relajar `StoreFacturaRequest` (mismo cambio
  que ya tenía `StorePedidoRequest` desde 027) y agregar una prueba (`una factura parcial se puede
  generar como linea libre...`) para dejarlo cubierto. De paso se corrigió que el descuento global
  de la cotización, si tenía uno, se quedaba pegado en modo "parcial" y habría distorsionado el
  monto capturado; ahora se apaga mientras dura ese modo. Suite Pest (692 tests), build de
  frontend, Vitest y Pint/Prettier vueltos a correr limpios después de esta corrección.
- **Corrección del 2026-09-23:** en producción, marcar una cotización como entregada dejaba la
  pantalla en blanco (`Cannot read properties of undefined (reading 'find')`). Solo `show()` pedía
  `facturas`; las acciones que devuelven la cotización actualizada al detalle (pago, entrega,
  deshacer entrega, envío) no, y el cálculo del botón "Facturar" tronaba. Se unificaron en
  `Cotizacion::RELACIONES_DETALLE` (ver `CotizacionController` arriba) y se agregó una prueba que
  verifica que esas respuestas traen `facturas`.

## Criterios de aceptación

1. Se puede generar una segunda factura (folio propio, fecha propia) sobre una cotización que ya
   tiene una factura timbrada, mientras quede saldo pendiente por facturar.
2. Una factura cuyo total exceda el saldo pendiente por facturar de la cotización se rechaza con un
   mensaje claro, sin llegar a timbrarse.
3. Una cotización cuya suma de facturas (no canceladas) ya iguala su total no admite ninguna
   factura adicional; el intento se rechaza antes de calcular líneas.
4. Cancelar una factura parcial libera su monto: el saldo pendiente por facturar vuelve a subir esa
   cantidad, y se puede volver a facturar.
5. Generar cualquier factura —completa o parcial— **no** cambia el `estado` de la cotización; sigue
   dependiendo solo de los pagos registrados con `CotizacionPago`.
6. El inventario se descuenta una sola vez, al marcar la cotización como `producto_entregado`, sin
   importar en cuántas facturas se haya repartido el cobro.
7. Tras aplicar las migraciones, una cotización que en producción ya tenía `factura_id` conserva el
   vínculo con esa misma factura, ahora vía `facturas.cotizacion_id`.
8. Eliminar una cotización que tenga cualquier factura asociada —aunque sea una sola parcial— sigue
   bloqueado con `422`.
9. La purga automática de cotizaciones sin movimiento ([008](008-cotizaciones.md)) no borra una
   cotización que tenga alguna factura, sin importar si esa factura cubre el total o no.
10. El detalle de la cotización muestra una sección "Facturas" con folio, estado, total y fecha de
    cada una, con enlace a su detalle.
11. El botón de facturar en el detalle de la cotización muestra "Facturar", "Facturar saldo
    restante", "Reintentar factura" o "Facturada" (deshabilitado) según la tabla de estados
    definida arriba.
12. Al facturar desde una cotización sin facturas previas, el formulario ofrece elegir entre "el
    total" (líneas precargadas, como hoy) y "un monto parcial" (los campos Monto y Descripción).
13. Al facturar desde una cotización que ya tiene alguna factura, el formulario arranca directo en
    modo "monto parcial", con la descripción precargada como "Anticipo cotización {folio}" y el
    saldo pendiente por facturar como referencia.
14. En modo "monto parcial", capturar $500.00 genera una factura por exactamente $500.00 (con IVA
    al 16% incluido), como una sola línea sin artículo del catálogo, con la descripción capturada.
15. En modo "monto parcial", un monto que exceda el saldo pendiente deshabilita el botón de guardar
    y muestra un aviso; si de todos modos se envía (p. ej. el saldo cambió en otra pestaña), el
    backend lo rechaza igual que a cualquier otra factura.
16. Cada factura generada desde una cotización —parcial o completa— es indistinguible ante
    Facturapi de cualquier otra factura normal: mismo tipo de comprobante (`I`), mismo método de
    pago (PUE), sin nodo de CFDI relacionados ni de complemento de anticipo.
17. `Factura::mueveInventario()` sigue devolviendo `false` para toda factura con `cotizacion_id`,
    sin importar cuántas otras facturas tenga esa misma cotización.
18. Registrar un pago, marcar la cotización como entregada, deshacer la entrega, crear el envío y
    marcar el envío como entregado devuelven la cotización con su lista `facturas`; el detalle sigue
    mostrándose (con la sección "Facturas" y el botón de facturar correctos) después de cada una.

## Supuestos asumidos (registro completo)

1. La relación Cotización↔Factura deja de ser 1 a 1 y pasa a permitir varias facturas por una misma
   cotización, siempre que la suma de sus montos no exceda el total de la cotización.
2. El monto de cada factura parcial lo captura manualmente el usuario; no se calcula a partir de
   los pagos ya registrados en Tesorería vía `CotizacionPago` —son sistemas independientes.
3. Cada factura parcial se emite como PUE ("pago en una sola exhibición"), nunca como PPD ni con el
   complemento de "parcialidades"/"anticipos" del SAT.
4. "Cotización ya pagada" (regla del usuario) significa que la suma de montos de sus facturas no
   canceladas ya es igual al total de la cotización —sin relación con el estado `pagada` que ya
   existe y que depende de `CotizacionPago` (ver "Esto NO cambia el estado de la cotización").
5. **(Resuelto, ver "Por qué las dos reglas del usuario son la misma regla")** Las dos reglas del
   usuario se implementan como una sola validación: el monto de una factura nunca puede exceder el
   saldo pendiente por facturar.
6. El monto de una factura parcial no puede exceder el saldo pendiente por facturar de la
   cotización.
7. **(Corregido por el usuario tras la primera implementación, ver "Frontend — precarga desde
   cotización")** La factura parcial no es una tabla de artículos: el usuario captura el **monto**
   directo (con IVA incluido) y una **descripción** ("Anticipo cotización 0025"), sin elegir
   productos del catálogo para llegar a esa cifra. El sistema arma una sola línea **libre** (sin
   artículo), igual que ya admite `StorePedidoRequest` (027). Un primer intento reutilizaba el
   componente de líneas dejándolo vacío para elegir artículos hasta sumar el monto —"3 sellos" en
   vez de un número—, y el usuario lo corrigió explícitamente. No hay descomposición proporcional
   de las líneas de la cotización, con o sin esta corrección.
8. No se contempla relación fiscal ante el SAT (CFDI relacionados, complemento de anticipo) entre
   las facturas parciales de una misma cotización.
9. **(Revertido tras aclarar con el usuario, ver "Esto NO cambia el estado de la cotización")**
   Facturar —parcial o completo— no cambia el estado de la cotización. Se descartó la versión
   original ("al llegar la suma de facturas al total, pasa a `pagada`") porque habilitaría
   "Marcar como entregado" sin que hubiera entrado dinero real a Tesorería.
10. La cotización no requiere ningún estado particular (ej. "enviada") para facturar, parcial o
    completo: **no existe hoy** tal restricción en el código (`StoreFacturaRequest` solo valida que
    la cotización exista y sea del usuario), así que esta historia no la introduce.
11. El inventario se sigue descontando solo una vez, al marcar la cotización como entregada, no por
    cada factura parcial.
12. No hay límite en el número de facturas parciales por cotización, mientras no se exceda el
    total.
13. Las facturas parciales usan el folio interno normal y correlativo del usuario, sin folio
    especial reservado.
14. Si una factura parcial se cancela, su monto deja de contar para el saldo facturado y vuelve a
    estar disponible.
15. **(Adición técnica)** El vínculo se voltea de `cotizaciones.factura_id` a
    `facturas.cotizacion_id`, en dos migraciones: una que agrega la columna y **rellena el vínculo
    ya existente en producción**, y otra que tira la columna vieja. No se puede tratar como si no
    hubiera datos que rescatar: el sistema está en producción desde el 2026-08-18.
16. **(Adición técnica)** `Cotizacion::totalFacturado()`/`saldoPendienteFacturar()`, hermanos de
    los ya existentes `totalPagado()`/`saldoPendiente()`, cuentan toda factura salvo las
    canceladas —no solo las timbradas—, porque el vínculo se reserva desde que la factura se crea,
    antes de intentar timbrar.
17. **(Adición técnica)** `Factura::mueveInventario()` no cambia: sigue evaluando solo si **esa**
    factura tiene cotización vinculada.
18. **(Adición técnica)** La validación de saldo vive en `FacturaController::store()`, junto a
    `calcularYValidarTotal()`, no en `StoreFacturaRequest`: necesita el total ya calculado de las
    líneas y un método del modelo, no una regla estática de existencia.
19. **(Adición técnica)** Se agrega `FacturaResumenResource`, chico, para listar las facturas de
    una cotización sin cargar líneas ni complemento de pago que ahí no hacen falta.
20. **(Adición técnica)** El formulario de crear factura desde cotización ofrece un interruptor
    "el total" / "un monto parcial" solo cuando la cotización aún no tiene ninguna factura; en
    cuanto ya tiene alguna, arranca directo en modo "monto parcial", porque "el total" ya no
    cabría en el saldo.
21. **(Adición técnica)** `StoreFacturaRequest` relaja `lineas.*.articulo_id` de `required` a
    `nullable`, con la misma regla que ya usa `StorePedidoRequest` (027) para su línea libre. No es
    exclusivo del modo "monto parcial": cualquier factura, venga o no de una cotización, puede
    llevar una línea sin artículo si el usuario la arma a mano.
22. **(Adición técnica)** La tasa de IVA del modo "monto parcial" queda **fija en 16%**, sin
    selector: es la tasa general y evita un tercer campo. El precio unitario sin IVA se calcula
    como `redondeo2(monto / 1.16)`, y el peso cerrado que ya aplica el formulario
    ([030](030-total-al-peso-cerrado.md)) hace que un monto en pesos exactos cierre exacto también
    después de sacarle el IVA y volver a sumarlo.
