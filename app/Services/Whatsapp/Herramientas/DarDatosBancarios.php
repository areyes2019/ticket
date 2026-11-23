<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Models\DatoBancario;
use App\Services\Whatsapp\ContextoTurno;

/**
 * Ventas (041, regla 41): los mismos datos bancarios que salen en las
 * cotizaciones (027).
 */
class DarDatosBancarios extends HerramientaBase
{
    public function nombre(): string
    {
        return 'dar_datos_bancarios';
    }

    public function descripcion(): string
    {
        return 'Regresa los datos bancarios del negocio para que el cliente deposite el anticipo. Cópialos tal cual en tu respuesta.';
    }

    public function esquema(): array
    {
        return self::objeto([]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $cuentas = DatoBancario::query()->paraCotizacion()->get()->map(fn (DatoBancario $d) => array_filter([
            'banco' => $d->nombre_banco,
            'beneficiario' => $d->beneficiario,
            'cuenta' => $d->numero_cuenta,
            'tarjeta' => $d->tarjeta,
            'clabe' => $d->clabe,
        ]))->values()->all();

        if ($cuentas === []) {
            return ResultadoHerramienta::error('No hay datos bancarios configurados. Pasa con un asesor para el pago.');
        }

        return ResultadoHerramienta::ok(['cuentas' => $cuentas], 'dio los datos bancarios');
    }
}
