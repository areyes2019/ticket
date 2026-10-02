{{-- Cifras del conjunto filtrado completo, no de la página visible. Sin IVA. --}}
@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
@endphp

<div id="existencias-totales" class="cifras" aria-live="polite">
    <x-card class="cifra">
        <p class="cifra-etiqueta">Unidades</p>
        <p class="cifra-valor">{{ number_format($totales['unidades']) }}</p>
    </x-card>
    <x-card class="cifra">
        <p class="cifra-etiqueta">Dinero invertido</p>
        <p class="cifra-valor">{{ $pesos($totales['invertido']) }}</p>
    </x-card>
    <x-card class="cifra">
        <p class="cifra-etiqueta">Beneficio potencial</p>
        <p class="cifra-valor">{{ $pesos($totales['beneficio']) }}</p>
    </x-card>
    <x-card class="cifra">
        <p class="cifra-etiqueta">Total general</p>
        <p class="cifra-valor">{{ $pesos($totales['total']) }}</p>
    </x-card>
</div>
