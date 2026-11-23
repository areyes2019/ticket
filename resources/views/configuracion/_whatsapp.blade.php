{{-- WhatsApp (041): conexión con Meta y ajustes de los agentes. Solo el
     administrador; el número queda ligado a su cuenta (regla 4). --}}
@php
    $numero = auth()->user()->whatsappNumero;
    $horario = $numero?->horarioSemanal() ?? App\Models\WhatsappNumero::HORARIO_POR_DEFECTO;
    $gasto = $numero === null ? 0 : app(App\Services\Whatsapp\RegistroUso::class)->gastoDelMes(auth()->user());
@endphp

<x-card titulo="WhatsApp">
    <p class="ayuda">
        Número de la API de WhatsApp Cloud de Meta con los agentes de ventas y facturación.
        Webhook para Meta: <code>{{ route('whatsapp.webhook.recibir') }}</code>, campo <code>messages</code>.
    </p>

    @if ($numero)
        <p>
            <strong>{{ $numero->nombre_visible ?? 'Número conectado' }}</strong>
            {{ $numero->numero_visible ? '· '.$numero->numero_visible : '' }}
            · Gasto estimado de Claude este mes: <strong>US$ {{ number_format($gasto, 2) }}</strong> de US$ {{ number_format((float) $numero->tope_mensual_usd, 2) }}
        </p>
        <div class="acciones">
            <form method="POST" action="{{ route('configuracion.whatsapp.probar') }}">
                @csrf
                <x-boton variante="secundario" icono="plug">Probar conexión</x-boton>
            </form>
            <form method="POST" action="{{ route('configuracion.whatsapp.plantillas') }}">
                @csrf
                <x-boton variante="secundario" icono="arrow-repeat">Actualizar plantillas</x-boton>
            </form>
            <form method="POST" action="{{ route('configuracion.whatsapp.registrar') }}" class="wa-registrar">
                @csrf
                <x-campo nombre="pin" id="wa-pin" etiqueta="PIN de 6 dígitos (verificación en dos pasos)" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off" required />
                <x-boton variante="secundario" icono="shield-check">Registrar número</x-boton>
            </form>
        </div>
    @endif

    <form method="POST" action="{{ route('configuracion.whatsapp') }}">
        @csrf
        @method('PUT')

        <h3>Conexión con Meta</h3>
        <x-campo nombre="phone_number_id" etiqueta="Identificador del número (Phone number ID)" :valor="$numero?->phone_number_id" inputmode="numeric" required />
        <x-campo nombre="waba_id" etiqueta="Identificador de la cuenta de WhatsApp (WABA ID)" :valor="$numero?->waba_id" inputmode="numeric" required />
        <x-campo nombre="token" etiqueta="Token de acceso permanente" tipo="password" autocomplete="off"
                 :ayuda="$numero ? 'Guardado: '.App\Models\WhatsappNumero::enmascarar($numero->token).'. Déjalo vacío para conservarlo.' : 'Token de usuario del sistema con permisos de mensajes y administración.'" />
        <x-campo nombre="app_secret" etiqueta="Clave secreta de la app (App secret)" tipo="password" autocomplete="off"
                 :ayuda="$numero ? 'Guardada: '.App\Models\WhatsappNumero::enmascarar($numero->app_secret).'. Déjala vacía para conservarla.' : 'Con ella se revisa que cada aviso venga de Meta.'" />
        <x-campo nombre="verify_token" etiqueta="Token de verificación del webhook" :valor="$numero?->verify_token ?? Str::random(32)" minlength="12" maxlength="64" required
                 ayuda="Una palabra secreta que inventas y escribes igual en Meta al configurar el webhook." />

        <h3>Agentes</h3>
        <x-campo nombre="ventas_activo" etiqueta="Agente de ventas encendido" tipo="checkbox" :valor="$numero?->ventas_activo" />
        <x-campo nombre="instrucciones_ventas" etiqueta="Indicaciones para el agente de ventas" tipo="textarea" rows="5" maxlength="4000" :valor="$numero?->instrucciones_ventas"
                 ayuda="Políticas, tiempos de entrega, preguntas frecuentes, tono. Se agregan a las reglas fijas del sistema; no las sustituyen." />
        <x-campo nombre="facturacion_activo" etiqueta="Agente de facturación encendido" tipo="checkbox" :valor="$numero?->facturacion_activo" />
        <x-campo nombre="instrucciones_facturacion" etiqueta="Indicaciones para el agente de facturación" tipo="textarea" rows="5" maxlength="4000" :valor="$numero?->instrucciones_facturacion" />

        <h3>Horario de atención</h3>
        <p class="ayuda">Los agentes contestan siempre. El horario solo cambia el aviso cuando una conversación pasa a una persona.</p>
        <div class="wa-horario">
            @foreach (App\Models\WhatsappNumero::DIAS as $dia => $nombre)
                @php $tramo = $horario[$dia] ?? null; @endphp
                <fieldset class="wa-horario-dia">
                    <legend>{{ $nombre }}</legend>
                    <x-campo nombre="horario[{{ $dia }}][abierto]" id="horario-{{ $dia }}-abierto" etiqueta="Abierto" tipo="checkbox" :valor="$tramo !== null" />
                    <x-campo nombre="horario[{{ $dia }}][abre]" id="horario-{{ $dia }}-abre" etiqueta="Abre" tipo="time" :valor="$tramo[0] ?? '09:00'" />
                    <x-campo nombre="horario[{{ $dia }}][cierra]" id="horario-{{ $dia }}-cierra" etiqueta="Cierra" tipo="time" :valor="$tramo[1] ?? '18:00'" />
                </fieldset>
            @endforeach
        </div>

        <h3>Avisos al pasar con una persona</h3>
        <x-campo nombre="aviso_humano" etiqueta="En horario" :valor="$numero?->aviso_humano ?? App\Models\WhatsappNumero::AVISO_HUMANO" maxlength="500" />
        <x-campo nombre="aviso_humano_fuera_horario" etiqueta="Fuera de horario" :valor="$numero?->aviso_humano_fuera_horario ?? App\Models\WhatsappNumero::AVISO_HUMANO_FUERA_HORARIO" maxlength="500"
                 ayuda="{horario} se cambia por el horario de atención." />

        <h3>Límites</h3>
        <x-campo nombre="tope_mensual_usd" etiqueta="Tope de gasto mensual de Claude (US$)" tipo="number" step="0.01" min="0" :valor="$numero?->tope_mensual_usd ?? 50" required
                 ayuda="Al llegar al tope, los agentes se pausan y todo pasa a una persona hasta el mes siguiente." />
        <x-campo nombre="limite_hora" etiqueta="Respuestas del agente por cliente, por hora" tipo="number" min="1" :valor="$numero?->limite_hora ?? 20" required />
        <x-campo nombre="limite_dia" etiqueta="Respuestas del agente por cliente, por día" tipo="number" min="1" :valor="$numero?->limite_dia ?? 100" required />

        <div class="acciones">
            <x-boton icono="save">Guardar WhatsApp</x-boton>
        </div>
    </form>
</x-card>
