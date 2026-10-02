<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaTelefono;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Aceptar una cotización (021): el contacto que llevará la venta. El ticket,
 * la etiqueta y el aviso de "listo" necesitan nombre y teléfono, y el cliente
 * de la cotización puede no tener teléfono. Se guarda en la venta, no en el
 * catálogo de Clientes. Si la cotización se puede aceptar lo revisa
 * AceptadorCotizacion con la fila bloqueada.
 */
class AceptarCotizacionRequest extends FormRequest
{
    use NormalizaTelefono;

    /**
     * Bolsa propia: el detalle y la bandeja reabren la ventana con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'aceptar';

    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('cotizacion'));
    }

    protected function prepareForValidation(): void
    {
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
        return [
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['required', 'string', 'regex:/^\+52\d{10}$/'],
            'cliente_correo' => ['nullable', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array{cliente_nombre: string, cliente_telefono: string, cliente_correo: string|null}
     */
    public function datosCliente(): array
    {
        return $this->safe()->only(['cliente_nombre', 'cliente_telefono', 'cliente_correo']);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_telefono.regex' => 'El teléfono debe tener 10 dígitos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cliente_nombre' => 'nombre del cliente',
            'cliente_telefono' => 'teléfono',
            'cliente_correo' => 'correo',
        ];
    }
}
