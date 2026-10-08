@extends('layouts.mostrador')

@php
    $acciones = [
        'venta' => route('pedidos.store'),
        'cotizacion' => route('cotizaciones.store'),
        'factura' => route('facturas.store'),
    ];
    $titulos = [
        'venta' => 'Venta al público',
        'cotizacion' => 'Cotización',
        'factura' => 'Factura',
    ];
    $pasos = match ($flujo) {
        'venta' => ['cliente' => 'Cliente', 'articulos' => 'Artículos', 'carrito' => 'Carrito'],
        'cotizacion' => ['cliente' => 'Cliente', 'articulos' => 'Artículos', 'carrito' => 'Carrito'],
        'factura' => ['cliente' => 'Cliente', 'articulos' => 'Artículos', 'carrito' => 'Carrito', 'uso' => 'Uso de CFDI', 'forma' => 'Forma de pago', 'metodo' => 'Método de pago', 'revisar' => 'Revisar'],
    };
    $botonFinal = ['venta' => 'Guardar y cobrar', 'cotizacion' => 'Guardar cotización', 'factura' => 'Continuar'][$flujo];
@endphp

@section('title', $titulos[$flujo].' · Mostrador')

@section('content')
    {{--
        Captura por pasos (033). Todo vive en este formulario: mostrador.js
        muestra un paso a la vez y, al enviar, escribe las líneas como campos
        ocultos. Se envía a la ruta de alta de siempre con origen=mostrador.
    --}}
    {{-- novalidate: los campos de un paso oculto no se pueden señalar; mostrador.js
         revisa el paso de cliente y el servidor valida todo al guardar. --}}
    <form method="POST" action="{{ $acciones[$flujo] }}" class="mostrador-captura" novalidate
        data-mostrador-captura
        data-flujo="{{ $flujo }}"
        data-tarjetas-clientes="{{ route('mostrador.tarjetas.clientes') }}"
        data-tarjetas-articulos="{{ route('mostrador.tarjetas.articulos') }}"
        @if ($anterior) data-anterior="{{ json_encode($anterior) }}" @endif
        @if ($cliente) data-cliente-inicial="{{ json_encode($cliente) }}" @endif
        @if ($errors->any()) data-errores="{{ json_encode($errors->getMessages()) }}" @endif>
        @csrf
        <input type="hidden" name="origen" value="mostrador">
        @if ($flujo !== 'venta')
            <input type="hidden" name="cliente_id" value="" data-campo-cliente>
        @endif
        @if ($flujo === 'factura')
            <input type="hidden" name="uso_cfdi" value="" data-campo-opcion="uso">
            <input type="hidden" name="forma_pago" value="" data-campo-opcion="forma">
            <input type="hidden" name="metodo_pago" value="" data-campo-opcion="metodo">
        @endif
        <div hidden data-campos-lineas></div>

        <p class="mostrador-indicador" aria-live="polite">
            <strong>{{ $titulos[$flujo] }}</strong>
            <span data-indicador>Paso 1 de {{ count($pasos) }} · {{ reset($pasos) }}</span>
        </p>

        {{-- Paso: cliente --}}
        <section class="mostrador-paso" data-paso="cliente" data-titulo="{{ $pasos['cliente'] }}">
            @if ($flujo === 'venta')
                <div class="mostrador-cliente-venta" data-cliente-pedido data-sugerir="{{ route('pedidos.cliente-por-telefono') }}">
                    <x-campo nombre="cliente_telefono" etiqueta="Teléfono" tipo="tel" inputmode="tel" autocomplete="off" maxlength="20" required data-telefono-cliente />
                    <x-campo nombre="cliente_nombre" etiqueta="Nombre" maxlength="150" autocomplete="off" required />
                    <x-campo nombre="cliente_correo" etiqueta="Correo (opcional)" tipo="email" maxlength="255" autocomplete="off" />
                </div>
                {{-- La llena pedido-cliente.js si el teléfono ya compró antes. --}}
                <x-alerta tipo="advertencia" hidden data-sugerencia-cliente>
                    Este teléfono compró antes como <strong data-sugerencia-nombre></strong><span data-sugerencia-correo></span>.
                    <x-boton tipo="button" variante="secundario" icono="person-check" data-usar-sugerencia>Usar esos datos</x-boton>
                </x-alerta>
                <div class="mostrador-pie">
                    <x-boton tipo="button" icono="arrow-right" bloque data-siguiente-paso>Siguiente</x-boton>
                </div>
            @else
                <div class="mostrador-acciones-cliente">
                    <x-boton :href="route('mostrador.cliente-nuevo', $flujo)" :variante="$flujo === 'factura' ? 'principal' : 'secundario'" icono="file-earmark-arrow-up" data-salir-a-alta>Subir constancia</x-boton>
                    <x-boton :href="route('mostrador.cliente-nuevo', [$flujo, 'manual' => 1])" variante="secundario" icono="person-plus" data-salir-a-alta>Nuevo cliente</x-boton>
                </div>
                <x-campo nombre="buscar_cliente" etiqueta="Buscar cliente" tipo="search" placeholder="Razón social, nombre comercial o RFC" autocomplete="off" data-buscar-lista="clientes" />
                <div class="mostrador-lista" data-lista="clientes" aria-live="polite"></div>
            @endif
        </section>

        {{-- Paso: artículos --}}
        <section class="mostrador-paso" data-paso="articulos" data-titulo="{{ $pasos['articulos'] }}" hidden>
            <p class="mostrador-cliente-elegido" data-cliente-elegido hidden></p>
            <x-campo nombre="buscar_articulo" etiqueta="Buscar artículo" tipo="search" placeholder="Nombre, modelo o proveedor" autocomplete="off" data-buscar-lista="articulos" />
            @if ($flujo === 'venta')
                <x-boton href="#dialogo-articulo-suelto" variante="secundario" icono="plus-lg" data-abrir-dialogo>Artículo suelto</x-boton>
            @endif
            <div class="mostrador-lista mostrador-lista-articulos" data-lista="articulos" aria-live="polite"></div>

            <div class="mostrador-pie">
                <span class="mostrador-pie-resumen" data-resumen-carrito>0 artículos · $0.00</span>
                <x-boton tipo="button" icono="cart" data-ir-paso="carrito" data-requiere-lineas>Ver carrito</x-boton>
            </div>
        </section>

        {{-- Paso: carrito --}}
        <section class="mostrador-paso" data-paso="carrito" data-titulo="{{ $pasos['carrito'] }}" hidden>
            <x-alerta tipo="error" hidden data-errores-generales></x-alerta>
            <ul class="mostrador-carrito" data-carrito></ul>
            <p class="mostrador-vacio" data-carrito-vacio><x-icono nombre="cart" />El carrito está vacío.</p>
            <x-boton tipo="button" variante="secundario" icono="plus-lg" data-ir-paso="articulos">Agregar más artículos</x-boton>

            <div class="mostrador-pie">
                <span class="mostrador-pie-resumen" data-resumen-carrito>0 artículos · $0.00</span>
                @if ($flujo === 'factura')
                    <x-boton tipo="button" icono="arrow-right" data-siguiente-paso data-requiere-lineas>{{ $botonFinal }}</x-boton>
                @else
                    <x-boton icono="check-lg" data-requiere-lineas data-enviar-una-vez>{{ $botonFinal }}</x-boton>
                @endif
            </div>
        </section>

        @if ($flujo === 'factura')
            {{-- Pasos fiscales: tocar elige y avanza; nada viene preseleccionado. --}}
            @include('mostrador._paso-opciones', [
                'paso' => 'uso',
                'titulo' => $pasos['uso'],
                'buscar' => 'Buscar uso de CFDI',
                'opciones' => collect($usos)->mapWithKeys(fn ($uso) => [$uso->value => $uso->descripcion()])->all(),
            ])
            @include('mostrador._paso-opciones', [
                'paso' => 'forma',
                'titulo' => $pasos['forma'],
                'buscar' => 'Buscar forma de pago',
                'opciones' => collect($formas)->mapWithKeys(fn ($forma) => [$forma->value => $forma->descripcion()])->all(),
            ])

            <section class="mostrador-paso" data-paso="metodo" data-titulo="{{ $pasos['metodo'] }}" hidden>
                <div class="mostrador-opciones" data-opciones="metodo">
                    @foreach ($metodos as $metodo)
                        <a href="#" class="mostrador-ficha mostrador-opcion mostrador-opcion-grande" data-opcion="{{ $metodo->value }}" data-texto="{{ $metodo->value }} – {{ $metodo->etiqueta() }}">
                            <strong class="mostrador-opcion-clave">{{ $metodo->value }}</strong>
                            <span>{{ $metodo->etiqueta() }}</span>
                            <x-icono nombre="check-lg" class="mostrador-opcion-palomita" />
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="mostrador-paso" data-paso="revisar" data-titulo="{{ $pasos['revisar'] }}" hidden>
                <div class="mostrador-revision">
                    <p class="mostrador-revision-nombre" data-resumen="cliente"></p>
                    <p class="mostrador-rfc" data-resumen="rfc"></p>
                    <p class="mostrador-revision-total" data-resumen="total"></p>
                    <dl class="mostrador-revision-fiscal">
                        <dt>Uso de CFDI</dt>
                        <dd data-resumen="uso"></dd>
                        <dt>Forma de pago</dt>
                        <dd data-resumen="forma"></dd>
                        <dt>Método de pago</dt>
                        <dd data-resumen="metodo"></dd>
                    </dl>
                </div>
                <div class="mostrador-pie">
                    <x-boton icono="patch-check" bloque data-enviar-una-vez>Timbrar</x-boton>
                </div>
            </section>
        @endif
    </form>

    @if ($flujo === 'venta')
        {{-- Línea libre de 019: descripción y precio escritos a mano, sin artículo del catálogo. --}}
        <dialog id="dialogo-articulo-suelto" class="ficha dialogo" aria-labelledby="dialogo-articulo-suelto-titulo">
            <div data-articulo-suelto>
                <h2 id="dialogo-articulo-suelto-titulo">Artículo suelto</h2>
                <x-campo nombre="suelto_descripcion" etiqueta="Descripción" maxlength="255" autocomplete="off" />
                <x-campo nombre="suelto_precio" etiqueta="Precio unitario sin IVA" tipo="number" inputmode="decimal" min="0.01" step="0.01" />
                <x-campo nombre="suelto_tasa" etiqueta="IVA" tipo="select" :opciones="$tasasIva" :vacia="false" :valor="App\Enums\TasaIva::Dieciseis->value" />
                <x-alerta tipo="error" hidden data-suelto-error>Escribe la descripción y un precio mayor a 0.</x-alerta>
                <div class="acciones">
                    <x-boton tipo="button" icono="plus-lg" data-agregar-suelto>Agregar</x-boton>
                    <x-boton tipo="button" variante="secundario" data-cerrar-dialogo>Cancelar</x-boton>
                </div>
            </div>
        </dialog>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/totales-documento.js') }}?v={{ filemtime(public_path('js/totales-documento.js')) }}"></script>
    @if ($flujo === 'venta')
        <script src="{{ asset('js/pedido-cliente.js') }}?v={{ filemtime(public_path('js/pedido-cliente.js')) }}"></script>
    @endif
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
