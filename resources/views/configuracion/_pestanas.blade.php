{{-- Barra de pestañas de Configuración (042). Sin JavaScript son enlaces a
     las anclas de cada sección; configuracion-pestanas.js les pone los roles
     de pestaña. Parámetro: $pestanas. --}}
<nav class="pestanas" aria-label="Secciones de Configuración" data-pestanas-barra>
    @foreach ($pestanas as $pestana)
        <a href="#{{ $pestana['ancla'] }}" id="pestana-{{ $pestana['ancla'] }}" data-pestana="{{ $pestana['ancla'] }}">
            <x-icono :nombre="$pestana['icono']" />{{ $pestana['titulo'] }}
            @if ($pestana['advertencia'])
                <span class="pestana-aviso" title="{{ $pestana['advertencia'] }}" aria-hidden="true"></span>
                <span class="solo-lectores">({{ $pestana['advertencia'] }})</span>
            @endif
        </a>
    @endforeach
</nav>
