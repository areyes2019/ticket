{{-- Umbrales de reposición. No mueve piezas. Parámetros: $articulo, $fila. --}}
<dialog id="dialogo-parametros" class="ficha dialogo" aria-labelledby="dialogo-parametros-titulo" @if ($errors->parametros->any()) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ route('existencias.parametros', $articulo) }}">
        @csrf
        @method('PUT')
        <h2 id="dialogo-parametros-titulo">Mínimo y máximo de {{ $articulo->modelo }}</h2>

        @include('existencias._errores-dialogo', ['bolsa' => 'parametros'])

        <x-campo nombre="minimo" id="parametros-minimo" etiqueta="Mínimo" tipo="number" min="0" :max="App\Http\Requests\CotizacionRequest::MAX_CANTIDAD" step="1" inputmode="numeric" :valor="$fila->minimo" required
            ayuda="Con menos piezas que esto, el artículo queda por pedir. 0 = no avisar." />
        <x-campo nombre="maximo" id="parametros-maximo" etiqueta="Máximo (opcional)" tipo="number" min="0" :max="App\Http\Requests\CotizacionRequest::MAX_CANTIDAD" step="1" inputmode="numeric" :valor="$fila->maximo"
            ayuda="Hasta dónde rellenar al pedir. Sin máximo, se rellena hasta el mínimo." />

        <div class="acciones">
            <x-boton icono="save" data-enviar-una-vez>Guardar</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
