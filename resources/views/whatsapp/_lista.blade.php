{{-- Lista de conversaciones. Se vuelve a pedir cada 20 segundos (regla 63). --}}
<div class="bandeja-filas">
    @if ($conversaciones->isEmpty())
        <p class="bandeja-vacia"><x-icono nombre="chat-dots" />Sin conversaciones</p>
    @else
        <ul class="bandeja-filas-lista">
            @foreach ($conversaciones as $conversacion)
                @php
                    $ultimo = $conversacion->mensajes->first();
                    $fecha = ($conversacion->ultimo_mensaje_en ?? $conversacion->created_at)->setTimezone(config('app.zona_negocio'));
                    $hoy = now(config('app.zona_negocio'));
                    $fechaCorta = match (true) {
                        $fecha->isSameDay($hoy) => $fecha->format('H:i'),
                        $fecha->isSameDay($hoy->copy()->subDay()) => 'Ayer',
                        default => rtrim($fecha->translatedFormat('j M'), '.'),
                    };
                    $porVencer = $conversacion->enManosDeHumano() && $conversacion->ventanaPorVencer();
                @endphp
                <li @class(['bandeja-fila', 'bandeja-fila-activa' => $abiertaId === $conversacion->id, 'bandeja-fila-no-leida' => $conversacion->pendientes_humano > 0, 'wa-fila-por-vencer' => $porVencer])>
                    <a href="{{ route('whatsapp.show', [$conversacion, 'carpeta' => $carpeta === 'todas' ? null : $carpeta, 'q' => $texto ?: null]) }}" class="bandeja-abrir"
                       @if ($abiertaId === $conversacion->id) aria-current="true" @endif>
                        <span class="bandeja-fila-texto">
                            <span class="bandeja-fila-linea">
                                <span class="bandeja-fila-remitente">
                                    @if ($conversacion->enManosDeHumano() && $conversacion->pendientes_humano > 0)
                                        <span class="wa-punto" aria-label="Requiere humano"></span>
                                    @endif
                                    {{ $conversacion->nombreVisible() }}
                                </span>
                                <time class="bandeja-fila-fecha" datetime="{{ $fecha->toIso8601String() }}">{{ $fechaCorta }}</time>
                            </span>
                            <span class="bandeja-fila-linea">
                                <span class="bandeja-fila-fragmento">{{ $ultimo ? Str::limit(App\Services\Whatsapp\OrquestadorTurno::comoTexto($ultimo), 70) : '' }}</span>
                            </span>
                            <span class="bandeja-fila-marcas">
                                <span @class(['etiqueta', $conversacion->estado->claseEtiqueta()])>
                                    @if ($conversacion->estado === App\Enums\EstadoConversacion::Agente)
                                        <x-icono nombre="robot" />{{ $conversacion->agente_actual->etiqueta() }}
                                    @else
                                        {{ $conversacion->estado->etiqueta() }}
                                    @endif
                                </span>
                                @if ($porVencer)
                                    <span class="etiqueta etiqueta-fallido"><x-icono nombre="stopwatch" />{{ $conversacion->ventanaRestanteTexto() }}</span>
                                @endif
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>

<div class="bandeja-paginacion">
    <x-paginacion :paginador="$conversaciones" />
</div>
