<?php

namespace App\Http\Requests;

use App\Models\Articulo;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros del listado de artículos (filtros, orden y filas por página),
 * compartidos por el listado, la búsqueda dinámica y la exportación.
 *
 * No rechaza nada: un valor fuera de la lista blanca cae al valor por defecto.
 */
class ListadoArticulosRequest extends FormRequest
{
    public const ORDEN_DEFECTO = 'nombre';

    public const POR_PAGINA_DEFECTO = 25;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function filtros(): array
    {
        $filtros = [];

        foreach (Articulo::FILTROS as $filtro) {
            $filtros[$filtro] = $this->string($filtro)->trim()->toString();
        }

        return $filtros;
    }

    public function orden(): string
    {
        $orden = $this->string('orden')->toString();

        return in_array($orden, Articulo::ORDENES, true) ? $orden : self::ORDEN_DEFECTO;
    }

    public function direccion(): string
    {
        return $this->string('direccion')->toString() === 'desc' ? 'desc' : 'asc';
    }

    public function porPagina(): int
    {
        $porPagina = $this->integer('por_pagina');

        return in_array($porPagina, Articulo::POR_PAGINA, true) ? $porPagina : self::POR_PAGINA_DEFECTO;
    }

    /**
     * Filtros, orden y filas por página, sin los valores vacíos ni los que
     * coinciden con el valor por defecto; sirve para armar enlaces.
     *
     * @return array<string, string|int>
     */
    public function parametros(): array
    {
        return array_filter([
            ...$this->filtros(),
            'orden' => $this->orden() === self::ORDEN_DEFECTO ? '' : $this->orden(),
            'direccion' => $this->direccion() === 'asc' ? '' : $this->direccion(),
            'por_pagina' => $this->porPagina() === self::POR_PAGINA_DEFECTO ? '' : $this->porPagina(),
        ], fn (string|int $valor) => $valor !== '');
    }
}
