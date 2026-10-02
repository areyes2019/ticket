@extends('layouts.publico')

@section('title', 'Factura tu compra · '.config('negocio.nombre'))

@section('content')
    @if ($pedido === null)
        <h1>Factura tu compra</h1>
        <x-alerta tipo="error">Este enlace no es válido. Comunícate con el negocio para que te envíe uno nuevo.</x-alerta>
    @else
        @php
            $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
        @endphp

        <h1>Factura tu compra</h1>

        <x-card estrecha>
            <dl class="datos">
                <div><dt>Pedido</dt><dd>No. {{ $pedido->numero_ticket }}</dd></div>
                <div><dt>Fecha</dt><dd>{{ $pedido->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</dd></div>
                <div><dt>Total</dt><dd><strong>{{ $pesos($pedido->total) }}</strong></dd></div>
            </dl>
        </x-card>

        @if (session('exito'))
            <x-alerta tipo="exito">{{ session('exito') }}</x-alerta>
        @endif

        @if (session('error'))
            <x-alerta tipo="error">{{ session('error') }}</x-alerta>
        @endif

        @if ($facturaTimbrada)
            <x-card titulo="Tu factura" estrecha>
                <dl class="datos">
                    <div><dt>Folio</dt><dd>{{ $facturaTimbrada->folioFiscal() }}</dd></div>
                    <div><dt>Folio fiscal (UUID)</dt><dd class="texto-uuid">{{ $facturaTimbrada->uuid_fiscal }}</dd></div>
                </dl>
                <div class="acciones">
                    <x-boton :href="route('autofactura.show', [$token, 'descargar' => 'pdf'])" icono="file-earmark-pdf">Descargar PDF</x-boton>
                    <x-boton :href="route('autofactura.show', [$token, 'descargar' => 'xml'])" variante="secundario" icono="filetype-xml">Descargar XML</x-boton>
                </div>
            </x-card>
        @elseif ($motivo !== null)
            <x-alerta tipo="advertencia">{{ $motivo }}</x-alerta>
        @else
            @if ($errors->any())
                <x-alerta tipo="error">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </x-alerta>
            @endif

            <x-card titulo="Tus datos fiscales" estrecha>
                <p class="ayuda">Escríbelos tal como aparecen en tu Constancia de Situación Fiscal. La factura te llega al correo.</p>
                <form method="POST" action="{{ route('autofactura.store', $token) }}">
                    @csrf
                    <x-campo nombre="rfc" etiqueta="RFC" maxlength="13" autocomplete="off" required />
                    <x-campo nombre="razon_social" etiqueta="Nombre o razón social" maxlength="255" required ayuda="Sin el régimen de capital (por ejemplo, sin «S.A. de C.V.»)." />
                    <x-campo nombre="regimen_fiscal" etiqueta="Régimen fiscal" tipo="select" :opciones="$regimenes" required />
                    <x-campo nombre="codigo_postal_fiscal" etiqueta="Código postal fiscal" maxlength="5" inputmode="numeric" pattern="\d{5}" required />
                    <x-campo nombre="uso_cfdi" etiqueta="Uso de CFDI" tipo="select" :opciones="$usosCfdi" valor="G03" required />
                    <x-campo nombre="correo" etiqueta="Correo para recibir la factura" tipo="email" :valor="$pedido->cliente_correo" maxlength="255" required />
                    <div class="acciones">
                        <x-boton icono="receipt" data-enviar-una-vez>Generar mi factura</x-boton>
                    </div>
                </form>
            </x-card>
        @endif
    @endif
@endsection
