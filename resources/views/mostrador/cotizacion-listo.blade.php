@extends('layouts.mostrador')

@section('title', 'Cotización lista · Mostrador')

@section('content')
    {{-- Fin de la cotización del mostrador (033). WhatsApp comparte el PDF desde
         el aparato y la marca enviada; el correo sale del servidor, como en 011. --}}
    <div class="mostrador-resultado" data-mostrador-limpiar>
        <p class="mostrador-indicador"><strong>Cotización</strong><span>Listo</span></p>

        <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

        <div class="mostrador-revision">
            <p class="mostrador-revision-nombre">{{ $cotizacion->folio_formateado }}</p>
            <p>{{ $cotizacion->cliente->razon_social }}</p>
            <p>{{ $cotizacion->lineas_count }} {{ $cotizacion->lineas_count === 1 ? 'renglón' : 'renglones' }}</p>
            <p class="mostrador-revision-total">Total ${{ number_format((float) $cotizacion->total, 2) }}</p>
            <p><span class="etiqueta etiqueta-{{ str_replace('_', '-', $cotizacion->estado->value) }}" data-estado-documento>{{ $cotizacion->estado->etiqueta() }}</span></p>
        </div>

        <div class="mostrador-acciones-resultado">
            <x-boton tipo="button" icono="whatsapp" bloque
                data-compartir-pdf
                data-pdf="{{ route('cotizaciones.pdf', $cotizacion) }}"
                data-marcar="{{ route('cotizaciones.marcar-enviada', $cotizacion) }}"
                data-archivo="cotizacion-{{ $cotizacion->folio_formateado }}.pdf"
                data-precargar="al-cargar"
                data-texto="Cotización {{ $cotizacion->folio_formateado }} de {{ config('app.name') }} por ${{ number_format((float) $cotizacion->total, 2) }}">Enviar por WhatsApp</x-boton>
            <x-boton href="#dialogo-envio" variante="secundario" icono="envelope" bloque data-abrir-dialogo>Enviar por correo</x-boton>
            <x-boton :href="route('mostrador.cotizacion')" variante="secundario" icono="plus-lg" bloque>Nueva cotización</x-boton>
            <x-boton :href="route('mostrador.inicio')" variante="secundario" icono="house" bloque>Inicio</x-boton>
        </div>
    </div>

    <dialog id="dialogo-envio" class="ficha dialogo" aria-labelledby="dialogo-envio-titulo" @if ($errors->envio->any()) data-abrir-al-cargar @endif>
        <form method="POST" action="{{ route('cotizaciones.enviar', $cotizacion) }}">
            @csrf
            <input type="hidden" name="origen" value="mostrador">
            <h2 id="dialogo-envio-titulo">Enviar por correo</h2>
            @if ($errors->envio->any())
                <x-alerta tipo="error">{{ $errors->envio->first() }}</x-alerta>
            @endif
            <x-campo nombre="destinatarios_texto" etiqueta="Correo del cliente" tipo="text" inputmode="email" :valor="$cotizacion->cliente->correo" required
                ayuda="Separa varios correos con comas. Se adjunta el PDF." />
            <div class="acciones">
                <x-boton icono="send" data-enviar-una-vez>Enviar</x-boton>
                <x-boton tipo="button" variante="secundario" data-cerrar-dialogo>Cancelar</x-boton>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
