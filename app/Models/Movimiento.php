<?php

namespace App\Models;

use App\Enums\TipoMovimiento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Operación que cambia el saldo de una cuenta. monto lleva signo: es el
 * efecto sobre el saldo (un egreso se guarda negativo). Así el saldo de una
 * cuenta es saldo_inicial + SUM(monto).
 *
 * Solo RegistradorMovimientos crea, cambia o borra movimientos. user_id y
 * transferencia_id no son asignables. Un movimiento con documento origen es
 * automático y se corrige desde ese documento.
 */
#[Fillable(['cuenta_id', 'tipo', 'monto', 'fecha', 'concepto'])]
class Movimiento extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function esAutomatico(): bool
    {
        return $this->documentable_type !== null;
    }

    public function esTransferencia(): bool
    {
        return $this->transferencia_id !== null;
    }

    /**
     * La otra fila de la transferencia.
     */
    public function contraparte(): ?self
    {
        if (! $this->esTransferencia()) {
            return null;
        }

        return self::query()
            ->where('transferencia_id', $this->transferencia_id)
            ->whereKeyNot($this->id)
            ->with('cuenta')
            ->first();
    }

    /**
     * El valor que escribió el usuario: positivo salvo en un ajuste, que
     * conserva su signo.
     */
    public function montoCapturado(): string
    {
        return $this->tipo === TipoMovimiento::Ajuste ? $this->monto : ltrim($this->monto, '-');
    }

    public function sumaAlSaldo(): bool
    {
        return (float) $this->monto >= 0;
    }

    /**
     * El documento que generó el movimiento, listo para la vista; null en los
     * manuales. Los módulos que se enganchen a Tesorería agregan su caso aquí.
     *
     * muestra_utilidad dice si la columna de utilidad aplica: un egreso no tiene
     * utilidad de venta.
     *
     * @return array{etiqueta: string, url: string, utilidad: string|null, utilidad_parcial: bool, muestra_utilidad: bool}|null
     */
    public function documentoOrigen(): ?array
    {
        $documento = $this->documentable;

        if ($documento instanceof CotizacionPago) {
            $cotizacion = $documento->cotizacion;
            $utilidad = $cotizacion->utilidadVenta();

            return [
                'etiqueta' => $cotizacion->folio_formateado,
                'url' => route('cotizaciones.show', $cotizacion),
                'utilidad' => $utilidad['utilidad'],
                'utilidad_parcial' => $utilidad['parcial'],
                'muestra_utilidad' => true,
            ];
        }

        if ($documento instanceof PedidoPago) {
            $pedido = $documento->pedido;
            $utilidad = $pedido->utilidadVenta();

            return [
                'etiqueta' => $pedido->folio_formateado,
                'url' => route('pedidos.show', $pedido),
                'utilidad' => $utilidad['utilidad'],
                'utilidad_parcial' => $utilidad['parcial'],
                'muestra_utilidad' => true,
            ];
        }

        if ($documento instanceof OrdenCompra) {
            return [
                'etiqueta' => $documento->folio_formateado,
                'url' => route('ordenes-compra.show', $documento),
                'utilidad' => null,
                'utilidad_parcial' => false,
                'muestra_utilidad' => false,
            ];
        }

        return null;
    }

    /**
     * Filtros del listado, combinados con Y. Los vacíos se ignoran. Las fechas
     * son días calendario del negocio (fecha es date, sin hora).
     *
     * @param  Builder<self>  $consulta
     * @param  array{fecha_desde: string, fecha_hasta: string, cuenta_id: string, tipo: string, concepto: string}  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        $consulta
            ->when($filtros['fecha_desde'] !== '', fn (Builder $q) => $q->where('fecha', '>=', $filtros['fecha_desde']))
            // "< día siguiente" y no "<= día": SQLite guarda la fecha con hora.
            ->when($filtros['fecha_hasta'] !== '', fn (Builder $q) => $q->where('fecha', '<', Carbon::parse($filtros['fecha_hasta'])->addDay()->toDateString()))
            ->when($filtros['cuenta_id'] !== '', fn (Builder $q) => $q->where('cuenta_id', (int) $filtros['cuenta_id']))
            ->when($filtros['tipo'] !== '', fn (Builder $q) => $q->where('tipo', $filtros['tipo']))
            ->when($filtros['concepto'] !== '', fn (Builder $q) => $q->where('concepto', 'like', '%'.addcslashes($filtros['concepto'], '%_\\').'%'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'monto' => 'decimal:2',
            'fecha' => 'date',
        ];
    }
}
