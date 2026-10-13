@include('articulos._mensajes')

@if ($catalogos === [])
    <x-alerta tipo="advertencia">
        Para registrar artículos primero necesitas un catálogo.
        <a href="{{ route('catalogos.create') }}">Registrar un catálogo</a>
    </x-alerta>
@else
    @push('scripts')
        <script src="{{ asset('js/autocompletar.js') }}?v={{ filemtime(public_path('js/autocompletar.js')) }}"></script>
        <script src="{{ asset('js/precio-articulo.js') }}?v={{ filemtime(public_path('js/precio-articulo.js')) }}"></script>
    @endpush

    <form method="POST" action="{{ $accion }}" enctype="multipart/form-data">
        @csrf
        @isset($articulo)
            @method('PUT')
        @endisset

        <x-card>
            <x-campo nombre="catalogo_id" etiqueta="Catálogo" tipo="select" :opciones="$catalogos" :valor="$articulo?->catalogo_id" vacia="Selecciona un catálogo" required
                ayuda="Proveedor — Catálogo (descuento). El proveedor del artículo es el del catálogo." />
            @if ($articulo)
                {{-- Solo lectura: se cambia en el catálogo (029). --}}
                <p class="ayuda" data-tipo-articulo>{{ $articulo->requiereProduccion() ? 'Producción' : 'Suministro' }} (por su catálogo)</p>
            @endif
            <x-campo nombre="nombre" etiqueta="Nombre" :valor="$articulo?->nombre" maxlength="255" required />
            <x-campo nombre="modelo" etiqueta="Modelo" :valor="$articulo?->modelo" maxlength="255" required />
            <x-campo nombre="clave_prod_serv" etiqueta="Clave de producto/servicio (SAT)" :valor="$articulo?->clave_prod_serv" maxlength="8" autocomplete="off" required
                :ayuda="$descripcionProdServ ?? 'Escribe la clave o parte de la descripción.'"
                data-autocompletar="{{ route('catalogos-sat.claves-prod-serv') }}" />
            <x-campo nombre="clave_unidad" etiqueta="Clave de unidad (SAT)" :valor="$articulo?->clave_unidad" maxlength="3" autocomplete="off" required
                :ayuda="$descripcionUnidad ?? 'Escribe la clave o el nombre de la unidad, por ejemplo H87 o pieza.'"
                data-autocompletar="{{ route('catalogos-sat.claves-unidad') }}" />
            <x-campo nombre="objeto_imp" etiqueta="Objeto de impuesto" tipo="select" :opciones="$objetosImpuesto" :valor="$articulo?->objeto_imp?->value" vacia="Selecciona un objeto de impuesto" required />
            <x-campo nombre="precio_proveedor" etiqueta="Precio de lista del proveedor (sin IVA)" tipo="number" step="0.01" min="0.01" max="900000" inputmode="decimal"
                :valor="$articulo?->precio_proveedor" required ayuda="Antes del descuento del catálogo." />
            {{-- 037: el precio de venta sale de la utilidad o de un precio escrito a mano. precio-articulo.js oculta
                 el bloque del otro modo, pero sus campos se siguen enviando: la utilidad propia se conserva. --}}
            <x-campo nombre="modo_precio" etiqueta="Precio de venta" tipo="radio" :opciones="['utilidad' => 'Por utilidad (%)', 'fijo' => 'Precio fijo']" :valor="$modoPrecio" />

            <div data-bloque-modo="utilidad">
                <x-campo nombre="utilidad_porcentaje" etiqueta="Utilidad (%)" tipo="number" step="0.01" min="0" max="9999.99" inputmode="decimal"
                    :valor="$articulo?->utilidad_porcentaje" :placeholder="$placeholderUtilidad" ayuda="Déjalo vacío para usar la del catálogo."
                    data-aviso-utilidad="utilidad_porcentaje-aviso" data-umbral="{{ App\Models\Articulo::UMBRAL_UTILIDAD_ALTA }}" />
                @include('articulos._aviso-utilidad', ['campo' => 'utilidad_porcentaje', 'valor' => $articulo?->utilidad_porcentaje])
            </div>

            <div data-bloque-modo="fijo">
                <x-campo nombre="precio_fijo" :etiqueta="old('objeto_imp', $articulo?->objeto_imp?->value ?? '02') === '02' ? 'Precio fijo con IVA' : 'Precio fijo'"
                    tipo="number" step="0.01" min="0.01" max="{{ App\Models\Articulo::PRECIO_FIJO_MAXIMO }}" inputmode="decimal"
                    :valor="$articulo?->precio_fijo" ayuda="El precio que paga el cliente." />
                @if ($articulo?->precio_fijo_desde)
                    <p class="ayuda" data-precio-fijo-desde>Precio fijo desde el {{ $articulo->precio_fijo_desde->format('d/m/Y') }}</p>
                @endif
                {{-- Avisos no bloqueantes; los muestra precio-articulo.js. Sin JavaScript, el de costo lo pinta el servidor con lo guardado. --}}
                <p id="precio_fijo-aviso-costo" class="aviso-utilidad" @if (! $articulo?->por_debajo_del_costo) hidden @endif>
                    <x-icono nombre="exclamation-triangle" />
                    Este precio queda <span data-monto>${{ number_format(max(0, (float) $articulo?->costo_con_descuento - (float) $articulo?->precio_unitario_sin_iva), 2) }}</span> por debajo del costo.
                </p>
                <p id="precio_fijo-aviso-centavo" class="aviso-utilidad" hidden>
                    <x-icono nombre="exclamation-triangle" />
                    Con IVA de {{ App\Models\Articulo::TASA_IVA * 100 }}%, <span data-capturado></span> no se puede alcanzar exacto; el precio quedará en <span data-final></span>.
                </p>
            </div>

            @include('articulos._resumen-precio', ['renglones' => $resumen, 'utilidad' => 'utilidad_porcentaje', 'herencia' => 'utilidad', 'titulo' => 'Cadena del precio de venta', 'fijo' => 'precio_fijo'])
        </x-card>

        {{-- Segundo precio, para los clientes distribuidores: mismo costo, su propia utilidad. --}}
        <x-card titulo="Precio distribuidor">
            <x-campo nombre="utilidad_distribuidor_porcentaje" etiqueta="Utilidad distribuidor (%)" tipo="number" step="0.01" min="0" max="9999.99" inputmode="decimal"
                :valor="$articulo?->utilidad_distribuidor_porcentaje" :placeholder="$placeholderUtilidadDistribuidor" ayuda="Déjalo vacío para usar la del catálogo."
                data-aviso-utilidad="utilidad_distribuidor_porcentaje-aviso" data-umbral="{{ App\Models\Articulo::UMBRAL_UTILIDAD_ALTA }}" />
            @include('articulos._aviso-utilidad', ['campo' => 'utilidad_distribuidor_porcentaje', 'valor' => $articulo?->utilidad_distribuidor_porcentaje])

            @include('articulos._resumen-precio', ['renglones' => $resumenDistribuidor, 'utilidad' => 'utilidad_distribuidor_porcentaje', 'herencia' => 'utilidad_distribuidor', 'titulo' => 'Cadena del precio distribuidor'])
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
