{{-- Ventanas para registrar anticipo y liquidar, del detalle y de la vista
     previa de la bandeja. Parámetros: $cotizacion, $cuentas (activas), $hoy y,
     opcional, $origen (a dónde regresar). --}}
@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $tieneAnticipo = $cotizacion->tieneAnticipo();
    $puedePagar = $cotizacion->puedeRegistrarPago();
    $tipoLiquidar = $tieneAnticipo ? App\Enums\TipoPago::Saldo : App\Enums\TipoPago::PagoTotal;
    $errorPago = $errors->pago->any() ? old('tipo') : null;
@endphp

@if ($puedePagar && ! $tieneAnticipo)
    <dialog id="dialogo-anticipo" class="ficha dialogo" aria-labelledby="dialogo-anticipo-titulo" @if ($errorPago === App\Enums\TipoPago::Anticipo->value) data-abrir-al-cargar @endif>
        <form method="POST" action="{{ route('cotizaciones.pagos.store', $cotizacion) }}">
            @csrf
            @isset($origen)
                <input type="hidden" name="origen" value="{{ $origen }}">
            @endisset
            <input type="hidden" name="tipo" value="{{ App\Enums\TipoPago::Anticipo->value }}">
            <h2 id="dialogo-anticipo-titulo">Registrar anticipo</h2>
            <p>Saldo pendiente: <strong>{{ $pesos($cotizacion->saldoPendiente()) }}</strong></p>
            <x-campo nombre="fecha_pago" id="anticipo-fecha" etiqueta="Fecha de pago" tipo="date" :valor="$hoy" :max="$hoy" required />
            @include('cotizaciones._cuenta-pago', ['id' => 'anticipo-cuenta'])
            <x-campo nombre="monto" id="anticipo-monto" etiqueta="Monto" tipo="number" min="0.01" :max="$cotizacion->saldoPendiente()" step="0.01" inputmode="decimal" required />
            <div class="acciones">
                <x-boton icono="save" :disabled="$cuentas === []">Registrar</x-boton>
                <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
            </div>
        </form>
    </dialog>
@endif

@if ($puedePagar)
    <dialog id="dialogo-liquidar" class="ficha dialogo" aria-labelledby="dialogo-liquidar-titulo" @if ($errorPago === $tipoLiquidar->value) data-abrir-al-cargar @endif>
        <form method="POST" action="{{ route('cotizaciones.pagos.store', $cotizacion) }}">
            @csrf
            @isset($origen)
                <input type="hidden" name="origen" value="{{ $origen }}">
            @endisset
            <input type="hidden" name="tipo" value="{{ $tipoLiquidar->value }}">
            <h2 id="dialogo-liquidar-titulo">{{ $tieneAnticipo ? 'Registrar saldo' : 'Pago total' }}</h2>
            <p>Se registrará el saldo pendiente: <strong data-saldo-pendiente>{{ $pesos($cotizacion->saldoPendiente()) }}</strong></p>
            <x-campo nombre="fecha_pago" id="liquidar-fecha" etiqueta="Fecha de pago" tipo="date" :valor="$hoy" :max="$hoy" required />
            @include('cotizaciones._cuenta-pago', ['id' => 'liquidar-cuenta'])
            <div class="acciones">
                <x-boton icono="save" :disabled="$cuentas === []">Registrar</x-boton>
                <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
            </div>
        </form>
    </dialog>
@endif
