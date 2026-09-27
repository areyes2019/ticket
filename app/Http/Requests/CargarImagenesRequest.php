<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * El tipo de cada imagen no se valida aquí: un archivo que no es imagen se
 * reporta en su renglón sin tumbar el envío.
 */
class CargarImagenesRequest extends FormRequest
{
    /**
     * max_file_uploads de PHP: por encima de este número PHP descarta los
     * archivos sobrantes en silencio.
     */
    public const MAXIMO_ARCHIVOS = 20;

    /**
     * Tope de un envío (suma de imágenes o un .zip), en kilobytes.
     */
    public const TAMANO_MAXIMO_KB = 40960;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'catalogo_id' => ['required', 'integer', ArticuloRequest::reglaCatalogo($this->user()->id)],
            'archivos' => ['required_without:archivo', 'prohibits:archivo', 'array', 'max:'.self::MAXIMO_ARCHIVOS],
            'archivos.*' => ['file'],
            'archivo' => ['required_without:archivos', 'file', 'mimes:zip', 'max:'.self::TAMANO_MAXIMO_KB],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $total = collect($this->file('archivos', []))->sum(fn (UploadedFile $archivo) => $archivo->getSize());

                if ($total > self::TAMANO_MAXIMO_KB * 1024) {
                    $validator->errors()->add('archivos', 'Las imágenes suman más de 40 MB; envía menos a la vez o usa un .zip.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'catalogo_id.exists' => 'Selecciona uno de tus catálogos.',
            'archivos.required_without' => 'Elige varias imágenes o un .zip.',
            'archivo.required_without' => 'Elige varias imágenes o un .zip.',
            'archivos.prohibits' => 'Elige varias imágenes o un .zip, no los dos a la vez.',
            'archivos.max' => 'Máximo '.self::MAXIMO_ARCHIVOS.' imágenes por envío; para más, usa un .zip.',
            'archivo.mimes' => 'El archivo debe ser un .zip.',
            'archivo.max' => 'El .zip pesa más de 40 MB; divídelo en varios.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'catalogo_id' => 'catálogo',
            'archivos' => 'imágenes',
            'archivo' => 'archivo .zip',
        ];
    }
}
