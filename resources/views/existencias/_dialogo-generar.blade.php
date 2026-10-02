{{-- Resumen de lo que creará "Generar órdenes de compra", antes de confirmar. Parámetro: $plan. --}}
@php
    $ordenes = count($plan['grupos']);
@endphp

<dialog id="dialogo-generar" class="ficha dialogo" aria-labelledby="dialogo-generar-titulo">
    <form method="POST" action="{{ route('existencias.generar-ordenes-compra') }}">
        @csrf
        <h2 id="dialogo-generar-titulo">Generar órdenes de compra</h2>

        <p>Se {{ $ordenes === 1 ? 'creará 1 orden' : "crearán {$ordenes} órdenes" }} en borrador, con la cantidad sugerida de cada artículo por pedir:</p>
        <ul>
            @foreach ($plan['grupos'] as $grupo)
                <li>{{ $grupo['proveedor']->nombre_comercial }}: {{ count($grupo['existencias']) }} {{ count($grupo['existencias']) === 1 ? 'artículo' : 'artículos' }}</li>
            @endforeach
        </ul>

        @if ($plan['omitidos'] !== [])
            <x-alerta tipo="advertencia">
                <p>Se omitirán:</p>
                <ul>
                    @foreach ($plan['omitidos'] as $omitido)
                        <li>{{ $omitido['articulo']->modelo }} ({{ $omitido['motivo'] }})</li>
                    @endforeach
                </ul>
            </x-alerta>
        @endif

        <p class="ayuda">No se envía nada al proveedor: revisa y envía cada orden a mano.</p>

        <div class="acciones">
            <x-boton icono="cart-plus" data-enviar-una-vez>Generar</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
