<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Enums\RegimenFiscal;
use App\Enums\UsoCfdi;
use App\Models\Pedido;
use App\Rules\RfcValido;
use App\Services\Facturacion\ResultadoTimbrado;
use App\Services\Pedidos\Autofacturador;
use App\Services\Whatsapp\ContextoTurno;
use App\Services\Whatsapp\EnvioFacturaWhatsapp;
use Illuminate\Validation\Rule;

/**
 * Facturación (041, reglas 47 a 49): timbra con el autofacturador del portal
 * (019), con sus mismas reglas y su candado. Solo ventas que buscar_venta
 * validó en esta conversación y con la confirmación del cliente.
 */
class TimbrarFactura extends HerramientaBase
{
    public function __construct(
        private Autofacturador $autofacturador,
        private EnvioFacturaWhatsapp $envio,
    ) {}

    public function nombre(): string
    {
        return 'timbrar_factura';
    }

    public function descripcion(): string
    {
        return 'Timbra la factura de una venta encontrada con buscar_venta y le manda al cliente el PDF y el XML por este chat. '
            .'Antes muéstrale al cliente un resumen (venta, total, RFC, razón social, régimen, código postal y uso de CFDI) y espera su "sí". '
            .'cliente_confirmo debe ser true solo después de ese sí. regimen_fiscal es la clave de 3 dígitos (ej. 612) y uso_cfdi la clave (ej. G03).';
    }

    public function esquema(): array
    {
        return self::objeto([
            'venta_id' => ['type' => 'integer'],
            'rfc' => ['type' => 'string'],
            'razon_social' => ['type' => 'string'],
            'regimen_fiscal' => ['type' => 'string'],
            'codigo_postal_fiscal' => ['type' => 'string'],
            'uso_cfdi' => ['type' => 'string'],
            'correo' => ['type' => ['string', 'null']],
            'cliente_confirmo' => ['type' => 'boolean'],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $entrada['rfc'] = strtoupper((string) preg_replace('/\s+/', '', (string) ($entrada['rfc'] ?? '')));
        $entrada['razon_social'] = trim((string) preg_replace('/\s+/', ' ', (string) ($entrada['razon_social'] ?? '')));

        $datos = $this->validar($entrada, [
            'venta_id' => ['required', 'integer'],
            'rfc' => ['required', 'string', new RfcValido],
            'razon_social' => ['required', 'string', 'max:255'],
            'regimen_fiscal' => ['required', Rule::enum(RegimenFiscal::class)],
            'codigo_postal_fiscal' => ['required', 'string', 'regex:/^\d{5}$/'],
            'uso_cfdi' => ['required', Rule::in(array_keys(UsoCfdi::opcionesFactura()))],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'cliente_confirmo' => ['required', 'boolean'],
        ]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        if (! $datos['cliente_confirmo']) {
            return ResultadoHerramienta::error('Primero muestra el resumen y espera que el cliente confirme.');
        }

        if (! in_array((int) $datos['venta_id'], $turno->ventasValidadas, true)) {
            return ResultadoHerramienta::error('Primero busca la venta con buscar_venta en esta conversación.');
        }

        $pedido = Pedido::where('user_id', $turno->user()->id)->find($datos['venta_id']);

        if ($pedido === null) {
            return ResultadoHerramienta::error('No encontré esa venta.');
        }

        $resultado = $this->autofacturador->facturar($pedido, [
            'rfc' => $datos['rfc'],
            'razon_social' => $datos['razon_social'],
            'regimen_fiscal' => $datos['regimen_fiscal'],
            'codigo_postal_fiscal' => $datos['codigo_postal_fiscal'],
            'uso_cfdi' => $datos['uso_cfdi'],
            'correo' => $datos['correo'] ?? null,
        ]);

        // Regla 49: ya no se podía (por ejemplo, ya se facturó).
        if ($resultado->motivo !== null) {
            return ResultadoHerramienta::error($resultado->motivo, 'no timbró: '.$resultado->motivo);
        }

        $factura = $resultado->factura;
        $factura?->forceFill(['whatsapp_conversacion_id' => $turno->conversacion->id])->save();

        return match ($resultado->timbrado) {
            ResultadoTimbrado::Timbrada => $this->timbrada($turno, $factura, $resultado->correoEnviado),
            ResultadoTimbrado::ErrorDatos => ResultadoHerramienta::error('El SAT rechazó los datos: '.$factura->error_timbrado.' Explícale al cliente qué corregir.', 'el SAT rechazó los datos'),
            ResultadoTimbrado::EnCurso => ResultadoHerramienta::ok(['en_curso' => true, 'nota' => 'La factura se está procesando. Dile que se la mandas en un momento y vuelve a intentar timbrar en el siguiente mensaje.'], 'timbrado en curso'),
            default => ResultadoHerramienta::error('El servicio de facturación no responde. Dile al cliente que lo intentamos de nuevo en unos minutos; sus datos quedaron guardados.', 'el servicio de facturación no respondió'),
        };
    }

    private function timbrada(ContextoTurno $turno, $factura, bool $correoEnviado): ResultadoHerramienta
    {
        if ($turno->conversacion->cliente_id === null) {
            $turno->conversacion->forceFill(['cliente_id' => $factura->cliente_id])->save();
        }

        $this->envio->enviar($turno->conversacion, $factura, $turno->conversacion->agente_actual->value);
        $turno->documentosEnviados += 2;

        return ResultadoHerramienta::ok([
            'timbrada' => true,
            'folio' => $factura->folioVisible(),
            'enviada_por_chat' => true,
            'enviada_por_correo' => $correoEnviado,
        ], 'timbró la factura '.$factura->folioVisible());
    }
}
