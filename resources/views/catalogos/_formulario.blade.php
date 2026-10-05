@include('catalogos._mensajes')

@if (! isset($catalogo) && $proveedores === [])
    <x-alerta tipo="advertencia">
        Para registrar catálogos primero necesitas un proveedor.
        <a href="{{ route('proveedores.create') }}">Registrar un proveedor</a>
    </x-alerta>
@else
    @push('scripts')
        <script src="{{ asset('js/precio-articulo.js') }}"></script>
    @endpush

    <form method="POST" action="{{ $accion }}">
        @csrf
        @isset($catalogo)
            @method('PUT')
        @endisset

        @isset($catalogo)
            {{-- El proveedor es fijo desde el alta: se muestra, pero no se envía. --}}
            <x-campo nombre="proveedor" etiqueta="Proveedor" :valor="$catalogo->proveedor->nombre_comercial" disabled
                ayuda="El proveedor no se puede cambiar." />
        @else
            <x-campo nombre="proveedor_id" etiqueta="Proveedor" tipo="select" :opciones="$proveedores" vacia="Selecciona un proveedor" required autofocus />
        @endisset

        <x-campo nombre="nombre" etiqueta="Nombre" :valor="$catalogo->nombre ?? null" maxlength="255" required />
        <x-campo nombre="descuento" etiqueta="Descuento (%)" tipo="number" step="0.01" min="0" max="100" inputmode="decimal"
            :valor="$catalogo->descuento ?? 0" ayuda="Descuento que te da el proveedor sobre su precio de lista. Déjalo en 0 si no tiene descuento." />
        <x-campo nombre="utilidad_porcentaje" etiqueta="Utilidad (%)" tipo="number" step="0.01" min="0" max="999.99" inputmode="decimal"
            :valor="$catalogo->utilidad_porcentaje ?? 0" ayuda="Markup sobre el costo que heredan los artículos sin utilidad propia."
            data-aviso-utilidad="utilidad_porcentaje-aviso" data-umbral="{{ App\Models\Articulo::UMBRAL_UTILIDAD_ALTA }}" />
        @include('articulos._aviso-utilidad', ['campo' => 'utilidad_porcentaje', 'valor' => $catalogo->utilidad_porcentaje ?? 0])
        <x-campo nombre="utilidad_distribuidor_porcentaje" etiqueta="Utilidad distribuidor (%)" tipo="number" step="0.01" min="0" max="999.99" inputmode="decimal"
            :valor="$catalogo->utilidad_distribuidor_porcentaje ?? 0" ayuda="Markup sobre el costo para el precio distribuidor; lo heredan los artículos sin utilidad distribuidor propia."
            data-aviso-utilidad="utilidad_distribuidor_porcentaje-aviso" data-umbral="{{ App\Models\Articulo::UMBRAL_UTILIDAD_ALTA }}" />
        @include('articulos._aviso-utilidad', ['campo' => 'utilidad_distribuidor_porcentaje', 'valor' => $catalogo->utilidad_distribuidor_porcentaje ?? 0])

        @if (session('confirmar_recalculo'))
            {{-- Paso de confirmación: nada se guardó todavía; el formulario conserva lo capturado. --}}
            <x-alerta tipo="advertencia">
                <p>
                    Se recalculará el precio de venta o el precio distribuidor de <strong>{{ session('confirmar_recalculo') }}</strong>
                    {{ session('confirmar_recalculo') === 1 ? 'artículo' : 'artículos' }}.
                </p>
                <div class="acciones">
                    <x-boton icono="check-lg" name="confirmar" value="1">Confirmar y guardar</x-boton>
                    <x-boton :href="route('catalogos.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
                </div>
            </x-alerta>
        @else
            <div class="acciones">
                <x-boton icono="save">Guardar</x-boton>
                <x-boton :href="route('catalogos.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
            </div>
        @endif
    </form>
@endif
