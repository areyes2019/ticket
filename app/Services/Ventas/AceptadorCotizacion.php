<?php

namespace App\Services\Ventas;

use App\Enums\MotivoMovimientoInventario;
use App\Models\Articulo;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\Pedido;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Inventario\RegistradorInventario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aceptar una cotización (021): nace su venta con el mismo cliente, líneas,
 * precios, descuentos y total, y la cotización queda aceptada. Todo en una
 * transacción con la cotización bloqueada: dos clics no crean dos ventas (y
 * el índice único de pedidos.cotizacion_id es la última red).
 *
 * La venta descuenta existencias al nacer, como la de mostrador, pero no se
 * bloquea por falta de mercancía: lo cotizado suele ser sobre pedido y deja
 * faltante para Reposición. No registra pagos: el primero se captura en la
 * venta.
 */
class AceptadorCotizacion
{
    public function __construct(private readonly RegistradorInventario $inventario) {}

    /**
     * @param  array{cliente_nombre: string, cliente_telefono: string, cliente_correo: string|null}  $datosCliente
     *
     * @throws ValidationException si ya no se puede aceptar (bolsa "aceptar")
     */
    public function aceptar(Cotizacion $cotizacion, array $datosCliente): Pedido
    {
        return DB::transaction(function () use ($cotizacion, $datosCliente) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $motivo = $bloqueada->motivoNoAceptable();

            if ($motivo !== null) {
                throw ValidationException::withMessages(['cotizacion' => $motivo])->errorBag('aceptar');
            }

            $venta = $bloqueada->user->pedidos()->make([
                ...$datosCliente,
                'descuento_global_tipo' => $bloqueada->descuento_global_tipo,
                'descuento_global_valor' => $bloqueada->descuento_global_valor,
            ]);
            $venta->cotizacion_id = $bloqueada->id;
            $venta->cliente_id = $bloqueada->cliente_id;
            $venta->folio = Pedido::siguienteFolio($bloqueada->user);

            $lineas = $this->lineas($bloqueada);
            $totales = CalculadoraTotalesDocumento::calcular(
                $lineas,
                $bloqueada->descuento_global_tipo?->value,
                $bloqueada->descuento_global_valor,
            );

            $venta->aplicarTotales($totales);
            $venta->save();

            $this->guardarLineas($venta, $lineas, $totales);
            $this->inventario->salidaPorDocumento($venta, $venta->lineas()->get(), MotivoMovimientoInventario::VentaPedido, creaFila: true);

            $bloqueada->marcarAceptada();
            $bloqueada->save();

            return $venta;
        });
    }

    /**
     * Las líneas tal como se cotizaron (las libres siguen libres).
     *
     * @return list<array<string, mixed>>
     */
    private function lineas(Cotizacion $cotizacion): array
    {
        return $cotizacion->lineas()->get()->map(fn (CotizacionLinea $linea) => [
            'articulo_id' => $linea->articulo_id,
            'cantidad' => $linea->cantidad,
            'descripcion' => $linea->descripcion,
            'modelo' => $linea->modelo,
            'precio_unitario' => $linea->precio_unitario,
            'descuento_tipo' => $linea->descuento_tipo?->value,
            'descuento_valor' => $linea->descuento_valor,
            'tasa_iva' => $linea->tasa_iva->value,
        ])->values()->all();
    }

    /**
     * El costo es el del artículo al aceptar (regla de 019: el de la venta),
     * en una sola consulta; importe e IVA salen de la calculadora.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @param  array<string, mixed>  $totales
     */
    private function guardarLineas(Pedido $venta, array $lineas, array $totales): void
    {
        $costos = Articulo::withTrashed()
            ->whereIn('id', array_filter(array_column($lineas, 'articulo_id')))
            ->pluck('costo_con_descuento', 'id');

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
