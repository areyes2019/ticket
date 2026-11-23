<?php

namespace App\Services\Cotizaciones;

use App\Enums\ObjetoImpuesto;
use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Support\Facades\DB;

/**
 * Alta de una cotización con su folio, el descuento del cliente y los datos
 * bancarios congelados, totales y líneas (011). La usan CotizacionController
 * y el agente de ventas de WhatsApp (041), para que las dos vías guarden lo
 * mismo.
 */
class CreadorCotizacion
{
    /**
     * @param  array<string, mixed>  $datos  cliente_id y descuento global
     * @param  list<array<string, mixed>>  $lineas
     * @param  array<string, mixed>  $totales  de CalculadoraTotalesDocumento::calcular
     * @param  array<string, mixed>  $extra  columnas que no vienen del formulario (notas, contacto, conversación)
     */
    public function crear(User $user, array $datos, array $lineas, array $totales, array $extra = []): Cotizacion
    {
        return DB::transaction(function () use ($user, $datos, $lineas, $totales, $extra) {
            $cotizacion = $user->cotizaciones()->make($datos);
            $cotizacion->forceFill($extra);
            $cotizacion->folio = self::siguienteFolio($user);
            $cotizacion->congelarDescuentoCliente();
            $cotizacion->congelarDatosBancarios();
            $cotizacion->aplicarTotales($totales);
            $cotizacion->save();

            $this->guardarLineas($cotizacion, $lineas, $totales);

            return $cotizacion;
        });
    }

    /**
     * Líneas de catálogo al precio que el sistema pone al agregar el artículo
     * (precio de venta sin IVA; nunca el de distribuidor) con el descuento
     * permanente del cliente (023), y sus totales.
     *
     * @param  list<array{articulo_id: int, cantidad: int}>  $pedidas
     * @return array{lineas: list<array<string, mixed>>, totales: array<string, mixed>}
     */
    public static function lineasDeCatalogo(User $user, Cliente $cliente, array $pedidas): array
    {
        $articulos = $user->articulos()->whereIn('id', array_column($pedidas, 'articulo_id'))->get()->keyBy('id');
        $descuento = (float) $cliente->descuento_permanente > 0 ? $cliente->descuento_permanente : null;

        $lineas = array_map(function (array $pedida) use ($articulos, $descuento) {
            /** @var Articulo $articulo */
            $articulo = $articulos->get($pedida['articulo_id']);

            return [
                'articulo_id' => $articulo->id,
                'cantidad' => $pedida['cantidad'],
                'descripcion' => $articulo->nombre,
                'modelo' => $articulo->modelo,
                'precio_unitario' => $articulo->precio_unitario_sin_iva,
                'descuento_tipo' => $descuento === null ? null : TipoDescuento::Porcentaje->value,
                'descuento_valor' => $descuento,
                'tasa_iva' => $articulo->objeto_imp === ObjetoImpuesto::SiObjeto ? TasaIva::Dieciseis->value : TasaIva::Exento->value,
            ];
        }, $pedidas);

        return ['lineas' => $lineas, 'totales' => CalculadoraTotalesDocumento::calcular($lineas, null, null)];
    }

    public static function siguienteFolio(User $user): int
    {
        $bloqueado = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $folio = max($bloqueado->ultimo_folio_cotizacion, (int) $bloqueado->cotizaciones()->max('folio')) + 1;

        $bloqueado->forceFill(['ultimo_folio_cotizacion' => $folio])->save();

        return $folio;
    }

    /**
     * Reemplaza las líneas. El costo se copia del artículo en este momento
     * (una sola consulta); importe e IVA salen de la calculadora.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @param  array<string, mixed>  $totales
     */
    public function guardarLineas(Cotizacion $cotizacion, array $lineas, array $totales): void
    {
        $costos = Articulo::withTrashed()
            ->whereIn('id', array_filter(array_column($lineas, 'articulo_id')))
            ->pluck('costo_con_descuento', 'id');

        $cotizacion->lineas()->delete();

        foreach ($lineas as $i => $linea) {
            $cotizacion->lineas()->create([
                ...$linea,
                'orden' => $i + 1,
                'importe' => $totales['lineas'][$i]['importe'],
                'iva_importe' => $totales['lineas'][$i]['iva_importe'],
                'costo_unitario' => $linea['articulo_id'] === null ? null : $costos->get($linea['articulo_id']),
            ]);
        }
    }
}
