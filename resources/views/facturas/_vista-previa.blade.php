{{-- Visor del dashboard: acciones, la hoja en HTML y la ventana de envío. Se
     pinta con la página o llega por AJAX (facturas.vista-previa) al elegir una
     fila. Timbrar, cancelar, complemento, duplicar y eliminar siguen en el detalle. --}}
@php
    $receptor = $factura->receptor();
@endphp

<div class="bandeja-acciones">
    <x-boton variante="suave" tipo="button" icono="arrow-left" class="bandeja-volver" data-volver>Volver</x-boton>
    @if ($factura->puedeEnviarse())
        <x-boton href="#dialogo-envio" icono="send" descripcion="Enviar por correo" title="Enviar por correo" data-abrir-dialogo />
    @endif
    @if ($factura->tieneDocumentoFiscal())
        <x-boton :href="route('facturas.pdf', $factura)" variante="secundario" icono="file-earmark-pdf" descripcion="Ver PDF" title="Ver PDF" target="_blank" />
        <x-boton :href="route('facturas.pdf', [$factura, 'descargar' => 1])" variante="secundario" icono="download" descripcion="Descargar PDF" title="Descargar PDF" />
        <x-boton :href="route('facturas.xml', $factura)" variante="secundario" icono="filetype-xml" descripcion="Descargar XML" title="Descargar XML" />
    @endif
    <x-boton :href="route('facturas.show', $factura)" variante="suave" icono="box-arrow-up-right" class="bandeja-abrir-detalle">Abrir</x-boton>
</div>

<div class="bandeja-documento" data-vista-previa-de="{{ $factura->id }}" data-documento="factura">
    <p class="bandeja-documento-estado">
        <span @class(['etiqueta', $factura->estado->claseEtiqueta()])>{{ $factura->estado->etiqueta() }}</span>
        @if ($factura->cancelacionEnCurso())
            <span class="etiqueta etiqueta-suspendido">Cancelación en proceso</span>
        @endif
        @if ($factura->puedeReintentarse())
            <span class="etiqueta etiqueta-suspendido">No se pudo timbrar</span>
        @endif
    </p>

    <x-facturas.hoja :factura="$factura" />
</div>

@if ($factura->puedeEnviarse())
    <dialog id="dialogo-envio" class="ficha dialogo" aria-labelledby="dialogo-envio-titulo">
        <form method="POST" action="{{ route('facturas.enviar', $factura) }}">
            @csrf
            <h2 id="dialogo-envio-titulo">Enviar {{ $factura->folioVisible() }} por correo</h2>
            <x-campo nombre="destinatarios_texto" etiqueta="Destinatarios" :valor="$receptor['correo']" required
                ayuda="Separa varios correos con comas (máximo {{ App\Http\Requests\EnviarFacturaRequest::MAX_DESTINATARIOS }}). Se adjuntan el XML y el PDF." />
            <div class="acciones">
                <x-boton icono="send" data-enviar-una-vez>Enviar</x-boton>
                <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
            </div>
        </form>
    </dialog>
@endif
