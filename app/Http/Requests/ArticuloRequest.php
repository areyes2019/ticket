<?php

namespace App\Http\Requests;

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ArticuloRequest extends FormRequest
{
    /**
     * En la edición, ArticuloPolicy se revisa antes de validar para que un
     * artículo ajeno responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $articulo = $this->route('articulo');

        return $articulo === null ? true : Gate::inspect('update', $articulo);
    }

    /**
     * Normaliza la clave de unidad antes de validar.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('clave_unidad')) {
            $this->merge(['clave_unidad' => $this->string('clave_unidad')->replaceMatches('/\s+/', '')->upper()->toString()]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return self::reglas($this->user()->id, $this->input('proveedor_id'), $this->route('articulo'));
    }

    /**
     * Reglas de un artículo. Las usan este Form Request y la importación CSV,
     * para que el alta individual y la masiva no se desincronicen.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public static function reglas(int $usuarioId, mixed $proveedorId, ?Articulo $ignorar = null): array
    {
        return [
            'proveedor_id' => ['required', 'integer', self::reglaProveedor($usuarioId)],
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('articulos', 'nombre')
                    ->where('proveedor_id', $proveedorId)
                    ->withoutTrashed()
                    ->ignore($ignorar),
            ],
            'modelo' => ['required', 'string', 'max:255'],
            'clave_prod_serv' => ['required', 'string', 'exists:sat_claves_prod_serv,clave'],
            'clave_unidad' => ['required', 'string', 'exists:sat_claves_unidad,clave'],
            'objeto_imp' => ['required', Rule::enum(ObjetoImpuesto::class)],
            'precio_unitario_sin_iva' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
        ];
    }

    /**
     * El proveedor debe ser del usuario y no estar eliminado.
     */
    public static function reglaProveedor(int $usuarioId): object
    {
        return Rule::exists('proveedores', 'id')->where('user_id', $usuarioId)->withoutTrashed();
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'proveedor_id.exists' => 'Selecciona uno de tus proveedores.',
            'nombre.unique' => 'Nombre duplicado: este proveedor ya tiene un artículo con ese nombre.',
            'clave_prod_serv.exists' => 'La clave de producto/servicio no existe en el catálogo del SAT.',
            'clave_unidad.exists' => 'La clave de unidad no existe en el catálogo del SAT.',
            'objeto_imp.enum' => 'Selecciona un objeto de impuesto del catálogo del SAT.',
            'precio_unitario_sin_iva.gt' => 'El precio unitario sin IVA debe ser mayor a 0.',
            'precio_unitario_sin_iva.decimal' => 'El precio unitario sin IVA admite como máximo 2 decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajes();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'proveedor_id' => 'proveedor',
            'nombre' => 'nombre',
            'modelo' => 'modelo',
            'clave_prod_serv' => 'clave de producto/servicio',
            'clave_unidad' => 'clave de unidad',
            'objeto_imp' => 'objeto de impuesto',
            'precio_unitario_sin_iva' => 'precio unitario sin IVA',
        ];
    }
}
