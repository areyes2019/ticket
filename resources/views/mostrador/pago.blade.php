@extends('layouts.mostrador')

@section('title', 'Pago de '.$cotizacion->folio_formateado.' · Mostrador')

@section('content')
    {{--
        Registrar un pago de la cotización desde el mostrador (034), con la forma
        del cobro de la venta (033): saldo ya escrito, fecha de hoy y la caja
        preseleccionada. El tipo de pago no se pregunta: mostrador.js lo deduce
        del monto (igual al saldo, pago total; menor, anticipo) y, con un
        anticipo ya registrado, solo queda el saldo. El servidor revisa todo
        con CotizacionPagoRequest, sin cambios.
    --}}
    @php
        $saldo = $cotizacion->saldoPendiente();
    @endphp

    <div class="mostrador-resultado">
        <x-boton :href="route('mostrador.cotizaciones.ver', $cotizacion)" variante="suave" icono="chevron-left">{{ $cotizacion->folio_formateado }}</x-boton>

        <p class="mostrador-indicador"><strong>Registrar pago</strong></p>

        <div class="mostrador-revision">
            <p class="mostrador-revision-nombre">{{ $cotizacion->folio_formateado }}</p>
            <p>{{ $cotizacion->cliente->razon_social }}</p>
            <p>Total ${{ number_format((float) $cotizacion->total, 2) }}@if ($cotizacion->pagos->isNotEmpty()) · Pagado ${{ number_format((float) $cotizacion->totalPagado(), 2) }}@endif</p>
            <p class="mostrador-revision-total">Saldo ${{ number_format((float) $saldo, 2) }}</p>
        </div>

        @if ($errors->pago->any())
            <x-alerta tipo="error">
                @foreach ($errors->pago->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif

        @if ($cuentas->isEmpty())
            <x-alerta tipo="advertencia">
                No tienes cuentas activas para registrar el pago. Da de alta una cuenta en Contabilidad desde la computadora.
            </x-alerta>
        @else
            <form method="POST" action="{{ route('cotizaciones.pagos.store', $cotizacion) }}" class="mostrador-pago"
                data-mostrador-pago data-saldo="{{ $saldo }}">
                @csrf
                <input type="hidden" name="origen" value="mostrador">
                <input type="hidden" name="tipo" value="{{ $tieneAnticipo ? App\Enums\TipoPago::Saldo->value : App\Enums\TipoPago::PagoTotal->value }}" data-tipo-pago>

                @if ($tieneAnticipo)
                    <p class="mostrador-motivo" data-monto-fijo>Ya tiene un anticipo: se registra el saldo de ${{ number_format((float) $saldo, 2) }}.</p>
                @else
                    <x-campo nombre="monto" etiqueta="Monto a cobrar" tipo="number" inputmode="decimal" min="0.01" step="0.01" :max="$saldo"
                        :valor="old('monto', $saldo)" required ayuda="Bájalo para registrar un anticipo." data-monto-pago />
                @endif

                <x-campo nombre="fecha_pago" etiqueta="Fecha" tipo="date" :max="$hoy" :valor="old('fecha_pago', $hoy)" required />
                <x-campo nombre="cuenta_id" etiqueta="Cuenta" tipo="select" :vacia="$cuentaElegida ? false : 'Elige la cuenta…'"
                    :opciones="$cuentas->pluck('nombre', 'id')->all()" :valor="$cuentaElegida" required />

                {{-- El primer pago puede crear la venta y su orden de trabajo (029): sus datos, ya llenos. --}}
                @include('cotizaciones._primer-pago', ['destino' => $destino, 'prefijo' => 'mostrador-pago'])

                <div class="mostrador-pie">
                    <x-boton icono="cash-coin" bloque data-enviar-una-vez>Registrar pago</x-boton>
                </div>
            </form>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
