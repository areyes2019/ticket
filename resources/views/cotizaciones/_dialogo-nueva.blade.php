{{-- Ventana "Nueva cotización" del dashboard: el mismo alta que /cotizaciones/crear
     (cotizaciones.store y documento-lineas.js), en tres pasos. Con origen=dashboard
     el alta regresa al dashboard con la cotización abierta; si la validación
     falla, la ventana vuelve abierta con lo capturado y los errores.
     Espera $clientes, $lineas, $tiposDescuento y $tasasIva (datosFormulario). --}}
<dialog id="dialogo-nueva-cotizacion" class="ficha dialogo dialogo-documento" aria-labelledby="nueva-cotizacion-titulo"
        @if ($abrir) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ route('cotizaciones.store') }}" class="documento-nuevo"
          data-documento-lineas data-sugerencias="{{ route('articulos.sugerencias') }}">
        @csrf
        <input type="hidden" name="origen" value="dashboard">

        <header class="documento-nuevo-encabezado">
            <span class="documento-nuevo-sello"><x-icono nombre="file-earmark-plus" /></span>
            <div class="documento-nuevo-titulos">
                <h2 id="nueva-cotizacion-titulo">Nueva cotización</h2>
                <p>Elige al cliente, agrega lo que vas a cotizar y revisa el total antes de guardar.</p>
            </div>
            <x-boton href="#" variante="suave" icono="x-lg" descripcion="Cerrar" title="Cerrar" class="documento-nuevo-cerrar" data-cerrar-dialogo />
        </header>

        <div class="documento-nuevo-cuerpo">
            @if ($abrir && $errors->any())
                <x-alerta tipo="error">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </x-alerta>
            @endif

            @if ($clientes === [])
                <x-alerta tipo="advertencia">Todavía no tienes clientes. <a href="{{ route('clientes.create') }}">Registra uno</a> para poder cotizar.</x-alerta>
            @endif

            <section class="documento-nuevo-paso" aria-labelledby="nueva-cotizacion-paso-cliente">
                <h3 id="nueva-cotizacion-paso-cliente" class="documento-nuevo-paso-titulo">
                    <span class="documento-nuevo-numero">1</span>Cliente
                </h3>
                <x-campo nombre="cliente_id" id="nueva-cotizacion-cliente" etiqueta="¿Para quién es?" tipo="select"
                         :opciones="$clientes" vacia="Selecciona un cliente" required />
            </section>

            <section class="documento-nuevo-paso" aria-labelledby="nueva-cotizacion-paso-lineas">
                <h3 id="nueva-cotizacion-paso-lineas" class="documento-nuevo-paso-titulo">
                    <span class="documento-nuevo-numero">2</span>Artículos y servicios
                </h3>

                @include('cotizaciones._aviso-descuento-cliente')

                {{-- El buscador y "Agregar línea libre" necesitan JavaScript; sin él se capturan líneas libres en las filas vacías. --}}
                <div class="barra-lineas" data-controles-lineas hidden>
                    <div class="buscador-articulos">
                        <x-campo nombre="buscar_articulo" etiqueta="Agregar artículo del catálogo" tipo="search" placeholder="Nombre o modelo" autocomplete="off" data-buscar-articulo />
                    </div>
                    <x-boton tipo="button" variante="secundario" icono="plus-lg" data-agregar-linea-libre>Línea libre</x-boton>
                </div>

                <div class="tabla-contenedor documento-nuevo-lineas">
                    <table class="tabla tabla-lineas">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Cantidad</th>
                                <th>Descripción</th>
                                <th>Modelo</th>
                                <th>Precio unitario</th>
                                <th>Descuento</th>
                                <th>IVA</th>
                                <th class="numero">Importe</th>
                                <th><span class="solo-lectores">Quitar</span></th>
                            </tr>
                        </thead>
                        <tbody data-lineas>
                            @foreach ($lineas as $i => $linea)
                                @include('documentos._linea', ['i' => $i, 'linea' => $linea])
                            @endforeach
                            @for ($extra = count($lineas); $extra < count($lineas) + 3; $extra++)
                                @include('documentos._linea', ['i' => $extra, 'linea' => [], 'vacia' => true])
                            @endfor
                        </tbody>
                    </table>
                </div>
                <p class="documento-nuevo-vacio" data-sin-lineas hidden>
                    <x-icono nombre="cart-plus" />Busca un artículo o agrega una línea libre para empezar.
                </p>

                <template id="plantilla-linea">
                    @include('documentos._linea', ['i' => '__i__', 'linea' => []])
                </template>
            </section>

            <section class="documento-nuevo-paso" aria-labelledby="nueva-cotizacion-paso-totales">
                <h3 id="nueva-cotizacion-paso-totales" class="documento-nuevo-paso-titulo">
                    <span class="documento-nuevo-numero">3</span>Descuento y totales
                </h3>

                <div class="documento-nuevo-cierre">
                    <div class="descuento-global">
                        <x-campo nombre="descuento_global_tipo" etiqueta="Descuento global" tipo="select" :opciones="$tiposDescuento" vacia="Sin descuento" />
                        <x-campo nombre="descuento_global_valor" etiqueta="Valor del descuento" tipo="number" min="0" step="0.01" inputmode="decimal" />
                    </div>

                    <dl class="documento-nuevo-resumen">
                        <div><dt>Subtotal</dt><dd><output data-total="subtotal">—</output></dd></div>
                        <div><dt>Descuento</dt><dd><output data-total="total_descuento">—</output></dd></div>
                        <div><dt>IVA 16%</dt><dd><output data-total="total_iva_16">—</output></dd></div>
                    </dl>
                </div>
            </section>
        </div>

        <footer class="documento-nuevo-pie">
            <p class="documento-nuevo-total">
                <span>Total estimado</span>
                <output data-total="total">—</output>
            </p>
            <div class="acciones">
                <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
                <x-boton icono="save" data-enviar-una-vez>Guardar cotización</x-boton>
            </div>
        </footer>
    </form>
</dialog>

@include('documentos._aviso-duplicado', ['documento' => 'la cotización'])
