<?php

namespace App\Services\Facturacion;

use App\Enums\EstadoComplementoPago;
use App\Enums\EstadoFactura;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoErrorTimbrado;
use App\Models\ComplementoPago;
use App\Models\Factura;
use App\Services\Inventario\RegistradorInventario;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Timbra facturas y complementos de pago contra facturapi.io.
 *
 * Un candado por factura impide timbrar dos veces a la vez (doble clic), y la
 * llamada al PAC ocurre fuera de cualquier transacción de base de datos para
 * no retener una conexión durante el timeout.
 */
class TimbradorFacturas
{
    public function __construct(
        private FacturapiCliente $facturapi,
        private ConstructorPayloadFacturapi $payload,
        private RegistradorInventario $inventario,
    ) {}

    public function timbrar(Factura $factura): ResultadoTimbrado
    {
        return $this->conCandado($factura, function () use ($factura) {
            $factura->refresh();

            if (! in_array($factura->estado, [EstadoFactura::Borrador, EstadoFactura::Pendiente], true)) {
                return ResultadoTimbrado::SinCambio;
            }

            $factura->load(['cliente', 'lineas']);
            $receptor = Factura::datosReceptor($factura->cliente);

            try {
                $respuesta = $this->crearOAdoptar(
                    fn () => $this->facturapi->crearFactura($this->payload->factura($factura, $receptor), $factura->id),
                    $factura->referenciaExterna(),
                    $factura->id,
                );
            } catch (FacturapiException $error) {
                $factura->registrarErrorTimbrado($error->getMessage(), $error->tipo);

                return $error->tipo === TipoErrorTimbrado::Datos ? ResultadoTimbrado::ErrorDatos : ResultadoTimbrado::ErrorPac;
            }

            // El timbrado y la salida de existencias se guardan juntos. Una
            // factura que viene de una cotización no descuenta: lo hace la
            // cotización al entregarse (018). Un artículo sin fila no se mueve.
            DB::transaction(function () use ($factura, $respuesta, $receptor) {
                $factura->aplicarRespuestaTimbrado($respuesta, $receptor, $this->emisor($respuesta));

                if ($factura->cotizacion_id === null) {
                    $this->inventario->salidaPorDocumento($factura, $factura->lineas, MotivoMovimientoInventario::VentaFactura, creaFila: false);
                }
            });

            return ResultadoTimbrado::Timbrada;
        });
    }

    /**
     * Crea (o, si el anterior falló, reutiliza) el complemento de pago de la
     * factura y lo timbra.
     *
     * @param  array{fecha_pago: string, monto: string, forma_pago: string}  $datos
     */
    public function timbrarComplemento(Factura $factura, array $datos): ResultadoTimbrado
    {
        return $this->conCandado($factura, function () use ($factura, $datos) {
            $factura->refresh()->load('complementoPago');

            if (! $factura->puedeRegistrarComplemento()) {
                return ResultadoTimbrado::SinCambio;
            }

            $complemento = $factura->complementoPago ?? new ComplementoPago;
            $complemento->fill($datos);
            $complemento->estado = EstadoComplementoPago::Pendiente;
            $factura->complementoPago()->save($complemento);
            $complemento->setRelation('factura', $factura);

            try {
                $respuesta = $this->crearOAdoptar(
                    fn () => $this->facturapi->crearComplementoPago($this->payload->complementoPago($complemento), $factura->id),
                    $complemento->referenciaExterna(),
                    $factura->id,
                );
            } catch (FacturapiException $error) {
                $complemento->registrarError($error->getMessage());

                return $error->tipo === TipoErrorTimbrado::Datos ? ResultadoTimbrado::ErrorDatos : ResultadoTimbrado::ErrorPac;
            }

            $complemento->aplicarRespuestaTimbrado($respuesta);

            return ResultadoTimbrado::Timbrada;
        });
    }

    /**
     * Crea el CFDI. Si facturapi.io responde 409 (un intento anterior sí timbró,
     * aunque aquí se viera como timeout), adopta ese CFDI en vez de timbrar
     * otro.
     *
     * @param  Closure(): array<string, mixed>  $crear
     * @return array<string, mixed>
     *
     * @throws FacturapiException
     */
    private function crearOAdoptar(Closure $crear, string $referencia, int $facturaId): array
    {
        try {
            return $crear();
        } catch (FacturapiException $error) {
            if (! $error->yaTimbrada()) {
                throw $error;
            }
        }

        $existente = $this->facturapi->buscarPorReferencia($referencia, $facturaId);

        if ($existente === null) {
            throw new FacturapiException('facturapi.io indica que esta factura ya se timbró, pero todavía no la encuentra. Reintenta en unos minutos.', TipoErrorTimbrado::Pac, 409);
        }

        return $existente;
    }

    /**
     * @param  Closure(): ResultadoTimbrado  $accion
     */
    private function conCandado(Factura $factura, Closure $accion): ResultadoTimbrado
    {
        $candado = Cache::lock('timbrar-factura-'.$factura->id, (int) config('services.facturapi.timeout') + 10);

        if (! $candado->get()) {
            return ResultadoTimbrado::EnCurso;
        }

        try {
            return $accion();
        } finally {
            $candado->release();
        }
    }

    /**
     * Datos del emisor para la copia fiscal: los de la respuesta si facturapi.io
     * los trae; si no, los de la configuración.
     *
     * @param  array<string, mixed>  $respuesta
     * @return array{rfc: string|null, razon_social: string|null, regimen_fiscal: string|null, codigo_postal: string|null}
     */
    private function emisor(array $respuesta): array
    {
        $configurado = config('services.facturapi.emisor');
        $emisor = $respuesta['issuer_info'] ?? $respuesta['issuer'] ?? null;

        if (! is_array($emisor) || blank($emisor['tax_id'] ?? null)) {
            return $configurado;
        }

        return [
            'rfc' => $emisor['tax_id'],
            'razon_social' => $emisor['legal_name'] ?? $configurado['razon_social'],
            'regimen_fiscal' => isset($emisor['tax_system']) ? (string) $emisor['tax_system'] : $configurado['regimen_fiscal'],
            'codigo_postal' => data_get($emisor, 'address.zip') ?? $configurado['codigo_postal'],
        ];
    }
}
