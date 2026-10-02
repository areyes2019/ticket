<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaTelefono;
use App\Models\Pedido;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de un pedido de mostrador. Las líneas y los descuentos siguen
 * las mismas reglas que la cotización (líneas libres incluidas); en lugar de
 * un cliente del catálogo llegan nombre, teléfono y correo. Totales, costo,
 * folio y estado los pone el servidor aunque lleguen en la petición.
 *
 * La existencia en bodega no se valida aquí sino en la transacción del
 * controlador: en la edición depende de lo que el propio pedido devuelve.
 */
class PedidoRequest extends CotizacionRequest
{
    use NormalizaTelefono;

    public function authorize(): bool|Response
    {
        $pedido = $this->route('pedido');

        return $pedido === null ? true : Gate::inspect('update', $pedido);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([
            'cliente_nombre' => $this->string('cliente_nombre')->squish()->toString(),
            'cliente_telefono' => $this->filled('cliente_telefono') ? $this->normalizarTelefono($this->string('cliente_telefono')->toString()) : null,
            'cliente_correo' => $this->filled('cliente_correo') ? $this->string('cliente_correo')->trim()->toString() : null,
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $reglas = parent::rules();
        unset($reglas['cliente_id']);

        return [
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['required', 'string', 'regex:/^\+52\d{10}$/'],
            'cliente_correo' => ['nullable', 'string', 'email', 'max:255'],
            ...$reglas,
        ];
    }

    /**
     * Las de la cotización (descuento global) más: con pagos, el total no puede
     * quedar por debajo de lo pagado.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                $pedido = $this->route('pedido');

                if ($validator->errors()->isNotEmpty() || ! $pedido instanceof Pedido) {
                    return;
                }

                if (CalculadoraTotalesDocumento::centavos($this->totales()['total']) < CalculadoraTotalesDocumento::centavos($pedido->totalPagado())) {
                    $validator->errors()->add('lineas', 'El total no puede ser menor a lo ya pagado ($'.number_format((float) $pedido->totalPagado(), 2).').');
                }
            },
        ];
    }

    /**
     * Cliente de mostrador y descuento global validados.
     *
     * @return array<string, mixed>
     */
    public function datosPedido(): array
    {
        return $this->safe()->only(['cliente_nombre', 'cliente_telefono', 'cliente_correo', 'descuento_global_tipo', 'descuento_global_valor']);
    }

    protected function documento(): ?Pedido
    {
        $pedido = $this->route('pedido');

        return $pedido instanceof Pedido ? $pedido : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'cliente_telefono.regex' => 'El teléfono debe tener 10 dígitos.',
            'lineas.max' => 'Un pedido admite como máximo '.self::MAX_LINEAS.' líneas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'cliente_nombre' => 'nombre del cliente',
            'cliente_telefono' => 'teléfono',
            'cliente_correo' => 'correo',
        ];
    }
}
