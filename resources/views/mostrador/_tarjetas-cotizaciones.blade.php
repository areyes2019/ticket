{{-- Una página de cotizaciones de la consulta del mostrador (034). Cada ficha
     lleva a su detalle. --}}
@foreach ($elementos as $cotizacion)
    <a href="{{ route('mostrador.cotizaciones.ver', $cotizacion) }}" class="mostrador-ficha mostrador-ficha-documento">
        <span class="mostrador-documento-encabezado">
            <strong class="mostrador-ficha-titulo">{{ $cotizacion->folio_formateado }}</strong>
            <strong class="mostrador-documento-total">${{ number_format((float) $cotizacion->total, 2) }}</strong>
        </span>
        <span class="mostrador-documento-cliente">{{ $cotizacion->cliente->razon_social }}</span>
        <span class="mostrador-documento-pie">
            <span class="mostrador-ficha-dato">{{ $cotizacion->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</span>
            <span class="etiqueta {{ $cotizacion->estado->claseEtiqueta() }}">{{ $cotizacion->estado->etiqueta() }}</span>
        </span>
    </a>
@endforeach

@include('mostrador._siguiente', [
    'icono' => 'file-earmark-text',
    'vacio' => $q === '' ? 'Sin cotizaciones en los últimos 30 días.' : 'Sin cotizaciones con esa búsqueda.',
])
