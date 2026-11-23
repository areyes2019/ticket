{{-- Un par pregunta/respuesta de las preguntas frecuentes (045, reglas 6 y
     34). $i es el número del par, o "__i__" en el molde que copia
     indicaciones-agente.js (adición 3). --}}
@use('App\Services\Whatsapp\TextoIndicaciones')
@php
    $molde ??= false;
    $orden = is_int($i) ? $i + 1 : '';
    $errores = $molde ? [] : array_values(array_filter([
        $errors->first("{$columna}.preguntas.{$i}.pregunta"),
        $errors->first("{$columna}.preguntas.{$i}.respuesta"),
    ]));
@endphp

<fieldset class="wa-pregunta" data-pregunta>
    <legend class="solo-lectores">Pregunta {{ $orden }}</legend>
    <div class="wa-pregunta-campos">
        <x-campo nombre="{{ $columna }}[preguntas][{{ $i }}][pregunta]" clave="{{ $columna }}.preguntas.{{ $i }}.pregunta"
                 id="{{ $columna }}-p{{ $i }}-pregunta" etiqueta="Pregunta" maxlength="{{ TextoIndicaciones::MAX_PREGUNTA }}"
                 :valor="$molde ? null : ($par['pregunta'] ?? null)" />
        <x-campo nombre="{{ $columna }}[preguntas][{{ $i }}][respuesta]" clave="{{ $columna }}.preguntas.{{ $i }}.respuesta"
                 id="{{ $columna }}-p{{ $i }}-respuesta" etiqueta="Respuesta" tipo="textarea" rows="3" maxlength="{{ TextoIndicaciones::MAX_RESPUESTA }}"
                 :valor="$molde ? null : ($par['respuesta'] ?? null)" />
        @foreach ($errores as $error)
            <p class="wa-pregunta-error">{{ $error }}</p>
        @endforeach
    </div>
    <x-boton tipo="button" variante="suave" icono="x-lg" descripcion="Quitar la pregunta {{ $orden }}" class="wa-pregunta-quitar" data-quitar hidden />
</fieldset>
