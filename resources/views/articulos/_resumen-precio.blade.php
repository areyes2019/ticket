{{-- Cadena de cálculo de un precio ($renglones de ArticuloController::resumenPrecio()). En edición la pinta
     el servidor con lo guardado; precio-articulo.js la recalcula en vivo con la utilidad del campo $utilidad
     y, si está vacío, con la $herencia del catálogo ("utilidad" o "utilidad_distribuidor"). --}}
<dl class="resumen-precio" data-resumen-precio data-precio="precio_proveedor" data-utilidad="{{ $utilidad }}" data-herencia="{{ $herencia }}" data-catalogo="catalogo_id" data-objeto="objeto_imp"
    data-tasa-iva="{{ App\Models\Articulo::TASA_IVA }}" data-catalogos="{{ json_encode($preciosCatalogo, JSON_FORCE_OBJECT) }}" @isset($titulo) aria-label="{{ $titulo }}" @endisset>
    @foreach ($renglones as $renglon)
        <div @class(['resumen-total' => $renglon['total'] ?? false]) data-renglon="{{ $renglon['clave'] }}" @if ($renglon['oculto'] ?? false) hidden @endif>
            <dt>
                {{ $renglon['etiqueta'] }}@isset($renglon['sufijoIva'])<span data-sufijo-iva @if (! $renglon['sufijoIva']) hidden @endif> con IVA</span>@endisset
                @isset($renglon['porcentaje']) (<span data-porcentaje="{{ $renglon['clave'] }}">{{ $renglon['porcentaje'] }}</span>)@endisset
            </dt>
            <dd><output data-valor="{{ $renglon['clave'] }}">{{ $renglon['valor'] }}</output></dd>
        </div>
    @endforeach
</dl>
