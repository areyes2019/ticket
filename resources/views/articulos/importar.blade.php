@extends('layouts.app')

@section('title', 'Importar artículos · '.config('app.name'))

@section('content')
    <h1>Importar artículos</h1>

    @include('articulos._mensajes')

    @if ($reporte)
        <x-alerta tipo="exito">
            {{ $reporte['importados'] === 1 ? '1 artículo importado.' : $reporte['importados'].' artículos importados.' }}
        </x-alerta>

        @if ($reporte['errores'] !== [])
            <x-alerta tipo="advertencia">
                {{ count($reporte['errores']) === 1 ? '1 fila rechazada.' : count($reporte['errores']).' filas rechazadas.' }}
                Corrígelas en tu hoja y vuelve a importar solo esas filas; las demás ya quedaron registradas.
            </x-alerta>

            <x-card titulo="Filas rechazadas" class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Fila</th>
                            <th>Modelo</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reporte['errores'] as $error)
                            <tr>
                                <td>{{ $error['fila'] }}</td>
                                <td>{{ $error['modelo'] !== '' ? $error['modelo'] : '—' }}</td>
                                <td>{{ $error['motivo'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    @endif

    @if ($catalogos === [])
        <x-alerta tipo="advertencia">
            Para importar artículos primero necesitas un catálogo.
            <a href="{{ route('catalogos.create') }}">Registrar un catálogo</a>
        </x-alerta>
    @else
        <x-card titulo="Archivo CSV">
            <p>La primera fila debe ser el encabezado, con estas columnas en cualquier orden:</p>
            <pre class="bloque-codigo"><code>{{ implode(',', App\Models\Articulo::COLUMNAS_CSV) }}</code></pre>
            <p>
                Todas las filas se registran en el catálogo que elijas (y en su proveedor). Se aceptan archivos guardados desde
                Excel como "CSV UTF-8" o "CSV (delimitado por comas)". Si necesitas una plantilla, exporta tu
                listado de artículos.
            </p>

            <form method="POST" action="{{ route('articulos.importar.store') }}" enctype="multipart/form-data">
                @csrf

                <x-campo nombre="catalogo_id" etiqueta="Catálogo" tipo="select" :opciones="$catalogos" vacia="Selecciona un catálogo" required />
                <x-campo nombre="archivo" etiqueta="Archivo CSV" tipo="file" accept=".csv,text/csv" required />

                <div class="acciones">
                    <x-boton icono="upload">Importar</x-boton>
                    <x-boton :href="route('articulos.index')" variante="secundario" icono="arrow-left">Volver al listado</x-boton>
                </div>
            </form>
        </x-card>
    @endif
@endsection
