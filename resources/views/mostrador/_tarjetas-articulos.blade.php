{{-- Una página del catálogo para la captura del mostrador (033). Los precios
     son los mismos que articulos.sugerencias da a una línea; tocar la ficha suma
     una unidad. --}}
@foreach ($articulos as $articulo)
    <a href="#" class="mostrador-ficha mostrador-ficha-articulo" data-articulo="{{ json_encode([
        'id' => $articulo->id,
        'nombre' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => $articulo->precio_unitario_sin_iva,
        'precio_distribuidor' => $articulo->precio_distribuidor_sin_iva,
        'tasa_iva' => $articulo->objeto_imp === App\Enums\ObjetoImpuesto::SiObjeto ? App\Enums\TasaIva::Dieciseis->value : App\Enums\TasaIva::Exento->value,
    ]) }}">
        <span class="mostrador-ficha-imagen">
            @if ($articulo->tiene_imagen)
                <img src="{{ route('articulos.imagen', [$articulo, 'v' => $articulo->imagen_version]) }}" alt="" loading="lazy">
            @else
                <x-icono nombre="image" />
            @endif
        </span>
        <span class="mostrador-ficha-cantidad" data-cantidad-ficha hidden></span>
        <strong class="mostrador-ficha-titulo">{{ $articulo->nombre }}</strong>
        <span class="mostrador-ficha-dato">{{ $articulo->modelo }}</span>
        <span class="mostrador-ficha-precio">${{ number_format((float) $articulo->precio_unitario_sin_iva, 2) }} <small>sin IVA</small></span>
    </a>
@endforeach

@if ($articulos->isEmpty() && $articulos->onFirstPage())
    <p class="mostrador-vacio"><x-icono nombre="box-seam" />Sin artículos con esa búsqueda.</p>
@endif

@if ($articulos->hasMorePages())
    <div class="mostrador-cargando" data-siguiente="{{ $articulos->nextPageUrl() }}">Cargando…</div>
@endif
