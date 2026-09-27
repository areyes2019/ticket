@extends('layouts.app')

@section('title', 'Estilos · '.config('app.name'))

@section('content')
    <h1>Muestra de estilos</h1>
    <p>Catálogo de los componentes del sistema. Solo existe en el entorno local.</p>

    <x-card titulo="Botones">
        <div class="muestra">
            <x-boton tipo="button">Principal</x-boton>
            <x-boton tipo="button" icono="save">Principal con icono</x-boton>
            <x-boton tipo="button" variante="secundario" icono="x-lg">Secundario</x-boton>
            <x-boton tipo="button" variante="suave">Suave</x-boton>
            <x-boton :href="route('inicio')" icono="link-45deg">Enlace con aspecto de botón</x-boton>
            <x-boton tipo="button" variante="suave" icono="eye" descripcion="Botón de solo icono" />
        </div>
        <p><code>&lt;x-boton icono="save" variante="principal|secundario|suave" tipo="submit|button" href="…" bloque descripcion="…"&gt;Texto&lt;/x-boton&gt;</code></p>
        <x-boton tipo="button" icono="arrows-expand-vertical" bloque>Botón de ancho completo</x-boton>
    </x-card>

    <x-card titulo="Cards">
        <x-card titulo="Card con título">
            <p>Contenido de la card.</p>
        </x-card>
        <x-card>
            <p>Card sin título.</p>
        </x-card>
        <p><code>&lt;x-card titulo="…" :nivel="1|2" estrecha&gt;…&lt;/x-card&gt;</code></p>
    </x-card>

    <x-card titulo="Alertas">
        <x-alerta tipo="exito">Los cambios se guardaron.</x-alerta>
        <x-alerta tipo="error">No se pudo guardar.</x-alerta>
        <x-alerta tipo="advertencia">Revisa los datos antes de continuar.</x-alerta>
        <p><code>&lt;x-alerta tipo="exito|error|advertencia"&gt;…&lt;/x-alerta&gt;</code></p>
    </x-card>

    <x-card titulo="Campos de formulario">
        <x-campo nombre="muestra_texto" etiqueta="Campo de texto" placeholder="Escribe algo" ayuda="Texto de ayuda opcional." />
        <x-campo nombre="muestra_error" etiqueta="Campo con error" valor="dato no válido" />
        <x-campo nombre="muestra_contrasena" etiqueta="Contraseña" tipo="password" />
        <x-campo nombre="muestra_casilla" etiqueta="Casilla" tipo="checkbox" />
        <x-campo nombre="muestra_select" etiqueta="Lista de opciones" tipo="select" :opciones="['a' => 'Opción A', 'b' => 'Opción B']" valor="b" />
        <p><code>&lt;x-campo nombre="…" etiqueta="…" tipo="text|email|password|checkbox|select" valor="…" ayuda="…" :opciones="[valor =&gt; texto]" vacia="…" (:vacia="false" sin opción vacía) /&gt;</code></p>
        <p>Campo con sugerencias: <code>&lt;x-campo … data-autocompletar="&lt;url&gt;" /&gt;</code> más <code>js/autocompletar.js</code>; la URL responde <code>[{ clave, descripcion }]</code> y la descripción elegida se escribe en el texto de ayuda. Se ve en el formulario de artículos (requiere sesión).</p>
    </x-card>

    <x-card titulo="Iconos del sistema">
        <div class="muestra">
            @foreach (['speedometer2', 'clock-history', 'box-arrow-in-right', 'box-arrow-right', 'person-plus', 'envelope', 'key', 'eye', 'eye-slash', 'check-circle', 'x-circle', 'exclamation-triangle', 'truck', 'collection', 'people', 'plus-lg', 'search', 'pencil', 'trash', 'save', 'x-lg', 'box-seam', 'upload', 'download', 'arrow-left', 'arrow-up', 'arrow-down', 'arrow-down-up', 'check-lg'] as $icono)
                <span class="muestra-icono"><x-icono :nombre="$icono" />{{ $icono }}</span>
            @endforeach
        </div>
        <p><code>&lt;x-icono nombre="eye" /&gt;</code> · Catálogo completo en <a href="https://icons.getbootstrap.com/">icons.getbootstrap.com</a></p>
    </x-card>

    <x-card titulo="Paginación">
        <x-paginacion :paginador="new Illuminate\Pagination\LengthAwarePaginator([], 75, 25, 2, ['path' => route('estilos')])" />
        <p><code>&lt;x-paginacion :paginador="$paginador" /&gt;</code></p>
    </x-card>

    <x-card titulo="Celda truncada">
        <table class="tabla">
            <tbody>
                <tr>
                    <td><span class="celda-truncada" title="Sello redondo de Ø X 45 mm con mango ergonómico y cojín de tinta azul">Sello redondo de Ø X 45 mm con mango ergonómico y cojín de tinta azul</span></td>
                </tr>
            </tbody>
        </table>
        <p><code>&lt;span class="celda-truncada" title="texto completo"&gt;texto completo&lt;/span&gt;</code></p>
    </x-card>

    <x-card titulo="Resumen de precio y aviso de utilidad">
        <dl class="resumen-precio">
            <div><dt>Precio de lista del proveedor</dt><dd><output>$200.00</output></dd></div>
            <div><dt>Descuento del catálogo (10%)</dt><dd><output>−$20.00</output></dd></div>
            <div class="resumen-total"><dt>Costo</dt><dd><output>$180.00</output></dd></div>
            <div><dt>Utilidad (25%)</dt><dd><output>+$45.00</output></dd></div>
            <div class="resumen-total"><dt>Precio de venta sin IVA</dt><dd><output>$225.00</output></dd></div>
            <div><dt>IVA (16%)</dt><dd><output>+$36.00</output></dd></div>
            <div class="resumen-total"><dt>Precio de venta con IVA</dt><dd><output>$261.00</output></dd></div>
        </dl>
        <p class="aviso-utilidad"><x-icono nombre="exclamation-triangle" /> Más de 400%: el costo se multiplica por 11. Revisa que no sobre un cero.</p>
        <p><code>&lt;dl class="resumen-precio" data-resumen-precio&gt;</code> · <code>&lt;p class="aviso-utilidad"&gt;</code> (precio-articulo.js)</p>
    </x-card>

    <x-card titulo="Etiquetas de estado">
        <div class="muestra">
            <span class="etiqueta etiqueta-exitoso">Exitoso</span>
            <span class="etiqueta etiqueta-fallido">Fallido</span>
            <span class="etiqueta etiqueta-suspendido">Suspendido</span>
        </div>
    </x-card>
@endsection

@push('scripts')
    <script src="{{ asset('js/contrasena.js') }}"></script>
@endpush
