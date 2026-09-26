# Spec: Dirección en el envío a domicilio y envío directo para clientes distribuidores

## Historia de usuario

Como usuario, cuando capturo un envío a domicilio ([038](038-produccion-ordenes-trabajo.md)) hoy no
tengo dónde anotar a dónde se entrega: solo capturo nombre y teléfono de quien recibe. Necesito un
campo de dirección.

Además, mis clientes distribuidores compran mecanismos que ya existen en inventario — no algo que
haya que fabricar — y muchas veces piden que se los envíe a domicilio. Hoy la única forma de generar
un envío pasa por abrir una Orden de Trabajo, y una Orden de Trabajo implica un flujo de producción
(`pendiente → en_producción → listo_para_entregar`) que no tiene sentido para un cliente que solo
está comprando de existencia. Quiero poder capturar el envío directamente sobre la cotización del
distribuidor, sin pasar por Producción.

## Objetivo / Alcance

Dos cambios sobre el envío a domicilio de [038](038-produccion-ordenes-trabajo.md):

1. El modelo `Envio` gana un campo **`direccion`** (texto libre, un solo campo — no se desglosa en
   calle/número/colonia), obligatorio al capturar cualquier envío nuevo.
2. El envío deja de depender exclusivamente de una Orden de Trabajo: ahora puede colgar **también
   directamente de una `Cotizacion`**, cuando su cliente es distribuidor
   ([033](033-precio-distribuidor.md)) y la cotización ya tiene al menos un pago registrado. Este
   envío directo:
   - No pasa por ningún estado de Producción ni aparece en `/produccion`.
   - Puede coexistir con una Orden de Trabajo de la misma cotización (son independientes: una
     cotización de distribuidor podría tener, por ejemplo, un mecanismo de existencia que se envía
     directo y, en la misma cotización, otro artículo que sí requiere fabricación).
   - Usa las mismas tarifas configurables A/B/C y la misma regla de Tesorería (prepagado genera
     movimiento de ingreso; por cobrar no toca Tesorería) que ya existen para el envío de Producción.
   - Se marca "entregado" con un botón propio en su propia ficha, sin relación con el QR/estado de
     la `Cotizacion` (que sigue existiendo para la entrega en mostrador, sin cambios).

No se toca nada del flujo de envío ya existente para `Pedido`/`Cotizacion` **vía Orden de Trabajo**
más que agregarle el campo `direccion`.

## Backend (Laravel)

### Migración: `Envio` pasa a relación polimórfica y gana dos columnas

Nueva migración (no se edita la que ya creó `envios`, esa tabla ya vive en producción):

```php
Schema::table('envios', function (Blueprint $table) {
    $table->string('documentable_type')->nullable()->after('id');
    $table->unsignedBigInteger('documentable_id')->nullable()->after('documentable_type');
    $table->string('direccion')->nullable()->after('telefono_receptor');
    $table->timestamp('entregado_en')->nullable()->after('forma_pago');
});

DB::table('envios')->whereNotNull('orden_trabajo_id')->update([
    'documentable_type' => (new OrdenTrabajo())->getMorphClass(),
    'documentable_id' => DB::raw('orden_trabajo_id'),
]);

Schema::table('envios', function (Blueprint $table) {
    $table->dropForeign(['orden_trabajo_id']);
    $table->dropColumn('orden_trabajo_id');
    $table->unique(['documentable_type', 'documentable_id']);
});
```

`documentable_type`/`documentable_id` quedan `nullable` a nivel de columna únicamente por el mismo
motivo técnico que en `Movimiento` y `OrdenTrabajo` (backfill primero, restricción después no aporta
nada distinto); en la práctica todo envío nuevo los trae siempre. `direccion` queda `nullable` a
nivel de columna a propósito: los envíos ya existentes en producción no tienen dirección capturada
y no se les exige retroactivamente — la obligatoriedad vive en la validación de los formularios
nuevos, no en la base de datos.

### Modelo `Envio`

```php
public function documentable(): MorphTo
{
    return $this->morphTo();
}

public function conceptoMovimiento(): string
{
    return match (true) {
        $this->documentable instanceof OrdenTrabajo => 'Envío de Orden '.$this->documentable->folioFormateado(),
        $this->documentable instanceof Cotizacion => 'Envío de Cotización '.$this->documentable->folioFormateado(),
    };
}
```

`ordenTrabajo(): BelongsTo` se elimina — el único caller (`EnvioController`) pasa a usar
`documentable`. `direccion` y `entregado_en` se suman a `#[Fillable]`.

`OrdenTrabajo::envio()` y la nueva `Cotizacion::envio()` quedan como `morphOne(Envio::class,
'documentable')` — misma técnica que `OrdenTrabajo::documentable()` (038) y `Movimiento::documentable()`
(010). El índice único compuesto sigue garantizando **un envío como máximo por documento**, y como
`OrdenTrabajo` y `Cotizacion` son filas distintas, nada impide que una cotización tenga su propio
envío directo *y* que su Orden de Trabajo (si la tiene) tenga el suyo — son dos registros de `Envio`
distintos, cada uno colgado de su propio documento.

### `App\Http\Requests\EnvioRequest` (se mueve fuera del namespace `Produccion`, ahora es compartida)

Mismas reglas que hoy (`nombre_receptor`, `telefono_receptor`, `fecha_recepcion`, `hora_recepcion`,
`tarifa`, `forma_pago`, `cuenta_id` condicional) más:

```php
'direccion' => ['required', 'string', 'max:255'],
```

Ambos controladores (`EnvioController` para Orden de Trabajo, `CotizacionEnvioController` para el
envío directo) la reutilizan sin cambios.

### `EnvioController::store` (Orden de Trabajo — ajuste mínimo sobre 038)

Sin cambios de reglas de negocio; solo pasa `direccion` al `create()` y usa `documentable` en vez de
`ordenTrabajo` para armar el concepto del movimiento:

```php
$envio = $orden->envio()->create([
    ...$datosDeSiempre,
    'direccion' => $datos['direccion'],
]);
// ...
$envio->setRelation('documentable', $orden)->conceptoMovimiento(),
```

### `CotizacionEnvioController` (nuevo)

```
POST /api/v1/cotizaciones/{cotizacion}/envio           (misma forma que EnvioRequest)
POST /api/v1/cotizaciones/{cotizacion}/envio/entregar
```

```php
public function store(EnvioRequest $request, Cotizacion $cotizacion): CotizacionResource
{
    abort_unless($cotizacion->cliente_id && $cotizacion->cliente?->es_distribuidor, 422,
        'Este envío directo solo aplica a cotizaciones de clientes distribuidores.');
    abort_unless($cotizacion->tienePagos(), 422,
        'La cotización necesita al menos un pago registrado para generar un envío.');
    abort_if($cotizacion->envio()->exists(), 422, 'Esta cotización ya tiene un envío registrado.');

    // mismo cuerpo que EnvioController::store: congela tarifa, crea el Envio colgado de
    // $cotizacion, y si forma_pago = prepagado registra el movimiento en Tesorería con
    // $envio->setRelation('documentable', $cotizacion)->conceptoMovimiento().

    return new CotizacionResource($cotizacion->fresh('envio'));
}

public function entregar(Cotizacion $cotizacion): CotizacionResource
{
    $envio = $cotizacion->envio()->firstOrFail();
    abort_if($envio->entregado_en !== null, 422, 'Este envío ya fue marcado como entregado.');

    $envio->update(['entregado_en' => now()]);

    return new CotizacionResource($cotizacion->fresh('envio'));
}
```

Marcar `entregado_en` en el envío directo **no toca** `Cotizacion::estado` ni
`Cotizacion::entregado_en` (el campo que ya existe para la entrega por QR/mostrador de 038): son dos
mecanismos independientes por diseño — uno es "el paquete salió y llegó", el otro es "el documento se
cerró".

`importe_pendiente` de la ficha del envío directo sigue la misma regla que 038: saldo pendiente de
la `Cotizacion` **+** el monto del envío únicamente si `forma_pago = por_cobrar`.

### `EnvioResource`

Suma `direccion` y `entregado_en` (`?string`, formato ISO o `null`) a los campos que ya expone hoy.

### Rutas

```php
Route::post('cotizaciones/{cotizacion}/envio', [CotizacionEnvioController::class, 'store']);
Route::post('cotizaciones/{cotizacion}/envio/entregar', [CotizacionEnvioController::class, 'entregar']);
```

### Tests

- `EnvioTest` (existente, 038): se extiende para exigir `direccion` en la validación; el resto de
  sus casos sigue igual.
- `CotizacionEnvioTest` (nuevo):
  - Solo se puede crear si `cliente.es_distribuidor` es `true` (`422` si el cliente no es
    distribuidor o la cotización no tiene cliente).
  - Solo se puede crear con al menos un pago registrado (`422` sin pagos).
  - Un segundo intento sobre la misma cotización responde `422` (único por documento).
  - `prepagado` genera movimiento de Tesorería con el mismo mecanismo que 038; `por_cobrar` no
    genera ninguno.
  - `entregar` exige que exista el envío y que no esté ya entregado; pone `entregado_en` y no toca
    `Cotizacion::estado` ni `Cotizacion::entregado_en`.
  - Una cotización puede tener a la vez su propio envío directo y, si generó una Orden de Trabajo,
    el envío de esa orden — ambos coexisten como registros independientes.

## Frontend (Vue 3)

### Componentes compartidos

`FormularioEnvio.vue` y `FichaEnvio.vue` se extraen de `OrdenTrabajoDetalleView.vue` (donde hoy viven
inline) para reutilizarse también en `CotizacionDetalleView.vue`: mismos campos, mismo botón
Compartir vía `lib/compartir.ts`. `FormularioEnvio.vue` gana el campo **Dirección** (`Textarea` o
`Input`, obligatorio). `FichaEnvio.vue` muestra la dirección en el texto compartible junto con
nombre/teléfono/fecha/hora, y cuando el envío no tiene Orden de Trabajo detrás, agrega:

- Un botón **"Marcar entregado"** (mientras `entregado_en` sea `null`).
- La leyenda "Entregado el {fecha/hora}" una vez capturado.

### `stores/cotizaciones.ts`

Gana `crearEnvio(cotizacionId, payload)` y `marcarEnvioEntregado(cotizacionId)`, mismo patrón que
`ordenesTrabajo.ts`.

### `CotizacionDetalleView.vue`

Botón **"Enviar a domicilio"** (junto al ya existente "Crear Orden de Trabajo"), visible solo cuando
`cliente_es_distribuidor` (ya expuesto por `CotizacionResource`, ver 033) es `true`, la cotización
tiene al menos un pago, y no tiene ya un envío directo propio. Al enviarse el formulario, se muestra
`FichaEnvio.vue` igual que en la Orden de Trabajo.

## Fuera de alcance

- Desglosar la dirección en calle/número/colonia/CP, o validarla contra un servicio de
  geocodificación: sigue siendo un solo campo de texto libre.
- Editar o eliminar un envío (de cualquiera de los dos tipos) una vez creado — la única transición
  nueva permitida es "Marcar entregado" sobre el envío directo, y no tiene "deshacer".
- Completar retroactivamente la dirección de envíos creados antes de este cambio.
- Cualquier tablero, estado o filtro de Producción para el envío directo de distribuidor — no
  aparece en `/produccion` ni pasa por `EstadoOrdenTrabajo`.
- Sincronizar `Envio::entregado_en` con `Cotizacion::estado` o `Cotizacion::entregado_en`.
- Permitir el envío directo a clientes no distribuidores, o a cotizaciones sin cliente asociado
  (mostrador): para esos casos el único camino sigue siendo la Orden de Trabajo (038).
- Más de un envío directo por `Cotizacion` (el índice único por documento lo impide, igual que hoy
  para Orden de Trabajo).

## Criterios de aceptación

1. Capturar un envío (de Producción o directo) sin `direccion` responde `422`.
2. La ficha y el texto compartible de cualquier envío muestran la dirección capturada.
3. Un envío creado antes de este cambio se sigue mostrando correctamente, con la dirección en blanco.
4. `POST /cotizaciones/{cotizacion}/envio` responde `422` si el cliente de la cotización no es
   distribuidor, si la cotización no tiene ningún pago, o si la cotización ya tiene un envío directo.
5. Un envío directo `prepagado` genera un movimiento de Tesorería por el monto congelado de la
   tarifa; uno `por_cobrar` no genera ninguno — igual que en Producción.
6. `POST /cotizaciones/{cotizacion}/envio/entregar` marca `entregado_en`; un segundo intento
   responde `422`. Esta acción no cambia `Cotizacion::estado` ni `Cotizacion::entregado_en`.
7. Una cotización de distribuidor puede tener simultáneamente su propio envío directo y, si además
   generó una Orden de Trabajo, el envío colgado de esa orden — son registros independientes.
8. El botón "Enviar a domicilio" en el detalle de cotización solo aparece con cliente distribuidor,
   al menos un pago, y sin un envío directo ya creado.
9. El flujo de envío de Producción (038) sigue funcionando exactamente igual salvo por exigir ahora
   también `direccion`.
10. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. La dirección de envío es un solo campo de texto libre (máx. 255 caracteres), no desglosado en
   calle/número/colonia.
2. La dirección es obligatoria en la validación de cualquier envío nuevo, tanto el de Producción
   como el directo de distribuidor; a nivel de base de datos la columna es `nullable` únicamente
   para no romper los envíos ya existentes sin dirección.
3. El envío deja de depender exclusivamente de `OrdenTrabajo`: `Envio` pasa a una relación
   polimórfica (`documentable`) que acepta tanto `OrdenTrabajo` como `Cotizacion`.
4. El envío directo de un distribuidor está disponible desde que la `Cotizacion` tiene al menos un
   pago registrado — mismo criterio que ya usa 038 para permitir crear una Orden de Trabajo, sin
   exigir que esté 100% pagada ni un estado particular.
5. El envío directo y una Orden de Trabajo de la misma cotización son independientes y pueden
   coexistir: no son mutuamente excluyentes.
6. El envío directo se marca "entregado" con una acción propia (`entregar`) que solo aplica cuando
   el envío no está colgado de una Orden de Trabajo; no reutiliza ni afecta el QR/estado de entrega
   que ya tiene `Cotizacion` desde 038.
7. El envío directo usa exactamente las mismas tarifas configurables A/B/C y la misma regla de
   Tesorería (prepagado genera movimiento de ingreso, por cobrar no genera ninguno) que el envío de
   Producción — no se define un esquema de costos ni de caja distinto.
8. El envío directo solo aplica a clientes marcados como distribuidores ([033](033-precio-distribuidor.md));
   una cotización sin cliente (no aplica) o con cliente no distribuidor sigue exigiendo pasar por
   Orden de Trabajo para tener envío.
9. Un documento (`OrdenTrabajo` o `Cotizacion`) admite como máximo un envío colgado directamente de
   él — el límite de "un envío por documento" de 038 se mantiene, ahora aplicado también al nuevo
   tipo de documento colgante.
10. No se completa retroactivamente la dirección de los envíos ya creados antes de este cambio.
