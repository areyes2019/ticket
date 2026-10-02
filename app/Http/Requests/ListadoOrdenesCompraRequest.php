<?php

namespace App\Http\Requests;

use App\Enums\EstadoOrdenCompra;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

/**
 * Parámetros del listado de órdenes de compra, compartidos por el listado y
 * la búsqueda dinámica.
 *
 * No rechaza nada: un valor inválido se ignora. Las fechas son días
 * calendario completos en la zona del negocio.
 */
class ListadoOrdenesCompraRequest extends FormRequest
{
    public const PERIODOS = ['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes'];

    public const PERIODO_DEFECTO = 'mes';

    public const POR_PAGINA = 25;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Filtros de texto tal como se escribieron (para volver a pintarlos).
     *
     * @return array{proveedor: string, rfc: string, folio: string, estado: string}
     */
    public function campos(): array
    {
        $estado = $this->string('estado')->toString();

        return [
            'proveedor' => $this->string('proveedor')->trim()->toString(),
            'rfc' => $this->string('rfc')->trim()->upper()->replaceMatches('/\s+/', '')->toString(),
            'folio' => $this->string('folio')->trim()->toString(),
            'estado' => EstadoOrdenCompra::tryFrom($estado) ? $estado : '',
        ];
    }

    /**
     * Filtros listos para OrdenCompra::filtrar().
     *
     * @return array<string, mixed>
     */
    public function filtros(): array
    {
        [$desde, $hasta] = $this->rango();

        return [
            ...$this->campos(),
            'folio' => $this->folio(),
            'desde' => $desde,
            'hasta' => $hasta,
        ];
    }

    /**
     * "15", "0015" u "OC-0015" → 15; otro texto no filtra.
     */
    public function folio(): ?int
    {
        $folio = $this->campos()['folio'];

        return preg_match('/^(?:OC-?)?0*(\d{1,9})$/i', $folio, $coincidencia) === 1 ? (int) $coincidencia[1] : null;
    }

    /**
     * Atajo activo: ninguno si hay un rango personalizado (las fechas mandan),
     * el pedido, o "Este mes" si no hay nada.
     */
    public function periodo(): ?string
    {
        if ($this->fecha('fecha_desde') || $this->fecha('fecha_hasta')) {
            return null;
        }

        $periodo = $this->string('periodo')->toString();

        return array_key_exists($periodo, self::PERIODOS) ? $periodo : self::PERIODO_DEFECTO;
    }

    public function fechaDesde(): ?CarbonImmutable
    {
        return $this->periodo() === null ? $this->fecha('fecha_desde') : null;
    }

    public function fechaHasta(): ?CarbonImmutable
    {
        return $this->periodo() === null ? $this->fecha('fecha_hasta') : null;
    }

    /**
     * Inicio y fin (inclusive) del rango, en la zona del negocio.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function rango(): array
    {
        $hoy = CarbonImmutable::now(config('app.zona_negocio'));

        return match ($this->periodo()) {
            'hoy' => [$hoy->startOfDay(), $hoy->endOfDay()],
            'semana' => [$hoy->startOfWeek(), $hoy->endOfWeek()],
            'mes' => [$hoy->startOfMonth(), $hoy->endOfMonth()],
            default => [$this->fechaDesde()?->startOfDay(), $this->fechaHasta()?->endOfDay()],
        };
    }

    /**
     * Si hay algún filtro de columna (para el mensaje de "sin resultados").
     */
    public function hayFiltros(): bool
    {
        return array_filter($this->campos()) !== [];
    }

    private function fecha(string $campo): ?CarbonImmutable
    {
        $valor = $this->string($campo)->trim()->toString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        try {
            $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $valor, config('app.zona_negocio'));
        } catch (Throwable) {
            return null;
        }

        return $fecha && $fecha->toDateString() === $valor ? $fecha : null;
    }
}
