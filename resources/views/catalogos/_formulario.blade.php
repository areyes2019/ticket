@include('catalogos._mensajes')

@if (! isset($catalogo) && $proveedores === [])
    <x-alerta tipo="advertencia">
        Para registrar catálogos primero necesitas un proveedor.
        <a href="{{ route('proveedores.create') }}">Registrar un proveedor</a>
    </x-alerta>
@else
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
            :valor="$catalogo->descuento ?? 0" ayuda="Se aplica a todos los artículos del catálogo. Déjalo en 0 si no tiene descuento." />

        <div class="acciones">
            <x-boton icono="save">Guardar</x-boton>
            <x-boton :href="route('catalogos.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
        </div>
    </form>
@endif
