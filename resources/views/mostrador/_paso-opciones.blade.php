{{-- Pantalla de opción de la factura del mostrador (033): uso de CFDI y forma
     de pago son esta misma pantalla con otra lista. Blade pinta la lista
     entera desde el enum; el buscador filtra en el navegador. --}}
<section class="mostrador-paso" data-paso="{{ $paso }}" data-titulo="{{ $titulo }}" hidden>
    <x-campo nombre="buscar_{{ $paso }}" etiqueta="{{ $buscar }}" tipo="search" placeholder="Clave o descripción" autocomplete="off" data-filtrar-opciones="{{ $paso }}" />
    <div class="mostrador-opciones" data-opciones="{{ $paso }}">
        @foreach ($opciones as $clave => $descripcion)
            <a href="#" class="mostrador-ficha mostrador-opcion" data-opcion="{{ $clave }}" data-texto="{{ $clave }} – {{ $descripcion }}">
                <strong class="mostrador-opcion-clave">{{ $clave }}</strong>
                <span>{{ $descripcion }}</span>
                <x-icono nombre="check-lg" class="mostrador-opcion-palomita" />
            </a>
        @endforeach
        <p class="mostrador-vacio" data-opciones-vacio hidden>Sin coincidencias.</p>
    </div>
</section>
