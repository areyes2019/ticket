<?php

namespace App\Http\Requests;

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;
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
        return self::reglas($this->user()->id, $this->input('catalogo_id'), $this->route('articulo'));
    }

    /**
     * Reglas de un artículo. Las usan este Form Request y la importación CSV,
     * para que el alta individual y la masiva no se desincronicen.
     *
     * El nombre es único por proveedor, tomado del catálogo: dos artículos del
     * mismo proveedor no lo comparten aunque estén en catálogos distintos.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public static function reglas(int $usuarioId, mixed $catalogoId, ?Articulo $ignorar = null): array
    {
        $proveedorId = is_numeric($catalogoId)
            ? Catalogo::where('user_id', $usuarioId)->whereKey((int) $catalogoId)->value('proveedor_id')
            : null;

        return [
            'catalogo_id' => ['required', 'integer', self::reglaCatalogo($usuarioId)],
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
            // El tope asegura que el precio de venta con 999.99% de utilidad
            // quepa en decimal(10,2).
            'precio_proveedor' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9000000'],
            'utilidad_porcentaje' => ['nullable', 'numeric', 'between:0,999.99', 'decimal:0,2'],
        ];
    }

    /**
     * El catálogo debe ser del usuario, y ni él ni su proveedor pueden estar
     * eliminados.
     */
    public static function reglaCatalogo(int $usuarioId): object
    {
        return Rule::exists('catalogos', 'id')
            ->where('user_id', $usuarioId)
            ->withoutTrashed()
            ->where(fn ($consulta) => $consulta->whereIn(
                'proveedor_id',
                fn ($proveedores) => $proveedores->select('id')->from('proveedores')->whereNull('deleted_at')
            ));
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'catalogo_id.exists' => 'Selecciona uno de tus catálogos.',
            'nombre.unique' => 'Nombre duplicado: este proveedor ya tiene un artículo con ese nombre.',
            'clave_prod_serv.exists' => 'La clave de producto/servicio no existe en el catálogo del SAT.',
            'clave_unidad.exists' => 'La clave de unidad no existe en el catálogo del SAT.',
            'objeto_imp.enum' => 'Selecciona un objeto de impuesto del catálogo del SAT.',
            'precio_proveedor.gt' => 'El precio del proveedor debe ser mayor a 0.',
            'precio_proveedor.decimal' => 'El precio del proveedor admite como máximo 2 decimales.',
            'precio_proveedor.max' => 'El precio del proveedor no puede ser mayor a 9,000,000.',
            'utilidad_porcentaje.between' => 'La utilidad debe estar entre 0 y 999.99%.',
            'utilidad_porcentaje.decimal' => 'La utilidad admite como máximo 2 decimales.',
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
            'catalogo_id' => 'catálogo',
            'nombre' => 'nombre',
            'modelo' => 'modelo',
            'clave_prod_serv' => 'clave de producto/servicio',
            'clave_unidad' => 'clave de unidad',
            'objeto_imp' => 'objeto de impuesto',
            'precio_proveedor' => 'precio del proveedor',
            'utilidad_porcentaje' => 'utilidad',
        ];
    }
}
