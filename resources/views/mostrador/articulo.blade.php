@extends('layouts.mostrador')

@php
    $precio = '$'.number_format($articulo->precio_unitario_con_iva, 2);
    $conIva = $articulo->objeto_imp === App\Enums\ObjetoImpuesto::SiObjeto;
@endphp

@section('title', $articulo->nombre.' · Mostrador')
@section('barra', 'catalogo')

@section('content')
    {{--
        Ficha del catálogo en el mostrador (034): la pantalla que se le voltea al
        cliente. Foto en grande, nombre, modelo y precio con IVA. Nunca costo,
        precio de proveedor, utilidad, existencias ni precio distribuidor: todo
        lo que esté aquí es información que se le dio. "Compartir" manda la foto
        en JPEG (WhatsApp trata los .webp como calcomanías) y el texto de la
        ficha del escritorio; sin foto, solo el texto. Nada se edita.
    --}}
    <div class="mostrador-detalle mostrador-articulo">
        <x-boton :href="route('mostrador.catalogo')" variante="suave" icono="chevron-left" data-volver-lista>Catálogo</x-boton>

        {{-- El marcador ocupa el mismo espacio que la foto: la ficha no cambia de forma. --}}
        <div class="mostrador-articulo-foto">
            @if ($articulo->tiene_imagen)
                <img src="{{ route('articulos.imagen', [$articulo, 'v' => $articulo->imagen_version]) }}" alt="{{ $articulo->nombre }}" data-ficha-imagen>
            @else
                <p class="ficha-sin-imagen"><x-icono nombre="image" />Sin imagen</p>
            @endif
        </div>

        <div class="mostrador-articulo-datos">
            <h1 class="mostrador-articulo-nombre">{{ $articulo->nombre }}</h1>
            <p>Modelo <strong>{{ $articulo->modelo }}</strong></p>
            <p class="mostrador-articulo-precio">{{ $precio }}</p>
            <p class="mostrador-ficha-dato">{{ $conIva ? 'Precio con IVA' : 'Precio' }}</p>
        </div>

        <x-alerta tipo="error" hidden data-ficha-error></x-alerta>
        {{-- Respaldo cuando el navegador no permite compartir ni copiar. --}}
        <div hidden data-ficha-copiar>
            <x-campo nombre="ficha_texto" etiqueta="Copia el texto" readonly />
        </div>
        <p class="ficha-aviso" role="status" data-ficha-aviso></p>

        <div class="mostrador-pie">
            <x-boton tipo="button" icono="share" bloque
                data-compartir-ficha
                data-texto="{{ $articulo->nombre }} — Modelo {{ $articulo->modelo }} — {{ $precio }}"
                data-archivo="{{ $articulo->modelo }}">Compartir</x-boton>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/imagen-compartible.js') }}?v={{ filemtime(public_path('js/imagen-compartible.js')) }}"></script>
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
