@extends('layouts.app')

@section('title', 'Dashboard · '.config('app.name'))
@section('contenido-clase', 'contenido-bandeja')

@section('content')
    @if (session('status'))
        <x-alerta tipo="exito">{{ session('status') }}</x-alerta>
    @endif

    @if (session('exito'))
        <x-alerta tipo="exito">
            {{ session('exito') }}
            @include('documentos._enlaces-exito')
        </x-alerta>
    @endif

    <x-alerta tipo="error" hidden data-vista-previa-error>No se pudo abrir el documento. Intenta de nuevo.</x-alerta>

    @php
        $carpetaInicial = 'entrada';
        $primero = collect($correos)->firstWhere('carpeta', $carpetaInicial);
        // El menú de aplicaciones del layout manda ?app=correo desde otras páginas.
        $correoAbierto = request('app') === 'correo';
    @endphp

    <div class="escritorio">
        {{-- Lo que se ve mientras no hay ninguna aplicación abierta: órdenes de
             trabajo recientes y su visor (bandeja-documentos.js). El buscador
             filtra solo lo cargado. --}}
        <div class="bandeja bandeja-dashboard" data-bandeja-documentos data-parametro="ot"
             data-sin-seleccion="Selecciona una orden" data-escritorio-inicio @if ($correoAbierto) hidden @endif>
            <section class="bandeja-lista bandeja-lista-ordenes" aria-labelledby="dashboard-ordenes" data-filtro-local>
                <h2 id="dashboard-ordenes" class="bandeja-lista-titulo">
                    <x-icono nombre="clipboard-check" />Órdenes de trabajo
                    <span class="bandeja-lista-herramientas">
                        <x-boton :href="route('pedidos.produccion')" variante="secundario" icono="printer" descripcion="Hoja de producción" title="Hoja de producción" target="_blank" />
                        <x-boton :href="route('pedidos.produccion.etiquetas')" variante="secundario" icono="tags" descripcion="Imprimir etiquetas" title="Imprimir etiquetas" target="_blank" />
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
                @if ($abierta)
                    @include('ordenes-trabajo._vista-previa', $datosVisor)
                @else
                    <p class="bandeja-sin-seleccion"><x-icono nombre="clipboard-check" />Selecciona una orden</p>
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

    <div class="bandeja-aviso" role="status" data-bandeja-aviso hidden></div>
@endsection

@push('scripts')
    <script src="{{ asset('js/dashboard-apps.js') }}?v={{ filemtime(public_path('js/dashboard-apps.js')) }}"></script>
    <script src="{{ asset('js/bandeja-correo.js') }}?v={{ filemtime(public_path('js/bandeja-correo.js')) }}"></script>
    <script src="{{ asset('js/bandeja-documentos.js') }}?v={{ filemtime(public_path('js/bandeja-documentos.js')) }}"></script>
@endpush
