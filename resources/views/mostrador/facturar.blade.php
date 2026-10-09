@extends('layouts.mostrador')

@php
    $pasos = ['uso' => 'Uso de CFDI', 'forma' => 'Forma de pago', 'metodo' => 'Método de pago', 'revisar' => 'Revisar', 'listo' => 'Listo'];
@endphp

@section('title', 'Facturar '.$cotizacion->folio_formateado.' · Mostrador')

@section('content')
    {{--
        Facturar una cotización desde el mostrador (034): solo los tres datos
        fiscales. Cliente, descuento y renglones los toma
        TimbrarCotizacionRequest de la cotización tal como está, e ignora lo que
        llegue en esos campos: aquí no se edita nada. Es el mismo timbrado
        directo del escritorio (020). "Listo" es el detalle de la factura.
    --}}
    <form method="POST" action="{{ route('cotizaciones.timbrar', $cotizacion) }}" class="mostrador-captura" novalidate
        data-mostrador-facturar
        data-total-pasos="{{ count($pasos) }}"
        @if ($anterior) data-anterior="{{ json_encode($anterior) }}" @endif>
        @csrf
        <input type="hidden" name="origen" value="mostrador">
        <input type="hidden" name="uso_cfdi" value="" data-campo-opcion="uso">
        <input type="hidden" name="forma_pago" value="" data-campo-opcion="forma">
        <input type="hidden" name="metodo_pago" value="" data-campo-opcion="metodo">

        <p class="mostrador-indicador" aria-live="polite">
            <strong>Factura</strong>
            <span data-indicador>Paso 1 de {{ count($pasos) }} · {{ reset($pasos) }}</span>
        </p>

        <p class="mostrador-cliente-elegido">Facturando la cotización {{ $cotizacion->folio_formateado }} — {{ $cotizacion->cliente->razon_social }}</p>

        @if ($errors->any())
            <x-alerta tipo="error">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif

        {{-- Pasos fiscales de 033: tocar elige y avanza; nada viene preseleccionado. --}}
        @include('mostrador._paso-opciones', [
            'paso' => 'uso',
            'titulo' => $pasos['uso'],
            'buscar' => 'Buscar uso de CFDI',
            'opciones' => collect($usos)->mapWithKeys(fn ($uso) => [$uso->value => $uso->descripcion()])->all(),
        ])
        @include('mostrador._paso-opciones', [
            'paso' => 'forma',
            'titulo' => $pasos['forma'],
            'buscar' => 'Buscar forma de pago',
            'opciones' => collect($formas)->mapWithKeys(fn ($forma) => [$forma->value => $forma->descripcion()])->all(),
        ])

        <section class="mostrador-paso" data-paso="metodo" data-titulo="{{ $pasos['metodo'] }}" hidden>
            <div class="mostrador-opciones" data-opciones="metodo">
                @foreach ($metodos as $metodo)
                    <a href="#" class="mostrador-ficha mostrador-opcion mostrador-opcion-grande" data-opcion="{{ $metodo->value }}" data-texto="{{ $metodo->value }} – {{ $metodo->etiqueta() }}">
                        <strong class="mostrador-opcion-clave">{{ $metodo->value }}</strong>
                        <span>{{ $metodo->etiqueta() }}</span>
                        <x-icono nombre="check-lg" class="mostrador-opcion-palomita" />
                    </a>
                @endforeach
            </div>
        </section>

        <section class="mostrador-paso" data-paso="revisar" data-titulo="{{ $pasos['revisar'] }}" hidden>
            <div class="mostrador-revision">
                <p class="mostrador-revision-nombre">{{ $cotizacion->cliente->razon_social }}</p>
                <p class="mostrador-rfc">{{ $cotizacion->cliente->rfc }}</p>
                <p class="mostrador-revision-total">Total ${{ number_format((float) $cotizacion->total, 2) }}</p>
                <dl class="mostrador-revision-fiscal">
                    <dt>Uso de CFDI</dt>
                    <dd data-resumen="uso"></dd>
                    <dt>Forma de pago</dt>
                    <dd data-resumen="forma"></dd>
                    <dt>Método de pago</dt>
                    <dd data-resumen="metodo"></dd>
                </dl>
            </div>

            {{-- Como el diálogo de timbrar del escritorio: la factura conserva el precio cotizado. --}}
            @if ($avisos !== [])
                <x-alerta tipo="advertencia">
                    <p>Precios que cambiaron desde la cotización (se factura el cotizado):</p>
                    <ul>
                        @foreach ($avisos as $aviso)
                            <li>{{ $aviso }}</li>
                        @endforeach
                    </ul>
                </x-alerta>
            @endif

            <div class="mostrador-pie">
                <x-boton icono="patch-check" bloque data-enviar-una-vez>Timbrar</x-boton>
            </div>
        </section>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
