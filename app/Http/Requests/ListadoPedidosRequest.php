<?php

namespace App\Http\Requests;

use App\Enums\EstadoPedido;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros por columna del listado de pedidos, compartidos por la página y la
 * búsqueda dinámica. No rechaza nada: un valor inválido se ignora. Los
 * periodos son días calendario completos en la zona del negocio.
 */
class ListadoPedidosRequest extends FormRequest
{
    public const POR_PAGINA = 25;

    public const PERIODO_DEFECTO = 'mes';

    /**
     * De dónde salió la venta (021): del mostrador o de una cotización
     * aceptada.
     */
    public const ORIGENES = ['mostrador' => 'Mostrador', 'cotizacion' => 'Cotización'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Valores tal como se escribieron, para volver a pintarlos.
     *
     * @return array{folio: string, cliente: string, telefono: string, estado: string, origen: string, periodo: string}
     */
    public function valores(): array
    {
        $estado = $this->string('estado')->toString();
        $periodo = $this->string('periodo')->toString();
        $origen = $this->string('origen')->toString();

        return [
            'folio' => $this->string('folio')->trim()->toString(),
            'cliente' => $this->string('cliente')->squish()->toString(),
            'telefono' => $this->string('telefono')->trim()->toString(),
            'estado' => EstadoPedido::tryFrom($estado) === null ? '' : $estado,
            'origen' => array_key_exists($origen, self::ORIGENES) ? $origen : '',
            'periodo' => array_key_exists($periodo, ListadoCotizacionesRequest::PERIODOS) ? $periodo : self::PERIODO_DEFECTO,
        ];
    }

    /**
     * Filtros listos para Pedido::filtrar().
     *
     * @return array<string, mixed>
     */
    public function filtros(): array
    {
        $valores = $this->valores();
        [$desde, $hasta] = ListadoCotizacionesRequest::rango($valores['periodo']);

        return [
            'folio' => $this->folio($valores['folio']),
            'cliente' => $valores['cliente'],
            'telefono' => (string) preg_replace('/\D/', '', $valores['telefono']),
            'estado' => $valores['estado'],
            'origen' => $valores['origen'],
            'desde' => $desde,
            'hasta' => $hasta,
        ];
    }

    /**
     * "42", "0042" o "PED-0042" → 42. Otro texto no filtra.
     */
    private function folio(string $texto): ?int
    {
        return preg_match('/^(?:PED-?)?0*(\d{1,9})$/i', $texto, $coincidencia) === 1 ? (int) $coincidencia[1] : null;
    }
}
