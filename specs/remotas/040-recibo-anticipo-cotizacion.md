# Spec: Recibo de pago (anticipo/saldo/pago total) de una cotización

## Historia de usuario

Como usuario, cuando le registro a un cliente un pago sobre una cotización (anticipo, saldo o
pago total), quiero poder generar un recibo en PDF de ese pago específico y compartirlo de
inmediato por el menú nativo de Windows 11, para dejarle al cliente un comprobante de lo que pagó
sin tener que armarlo a mano. Esto solo aplica a cotizaciones: una venta de mostrador no lo
necesita, porque su ticket ([027](027-venta-mostrador-ticket.md)) ya cumple ese papel al momento
del cobro completo.

## Objetivo / Alcance

Un botón nuevo, **"Recibo"**, en cada fila del historial de pagos que ya se muestra en
`/cotizaciones/{id}` (ver [008](008-cotizaciones.md), tabla de `pagos`): genera al vuelo un PDF con
los datos de ese pago concreto y lo entrega al menú de compartir del sistema operativo mediante el
mismo mecanismo que ya resuelve [`lib/compartir.ts`](../frontend/src/lib/compartir.ts) para la
cotización y el QR de entrega ([039](039-qr-conductor-produccion.md)).

No se crea ningún modelo, migración ni tabla nueva: el recibo se arma con los datos que ya existen
de `CotizacionPago` (008) en el momento en que se pide, igual de espíritu a como ya se genera el
PDF de la propia cotización — nunca se persiste una copia.

## Backend (Laravel)

### Endpoint

| Método | Ruta | Qué hace |
| --- | --- | --- |
| `GET` | `/api/v1/cotizaciones/{cotizacion}/pagos/{pago}/recibo` | PDF (`application/pdf`) del recibo de ese pago |

Se registra junto a las rutas de pago ya existentes (`routes/api.php:177-178`), mismo patrón de
parámetros que `eliminarPago`:

```php
Route::get('cotizaciones/{cotizacion}/pagos/{pago}/recibo', [CotizacionController::class, 'reciboPago']);
```

### `CotizacionController::reciboPago`

```php
public function reciboPago(Request $request, Cotizacion $cotizacion, CotizacionPago $pago): Response
{
    abort_unless($cotizacion->user_id === $request->user()->id, 404);
    abort_unless($pago->cotizacion_id === $cotizacion->id, 404);

    $cotizacion->loadMissing('cliente');
    $pago->loadMissing('cuenta');

    $pdf = app('dompdf.wrapper')->loadView('pdf.recibo-pago', [
        'cotizacion' => $cotizacion,
        'pago' => $pago,
        'saldoPendienteTrasPago' => $pago->saldoPendienteTrasEste(),
    ]);

    return $pdf->stream("recibo-cotizacion-{$cotizacion->folio}-{$pago->tipo->value}.pdf");
}
```

Mismo criterio de scoping por usuario y de pertenencia del pago a la cotización que ya usa
`eliminarPago` (`CotizacionController.php:280-283`): un pago ajeno a esa cotización, o una
cotización ajena al usuario autenticado, responde `404`.

### `CotizacionPago::saldoPendienteTrasEste()`

```php
public function saldoPendienteTrasEste(): float
{
    $acumuladoHastaEste = $this->cotizacion->pagos()
        ->where('id', '<=', $this->id)
        ->sum('monto');

    return max(0, (float) $this->cotizacion->total - (float) $acumuladoHastaEste);
}
```

- **Es el saldo en el momento histórico de ese pago, no el saldo actual de la cotización.** Suma
  solo los pagos creados hasta ese (inclusive, por `id`, que respeta el orden de creación — los
  pagos son estrictamente secuenciales y solo el más reciente puede eliminarse, ver
  [008](008-cotizaciones.md)). Un recibo generado después de registrar un pago posterior sigue
  mostrando el saldo que quedaba justo después de sí mismo, no el saldo de hoy.
- Vive en el modelo, no en el controlador, porque es un dato propio del pago (igual que
  `conceptoMovimiento()`, que ya vive ahí).

### Vista `resources/views/pdf/recibo-pago.blade.php`

**No extiende `pdf.documento`**, mismo motivo que `pdf.lista-precios` (028): esa plantilla base
asume una tabla de líneas con cantidad/descuento/IVA que un recibo de pago no tiene. Reutiliza la
paleta y tipografía de los demás documentos (gris azulado `#2c3e50`, bordes `#95a5a6`, fondo de
cabecera `#f5f5f5`, DejaVu Sans) para que se sienta de la misma familia sin ser una extensión
forzada. Al vivir bajo `resources/views/pdf/`, recibe `$emisor` gratis vía
`View::composer('pdf.*', EmisorComposer::class)`.

Estructura:

- **Encabezado**: logo del emisor a la izquierda, "Recibo de pago" en 18pt a la derecha, y debajo
  la etiqueta del tipo de pago (`Anticipo` / `Saldo` / `Pago total`) y la fecha de generación.
- **Datos de la cotización**: folio (`COT-{folio con 5 dígitos}`, mismo formato que
  `conceptoMovimiento()`) y cliente (razón social, RFC).
- **Datos del pago**: fecha de pago, forma de pago (nombre de la cuenta de Tesorería,
  `$pago->cuenta->nombre`), monto pagado (grande, destacado) y saldo pendiente tras este pago
  (`$saldoPendienteTrasPago`) — `$0.00` cuando el pago dejó la cotización saldada.
- **Pie**: *"Este documento es un comprobante interno de pago, no un CFDI."*, mismo criterio que la
  nota al pie de la cotización (`notaPie` en `pdf/cotizacion.blade.php`).

### Tests

Feature tests sobre la base MySQL de trabajo con `php artisan test`, nunca `migrate:fresh`
([[feedback_nunca_migrate_fresh_en_dev]]):

1. `GET /cotizaciones/{id}/pagos/{pago}/recibo` sobre un pago existente devuelve `200` con
   `application/pdf`.
2. El nombre de archivo del PDF incluye el folio de la cotización y el tipo del pago
   (`recibo-cotizacion-{folio}-anticipo.pdf`, etc.).
3. Un `pago` que pertenece a otra cotización (aunque el `id` exista) responde `404`.
4. Un `pago` de una cotización que pertenece a otro usuario responde `404`.
5. `saldoPendienteTrasEste()` sobre el primer pago (anticipo) de una cotización con más de un pago
   devuelve el saldo justo después de ese anticipo, no el saldo actual tras el pago siguiente.
6. `saldoPendienteTrasEste()` sobre el último pago registrado, cuando ya cubrió el total, devuelve
   `0`.
7. El PDF se genera igual sin logo del emisor cargado, sin lanzar excepción.

## Frontend (Vue 3)

### `stores/cotizaciones.ts`

Nueva acción, junto a `descargarPdf`:

```ts
/** El PDF del recibo de un pago, dibujado por el servidor, como Blob listo para compartir. */
async reciboPagoBlob(cotizacionId: number, pagoId: number): Promise<Blob> {
  const { data } = await http.get(`/cotizaciones/${cotizacionId}/pagos/${pagoId}/recibo`, {
    responseType: 'blob',
  })
  return new Blob([data], { type: 'application/pdf' })
},
```

Mismo patrón que `listaPreciosBlob()` (028) y la descarga de la cotización.

### `CotizacionDetalleView.vue`

- En la tabla de pagos (`CotizacionDetalleView.vue:541-571`), se agrega una columna/botón
  **"Recibo"** en cada fila, junto al botón "Eliminar" (visible siempre, a diferencia de
  "Eliminar" que solo aparece en el pago más reciente — un recibo de un pago anterior sigue siendo
  válido y compartible).
- Al hacer clic:
  1. Se pide el PDF: `const blob = await cotizacionesStore.reciboPagoBlob(cotizacion.value.id, pago.id)`.
  2. Se comparte de inmediato, con texto acompañante (para que el respaldo de escritorio abra
     WhatsApp con el mensaje ya escrito, igual que "Compartir QR"):
     `await compartirArchivo(blob, `recibo-cotizacion-${cotizacion.folio}-${pago.tipo}.pdf`, texto)`,
     con
     `texto = `Recibo de ${etiquetaTipo(pago.tipo)} de la cotización ${cotizacion.folio} (${cotizacion.cliente_razon_social}): $${pago.monto.toFixed(2)}.``.
- Estado de carga por fila (no global): mientras se genera el recibo de un pago, solo el botón de
  esa fila muestra "Generando..." y queda deshabilitado; los demás botones de recibo siguen
  disponibles.
- Si la generación falla, se muestra un error puntual (mismo patrón que `errorCompartirQr`):
  "No se pudo generar el recibo.".

## Fuera de alcance

- **Un recibo acumulado de toda la cotización.** Cada recibo corresponde a un único pago; para ver
  el historial completo se usa la tabla de pagos que ya existe en el detalle.
- **Envío del recibo por correo.** Solo se comparte por el menú nativo/WhatsApp, igual que el QR;
  no hay un botón de "enviar recibo por correo" ni un mailable nuevo.
- **Persistir o listar los recibos generados.** Es un documento efímero, igual que el PDF de la
  cotización: no deja rastro en base de datos ni se puede "volver a ver" desde un listado — se
  regenera cada vez que se pide.
- **Recibos para pagos de ventas de mostrador.** Fuera de alcance porque el ticket de
  [027](027-venta-mostrador-ticket.md) ya cumple ese papel ahí.
- **Folio o numeración propia del recibo.** No es un documento fiscal ni de control interno
  numerado; se identifica por el pago del que proviene.
- **Editar o anular un recibo ya generado.** No hay nada que editar: es una foto de los datos del
  pago en el momento en que se pide.

## Criterios de aceptación

1. En el detalle de una cotización, cada fila del historial de pagos muestra un botón "Recibo".
2. Al hacer clic, se genera un PDF con el folio de la cotización, el cliente, el tipo de pago
   (anticipo/saldo/pago total), la fecha de pago, la cuenta, el monto pagado y el saldo pendiente
   justo después de ese pago.
3. El PDF se entrega de inmediato al menú nativo de compartir de Windows 11, con un texto
   acompañante que resume el pago; si el menú no está disponible, se descarga el archivo y se abre
   WhatsApp con ese texto ya escrito.
4. El nombre del archivo generado incluye el folio de la cotización y el tipo de pago.
5. El saldo pendiente impreso en el recibo de un pago anterior no cambia aunque se registren pagos
   posteriores: siempre refleja el saldo justo después de ese pago específico.
6. El botón "Recibo" está disponible para cualquier pago del historial, no solo el más reciente.
7. Pedir el recibo de un pago que no pertenece a esa cotización, o de una cotización ajena al
   usuario autenticado, responde `404` y no genera ningún PDF.
8. El PDF se genera igual sin logo del emisor cargado, sin error.
9. Pint corre sin errores sobre el backend, ESLint y Prettier sobre el frontend, la suite de Pest
   sigue pasando, y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

Los 7 primeros son las asunciones funcionales aceptadas al definir la historia; del 8 al 11, las
cuatro adiciones técnicas resueltas.

1. El recibo aplica únicamente a pagos de cotización (anticipo, saldo, pago total); las ventas de
   mostrador quedan fuera porque su ticket ya cumple ese papel.
2. Se genera un recibo por cada pago registrado individualmente, no un recibo único acumulado por
   cotización.
3. El recibo se descarga y comparte por el mismo mecanismo (`lib/compartir.ts`) que ya usan el PDF
   de la cotización y el QR de entrega.
4. El recibo incluye: folio de la cotización, cliente, tipo de pago, monto, cuenta/forma de pago,
   fecha del pago y saldo pendiente después de ese pago.
5. El recibo no es un CFDI ni lleva folio fiscal: es un comprobante interno, igual que
   `CotizacionPago` no pasa por facturapi.io.
6. El botón para generarlo/descargarlo vive junto a cada pago en la tabla de historial, no como un
   botón único a nivel de toda la cotización.
7. Se puede generar el recibo de cualquier pago ya registrado, sin importar cuándo se capturó.
8. **(Adición técnica)** El PDF se arma al vuelo en cada petición y nunca se persiste, mismo
   criterio que el PDF de la cotización.
9. **(Adición técnica)** El recibo usa una plantilla Blade nueva y propia (`pdf/recibo-pago.blade.php`),
   que no extiende `pdf.documento` pero reutiliza su paleta y tipografía.
10. **(Adición técnica)** No se crea ninguna tabla ni columna nueva: el recibo se arma con los
    datos que ya existen de `CotizacionPago`.
11. **(Adición técnica)** El botón de recibo vive en cada fila de la tabla de pagos que ya existe
    en `CotizacionDetalleView.vue`, con estado de carga y error propios de esa fila.

## Estado de implementación

Implementada el 2026-08-25.

- Backend: ruta `GET cotizaciones/{cotizacion}/pagos/{pago}/recibo`,
  `CotizacionController::reciboPago`, `CotizacionPago::saldoPendienteTrasEste()` y la plantilla
  `pdf/recibo-pago.blade.php` (no extiende `pdf.documento`, reutiliza su paleta). Cubierto por 6
  tests nuevos en `CotizacionesTest.php` (663 pruebas del backend, todas en verde): PDF generado
  con el nombre de archivo esperado, `404` sobre un pago de otra cotización y sobre una cotización
  ajena, el saldo impreso de un pago anterior no cambia tras registrar uno posterior, y el PDF se
  genera igual sin logo del emisor. Pint corre sin cambios pendientes.
- Frontend: acción `reciboPagoBlob()` en `stores/cotizaciones.ts` y botón "Recibo" por fila en la
  tabla de pagos de `CotizacionDetalleView.vue`, con estado de carga (`generandoReciboId`) y error
  propios de esa fila; comparte con `compartirArchivo()` y un texto de respaldo para WhatsApp de
  escritorio, mismo mecanismo que "Compartir QR". ESLint, Prettier y `npm run build` corren
  limpios. **No se pudo verificar visualmente en un navegador real** (misma limitación de entorno
  que el resto del proyecto) — se recomienda registrar un pago sobre una cotización enviada y
  confirmar que el botón "Recibo" genera el PDF y abre el menú de compartir (o descarga con el
  texto copiado si no hay menú nativo disponible).
