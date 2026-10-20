{{-- Ventana del reembolso (038), en el detalle de la venta y en el de la
     cotización sin venta. Parámetros: $documento (Pedido o Cotizacion) y
     $accion (la ruta del reembolso). Sin JavaScript se abre con #dialogo-reembolso
     gracias a :target; el motivo SAT se muestra siempre y el servidor lo ignora
     con "No, dejarla vigente". --}}
@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $monto = $documento->montoReembolsable();
    $devoluciones = $documento->devolucionesPorCuenta();
    $articulos = $documento->articulosQueRegresan();
    $factura = $documento->facturaParaReembolso();
    $ordenTrabajo = $documento instanceof App\Models\Pedido ? $documento->ordenTrabajo : null;
@endphp

<dialog id="dialogo-reembolso" class="ficha dialogo" aria-labelledby="dialogo-reembolso-titulo" @if ($errors->reembolso->any()) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ $accion }}">
        @csrf
        <h2 id="dialogo-reembolso-titulo">Reembolsar {{ $documento->folio_formateado }}</h2>

        @foreach ($errors->reembolso->all() as $error)
            <x-alerta tipo="error">{{ $error }}</x-alerta>
        @endforeach

        <p>Se devuelven <strong>{{ $pesos($monto) }}</strong>:
            {{ $devoluciones->map(fn ($devolucion) => $devolucion['cuenta']->nombre.' '.$pesos($devolucion['monto']))->join(' · ') }}</p>

        @if ($articulos->isEmpty())
            <p class="ayuda">Ningún artículo regresa a existencias.</p>
        @else
            <p>Regresan a existencias:</p>
            <ul data-articulos-reembolso>
                @foreach ($articulos as $articulo)
                    <li>{{ $articulo['articulo']->modelo ?: $articulo['articulo']->nombre }} × {{ $articulo['cantidad'] }}</li>
                @endforeach
            </ul>
        @endif

        @if ($ordenTrabajo)
            <p>La orden de trabajo se cancelará.</p>
        @endif

        @if ($factura !== null)
            @if ($factura->estado === App\Enums\EstadoFactura::Timbrada && $factura->cancelacionEnCurso())
                <x-alerta tipo="info">La factura {{ $factura->folioVisible() }} ya tiene una cancelación en curso.</x-alerta>
            @elseif ($factura->estado === App\Enums\EstadoFactura::Timbrada)
                <fieldset class="reembolso-factura" data-pregunta-factura>
                    <legend>¿También quieres cancelar la factura {{ $factura->folioVisible() }} ({{ $pesos($factura->total) }}) ante el SAT?</legend>
                    <x-campo nombre="cancelar_factura" id="reembolso-cancelar-factura" etiqueta="Factura" tipo="radio"
                        :opciones="['si' => 'Sí, cancelar factura', 'no' => 'No, dejarla vigente']" valor="si" required />
                    <x-campo nombre="motivo_cancelacion" id="reembolso-motivo-cancelacion" etiqueta="Motivo SAT (solo si se cancela)" tipo="select"
                        :opciones="App\Enums\MotivoCancelacion::opcionesParaReembolso()" :valor="App\Enums\MotivoCancelacion::NoSeLlevoACabo->value" :vacia="false" />
                </fieldset>
            @else
                <x-alerta tipo="advertencia">La factura {{ $factura->folioVisible() }}, que todavía no se timbró, se descartará.</x-alerta>
            @endif
        @endif

        <x-campo nombre="motivo" id="reembolso-motivo" etiqueta="Motivo del reembolso" tipo="textarea" maxlength="500" required />

        <div class="acciones">
            <x-boton variante="peligro" icono="arrow-counterclockwise" data-enviar-una-vez
                data-confirmar="¿Seguro que quieres reembolsar {{ $pesos($monto) }}? Esta acción no se puede deshacer.">Reembolsar {{ $pesos($monto) }}</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
