<?php

namespace App\Http\Requests;

use App\Enums\FormaPago;
use App\Enums\MetodoPago;
use App\Enums\ObjetoImpuesto;
use App\Enums\TasaIva;
use App\Enums\UsoCfdi;
use App\Models\Articulo;
use App\Models\Factura;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y corrección de una factura. Las líneas y los descuentos siguen las
 * mismas reglas que la cotización (topes incluidos), con dos diferencias: toda
 * línea viene de un artículo (el CFDI necesita sus claves SAT) y se captura la
 * cabecera fiscal. Totales, folio, estado, sellos y copias fiscales los pone
 * el servidor aunque lleguen en la petición.
 */
class FacturaRequest extends CotizacionRequest
{
    /**
     * FacturaPolicy se revisa antes de validar para que una factura ajena
     * responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $factura = $this->route('factura');

        return $factura === null ? true : Gate::inspect('update', $factura);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'lineas.*.articulo_id' => ['required', 'integer', 'distinct', $this->reglaArticulo($this->user()->id)],
            'lineas.*.modelo' => ['required', 'string', 'max:255'],
            'uso_cfdi' => ['required', Rule::in(array_map(fn (UsoCfdi $uso) => $uso->value, UsoCfdi::deFactura()))],
            'metodo_pago' => ['required', Rule::enum(MetodoPago::class)],
            'forma_pago' => ['required', Rule::enum(FormaPago::class), $this->reglaFormaPago()],
            // El origen solo se fija en el alta. Si la cotización se puede
            // facturar lo decide store con la fila bloqueada, no aquí.
            'cotizacion_id' => ['nullable', 'integer', 'prohibits:duplicada_de_id', Rule::exists('cotizaciones', 'id')->where('user_id', $this->user()->id)],
            'duplicada_de_id' => ['nullable', 'integer', Rule::exists('facturas', 'id')->where('user_id', $this->user()->id)],
        ];
    }

    /**
     * Además de las reglas de la cotización: un artículo que no es objeto de
     * impuesto solo admite tasa exento.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $objetos = $this->copiasFiscales()->map(fn (Articulo $articulo) => $articulo->objeto_imp);

                foreach ($this->lineas() as $i => $linea) {
                    if ($objetos->get($linea['articulo_id']) !== ObjetoImpuesto::SiObjeto && $linea['tasa_iva'] !== TasaIva::Exento->value) {
                        $validator->errors()->add("lineas.{$i}.tasa_iva", 'El artículo de la línea '.($i + 1).' no es objeto de impuesto: la tasa debe ser Exento.');
                    }
                }
            },
        ];
    }

    /**
     * Cabecera validada.
     *
     * @return array{cliente_id: int, uso_cfdi: string, forma_pago: string, metodo_pago: string, descuento_global_tipo: string|null, descuento_global_valor: string|null}
     */
    public function datosFactura(): array
    {
        return $this->safe()->only(['cliente_id', 'uso_cfdi', 'forma_pago', 'metodo_pago', 'descuento_global_tipo', 'descuento_global_valor']);
    }

    /**
     * De qué documento sale una factura nueva; en la corrección se ignora.
     *
     * @return array{cotizacion_id: int|null, duplicada_de_id: int|null}
     */
    public function origen(): array
    {
        if ($this->documento() !== null) {
            return ['cotizacion_id' => null, 'duplicada_de_id' => null];
        }

        return [
            'cotizacion_id' => $this->safe()['cotizacion_id'] ?? null,
            'duplicada_de_id' => $this->safe()['duplicada_de_id'] ?? null,
        ];
    }

    /**
     * Artículos de las líneas (incluidos los eliminados después de guardarse),
     * con las claves SAT que se copian a cada línea. Una sola consulta.
     *
     * @return Collection<int, Articulo>
     */
    public function copiasFiscales(): Collection
    {
        return Articulo::withTrashed()
            ->whereIn('id', array_column($this->lineas(), 'articulo_id'))
            ->get(['id', 'clave_prod_serv', 'clave_unidad', 'objeto_imp'])
            ->keyBy('id');
    }

    protected function documento(): ?Factura
    {
        $factura = $this->route('factura');

        return $factura instanceof Factura ? $factura : null;
    }

    /**
     * Regla del SAT para CFDI 4.0: con PPD la forma de pago es 99 (Por
     * definir); con PUE no puede serlo.
     */
    private function reglaFormaPago(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar) {
            $metodo = MetodoPago::tryFrom((string) $this->input('metodo_pago'));

            if ($metodo === MetodoPago::Diferido && $valor !== FormaPago::PorDefinir->value) {
                $fallar('Con método de pago PPD la forma de pago debe ser 99 – Por definir.');
            }

            if ($metodo === MetodoPago::UnaExhibicion && $valor === FormaPago::PorDefinir->value) {
                $fallar('Con método de pago PUE la forma de pago no puede ser 99 – Por definir.');
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
            'lineas.max' => 'Una factura admite como máximo '.self::MAX_LINEAS.' líneas.',
            'lineas.*.articulo_id.required' => 'La línea :position no tiene artículo: agrega los artículos con el buscador.',
            'uso_cfdi.in' => 'Selecciona un uso de CFDI válido para una factura.',
            'cotizacion_id.exists' => 'La cotización de origen no existe.',
            'duplicada_de_id.exists' => 'La factura de origen no existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'uso_cfdi' => 'uso de CFDI',
            'forma_pago' => 'forma de pago',
            'metodo_pago' => 'método de pago',
        ];
    }
}
