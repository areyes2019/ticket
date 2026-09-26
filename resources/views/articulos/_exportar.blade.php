{{-- Exporta lo que se ve: mismos filtros y orden, sin paginar. --}}
<span id="articulos-exportar">
    <x-boton :href="route('articulos.exportar', Illuminate\Support\Arr::except($parametros, ['por_pagina']))" variante="secundario" icono="download">Exportar CSV</x-boton>
</span>
