@include('proveedores._mensajes')

<form method="POST" action="{{ $accion }}">
    @csrf
    @isset($proveedor)
        @method('PUT')
    @endisset

    <x-campo nombre="nombre_comercial" etiqueta="Nombre comercial" :valor="$proveedor->nombre_comercial ?? null" required autofocus />
    <x-campo nombre="nombre_contacto" etiqueta="Nombre de contacto" :valor="$proveedor->nombre_contacto ?? null" />
    <x-campo nombre="correo" etiqueta="Correo" tipo="email" :valor="$proveedor->correo ?? null" />
    <x-campo nombre="telefono" etiqueta="Teléfono" tipo="tel" :valor="$proveedor->telefono ?? null" ayuda="10 dígitos; se guarda con el prefijo +52." />
    <x-campo nombre="rfc" etiqueta="RFC" :valor="$proveedor->rfc ?? null" maxlength="13" />

    <div class="acciones">
        <x-boton icono="save">Guardar</x-boton>
        <x-boton :href="route('proveedores.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>
