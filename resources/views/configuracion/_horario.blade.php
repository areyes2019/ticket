{{-- Horario de atención del negocio (046, adición 1). Uno para toda la
     instalación; lo usan los agentes de WhatsApp y el aviso fuera de horario.
     Solo el administrador. --}}
@php
    $horario = $emisor->horarioSemanal();
@endphp

<x-card titulo="Horario de atención" id="horario">
    <p class="ayuda">Los agentes de WhatsApp contestan siempre. El horario solo cambia el aviso cuando una conversación pasa a una persona, y los agentes lo dan cuando el cliente lo pregunta.</p>

    <form method="POST" action="{{ route('configuracion.horario') }}">
        @csrf
        @method('PUT')

        <div class="wa-horario">
            @foreach (App\Models\Emisor::DIAS as $dia => $nombre)
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

        <div class="acciones">
            <x-boton icono="save">Guardar horario</x-boton>
        </div>
    </form>
</x-card>
