<?php

namespace App\Http\Requests;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Models\Cotizacion;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de una cotización. Solo se toman el cliente, el descuento
 * global y las líneas: totales, costo, folio y estado los pone el servidor
 * aunque lleguen en la petición.
 */
class CotizacionRequest extends FormRequest
{
    public const MAX_LINEAS = 100;

    public const MAX_CANTIDAD = 9999;

    public const MAX_PRECIO = 999999.99;

    /**
     * Campos de una línea que llegan del formulario.
     */
    private const CAMPOS_LINEA = ['articulo_id', 'cantidad', 'descripcion', 'modelo', 'precio_unitario', 'descuento_tipo', 'descuento_valor', 'tasa_iva'];

    /**
     * En la edición, CotizacionPolicy se revisa antes de validar para que una
     * cotización ajena responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $cotizacion = $this->route('cotizacion');

        return $cotizacion === null ? true : Gate::inspect('update', $cotizacion);
    }

    /**
     * Quita las filas totalmente vacías (el formulario sin JavaScript trae
     * filas de sobra) y convierte los descuentos vacíos en null.
     */
    protected function prepareForValidation(): void
    {
        $lineas = $this->input('lineas');

        if (! is_array($lineas)) {
            return;
        }

        $limpias = [];

        foreach ($lineas as $linea) {
            if (! is_array($linea)) {
                continue;
            }

            $linea = array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, array_intersect_key($linea, array_flip(self::CAMPOS_LINEA)));

            if (blank($linea['articulo_id'] ?? null) && blank($linea['descripcion'] ?? null) && blank($linea['precio_unitario'] ?? null)) {
                continue;
            }

            foreach (['articulo_id', 'modelo', 'descuento_tipo', 'descuento_valor'] as $campo) {
                if (blank($linea[$campo] ?? null)) {
                    $linea[$campo] = null;
                }
            }

            if ($linea['descuento_tipo'] === null) {
                $linea['descuento_valor'] = null;
            }

            $limpias[] = $linea;
        }

        $this->merge([
            'lineas' => $limpias,
            'descuento_global_tipo' => $this->filled('descuento_global_tipo') ? $this->input('descuento_global_tipo') : null,
            'descuento_global_valor' => $this->filled('descuento_global_tipo') && $this->filled('descuento_global_valor') ? $this->input('descuento_global_valor') : null,
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $usuarioId = $this->user()->id;

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('user_id', $usuarioId)->withoutTrashed()],
            'lineas' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINEAS],
            'lineas.*' => ['array'],
            'lineas.*.articulo_id' => ['nullable', 'integer', 'distinct', $this->reglaArticulo($usuarioId)],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CANTIDAD],
            'lineas.*.descripcion' => ['required', 'string', 'max:255'],
            'lineas.*.modelo' => ['nullable', 'required_with:lineas.*.articulo_id', 'string', 'max:255'],
            'lineas.*.precio_unitario' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.self::MAX_PRECIO],
            'lineas.*.descuento_tipo' => ['nullable', Rule::enum(TipoDescuento::class)],
            'lineas.*.descuento_valor' => ['nullable', 'required_with:lineas.*.descuento_tipo', 'numeric', 'gte:0', 'decimal:0,2', $this->reglaDescuentoLinea()],
            'lineas.*.tasa_iva' => ['required', Rule::enum(TasaIva::class)],
            'descuento_global_tipo' => ['nullable', Rule::enum(TipoDescuento::class)],
            'descuento_global_valor' => ['nullable', 'required_with:descuento_global_tipo', 'numeric', 'gte:0', 'decimal:0,2'],
        ];
    }

    /**
     * Reglas que necesitan todas las líneas ya válidas: el descuento global no
     * puede pasar de la suma de las líneas y, con pagos, el total no puede
     * quedar por debajo de lo pagado.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $totales = $this->totales();

                if ($this->input('descuento_global_tipo') === TipoDescuento::Porcentaje->value && (float) $this->input('descuento_global_valor') > 100) {
                    $validator->errors()->add('descuento_global_valor', 'El descuento global no puede ser mayor a 100%.');
                }

                if ($this->input('descuento_global_tipo') === TipoDescuento::Monto->value) {
                    $sumaLineas = CalculadoraTotalesDocumento::centavos($totales['subtotal']) - $this->descuentosDeLinea($totales);

                    if (CalculadoraTotalesDocumento::centavos($this->input('descuento_global_valor')) > $sumaLineas) {
                        $validator->errors()->add('descuento_global_valor', 'El descuento global no puede ser mayor a la suma de las líneas ($'.number_format($sumaLineas / 100, 2).').');
                    }
                }

                $cotizacion = $this->route('cotizacion');

                if ($cotizacion instanceof Cotizacion && CalculadoraTotalesDocumento::centavos($totales['total']) < CalculadoraTotalesDocumento::centavos($cotizacion->totalPagado())) {
                    $validator->errors()->add('lineas', 'El total no puede ser menor a lo ya pagado ($'.number_format((float) $cotizacion->totalPagado(), 2).').');
                }
            },
        ];
    }

    /**
     * Cliente y descuento global validados.
     *
     * @return array{cliente_id: int, descuento_global_tipo: string|null, descuento_global_valor: string|null}
     */
    public function datosCotizacion(): array
    {
        return $this->safe()->only(['cliente_id', 'descuento_global_tipo', 'descuento_global_valor']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lineas(): array
    {
        return array_values($this->validated('lineas'));
    }

    /**
     * @return array<string, mixed>
     */
    public function totales(): array
    {
        return CalculadoraTotalesDocumento::calcular(
            array_values($this->input('lineas', [])),
            $this->input('descuento_global_tipo'),
            $this->input('descuento_global_valor'),
        );
    }

    /**
     * @param  array<string, mixed>  $totales
     */
    private function descuentosDeLinea(array $totales): int
    {
        return array_sum(array_map(fn (array $linea) => CalculadoraTotalesDocumento::centavos($linea['descuento']), $totales['lineas']));
    }

    /**
     * El artículo debe ser del usuario y no estar eliminado; en la edición se
     * acepta uno eliminado después de guardarse en esta cotización.
     */
    private function reglaArticulo(int $usuarioId): object
    {
        $cotizacion = $this->route('cotizacion');
        $yaGuardados = $cotizacion instanceof Cotizacion
            ? $cotizacion->lineas()->whereNotNull('articulo_id')->pluck('articulo_id')->all()
            : [];

        return Rule::exists('articulos', 'id')
            ->where('user_id', $usuarioId)
            ->where(fn ($consulta) => $consulta->whereNull('deleted_at')->orWhereIn('id', $yaGuardados));
    }

    /**
     * Porcentaje hasta 100; monto hasta el importe bruto de la línea.
     */
    private function reglaDescuentoLinea(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar) {
            $indice = (int) explode('.', $atributo)[1];
            $linea = $this->input("lineas.{$indice}");
            $tipo = $linea['descuento_tipo'] ?? null;

            if ($tipo === TipoDescuento::Porcentaje->value && (float) $valor > 100) {
                $fallar('El descuento de la línea '.($indice + 1).' no puede ser mayor a 100%.');
            }

            if ($tipo === TipoDescuento::Monto->value && is_numeric($linea['cantidad'] ?? null) && is_numeric($linea['precio_unitario'] ?? null)) {
                $bruto = (int) $linea['cantidad'] * CalculadoraTotalesDocumento::centavos($linea['precio_unitario']);

                if (CalculadoraTotalesDocumento::centavos($valor) > $bruto) {
                    $fallar('El descuento de la línea '.($indice + 1).' no puede ser mayor a su importe ($'.number_format($bruto / 100, 2).').');
                }
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_id.exists' => 'Selecciona uno de tus clientes.',
            'lineas.required' => 'Agrega al menos una línea.',
            'lineas.min' => 'Agrega al menos una línea.',
            'lineas.max' => 'Una cotización admite como máximo '.self::MAX_LINEAS.' líneas.',
            'lineas.*.articulo_id.distinct' => 'El artículo de la línea :position ya está en otra línea: suma las unidades en una sola.',
            'lineas.*.articulo_id.exists' => 'El artículo de la línea :position no existe en tu catálogo.',
            'lineas.*.precio_unitario.gt' => 'El precio de la línea :position debe ser mayor a 0.',
            'lineas.*.precio_unitario.decimal' => 'El precio de la línea :position admite como máximo 2 decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cliente_id' => 'cliente',
            'lineas' => 'líneas',
            'lineas.*.articulo_id' => 'artículo de la línea :position',
            'lineas.*.cantidad' => 'cantidad de la línea :position',
            'lineas.*.descripcion' => 'descripción de la línea :position',
            'lineas.*.modelo' => 'modelo de la línea :position',
            'lineas.*.precio_unitario' => 'precio de la línea :position',
            'lineas.*.descuento_tipo' => 'tipo de descuento de la línea :position',
            'lineas.*.descuento_valor' => 'descuento de la línea :position',
            'lineas.*.tasa_iva' => 'IVA de la línea :position',
            'descuento_global_tipo' => 'tipo de descuento global',
            'descuento_global_valor' => 'descuento global',
        ];
    }
}
