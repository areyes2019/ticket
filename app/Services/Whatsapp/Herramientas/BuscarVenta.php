<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Models\WhatsappMensaje;
use App\Services\Constancia\QrLector;
use App\Services\Whatsapp\ContextoTurno;
use Illuminate\Support\Facades\Storage;

/**
 * Facturación (041, reglas 44 y 45). Una venta se puede facturar por este
 * chat solo si el teléfono es el de la venta (con el folio del ticket) o si
 * el cliente manda el enlace de autofactura o la foto de su QR: el folio solo
 * es un número consecutivo que cualquiera puede adivinar.
 */
class BuscarVenta extends HerramientaBase
{
    public function __construct(private QrLector $qr) {}

    public function nombre(): string
    {
        return 'buscar_venta';
    }

    public function descripcion(): string
    {
        return 'Busca la venta que el cliente quiere facturar. Manda el folio del ticket (por ejemplo "0042" o "PED-0042"), '
            .'o el enlace de autofactura que pegó el cliente, o el archivo_id de la foto del QR del ticket. '
            .'Regresa la venta, su total y si se puede facturar (con el motivo si no). '
            .'Con solo el folio, la venta debe estar registrada con el teléfono de este chat.';
    }

    public function esquema(): array
    {
        return self::objeto([
            'folio' => ['type' => ['string', 'null']],
            'enlace' => ['type' => ['string', 'null'], 'description' => 'El enlace de autofactura completo, si lo pegó.'],
            'archivo_id' => ['type' => ['integer', 'null'], 'description' => 'Id de la foto del QR del ticket que mandó el cliente.'],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $datos = $this->validar($entrada, [
            'folio' => ['nullable', 'string', 'max:30'],
            'enlace' => ['nullable', 'string', 'max:500'],
            'archivo_id' => ['nullable', 'integer'],
        ]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        $token = self::token($datos['enlace'] ?? null) ?? $this->tokenDeImagen($turno, $datos['archivo_id'] ?? null);
        $pedidos = $turno->user()->pedidos();

        $pedido = match (true) {
            $token !== null => $pedidos->where('autofactura_token', $token)->first(),
            ($folio = self::folio($datos['folio'] ?? null)) !== null => $pedidos->where('folio', $folio)->where('cliente_telefono', '+52'.$turno->telefono10())->first(),
            default => false,
        };

        if ($pedido === false) {
            return ResultadoHerramienta::error('Pide el folio del ticket, el enlace de autofactura o una foto del QR del ticket.');
        }

        if ($pedido === null) {
            return ResultadoHerramienta::error(
                $token !== null
                    ? 'Ese enlace o QR no corresponde a ninguna venta.'
                    : 'No encontré una venta con ese folio registrada con este teléfono. Pide el enlace de autofactura o una foto del QR del ticket; si no los tiene, pasa con un asesor.',
                'no encontró la venta',
            );
        }

        if (! in_array($pedido->id, $turno->ventasValidadas, true)) {
            $turno->ventasValidadas[] = $pedido->id;
        }

        $motivo = $pedido->motivoAutofacturaNoDisponible();
        $factura = $pedido->facturaTimbrada() ?? $pedido->facturaDeLaCotizacion();

        return ResultadoHerramienta::ok([
            'venta_id' => $pedido->id,
            'folio' => $pedido->folio_formateado,
            'fecha' => $pedido->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y'),
            'total' => (float) $pedido->total,
            'se_puede_facturar' => $motivo === null,
            'motivo' => $motivo,
            'factura_existente' => $factura?->folioVisible(),
        ], 'consultó la venta '.$pedido->folio_formateado.($motivo === null ? '' : ' ('.$motivo.')'));
    }

    public static function token(?string $texto): ?string
    {
        return $texto !== null && preg_match('~autofactura/([A-Za-z0-9]{64})~', $texto, $m) ? $m[1] : null;
    }

    public static function folio(?string $texto): ?int
    {
        $digitos = preg_replace('/\D/', '', (string) $texto);

        return $digitos === '' || strlen($digitos) > 9 ? null : (int) $digitos;
    }

    private function tokenDeImagen(ContextoTurno $turno, ?int $archivoId): ?string
    {
        if ($archivoId === null) {
            return null;
        }

        $mensaje = $turno->conversacion->mensajes()->whereKey($archivoId)->where('direccion', WhatsappMensaje::ENTRANTE)->whereNotNull('archivo_ruta')->first();

        if ($mensaje === null || ! $mensaje->esImagen()) {
            return null;
        }

        return self::token($this->qr->leerDeImagen(Storage::disk('local')->get($mensaje->archivo_ruta)));
    }
}
