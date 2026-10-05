{{-- Visor de la bandeja: acciones, la hoja en HTML y sus ventanas (envío, pagos,
     duplicar y timbrar). El primer pago crea la venta cuando toca (029). Se pinta con la página o llega por AJAX (cotizaciones.vista-previa)
     al elegir una fila. --}}
@php
    $telefono = preg_replace('/\D/', '', (string) $cotizacion->cliente->telefono);
    $puedePagar = $cotizacion->puedeRegistrarPago();
    $tieneAnticipo = $cotizacion->tieneAnticipo();
    $facturable = $cotizacion->motivoNoFacturable() === null;
@endphp

<div class="bandeja-acciones">
    <x-boton variante="suave" tipo="button" icono="arrow-left" class="bandeja-volver" data-volver>Volver</x-boton>
    <x-boton href="#dialogo-envio" icono="send" descripcion="Enviar" title="Enviar" data-abrir-dialogo />
    {{-- El PDF se baja al apuntar al botón, no con cada cotización que se abre. --}}
    <x-boton tipo="button" variante="secundario" icono="whatsapp" descripcion="Compartir por WhatsApp" title="Compartir por WhatsApp" hidden
        data-compartir-pdf
        data-pdf="{{ route('cotizaciones.pdf', $cotizacion) }}"
        data-marcar="{{ route('cotizaciones.marcar-enviada', $cotizacion) }}"
        data-archivo="cotizacion-{{ $cotizacion->folio_formateado }}.pdf"
        data-telefono="{{ $telefono }}"
        data-precargar="al-apuntar"
        data-texto="Cotización {{ $cotizacion->folio_formateado }} de {{ config('app.name') }} por ${{ number_format((float) $cotizacion->total, 2) }}" />
    @if ($facturable)
        {{-- Timbra directo tras confirmar (spec 020); el detalle sigue llevando al formulario. --}}
        <x-boton href="#dialogo-timbrar" variante="secundario" icono="receipt" descripcion="Timbrar factura" title="Timbrar factura" data-abrir-dialogo />
    @endif
    @if ($puedePagar && ! $tieneAnticipo)
        <x-boton href="#dialogo-anticipo" variante="secundario" icono="cash" descripcion="Registrar anticipo" title="Registrar anticipo" data-abrir-dialogo />
    @endif
    @if ($puedePagar)
        <x-boton href="#dialogo-liquidar" variante="secundario" icono="cash-stack" :descripcion="$tieneAnticipo ? 'Registrar saldo' : 'Pago total'" :title="$tieneAnticipo ? 'Registrar saldo' : 'Pago total'" data-abrir-dialogo />
    @endif
    <x-boton href="#dialogo-duplicar" variante="secundario" icono="copy" descripcion="Duplicar" title="Duplicar" data-abrir-dialogo />
    <x-boton :href="route('cotizaciones.pdf', $cotizacion)" variante="secundario" icono="file-earmark-pdf" descripcion="Ver PDF" title="Ver PDF" target="_blank" />
    <x-boton :href="route('cotizaciones.pdf', [$cotizacion, 'descargar' => 1])" variante="secundario" icono="download" descripcion="Descargar" title="Descargar" />
    <x-boton :href="route('cotizaciones.show', $cotizacion)" variante="suave" icono="box-arrow-up-right" class="bandeja-abrir-detalle">Abrir</x-boton>
</div>

<div class="bandeja-documento" data-vista-previa-de="{{ $cotizacion->id }}" data-documento="cotizacion">
    @include('documentos._aviso-emisor')
    <p class="bandeja-documento-estado">
        <span @class(['etiqueta', $cotizacion->estado->claseEtiqueta()]) data-estado-documento>{{ $cotizacion->estado->etiqueta() }}</span>
        @if ($cotizacion->facturaVigente)
            <a href="{{ route('facturas.show', $cotizacion->facturaVigente) }}" class="etiqueta etiqueta-facturada">Facturada · {{ $cotizacion->facturaVigente->folioVisible() }}</a>
        @endif
        @if ($cotizacion->venta)
            <a href="{{ route('pedidos.show', $cotizacion->venta) }}" class="etiqueta etiqueta-venta" data-venta-de-cotizacion>Venta · {{ $cotizacion->venta->folio_formateado }}</a>
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

@include('documentos._dialogo-duplicar', [
    'titulo' => 'Duplicar '.$cotizacion->folio_formateado,
    'accion' => route('cotizaciones.duplicar', $cotizacion),
    'metodo' => 'POST',
    'clienteActual' => $cotizacion->cliente_id,
    'ocultos' => ['origen' => 'bandeja'],
    'ayuda' => 'La copia nace en borrador, con las mismas líneas y precios.',
])

@include('cotizaciones._dialogos-pago', ['origen' => 'bandeja'])

@if ($facturable)
    @include('cotizaciones._dialogo-timbrar')
@endif
