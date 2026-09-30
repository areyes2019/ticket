{{-- Encabezado de la lista: "Carpetas" (solo en tableta y celular) y el buscador. --}}
<div {{ $attributes->class('bandeja-lista-encabezado') }}>
    <x-boton variante="suave" tipo="button" icono="list" class="bandeja-boton-carpetas"
             aria-controls="bandeja-carpetas" aria-expanded="false" data-mostrar-carpetas>Carpetas</x-boton>
    <label class="bandeja-buscador">
        <x-icono nombre="search" />
        <input type="search" placeholder="Buscar correo" aria-label="Buscar correo" data-buscar>
    </label>
</div>
