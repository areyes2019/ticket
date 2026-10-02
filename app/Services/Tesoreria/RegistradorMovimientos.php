<?php

namespace App\Services\Tesoreria;

use App\Enums\TipoMovimiento;
use App\Exceptions\CuentaInactivaException;
use App\Exceptions\SaldoNegativoException;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Única vía para crear, cambiar o borrar movimientos y para tocar
 * saldo_actual. Cada operación, en una transacción:
 *
 * 1. bloquea las cuentas involucradas, en orden de id (dos transferencias
 *    cruzadas piden los bloqueos en el mismo orden y no se traban);
 * 2. exige cuenta activa a la que recibe un movimiento nuevo;
 * 3. escribe;
 * 4. recalcula saldo_actual = saldo_inicial + SUM(monto);
 * 5. si alguna cuenta quedó en negativo, lanza SaldoNegativoException y la
 *    transacción se deshace.
 *
 * Revisar el saldo después de escribir, con las cuentas bloqueadas, equivale
 * a revisarlo antes sin repetir la fórmula.
 */
class RegistradorMovimientos
{
    /**
     * Ingreso, egreso o ajuste. El monto llega como lo capturó el usuario y se
     * guarda con el signo de su efecto. Con $documento, el movimiento es
     * automático.
     */
    public function registrar(Cuenta $cuenta, TipoMovimiento $tipo, string $montoCapturado, string $fecha, string $concepto, ?Model $documento = null): Movimiento
    {
        if ($tipo === TipoMovimiento::Transferencia) {
            throw new LogicException('Las transferencias se registran con transferir().');
        }

        return DB::transaction(function () use ($cuenta, $tipo, $montoCapturado, $fecha, $concepto, $documento) {
            $bloqueada = $this->bloquear([$cuenta->id])->sole();
            $this->exigirActiva($bloqueada);

            $movimiento = $this->nuevo($bloqueada, $tipo, $this->conSigno($tipo, $montoCapturado), $fecha, $concepto);

            if ($documento !== null) {
                $movimiento->documentable()->associate($documento);
            }

            $movimiento->save();
            $this->recalcular($bloqueada);

            return $movimiento;
        });
    }

    /**
     * Dos filas con el mismo transferencia_id: una resta en la origen y otra
     * suma en la destino. Devuelve la de la origen.
     */
    public function transferir(Cuenta $origen, Cuenta $destino, string $monto, string $fecha, string $concepto): Movimiento
    {
        if ($origen->is($destino)) {
            throw new LogicException('Una transferencia necesita dos cuentas distintas.');
        }

        return DB::transaction(function () use ($origen, $destino, $monto, $fecha, $concepto) {
            $cuentas = $this->bloquear([$origen->id, $destino->id]);
            $cuentas->each(fn (Cuenta $cuenta) => $this->exigirActiva($cuenta));

            $centavos = abs(CalculadoraTotalesDocumento::centavos($monto));
            $transferencia = (string) Str::uuid();

            $salida = $this->nuevo($cuentas->find($origen->id), TipoMovimiento::Transferencia, CalculadoraTotalesDocumento::pesos(-$centavos), $fecha, $concepto);
            $entrada = $this->nuevo($cuentas->find($destino->id), TipoMovimiento::Transferencia, CalculadoraTotalesDocumento::pesos($centavos), $fecha, $concepto);

            foreach ([$salida, $entrada] as $movimiento) {
                $movimiento->transferencia_id = $transferencia;
                $movimiento->save();
            }

            $cuentas->each(fn (Cuenta $cuenta) => $this->recalcular($cuenta));

            return $salida;
        });
    }

    /**
     * Solo manuales que no sean transferencia. El tipo no cambia. Si cambia la
     * cuenta, la nueva tiene que estar activa y la anterior puede quedar en
     * negativo al perder un ingreso.
     */
    public function actualizar(Movimiento $movimiento, Cuenta $cuenta, string $montoCapturado, string $fecha, string $concepto): Movimiento
    {
        $this->exigirManual($movimiento);

        if ($movimiento->esTransferencia()) {
            throw new LogicException('Una transferencia no se edita.');
        }

        return DB::transaction(function () use ($movimiento, $cuenta, $montoCapturado, $fecha, $concepto) {
            $cuentas = $this->bloquear([$movimiento->cuenta_id, $cuenta->id]);
            $nueva = $cuentas->find($cuenta->id);

            if ($nueva->id !== $movimiento->cuenta_id) {
                $this->exigirActiva($nueva);
            }

            $movimiento->cuenta()->associate($nueva);
            $movimiento->fill([
                'monto' => $this->conSigno($movimiento->tipo, $montoCapturado),
                'fecha' => $fecha,
                'concepto' => $concepto,
            ])->save();

            $cuentas->each(fn (Cuenta $bloqueada) => $this->recalcular($bloqueada));

            return $movimiento;
        });
    }

    /**
     * Solo manuales. En una transferencia se borran sus dos filas.
     */
    public function eliminar(Movimiento $movimiento): void
    {
        $this->exigirManual($movimiento);

        $filas = $movimiento->esTransferencia()
            ? Movimiento::query()->where('transferencia_id', $movimiento->transferencia_id)
            : Movimiento::query()->whereKey($movimiento->id);

        $this->borrar($filas->get());
    }

    /**
     * Borra los movimientos que generó un documento (por ejemplo, al eliminar
     * un pago de cotización). No exige cuenta activa: quitar un movimiento no
     * es un movimiento nuevo.
     */
    public function eliminarDeDocumento(Model $documento): void
    {
        $this->borrar(Movimiento::query()->whereMorphedTo('documentable', $documento)->get());
    }

    /**
     * @param  Collection<int, Movimiento>  $movimientos
     */
    private function borrar(Collection $movimientos): void
    {
        if ($movimientos->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($movimientos) {
            $cuentas = $this->bloquear($movimientos->pluck('cuenta_id')->all());

            Movimiento::query()->whereKey($movimientos->modelKeys())->delete();

            $cuentas->each(fn (Cuenta $cuenta) => $this->recalcular($cuenta));
        });
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Cuenta>
     */
    private function bloquear(array $ids): Collection
    {
        return Cuenta::query()
            ->whereKey(array_unique($ids))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function nuevo(Cuenta $cuenta, TipoMovimiento $tipo, string $monto, string $fecha, string $concepto): Movimiento
    {
        $movimiento = new Movimiento(['tipo' => $tipo, 'monto' => $monto, 'fecha' => $fecha, 'concepto' => $concepto]);
        $movimiento->user_id = $cuenta->user_id;
        $movimiento->cuenta()->associate($cuenta);

        return $movimiento;
    }

    /**
     * El efecto sobre el saldo: el egreso resta, el ingreso suma y el ajuste
     * conserva el signo con que se capturó.
     */
    private function conSigno(TipoMovimiento $tipo, string $montoCapturado): string
    {
        $centavos = CalculadoraTotalesDocumento::centavos($montoCapturado);

        return CalculadoraTotalesDocumento::pesos(match ($tipo) {
            TipoMovimiento::Ingreso => abs($centavos),
            TipoMovimiento::Egreso => -abs($centavos),
            default => $centavos,
        });
    }

    private function recalcular(Cuenta $cuenta): void
    {
        $saldo = CalculadoraTotalesDocumento::centavos($cuenta->saldo_inicial)
            + CalculadoraTotalesDocumento::centavos($cuenta->movimientos()->sum('monto'));

        if ($saldo < 0) {
            throw new SaldoNegativoException($cuenta);
        }

        $cuenta->forceFill(['saldo_actual' => CalculadoraTotalesDocumento::pesos($saldo)])->save();
    }

    private function exigirActiva(Cuenta $cuenta): void
    {
        if (! $cuenta->activa) {
            throw new CuentaInactivaException($cuenta);
        }
    }

    private function exigirManual(Movimiento $movimiento): void
    {
        if ($movimiento->esAutomatico()) {
            throw new LogicException('Un movimiento automático se corrige desde su documento origen.');
        }
    }
}
