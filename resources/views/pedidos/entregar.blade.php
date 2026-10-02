@extends('layouts.app')

@section('title', 'Entregar '.$pedido->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-entrega')

{{-- Destino del QR. Se usa de pie, con el cliente enfrente: letra grande y
     botones a todo lo ancho. El servidor decide el camino según el estado. --}}
@php
    $zona = config('app.zona_negocio');
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
@endphp

@section('content')
    @include('documentos._mensajes')

    <section class="entrega" aria-labelledby="entrega-titulo">
        <p class="entrega-ticket">Ticket No. {{ $pedido->numero_ticket }}</p>
        <h1 id="entrega-titulo" class="entrega-cliente">{{ $pedido->cliente_nombre }}</h1>
        <p class="entrega-telefono">{{ $pedido->telefono_legible }}</p>

        @if ($pedido->estaEntregado())
            {{-- Camino 1: ya entregado. Solo informa; es el candado contra el doble escaneo. --}}
            <p class="entrega-estado">
                <x-icono nombre="check-circle" />
                Entregado el {{ $pedido->entregado_en->setTimezone($zona)->format('d/m/Y') }} a las {{ $pedido->entregado_en->setTimezone($zona)->format('H:i') }}
            </p>

            @if ($puedeDeshacer)
                <form method="POST" action="{{ route('pedidos.deshacer-entrega', $pedido) }}" class="entrega-deshacer" data-cuenta-regresiva="{{ App\Models\Pedido::SEGUNDOS_BOTON_DESHACER }}">
                    @csrf
                    <x-boton variante="secundario" icono="arrow-counterclockwise" bloque data-enviar-una-vez>
                        Deshacer (<span data-cuenta-regresiva-numero>{{ App\Models\Pedido::SEGUNDOS_BOTON_DESHACER }}</span>)
                    </x-boton>
                </form>
            @endif
        @elseif (! $pedido->tieneSaldo())
            {{-- Camino 2: saldo en cero. Se cierra solo al cargar (sin JavaScript, con el botón). --}}
            <dl class="entrega-montos">
                <div><dt>Total</dt><dd>{{ $pesos($pedido->total) }}</dd></div>
                <div class="entrega-pagado"><dt>Pagado</dt><dd>Completo</dd></div>
            </dl>
            <form method="POST" action="{{ route('pedidos.entregar.store', $pedido) }}" data-enviar-al-cargar>
                @csrf
                <x-boton icono="box-arrow-right" bloque data-enviar-una-vez>Entregar</x-boton>
            </form>
        @else
            {{-- Camino 3: saldo pendiente. No toca nada hasta que el usuario confirma. --}}
            <dl class="entrega-montos">
                <div><dt>Total</dt><dd>{{ $pesos($pedido->total) }}</dd></div>
                <div><dt>Pagado</dt><dd>{{ $pesos($pedido->totalPagado()) }}</dd></div>
                <div class="entrega-saldo"><dt>Saldo</dt><dd>{{ $pesos($pedido->saldoPendiente()) }}</dd></div>
            </dl>

            @if ($cuentas === [])
                <x-alerta tipo="advertencia">
                    Da de alta una cuenta en Contabilidad para poder cobrar.
                    <a href="{{ route('tesoreria.cuentas.index') }}">Ir a cuentas</a>
                </x-alerta>
            @else
                <form method="POST" action="{{ route('pedidos.entregar.store', $pedido) }}">
                    @csrf
                    <x-campo nombre="cuenta_id" id="entrega-cuenta" etiqueta="¿A qué cuenta entra el dinero?" tipo="select" :opciones="$cuentas" vacia="Elige la cuenta…" required />
                    <x-boton icono="cash-coin" bloque data-enviar-una-vez data-habilitar-con="#entrega-cuenta">Cobrar y entregar {{ $pesos($pedido->saldoPendiente()) }}</x-boton>
                </form>
            @endif
        @endif

        <p class="entrega-detalle"><a href="{{ route('pedidos.show', $pedido) }}">Ver el pedido {{ $pedido->folio_formateado }}</a></p>
    </section>
@endsection
