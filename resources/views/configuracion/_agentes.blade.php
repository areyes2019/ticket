{{-- Agentes IA (046, reglas 11 a 13): encendido e indicaciones de cada agente,
     avisos al pasar con una persona y límites. Se guardan aparte de la
     conexión. Solo el administrador; sin número no hay formulario. --}}
@php
    $numero = auth()->user()->whatsappNumero;
    $gasto = $numero === null ? 0 : app(App\Services\Whatsapp\RegistroUso::class)->gastoDelMes(auth()->user());
@endphp

<x-card titulo="Agentes IA">
    <p class="ayuda">Los agentes contestan por WhatsApp a tus clientes: el de ventas cotiza y resuelve dudas, y el de facturación ayuda a pedir la factura.</p>

    @if ($numero === null)
        <x-alerta tipo="advertencia">
            {{ App\Http\Controllers\ConfiguracionWhatsappController::SIN_NUMERO }}
            <a href="#whatsapp">Ir a la pestaña WhatsApp</a>.
        </x-alerta>
    @else
        <form method="POST" action="{{ route('configuracion.whatsapp.agentes') }}">
            @csrf
            @method('PUT')

            {{-- Un bloque por agente con sus indicaciones por secciones (045, regla 14). --}}
            @foreach (App\Enums\AgenteWhatsapp::cases() as $agente)
                @include('configuracion._whatsapp-agente', ['agente' => $agente, 'numero' => $numero])
            @endforeach

            <section class="config-bloque">
                <div class="config-bloque-texto">
                    <h3>Avisos al pasar con una persona</h3>
                    <p class="ayuda">Lo que recibe el cliente cuando la conversación pasa a alguien del equipo. <code>{horario}</code> se cambia por el <a href="#horario">horario de atención</a>.</p>
                </div>
                <div class="rejilla-campos">
                    <x-campo nombre="aviso_humano" etiqueta="En horario" tipo="textarea" rows="3" :valor="$numero->aviso_humano ?? App\Models\WhatsappNumero::AVISO_HUMANO" maxlength="500" />
                    <x-campo nombre="aviso_humano_fuera_horario" etiqueta="Fuera de horario" tipo="textarea" rows="3" :valor="$numero->aviso_humano_fuera_horario ?? App\Models\WhatsappNumero::AVISO_HUMANO_FUERA_HORARIO" maxlength="500" />
                </div>
            </section>

            <section class="config-bloque">
                <div class="config-bloque-texto">
                    <h3>Límites</h3>
                    <p class="ayuda">Al llegar al tope de gasto, los agentes se pausan y todo pasa a una persona hasta el mes siguiente.</p>
                    <p class="ayuda">Gasto estimado de Claude este mes: <strong>US$ {{ number_format($gasto, 2) }}</strong> de US$ {{ number_format((float) $numero->tope_mensual_usd, 2) }}.</p>
                </div>
                <div class="rejilla-campos rejilla-campos-3">
                    <x-campo nombre="tope_mensual_usd" etiqueta="Tope de gasto mensual (US$)" tipo="number" step="0.01" min="0" :valor="$numero->tope_mensual_usd" required />
                    <x-campo nombre="limite_hora" etiqueta="Respuestas por cliente, por hora" tipo="number" min="1" :valor="$numero->limite_hora" required />
                    <x-campo nombre="limite_dia" etiqueta="Respuestas por cliente, por día" tipo="number" min="1" :valor="$numero->limite_dia" required />
                </div>
            </section>

            <div class="acciones config-pie">
                <x-boton icono="save">Guardar agentes</x-boton>
            </div>
        </form>
    @endif
</x-card>
