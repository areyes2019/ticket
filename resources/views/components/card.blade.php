@props(['titulo' => null, 'nivel' => 2, 'estrecha' => false])

<section {{ $attributes->class(['tarjeta', 'tarjeta-estrecha' => $estrecha]) }}>
    @if ($titulo)
        <h{{ $nivel }} class="tarjeta-titulo">{{ $titulo }}</h{{ $nivel }}>
    @endif

    {{ $slot }}
</section>
