<tbody id="cotizaciones-filas" aria-live="polite">
    @forelse ($cotizaciones as $cotizacion)
        <tr>
            <td><a href="{{ route('cotizaciones.show', $cotizacion) }}" class="enlace-ficha">{{ $cotizacion->folio_formateado }}</a></td>
            <td>
                {{ $cotizacion->cliente->razon_social }}
                @if ($cotizacion->cliente->nombre_comercial)
                    <br><span class="ayuda">{{ $cotizacion->cliente->nombre_comercial }}</span>
                @endif
            </td>
            <td>{{ $cotizacion->cliente->rfc }}</td>
            <td>
                <span @class(['etiqueta', $cotizacion->estado->claseEtiqueta()])>{{ $cotizacion->estado->etiqueta() }}</span>
                @if ($cotizacion->mostrarAvisoCaducidad())
                    <br><span class="etiqueta etiqueta-suspendido" data-aviso-caducidad>{{ $cotizacion->textoCaducidad() }}</span>
                @endif
            </td>
            <td class="numero">${{ number_format((float) $cotizacion->total, 2) }}</td>
            <td>{{ $cotizacion->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</td>
            <td>
                <div class="acciones">
                    <x-boton :href="route('cotizaciones.show', $cotizacion)" variante="suave" icono="eye" title="Ver" descripcion="Ver {{ $cotizacion->folio_formateado }}" />

                    @if ($cotizacion->puedeEliminarse())
                        <form method="POST" action="{{ route('cotizaciones.destroy', $cotizacion) }}">
                            @csrf
                            @method('DELETE')
                            <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $cotizacion->folio_formateado }}" data-confirmar="¿Eliminar la cotización {{ $cotizacion->folio_formateado }}? El borrado es definitivo." />
                        </form>
                    @endif
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="7">
                {{ $hayFiltros ? 'Ninguna cotización coincide con la búsqueda.' : 'No hay cotizaciones en este periodo.' }}
            </td>
        </tr>
    @endforelse
</tbody>
