@extends('layouts.app')

@section('title', 'Subir imágenes · '.config('app.name'))

@section('content')
    <h1>Subir imágenes</h1>

    @include('articulos._mensajes')

    <div data-reporte-destino>
        @if ($reporte)
            @include('articulos._reporte-imagenes')
        @endif
    </div>

    @if ($catalogos === [])
        <x-alerta tipo="advertencia">
            Para subir imágenes primero necesitas un catálogo con artículos.
            <a href="{{ route('catalogos.create') }}">Registrar un catálogo</a>
        </x-alerta>
    @else
        <x-card titulo="Imágenes de los artículos">
            <p>
                El nombre de cada archivo debe ser el <strong>modelo</strong> del artículo: <code>A-1234.jpg</code> va al
                artículo de modelo <code>A-1234</code>. No importan mayúsculas, acentos ni la diferencia entre espacios,
                guiones y guiones bajos, y solo se compara con los artículos del catálogo que elijas. Una imagen que no
                encuentra su artículo se descarta y aparece en el reporte; si el artículo ya tenía imagen, se reemplaza.
            </p>

            <form method="POST" action="{{ route('articulos.imagenes.store') }}" enctype="multipart/form-data" data-carga-imagenes
                data-articulos="{{ json_encode($articulosPorCatalogo, JSON_FORCE_OBJECT) }}"
                data-maximo-archivos="{{ App\Http\Requests\CargarImagenesRequest::MAXIMO_ARCHIVOS }}"
                data-tamano-maximo="{{ App\Http\Requests\CargarImagenesRequest::TAMANO_MAXIMO_KB * 1024 }}">
                @csrf

                <x-campo nombre="catalogo_id" etiqueta="Catálogo" tipo="select" :opciones="$catalogos" vacia="Selecciona un catálogo" required />

                <x-alerta tipo="advertencia" hidden data-aviso-vacio>
                    <strong data-aviso-catalogo></strong> no tiene ningún artículo. Ninguna de estas fotos va a encontrar a
                    quién pertenecer. Empieza por <a href="{{ route('articulos.importar') }}">importar los artículos</a>.
                </x-alerta>
                <p class="ayuda conteo-articulos" hidden data-conteo-articulos></p>

                <x-campo nombre="archivos[]" id="archivos" etiqueta="Varias imágenes" tipo="file" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    ayuda="JPG, PNG o WEBP de hasta 10 MB cada una. Hasta 20 por envío; para más, usa un .zip." data-archivos />
                <x-campo nombre="archivo" etiqueta="O un .zip" tipo="file" accept=".zip,application/zip"
                    ayuda="Plano (sin carpetas dentro) y de hasta 40 MB." data-zip />

                <progress class="progreso-carga" max="1" value="0" hidden data-progreso></progress>
                <p class="ayuda" role="status" data-estado-carga></p>

                <div class="acciones">
                    <x-boton icono="upload" data-boton-subir><span data-etiqueta-subir>Subir imágenes</span></x-boton>
                    <x-boton :href="route('articulos.index')" variante="secundario" icono="arrow-left">Volver al listado</x-boton>
                </div>
            </form>
        </x-card>

        <template id="plantilla-reporte-imagenes">
            @include('articulos._reporte-imagenes', ['reporte' => ['asociadas' => 0, 'errores' => [], 'catalogo' => '']])
        </template>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/carga-imagenes.js') }}?v={{ filemtime(public_path('js/carga-imagenes.js')) }}"></script>
@endpush
