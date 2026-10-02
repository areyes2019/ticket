<?php

namespace App\Http\Requests;

use App\Models\Articulo;
use App\Models\OrdenCompra;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una orden de compra. Las líneas y los descuentos siguen
 * las mismas reglas que la cotización (topes incluidos, líneas libres
 * permitidas), con una más: cada artículo debe ser de un catálogo del
 * proveedor de la orden. Totales, folio, estado y pago los pone el servidor
 * aunque lleguen en la petición.
 */
class OrdenCompraRequest extends CotizacionRequest
{
    public const MAX_OBSERVACIONES = 2000;

    /**
     * OrdenCompraPolicy se revisa antes de validar para que una orden ajena
     * responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $orden = $this->route('ordenCompra');

        return $orden === null ? true : Gate::inspect('update', $orden);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $reglas = parent::rules();
        unset($reglas['cliente_id']);

        return [
            'proveedor_id' => ['required', 'integer', $this->reglaProveedor()],
            ...$reglas,
            'lineas.*.articulo_id' => [...$reglas['lineas.*.articulo_id'], $this->reglaArticuloDelProveedor()],
            'fecha_entrega_esperada' => ['nullable', 'date_format:Y-m-d'],
            'observaciones' => ['nullable', 'string', 'max:'.self::MAX_OBSERVACIONES],
        ];
    }

    /**
     * Proveedor, descuento global, fecha esperada y observaciones validados.
     *
     * @return array<string, mixed>
     */
    public function datosOrden(): array
    {
        return $this->safe()->only(['proveedor_id', 'descuento_global_tipo', 'descuento_global_valor', 'fecha_entrega_esperada', 'observaciones']);
    }

    protected function documento(): ?OrdenCompra
    {
        $orden = $this->route('ordenCompra');

        return $orden instanceof OrdenCompra ? $orden : null;
    }

    /**
     * Proveedor del usuario y no eliminado; en la edición se acepta el que ya
     * tenía la orden aunque se haya eliminado después.
     */
    private function reglaProveedor(): object
    {
        $actual = $this->documento()?->proveedor_id;

        return Rule::exists('proveedores', 'id')
            ->where('user_id', $this->user()->id)
            ->where(fn ($consulta) => $consulta->whereNull('deleted_at')->orWhere('id', $actual));
    }

    /**
     * El artículo debe venir de un catálogo del proveedor de la orden. Los ya
     * guardados en esta orden se aceptan aunque después hayan cambiado de
     * catálogo: la línea es una copia.
     */
    private function reglaArticuloDelProveedor(): Closure
    {
        $yaGuardados = $this->documento()?->lineas()->whereNotNull('articulo_id')->pluck('articulo_id')->all() ?? [];

        return function (string $atributo, mixed $valor, Closure $fallar) use ($yaGuardados) {
            if (in_array((int) $valor, $yaGuardados, true)) {
                return;
            }

            $proveedor = Articulo::withTrashed()->whereKey($valor)->value('proveedor_id');

            if ($proveedor !== null && (int) $proveedor !== (int) $this->input('proveedor_id')) {
                $fallar('El artículo de la línea '.((int) explode('.', $atributo)[1] + 1).' no pertenece a un catálogo de este proveedor.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'proveedor_id.exists' => 'Selecciona uno de tus proveedores.',
            'lineas.max' => 'Una orden de compra admite como máximo '.self::MAX_LINEAS.' líneas.',
            'fecha_entrega_esperada.date_format' => 'La fecha de entrega esperada no es una fecha válida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $atributos = parent::attributes();
        unset($atributos['cliente_id']);

        return [
            ...$atributos,
            'proveedor_id' => 'proveedor',
            'lineas.*.precio_unitario' => 'costo de la línea :position',
            'fecha_entrega_esperada' => 'fecha de entrega esperada',
            'observaciones' => 'observaciones',
        ];
    }
}
