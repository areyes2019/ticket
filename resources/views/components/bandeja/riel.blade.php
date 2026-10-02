{{-- Barra lateral (solo escritorio): la hamburguesa pliega y despliega las carpetas. --}}
<div {{ $attributes->class('bandeja-riel') }}>
    <button type="button" class="bandeja-riel-boton" aria-label="Carpetas" title="Carpetas"
            aria-controls="bandeja-carpetas" aria-expanded="false" data-mostrar-carpetas>
        <x-icono nombre="list" />
    </button>
</div>
