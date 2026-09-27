@extends('layouts.app')

@section('title', $cotizacion->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $zona = config('app.zona_negocio');
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $tieneAnticipo = $cotizacion->tieneAnticipo();
    $puedePagar = $cotizacion->puedeRegistrarPago();
    $ultimoPago = $cotizacion->pagos->sortBy('id')->last();
    $tipoLiquidar = $tieneAnticipo ? App\Enums\TipoPago::Saldo : App\Enums\TipoPago::PagoTotal;
    $errorPago = $errors->pago->any() ? old('tipo') : null;
    $telefono = preg_replace('/\D/', '', (string) $cotizacion->cliente->telefono);
@endphp

@section('content')
    <div class="encabezado">
        <h1>
            Cotización {{ $cotizacion->folio_formateado }}
            <span @class(['etiqueta', $cotizacion->estado->claseEtiqueta()]) data-estado-cotizacion>{{ $cotizacion->estado->etiqueta() }}</span>
        </h1>
        <x-boton :href="route('cotizaciones.index')" variante="secundario" icono="arrow-left">Listado</x-boton>
    </div>

    @include('cotizaciones._mensajes')

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

    @if ($cotizacion->mostrarAvisoCaducidad())
        <x-alerta tipo="advertencia" data-aviso-caducidad>
            Sin movimiento desde el {{ $cotizacion->updated_at->setTimezone($zona)->format('d/m/Y') }}.
            Se eliminará automáticamente el {{ $cotizacion->caducaEl()->setTimezone($zona)->format('d/m/Y') }}
            ({{ $cotizacion->diasParaCaducar() === 0 ? 'hoy' : 'en '.$cotizacion->diasParaCaducar().' '.($cotizacion->diasParaCaducar() === 1 ? 'día' : 'días') }}).
            Editarla o reenviarla reinicia el plazo.
        </x-alerta>
    @endif

    <div class="acciones barra-acciones">
        @can('update', $cotizacion)
            <x-boton :href="route('cotizaciones.edit', $cotizacion)" variante="secundario" icono="pencil">Editar</x-boton>
        @endcan

        <x-boton href="#dialogo-envio" variante="secundario" icono="envelope" data-abrir-dialogo>Enviar por correo</x-boton>
        <x-boton tipo="button" variante="secundario" icono="whatsapp" hidden
            data-compartir-cotizacion
            data-pdf="{{ route('cotizaciones.pdf', $cotizacion) }}"
            data-marcar="{{ route('cotizaciones.marcar-enviada', $cotizacion) }}"
            data-archivo="cotizacion-{{ $cotizacion->folio_formateado }}.pdf"
            data-telefono="{{ $telefono }}"
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
                <x-boton variante="secundario" icono="box-seam" data-confirmar="¿Marcar la cotización como entregada?">Marcar como entregado</x-boton>
            </form>
        @endif

        <form method="POST" action="{{ route('cotizaciones.duplicar', $cotizacion) }}">
            @csrf
            <x-boton variante="secundario" icono="copy">Duplicar</x-boton>
        </form>

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

    <x-card titulo="Cliente">
        <dl class="datos">
            <div><dt>Razón social</dt><dd>{{ $cotizacion->cliente->razon_social }}</dd></div>
            <div><dt>RFC</dt><dd>{{ $cotizacion->cliente->rfc }}</dd></div>
            <div><dt>Correo</dt><dd>{{ $cotizacion->cliente->correo ?? '—' }}</dd></div>
            <div><dt>Teléfono</dt><dd>{{ $cotizacion->cliente->telefono ?? '—' }}</dd></div>
            <div><dt>Creada</dt><dd>{{ $cotizacion->created_at->setTimezone($zona)->format('d/m/Y H:i') }}</dd></div>
            <div><dt>Último movimiento</dt><dd>{{ $cotizacion->updated_at->setTimezone($zona)->format('d/m/Y H:i') }}</dd></div>
        </dl>
    </x-card>

    <x-card titulo="Líneas" class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th class="numero">#</th>
                    <th class="numero">Cantidad</th>
                    <th>Descripción</th>
                    <th>Modelo</th>
                    <th class="numero">Precio unitario</th>
                    <th class="numero">Descuento</th>
                    <th>IVA</th>
                    <th class="numero">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cotizacion->lineas as $linea)
                    <tr>
                        <td class="numero">{{ $linea->orden }}</td>
                        <td class="numero">{{ $linea->cantidad }}</td>
                        <td>{{ $linea->descripcion }}</td>
                        <td>{{ $linea->modelo ?? '—' }}</td>
                        <td class="numero">{{ $pesos($linea->precio_unitario) }}</td>
                        <td class="numero">{{ $linea->descuentoTexto() ?: '—' }}</td>
                        <td>{{ $linea->tasa_iva->etiqueta() }}</td>
                        <td class="numero">{{ $pesos($linea->importe) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>

    <x-card titulo="Totales">
        <dl class="resumen-precio">
            <div><dt>Subtotal</dt><dd>{{ $pesos($cotizacion->subtotal) }}</dd></div>
            @if ((float) $cotizacion->total_descuento > 0)
                <div><dt>Descuento</dt><dd>−{{ $pesos($cotizacion->total_descuento) }}</dd></div>
            @endif
            <div><dt>IVA 16%</dt><dd>{{ $pesos($cotizacion->total_iva_16) }}</dd></div>
            <div class="resumen-total"><dt>Total</dt><dd>{{ $pesos($cotizacion->total) }}</dd></div>
        </dl>
    </x-card>

    <x-card titulo="Pagos">
        @if ($cotizacion->pagos->isEmpty())
            <p class="ayuda">Sin pagos registrados.</p>
        @else
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Forma de pago</th>
                        <th class="numero">Monto</th>
                        <th><span class="solo-lectores">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cotizacion->pagos as $pago)
                        <tr>
                            <td>{{ $pago->fecha_pago->format('d/m/Y') }}</td>
                            <td>{{ $pago->tipo->etiqueta() }}</td>
                            <td>{{ $pago->forma_pago->descripcion() }}</td>
                            <td class="numero">{{ $pesos($pago->monto) }}</td>
                            <td>
                                @if ($pago->is($ultimoPago) && $cotizacion->estado !== App\Enums\EstadoCotizacion::ProductoEntregado)
                                    <form method="POST" action="{{ route('cotizaciones.pagos.destroy', [$cotizacion, $pago]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-boton variante="secundario" icono="trash" title="Eliminar pago" descripcion="Eliminar pago de {{ $pesos($pago->monto) }}" data-confirmar="¿Eliminar este pago de {{ $pesos($pago->monto) }}?" />
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

    @if ($puedePagar && ! $tieneAnticipo)
        <dialog id="dialogo-anticipo" class="ficha dialogo" aria-labelledby="dialogo-anticipo-titulo" @if ($errorPago === App\Enums\TipoPago::Anticipo->value) data-abrir-al-cargar @endif>
            <form method="POST" action="{{ route('cotizaciones.pagos.store', $cotizacion) }}">
                @csrf
                <input type="hidden" name="tipo" value="{{ App\Enums\TipoPago::Anticipo->value }}">
                <h2 id="dialogo-anticipo-titulo">Registrar anticipo</h2>
                <p>Saldo pendiente: <strong>{{ $pesos($cotizacion->saldoPendiente()) }}</strong></p>
                <x-campo nombre="fecha_pago" id="anticipo-fecha" etiqueta="Fecha de pago" tipo="date" :valor="$hoy" :max="$hoy" required />
                <x-campo nombre="forma_pago" id="anticipo-forma" etiqueta="Forma de pago" tipo="select" :opciones="$formasPago" vacia="Selecciona la forma de pago" required />
                <x-campo nombre="monto" id="anticipo-monto" etiqueta="Monto" tipo="number" min="0.01" :max="$cotizacion->saldoPendiente()" step="0.01" inputmode="decimal" required />
                <div class="acciones">
                    <x-boton icono="save">Registrar</x-boton>
                    <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
                </div>
            </form>
        </dialog>
    @endif

    @if ($puedePagar)
        <dialog id="dialogo-liquidar" class="ficha dialogo" aria-labelledby="dialogo-liquidar-titulo" @if ($errorPago === $tipoLiquidar->value) data-abrir-al-cargar @endif>
            <form method="POST" action="{{ route('cotizaciones.pagos.store', $cotizacion) }}">
                @csrf
                <input type="hidden" name="tipo" value="{{ $tipoLiquidar->value }}">
                <h2 id="dialogo-liquidar-titulo">{{ $tieneAnticipo ? 'Registrar saldo' : 'Pago total' }}</h2>
                <p>Se registrará el saldo pendiente: <strong data-saldo-pendiente>{{ $pesos($cotizacion->saldoPendiente()) }}</strong></p>
                <x-campo nombre="fecha_pago" id="liquidar-fecha" etiqueta="Fecha de pago" tipo="date" :valor="$hoy" :max="$hoy" required />
                <x-campo nombre="forma_pago" id="liquidar-forma" etiqueta="Forma de pago" tipo="select" :opciones="$formasPago" vacia="Selecciona la forma de pago" required />
                <div class="acciones">
                    <x-boton icono="save">Registrar</x-boton>
                    <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/compartir-cotizacion.js') }}"></script>
@endpush
