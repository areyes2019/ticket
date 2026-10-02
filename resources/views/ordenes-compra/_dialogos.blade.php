{{-- Ventanas de envío por correo y de pago, del detalle y de la vista previa de
     la bandeja. Parámetros: $orden, $cuentas (activas), $hoy y, opcional,
     $origen (a dónde regresar). --}}
@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
@endphp

<dialog id="dialogo-envio" class="ficha dialogo" aria-labelledby="dialogo-envio-titulo" @if ($errors->envio->any()) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ route('ordenes-compra.enviar', $orden) }}">
        @csrf
        @isset($origen)
            <input type="hidden" name="origen" value="{{ $origen }}">
        @endisset
        <h2 id="dialogo-envio-titulo">Enviar {{ $orden->folio_formateado }} por correo</h2>
        <x-campo nombre="destinatarios_texto" etiqueta="Destinatarios" :valor="$orden->proveedor->correo" required
            ayuda="Separa varios correos con comas (máximo {{ App\Http\Requests\EnviarOrdenCompraRequest::MAX_DESTINATARIOS }}). Se adjunta el PDF." />
        <div class="acciones">
            <x-boton icono="send" data-enviar-una-vez>Enviar</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>

@if ($orden->puedeRegistrarPago())
    <dialog id="dialogo-pago" class="ficha dialogo" aria-labelledby="dialogo-pago-titulo" @if ($errors->pago->any()) data-abrir-al-cargar @endif>
        <form method="POST" action="{{ route('ordenes-compra.pago.store', $orden) }}">
            @csrf
            @isset($origen)
                <input type="hidden" name="origen" value="{{ $origen }}">
            @endisset
            <h2 id="dialogo-pago-titulo">Registrar pago</h2>
            @if ($errors->pago->any())
                <x-alerta tipo="error">
                    @foreach ($errors->pago->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </x-alerta>
            @endif
            <p>Se pagará el total de la orden: <strong>{{ $pesos($orden->total) }}</strong></p>
            <x-campo nombre="fecha_pago" id="pago-fecha" etiqueta="Fecha de pago" tipo="date" :valor="$hoy" :max="$hoy" required />
            @include('cotizaciones._cuenta-pago', ['id' => 'pago-cuenta'])
            <div class="acciones">
                <x-boton icono="save" :disabled="$cuentas === []" data-enviar-una-vez>Registrar</x-boton>
                <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
            </div>
        </form>
    </dialog>
@endif
