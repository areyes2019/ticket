{{-- Cierre de una página de tarjetas (033, 034): el aviso de lista vacía y el
     marcador con la URL de la página siguiente. --}}
@if ($elementos->isEmpty() && $elementos->onFirstPage())
    <p class="mostrador-vacio"><x-icono :nombre="$icono" />{{ $vacio }}</p>
@endif

@if ($siguiente)
    <div class="mostrador-cargando" data-siguiente="{{ $siguiente }}">Cargando…</div>
@endif
