<?php

namespace App\Http\Requests;

use App\Models\Catalogo;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogoRequest extends FormRequest
{
    /**
     * En la edición, CatalogoPolicy se revisa antes de validar para que un
     * catálogo ajeno responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $catalogo = $this->catalogo();

        return $catalogo === null ? true : Gate::inspect('update', $catalogo);
    }

    /**
     * Un descuento o una utilidad vacíos son 0%.
     */
    protected function prepareForValidation(): void
    {
        foreach (['descuento', 'utilidad_porcentaje', 'utilidad_distribuidor_porcentaje'] as $campo) {
            if (! $this->filled($campo)) {
                $this->merge([$campo => 0]);
            }
        }
    }

    /**
     * El proveedor solo se acepta en el alta: en la edición no hay regla, así
     * que no llega a validated() y el proveedor del catálogo no cambia.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $catalogo = $this->catalogo();

        $reglas = [
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('catalogos', 'nombre')
                    ->where('proveedor_id', $catalogo?->proveedor_id ?? $this->input('proveedor_id'))
                    ->withoutTrashed()
                    ->ignore($catalogo),
            ],
            'descuento' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'utilidad_porcentaje' => ['required', 'numeric', 'between:0,999.99', 'decimal:0,2'],
            'utilidad_distribuidor_porcentaje' => ['required', 'numeric', 'between:0,999.99', 'decimal:0,2'],
        ];

        if ($catalogo === null) {
            $reglas['proveedor_id'] = [
                'required',
                'integer',
                Rule::exists('proveedores', 'id')->where('user_id', $this->user()->id)->withoutTrashed(),
            ];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proveedor_id.exists' => 'Selecciona uno de tus proveedores.',
            'nombre.unique' => 'Nombre duplicado: este proveedor ya tiene un catálogo con ese nombre.',
            'descuento.between' => 'El descuento debe estar entre 0 y 100.',
            'descuento.decimal' => 'El descuento admite como máximo 2 decimales.',
            'utilidad_porcentaje.between' => 'La utilidad debe estar entre 0 y 999.99%.',
            'utilidad_porcentaje.decimal' => 'La utilidad admite como máximo 2 decimales.',
            'utilidad_distribuidor_porcentaje.between' => 'La utilidad distribuidor debe estar entre 0 y 999.99%.',
            'utilidad_distribuidor_porcentaje.decimal' => 'La utilidad distribuidor admite como máximo 2 decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'proveedor_id' => 'proveedor',
            'nombre' => 'nombre',
            'descuento' => 'descuento',
            'utilidad_porcentaje' => 'utilidad',
            'utilidad_distribuidor_porcentaje' => 'utilidad distribuidor',
        ];
    }

    private function catalogo(): ?Catalogo
    {
        return $this->route('catalogo');
    }
}
