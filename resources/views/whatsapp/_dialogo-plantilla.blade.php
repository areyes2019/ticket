{{-- Enviar una plantilla aprobada (reglas 9 a 11). La vista previa la arma
     whatsapp.js con el cuerpo de cada plantilla (data-plantillas). --}}
<dialog id="dialogo-plantilla" class="ficha dialogo" aria-labelledby="dialogo-plantilla-titulo" data-dialogo-plantilla>
    <h2 id="dialogo-plantilla-titulo">Mandar una plantilla</h2>

    @if ($plantillas->isEmpty())
        <p>No hay plantillas aprobadas. Créalas en el administrador de WhatsApp de Meta y pulsa "Actualizar plantillas" en Configuración.</p>
        <div class="acciones">
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cerrar</x-boton>
        </div>
    @else
        <script type="application/json" data-plantillas>@json($plantillas->mapWithKeys(fn ($p) => [$p->id => ['cuerpo' => $p->cuerpo, 'variables' => $p->variables]]))</script>

        <form method="POST" action="{{ $accion }}">
            @csrf
            <x-campo nombre="plantilla_id" etiqueta="Plantilla" tipo="select" :vacia="false" required data-plantilla-elegir
                     :opciones="$plantillas->mapWithKeys(fn ($p) => [$p->id => $p->nombre.' ('.$p->idioma.')'])->all()" />

            @for ($i = 1; $i <= 10; $i++)
                <div data-plantilla-variable="{{ $i }}" hidden>
                    <x-campo nombre="variables[]" id="variable-{{ $i }}" etiqueta="Variable {{ $i }}" maxlength="500" disabled />
                </div>
            @endfor

            <h3>Así se verá</h3>
            <p class="wa-vista-plantilla" data-plantilla-vista></p>

            <div class="acciones">
                <x-boton icono="send">Enviar plantilla</x-boton>
                <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
            </div>
        </form>
    @endif
</dialog>
