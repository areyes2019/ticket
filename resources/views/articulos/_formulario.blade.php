@include('articulos._mensajes')

@if ($catalogos === [])
    <x-alerta tipo="advertencia">
        Para registrar artículos primero necesitas un catálogo.
        <a href="{{ route('catalogos.create') }}">Registrar un catálogo</a>
    </x-alerta>
@else
    @push('scripts')
        <script src="{{ asset('js/autocompletar.js') }}"></script>
        <script src="{{ asset('js/precio-con-iva.js') }}"></script>
        <script src="{{ asset('js/precio-con-descuento.js') }}"></script>
    @endpush

    <form method="POST" action="{{ $accion }}">
        @csrf
        @isset($articulo)
            @method('PUT')
        @endisset

        <x-card>
            <x-campo nombre="catalogo_id" etiqueta="Catálogo" tipo="select" :opciones="$catalogos" :valor="$articulo?->catalogo_id" vacia="Selecciona un catálogo" required
                ayuda="Proveedor — Catálogo (descuento). El proveedor del artículo es el del catálogo." />
            <x-campo nombre="nombre" etiqueta="Nombre" :valor="$articulo?->nombre" maxlength="255" required />
            <x-campo nombre="modelo" etiqueta="Modelo" :valor="$articulo?->modelo" maxlength="255" required />
            <x-campo nombre="clave_prod_serv" etiqueta="Clave de producto/servicio (SAT)" :valor="$articulo?->clave_prod_serv" maxlength="8" autocomplete="off" required
                :ayuda="$descripcionProdServ ?? 'Escribe la clave o parte de la descripción.'"
                data-autocompletar="{{ route('catalogos-sat.claves-prod-serv') }}" />
            <x-campo nombre="clave_unidad" etiqueta="Clave de unidad (SAT)" :valor="$articulo?->clave_unidad" maxlength="3" autocomplete="off" required
                :ayuda="$descripcionUnidad ?? 'Escribe la clave o el nombre de la unidad, por ejemplo H87 o pieza.'"
                data-autocompletar="{{ route('catalogos-sat.claves-unidad') }}" />
            <x-campo nombre="objeto_imp" etiqueta="Objeto de impuesto" tipo="select" :opciones="$objetosImpuesto" :valor="$articulo?->objeto_imp?->value" vacia="Selecciona un objeto de impuesto" required />
            <x-campo nombre="precio_unitario_sin_iva" etiqueta="Precio unitario sin IVA" tipo="number" step="0.01" min="0.01" inputmode="decimal" :valor="$articulo?->precio_unitario_sin_iva" required />
            <p class="precio-con-iva">
                Precio con IVA ({{ App\Models\Articulo::TASA_IVA * 100 }}%):
                <output for="precio_unitario_sin_iva" data-precio-con-iva data-tasa-iva="{{ App\Models\Articulo::TASA_IVA }}">{{ $articulo ? '$'.number_format($articulo->precio_unitario_con_iva, 2) : '—' }}</output>
            </p>
            <p class="precio-con-iva">
                Precio con descuento del catálogo (sin IVA):
                <output for="precio_unitario_sin_iva catalogo_id" data-precio-con-descuento data-precio="precio_unitario_sin_iva" data-catalogo="catalogo_id"
                    data-descuentos="{{ json_encode($descuentos, JSON_FORCE_OBJECT) }}">{{ $articulo ? '$'.number_format((float) $articulo->precio_con_descuento, 2) : '—' }}</output>
            </p>
        </x-card>

        <div class="acciones">
            <x-boton icono="save">Guardar</x-boton>
            <x-boton :href="route('articulos.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
        </div>
    </form>
@endif
