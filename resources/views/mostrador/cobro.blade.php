@extends('layouts.mostrador')

@section('title', 'Cobro · Mostrador')

@section('content')
    {{--
        Cobro de la venta del mostrador (033). La venta ya existe: si el pago
        falla, esta misma pantalla regresa con el motivo y se reintenta solo el
        cobro. La caja va preseleccionada; el monto (el saldo) se puede bajar
        para registrar un anticipo.
    --}}
    {{-- La venta ya se guardó: el borrador de la captura sobra. --}}
    <div class="mostrador-resultado" data-mostrador-limpiar>
        <p class="mostrador-indicador"><strong>Venta al público</strong><span>Cobro</span></p>

        <div class="mostrador-revision">
            <p class="mostrador-revision-nombre">Ticket No. {{ $pedido->numero_ticket }}</p>
            <p>{{ $pedido->cliente_nombre }}</p>
            <p class="mostrador-revision-total">Total ${{ number_format((float) $pedido->total, 2) }}</p>
            @if ((float) $pedido->totalPagado() > 0)
                <p>Pagado ${{ number_format((float) $pedido->totalPagado(), 2) }} · Saldo ${{ number_format((float) $pedido->saldoPendiente(), 2) }}</p>
            @endif
        </div>

        @if ($errors->pago->any())
            <x-alerta tipo="error">
                <p>No se registró el cobro de la venta No. {{ $pedido->numero_ticket }}; la venta sí quedó guardada. Corrige y vuelve a cobrar.</p>
                @foreach ($errors->pago->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif

        @if ($cuentas->isEmpty())
            <x-alerta tipo="advertencia">
                No tienes cuentas activas para registrar el cobro. Da de alta una cuenta en Contabilidad desde la computadora; la venta No. {{ $pedido->numero_ticket }} quedó guardada.
            </x-alerta>
            <x-boton :href="route('mostrador.inicio')" variante="secundario" icono="house" bloque>Inicio</x-boton>
        @else
            <form method="POST" action="{{ route('pedidos.pagos.store', $pedido) }}">
                @csrf
                <input type="hidden" name="origen" value="mostrador">
                <input type="hidden" name="fecha_pago" value="{{ $hoy }}">

                <x-campo nombre="monto" etiqueta="Monto a cobrar" tipo="number" inputmode="decimal" min="0.01" step="0.01"
                    :valor="old('monto', $pedido->saldoPendiente())" required ayuda="Bájalo para registrar un anticipo." />
                <x-campo nombre="cuenta_id" etiqueta="Cuenta" tipo="select" :vacia="$cuentaElegida ? false : 'Elige la cuenta…'"
                    :opciones="$cuentas->pluck('nombre', 'id')->all()" :valor="$cuentaElegida" required />

                <div class="mostrador-pie">
                    <x-boton icono="cash-coin" bloque data-enviar-una-vez>Cobrar</x-boton>
                </div>
            </form>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
