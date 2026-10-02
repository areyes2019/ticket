@extends('layouts.app')

@section('title', 'Editar movimiento · '.config('app.name'))

@php
    $esAjuste = $movimiento->tipo === App\Enums\TipoMovimiento::Ajuste;
    $hoy = now(config('app.zona_negocio'))->toDateString();
@endphp

@section('content')
    <x-card titulo="Editar {{ mb_strtolower($movimiento->tipo->etiqueta()) }}" :nivel="1">
        @include('tesoreria._mensajes')

        <form method="POST" action="{{ route('tesoreria.movimientos.update', $movimiento) }}">
            @csrf
            @method('PUT')

            <x-campo nombre="cuenta_id" etiqueta="Cuenta" tipo="select" :opciones="$cuentas" :valor="$movimiento->cuenta_id" vacia="Selecciona la cuenta" required />
            @if ($esAjuste)
                <x-campo nombre="monto" etiqueta="Monto" tipo="number" step="0.01" inputmode="decimal" :valor="$movimiento->montoCapturado()" required
                    ayuda="Usa un monto negativo para restar." />
            @else
                <x-campo nombre="monto" etiqueta="Monto" tipo="number" step="0.01" min="0.01" inputmode="decimal" :valor="$movimiento->montoCapturado()" required />
            @endif
            <x-campo nombre="fecha" etiqueta="Fecha" tipo="date" :valor="$movimiento->fecha->toDateString()" :max="$hoy" required />
            <x-campo nombre="concepto" :etiqueta="$esAjuste ? 'Motivo' : 'Concepto'" :valor="$movimiento->concepto" maxlength="255" required />

            <div class="acciones">
                <x-boton icono="save" data-enviar-una-vez>Guardar</x-boton>
                <x-boton :href="route('tesoreria.movimientos.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
            </div>
        </form>
    </x-card>
@endsection
