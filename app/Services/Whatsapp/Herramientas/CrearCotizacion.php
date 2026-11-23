<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Enums\RegimenFiscal;
use App\Models\Cliente;
use App\Models\Emisor;
use App\Services\Cotizaciones\CreadorCotizacion;
use App\Services\Whatsapp\ContextoTurno;
use App\Services\Whatsapp\ReceptorWebhook;
use Illuminate\Validation\Rule;

/**
 * Ventas (041, reglas 38 a 40): arma una cotización solo con artículos del
 * catálogo. El precio lo pone el sistema, nunca el agente.
 */
class CrearCotizacion extends HerramientaBase
{
    public const RFC_PUBLICO = 'XAXX010101000';

    public const RAZON_PUBLICO = 'PÚBLICO EN GENERAL';

    public function __construct(private CreadorCotizacion $creador) {}

    public function nombre(): string
    {
        return 'crear_cotizacion';
    }

    public function descripcion(): string
    {
        return 'Crea una cotización con artículos del catálogo (por su id de buscar_articulos) y cantidades. '
            .'El sistema calcula precios, descuentos y totales. En notas van los datos del sello que dio el cliente '
            .'(texto, tamaño, tinta, logo). contacto_nombre es el nombre de la persona; pídeselo si no lo sabes. '
            .'Regresa folio y total. Después usa enviar_cotizacion para mandarle el PDF.';
    }

    public function esquema(): array
    {
        return self::objeto([
            'lineas' => [
                'type' => 'array',
                'description' => 'De 1 a 20 artículos.',
                'items' => self::objeto([
                    'articulo_id' => ['type' => 'integer'],
                    'cantidad' => ['type' => 'integer'],
                ]),
            ],
            'notas' => ['type' => ['string', 'null'], 'description' => 'Datos del sello: texto, tamaño, tinta, logo.'],
            'contacto_nombre' => ['type' => ['string', 'null'], 'description' => 'Nombre de la persona.'],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $user = $turno->user();

        $datos = $this->validar($entrada, [
            'lineas' => ['required', 'array', 'min:1', 'max:20'],
            'lineas.*.articulo_id' => ['required', 'integer', 'distinct', Rule::exists('articulos', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:500'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'contacto_nombre' => ['nullable', 'string', 'max:120'],
        ]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        $cliente = $this->cliente($turno);

        if ($cliente === null) {
            return ResultadoHerramienta::error('Hay varios clientes con este teléfono. Pregunta su nombre o RFC, o pasa con un asesor.');
        }

        $lineas = array_map(fn (array $l) => ['articulo_id' => (int) $l['articulo_id'], 'cantidad' => (int) $l['cantidad']], $datos['lineas']);
        $calculo = CreadorCotizacion::lineasDeCatalogo($user, $cliente, $lineas);

        $cotizacion = $this->creador->crear($user, ['cliente_id' => $cliente->id], $calculo['lineas'], $calculo['totales'], [
            'notas' => $datos['notas'] ?? null,
            'contacto_nombre' => $datos['contacto_nombre'] ?? $turno->conversacion->nombre_perfil,
            'contacto_telefono' => $turno->conversacion->telefonoLegible(),
            'whatsapp_conversacion_id' => $turno->conversacion->id,
        ]);

        return ResultadoHerramienta::ok([
            'cotizacion_id' => $cotizacion->id,
            'folio' => $cotizacion->folio_formateado,
            'total_con_iva' => (float) $cotizacion->total,
            'descuento_cliente_porcentaje' => (float) $cotizacion->descuento_cliente_porcentaje,
            'lineas' => $cotizacion->lineas()->get()->map(fn ($l) => [
                'descripcion' => $l->descripcion,
                'modelo' => $l->modelo,
                'cantidad' => $l->cantidad,
                'importe' => (float) $l->importe,
            ])->all(),
        ], 'creó la cotización '.$cotizacion->folio_formateado.' por $'.number_format((float) $cotizacion->total, 2));
    }

    /**
     * Regla 40: el cliente del teléfono si es uno solo; ninguno → "PÚBLICO EN
     * GENERAL" de la cuenta (se crea si falta); varios → null.
     */
    private function cliente(ContextoTurno $turno): ?Cliente
    {
        if ($turno->conversacion->cliente !== null) {
            return $turno->conversacion->cliente;
        }

        $user = $turno->user();
        $coinciden = Cliente::where('user_id', $user->id)->where('telefono_10', $turno->telefono10())->count();

        if ($coinciden > 1) {
            return null;
        }

        if ($coinciden === 1) {
            $cliente = ReceptorWebhook::clienteUnico($user->id, $turno->telefono10());
            $turno->conversacion->forceFill(['cliente_id' => $cliente->id])->save();

            return $cliente;
        }

        return $user->clientes()->firstOrCreate(['rfc' => self::RFC_PUBLICO], [
            'razon_social' => self::RAZON_PUBLICO,
            'regimen_fiscal' => RegimenFiscal::SinObligacionesFiscales->value,
            'codigo_postal_fiscal' => self::codigoPostalEmisor(),
        ]);
    }

    private static function codigoPostalEmisor(): string
    {
        preg_match('/\b(\d{5})\b/', (string) Emisor::actual()->domicilio, $coincidencia);

        return $coincidencia[1] ?? '00000';
    }
}
