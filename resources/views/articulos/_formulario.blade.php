@include('articulos._mensajes')

@if ($catalogos === [])
    <x-alerta tipo="advertencia">
        Para registrar artículos primero necesitas un catálogo.
        <a href="{{ route('catalogos.create') }}">Registrar un catálogo</a>
    </x-alerta>
@else
    @push('scripts')
        <script src="{{ asset('js/autocompletar.js') }}"></script>
        <script src="{{ asset('js/precio-articulo.js') }}"></script>
    @endpush

    <form method="POST" action="{{ $accion }}" enctype="multipart/form-data">
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
            <x-campo nombre="precio_proveedor" etiqueta="Precio de lista del proveedor (sin IVA)" tipo="number" step="0.01" min="0.01" max="9000000" inputmode="decimal"
                :valor="$articulo?->precio_proveedor" required ayuda="Antes del descuento del catálogo." />
            <x-campo nombre="utilidad_porcentaje" etiqueta="Utilidad (%)" tipo="number" step="0.01" min="0" max="999.99" inputmode="decimal"
                :valor="$articulo?->utilidad_porcentaje" :placeholder="$placeholderUtilidad" ayuda="Déjalo vacío para usar la del catálogo."
                data-aviso-utilidad="utilidad_porcentaje-aviso" data-umbral="{{ App\Models\Articulo::UMBRAL_UTILIDAD_ALTA }}" />
            @include('articulos._aviso-utilidad', ['campo' => 'utilidad_porcentaje', 'valor' => $articulo?->utilidad_porcentaje])

            {{-- Cadena de cálculo. En edición la pinta el servidor con lo guardado; precio-articulo.js la recalcula en vivo. --}}
            <dl class="resumen-precio" data-resumen-precio data-precio="precio_proveedor" data-utilidad="utilidad_porcentaje" data-catalogo="catalogo_id"
                data-tasa-iva="{{ App\Models\Articulo::TASA_IVA }}" data-catalogos="{{ json_encode($preciosCatalogo, JSON_FORCE_OBJECT) }}">
                @foreach ($resumen as $renglon)
                    <div @class(['resumen-total' => $renglon['total'] ?? false])>
                        <dt>{{ $renglon['etiqueta'] }}@isset($renglon['porcentaje']) (<span data-porcentaje="{{ $renglon['clave'] }}">{{ $renglon['porcentaje'] }}</span>)@endisset</dt>
                        <dd><output data-valor="{{ $renglon['clave'] }}">{{ $renglon['valor'] }}</output></dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        <x-card titulo="Imagen">
            @if ($articulo?->tiene_imagen)
                <img class="imagen-articulo" src="{{ route('articulos.imagen', [$articulo, 'v' => $articulo->imagen_version]) }}" alt="Imagen de {{ $articulo->nombre }}">
                <x-campo nombre="quitar_imagen" etiqueta="Quitar imagen" tipo="checkbox" />
            @endif
            <x-campo nombre="imagen" etiqueta="{{ $articulo?->tiene_imagen ? 'Reemplazar imagen' : 'Imagen' }}" tipo="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                ayuda="JPG, PNG o WEBP de hasta 10 MB. Se guarda reducida a 1200 puntos de lado largo." />
        </x-card>

        <div class="acciones">
            <x-boton icono="save">Guardar</x-boton>
            <x-boton :href="route('articulos.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
        </div>
    </form>
@endif
