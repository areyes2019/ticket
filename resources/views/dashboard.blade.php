@extends('layouts.app')

@section('title', 'Dashboard · '.config('app.name'))
@section('contenido-clase', 'contenido-bandeja')

@section('content')
    @if (session('status'))
        <x-alerta tipo="exito">{{ session('status') }}</x-alerta>
    @endif

    @if (session('exito'))
        <x-alerta tipo="exito">{{ session('exito') }}</x-alerta>
    @endif

    <x-alerta tipo="error" hidden data-compartir-error></x-alerta>
    <x-alerta tipo="error" hidden data-vista-previa-error>No se pudo abrir el documento. Intenta de nuevo.</x-alerta>
    <x-alerta tipo="exito" hidden data-timbrado-aviso-exito></x-alerta>
    <x-alerta tipo="error" hidden data-timbrado-aviso-error></x-alerta>

    @php
        $carpetaInicial = 'entrada';
        $primero = collect($correos)->firstWhere('carpeta', $carpetaInicial);
        // El menú de aplicaciones del layout manda ?app=correo desde otras páginas.
        $correoAbierto = request('app') === 'correo';
    @endphp

    <div class="escritorio">
        {{-- Lo que se ve mientras no hay ninguna aplicación abierta: cotizaciones y
             facturas recientes, cada una en su lista, con un solo visor
             (bandeja-documentos.js). Los buscadores filtran solo lo cargado. --}}
        <div class="bandeja bandeja-dashboard" data-bandeja-documentos data-parametro="cotizacion factura"
             data-sin-seleccion="Selecciona una cotización o una factura" data-escritorio-inicio @if ($correoAbierto) hidden @endif>
            <section class="bandeja-lista bandeja-lista-cotizaciones" aria-labelledby="dashboard-cotizaciones" data-filtro-local>
                <h2 id="dashboard-cotizaciones" class="bandeja-lista-titulo">
                    <x-icono nombre="file-earmark-text" />Cotizaciones
                    <span class="bandeja-lista-herramientas">
                        <x-boton :href="route('cotizaciones.index')" variante="secundario" icono="eye" descripcion="Ver todas las cotizaciones" title="Ver todas" />
                        <x-boton href="#dialogo-nueva-cotizacion" icono="plus-lg" descripcion="Nueva cotización" title="Nueva cotización" data-abrir-dialogo />
                    </span>
                </h2>
                <x-bandeja.encabezado-lista texto="Buscar cotización" :carpetas="false" />

                <div class="bandeja-filas">
                    <ul class="bandeja-filas-lista">
                        @foreach ($cotizaciones as $cotizacion)
                            <x-cotizaciones.fila :cotizacion="$cotizacion" :activa="$abierta instanceof App\Models\Cotizacion && $abierta->is($cotizacion)" />
                        @endforeach
                    </ul>
                    <p class="bandeja-vacia" data-vacia @if ($cotizaciones->isNotEmpty()) hidden @endif><x-icono nombre="file-earmark-text" />Sin cotizaciones</p>
                </div>
            </section>

            <section class="bandeja-lista bandeja-lista-facturas" aria-labelledby="dashboard-facturas" data-filtro-local>
                <h2 id="dashboard-facturas" class="bandeja-lista-titulo">
                    <x-icono nombre="receipt" />Facturas
                    <a href="{{ route('facturas.index') }}">Ver todas</a>
                </h2>
                <x-bandeja.encabezado-lista texto="Buscar factura" :carpetas="false" />

                <div class="bandeja-filas">
                    <ul class="bandeja-filas-lista" data-lista-facturas>
                        @foreach ($facturas as $factura)
                            <x-facturas.fila :factura="$factura" :activa="$abierta instanceof App\Models\Factura && $abierta->is($factura)" />
                        @endforeach
                    </ul>
                    <p class="bandeja-vacia" data-vacia @if ($facturas->isNotEmpty()) hidden @endif><x-icono nombre="receipt" />Sin facturas</p>
                </div>
            </section>

            <section class="bandeja-visor bandeja-visor-documento" aria-label="Documento abierto" data-visor-documento>
                @if ($abierta instanceof App\Models\Cotizacion)
                    @include('cotizaciones._vista-previa', $datosVisor)
                @elseif ($abierta instanceof App\Models\Factura)
                    @include('facturas._vista-previa', $datosVisor)
                @else
                    <p class="bandeja-sin-seleccion"><x-icono nombre="file-earmark-text" />Selecciona una cotización o una factura</p>
                @endif
            </section>
        </div>

        <div id="app-correo" class="bandeja bandeja-plegable bandeja-carpetas-ocultas" data-bandeja data-app="correo" @if (! $correoAbierto) hidden @endif>
            <x-bandeja.riel />

            <x-bandeja.carpetas :carpetas="$carpetas" :etiquetas="$etiquetas" :activa="$carpetaInicial" />

            <section class="bandeja-lista" aria-label="Lista de correos">
                <x-bandeja.encabezado-lista />

                <ul class="bandeja-filas">
                    @foreach ($correos as $correo)
                        <x-bandeja.fila-correo :correo="$correo" :etiquetas="$etiquetas"
                                               :visible="$correo['carpeta'] === $carpetaInicial"
                                               :activa="$correo['id'] === $primero['id']" />
                    @endforeach
                </ul>

                <p class="bandeja-vacia" data-vacia hidden><x-icono nombre="envelope-open" />Sin correos</p>
            </section>

            <section class="bandeja-visor" aria-label="Correo abierto">
                @foreach ($correos as $correo)
                    <x-bandeja.visor-correo :correo="$correo" :etiquetas="$etiquetas" :visible="$correo['id'] === $primero['id']" />
                @endforeach

                <p class="bandeja-sin-seleccion" data-sin-seleccion hidden><x-icono nombre="envelope" />Selecciona un correo para leerlo</p>
            </section>
        </div>
    </div>

    <x-bandeja.redactar />

    @include('cotizaciones._dialogo-nueva', [...$nuevaCotizacion, 'abrir' => $abrirNuevaCotizacion])

    <div class="bandeja-aviso" role="status" data-bandeja-aviso hidden></div>
@endsection

@push('scripts')
    <script src="{{ asset('js/dashboard-apps.js') }}?v={{ filemtime(public_path('js/dashboard-apps.js')) }}"></script>
    <script src="{{ asset('js/bandeja-correo.js') }}?v={{ filemtime(public_path('js/bandeja-correo.js')) }}"></script>
    <script src="{{ asset('js/bandeja-documentos.js') }}?v={{ filemtime(public_path('js/bandeja-documentos.js')) }}"></script>
    <script src="{{ asset('js/compartir-pdf.js') }}"></script>
    <script src="{{ asset('js/totales-documento.js') }}"></script>
    <script src="{{ asset('js/documento-lineas.js') }}"></script>
    <script src="{{ asset('js/timbrar-cotizacion.js') }}?v={{ filemtime(public_path('js/timbrar-cotizacion.js')) }}"></script>
@endpush
