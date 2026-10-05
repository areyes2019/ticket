{{-- Una fila de la tabla de líneas. $i es el índice (o "__i__" en la plantilla), $linea sus datos.
     $etiquetaPrecio (opcional) cambia la etiqueta del precio: la orden de compra captura costo. --}}
@php
    $error = fn (string ...$campos) => is_int($i) && collect($campos)->contains(fn ($campo) => $errors->has("lineas.{$i}.{$campo}"));
    $nombre = fn (string $campo) => "lineas[{$i}][{$campo}]";
@endphp
{{-- data-precio-directo y data-precio-distribuidor (028) son los dos precios del artículo, para que
     documento-lineas.js cambie el precio al cambiar de cliente; no viajan al servidor. --}}
<tr data-linea @if (! empty($vacia)) data-linea-vacia @endif
    @isset($linea['precio_directo']) data-precio-directo="{{ $linea['precio_directo'] }}" data-precio-distribuidor="{{ $linea['precio_distribuidor'] }}" @endisset>
    <td class="numero" data-linea-numero>{{ is_int($i) ? $i + 1 : '' }}</td>
    <td @class(['campo-error' => $error('cantidad')])>
        <input type="hidden" name="{{ $nombre('articulo_id') }}" value="{{ $linea['articulo_id'] ?? '' }}" data-campo="articulo_id">
        <x-celda :nombre="$nombre('cantidad')" etiqueta="Cantidad" tipo="number" :valor="$linea['cantidad'] ?? null" :error="$error('cantidad')"
            min="1" :max="App\Http\Requests\CotizacionRequest::MAX_CANTIDAD" step="1" inputmode="numeric" data-campo="cantidad" />
    </td>
    <td @class(['campo-error' => $error('descripcion')])>
        <x-celda :nombre="$nombre('descripcion')" etiqueta="Descripción" :valor="$linea['descripcion'] ?? null" :error="$error('descripcion')" maxlength="255" data-campo="descripcion" />
    </td>
    <td @class(['campo-error' => $error('modelo')])>
        <x-celda :nombre="$nombre('modelo')" etiqueta="Modelo" :valor="$linea['modelo'] ?? null" :error="$error('modelo')" maxlength="255" data-campo="modelo" />
    </td>
    <td @class(['campo-error' => $error('precio_unitario')])>
        <x-celda :nombre="$nombre('precio_unitario')" :etiqueta="$etiquetaPrecio ?? 'Precio unitario sin IVA'" tipo="number" :valor="$linea['precio_unitario'] ?? null" :error="$error('precio_unitario')"
            min="0.01" :max="App\Http\Requests\CotizacionRequest::MAX_PRECIO" step="0.01" inputmode="decimal" data-campo="precio_unitario" />
    </td>
    <td @class(['campo-error' => $error('descuento_tipo', 'descuento_valor')])>
        <div class="celda-descuento">
            <x-celda :nombre="$nombre('descuento_tipo')" etiqueta="Tipo de descuento" tipo="select" :opciones="$tiposDescuento" vacia="—" :valor="$linea['descuento_tipo'] ?? null" data-campo="descuento_tipo" />
            <x-celda :nombre="$nombre('descuento_valor')" etiqueta="Descuento" tipo="number" :valor="$linea['descuento_valor'] ?? null" :error="$error('descuento_valor')"
                min="0" step="0.01" inputmode="decimal" data-campo="descuento_valor" />
        </div>
    </td>
    <td @class(['campo-error' => $error('tasa_iva')])>
        <x-celda :nombre="$nombre('tasa_iva')" etiqueta="IVA" tipo="select" :opciones="$tasasIva" :valor="$linea['tasa_iva'] ?? App\Enums\TasaIva::Dieciseis->value" data-campo="tasa_iva" />
    </td>
    <td class="numero"><output data-linea-importe>{{ isset($linea['importe']) ? '$'.number_format((float) $linea['importe'], 2) : '—' }}</output></td>
    <td>
        <x-boton tipo="button" variante="suave" icono="x-lg" descripcion="Quitar línea" title="Quitar línea" data-quitar-linea hidden />
    </td>
</tr>
