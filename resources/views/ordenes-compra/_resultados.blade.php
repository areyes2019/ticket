{{-- Respuesta de la búsqueda dinámica: el <table> solo sirve para que el navegador interprete el <tbody>. --}}
<table>
    @include('ordenes-compra._filas')
</table>

@include('ordenes-compra._paginacion')
@include('ordenes-compra._atajos')
