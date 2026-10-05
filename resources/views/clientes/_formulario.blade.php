@include('clientes._mensajes')

@include('clientes._constancia')

@push('scripts')
    <script src="{{ asset('js/constancia-fiscal.js') }}?v={{ filemtime(public_path('js/constancia-fiscal.js')) }}"></script>
@endpush

<form method="POST" action="{{ $accion }}">
    @csrf
    @isset($cliente)
        @method('PUT')
    @endisset

    <x-card titulo="Datos fiscales">
        <x-campo nombre="rfc" etiqueta="RFC" :valor="$cliente->rfc ?? null" maxlength="13" required autofocus ayuda="12 caracteres para persona moral, 13 para persona física." />
        <x-campo nombre="razon_social" etiqueta="Razón social" :valor="$cliente->razon_social ?? null" required ayuda="Tal como aparece en la Constancia de Situación Fiscal." />
        <x-campo nombre="regimen_fiscal" etiqueta="Régimen fiscal" tipo="select" :opciones="App\Enums\RegimenFiscal::opciones()" :valor="isset($cliente) ? $cliente->regimen_fiscal->value : null" vacia="Selecciona un régimen fiscal" required />
        <x-campo nombre="codigo_postal_fiscal" etiqueta="Código postal fiscal" :valor="$cliente->codigo_postal_fiscal ?? null" maxlength="5" inputmode="numeric" required />
    </x-card>

    <x-card titulo="Datos comerciales">
        <x-campo nombre="nombre_comercial" etiqueta="Nombre comercial" :valor="$cliente->nombre_comercial ?? null" />
        <x-campo nombre="nombre_contacto" etiqueta="Nombre de contacto" :valor="$cliente->nombre_contacto ?? null" />
        <x-campo nombre="correo" etiqueta="Correo" tipo="email" :valor="$cliente->correo ?? null" />
        <x-campo nombre="telefono" etiqueta="Teléfono" tipo="tel" :valor="$cliente->telefono ?? null" ayuda="10 dígitos; se guarda con el prefijo +52." />
        <x-campo nombre="direccion_comercial" etiqueta="Dirección comercial" :valor="$cliente->direccion_comercial ?? null" />
        <x-campo nombre="descuento_permanente" etiqueta="Descuento permanente (%)" tipo="number" :valor="isset($cliente) ? App\Models\Cliente::porcentajeTexto($cliente->descuento_permanente) : 0" min="0" max="{{ App\Models\Cliente::DESCUENTO_MAXIMO }}" step="0.01" inputmode="decimal" ayuda="Se aplicará automáticamente a cada línea de las cotizaciones de este cliente. Máximo {{ App\Models\Cliente::DESCUENTO_MAXIMO }}%." />
        {{-- Tras un error de validación manda lo enviado: una casilla sin marcar no viaja y old() la daría por ausente. --}}
        <x-campo nombre="es_distribuidor" etiqueta="Es distribuidor" tipo="checkbox" :valor="$errors->any() ? false : ($cliente->es_distribuidor ?? false)" aria-describedby="es_distribuidor-ayuda" />
        <p id="es_distribuidor-ayuda" class="ayuda">Sus cotizaciones y facturas usarán el precio distribuidor de cada artículo en vez del precio de lista.</p>
    </x-card>

    <div class="acciones">
        <x-boton icono="save">Guardar</x-boton>
        <x-boton :href="route('clientes.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>
