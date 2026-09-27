{{-- Respuesta de la búsqueda dinámica: el <table> solo sirve para que el navegador interprete el <tbody>. --}}
<table>
    @include('cotizaciones._filas')
</table>

@include('cotizaciones._paginacion')
@include('cotizaciones._atajos')
