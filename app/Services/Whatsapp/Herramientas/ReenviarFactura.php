<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Enums\EstadoFactura;
use App\Models\Factura;
use App\Services\Whatsapp\ContextoTurno;
use App\Services\Whatsapp\EnvioFacturaWhatsapp;
use Illuminate\Database\Eloquent\Builder;

/**
 * Facturación (041, regla 50): reenvía una factura timbrada solo si es del
 * dueño del chat. El mismo texto exista o no, para no confirmar folios ajenos.
 */
class ReenviarFactura extends HerramientaBase
{
    public const NO_ENCONTRADA = 'No encontré esa factura a tu nombre.';

    public function __construct(private EnvioFacturaWhatsapp $envio) {}

    public function nombre(): string
    {
        return 'reenviar_factura';
    }

    public function descripcion(): string
    {
        return 'Reenvía por este chat el PDF y el XML de una factura ya timbrada del cliente. Manda el folio de la factura o del ticket; si es null, reenvía la más reciente.';
    }

    public function esquema(): array
    {
        return self::objeto([
            'folio' => ['type' => ['string', 'null']],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $datos = $this->validar($entrada, ['folio' => ['nullable', 'string', 'max:40']]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        $diez = $turno->telefono10();

        $facturas = Factura::where('user_id', $turno->user()->id)
            ->where('estado', EstadoFactura::Timbrada->value)
            ->where(fn (Builder $q) => $q
                ->whereHas('cliente', fn (Builder $c) => $c->where('telefono_10', $diez))
                ->orWhereHas('pedido', fn (Builder $p) => $p->where('cliente_telefono', '+52'.$diez)))
            ->with('pedido')
            ->latest('id')
            ->limit(30)
            ->get();

        $buscado = strtoupper((string) preg_replace('/[\s-]+/', '', (string) ($datos['folio'] ?? '')));
        $digitos = BuscarVenta::folio($buscado);

        $factura = $buscado === ''
            ? $facturas->first()
            : $facturas->first(fn (Factura $f) => strtoupper(str_replace('-', '', $f->folioVisible())) === $buscado
                || ($digitos !== null && ((int) $f->folio === $digitos || (int) $f->facturapi_folio === $digitos || (int) $f->pedido?->folio === $digitos)));

        if ($factura === null) {
            return ResultadoHerramienta::error(self::NO_ENCONTRADA, 'no encontró la factura');
        }

        $this->envio->enviar($turno->conversacion, $factura, $turno->conversacion->agente_actual->value);
        $turno->documentosEnviados += 2;

        return ResultadoHerramienta::ok(['enviada' => true, 'folio' => $factura->folioVisible()], 'reenvió la factura '.$factura->folioVisible());
    }
}
