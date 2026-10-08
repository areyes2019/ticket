@extends('layouts.mostrador')

@section('title', 'Factura · Mostrador')

@section('content')
    {{--
        Resultado de la factura del mostrador (033), leído de la factura:
        timbrada (WhatsApp con el PDF; el XML va por correo), pendiente por el
        PAC (se reintenta aquí) o rechazada por datos (se corrige en la
        computadora: los mismos datos volverían a fallar).
    --}}
    <div class="mostrador-resultado" data-mostrador-limpiar>
        <p class="mostrador-indicador"><strong>Factura</strong><span>Listo</span></p>

        <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

        <div class="mostrador-revision">
            <p class="mostrador-revision-nombre">{{ $factura->folioVisible() }}</p>
            <p>{{ $factura->cliente->razon_social }}</p>
            <p class="mostrador-rfc">{{ $factura->cliente->rfc }}</p>
            <p class="mostrador-revision-total">Total ${{ number_format((float) $factura->total, 2) }}</p>
            @if ($timbrada && $factura->uuid_fiscal)
                <p class="mostrador-rfc">UUID {{ $factura->uuid_fiscal }}</p>
            @endif
        </div>

        @if ($timbrada)
            <div class="mostrador-acciones-resultado">
                <x-boton tipo="button" icono="whatsapp" bloque
                    data-compartir-pdf
                    data-pdf="{{ route('facturas.pdf', $factura) }}"
                    data-archivo="{{ $factura->nombreArchivo('pdf') }}"
                    data-precargar="al-cargar"
                    data-texto="Factura {{ $factura->folioFiscal() }} de {{ config('app.name') }} por ${{ number_format((float) $factura->total, 2) }}">Enviar por WhatsApp</x-boton>
                <x-boton href="#dialogo-envio" variante="secundario" icono="envelope" bloque data-abrir-dialogo>Enviar por correo</x-boton>
                <x-boton :href="route('mostrador.factura')" variante="secundario" icono="plus-lg" bloque>Nueva factura</x-boton>
                <x-boton :href="route('mostrador.inicio')" variante="secundario" icono="house" bloque>Inicio</x-boton>
            </div>
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
                        <x-boton icono="arrow-repeat" bloque data-enviar-una-vez>Reintentar</x-boton>
                    </form>
                @else
                    <x-alerta tipo="advertencia">La factura quedó guardada; corrige los datos desde la computadora.</x-alerta>
                @endif
                <x-boton :href="route('mostrador.inicio')" variante="secundario" icono="house" bloque>Inicio</x-boton>
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
