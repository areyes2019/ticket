<?php

namespace App\Services\Pedidos;

use App\Enums\EstadoFactura;
use App\Enums\MetodoPago;
use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\PedidoLinea;
use App\Services\Facturacion\EnviadorCorreoFactura;
use App\Services\Facturacion\ResultadoTimbrado;
use App\Services\Facturacion\TimbradorFacturas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Portal público de autofacturación (019): el cliente captura sus datos
 * fiscales y se timbra la factura del pedido, con sus mismos importes.
 *
 * El cliente fiscal, la factura y su vínculo con el pedido se guardan en una
 * transacción con el pedido bloqueado; el timbrado va después, fuera de ella
 * (como en 012). Si falla, la factura queda pendiente, ligada al pedido, y se
 * reutiliza en el siguiente intento: nunca hay dos facturas por pedido.
 */
class Autofacturador
{
    /**
     * Claves SAT de una línea libre: "No existe en el catálogo" y pieza.
     */
    public const CLAVE_PROD_SERV_GENERICA = '01010101';

    public const CLAVE_UNIDAD_GENERICA = 'H87';

    public function __construct(
        private TimbradorFacturas $timbrador,
        private EnviadorCorreoFactura $enviador,
    ) {}

    /**
     * @param  array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal_fiscal: string, uso_cfdi: string, correo: string}  $datos
     */
    public function facturar(Pedido $pedido, array $datos): ResultadoAutofactura
    {
        $preparada = DB::transaction(function () use ($pedido, $datos): Factura|string {
            $bloqueado = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            // Una sola factura entre la cotización aceptada y su venta (021):
            // la fila de la cotización es la que comparten las dos vías.
            if ($bloqueado->esDeCotizacion()) {
                $bloqueado->setRelation('cotizacion', Cotizacion::whereKey($bloqueado->cotizacion_id)->lockForUpdate()->first());
            }

            $motivo = $bloqueado->motivoAutofacturaNoDisponible();

            if ($motivo !== null) {
                return $motivo;
            }

            $cliente = $this->cliente($bloqueado, $datos);
            $pendiente = $bloqueado->facturaVigente()
                ->whereIn('estado', [EstadoFactura::Borrador->value, EstadoFactura::Pendiente->value])
                ->first();

            if ($pendiente !== null) {
                $pendiente->fill(['cliente_id' => $cliente->id, 'uso_cfdi' => $datos['uso_cfdi']])->save();

                return $pendiente;
            }

            return $this->crearFactura($bloqueado, $cliente, $datos['uso_cfdi']);
        });

        if (is_string($preparada)) {
            return new ResultadoAutofactura(motivo: $preparada);
        }

        $resultado = $this->timbrador->timbrar($preparada);
        $preparada->refresh();

        if ($resultado === ResultadoTimbrado::Timbrada) {
            $pedido->forceFill(['autofactura_error' => null])->save();

            return new ResultadoAutofactura(
                timbrado: $resultado,
                factura: $preparada,
                correoEnviado: $this->enviarCorreo($preparada, $datos['correo']),
            );
        }

        if ($resultado === ResultadoTimbrado::ErrorDatos || $resultado === ResultadoTimbrado::ErrorPac) {
            Log::warning('Autofactura fallida.', ['pedido' => $pedido->id, 'factura' => $preparada->id, 'error' => $preparada->error_timbrado]);
            $pedido->forceFill(['autofactura_error' => $preparada->error_timbrado])->save();
        }

        return new ResultadoAutofactura(timbrado: $resultado, factura: $preparada);
    }

    /**
     * El cliente del usuario con ese RFC, con los datos fiscales que acaba de
     * capturar (sin esto no podría corregir un código postal que el SAT
     * rechazó); si no existe, se da de alta con el contacto del pedido.
     *
     * @param  array<string, string>  $datos
     */
    private function cliente(Pedido $pedido, array $datos): Cliente
    {
        $fiscales = [
            'razon_social' => $datos['razon_social'],
            'regimen_fiscal' => $datos['regimen_fiscal'],
            'codigo_postal_fiscal' => $datos['codigo_postal_fiscal'],
            'correo' => $datos['correo'],
        ];

        $cliente = $pedido->user->clientes()->where('rfc', $datos['rfc'])->first();

        if ($cliente !== null) {
            $cliente->fill($fiscales)->save();

            return $cliente;
        }

        return $pedido->user->clientes()->create([
            'rfc' => $datos['rfc'],
            ...$fiscales,
            'nombre_contacto' => $pedido->cliente_nombre,
            'telefono' => $pedido->cliente_telefono,
        ]);
    }

    /**
     * Copia del pedido con los mismos importes (no se recalcula: la factura
     * dice exactamente lo que se cobró). PUE siempre, porque el enlace solo
     * existe con el pedido pagado; la forma de pago sale de la cuenta del
     * último pago. pedido_id se escribe antes de timbrar, para que la factura
     * no descuente existencias que el pedido ya descontó.
     */
    private function crearFactura(Pedido $pedido, Cliente $cliente, string $usoCfdi): Factura
    {
        $ultimoPago = $pedido->pagos()->with('cuenta')->reorder()->orderByDesc('fecha_pago')->orderByDesc('id')->firstOrFail();

        $factura = $pedido->user->facturas()->make([
            'cliente_id' => $cliente->id,
            'uso_cfdi' => $usoCfdi,
            'forma_pago' => $ultimoPago->cuenta->tipo->formaPagoSat(),
            'metodo_pago' => MetodoPago::UnaExhibicion,
            'descuento_global_tipo' => $pedido->descuento_global_tipo,
            'descuento_global_valor' => $pedido->descuento_global_valor,
        ]);
        $factura->folio = Factura::siguienteFolio($pedido->user);
        $factura->pedido_id = $pedido->id;
        $factura->aplicarTotales($pedido->only(Pedido::TOTALES));
        $factura->save();

        $articulos = Articulo::withTrashed()->whereIn('id', $pedido->lineas->pluck('articulo_id')->filter())->get()->keyBy('id');

        foreach ($pedido->lineas as $linea) {
            /** @var PedidoLinea $linea */
            $articulo = $articulos->get($linea->articulo_id);

            $factura->lineas()->create([
                'orden' => $linea->orden,
                'articulo_id' => $linea->articulo_id,
                'cantidad' => $linea->cantidad,
                'descripcion' => $linea->descripcion,
                'modelo' => $linea->modelo ?? '',
                'precio_unitario' => $linea->precio_unitario,
                'descuento_tipo' => $linea->descuento_tipo,
                'descuento_valor' => $linea->descuento_valor,
                'tasa_iva' => $linea->tasa_iva,
                'importe' => $linea->importe,
                'iva_importe' => $linea->iva_importe,
                'clave_prod_serv' => $articulo->clave_prod_serv ?? self::CLAVE_PROD_SERV_GENERICA,
                'clave_unidad' => $articulo->clave_unidad ?? self::CLAVE_UNIDAD_GENERICA,
                'objeto_imp' => $articulo->objeto_imp ?? ObjetoImpuesto::SiObjeto,
            ]);
        }

        return $factura;
    }

    /**
     * La factura ya está timbrada aunque el correo no salga: el acuse ofrece
     * la descarga.
     */
    private function enviarCorreo(Factura $factura, string $correo): bool
    {
        try {
            $this->enviador->enviar($factura, [$correo]);

            return true;
        } catch (Throwable $error) {
            Log::warning('La autofactura se timbró pero no salió por correo.', ['factura' => $factura->id, 'error' => $error->getMessage()]);

            return false;
        }
    }
}
