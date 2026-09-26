{{-- Respuesta de la búsqueda dinámica: el <table> solo sirve para que el navegador interprete el <tr> y el <tbody>. --}}
<table>
    <thead>
        @include('articulos._titulos')
    </thead>
    @include('articulos._filas')
</table>

@include('articulos._paginacion')
@include('articulos._orden')
@include('articulos._exportar')
