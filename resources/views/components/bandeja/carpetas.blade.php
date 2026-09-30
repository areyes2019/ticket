@props(['carpetas', 'etiquetas', 'activa' => 'entrada'])

<nav {{ $attributes->class('bandeja-carpetas') }} id="bandeja-carpetas" aria-label="Carpetas del correo">
    <x-boton tipo="button" icono="pencil-square" bloque data-bandeja-redactar>Redactar</x-boton>

    <ul class="bandeja-opciones">
        @foreach ($carpetas as $carpeta)
            <li>
                <button type="button" @class(['bandeja-opcion', 'bandeja-opcion-activa' => $carpeta['clave'] === $activa])
                        data-filtro="carpeta" data-valor="{{ $carpeta['clave'] }}" aria-pressed="{{ $carpeta['clave'] === $activa ? 'true' : 'false' }}">
                    <x-icono :nombre="$carpeta['icono']" />
                    <span class="bandeja-opcion-nombre">{{ $carpeta['nombre'] }}</span>
                    <span class="bandeja-contador" data-contador="{{ $carpeta['clave'] }}" @if ($carpeta['no_leidos'] === 0) hidden @endif>{{ $carpeta['no_leidos'] }}</span>
                </button>
            </li>
        @endforeach
    </ul>

    <h2 class="bandeja-subtitulo">Etiquetas</h2>

    <ul class="bandeja-opciones">
        @foreach ($etiquetas as $etiqueta)
            <li>
                <button type="button" class="bandeja-opcion" data-filtro="etiqueta" data-valor="{{ $etiqueta['clave'] }}" aria-pressed="false">
                    <span class="bandeja-color bandeja-etiqueta-{{ $etiqueta['clave'] }}" aria-hidden="true"></span>
                    <span class="bandeja-opcion-nombre">{{ $etiqueta['nombre'] }}</span>
                </button>
            </li>
        @endforeach
    </ul>
</nav>
