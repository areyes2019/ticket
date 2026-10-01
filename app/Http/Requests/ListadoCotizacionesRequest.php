<?php

namespace App\Http\Requests;

use App\Enums\EstadoCotizacion;
use App\Models\Cotizacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros de la bandeja de cotizaciones, compartidos por la página y la
 * búsqueda dinámica: carpeta (periodo), etiqueta (estado, "por caducar",
 * "facturadas" o "por facturar"),
 * texto de búsqueda y cotización abierta.
 *
 * No rechaza nada: un valor inválido se ignora. Los periodos son días
 * calendario completos en la zona del negocio.
 */
class ListadoCotizacionesRequest extends FormRequest
{
    public const PERIODOS = ['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes', 'todas' => 'Todas'];

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
     * Etiquetas de la bandeja: los estados, "Por caducar", "Facturadas" y
     * "Por facturar".
     *
     * @return array<string, string>
     */
    public static function etiquetas(): array
    {
        return [
            ...EstadoCotizacion::opciones(),
            Cotizacion::POR_CADUCAR => 'Por caducar',
            Cotizacion::FACTURADAS => 'Facturadas',
            Cotizacion::POR_FACTURAR => 'Por facturar',
        ];
    }

    /**
     * Carpeta activa; "Este mes" si no hay una válida.
     */
    public function periodo(): string
    {
        $periodo = $this->string('periodo')->toString();

        return array_key_exists($periodo, self::PERIODOS) ? $periodo : self::PERIODO_DEFECTO;
    }

    /**
     * Etiqueta activa, o '' si no hay.
     */
    public function estado(): string
    {
        $estado = $this->string('estado')->toString();

        return array_key_exists($estado, self::etiquetas()) ? $estado : '';
    }

    /**
     * Texto del buscador tal como se escribió (para volver a pintarlo).
     */
    public function texto(): string
    {
        return $this->string('q')->trim()->toString();
    }

    /**
     * Cotización pedida en la URL (?cotizacion=15), si el valor es un número.
     */
    public function abierta(): ?int
    {
        $id = $this->string('cotizacion')->toString();

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Filtros listos para Cotizacion::filtrar().
     *
     * @return array<string, mixed>
     */
    public function filtros(): array
    {
        [$desde, $hasta] = self::rango($this->periodo());

        return [
            'texto' => $this->texto(),
            'folio' => $this->folio(),
            'estado' => $this->estado(),
            'desde' => $desde,
            'hasta' => $hasta,
        ];
    }

    /**
     * "12", "0012" o "COT-0012" → 12; otro texto no es un folio.
     */
    public function folio(): ?int
    {
        return preg_match('/^(?:COT-?)?0*(\d{1,9})$/i', $this->texto(), $coincidencia) === 1 ? (int) $coincidencia[1] : null;
    }

    /**
     * Inicio y fin (inclusive) de un periodo, en la zona del negocio. "Todas"
     * no tiene límite.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public static function rango(string $periodo): array
    {
        $hoy = CarbonImmutable::now(config('app.zona_negocio'));

        return match ($periodo) {
            'hoy' => [$hoy->startOfDay(), $hoy->endOfDay()],
            'semana' => [$hoy->startOfWeek(), $hoy->endOfWeek()],
            'mes' => [$hoy->startOfMonth(), $hoy->endOfMonth()],
            default => [null, null],
        };
    }

    /**
     * Parámetros no vacíos, para armar enlaces. Sin la cotización abierta:
     * cambiar de carpeta o etiqueta abre la primera de la lista nueva.
     *
     * @return array<string, string>
     */
    public function parametros(): array
    {
        return array_filter([
            'q' => $this->texto(),
            'estado' => $this->estado(),
            'periodo' => $this->periodo() === self::PERIODO_DEFECTO ? '' : $this->periodo(),
        ], fn (string $valor) => $valor !== '');
    }
}
