<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros del listado de existencias (filtros y orden), compartidos por el
 * listado y la búsqueda dinámica.
 *
 * No rechaza nada: un valor fuera de la lista blanca cae al valor por defecto.
 */
class ListadoExistenciasRequest extends FormRequest
{
    public const ORDENES = ['modelo', 'catalogo', 'existencia', 'faltante', 'minimo', 'invertido', 'beneficio'];

    public const ORDEN_DEFECTO = 'modelo';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{q: string, catalogo: int|null, proveedor: int|null, por_pedir: bool}
     */
    public function filtros(): array
    {
        return [
            'q' => $this->string('q')->trim()->toString(),
            'catalogo' => $this->entero('catalogo'),
            'proveedor' => $this->entero('proveedor'),
            'por_pedir' => $this->string('por_pedir')->toString() === '1',
        ];
    }

    public function orden(): string
    {
        $orden = $this->string('orden')->toString();

        return in_array($orden, self::ORDENES, true) ? $orden : self::ORDEN_DEFECTO;
    }

    public function direccion(): string
    {
        return $this->string('direccion')->toString() === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Filtros y orden sin los valores vacíos ni los que coinciden con el
     * valor por defecto; sirve para armar enlaces.
     *
     * @return array<string, string|int>
     */
    public function parametros(): array
    {
        $filtros = $this->filtros();

        return array_filter([
            'q' => $filtros['q'],
            'catalogo' => $filtros['catalogo'] ?? '',
            'proveedor' => $filtros['proveedor'] ?? '',
            'por_pedir' => $filtros['por_pedir'] ? '1' : '',
            'orden' => $this->orden() === self::ORDEN_DEFECTO ? '' : $this->orden(),
            'direccion' => $this->direccion() === 'asc' ? '' : $this->direccion(),
        ], fn (string|int $valor) => $valor !== '');
    }

    private function entero(string $clave): ?int
    {
        $valor = $this->string($clave)->toString();

        return ctype_digit($valor) && (int) $valor > 0 ? (int) $valor : null;
    }
}
