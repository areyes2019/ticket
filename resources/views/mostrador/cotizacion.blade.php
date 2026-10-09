@extends('layouts.mostrador')

@section('title', 'Cotización '.$cotizacion->folio_formateado.' · Mostrador')
@section('barra', 'cotizaciones')

@section('content')
    {{--
        Detalle de la cotización en el mostrador (034). Es también donde termina
        la captura de una cotización (033). Facturar y registrar un pago abren
        sus propias pantallas; WhatsApp comparte el PDF desde el aparato y la
        marca enviada; el correo sale del servidor, como en 011. Nada se edita.
    --}}
    {{-- La cotización ya existe: el borrador de la captura sobra. --}}
    <div class="mostrador-detalle" data-mostrador-limpiar>
        <x-boton :href="route('mostrador.cotizaciones')" variante="suave" icono="chevron-left" data-volver-lista>Cotizaciones</x-boton>

        <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

        @include('mostrador._documento', ['documento' => $cotizacion, 'folio' => $cotizacion->folio_formateado, 'conPagos' => true])

        @if ($cotizacion->venta)
            <p class="mostrador-ficha-dato"><x-icono nombre="bag-check" />Venta {{ $cotizacion->venta->folio_formateado }}</p>
        @endif

        <div class="mostrador-acciones-resultado">
            {{-- Facturar: la misma regla del escritorio (motivoNoFacturable). --}}
            @if ($facturar['accion'] === 'facturar')
                <x-boton :href="route('mostrador.cotizaciones.facturar', $cotizacion)" icono="receipt" bloque>Facturar</x-boton>
            @elseif ($facturar['accion'] === 'ver')
                <p class="mostrador-motivo">{{ $facturar['motivo'] }}</p>
                <x-boton :href="route('mostrador.facturas.ver', $facturar['factura'])" icono="receipt" bloque>Ver su factura</x-boton>
            @else
                <x-boton tipo="button" icono="receipt" bloque disabled>Facturar</x-boton>
                <p class="mostrador-motivo" data-motivo-facturar>
                    {{ $facturar['motivo'] }}
                    @if ($facturar['factura'])
                        <a href="{{ route('mostrador.facturas.ver', $facturar['factura']) }}">Ver la factura {{ $facturar['factura']->folioVisible() }}</a>
                    @endif
                </p>
            @endif

            <x-boton tipo="button" variante="secundario" icono="whatsapp" bloque
                data-compartir-pdf
                data-pdf="{{ route('cotizaciones.pdf', $cotizacion) }}"
                data-marcar="{{ route('cotizaciones.marcar-enviada', $cotizacion) }}"
                data-archivo="cotizacion-{{ $cotizacion->folio_formateado }}.pdf"
                data-precargar="al-cargar"
                data-texto="Cotización {{ $cotizacion->folio_formateado }} de {{ config('app.name') }} por ${{ number_format((float) $cotizacion->total, 2) }}">Enviar por WhatsApp</x-boton>
            <x-boton href="#dialogo-envio" variante="secundario" icono="envelope" bloque data-abrir-dialogo>Enviar por correo</x-boton>

            @if ($motivoSinPago === null)
                <x-boton :href="route('mostrador.cotizaciones.pago', $cotizacion)" variante="secundario" icono="cash-coin" bloque>Registrar pago</x-boton>
            @else
                <p class="mostrador-motivo" data-motivo-pago>{{ $motivoSinPago }}</p>
            @endif
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
