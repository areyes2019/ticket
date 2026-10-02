<?php

namespace App\Http\Requests;

use App\Enums\TipoMovimiento;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros del listado de movimientos. No rechaza nada: un valor inválido se
 * ignora.
 */
class ListadoMovimientosRequest extends FormRequest
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
     * Filtros válidos tal como se escribieron, para volver a pintarlos y para
     * Movimiento::filtrar(). Los vacíos o inválidos quedan en ''.
     *
     * @return array{fecha_desde: string, fecha_hasta: string, cuenta_id: string, tipo: string, concepto: string}
     */
    public function filtros(): array
    {
        $cuenta = $this->string('cuenta_id')->trim()->toString();
        $tipo = $this->string('tipo')->toString();

        return [
            'fecha_desde' => $this->fecha('fecha_desde'),
            'fecha_hasta' => $this->fecha('fecha_hasta'),
            'cuenta_id' => ctype_digit($cuenta) ? $cuenta : '',
            'tipo' => TipoMovimiento::tryFrom($tipo) ? $tipo : '',
            'concepto' => $this->string('concepto')->trim()->limit(100, '')->toString(),
        ];
    }

    /**
     * Una fecha AAAA-MM-DD que existe en el calendario, o ''.
     */
    private function fecha(string $campo): string
    {
        $valor = $this->string($campo)->trim()->toString();

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes) !== 1) {
            return '';
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $valor : '';
    }
}
