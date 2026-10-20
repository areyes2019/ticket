<?php

namespace App\Models\Concerns;

use App\Models\Articulo;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Models\Reembolso;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

/**
 * Lo común de los documentos que se reembolsan (038): la venta y la
 * cotización sin venta. Cada uno dice cuáles son sus pagos, su factura y por
 * qué no se puede reembolsar.
 */
trait SeReembolsa
{
    abstract public function estaReembolsado(): bool;

    /**
     * Por qué no se puede reembolsar; null si se puede. La factura no bloquea.
     */
    abstract public function motivoNoReembolsable(): ?string;

    /**
     * Los pagos que regresan, cada uno con su cuenta y su monto.
     *
     * @return Collection<int, Model>
     */
    abstract public function pagosParaReembolso(): Collection;

    /**
     * La factura no cancelada del documento (o de su cotización), si la hay.
     */
    abstract public function facturaParaReembolso(): ?Factura;

    /**
     * @return MorphOne<Reembolso, $this>
     */
    public function reembolso(): MorphOne
    {
        return $this->morphOne(Reembolso::class, 'reembolsable');
    }

    public function puedeReembolsarse(): bool
    {
        return $this->motivoNoReembolsable() === null;
    }

    public function montoReembolsable(): string
    {
        return CalculadoraTotalesDocumento::pesos(
            $this->pagosParaReembolso()->sum(fn (Model $pago) => CalculadoraTotalesDocumento::centavos($pago->monto))
        );
    }

    /**
     * Lo que regresaría a existencias: lo que el documento sacó y no ha
     * regresado (el mismo cálculo del reembolso).
     *
     * @return Collection<int, array{articulo: Articulo, cantidad: int}>
     */
    public function articulosQueRegresan(): Collection
    {
        $netos = MovimientoInventario::netosSalidos($this);
        $articulos = Articulo::withTrashed()->whereKey($netos->keys())->get()->keyBy('id');

        return $netos->map(fn (int $cantidad, int $articuloId) => ['articulo' => $articulos[$articuloId], 'cantidad' => $cantidad])->values();
    }

    /**
     * Lo que sale de cada cuenta, en el orden en que aparecen los pagos.
     *
     * @return Collection<int, array{cuenta: Cuenta, monto: string}>
     */
    public function devolucionesPorCuenta(): Collection
    {
        return $this->pagosParaReembolso()
            ->groupBy('cuenta_id')
            ->map(fn (Collection $pagos) => [
                'cuenta' => $pagos->first()->cuenta,
                'monto' => CalculadoraTotalesDocumento::pesos($pagos->sum(fn (Model $pago) => CalculadoraTotalesDocumento::centavos($pago->monto))),
            ])
            ->values();
    }
}
