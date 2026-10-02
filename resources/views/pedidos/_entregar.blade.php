{{-- Botón "Entregado" (022, corrección 1) y, mientras la ventana del servidor lo
     permita, "Deshacer entrega". Lo comparten el detalle de la venta, el de su
     orden de trabajo y el visor del dashboard.
     Parámetros: $pedido (con ordenTrabajo), $cuentas, $origen (venta, orden o dashboard).
     Sin saldo confirma y entrega; con saldo abre un diálogo que cobra el saldo
     completo a la cuenta elegida. El monto no viaja en la petición. --}}
@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $motivoNoEntrega = $pedido->motivoNoEntrega();
    $dialogoEntrega = 'dialogo-entrega-'.$pedido->id;
@endphp

@if (! $pedido->estaEntregado())
    @if ($motivoNoEntrega !== null)
        <x-boton tipo="button" variante="secundario" icono="box-arrow-right" disabled title="{{ $motivoNoEntrega }}">Entregado</x-boton>
    @elseif (! $pedido->tieneSaldo())
        <form method="POST" action="{{ route('pedidos.entregar.store', $pedido) }}">
            @csrf
            <input type="hidden" name="origen" value="{{ $origen }}">
            <x-boton icono="box-arrow-right" data-enviar-una-vez data-confirmar="¿Marcar {{ $pedido->folio_formateado }} como entregado?">Entregado</x-boton>
        </form>
    @else
        <x-boton :href="'#'.$dialogoEntrega" icono="box-arrow-right" data-abrir-dialogo>Entregado</x-boton>

        <dialog id="{{ $dialogoEntrega }}" class="ficha dialogo" aria-labelledby="{{ $dialogoEntrega }}-titulo" @if ($errors->entrega->any()) data-abrir-al-cargar @endif>
            <form method="POST" action="{{ route('pedidos.entregar.store', $pedido) }}">
                @csrf
                <input type="hidden" name="origen" value="{{ $origen }}">
                <h2 id="{{ $dialogoEntrega }}-titulo">Entregar {{ $pedido->folio_formateado }}</h2>
                <p>{{ $pedido->cliente_nombre }}</p>
                <p>Total {{ $pesos($pedido->total) }} · Pagado {{ $pesos($pedido->totalPagado()) }} · Saldo <strong>{{ $pesos($pedido->saldoPendiente()) }}</strong></p>

                @foreach ($errors->entrega->all() as $error)
                    <x-alerta tipo="error">{{ $error }}</x-alerta>
                @endforeach

                @if ($cuentas === [])
                    <x-alerta tipo="advertencia">
                        Da de alta una cuenta en Contabilidad para poder cobrar.
                        <a href="{{ route('tesoreria.cuentas.index') }}">Ir a cuentas</a>
                    </x-alerta>
                @else
                    <x-campo nombre="cuenta_id" id="{{ $dialogoEntrega }}-cuenta" etiqueta="¿A qué cuenta entra el dinero?" tipo="select" :opciones="$cuentas" vacia="Elige la cuenta…" required />
                @endif

                <div class="acciones">
                    <x-boton icono="cash-coin" :disabled="$cuentas === []" data-enviar-una-vez>Cobrar {{ $pesos($pedido->saldoPendiente()) }} y entregar</x-boton>
                    <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
                </div>
            </form>
        </dialog>
    @endif
@elseif ($pedido->puedeDeshacerEntrega())
    <form method="POST" action="{{ route('pedidos.deshacer-entrega', $pedido) }}">
        @csrf
        <input type="hidden" name="origen" value="{{ $origen }}">
        <x-boton variante="secundario" icono="arrow-counterclockwise" data-enviar-una-vez data-confirmar="¿Deshacer la entrega de {{ $pedido->folio_formateado }}?">Deshacer entrega</x-boton>
    </form>
@endif
