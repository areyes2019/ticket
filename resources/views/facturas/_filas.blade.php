<tbody id="facturas-filas" aria-live="polite">
    @forelse ($facturas as $factura)
        <tr>
            <td>
                <a href="{{ route('facturas.show', $factura) }}" class="enlace-ficha">{{ $factura->folio_formateado }}</a>
                @if ($factura->folioFiscal())
                    <br><span class="ayuda">Fiscal {{ $factura->folioFiscal() }}</span>
                @endif
            </td>
            <td>
                {{ $factura->cliente->razon_social }}
                @if ($factura->cliente->nombre_comercial)
                    <br><span class="ayuda">{{ $factura->cliente->nombre_comercial }}</span>
                @endif
            </td>
            <td>{{ $factura->receptor()['rfc'] }}</td>
            <td class="texto-uuid">{{ $factura->uuid_fiscal ?? '—' }}</td>
            <td>
                <span @class(['etiqueta', $factura->estado->claseEtiqueta()])>{{ $factura->estado->etiqueta() }}</span>
                @if ($factura->cancelacionEnCurso())
                    <br><span class="etiqueta etiqueta-suspendido">Cancelación en proceso</span>
                @endif
            </td>
            <td class="numero">${{ number_format((float) $factura->total, 2) }}</td>
            <td>{{ $factura->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</td>
            <td>
                <div class="acciones">
                    <x-boton :href="route('facturas.show', $factura)" variante="suave" icono="eye" title="Ver" descripcion="Ver {{ $factura->folio_formateado }}" />

                    @if ($factura->tieneDocumentoFiscal())
                        {{-- Se muestra solo si el navegador puede compartir archivos (compartir-pdf.js). --}}
                        <x-boton tipo="button" variante="suave" icono="share" title="Compartir PDF" descripcion="Compartir el PDF de {{ $factura->folioVisible() }}" hidden
                            data-compartir-pdf
                            data-pdf="{{ route('facturas.pdf', $factura) }}"
                            data-archivo="{{ $factura->nombreArchivo('pdf') }}"
                            data-precargar="al-apuntar" />
                    @endif

                    @if ($factura->puedeEliminarse())
                        <form method="POST" action="{{ route('facturas.destroy', $factura) }}">
                            @csrf
                            @method('DELETE')
                            <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $factura->folio_formateado }}" data-confirmar="¿Eliminar la factura {{ $factura->folio_formateado }}? No está timbrada; el borrado es definitivo." />
                        </form>
                    @endif
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="8">
                {{ $hayFiltros ? 'Ninguna factura coincide con la búsqueda.' : 'Todavía no hay facturas.' }}
            </td>
        </tr>
    @endforelse
</tbody>
