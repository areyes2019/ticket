{{-- Respuesta de la búsqueda dinámica: el <table> solo sirve para que el navegador interprete el <tr> y el <tbody>. --}}
@include('existencias._totales')
@include('existencias._contador')

<table>
    <thead>
        @include('existencias._titulos')
    </thead>
    @include('existencias._filas')
</table>

@include('existencias._paginacion')
@include('existencias._orden')
