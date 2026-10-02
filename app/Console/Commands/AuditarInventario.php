<?php

namespace App\Console\Commands;

use App\Enums\TipoMovimientoInventario;
use App\Models\Articulo;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Services\Inventario\RegistradorInventario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventario:auditar {--usuario= : Solo los artículos de este usuario (id)}')]
#[Description('Reconstruye las existencias desde su historial y reporta las que no coinciden con lo guardado (no corrige nada)')]
class AuditarInventario extends Command
{
    /**
     * Reaplica las tres reglas desde (0, 0), en orden, y compara contra los
     * resultantes de cada movimiento y contra la fila guardada (incluidas las
     * quitadas). Solo reporta: un descuadre se corrige con un ajuste manual,
     * que queda registrado; repararlo aquí borraría la evidencia.
     */
    public function handle(): int
    {
        $usuario = $this->option('usuario');

        $articulos = Articulo::withTrashed()
            ->when($usuario !== null, fn ($q) => $q->where('user_id', (int) $usuario))
            ->where(fn ($q) => $q->whereHas('movimientosInventario')
                ->orWhereIn('id', Existencia::withTrashed()->select('articulo_id')))
            ->orderBy('id');

        $descuadres = [];

        foreach ($articulos->lazy() as $articulo) {
            $descuadre = $this->auditar($articulo);

            if ($descuadre !== null) {
                $descuadres[] = $descuadre;
            }
        }

        if ($descuadres === []) {
            $this->info('Sin descuadres: todas las existencias coinciden con su historial.');

            return self::SUCCESS;
        }

        $this->table(['Artículo', 'Modelo', 'Guardado (existencia/faltante)', 'Reconstruido (existencia/faltante)', 'Detalle'], $descuadres);
        $this->error(count($descuadres) === 1 ? '1 artículo con descuadre.' : count($descuadres).' artículos con descuadre.');

        return self::FAILURE;
    }

    /**
     * @return list<string|int>|null
     */
    private function auditar(Articulo $articulo): ?array
    {
        [$existencia, $faltante] = [0, 0];
        $detalle = null;

        foreach (MovimientoInventario::where('articulo_id', $articulo->id)->orderBy('id')->lazy() as $movimiento) {
            [$existencia, $faltante] = match ($movimiento->tipo) {
                TipoMovimientoInventario::Entrada => RegistradorInventario::entrar($existencia, $faltante, $movimiento->cantidad),
                TipoMovimientoInventario::Salida => RegistradorInventario::salir($existencia, $faltante, $movimiento->cantidad),
                TipoMovimientoInventario::Ajuste => RegistradorInventario::fijar($movimiento->cantidad),
            };

            if ($detalle === null && [$existencia, $faltante] !== [$movimiento->existencia_resultante, $movimiento->faltante_resultante]) {
                $detalle = "El movimiento {$movimiento->id} dice {$movimiento->existencia_resultante}/{$movimiento->faltante_resultante}";
            }
        }

        $fila = Existencia::withTrashed()->where('articulo_id', $articulo->id)->first();
        $guardado = [$fila->existencia ?? 0, $fila->faltante_pendiente ?? 0];

        if ($detalle === null && $guardado === [$existencia, $faltante]) {
            return null;
        }

        return [$articulo->id, $articulo->modelo, implode('/', $guardado), "{$existencia}/{$faltante}", $detalle ?? 'La fila no coincide con el historial'];
    }
}
