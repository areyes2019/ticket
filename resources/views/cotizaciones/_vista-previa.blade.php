{{-- Visor de la bandeja: acciones, la hoja en HTML y la ventana de envío. Se pinta
     con la página o llega por AJAX (cotizaciones.vista-previa) al elegir una fila. --}}
<div class="bandeja-acciones">
    <x-boton variante="suave" tipo="button" icono="arrow-left" class="bandeja-volver" data-volver>Volver</x-boton>
    <x-boton href="#dialogo-envio" icono="send" data-abrir-dialogo>Enviar</x-boton>
    <x-boton :href="route('cotizaciones.pdf', [$cotizacion, 'descargar' => 1])" variante="secundario" icono="download">Descargar</x-boton>
    <x-boton :href="route('cotizaciones.show', $cotizacion)" variante="suave" icono="box-arrow-up-right" class="bandeja-abrir-detalle">Abrir</x-boton>
</div>

<div class="bandeja-documento" data-vista-previa-de="{{ $cotizacion->id }}">
    <p class="bandeja-documento-estado">
        <span @class(['etiqueta', $cotizacion->estado->claseEtiqueta()])>{{ $cotizacion->estado->etiqueta() }}</span>
        @if ($cotizacion->facturaVigente)
            <a href="{{ route('facturas.show', $cotizacion->facturaVigente) }}" class="etiqueta etiqueta-facturada">Facturada · {{ $cotizacion->facturaVigente->folioVisible() }}</a>
        @endif
        @if ($cotizacion->mostrarAvisoCaducidad())
            <span class="etiqueta etiqueta-suspendido">{{ $cotizacion->textoCaducidad() }}</span>
        @endif
    </p>

    <x-cotizaciones.hoja :cotizacion="$cotizacion" />
</div>

<dialog id="dialogo-envio" class="ficha dialogo" aria-labelledby="dialogo-envio-titulo" @if ($errors->envio->any()) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ route('cotizaciones.enviar', $cotizacion) }}">
        @csrf
        <input type="hidden" name="origen" value="bandeja">
        <h2 id="dialogo-envio-titulo">Enviar {{ $cotizacion->folio_formateado }} por correo</h2>
        <x-campo nombre="destinatarios_texto" etiqueta="Destinatarios" :valor="$cotizacion->cliente->correo" required
            ayuda="Separa varios correos con comas (máximo {{ App\Http\Requests\EnviarCotizacionRequest::MAX_DESTINATARIOS }}). Se adjunta el PDF." />
        <div class="acciones">
            <x-boton icono="send">Enviar</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
