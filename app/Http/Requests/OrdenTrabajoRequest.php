<?php

namespace App\Http\Requests;

use App\Enums\ColorTinta;
use App\Models\Pedido;
use App\Models\PedidoLinea;
use App\Rules\ImagenLegible;
use App\Services\Imagenes\GuardadorImagenWebp;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de la orden de trabajo (022): un color de tinta por cada
 * línea de la venta, con el id de la línea como clave, y la imagen opcional.
 */
class OrdenTrabajoRequest extends FormRequest
{
    public function authorize(): bool|Response
    {
        return Gate::inspect($this->isMethod('POST') ? 'crearOrdenTrabajo' : 'editarOrdenTrabajo', $this->pedido());
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $reglas = [
            'colores' => ['required', 'array:'.$this->pedido()->lineas->pluck('id')->join(',')],
            'imagen' => ['bail', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.GuardadorImagenWebp::TAMANO_MAXIMO_KB, new ImagenLegible],
            'quitar_imagen' => ['boolean'],
        ];

        foreach ($this->pedido()->lineas as $linea) {
            $reglas["colores.{$linea->id}.color"] = ['required', Rule::enum(ColorTinta::class)];
            $reglas["colores.{$linea->id}.otro"] = ['nullable', 'string', 'max:40', "required_if:colores.{$linea->id}.color,".ColorTinta::Otro->value];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mensajes = [
            'colores.required' => 'Elige el color de tinta de cada artículo.',
            'colores.array' => 'Hay un artículo que no es de esta venta. Recarga la página.',
            'imagen.mimes' => 'La imagen debe ser JPG, PNG o WEBP. Vuelve a elegir la imagen.',
            'imagen.max' => 'La imagen pesa más de 10 MB. Vuelve a elegir una más ligera.',
        ];

        foreach ($this->pedido()->lineas as $linea) {
            $mensajes["colores.{$linea->id}.color.required"] = "Elige el color de tinta de {$linea->descripcion}.";
            $mensajes["colores.{$linea->id}.color.enum"] = "Elige el color de tinta de {$linea->descripcion}.";
            $mensajes["colores.{$linea->id}.otro.required_if"] = "Escribe el color de tinta de {$linea->descripcion}.";
            $mensajes["colores.{$linea->id}.otro.max"] = "El color de tinta de {$linea->descripcion} admite 40 caracteres.";
        }

        return $mensajes;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['imagen' => 'imagen'];
    }

    /**
     * Renglones listos para OrdenTrabajoLinea: el texto de "Otro" solo se
     * guarda con ese color.
     *
     * @return list<array{pedido_linea_id: int, color_tinta: string, color_tinta_otro: string|null}>
     */
    public function colores(): array
    {
        $colores = $this->validated('colores');

        return $this->pedido()->lineas->map(function (PedidoLinea $linea) use ($colores) {
            $color = $colores[$linea->id]['color'];
            $otro = trim((string) ($colores[$linea->id]['otro'] ?? ''));

            return [
                'pedido_linea_id' => $linea->id,
                'color_tinta' => $color,
                'color_tinta_otro' => $color === ColorTinta::Otro->value && $otro !== '' ? $otro : null,
            ];
        })->values()->all();
    }

    private function pedido(): Pedido
    {
        /** @var Pedido $pedido */
        $pedido = $this->route('pedido');

        return $pedido;
    }
}
