{{-- Encabezado de la conversación (regla 57). Se vuelve a pintar con el refresco. --}}
@use('App\Enums\EstadoConversacion')

<div class="wa-encabezado">
    <div class="wa-encabezado-quien">
        <h2>{{ $conversacion->nombreVisible() }}</h2>
        <p class="ayuda">
            {{ $conversacion->telefonoLegible() }}
            @if ($conversacion->cliente)
                · <a href="{{ route('clientes.edit', $conversacion->cliente) }}">{{ $conversacion->cliente->razon_social }}</a>
            @endif
        </p>
    </div>
    <div class="wa-encabezado-estado">
        <span @class(['etiqueta', $conversacion->estado->claseEtiqueta()])>{{ $conversacion->estado->etiqueta() }}</span>
        @if ($conversacion->estado === EstadoConversacion::Agente)
            <span class="etiqueta etiqueta-exitoso"><x-icono nombre="robot" />{{ $conversacion->agente_actual->etiqueta() }}</span>
        @endif
        @if ($conversacion->ventanaAbierta())
            <span @class(['etiqueta', 'etiqueta-fallido' => $conversacion->ventanaPorVencer(), 'etiqueta-cerrada' => ! $conversacion->ventanaPorVencer()]) title="Ventana de 24 horas para mensajes libres">
                <x-icono nombre="stopwatch" />{{ $conversacion->ventanaRestanteTexto() }}
            </span>
        @else
            <span class="etiqueta etiqueta-cerrada" title="Ventana de 24 horas para mensajes libres"><x-icono nombre="stopwatch" />Ventana cerrada</span>
        @endif
        @if ($conversacion->sin_limite)
            <span class="etiqueta etiqueta-cerrada">Sin límite</span>
        @endif
    </div>
    @if ($conversacion->enManosDeHumano() && $conversacion->motivo_humano)
        <p class="wa-motivo"><x-icono nombre="person-raised-hand" />{{ $conversacion->motivo_humano }}</p>
    @endif
</div>
