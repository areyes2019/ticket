@extends('layouts.app')

@section('title', 'Importar clientes · '.config('app.name'))

@section('content')
    <h1>Importar clientes</h1>

    @include('clientes._mensajes')

    @if ($reporte)
        <x-alerta tipo="exito">
            {{ $reporte['importados'] === 1 ? '1 cliente importado.' : $reporte['importados'].' clientes importados.' }}
        </x-alerta>

        @if ($reporte['errores'] !== [])
            <x-alerta tipo="advertencia">
                {{ count($reporte['errores']) === 1 ? '1 fila rechazada.' : count($reporte['errores']).' filas rechazadas.' }}
                Corrígelas en tu hoja y vuelve a importar el archivo: las que ya quedaron registradas se rechazarán como RFC duplicado sin crearse dos veces.
            </x-alerta>

            <x-card titulo="Filas rechazadas" class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Fila</th>
                            <th>RFC</th>
                            <th>Razón social</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reporte['errores'] as $error)
                            <tr>
                                <td>{{ $error['fila'] }}</td>
                                <td>{{ $error['rfc'] !== '' ? $error['rfc'] : '—' }}</td>
                                <td>{{ $error['razon_social'] !== '' ? $error['razon_social'] : '—' }}</td>
                                <td>{{ $error['motivo'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    @endif

    <x-card titulo="Archivo CSV">
        <p>La primera fila debe ser el encabezado. Las columnas pueden venir en cualquier orden; estas son obligatorias:</p>
        <pre class="bloque-codigo"><code>RazonSocial,RFC,RegimenFiscal,CP</code></pre>
        <p>Y estas son opcionales:</p>
        <pre class="bloque-codigo"><code>Email,Calle,NumExterior,NumInterior,Colonia,Ciudad,Municipio,Estado,Pais,NombreComercial,Contacto,Telefono</code></pre>
        <p>
            <code>RegimenFiscal</code> es la clave del SAT de 3 dígitos (por ejemplo <code>601</code>). Las columnas de
            domicilio se juntan en la dirección comercial del cliente. Un RFC que ya tengas registrado se rechaza.
            Se aceptan archivos guardados desde Excel como "CSV UTF-8" o "CSV (delimitado por comas)".
        </p>

        <form method="POST" action="{{ route('clientes.importar.store') }}" enctype="multipart/form-data">
            @csrf

            <x-campo nombre="archivo" etiqueta="Archivo CSV" tipo="file" accept=".csv,text/csv" required />

            <div class="acciones">
                <x-boton icono="upload">Importar</x-boton>
                <x-boton :href="route('clientes.index')" variante="secundario" icono="arrow-left">Volver al listado</x-boton>
            </div>
        </form>
    </x-card>
@endsection
