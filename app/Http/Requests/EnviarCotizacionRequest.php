<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class EnviarCotizacionRequest extends FormRequest
{
    public const MAX_DESTINATARIOS = 5;

    /**
     * Bolsa propia: el detalle reabre el diálogo de envío con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'envio';

    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('cotizacion'));
    }

    /**
     * El formulario manda un solo campo con los correos separados por comas
     * (o punto y coma); se conserva el texto para volver a pintarlo.
     */
    protected function prepareForValidation(): void
    {
        $texto = $this->string('destinatarios_texto')->toString();

        $this->merge([
            'destinatarios' => array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $texto) ?: []))),
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'destinatarios' => ['required', 'array', 'min:1', 'max:'.self::MAX_DESTINATARIOS],
            'destinatarios.*' => ['email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destinatarios.required' => 'Escribe al menos un correo.',
            'destinatarios.max' => 'Se puede enviar a :max correos como máximo.',
            'destinatarios.*.email' => '":input" no es un correo válido.',
        ];
    }
}
