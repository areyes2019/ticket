{{-- WhatsApp (041): el canal, es decir, el número y la conexión con Meta. Los
     agentes van en la pestaña Agentes IA (046, regla 8). Solo el
     administrador; el número queda ligado a su cuenta (041, regla 4). Acomodo
     por bloques: título y explicación a la izquierda, campos a la derecha
     (042, reglas 34 a 41). --}}
@php
    $numero = auth()->user()->whatsappNumero;
@endphp

<x-card titulo="WhatsApp">
    <p class="ayuda">Número de la API de WhatsApp Cloud de Meta. Los agentes de ventas y facturación se ajustan en la pestaña <a href="#agentes">Agentes IA</a>.</p>

    {{-- Estado del número y acciones sobre la conexión (regla 35). --}}
    @if ($numero)
        <div class="wa-estado">
            <dl class="datos">
                <div>
                    <dt>Número</dt>
                    <dd><strong>{{ $numero->nombre_visible ?? 'Número conectado' }}</strong>@if ($numero->numero_visible)<br>{{ $numero->numero_visible }}@endif</dd>
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

        <div class="acciones config-pie">
            <x-boton icono="save">Guardar conexión</x-boton>
        </div>
    </form>
</x-card>
