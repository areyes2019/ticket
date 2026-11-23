@include('documentos._mensajes')

<form method="POST" action="{{ $accion }}">
    @csrf
    @isset($respuesta)
        @method('PUT')
    @endisset

    <x-campo nombre="atajo" etiqueta="Atajo" :valor="$respuesta->atajo ?? null" maxlength="31" required autofocus autocomplete="off"
        ayuda="Una palabra, sin espacios. Se escribe en el chat como /atajo." />
    <x-campo nombre="texto" etiqueta="Texto" tipo="textarea" :valor="$respuesta->texto ?? null" rows="6" maxlength="4000" required
        ayuda="Puedes usar {nombre} (el nombre del cliente ligado a la conversación) y {fecha} (la fecha de hoy)." />

    <div class="acciones">
        <x-boton icono="save">Guardar</x-boton>
        <x-boton :href="route('configuracion.edit').'#respuestas-rapidas'" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>
