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
        <label for="{{ $id }}">{{ $etiqueta }}</label>

        @if ($tipo === 'password')
            <div class="contrasena">
                <input id="{{ $id }}" type="password" name="{{ $nombre }}" {{ $attributes }}>
                <x-boton variante="suave" tipo="button" icono="eye" descripcion="Mostrar contraseña" data-mostrar-contrasena="{{ $id }}" />
            </div>
        @elseif ($tipo === 'select')
            <select id="{{ $id }}" name="{{ $nombre }}" {{ $attributes }}>
                <option value="">{{ $vacia }}</option>
                @foreach ($opciones as $valorOpcion => $textoOpcion)
                    <option value="{{ $valorOpcion }}" @selected((string) old($nombre, $valor) === (string) $valorOpcion)>{{ $textoOpcion }}</option>
                @endforeach
            </select>
        @else
            <input id="{{ $id }}" type="{{ $tipo }}" name="{{ $nombre }}" value="{{ old($nombre, $valor) }}" {{ $attributes }}>
        @endif

        @if ($ayuda)
            <p id="{{ $id }}-ayuda" class="ayuda">{{ $ayuda }}</p>
        @endif
    </div>
@endif
