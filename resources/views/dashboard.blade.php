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
             facturas recientes en un acordeón (columna "Documentos"), órdenes de
             trabajo recientes y un solo visor (bandeja-documentos.js). Los
             buscadores filtran solo lo cargado. --}}
        <div class="bandeja bandeja-dashboard" data-bandeja-documentos data-parametro="cotizacion factura ot"
             data-sin-seleccion="Selecciona una cotización, una factura o una orden" data-escritorio-inicio @if ($correoAbierto) hidden @endif>
            <section class="bandeja-lista bandeja-lista-documentos" aria-label="Documentos">
                {{-- Cada sección es un <details>: se abre y cierra sola, también sin
                     JavaScript. Los botones del <summary> no la abren ni la cierran. --}}
                <details class="bandeja-seccion" data-filtro-local data-seccion="cotizaciones" open>
                    <summary class="bandeja-lista-titulo">
                        <x-icono nombre="chevron-right" class="bandeja-seccion-flecha" /><x-icono nombre="file-earmark-text" />Cotizaciones
                        <span class="bandeja-seccion-cuenta">{{ $cotizaciones->count() }}</span>
                        <span class="bandeja-lista-herramientas">
                            <x-boton :href="route('cotizaciones.index')" variante="secundario" icono="eye" descripcion="Ver todas las cotizaciones" title="Ver todas" />
                            <x-boton href="#dialogo-nueva-cotizacion" icono="plus-lg" descripcion="Nueva cotización" title="Nueva cotización" data-abrir-dialogo />
                        </span>
                    </summary>
                    <x-bandeja.encabezado-lista texto="Buscar cotización" :carpetas="false" />

                    <div class="bandeja-filas">
                        <ul class="bandeja-filas-lista">
                            @foreach ($cotizaciones as $cotizacion)
                                <x-cotizaciones.fila :cotizacion="$cotizacion" :activa="$abierta instanceof App\Models\Cotizacion && $abierta->is($cotizacion)" />
                            @endforeach
                        </ul>
                        <p class="bandeja-vacia" data-vacia @if ($cotizaciones->isNotEmpty()) hidden @endif><x-icono nombre="file-earmark-text" />Sin cotizaciones</p>
                    </div>
                </details>

                <details class="bandeja-seccion" data-filtro-local data-seccion="facturas" @if ($facturasAbiertas) open @endif>
                    <summary class="bandeja-lista-titulo">
                        <x-icono nombre="chevron-right" class="bandeja-seccion-flecha" /><x-icono nombre="receipt" />Facturas
                        <span class="bandeja-seccion-cuenta">{{ $facturas->count() }}</span>
                        <span class="bandeja-lista-herramientas">
                            <x-boton :href="route('facturas.index')" variante="secundario" icono="eye" descripcion="Ver todas las facturas" title="Ver todas" />
                        </span>
                    </summary>
                    <x-bandeja.encabezado-lista texto="Buscar factura" :carpetas="false" />

                    <div class="bandeja-filas">
                        <ul class="bandeja-filas-lista" data-lista-facturas>
                            @foreach ($facturas as $factura)
                                <x-facturas.fila :factura="$factura" :activa="$abierta instanceof App\Models\Factura && $abierta->is($factura)" />
                            @endforeach
                        </ul>
                        <p class="bandeja-vacia" data-vacia @if ($facturas->isNotEmpty()) hidden @endif><x-icono nombre="receipt" />Sin facturas</p>
                    </div>
                </details>
            </section>

            <section class="bandeja-lista bandeja-lista-ordenes" aria-labelledby="dashboard-ordenes" data-filtro-local>
                <h2 id="dashboard-ordenes" class="bandeja-lista-titulo">
                    <x-icono nombre="clipboard-check" />Órdenes de trabajo
                    <span class="bandeja-lista-herramientas">
                        <x-boton :href="route('pedidos.produccion')" variante="secundario" icono="printer" descripcion="Hoja de producción" title="Hoja de producción" target="_blank" />
                    </span>
                </h2>
                <x-bandeja.encabezado-lista texto="Buscar orden" :carpetas="false" />

                <div class="bandeja-filas">
                    <ul class="bandeja-filas-lista">
                        @foreach ($ordenesTrabajo as $orden)
                            <x-ordenes-trabajo.fila :orden="$orden" :activa="$abierta instanceof App\Models\OrdenTrabajo && $abierta->is($orden)" />
                        @endforeach
                    </ul>
                    <p class="bandeja-vacia" data-vacia @if ($ordenesTrabajo->isNotEmpty()) hidden @endif><x-icono nombre="clipboard-check" />Sin órdenes de trabajo</p>
                </div>
            </section>

            <section class="bandeja-visor bandeja-visor-documento" aria-label="Documento abierto" data-visor-documento>
                @if ($abierta instanceof App\Models\Cotizacion)
                    @include('cotizaciones._vista-previa', $datosVisor)
                @elseif ($abierta instanceof App\Models\Factura)
                    @include('facturas._vista-previa', $datosVisor)
                @elseif ($abierta instanceof App\Models\OrdenTrabajo)
                    @include('ordenes-trabajo._vista-previa', $datosVisor)
                @else
                    <p class="bandeja-sin-seleccion"><x-icono nombre="file-earmark-text" />Selecciona una cotización, una factura o una orden</p>
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
    <script src="{{ asset('js/dashboard-secciones.js') }}?v={{ filemtime(public_path('js/dashboard-secciones.js')) }}"></script>
    <script src="{{ asset('js/compartir-pdf.js') }}"></script>
    <script src="{{ asset('js/totales-documento.js') }}"></script>
    <script src="{{ asset('js/documento-lineas.js') }}"></script>
    <script src="{{ asset('js/timbrar-cotizacion.js') }}?v={{ filemtime(public_path('js/timbrar-cotizacion.js')) }}"></script>
@endpush
