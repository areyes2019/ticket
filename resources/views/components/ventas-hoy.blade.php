@php
    // Contador de ventas del día (039): la regla vive solo en ContadorVentas.
    $total = app(\App\Services\Ventas\ContadorVentas::class)->hoy(auth()->user());
@endphp

<span @class(['ventas-hoy', 'ventas-hoy-cero' => $total === 0]) title="Ventas de hoy" aria-label="Ventas de hoy: {{ $total }}" role="status">
    <x-icono nombre="bag-check" />
    <span class="ventas-hoy-numero" aria-hidden="true">{{ $total > 99 ? '99+' : $total }}</span>
</span>
