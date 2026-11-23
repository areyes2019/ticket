{{-- Un agente de WhatsApp: encendido e indicaciones por secciones (045).
     Recibe $agente (AgenteWhatsapp) y $numero (?WhatsappNumero). --}}
@use('App\Services\Whatsapp\TextoIndicaciones')
@php
    $columna = $agente->columnaIndicaciones();
    $caja = $numero?->indicaciones($agente) ?? TextoIndicaciones::normalizar($agente, []);
    $preguntas = array_values((array) old("{$columna}.preguntas", $caja['preguntas']));
    $nombreAgente = 'Agente de '.Str::lower($agente->etiqueta());
    $textos = $agente->seccionesTexto();
    $otras = $textos['otras'];
    unset($textos['otras']);
    $conOtras = filled(old("{$columna}.otras", $caja['otras'])) || $errors->has("{$columna}.otras");
    $conPreguntas = count($preguntas) > 0 || $errors->has("{$columna}.preguntas*");
@endphp

<section class="config-bloque" data-indicaciones aria-labelledby="{{ $columna }}-titulo">
    <div class="config-bloque-texto">
        <h3 id="{{ $columna }}-titulo">{{ $nombreAgente }}</h3>
        <p class="ayuda">Las indicaciones se agregan a las reglas fijas del sistema; no las sustituyen.</p>
    </div>
    <div class="rejilla-campos">
        <div class="ocupa-fila">
            <x-campo nombre="{{ $agente->value }}_activo" etiqueta="{{ $nombreAgente }} encendido" tipo="checkbox" :valor="$numero?->agenteActivo($agente)" />
        </div>

        @foreach ($textos as $clave => $seccion)
            {{-- Si la última sección queda sola en su fila, ocupa la fila entera (regla 14). --}}
            <div @class(['ocupa-fila' => $loop->last && $loop->count % 2 === 1])>
                <x-campo nombre="{{ $columna }}[{{ $clave }}]" clave="{{ $columna }}.{{ $clave }}" id="{{ $columna }}-{{ $clave }}"
                         etiqueta="{{ $seccion['titulo'] }}" tipo="textarea" rows="3" maxlength="{{ TextoIndicaciones::MAX_SECCION }}"
                         placeholder="{{ $seccion['ejemplo'] }}" :valor="$caja[$clave]" />
            </div>
        @endforeach

        <details class="ocupa-fila wa-plegable" @if ($conPreguntas) open @endif>
            <summary>Preguntas frecuentes</summary>
            <div class="wa-preguntas" data-preguntas data-max="{{ TextoIndicaciones::MAX_PREGUNTAS }}">
                @foreach ($preguntas as $i => $par)
                    @include('configuracion._pregunta-frecuente', ['columna' => $columna, 'i' => $i, 'par' => (array) $par])
                @endforeach
                @if (count($preguntas) < TextoIndicaciones::MAX_PREGUNTAS)
                    @include('configuracion._pregunta-frecuente', ['columna' => $columna, 'i' => count($preguntas), 'par' => []])
                @endif
            </div>
            <template data-molde-pregunta>
                @include('configuracion._pregunta-frecuente', ['columna' => $columna, 'i' => '__i__', 'par' => [], 'molde' => true])
            </template>
            <p class="ayuda wa-preguntas-tope" data-tope @if (count($preguntas) < TextoIndicaciones::MAX_PREGUNTAS) hidden @endif>
                Llegaste al máximo de {{ TextoIndicaciones::MAX_PREGUNTAS }} preguntas frecuentes.
            </p>
            <p class="solo-lectores" aria-live="polite" data-anuncio></p>
        </details>

        <details class="ocupa-fila wa-plegable" @if ($conOtras) open @endif>
            <summary>{{ $otras['titulo'] }}</summary>
            <x-campo nombre="{{ $columna }}[otras]" clave="{{ $columna }}.otras" id="{{ $columna }}-otras"
                     etiqueta="{{ $otras['titulo'] }}" tipo="textarea" rows="4" maxlength="{{ TextoIndicaciones::MAX_SECCION }}"
                     placeholder="{{ $otras['ejemplo'] }}" :valor="$caja['otras']" class="wa-otras" />
        </details>

        <p class="ayuda ocupa-fila wa-contador" data-contador>{{ number_format(TextoIndicaciones::caracteres($agente, $caja)) }} caracteres en total</p>
    </div>
</section>
