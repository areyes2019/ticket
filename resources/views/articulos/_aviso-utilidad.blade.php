{{-- Aviso no bloqueante de utilidad alta, bajo el campo $campo. Lo muestra y oculta precio-articulo.js; sin JavaScript lo pinta el servidor con el valor recibido. --}}
@php
    $umbral = App\Models\Articulo::UMBRAL_UTILIDAD_ALTA;
    $actual = old($campo, $valor);
    $alto = is_numeric($actual) && (float) $actual > $umbral;
@endphp
<p id="{{ $campo }}-aviso" class="aviso-utilidad"{{ $alto ? '' : ' hidden' }}>
    <x-icono nombre="exclamation-triangle" />
    Más de {{ $umbral }}%: el costo se multiplica por <span data-factor>{{ $alto ? round(1 + (float) $actual / 100, 4) : '' }}</span>. Revisa que no sobre un cero.
</p>
