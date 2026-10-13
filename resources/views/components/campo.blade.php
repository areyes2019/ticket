@props([
    'nombre',
    'etiqueta',
    'tipo' => 'text',
    'id' => null,
    'valor' => null,
    'ayuda' => null,
    'opciones' => [],
    'vacia' => 'Selecciona una opción',
])

@php
    $id ??= $nombre;
    $conError = isset($errors) && $errors->has($nombre);

    $attributes = $attributes->merge(array_filter([
        'aria-invalid' => $conError ? 'true' : null,
        'aria-describedby' => $ayuda ? $id.'-ayuda' : null,
    ]));
@endphp

@if ($tipo === 'checkbox')
    <label class="casilla">
        <input id="{{ $id }}" type="checkbox" name="{{ $nombre }}" value="1" @checked(old($nombre, $valor)) {{ $attributes }}>
        {{ $etiqueta }}
    </label>
@else
    <div @class(['campo', 'campo-error' => $conError])>
        @if ($tipo === 'radio')
            {{-- Un grupo de radios no tiene un control al cual apuntar: el título lo nombra con aria-labelledby. --}}
            <span id="{{ $id }}-etiqueta" class="campo-titulo">{{ $etiqueta }}</span>
        @else
            <label for="{{ $id }}">{{ $etiqueta }}</label>
        @endif

        @if ($tipo === 'password')
            <div class="contrasena">
                <input id="{{ $id }}" type="password" name="{{ $nombre }}" {{ $attributes }}>
                <x-boton variante="suave" tipo="button" icono="eye" descripcion="Mostrar contraseña" data-mostrar-contrasena="{{ $id }}" />
            </div>
        @elseif ($tipo === 'select')
            <select id="{{ $id }}" name="{{ $nombre }}" {{ $attributes }}>
                @if ($vacia !== false)
                    <option value="">{{ $vacia }}</option>
                @endif
                @foreach ($opciones as $valorOpcion => $textoOpcion)
                    <option value="{{ $valorOpcion }}" @selected((string) old($nombre, $valor) === (string) $valorOpcion)>{{ $textoOpcion }}</option>
                @endforeach
            </select>
        @elseif ($tipo === 'radio')
            <div class="opciones-radio" role="radiogroup" aria-labelledby="{{ $id }}-etiqueta">
                @foreach ($opciones as $valorOpcion => $textoOpcion)
                    <label>
                        <input id="{{ $id }}-{{ $valorOpcion }}" type="radio" name="{{ $nombre }}" value="{{ $valorOpcion }}" @checked((string) old($nombre, $valor) === (string) $valorOpcion) {{ $attributes }}>
                        {{ $textoOpcion }}
                    </label>
                @endforeach
            </div>
        @elseif ($tipo === 'textarea')
            <textarea id="{{ $id }}" name="{{ $nombre }}" {{ $attributes }}>{{ old($nombre, $valor) }}</textarea>
        @else
            <input id="{{ $id }}" type="{{ $tipo }}" name="{{ $nombre }}" value="{{ old($nombre, $valor) }}" {{ $attributes }}>
        @endif

        @if ($ayuda)
            <p id="{{ $id }}-ayuda" class="ayuda">{{ $ayuda }}</p>
        @endif
    </div>
@endif
