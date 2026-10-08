@extends('layouts.mostrador')

@section('title', 'Venta lista · Mostrador')

@section('content')
    {{-- Fin de la venta del mostrador (033): el ticket que dibuja el servidor y
         el mismo botón de compartir del detalle de la venta (019). --}}
    <div class="mostrador-resultado" data-mostrador-limpiar>
        <p class="mostrador-indicador"><strong>Venta al público</strong><span>Listo</span></p>

        <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

        <img class="mostrador-ticket" src="{{ route('pedidos.ticket', $pedido) }}" alt="Ticket No. {{ $pedido->numero_ticket }}">

        <div class="mostrador-acciones-resultado">
            @if ($pedido->puedeCompartirTicket())
                <x-boton tipo="button" icono="whatsapp" bloque
                    data-compartir-pdf
                    data-pdf="{{ route('pedidos.ticket', $pedido) }}"
                    data-tipo="image/jpeg"
                    data-archivo="ticket-{{ $pedido->folio_formateado }}.jpg"
                    data-texto="{{ $mensajeTicket }}"
                    data-sufijo=""
                    data-descargar-sin-menu
                    data-precargar="al-cargar">Compartir por WhatsApp</x-boton>
            @endif
            <x-boton :href="route('mostrador.venta')" variante="secundario" icono="plus-lg" bloque>Nueva venta</x-boton>
            <x-boton :href="route('mostrador.inicio')" variante="secundario" icono="house" bloque>Inicio</x-boton>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
