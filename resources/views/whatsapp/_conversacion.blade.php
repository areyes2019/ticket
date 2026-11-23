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
        {{-- Barra estilo WhatsApp (044): clip con menú de adjuntos, texto a lo
             ancho y enviar. Sin JavaScript el clip queda oculto y se ve el
             campo de archivo de siempre (adición 1). --}}
        <form method="POST" action="{{ route('whatsapp.mensajes.store', $abierta) }}" enctype="multipart/form-data" class="wa-redactar"
              data-redactar data-max-bytes="{{ config('whatsapp.max_mb_archivo') * 1024 * 1024 }}" data-max-mb="{{ config('whatsapp.max_mb_archivo') }}">
            @csrf
            {{-- Respuestas rápidas con "/" (043): la lista viaja con la página
                 (adición 1) y la arma respuestas-rapidas.js. Sin JavaScript
                 no se usa y la caja funciona igual (adición 2). --}}
            @isset($respuestasRapidas)
                <script type="application/json" id="wa-respuestas-rapidas" data-configuracion="{{ route('configuracion.edit') }}#respuestas-rapidas">@json($respuestasRapidas, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)</script>
                <div class="wa-respuestas" id="wa-respuestas-lista" role="listbox" aria-label="Respuestas rápidas" hidden></div>
                <p class="solo-lectores" id="wa-respuestas-aviso" aria-live="polite"></p>
            @endisset

            {{-- Regla 14: el archivo elegido, encima de la barra. --}}
            <div class="wa-adjunto" data-adjunto aria-live="polite" hidden></div>

            <div class="wa-fila">
                <div class="wa-barra">
                    <div class="wa-clip" data-clip hidden>
                        <x-boton tipo="button" variante="suave" icono="paperclip" descripcion="Adjuntar" class="wa-clip-abrir"
                                 aria-haspopup="menu" aria-expanded="false" aria-controls="wa-menu-adjuntar" data-clip-boton />
                        <div class="wa-menu-adjuntar" id="wa-menu-adjuntar" role="menu" aria-label="Adjuntar" hidden>
                            <x-boton tipo="button" variante="suave" icono="file-earmark-pdf" role="menuitem" tabindex="-1"
                                     data-adjuntar="documento" data-accept="application/pdf">Documento</x-boton>
                            <x-boton tipo="button" variante="suave" icono="image" role="menuitem" tabindex="-1"
                                     data-adjuntar="imagen" data-accept="image/jpeg,image/png,image/webp">Imagen</x-boton>
                            {{-- Regla 11: de adorno hasta que se defina qué hace. --}}
                            <x-boton tipo="button" variante="suave" icono="shop" role="menuitem" tabindex="-1" aria-disabled="true"
                                     data-adjuntar="catalogo">Catálogo <span class="wa-proximamente">Próximamente</span></x-boton>
                        </div>
                    </div>

                    <x-campo nombre="texto" id="wa-texto" etiqueta="Respuesta" tipo="textarea" rows="2" maxlength="4000"
                             placeholder="Escribe un mensaje o / para respuestas rápidas" class="wa-redactar-texto" />
                </div>

                <x-boton icono="send-fill" descripcion="Enviar" class="wa-enviar" data-enviar />
            </div>

            <x-campo nombre="archivo" id="wa-archivo" etiqueta="Adjuntar imagen o PDF (máx. {{ config('whatsapp.max_mb_archivo') }} MB)" tipo="file"
                     accept="image/jpeg,image/png,image/webp,application/pdf" />

            @if ($abierta->estado === EstadoConversacion::Agente)
                {{-- Regla 5. --}}
                <p class="ayuda wa-aviso-pausa">Contestar pausa el agente en este chat.</p>
            @endif
        </form>
    @else
        {{-- Regla 65. --}}
        <x-alerta tipo="advertencia">
            Pasaron más de 24 horas desde el último mensaje del cliente. Solo puedes mandar una plantilla.
        </x-alerta>
    @endif
</div>
