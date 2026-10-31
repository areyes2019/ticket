<?php

namespace App\Http\Requests;

use App\Enums\EstadoFactura;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros de la bandeja de facturas, compartidos por la página y la
 * búsqueda dinámica: carpeta (periodo), etiqueta (estado), texto de búsqueda
 * y factura abierta. Las carpetas son las de la bandeja de cotizaciones.
 *
 * No rechaza nada: un valor inválido se ignora.
 */
class ListadoFacturasRequest extends FormRequest
{
    public const PERIODOS = ListadoCotizacionesRequest::PERIODOS;

    public const PERIODO_DEFECTO = ListadoCotizacionesRequest::PERIODO_DEFECTO;

    public const POR_PAGINA = 25;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
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
     * Etiqueta activa (un estado de la factura), o '' si no hay.
     */
    public function estado(): string
    {
        $estado = $this->string('estado')->toString();

        return EstadoFactura::tryFrom($estado) ? $estado : '';
    }

    /**
     * Texto del buscador tal como se escribió (para volver a pintarlo).
     */
    public function texto(): string
    {
        return $this->string('q')->trim()->toString();
    }

    /**
     * Factura pedida en la URL (?factura=15), si el valor es un número.
     */
    public function abierta(): ?int
    {
        $id = $this->string('factura')->toString();

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Inicio y fin (inclusive) del periodo activo, en la zona del negocio.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function rango(): array
    {
        return ListadoCotizacionesRequest::rango($this->periodo());
    }

    /**
     * Parámetros no vacíos, para armar enlaces. Sin la factura abierta:
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
