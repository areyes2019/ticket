{{-- Respuesta de la búsqueda dinámica: el <table> solo sirve para que el navegador interprete el <tr> y el <tbody>. --}}
<table>
    <thead>
        @include('articulos-ia._titulos')
    </thead>
    @include('articulos-ia._filas')
</table>

@include('articulos-ia._paginacion')
