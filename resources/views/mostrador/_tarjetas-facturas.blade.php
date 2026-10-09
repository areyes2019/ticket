{{-- Una página de facturas de la consulta del mostrador (034). Cada ficha
     lleva a su detalle. --}}
@foreach ($elementos as $factura)
    <a href="{{ route('mostrador.facturas.ver', $factura) }}" class="mostrador-ficha mostrador-ficha-documento">
        <span class="mostrador-documento-encabezado">
            <strong class="mostrador-ficha-titulo">{{ $factura->folioVisible() }}</strong>
            <strong class="mostrador-documento-total">${{ number_format((float) $factura->total, 2) }}</strong>
        </span>
        <span class="mostrador-documento-cliente">{{ $factura->cliente->razon_social }}</span>
        <span class="mostrador-documento-pie">
            <span class="mostrador-ficha-dato">{{ $factura->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</span>
            <span class="etiqueta {{ $factura->estado->claseEtiqueta() }}">{{ $factura->estado->etiqueta() }}</span>
        </span>
    </a>
@endforeach

@include('mostrador._siguiente', [
    'icono' => 'receipt',
    'vacio' => $q === '' ? 'Sin facturas en los últimos 30 días.' : 'Sin facturas con esa búsqueda.',
])
