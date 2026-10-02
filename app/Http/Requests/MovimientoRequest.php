<?php

namespace App\Http\Requests;

use App\Enums\TipoMovimiento;
use App\Http\Requests\Concerns\ValidaCuentas;
use App\Models\Movimiento;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ingreso, egreso o ajuste manual, en el alta (diálogo del listado) y en la
 * edición (página propia). El saldo no negativo no se revisa aquí: lo revisa
 * RegistradorMovimientos con la cuenta bloqueada.
 */
class MovimientoRequest extends FormRequest
{
    use ValidaCuentas;

    public function authorize(): bool|Response
    {
        $movimiento = $this->movimiento();

        return $movimiento === null ? true : Gate::inspect('update', $movimiento);
    }

    /**
     * En el alta la bolsa de errores es el tipo, para reabrir su diálogo.
     */
    protected function prepareForValidation(): void
    {
        if ($this->movimiento() === null) {
            $tipo = TipoMovimiento::tryFrom((string) $this->input('tipo'));
            $this->errorBag = in_array($tipo, TipoMovimiento::manuales(), true) ? $tipo->value : 'default';
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $reglas = [
            'cuenta_id' => ['required', 'integer', $this->cuentaDelUsuario()],
            'monto' => [
                'required',
                'numeric',
                'decimal:0,2',
                'between:-'.$this->montoMaximo().','.$this->montoMaximo(),
                function (string $atributo, mixed $valor, Closure $fallar) {
                    $centavos = CalculadoraTotalesDocumento::centavos($valor);
                    $tipo = $this->tipo();

                    if ($tipo === TipoMovimiento::Ajuste && $centavos === 0) {
                        $fallar('El ajuste no puede ser de 0.');
                    } elseif ($tipo !== null && $tipo !== TipoMovimiento::Ajuste && $centavos <= 0) {
                        $fallar('El monto debe ser mayor a 0.');
                    }
                },
            ],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.zona_negocio'))->toDateString()],
            'concepto' => ['required', 'string', 'max:255'],
        ];

        if ($this->movimiento() === null) {
            $reglas['tipo'] = ['required', Rule::in(array_map(fn (TipoMovimiento $tipo) => $tipo->value, TipoMovimiento::manuales()))];
        }

        return $reglas;
    }

    /**
     * En la edición, la cuenta actual se acepta aunque ya esté inactiva.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->exigirCuentaActiva($validator, 'cuenta_id', $this->movimiento()?->cuenta_id),
        ];
    }

    public function tipo(): ?TipoMovimiento
    {
        return $this->movimiento()?->tipo ?? TipoMovimiento::tryFrom((string) $this->input('tipo'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.in' => 'Elige ingreso, egreso o ajuste.',
            'cuenta_id.exists' => 'Selecciona una de tus cuentas.',
            'monto.between' => 'El monto es demasiado grande.',
            'monto.decimal' => 'El monto admite como máximo 2 decimales.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo',
            'cuenta_id' => 'cuenta',
            'monto' => 'monto',
            'fecha' => 'fecha',
            'concepto' => $this->tipo() === TipoMovimiento::Ajuste ? 'motivo' : 'concepto',
        ];
    }

    private function movimiento(): ?Movimiento
    {
        return $this->route('movimiento');
    }
}
