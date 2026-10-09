@extends('layouts.mostrador')

@section('title', 'Factura '.$factura->folioVisible().' · Mostrador')
@section('barra', 'facturas')

@section('content')
    {{--
        Detalle de la factura en el mostrador (034). Es también donde termina el
        timbrado hecho aquí (033), así que el resultado se lee de la factura:
        timbrada (WhatsApp con el PDF; el XML va por correo), pendiente por el
        PAC (se reintenta aquí), rechazada por datos (se corrige en la
        computadora) o cancelada (sin envíos). Nada se cancela ni se edita.
    --}}
    <div class="mostrador-detalle" data-mostrador-limpiar>
        <x-boton :href="route('mostrador.facturas')" variante="suave" icono="chevron-left" data-volver-lista>Facturas</x-boton>

        <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

        @include('mostrador._documento', ['documento' => $factura, 'folio' => $factura->folioVisible()])

        @if ($timbrada && $factura->uuid_fiscal)
            <p class="mostrador-rfc mostrador-uuid">UUID {{ $factura->uuid_fiscal }}</p>
        @endif

        @if ($factura->cotizacion_id && $factura->cotizacion)
            <p class="mostrador-ficha-dato">
                <x-icono nombre="file-earmark-text" />De la cotización
                <a href="{{ route('mostrador.cotizaciones.ver', $factura->cotizacion) }}">{{ $factura->cotizacion->folio_formateado }}</a>
            </p>
        @endif

        @if ($timbrada)
            <div class="mostrador-acciones-resultado">
                <x-boton tipo="button" icono="whatsapp" bloque
                    data-compartir-pdf
                    data-pdf="{{ route('facturas.pdf', $factura) }}"
                    data-archivo="{{ $factura->nombreArchivo('pdf') }}"
                    data-precargar="al-cargar"
                    data-texto="Factura {{ $factura->folioFiscal() }} de {{ config('app.name') }} por ${{ number_format((float) $factura->total, 2) }}">Enviar por WhatsApp</x-boton>
                <x-boton href="#dialogo-envio" variante="secundario" icono="envelope" bloque data-abrir-dialogo>Enviar por correo</x-boton>
                <p class="mostrador-motivo">Por WhatsApp va el PDF; el XML se manda por correo.</p>
            </div>
        @elseif ($cancelada)
            <x-alerta tipo="advertencia">
                Factura cancelada{{ $factura->motivo_cancelacion ? ': '.$factura->motivo_cancelacion->descripcion() : '' }}. No se envía.
            </x-alerta>
        @else
            {{-- Recién timbrada, el motivo ya llegó en el aviso del layout. --}}
            @if ($factura->error_timbrado && ! session('error'))
                <x-alerta tipo="error">{{ $factura->error_timbrado }}</x-alerta>
            @endif

            <div class="mostrador-acciones-resultado">
                @if ($reintentable)
                    <form method="POST" action="{{ route('facturas.timbrar', $factura) }}">
                        @csrf
                        <input type="hidden" name="origen" value="mostrador">
                        <x-boton icono="arrow-repeat" bloque data-enviar-una-vez>Reintentar timbrado</x-boton>
                    </form>
                @else
                    <x-alerta tipo="advertencia">La factura quedó guardada; corrige los datos desde la computadora.</x-alerta>
                @endif
            </div>
        @endif
    </div>

    @if ($timbrada)
        <dialog id="dialogo-envio" class="ficha dialogo" aria-labelledby="dialogo-envio-titulo" @if ($errors->envio->any()) data-abrir-al-cargar @endif>
            <form method="POST" action="{{ route('facturas.enviar', $factura) }}">
                @csrf
                <input type="hidden" name="origen" value="mostrador">
                <h2 id="dialogo-envio-titulo">Enviar por correo</h2>
                @if ($errors->envio->any())
                    <x-alerta tipo="error">{{ $errors->envio->first() }}</x-alerta>
                @endif
                <x-campo nombre="destinatarios_texto" etiqueta="Correo del cliente" tipo="text" inputmode="email" :valor="$factura->cliente->correo" required
                    ayuda="Separa varios correos con comas. Se adjuntan el XML y el PDF." />
                <div class="acciones">
                    <x-boton icono="send" data-enviar-una-vez>Enviar</x-boton>
                    <x-boton tipo="button" variante="secundario" data-cerrar-dialogo>Cancelar</x-boton>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
