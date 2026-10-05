<?php

namespace App\Services\Ventas;

use App\Enums\MotivoMovimientoInventario;
use App\Models\Articulo;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Inventario\RegistradorInventario;
use App\Services\OrdenesTrabajo\ConservadorColores;

/**
 * La venta de una cotización (029): nace con el primer pago, con el mismo
 * cliente, líneas, precios, descuentos y total, y lee sus pagos de la
 * cotización (cobro_en_cotizacion). Al editar la cotización, sincronizar()
 * le copia los cambios.
 *
 * No abre transacción ni bloquea: lo llaman el pago y la edición de la
 * cotización dentro de la suya, con la cotización ya bloqueada (bloqueo:
 * primero la cotización, después la venta).
 *
 * La venta descuenta existencias al nacer, como la de mostrador, pero no se
 * bloquea por falta de mercancía: lo cotizado suele ser sobre pedido y deja
 * faltante para Reposición (021).
 */
class CreadorVentaDeCotizacion
{
    public function __construct(
        private readonly RegistradorInventario $inventario,
        private readonly ConservadorColores $colores,
    ) {}

    /**
     * Crea la venta (y su orden de trabajo, sin colores ni imagen) y deja la
     * cotización aceptada. No guarda la cotización.
     *
     * @param  array{cliente_nombre: string, cliente_telefono: string, cliente_correo: string|null}  $datosCliente
     */
    public function crear(Cotizacion $cotizacion, array $datosCliente, bool $conOrden): Pedido
    {
        $venta = $cotizacion->user->pedidos()->make($datosCliente);
        $venta->cotizacion_id = $cotizacion->id;
        $venta->cliente_id = $cotizacion->cliente_id;
        $venta->cobro_en_cotizacion = true;
        $venta->folio = Pedido::siguienteFolio($cotizacion->user);
        $venta->setRelation('cotizacion', $cotizacion);

        $this->copiar($cotizacion, $venta);
        $this->inventario->salidaPorDocumento($venta, $venta->lineas()->get(), MotivoMovimientoInventario::VentaPedido, creaFila: true);

        if ($conOrden) {
            $orden = new OrdenTrabajo;
            $orden->user_id = $venta->user_id;
            $orden->pedido_id = $venta->id;
            $orden->save();
            $venta->setRelation('ordenTrabajo', $orden);
        }

        $cotizacion->marcarAceptada();
        $cotizacion->setRelation('venta', $venta);

        return $venta;
    }

    /**
     * Después de guardar la cotización editada: la venta toma sus líneas,
     * descuento y totales, corrige el inventario, conserva los colores de su
     * orden (022) y recalcula su estado. Los datos de contacto no cambian.
     */
    public function sincronizar(Cotizacion $cotizacion, Pedido $venta): void
    {
        $colores = $this->colores->recordar($venta);
        $this->inventario->revertirDocumento($venta, MotivoMovimientoInventario::CorreccionPedido);

        $this->copiar($cotizacion, $venta);

        $this->colores->reaplicar($venta, $colores);
        $this->inventario->salidaPorDocumento($venta, $venta->lineas()->get(), MotivoMovimientoInventario::VentaPedido, creaFila: true);

        $venta->unsetRelation('lineas');
        $venta->setRelation('cotizacion', $cotizacion);
        $venta->recalcularEstado();
        $venta->save();
    }

    /**
     * Descuento global, totales y líneas de la cotización (las libres siguen
     * libres). El costo es el del artículo en este momento (regla de 019: el
     * de la venta); importe e IVA salen de la calculadora. Guarda la venta.
     */
    private function copiar(Cotizacion $cotizacion, Pedido $venta): void
    {
        $lineas = $cotizacion->lineas()->get()->map(fn (CotizacionLinea $linea) => [
            'articulo_id' => $linea->articulo_id,
            'cantidad' => $linea->cantidad,
            'descripcion' => $linea->descripcion,
            'modelo' => $linea->modelo,
            'precio_unitario' => $linea->precio_unitario,
            'descuento_tipo' => $linea->descuento_tipo?->value,
            'descuento_valor' => $linea->descuento_valor,
            'tasa_iva' => $linea->tasa_iva->value,
        ])->values()->all();

        $totales = CalculadoraTotalesDocumento::calcular(
            $lineas,
            $cotizacion->descuento_global_tipo?->value,
            $cotizacion->descuento_global_valor,
        );

        $venta->descuento_global_tipo = $cotizacion->descuento_global_tipo;
        $venta->descuento_global_valor = $cotizacion->descuento_global_valor;
        $venta->aplicarTotales($totales);
        $venta->save();

        $costos = Articulo::withTrashed()
            ->whereIn('id', array_filter(array_column($lineas, 'articulo_id')))
            ->pluck('costo_con_descuento', 'id');

        $venta->lineas()->delete();

        foreach ($lineas as $i => $linea) {
            $venta->lineas()->create([
                ...$linea,
                'orden' => $i + 1,
                'importe' => $totales['lineas'][$i]['importe'],
                'iva_importe' => $totales['lineas'][$i]['iva_importe'],
                'costo_unitario' => $linea['articulo_id'] === null ? null : $costos->get($linea['articulo_id']),
            ]);
        }
    }
}
