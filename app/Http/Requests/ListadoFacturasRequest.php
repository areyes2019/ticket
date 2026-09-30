<?php

namespace App\Http\Requests;

use App\Enums\EstadoFactura;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros del listado de facturas, compartidos por el listado y la
 * búsqueda dinámica. No rechaza nada: un valor inválido se ignora.
 */
class ListadoFacturasRequest extends FormRequest
{
    public const POR_PAGINA = 25;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Filtros tal como se escribieron (para volver a pintarlos).
     *
     * @return array{cliente: string, rfc: string, folio: string, uuid: string, estado: string}
     */
    public function campos(): array
    {
        $estado = $this->string('estado')->toString();

        return [
            'cliente' => $this->string('cliente')->trim()->toString(),
            'rfc' => $this->string('rfc')->trim()->upper()->replaceMatches('/\s+/', '')->toString(),
            'folio' => $this->string('folio')->trim()->toString(),
            'uuid' => $this->string('uuid')->trim()->toString(),
            'estado' => EstadoFactura::tryFrom($estado) ? $estado : '',
        ];
    }

    /**
     * Filtros listos para Factura::filtrar().
     *
     * @return array<string, mixed>
     */
    public function filtros(): array
    {
        return [...$this->campos(), 'folio' => $this->folio()];
    }

    /**
     * "12" o "FAC-0012" buscan el folio interno 12 y también el folio fiscal
     * 12; "A12" busca el folio fiscal 12 de la serie A. Otro texto no filtra.
     *
     * @return array{interno: int|null, serie: string|null, fiscal: int|null}|null
     */
    public function folio(): ?array
    {
        $folio = $this->campos()['folio'];

        if (preg_match('/^FAC-?0*(\d{1,9})$/i', $folio, $coincidencia) === 1) {
            return ['interno' => (int) $coincidencia[1], 'serie' => null, 'fiscal' => null];
        }

        if (preg_match('/^0*(\d{1,9})$/', $folio, $coincidencia) === 1) {
            return ['interno' => (int) $coincidencia[1], 'serie' => null, 'fiscal' => (int) $coincidencia[1]];
        }

        if (preg_match('/^([A-Z]{1,25})-?0*(\d{1,9})$/i', $folio, $coincidencia) === 1) {
            return ['interno' => null, 'serie' => mb_strtoupper($coincidencia[1]), 'fiscal' => (int) $coincidencia[2]];
        }

        return null;
    }

    /**
     * Parámetros no vacíos, para armar enlaces.
     *
     * @return array<string, string>
     */
    public function parametros(): array
    {
        return array_filter($this->campos(), fn (string $valor) => $valor !== '');
    }
}
