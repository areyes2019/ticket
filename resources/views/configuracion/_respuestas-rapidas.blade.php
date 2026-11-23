{{-- Respuestas rápidas (043): textos que se insertan en el chat de WhatsApp
     tecleando "/atajo". Del negocio y para todos los usuarios. --}}
<x-card titulo="Respuestas rápidas">
    <div class="encabezado">
        <p class="ayuda">Escribe / en el chat de WhatsApp para insertarlas. Puedes usar {nombre} y {fecha}.</p>
        <x-boton :href="route('configuracion.respuestas-rapidas.create')" icono="plus-lg">Nueva respuesta</x-boton>
    </div>

    @if ($respuestasRapidas->isEmpty())
        <p>Todavía no hay respuestas rápidas. Agrega los textos que mandas seguido, como tu horario o tus datos de pago.</p>
    @else
        <table class="tabla respuestas-rapidas">
            <thead>
                <tr>
                    <th>Atajo</th>
                    <th>Texto</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($respuestasRapidas as $respuesta)
                    <tr>
                        <td><strong>/{{ $respuesta->atajo }}</strong></td>
                        <td class="respuesta-rapida-texto">{{ Str::limit(preg_replace('/\s+/u', ' ', $respuesta->texto), 80, '…') }}</td>
                        <td>
                            <div class="acciones">
                                <x-boton :href="route('configuracion.respuestas-rapidas.edit', $respuesta)" variante="suave" icono="pencil" title="Editar" descripcion="Editar /{{ $respuesta->atajo }}" />
                                <form method="POST" action="{{ route('configuracion.respuestas-rapidas.destroy', $respuesta) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar /{{ $respuesta->atajo }}"
                                        data-confirmar="¿Eliminar la respuesta /{{ $respuesta->atajo }}?" />
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-card>
