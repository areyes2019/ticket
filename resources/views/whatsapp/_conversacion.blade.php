{{-- La conversación abierta: encabezado, mensajes, caja de respuesta y botones (reglas 57 a 59, 65). --}}
@use('App\Enums\EstadoConversacion')

<div class="wa-conversacion">
    <x-boton :href="route('whatsapp.index', array_filter(['carpeta' => $carpeta === 'todas' ? null : $carpeta, 'q' => $texto ?: null]))" variante="suave" icono="arrow-left" class="bandeja-volver">Conversaciones</x-boton>

    <div data-whatsapp-encabezado>
        @include('whatsapp._encabezado', ['conversacion' => $abierta])
    </div>

    <div class="wa-acciones">
        @if ($abierta->estado === EstadoConversacion::Agente)
            <form method="POST" action="{{ route('whatsapp.estado', $abierta) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="accion" value="pausar">
                <x-boton variante="secundario" icono="pause-circle">Pausar agente</x-boton>
            </form>
        @elseif ($abierta->estado === EstadoConversacion::Humano)
            <form method="POST" action="{{ route('whatsapp.estado', $abierta) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="accion" value="reactivar">
                <x-boton variante="secundario" icono="robot">Reactivar agente</x-boton>
            </form>
        @endif

        <x-boton href="#dialogo-plantilla" variante="secundario" icono="card-text" data-abrir-dialogo>Plantilla</x-boton>

        <form method="POST" action="{{ route('whatsapp.estado', $abierta) }}">
            @csrf @method('PATCH')
            @if ($abierta->estado === EstadoConversacion::Cerrada)
                <input type="hidden" name="accion" value="reabrir">
                <x-boton variante="secundario" icono="arrow-counterclockwise">Reabrir</x-boton>
            @else
                <input type="hidden" name="accion" value="cerrar">
                <x-boton variante="secundario" icono="check2-circle">Cerrar</x-boton>
            @endif
        </form>

        @if ($abierta->motivo_humano === 'Límite de mensajes' && ! $abierta->sin_limite)
            <form method="POST" action="{{ route('whatsapp.limite', $abierta) }}">
                @csrf @method('PATCH')
                <x-boton variante="secundario" icono="unlock">Quitar límite</x-boton>
            </form>
        @endif

        <details class="wa-ligar-cliente">
            <summary>Cliente ligado</summary>
            <form method="POST" action="{{ route('whatsapp.cliente', $abierta) }}">
                @csrf @method('PATCH')
                <x-campo nombre="cliente_id" etiqueta="Cliente" tipo="select" vacia="Sin cliente"
                         :opciones="$clientes->mapWithKeys(fn ($c) => [$c->id => $c->razon_social.' — '.$c->rfc])->all()" :valor="$abierta->cliente_id" />
                <x-boton variante="secundario" icono="link-45deg">Guardar</x-boton>
            </form>
        </details>
    </div>

    <div class="wa-mensajes" data-whatsapp-mensajes data-ultimo="{{ $mensajes->last()?->id ?? 0 }}" aria-live="polite">
        @foreach ($mensajes as $mensaje)
            @include('whatsapp._mensaje')
        @endforeach
    </div>

    @if ($abierta->ventanaAbierta())
        <form method="POST" action="{{ route('whatsapp.mensajes.store', $abierta) }}" enctype="multipart/form-data" class="wa-redactar">
            @csrf
            <x-campo nombre="texto" id="wa-texto" etiqueta="Respuesta" tipo="textarea" rows="2" maxlength="4000"
                     placeholder="Escribe una respuesta… (contestar pausa el agente en este chat)" class="wa-redactar-texto" />
            <x-campo nombre="archivo" id="wa-archivo" etiqueta="Adjuntar imagen o PDF (máx. {{ config('whatsapp.max_mb_archivo') }} MB)" tipo="file"
                     accept="image/jpeg,image/png,image/webp,application/pdf" />
            <x-boton icono="send">Enviar</x-boton>
        </form>
    @else
        {{-- Regla 65. --}}
        <x-alerta tipo="advertencia">
            Pasaron más de 24 horas desde el último mensaje del cliente. Solo puedes mandar una plantilla.
        </x-alerta>
    @endif
</div>
