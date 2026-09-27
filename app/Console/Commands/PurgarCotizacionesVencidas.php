<?php

namespace App\Console\Commands;

use App\Models\Cotizacion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cotizaciones:purgar-vencidas')]
#[Description('Elimina las cotizaciones en borrador o enviadas, sin pagos, con más de '.Cotizacion::DIAS_CADUCIDAD.' días sin movimiento')]
class PurgarCotizacionesVencidas extends Command
{
    /**
     * Borrado físico masivo: las líneas se van por la FK en cascada. Es
     * idempotente, una segunda corrida no encuentra nada que borrar.
     */
    public function handle(): int
    {
        $borradas = Cotizacion::query()->vencidas()->delete();

        $this->info($borradas === 1 ? 'Se eliminó 1 cotización vencida.' : "Se eliminaron {$borradas} cotizaciones vencidas.");

        return self::SUCCESS;
    }
}
