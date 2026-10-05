@extends('layouts.app')

@section('title', $orden->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $telefono = preg_replace('/\D/', '', (string) $orden->proveedor->telefono);
@endphp

@section('content')
    <div class="encabezado">
        <h1>
            Orden de compra {{ $orden->folio_formateado }}
            <span @class(['etiqueta', $orden->estado->claseEtiqueta()]) data-estado-documento>{{ $orden->estado->etiqueta() }}</span>
        </h1>
        <x-boton :href="route('ordenes-compra.index')" variante="secundario" icono="arrow-left">Listado</x-boton>
    </div>

    @include('documentos._mensajes')
    @include('documentos._aviso-emisor')

    @foreach (['envio', 'pago'] as $bolsa)
        @if ($errors->{$bolsa}->any())
            <x-alerta tipo="error">
                @foreach ($errors->{$bolsa}->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif
    @endforeach

    <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

    @if ($orden->duplicadaDe)
        <p class="ayuda">Duplicada de <a href="{{ route('ordenes-compra.show', $orden->duplicadaDe) }}">{{ $orden->duplicadaDe->folio_formateado }}</a></p>
    @endif

    <div class="detalle-documento">
        <div class="detalle-acciones">
            @can('update', $orden)
                <x-boton :href="route('ordenes-compra.edit', $orden)" variante="secundario" icono="pencil">Editar</x-boton>
            @endcan

            <x-boton href="#dialogo-envio" variante="secundario" icono="envelope" data-abrir-dialogo>Enviar por correo</x-boton>
            <x-boton tipo="button" variante="secundario" icono="whatsapp" hidden
                data-compartir-pdf
                data-pdf="{{ route('ordenes-compra.pdf', $orden) }}"
                data-marcar="{{ route('ordenes-compra.marcar-enviada', $orden) }}"
                data-archivo="orden-compra-{{ $orden->folio_formateado }}.pdf"
                data-telefono="{{ $telefono }}"
                data-precargar="al-cargar"
                data-texto="Orden de compra {{ $orden->folio_formateado }} de {{ config('app.name') }} por {{ $pesos($orden->total) }}">Compartir por WhatsApp</x-boton>

            @if ($orden->puedeRegistrarPago())
                <x-boton href="#dialogo-pago" variante="secundario" icono="cash-stack" data-abrir-dialogo>Registrar pago</x-boton>
            @endif

            @if ($orden->puedeCancelarPago())
                <form method="POST" action="{{ route('ordenes-compra.pago.destroy', $orden) }}">
                    @csrf
                    @method('DELETE')
                    <x-boton variante="secundario" icono="arrow-counterclockwise" data-confirmar="Se eliminará el egreso de {{ $pesos($orden->total) }} en Contabilidad, se recalculará el saldo de {{ $orden->cuenta->nombre }} y la orden volverá a Enviada para que puedas editarla.">Cancelar pago</x-boton>
                </form>
            @endif

            @if ($orden->puedeRecibirse())
                <form method="POST" action="{{ route('ordenes-compra.recibir', $orden) }}">
                    @csrf
                    <x-boton variante="secundario" icono="box-seam" data-confirmar="¿Marcar la orden como recibida? Su mercancía entrará a existencias y ya no podrás cancelar su pago ni editarla.">Marcar como recibida</x-boton>
                </form>
            @endif

            <form method="POST" action="{{ route('ordenes-compra.duplicar', $orden) }}">
                @csrf
                <x-boton variante="secundario" icono="copy" data-enviar-una-vez>Duplicar</x-boton>
            </form>

            <x-boton :href="route('ordenes-compra.pdf', $orden)" variante="secundario" icono="file-earmark-pdf" target="_blank">Ver PDF</x-boton>
            <x-boton :href="route('ordenes-compra.pdf', [$orden, 'descargar' => 1])" variante="secundario" icono="download">Descargar PDF</x-boton>

            @can('delete', $orden)
                <form method="POST" action="{{ route('ordenes-compra.destroy', $orden) }}">
                    @csrf
                    @method('DELETE')
                    <x-boton variante="peligro" icono="trash" data-confirmar="¿Eliminar la orden de compra {{ $orden->folio_formateado }}? El borrado es definitivo: se lleva sus líneas y no hay papelera.">Eliminar</x-boton>
                </form>
            @endcan
        </div>

        <div class="detalle-principal">
            <x-ordenes-compra.hoja :orden="$orden" />

            <x-card titulo="Pago">
                @if ($orden->estaPagada())
                    <dl class="resumen-precio">
                        <div><dt>Cuenta</dt><dd><a href="{{ route('tesoreria.movimientos.index', ['cuenta_id' => $orden->cuenta_id]) }}">{{ $orden->cuenta->nombre }}</a></dd></div>
                        <div><dt>Fecha</dt><dd>{{ $orden->fecha_pago->format('d/m/Y') }}</dd></div>
                        <div class="resumen-total"><dt>Pagado</dt><dd>{{ $pesos($orden->total) }}</dd></div>
                    </dl>
                @else
                    <p class="ayuda">Sin pago registrado. El pago es de contado, por el total, y se registra cuando la orden ya se envió.</p>
                @endif
            </x-card>
        </div>
    </div>

    {{-- Diálogos. Sin JavaScript se muestran con el enlace (#id) gracias a :target. --}}
    @include('ordenes-compra._dialogos')
@endsection

@push('scripts')
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
@endpush
