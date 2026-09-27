{{-- Reporte de una carga de imágenes. Lo pinta el servidor y, como plantilla, carga-imagenes.js tras las tandas. --}}
@php
    $errores = $reporte['errores'];
    $ninguna = $reporte['asociadas'] === 0 && $errores !== [];
@endphp

<div id="reporte-imagenes" data-reporte>
    <x-alerta tipo="exito">
        <span data-reporte-asociadas>{{ $reporte['asociadas'] === 1 ? '1 imagen asociada.' : $reporte['asociadas'].' imágenes asociadas.' }}</span>
    </x-alerta>

    <x-alerta tipo="advertencia" :hidden="! $ninguna" data-reporte-ninguna>
        Ninguna imagen encontró su artículo en <strong data-reporte-catalogo>{{ $reporte['catalogo'] }}</strong>.
        Revisa que sea el catálogo correcto y que los nombres de archivo coincidan con el modelo de cada artículo.
    </x-alerta>

    <x-card titulo="Archivos rechazados" class="tabla-contenedor" :hidden="$errores === []" data-reporte-rechazados>
        <table class="tabla">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody data-reporte-filas>
                @foreach ($errores as $error)
                    <tr>
                        <td>{{ $error['archivo'] }}</td>
                        <td>{{ $error['motivo'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>

    {{-- Solo con JavaScript: carga-imagenes.js quita el hidden. --}}
    <div class="acciones reporte-acciones">
        <x-boton tipo="button" variante="secundario" icono="copy" hidden data-copiar-reporte>Copiar reporte</x-boton>
        <span class="ayuda" role="status" data-copiar-estado></span>
    </div>
</div>
