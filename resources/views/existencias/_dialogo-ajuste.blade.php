{{-- Ajuste manual o alta en existencias: la cantidad final que hay. Sin fila
     (alta) se abre al cargar la ficha; con errores de su bolsa, también.
     Parámetros: $articulo, $fila (o null), $motivos. --}}
@php
    $esAlta = $fila === null;
@endphp

<dialog id="dialogo-ajuste" class="ficha dialogo" aria-labelledby="dialogo-ajuste-titulo" @if ($esAlta || $errors->ajuste->any()) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ route('existencias.ajuste', $articulo) }}">
        @csrf
        <h2 id="dialogo-ajuste-titulo">{{ $esAlta ? 'Pasar '.$articulo->modelo.' a existencias' : 'Ajustar '.$articulo->modelo }}</h2>

        @include('existencias._errores-dialogo', ['bolsa' => 'ajuste'])

        <x-campo nombre="cantidad" id="ajuste-cantidad" etiqueta="¿Cuántas piezas hay?" tipo="number" min="0" :max="App\Http\Requests\CotizacionRequest::MAX_CANTIDAD" step="1" inputmode="numeric" :valor="$fila?->existencia" required
            ayuda="La cantidad final que cuentas, no la diferencia.{{ $fila?->faltante_pendiente > 0 ? ' El faltante pendiente se pondrá en cero.' : '' }}" />
        <x-campo nombre="motivo" id="ajuste-motivo" etiqueta="Motivo" tipo="select" :opciones="$motivos" :vacia="false"
            :valor="$esAlta ? App\Enums\MotivoMovimientoInventario::EntradaInicial->value : App\Enums\MotivoMovimientoInventario::ConteoFisico->value" required />
        <x-campo nombre="nota" id="ajuste-nota" etiqueta="Nota (opcional)" tipo="textarea" maxlength="500" rows="2" />

        <div class="acciones">
            <x-boton icono="save" data-enviar-una-vez>{{ $esAlta ? 'Pasar a existencias' : 'Guardar ajuste' }}</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
