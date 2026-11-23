{{-- WhatsApp (041): conexión con Meta y ajustes de los agentes. Solo el
     administrador; el número queda ligado a su cuenta (regla 4). Acomodo por
     bloques: título y explicación a la izquierda, campos a la derecha (042,
     reglas 34 a 41). --}}
@php
    $numero = auth()->user()->whatsappNumero;
    $horario = $numero?->horarioSemanal() ?? App\Models\WhatsappNumero::HORARIO_POR_DEFECTO;
    $gasto = $numero === null ? 0 : app(App\Services\Whatsapp\RegistroUso::class)->gastoDelMes(auth()->user());
@endphp

<x-card titulo="WhatsApp">
    <p class="ayuda">Número de la API de WhatsApp Cloud de Meta con los agentes de ventas y facturación.</p>

    {{-- Estado del número y acciones sobre la conexión (regla 35). --}}
    @if ($numero)
        <div class="wa-estado">
            <dl class="datos">
                <div>
                    <dt>Número</dt>
                    <dd><strong>{{ $numero->nombre_visible ?? 'Número conectado' }}</strong>@if ($numero->numero_visible)<br>{{ $numero->numero_visible }}@endif</dd>
                </div>
                <div>
                    <dt>Gasto estimado de Claude este mes</dt>
                    <dd><strong>US$ {{ number_format($gasto, 2) }}</strong> de US$ {{ number_format((float) $numero->tope_mensual_usd, 2) }}</dd>
                </div>
            </dl>

            <div class="acciones">
                <form method="POST" action="{{ route('configuracion.whatsapp.probar') }}">
                    @csrf
                    <x-boton variante="secundario" icono="plug">Probar conexión</x-boton>
                </form>
                <form method="POST" action="{{ route('configuracion.whatsapp.plantillas') }}">
                    @csrf
                    <x-boton variante="secundario" icono="arrow-repeat">Actualizar plantillas</x-boton>
                </form>
            </div>

            <details class="wa-registrar" @if ($errors->has('pin')) open @endif>
                <summary>Registrar el número en Meta (solo una vez, al darlo de alta)</summary>
                <form method="POST" action="{{ route('configuracion.whatsapp.registrar') }}" class="wa-registrar-formulario">
                    @csrf
                    <x-campo nombre="pin" id="wa-pin" etiqueta="PIN de 6 dígitos (verificación en dos pasos)" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off" required />
                    <x-boton variante="secundario" icono="shield-check">Registrar número</x-boton>
                </form>
            </details>
        </div>
    @else
        <x-alerta tipo="advertencia">Todavía no hay un número conectado. Llena la conexión con Meta y guarda.</x-alerta>
    @endif

    <form method="POST" action="{{ route('configuracion.whatsapp') }}">
        @csrf
        @method('PUT')

        <section class="config-bloque">
            <div class="config-bloque-texto">
                <h3>Conexión con Meta</h3>
                <p class="ayuda">Los datos de la app de WhatsApp en Meta for Developers.</p>
                <p class="ayuda">Webhook para Meta, campo <code>messages</code>:</p>
                <code class="wa-webhook">{{ route('whatsapp.webhook.recibir') }}</code>
            </div>
            <div class="rejilla-campos">
                <x-campo nombre="phone_number_id" etiqueta="Identificador del número (Phone number ID)" :valor="$numero?->phone_number_id" inputmode="numeric" required />
                <x-campo nombre="waba_id" etiqueta="Identificador de la cuenta (WABA ID)" :valor="$numero?->waba_id" inputmode="numeric" required />
                <x-campo nombre="token" etiqueta="Token de acceso permanente" tipo="password" autocomplete="off"
                         :ayuda="$numero ? 'Guardado: '.App\Models\WhatsappNumero::enmascarar($numero->token).'. Déjalo vacío para conservarlo.' : 'Token de usuario del sistema con permisos de mensajes y administración.'" />
                <x-campo nombre="app_secret" etiqueta="Clave secreta de la app (App secret)" tipo="password" autocomplete="off"
                         :ayuda="$numero ? 'Guardada: '.App\Models\WhatsappNumero::enmascarar($numero->app_secret).'. Déjala vacía para conservarla.' : 'Con ella se revisa que cada aviso venga de Meta.'" />
                <div class="ocupa-fila">
                    <x-campo nombre="verify_token" etiqueta="Token de verificación del webhook" :valor="$numero?->verify_token ?? Str::random(32)" minlength="12" maxlength="64" required
                             ayuda="Una palabra secreta que inventas y escribes igual en Meta al configurar el webhook." />
                </div>
            </div>
        </section>

        <section class="config-bloque">
            <div class="config-bloque-texto">
                <h3>Agentes</h3>
                <p class="ayuda">Las indicaciones se agregan a las reglas fijas del sistema; no las sustituyen.</p>
            </div>
            <div class="rejilla-campos">
                <div class="wa-agente">
                    <x-campo nombre="ventas_activo" etiqueta="Agente de ventas encendido" tipo="checkbox" :valor="$numero?->ventas_activo" />
                    <x-campo nombre="instrucciones_ventas" etiqueta="Indicaciones para el agente de ventas" tipo="textarea" rows="6" maxlength="4000" :valor="$numero?->instrucciones_ventas"
                             ayuda="Políticas, tiempos de entrega, preguntas frecuentes, tono." />
                </div>
                <div class="wa-agente">
                    <x-campo nombre="facturacion_activo" etiqueta="Agente de facturación encendido" tipo="checkbox" :valor="$numero?->facturacion_activo" />
                    <x-campo nombre="instrucciones_facturacion" etiqueta="Indicaciones para el agente de facturación" tipo="textarea" rows="6" maxlength="4000" :valor="$numero?->instrucciones_facturacion" />
                </div>
            </div>
        </section>

        <section class="config-bloque">
            <div class="config-bloque-texto">
                <h3>Horario de atención</h3>
                <p class="ayuda">Los agentes contestan siempre. El horario solo cambia el aviso cuando una conversación pasa a una persona.</p>
            </div>
            <div class="wa-horario">
                @foreach (App\Models\WhatsappNumero::DIAS as $dia => $nombre)
                    @php $tramo = $horario[$dia] ?? null; @endphp
                    <div class="wa-horario-dia" role="group" aria-labelledby="horario-{{ $dia }}-nombre">
                        <span class="wa-horario-nombre" id="horario-{{ $dia }}-nombre">{{ $nombre }}</span>
                        <x-campo nombre="horario[{{ $dia }}][abierto]" id="horario-{{ $dia }}-abierto" etiqueta="Abierto" tipo="checkbox" :valor="$tramo !== null" />
                        <x-campo nombre="horario[{{ $dia }}][abre]" id="horario-{{ $dia }}-abre" etiqueta="Abre" tipo="time" :valor="$tramo[0] ?? '09:00'" />
                        <span class="wa-horario-a" aria-hidden="true">a</span>
                        <x-campo nombre="horario[{{ $dia }}][cierra]" id="horario-{{ $dia }}-cierra" etiqueta="Cierra" tipo="time" :valor="$tramo[1] ?? '18:00'" />
                    </div>
                @endforeach
            </div>
        </section>

        <section class="config-bloque">
            <div class="config-bloque-texto">
                <h3>Avisos al pasar con una persona</h3>
                <p class="ayuda">Lo que recibe el cliente cuando la conversación pasa a alguien del equipo. <code>{horario}</code> se cambia por el horario de atención.</p>
            </div>
            <div class="rejilla-campos">
                <x-campo nombre="aviso_humano" etiqueta="En horario" tipo="textarea" rows="3" :valor="$numero?->aviso_humano ?? App\Models\WhatsappNumero::AVISO_HUMANO" maxlength="500" />
                <x-campo nombre="aviso_humano_fuera_horario" etiqueta="Fuera de horario" tipo="textarea" rows="3" :valor="$numero?->aviso_humano_fuera_horario ?? App\Models\WhatsappNumero::AVISO_HUMANO_FUERA_HORARIO" maxlength="500" />
            </div>
        </section>

        <section class="config-bloque">
            <div class="config-bloque-texto">
                <h3>Límites</h3>
                <p class="ayuda">Al llegar al tope de gasto, los agentes se pausan y todo pasa a una persona hasta el mes siguiente.</p>
            </div>
            <div class="rejilla-campos rejilla-campos-3">
                <x-campo nombre="tope_mensual_usd" etiqueta="Tope de gasto mensual (US$)" tipo="number" step="0.01" min="0" :valor="$numero?->tope_mensual_usd ?? 50" required />
                <x-campo nombre="limite_hora" etiqueta="Respuestas por cliente, por hora" tipo="number" min="1" :valor="$numero?->limite_hora ?? 20" required />
                <x-campo nombre="limite_dia" etiqueta="Respuestas por cliente, por día" tipo="number" min="1" :valor="$numero?->limite_dia ?? 100" required />
            </div>
        </section>

        <div class="acciones config-pie">
            <x-boton icono="save">Guardar WhatsApp</x-boton>
        </div>
    </form>
</x-card>
