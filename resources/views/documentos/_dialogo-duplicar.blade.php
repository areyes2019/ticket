{{-- Ventana "Duplicar" de cotizaciones (POST: crea la copia) y facturas (GET:
     abre el formulario lleno). Elige el cliente de la copia, con el original
     preseleccionado; si ya no está entre los activos, queda sin elegir.
     Parámetros: $titulo, $accion, $metodo, $clientes, $clienteActual y,
     opcionales, $ocultos (name => value) y $ayuda. --}}
@php
    $ocultos ??= [];
    $conErrores = $errors->duplicar->any();
@endphp

<dialog id="dialogo-duplicar" class="ficha dialogo" aria-labelledby="dialogo-duplicar-titulo" @if ($conErrores) data-abrir-al-cargar @endif>
    <form method="{{ $metodo }}" action="{{ $accion }}">
        @if ($metodo === 'POST')
            @csrf
        @endif
        @foreach ($ocultos as $nombre => $valor)
            <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
        @endforeach

        <h2 id="dialogo-duplicar-titulo">{{ $titulo }}</h2>

        @if ($conErrores)
            <x-alerta tipo="error">{{ $errors->duplicar->first() }}</x-alerta>
        @endif

        <x-campo nombre="cliente_id" id="duplicar-cliente" etiqueta="Cliente de la copia" tipo="select" :opciones="$clientes"
            :valor="array_key_exists($clienteActual, $clientes) ? $clienteActual : null" vacia="Selecciona un cliente" required data-buscable="Buscar cliente por nombre…"
            :ayuda="$ayuda ?? null" />

        <div class="acciones">
            <x-boton icono="copy" data-enviar-una-vez>Duplicar</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>

@push('scripts')
    <script src="{{ asset('js/select-buscable.js') }}?v={{ filemtime(public_path('js/select-buscable.js')) }}"></script>
@endpush
