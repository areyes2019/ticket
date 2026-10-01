@props(['texto' => 'Buscar correo', 'nombre' => null, 'valor' => null, 'formulario' => null])

{{-- Encabezado de la lista: "Carpetas" (solo en tableta y celular) y el buscador.
     Con nombre y formulario, el buscador es un campo más de ese formulario. --}}
<div {{ $attributes->class('bandeja-lista-encabezado') }}>
    <x-boton variante="suave" tipo="button" icono="list" descripcion="Carpetas" class="bandeja-boton-carpetas"
             aria-controls="bandeja-carpetas" aria-expanded="false" data-mostrar-carpetas />
    <label class="bandeja-buscador">
        <x-icono nombre="search" />
        <input type="search" placeholder="{{ $texto }}" aria-label="{{ $texto }}" data-buscar
               @if ($nombre) name="{{ $nombre }}" value="{{ $valor }}" autocomplete="off" @endif
               @if ($formulario) form="{{ $formulario }}" @endif>
    </label>
</div>
