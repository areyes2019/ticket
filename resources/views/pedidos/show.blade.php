@extends('layouts.app')

@section('title', $pedido->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $zona = config('app.zona_negocio');
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $puedeEliminarPago = $pedido->puedeEliminarPago();
    // Una sola factura entre la cotización aceptada y su venta (021).
    $facturaDeLaCotizacion = $pedido->facturaDeLaCotizacion();
@endphp

@section('content')
    <div class="encabezado">
        <h1>
            Venta {{ $pedido->folio_formateado }}
            <span @class(['etiqueta', $pedido->estado->claseEtiqueta()])>{{ $pedido->estado->etiqueta() }}</span>
            @if ($facturaTimbrada)
                <a href="{{ route('facturas.show', $facturaTimbrada) }}" class="etiqueta etiqueta-facturada" title="Ver la factura">Facturada · {{ $facturaTimbrada->folioVisible() }}</a>
            @elseif ($facturaDeLaCotizacion)
                <a href="{{ route('facturas.show', $facturaDeLaCotizacion) }}" class="etiqueta etiqueta-facturada" title="Ver la factura (desde la cotización)">Facturada · {{ $facturaDeLaCotizacion->folioVisible() }}</a>
            @endif
        </h1>
        <x-boton :href="route('pedidos.index')" variante="secundario" icono="arrow-left">Listado</x-boton>
    </div>

    @include('documentos._mensajes')

    @if ($errors->pago->any())
        <x-alerta tipo="error">
            @foreach ($errors->pago->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-alerta>
    @endif

    <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

    @if (filled($pedido->autofactura_error))
        <x-alerta tipo="advertencia">
            El cliente intentó facturar y no pudo: {{ $pedido->autofactura_error }}
            @if ($pedido->facturaVigente)
                Revisa la <a href="{{ route('facturas.show', $pedido->facturaVigente) }}">factura {{ $pedido->facturaVigente->folio_formateado }}</a>.
            @endif
        </x-alerta>
    @endif

    <div class="detalle-documento">
        <div class="detalle-acciones">
            @if ($pedido->puedeRegistrarPago())
                <x-boton href="#dialogo-pago" icono="cash" data-abrir-dialogo>Agregar pago</x-boton>
            @endif

            @if ($pedido->puedeCompartirTicket())
                <x-boton tipo="button" variante="secundario" icono="whatsapp" hidden
                    data-compartir-pdf
                    data-pdf="{{ route('pedidos.ticket', $pedido) }}"
                    data-tipo="image/jpeg"
                    data-archivo="ticket-{{ $pedido->folio_formateado }}.jpg"
                    data-texto="{{ $mensajeTicket }}"
                    data-sufijo=""
                    data-descargar-sin-menu
                    data-precargar="al-apuntar">Compartir ticket</x-boton>
            @endif

            <x-boton :href="route('pedidos.etiqueta', $pedido)" variante="secundario" icono="printer" target="_blank">Imprimir etiqueta</x-boton>

            @if ($pedido->ordenTrabajo)
                <x-boton :href="route('pedidos.orden-trabajo.show', $pedido)" variante="secundario" icono="clipboard-check">Orden de trabajo</x-boton>
            @elseif ($pedido->puedeCrearOrdenTrabajo())
                <x-boton :href="route('pedidos.orden-trabajo.create', $pedido)" variante="secundario" icono="clipboard-plus">Orden de trabajo</x-boton>
            @elseif (! $pedido->estaEntregado())
                <x-boton tipo="button" variante="secundario" icono="clipboard-plus" disabled title="{{ $pedido->motivoNoCreaOrdenTrabajo() }}">Orden de trabajo</x-boton>
            @endif

            @if ($pedido->puedeCompartirTicket() && $mensajeListo !== null)
                <x-boton href="https://wa.me/?text={{ rawurlencode($mensajeListo) }}" variante="secundario" icono="bell" target="_blank" rel="noopener">Avisar que está listo</x-boton>
            @endif

            @if ($textoAutofactura !== null)
                <x-boton href="https://wa.me/?text={{ rawurlencode($textoAutofactura) }}" variante="secundario" icono="receipt" target="_blank" rel="noopener">Compartir enlace de autofactura</x-boton>
            @endif

            @unless ($pedido->estaEntregado())
                <x-boton :href="route('pedidos.entregar', $pedido)" variante="secundario" icono="box-arrow-right">Entregar</x-boton>
            @endunless

            @can('update', $pedido)
                <x-boton :href="route('pedidos.edit', $pedido)" variante="secundario" icono="pencil">Editar</x-boton>
            @endcan

            @can('delete', $pedido)
                <form method="POST" action="{{ route('pedidos.destroy', $pedido) }}">
                    @csrf
                    @method('DELETE')
                    <x-boton variante="peligro" icono="trash" data-confirmar="¿Eliminar la venta {{ $pedido->folio_formateado }}? Sus artículos regresan a existencias y el borrado es definitivo.{{ $pedido->cotizacion ? ' La cotización '.$pedido->cotizacion->folio_formateado.' volverá a Enviada.' : '' }}">Eliminar</x-boton>
                </form>
            @endcan
        </div>

        <div class="detalle-principal">
            @if ($pedido->ordenTrabajo)
                <p class="ayuda" data-orden-trabajo>Orden de trabajo: <span @class(['etiqueta', $pedido->ordenTrabajo->estado->claseEtiqueta()])>{{ $pedido->ordenTrabajo->estado->etiqueta() }}</span> <a href="{{ route('pedidos.orden-trabajo.show', $pedido) }}">Ver orden</a></p>
            @endif

            @if ($pedido->cotizacion)
                <p class="ayuda" data-origen-cotizacion>Origen: cotización <a href="{{ route('cotizaciones.show', $pedido->cotizacion) }}">{{ $pedido->cotizacion->folio_formateado }}</a> aceptada.</p>
            @endif

            <article class="hoja" aria-label="Venta {{ $pedido->folio_formateado }}">
                <header class="hoja-encabezado">
                    <p class="hoja-negocio">{{ config('negocio.nombre') }}</p>
                    <p class="hoja-folio">
                        <strong>Ticket No. {{ $pedido->numero_ticket }}</strong><br>
                        <span class="hoja-suave">{{ $pedido->created_at->setTimezone($zona)->format('d/m/Y H:i') }}</span>
                    </p>
                </header>

                <dl class="hoja-cliente">
                    <div>
                        <dt>Cliente</dt>
                        <dd>
                            <strong>{{ $pedido->cliente_nombre }}</strong>
                            @if ($pedido->cliente)
                                <br><span class="hoja-suave">{{ $pedido->cliente->razon_social }} · {{ $pedido->cliente->rfc }}</span>
                            @endif
                        </dd>
                    </div>
                    <div><dt>Teléfono</dt><dd>{{ $pedido->telefono_legible }}</dd></div>
                    @if ($pedido->cliente_correo)
                        <div><dt>Correo</dt><dd>{{ $pedido->cliente_correo }}</dd></div>
                    @endif
                    @if ($pedido->entregado_en)
                        <div><dt>Entregado</dt><dd>{{ $pedido->entregado_en->setTimezone($zona)->format('d/m/Y H:i') }}</dd></div>
                    @endif
                </dl>

                <div class="hoja-lineas">
                    <table>
                        <thead>
                            <tr>
                                <th class="numero">Cant.</th>
                                <th>Descripción</th>
                                <th>Modelo</th>
                                <th class="numero">Precio unitario</th>
                                <th class="numero">Descuento</th>
                                <th class="numero">Importe</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pedido->lineas as $linea)
                                <tr>
                                    <td class="numero">{{ $linea->cantidad }}</td>
                                    <td>{{ $linea->descripcion }} @if ($linea->articulo_id === null)<span class="hoja-suave">(libre)</span>@endif</td>
                                    <td>{{ $linea->modelo }}</td>
                                    <td class="numero">{{ $pesos($linea->precio_unitario) }}</td>
                                    <td class="numero">{{ $linea->descuentoTexto() }}</td>
                                    <td class="numero">{{ $pesos($linea->importe) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="hoja-totales">
                    <div><dt>Subtotal</dt><dd>{{ $pesos($pedido->subtotal) }}</dd></div>
                    @if ((float) $pedido->total_descuento > 0)
                        <div><dt>Descuento</dt><dd>−{{ $pesos($pedido->total_descuento) }}</dd></div>
                    @endif
                    <div><dt>IVA 16%</dt><dd>{{ $pesos($pedido->total_iva_16) }}</dd></div>
                    <div class="hoja-total"><dt>Total</dt><dd>{{ $pesos($pedido->total) }}</dd></div>
                    <div><dt>Pagado</dt><dd>{{ $pesos($pedido->totalPagado()) }}</dd></div>
                    <div class="hoja-saldo"><dt>Saldo pendiente</dt><dd>{{ $pesos($pedido->saldoPendiente()) }}</dd></div>
                </dl>
            </article>

            <x-card titulo="Pagos">
                @if ($pedido->pagos->isEmpty())
                    <p class="ayuda">Sin pagos registrados. El ticket se comparte a partir del primer pago.</p>
                @else
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Cuenta</th>
                                <th class="numero">Monto</th>
                                <th><span class="solo-lectores">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pedido->pagos as $pago)
                                <tr>
                                    <td>
                                        {{ $pago->fecha_pago->format('d/m/Y') }}
                                        @if ($pago->registrado_al_entregar)
                                            <span class="etiqueta etiqueta-automatico">Al entregar</span>
                                        @endif
                                    </td>
                                    <td>{{ $pago->cuenta->nombre }}</td>
                                    <td class="numero">{{ $pesos($pago->monto) }}</td>
                                    <td>
                                        @if ($puedeEliminarPago)
                                            <form method="POST" action="{{ route('pedidos.pagos.destroy', [$pedido, $pago]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-boton variante="secundario" icono="trash" title="Eliminar pago" descripcion="Eliminar pago de {{ $pesos($pago->monto) }}" data-confirmar="¿Eliminar este pago de {{ $pesos($pago->monto) }}? También se eliminará su ingreso en Contabilidad." />
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-card>

            @if ($textoAutofactura !== null)
                <x-card titulo="Enlace de autofactura">
                    <x-campo nombre="enlace_autofactura" etiqueta="El cliente se factura aquí" :valor="$pedido->urlAutofactura()" readonly
                        ayuda="Vence el {{ $pedido->autofacturaVenceEl()->format('d/m/Y') }}. Solo sirve para una factura." />
                </x-card>
            @endif

            <x-card titulo="Ticket">
                <img src="{{ route('pedidos.ticket', $pedido) }}" alt="Ticket de la venta {{ $pedido->folio_formateado }}" class="ticket-vista-previa" loading="lazy">
                @unless ($pedido->puedeCompartirTicket())
                    <p class="ayuda">Vista previa: el ticket se podrá compartir en cuanto se registre el primer pago.</p>
                @endunless
            </x-card>
        </div>
    </div>

    @if ($pedido->puedeRegistrarPago())
        <dialog id="dialogo-pago" class="ficha dialogo" aria-labelledby="dialogo-pago-titulo" @if ($errors->pago->any()) data-abrir-al-cargar @endif>
            <form method="POST" action="{{ route('pedidos.pagos.store', $pedido) }}">
                @csrf
                <h2 id="dialogo-pago-titulo">Agregar pago</h2>
                <p>Saldo pendiente: <strong>{{ $pesos($pedido->saldoPendiente()) }}</strong></p>
                <x-campo nombre="fecha_pago" id="pago-fecha" etiqueta="Fecha de pago" tipo="date" :valor="$hoy" :max="$hoy" required />
                @include('cotizaciones._cuenta-pago', ['id' => 'pago-cuenta'])
                <x-campo nombre="monto" id="pago-monto" etiqueta="Monto" tipo="number" :valor="$pedido->saldoPendiente()" min="0.01" :max="$pedido->saldoPendiente()" step="0.01" inputmode="decimal" required />
                <div class="acciones">
                    <x-boton icono="save" :disabled="$cuentas === []" data-enviar-una-vez>Registrar</x-boton>
                    <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
@endpush
