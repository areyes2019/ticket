@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $zona = config('app.zona_negocio');
@endphp
<tbody id="pedidos-filas" aria-live="polite">
    @forelse ($pedidos as $pedido)
        <tr>
            <td>
                <a href="{{ route('pedidos.show', $pedido) }}">{{ $pedido->folio_formateado }}</a>
                @if ($pedido->cotizacion)
                    <span class="senal-origen" title="De la cotización {{ $pedido->cotizacion->folio_formateado }}">
                        <x-icono nombre="file-earmark-text" /><span class="solo-lectores">De la cotización {{ $pedido->cotizacion->folio_formateado }}</span>
                    </span>
                @endif
                @if (filled($pedido->autofactura_error))
                    <span class="senal-autofactura" title="El cliente intentó facturar y no pudo: {{ $pedido->autofactura_error }}">
                        <x-icono nombre="exclamation-triangle" /><span class="solo-lectores">El cliente intentó facturar y no pudo</span>
                    </span>
                @endif
            </td>
            <td>{{ $pedido->cliente_nombre }}</td>
            <td>{{ $pedido->telefono_legible }}</td>
            <td>{{ $pedido->created_at->setTimezone($zona)->format('d/m/Y') }}</td>
            <td class="numero">{{ $pesos($pedido->total) }}</td>
            <td class="numero">{{ $pesos($pedido->totalPagado()) }}</td>
            <td class="numero">{{ $pesos($pedido->saldoPendiente()) }}</td>
            <td><span @class(['etiqueta', $pedido->estado->claseEtiqueta()])>{{ $pedido->estado->etiqueta() }}</span></td>
            <td>
                <x-boton :href="route('pedidos.show', $pedido)" variante="suave" icono="eye" title="Ver" descripcion="Ver {{ $pedido->folio_formateado }}" />
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="9">
                {{ array_filter(Illuminate\Support\Arr::except($filtros, 'periodo')) !== [] ? 'Ninguna venta coincide con la búsqueda.' : 'No hay ventas en este periodo.' }}
            </td>
        </tr>
    @endforelse
</tbody>
