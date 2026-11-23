<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Enums\AgenteWhatsapp;
use App\Models\DatoBancario;
use App\Services\Whatsapp\ContextoTurno;

/**
 * Ventas (041, regla 41): el texto de "Formas de pago" de las indicaciones
 * si está lleno; si no, los mismos datos bancarios de las cotizaciones (027).
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
        // Lo que el negocio escribió en "Formas de pago" manda sobre los bancos
        // de las cotizaciones: decide qué datos se comparten por WhatsApp.
        $formasDePago = $turno->numero()->indicaciones(AgenteWhatsapp::Ventas)['pago'] ?? null;

        if (filled($formasDePago)) {
            return ResultadoHerramienta::ok([
                'formas_de_pago' => $formasDePago,
                'indicacion' => 'Comparte estos datos de pago tal cual, sin agregar cuentas, CLABE ni otros números que no estén aquí.',
            ], 'dio los datos bancarios');
        }

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
