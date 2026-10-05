{{-- Visor de la bandeja: acciones, la hoja en HTML y sus ventanas (envío y pago).
     Se pinta con la página o llega por AJAX (ordenes-compra.vista-previa) al
     elegir una fila. --}}
@php
    $telefono = preg_replace('/\D/', '', (string) $orden->proveedor->telefono);
@endphp

<div class="bandeja-acciones">
    <x-boton variante="suave" tipo="button" icono="arrow-left" class="bandeja-volver" data-volver>Volver</x-boton>
    <x-boton href="#dialogo-envio" icono="send" descripcion="Enviar por correo" title="Enviar por correo" data-abrir-dialogo />
    {{-- El PDF se baja al apuntar al botón, no con cada orden que se abre. --}}
    <x-boton tipo="button" variante="secundario" icono="whatsapp" descripcion="Compartir por WhatsApp" title="Compartir por WhatsApp" hidden
        data-compartir-pdf
        data-pdf="{{ route('ordenes-compra.pdf', $orden) }}"
        data-marcar="{{ route('ordenes-compra.marcar-enviada', $orden) }}"
        data-archivo="orden-compra-{{ $orden->folio_formateado }}.pdf"
        data-telefono="{{ $telefono }}"
        data-precargar="al-apuntar"
        data-texto="Orden de compra {{ $orden->folio_formateado }} de {{ config('app.name') }} por ${{ number_format((float) $orden->total, 2) }}" />
    @if ($orden->puedeRegistrarPago())
        <x-boton href="#dialogo-pago" variante="secundario" icono="cash-stack" descripcion="Registrar pago" title="Registrar pago" data-abrir-dialogo />
    @endif
    @if ($orden->puedeRecibirse())
        <form method="POST" action="{{ route('ordenes-compra.recibir', $orden) }}">
            @csrf
            <input type="hidden" name="origen" value="bandeja">
            <x-boton variante="secundario" icono="box-seam" descripcion="Marcar como recibida" title="Marcar como recibida" data-confirmar="¿Marcar la orden como recibida? Su mercancía entrará a existencias y ya no podrás cancelar su pago ni editarla." />
        </form>
    @endif
    <form method="POST" action="{{ route('ordenes-compra.duplicar', $orden) }}">
        @csrf
        <input type="hidden" name="origen" value="bandeja">
        <x-boton variante="secundario" icono="copy" descripcion="Duplicar" title="Duplicar" data-enviar-una-vez />
    </form>
    <x-boton :href="route('ordenes-compra.pdf', $orden)" variante="secundario" icono="file-earmark-pdf" descripcion="Ver PDF" title="Ver PDF" target="_blank" />
    <x-boton :href="route('ordenes-compra.pdf', [$orden, 'descargar' => 1])" variante="secundario" icono="download" descripcion="Descargar" title="Descargar" />
    <x-boton :href="route('ordenes-compra.show', $orden)" variante="suave" icono="box-arrow-up-right" class="bandeja-abrir-detalle">Abrir</x-boton>
</div>

<div class="bandeja-documento" data-vista-previa-de="{{ $orden->id }}">
    @include('documentos._aviso-emisor')
    <p class="bandeja-documento-estado">
        <span @class(['etiqueta', $orden->estado->claseEtiqueta()]) data-estado-documento>{{ $orden->estado->etiqueta() }}</span>
        @if ($orden->estaPagada())
            <span class="etiqueta etiqueta-borrador">Pagada desde {{ $orden->cuenta->nombre }} el {{ $orden->fecha_pago->format('d/m/Y') }}</span>
        @endif
    </p>

    <x-ordenes-compra.hoja :orden="$orden" />
</div>

@include('ordenes-compra._dialogos', ['origen' => 'bandeja'])
