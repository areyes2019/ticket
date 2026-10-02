<tbody id="ordenes-filas" aria-live="polite">
    @forelse ($ordenes as $orden)
        <tr>
            <td><a href="{{ route('ordenes-compra.show', $orden) }}" class="enlace-ficha">{{ $orden->folio_formateado }}</a></td>
            <td>
                {{ $orden->proveedor->nombre_comercial }}
                @if ($orden->proveedor->nombre_contacto)
                    <br><span class="ayuda">{{ $orden->proveedor->nombre_contacto }}</span>
                @endif
            </td>
            <td>{{ $orden->proveedor->rfc }}</td>
            <td><span @class(['etiqueta', $orden->estado->claseEtiqueta()])>{{ $orden->estado->etiqueta() }}</span></td>
            <td class="numero">${{ number_format((float) $orden->total, 2) }}</td>
            <td>{{ $orden->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</td>
            <td>
                <x-boton :href="route('ordenes-compra.show', $orden)" variante="suave" icono="eye" title="Ver" descripcion="Ver {{ $orden->folio_formateado }}" />
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="7">
                {{ $hayFiltros ? 'No hay órdenes de compra con estos filtros.' : 'No hay órdenes de compra en este periodo.' }}
            </td>
        </tr>
    @endforelse
</tbody>
