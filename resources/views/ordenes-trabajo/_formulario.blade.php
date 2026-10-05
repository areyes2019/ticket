{{-- Alta y edición de la orden de trabajo (022). Cliente, teléfono y folio son
     de la venta y no se editan aquí. x-campo no resuelve old() con nombres de
     arreglo ("colores[12][color]"), así que el valor llega ya resuelto. --}}
@push('scripts')
    <script src="{{ asset('js/orden-trabajo.js') }}?v={{ filemtime(public_path('js/orden-trabajo.js')) }}"></script>
@endpush

@include('documentos._mensajes')

<form method="POST" action="{{ $accion }}" enctype="multipart/form-data">
    @csrf
    @isset($orden)
        @method('PUT')
    @endisset

    <x-card titulo="Venta">
        <dl class="hoja-cliente">
            <div><dt>Venta</dt><dd><a href="{{ route('pedidos.show', $pedido) }}">{{ $pedido->folio_formateado }}</a></dd></div>
            <div><dt>Cliente</dt><dd>{{ $pedido->cliente_nombre }}</dd></div>
            <div><dt>Teléfono</dt><dd>{{ $pedido->telefono_legible }}</dd></div>
        </dl>
    </x-card>

    <x-card titulo="Artículos y color de tinta" class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Modelo</th>
                    <th>Artículo</th>
                    <th class="numero">Cant.</th>
                    <th>Color de tinta</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pedido->lineasDeTrabajo() as $linea)
                    @php
                        $guardado = $orden?->colorDe($linea);
                        $color = old("colores.{$linea->id}.color", $guardado?->color_tinta->value);
                        $otro = old("colores.{$linea->id}.otro", $guardado?->color_tinta_otro);
                        $idColor = "color-{$linea->id}";
                        $idOtro = "color-otro-{$linea->id}";
                    @endphp
                    <tr>
                        <td>{{ filled($linea->modelo) ? $linea->modelo : '—' }}</td>
                        <td>{{ $linea->descripcion }}</td>
                        <td class="numero">{{ $linea->cantidad }}</td>
                        <td>
                            <x-campo nombre="colores[{{ $linea->id }}][color]" :id="$idColor" etiqueta="Color de tinta" tipo="select" :opciones="$colores" :valor="$color"
                                vacia="Elige un color" required data-color-tinta="{{ $idOtro }}"
                                :aria-invalid="$errors->has('colores.'.$linea->id.'.color') ? 'true' : null" />
                            <div data-color-otro="{{ $idOtro }}">
                                <x-campo nombre="colores[{{ $linea->id }}][otro]" :id="$idOtro" etiqueta="¿Qué color? (si elegiste Otro)" :valor="$otro" maxlength="40"
                                    :aria-invalid="$errors->has('colores.'.$linea->id.'.otro') ? 'true' : null" />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>

    <x-card titulo="Imagen del diseño">
        @if ($orden?->tiene_imagen)
            <img class="imagen-articulo" src="{{ route('pedidos.orden-trabajo.imagen', [$pedido, 'v' => $orden->imagen_version]) }}" alt="Diseño de la venta {{ $pedido->folio_formateado }}">
            <x-campo nombre="quitar_imagen" etiqueta="Quitar imagen" tipo="checkbox" />
        @endif
        <x-campo nombre="imagen" etiqueta="{{ $orden?->tiene_imagen ? 'Reemplazar imagen' : 'Imagen' }}" tipo="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            ayuda="Opcional. JPG, PNG o WEBP de hasta 10 MB. Se guarda reducida a 1200 puntos de lado largo." />
    </x-card>

    <div class="acciones">
        <x-boton icono="save" data-enviar-una-vez>Guardar</x-boton>
        <x-boton :href="isset($orden) ? route('pedidos.orden-trabajo.show', $pedido) : route('pedidos.show', $pedido)" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>
