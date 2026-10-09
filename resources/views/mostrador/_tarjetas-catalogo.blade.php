{{-- Una página del catálogo de la consulta del mostrador (034). Las mismas
     fichas del paso de artículos, pero tocar una abre su ficha: no suma a
     ningún carrito. El precio va con IVA, el que se le dice al cliente. --}}
@foreach ($elementos as $articulo)
    <a href="{{ route('mostrador.catalogo.ver', $articulo) }}" class="mostrador-ficha mostrador-ficha-articulo">
        <span class="mostrador-ficha-imagen">
            @if ($articulo->tiene_imagen)
                <img src="{{ route('articulos.imagen', [$articulo, 'v' => $articulo->imagen_version]) }}" alt="" loading="lazy">
            @else
                <x-icono nombre="image" />
            @endif
        </span>
        <strong class="mostrador-ficha-titulo">{{ $articulo->nombre }}</strong>
        <span class="mostrador-ficha-dato">{{ $articulo->modelo }}</span>
        <span class="mostrador-ficha-precio">${{ number_format($articulo->precio_unitario_con_iva, 2) }}@if ($articulo->objeto_imp === App\Enums\ObjetoImpuesto::SiObjeto) <small>con IVA</small>@endif</span>
    </a>
@endforeach

@include('mostrador._siguiente', ['icono' => 'box-seam', 'vacio' => 'Sin artículos con esa búsqueda.'])
