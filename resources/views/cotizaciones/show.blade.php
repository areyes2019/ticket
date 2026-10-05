@extends('layouts.app')

@section('title', $cotizacion->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $zona = config('app.zona_negocio');
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $tieneAnticipo = $cotizacion->tieneAnticipo();
    $puedePagar = $cotizacion->puedeRegistrarPago();
    $ultimoPago = $cotizacion->pagos->sortBy('id')->last();
    $telefono = preg_replace('/\D/', '', (string) $cotizacion->cliente->telefono);
    $facturaVigente = $cotizacion->facturaVigente;
    $motivoNoFacturable = $cotizacion->motivoNoFacturable();
    $venta = $cotizacion->venta;
    $ventaEntregada = (bool) $venta?->estaEntregado();
@endphp

@section('content')
    <div class="encabezado">
        <h1>
            Cotización {{ $cotizacion->folio_formateado }}
            <span @class(['etiqueta', $cotizacion->estado->claseEtiqueta()]) data-estado-documento>{{ $cotizacion->estado->etiqueta() }}</span>
            @if ($facturaVigente)
                <a href="{{ route('facturas.show', $facturaVigente) }}" class="etiqueta etiqueta-facturada" title="Ver la factura">Facturada · {{ $facturaVigente->folioVisible() }}</a>
            @endif
            @if ($cotizacion->venta)
                <a href="{{ route('pedidos.show', $cotizacion->venta) }}" class="etiqueta etiqueta-venta" title="Ver la venta" data-venta-de-cotizacion>Venta · {{ $cotizacion->venta->folio_formateado }}</a>
            @endif
        </h1>
        <x-boton :href="route('cotizaciones.index')" variante="secundario" icono="arrow-left">Listado</x-boton>
    </div>

    @include('documentos._mensajes')
    @include('documentos._aviso-emisor')

    @foreach (['envio', 'pago'] as $bolsa)
        @if ($errors->{$bolsa}->any())
            <x-alerta tipo="error">
                @foreach ($errors->{$bolsa}->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif
    @endforeach

    <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

    @if ($cotizacion->duplicadaDe)
        <p class="ayuda">Duplicada de <a href="{{ route('cotizaciones.show', $cotizacion->duplicadaDe) }}">{{ $cotizacion->duplicadaDe->folio_formateado }}</a></p>
    @endif

    {{-- Se podría facturar por estado, pero alguna línea no viene del catálogo. --}}
    @if ($motivoNoFacturable !== null && $cotizacion->esFacturable())
        <x-alerta tipo="advertencia">{{ $motivoNoFacturable }}</x-alerta>
    @endif

    @if ($cotizacion->mostrarAvisoCaducidad())
        <x-alerta tipo="advertencia" data-aviso-caducidad>
            Sin movimiento desde el {{ $cotizacion->updated_at->setTimezone($zona)->format('d/m/Y') }}.
            Se eliminará automáticamente el {{ $cotizacion->caducaEl()->setTimezone($zona)->format('d/m/Y') }}
            ({{ $cotizacion->diasParaCaducar() === 0 ? 'hoy' : 'en '.$cotizacion->diasParaCaducar().' '.($cotizacion->diasParaCaducar() === 1 ? 'día' : 'días') }}).
            Editarla o reenviarla reinicia el plazo.
        </x-alerta>
    @endif

    @if ($cotizacion->estaAceptada() && $venta)
        @if ($venta->cobro_en_cotizacion)
            <p class="ayuda" data-venta-cobra-aqui>
                Aceptada el {{ $cotizacion->aceptada_en?->setTimezone($zona)->format('d/m/Y') }} con su primer pago.
                Venta: <a href="{{ route('pedidos.show', $venta) }}">{{ $venta->folio_formateado }}</a>.
                @if ($venta->ordenTrabajo)
                    Orden de trabajo: <a href="{{ route('pedidos.orden-trabajo.show', $venta) }}">{{ $venta->ordenTrabajo->estado->etiqueta() }}</a>.
                @endif
                Los pagos se registran aquí; el ticket y la entrega, en la venta.
            </p>
        @else
            <p class="ayuda">
                Aceptada el {{ $cotizacion->aceptada_en?->setTimezone($zona)->format('d/m/Y') }}. El cobro, el ticket y la entrega siguen en la venta
                <a href="{{ route('pedidos.show', $venta) }}">{{ $venta->folio_formateado }}</a>.
            </p>
        @endif
    @endif

    <div class="detalle-documento">
        <div class="detalle-acciones">
            @if ($motivoNoFacturable === null)
                <x-boton :href="route('facturas.create', ['cotizacion' => $cotizacion->id])" icono="receipt">Facturar</x-boton>
            @endif

            @can('update', $cotizacion)
                <x-boton :href="route('cotizaciones.edit', $cotizacion)" variante="secundario" icono="pencil">Editar</x-boton>
            @endcan

            <x-boton href="#dialogo-envio" variante="secundario" icono="envelope" data-abrir-dialogo>Enviar por correo</x-boton>
            <x-boton tipo="button" variante="secundario" icono="whatsapp" hidden
                data-compartir-pdf
                data-pdf="{{ route('cotizaciones.pdf', $cotizacion) }}"
                data-marcar="{{ route('cotizaciones.marcar-enviada', $cotizacion) }}"
                data-archivo="cotizacion-{{ $cotizacion->folio_formateado }}.pdf"
                data-telefono="{{ $telefono }}"
                data-precargar="al-cargar"
                data-texto="Cotización {{ $cotizacion->folio_formateado }} de {{ config('app.name') }} por {{ $pesos($cotizacion->total) }}">Compartir por WhatsApp</x-boton>

            @if ($puedePagar && ! $tieneAnticipo)
                <x-boton href="#dialogo-anticipo" variante="secundario" icono="cash" data-abrir-dialogo>Registrar anticipo</x-boton>
            @endif
            @if ($puedePagar)
                <x-boton href="#dialogo-liquidar" variante="secundario" icono="cash-stack" data-abrir-dialogo>{{ $tieneAnticipo ? 'Registrar saldo' : 'Pago total' }}</x-boton>
            @endif

            @if ($cotizacion->puedeEntregarse())
                <form method="POST" action="{{ route('cotizaciones.entregar', $cotizacion) }}">
                    @csrf
                    <x-boton variante="secundario" icono="box-seam" data-confirmar="¿Marcar la cotización como entregada? Sus artículos saldrán de existencias.">Marcar como entregado</x-boton>
                </form>
            @endif

            <x-boton href="#dialogo-duplicar" variante="secundario" icono="copy" data-abrir-dialogo>Duplicar</x-boton>

            <x-boton :href="route('cotizaciones.pdf', $cotizacion)" variante="secundario" icono="file-earmark-pdf" target="_blank">Ver PDF</x-boton>
            <x-boton :href="route('cotizaciones.pdf', [$cotizacion, 'descargar' => 1])" variante="secundario" icono="download">Descargar PDF</x-boton>

            @can('delete', $cotizacion)
                <form method="POST" action="{{ route('cotizaciones.destroy', $cotizacion) }}">
                    @csrf
                    @method('DELETE')
                    <x-boton variante="peligro" icono="trash" data-confirmar="¿Eliminar la cotización {{ $cotizacion->folio_formateado }}? El borrado es definitivo: se lleva sus líneas y no hay papelera.">Eliminar</x-boton>
                </form>
            @endcan
        </div>

        <div class="detalle-principal">
            <x-cotizaciones.hoja :cotizacion="$cotizacion" />

            <p class="ayuda">Último movimiento: {{ $cotizacion->updated_at->setTimezone($zona)->format('d/m/Y H:i') }}</p>

            <x-card titulo="Pagos">
                @if ($cotizacion->pagos->isEmpty())
                    <p class="ayuda">Sin pagos registrados.</p>
                @else
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th>Cuenta</th>
                                <th class="numero">Monto</th>
                                <th><span class="solo-lectores">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cotizacion->pagos as $pago)
                                <tr>
                                    <td>{{ $pago->fecha_pago->format('d/m/Y') }}</td>
                                    <td>{{ $pago->tipo->etiqueta() }}</td>
                                    <td>{{ $pago->cuenta->nombre }}</td>
                                    <td class="numero">{{ $pesos($pago->monto) }}</td>
                                    <td>
                                        @if ($pago->is($ultimoPago) && $cotizacion->estado !== App\Enums\EstadoCotizacion::ProductoEntregado && ! $ventaEntregada)
                                            <form method="POST" action="{{ route('cotizaciones.pagos.destroy', [$cotizacion, $pago]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-boton variante="secundario" icono="trash" title="Eliminar pago" descripcion="Eliminar pago de {{ $pesos($pago->monto) }}" data-confirmar="¿Eliminar este pago de {{ $pesos($pago->monto) }}? También se eliminará su ingreso en Contabilidad y, si la cotización estaba pagada, regresará a Enviada." />
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <dl class="resumen-precio">
                    <div><dt>Pagado</dt><dd>{{ $pesos($cotizacion->totalPagado()) }}</dd></div>
                    <div class="resumen-total"><dt>Saldo pendiente</dt><dd>{{ $pesos($cotizacion->saldoPendiente()) }}</dd></div>
                </dl>
            </x-card>
        </div>
    </div>

    {{-- Diálogos. Sin JavaScript se muestran con el enlace (#id) gracias a :target. --}}
    <dialog id="dialogo-envio" class="ficha dialogo" aria-labelledby="dialogo-envio-titulo" @if ($errors->envio->any()) data-abrir-al-cargar @endif>
        <form method="POST" action="{{ route('cotizaciones.enviar', $cotizacion) }}">
            @csrf
            <h2 id="dialogo-envio-titulo">Enviar por correo</h2>
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
        'ayuda' => 'La copia nace en borrador, con las mismas líneas y precios.',
    ])

    @include('cotizaciones._dialogos-pago')
@endsection

@push('scripts')
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
@endpush
