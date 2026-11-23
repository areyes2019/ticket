<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Models\Cliente;
use App\Services\Whatsapp\ContextoTurno;

/**
 * Facturación (041, regla 46): los datos fiscales que ya hay para este
 * teléfono, para solo confirmarlos. Nunca los de otro teléfono.
 */
class DatosFiscalesGuardados extends HerramientaBase
{
    public function nombre(): string
    {
        return 'datos_fiscales_guardados';
    }

    public function descripcion(): string
    {
        return 'Regresa los datos fiscales que el negocio ya tiene registrados para el teléfono de este chat (puede haber más de uno). Pregúntale al cliente si son correctos antes de usarlos.';
    }

    public function esquema(): array
    {
        return self::objeto([]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $clientes = Cliente::where('user_id', $turno->user()->id)
            ->where('telefono_10', $turno->telefono10())
            ->whereNotIn('rfc', [CrearCotizacion::RFC_PUBLICO, 'XEXX010101000'])
            ->latest('updated_at')
            ->limit(3)
            ->get();

        return ResultadoHerramienta::ok([
            'datos' => $clientes->map(fn (Cliente $c) => [
                'rfc' => $c->rfc,
                'razon_social' => $c->razon_social,
                'regimen_fiscal' => $c->regimen_fiscal?->value ?? $c->regimen_fiscal,
                'codigo_postal_fiscal' => $c->codigo_postal_fiscal,
                'correo' => $c->correo,
            ])->values()->all(),
        ], 'consultó datos fiscales guardados ('.$clientes->count().')');
    }
}
