<?php

namespace App\Http\Requests;

use App\Enums\TipoPago;
use App\Http\Requests\Concerns\NormalizaTelefono;
use App\Http\Requests\Concerns\ValidaCuentas;
use App\Models\Cotizacion;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Un pago de la cotización. Si es el primero y con él nace la venta (029),
 * también el contacto que llevará la venta: el ticket, la etiqueta y el aviso
 * de "listo" necesitan nombre y teléfono, y el cliente puede no tener
 * teléfono. Se guarda en la venta, no en el catálogo de Clientes.
 */
class CotizacionPagoRequest extends FormRequest
{
    use NormalizaTelefono, ValidaCuentas;

    /**
     * Bolsa propia: el detalle reabre el diálogo del pago con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'pago';

    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('cotizacion'));
    }

    protected function prepareForValidation(): void
    {
        if (! $this->creaVenta()) {
            return;
        }

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
        $reglas = [
            'tipo' => ['required', Rule::enum(TipoPago::class)],
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.zona_negocio'))->toDateString()],
            'cuenta_id' => ['required', 'integer', $this->cuentaDelUsuario()],
            'monto' => ['exclude_unless:tipo,anticipo', 'required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];

        if ($this->creaVenta()) {
            $reglas['cliente_nombre'] = ['required', 'string', 'max:150'];
            $reglas['cliente_telefono'] = ['required', 'string', 'regex:/^\+52\d{10}$/'];
            $reglas['cliente_correo'] = ['nullable', 'string', 'email', 'max:255'];
        }

        return $reglas;
    }

    /**
     * El pago que llega es el primero y crea la venta (029). El controlador
     * lo vuelve a decidir con la cotización bloqueada.
     */
    public function creaVenta(): bool
    {
        return (bool) $this->cotizacion()->destinoAlCobrar()?->creaVenta();
    }

    /**
     * El contacto de la venta; vacío si el pago no la crea.
     *
     * @return array{cliente_nombre?: string, cliente_telefono?: string, cliente_correo?: string|null}
     */
    public function datosVenta(): array
    {
        return $this->safe()->only(['cliente_nombre', 'cliente_telefono', 'cliente_correo']);
    }

    private function cotizacion(): Cotizacion
    {
        /** @var Cotizacion $cotizacion */
        $cotizacion = $this->route('cotizacion');

        return $cotizacion;
    }

    /**
     * Reglas de negocio del pago (un solo anticipo, sin sobrepago, solo en
     * enviada). El controlador las vuelve a revisar con la fila bloqueada.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->exigirCuentaActiva($validator, 'cuenta_id');

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Cotizacion $cotizacion */
                $cotizacion = $this->route('cotizacion');
                $motivo = $cotizacion->motivoRechazoPago(TipoPago::from($this->input('tipo')), $this->input('monto'));

                if ($motivo !== null) {
                    $validator->errors()->add('monto', $motivo);
                }
            },
        ];
    }

    public function tipo(): TipoPago
    {
        return TipoPago::from($this->validated('tipo'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_pago.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
            'cuenta_id.exists' => 'Selecciona una de tus cuentas.',
            'cliente_telefono.regex' => 'El teléfono debe tener 10 dígitos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo de pago',
            'fecha_pago' => 'fecha de pago',
            'cuenta_id' => 'cuenta',
            'monto' => 'monto',
            'cliente_nombre' => 'nombre en la venta',
            'cliente_telefono' => 'teléfono',
            'cliente_correo' => 'correo',
        ];
    }
}
