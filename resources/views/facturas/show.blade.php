@extends('layouts.app')

@section('title', $factura->folioVisible().' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $zona = config('app.zona_negocio');
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $receptor = $factura->receptor();
    $emisor = $factura->emisor();
    $complemento = $factura->complementoPago;
@endphp

@section('content')
    <div class="encabezado">
        <h1>
            Factura {{ $factura->folioVisible() }}
            <span @class(['etiqueta', $factura->estado->claseEtiqueta()])>{{ $factura->estado->etiqueta() }}</span>
            @if ($factura->cancelacionEnCurso())
                <span class="etiqueta etiqueta-suspendido">Cancelación en proceso</span>
            @endif
        </h1>
        <x-boton :href="route('facturas.index')" variante="secundario" icono="arrow-left">Listado</x-boton>
    </div>

    @include('documentos._mensajes')

    @foreach (['envio', 'cancelacion', 'complemento'] as $bolsa)
        @if ($errors->{$bolsa}->any())
            <x-alerta tipo="error">
                @foreach ($errors->{$bolsa}->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif
    @endforeach

    <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

    @if ($sinConsultaCancelacion)
        <x-alerta tipo="advertencia">No se pudo consultar el estado de la cancelación en facturapi.io; se muestra el último conocido.</x-alerta>
    @endif

    @if ($factura->puedeReintentarse() && ! session('error'))
        {{-- Reintentar es seguro aunque el error haya sido un timeout: la idempotency_key evita un segundo CFDI. --}}
        <x-alerta tipo="error">No se pudo timbrar: {{ $factura->error_timbrado }}</x-alerta>
    @endif

    <div class="acciones barra-acciones">
        @if ($factura->puedeReintentarse())
            <form method="POST" action="{{ route('facturas.timbrar', $factura) }}">
                @csrf
                <x-boton icono="arrow-repeat" data-enviar-una-vez>Reintentar timbrado</x-boton>
            </form>
        @endif

        @can('update', $factura)
            <x-boton :href="route('facturas.edit', $factura)" variante="secundario" icono="pencil">Corregir datos</x-boton>
        @endcan

        @if ($factura->puedeEnviarse())
            <x-boton href="#dialogo-envio" variante="secundario" icono="envelope" data-abrir-dialogo>Enviar por correo</x-boton>
        @endif

        @if ($factura->tieneDocumentoFiscal())
            <x-boton :href="route('facturas.xml', $factura)" variante="secundario" icono="filetype-xml">Descargar XML</x-boton>
            <x-boton :href="route('facturas.pdf', $factura)" variante="secundario" icono="file-earmark-pdf" target="_blank">Ver PDF</x-boton>
            <x-boton :href="route('facturas.pdf', [$factura, 'descargar' => 1])" variante="secundario" icono="download">Descargar PDF</x-boton>
            {{-- Se muestra solo si el navegador puede compartir archivos (compartir-pdf.js). --}}
            <span class="compartir-pdf" data-compartir-contenedor hidden>
                <x-boton tipo="button" variante="secundario" icono="share"
                    data-compartir-pdf
                    data-pdf="{{ route('facturas.pdf', $factura) }}"
                    data-archivo="{{ $factura->nombreArchivo('pdf') }}"
                    data-precargar="al-cargar">Compartir PDF</x-boton>
                <span class="ayuda">Por aquí va el PDF; el XML se manda por correo.</span>
            </span>
        @endif

        @if ($factura->puedeCancelarse())
            <x-boton href="#dialogo-cancelar" variante="secundario" icono="x-octagon" data-abrir-dialogo>Cancelar factura</x-boton>
        @endif

        @if ($factura->puedeRegistrarComplemento())
            <x-boton href="#dialogo-complemento" variante="secundario" icono="cash-stack" data-abrir-dialogo>{{ $complemento ? 'Reintentar complemento de pago' : 'Registrar complemento de pago' }}</x-boton>
        @endif

        <x-boton href="#dialogo-duplicar" variante="secundario" icono="copy" data-abrir-dialogo>Duplicar</x-boton>

        @can('delete', $factura)
            <form method="POST" action="{{ route('facturas.destroy', $factura) }}">
                @csrf
                @method('DELETE')
                <x-boton variante="peligro" icono="trash" data-confirmar="¿Eliminar la factura {{ $factura->folio_formateado }}? No está timbrada; el borrado es definitivo y se lleva sus líneas.">Eliminar</x-boton>
            </form>
        @endcan
    </div>

    <x-card titulo="Comprobante">
        <dl class="datos">
            <div><dt>Folio interno</dt><dd>{{ $factura->folio_formateado }}</dd></div>
            <div><dt>Serie y folio fiscal</dt><dd>{{ $factura->folioFiscal() ?? '—' }}</dd></div>
            <div><dt>UUID (folio fiscal SAT)</dt><dd class="texto-uuid">{{ $factura->uuid_fiscal ?? '—' }}</dd></div>
            <div><dt>Fecha de timbrado</dt><dd>{{ $factura->fecha_timbrado?->setTimezone($zona)->format('d/m/Y H:i:s') ?? '—' }}</dd></div>
            <div><dt>Uso de CFDI</dt><dd>{{ $factura->uso_cfdi->value }} – {{ $factura->uso_cfdi->descripcion() }}</dd></div>
            <div><dt>Método de pago</dt><dd>{{ $factura->metodo_pago->value }} – {{ $factura->metodo_pago->etiqueta() }}</dd></div>
            <div><dt>Forma de pago</dt><dd>{{ $factura->forma_pago->value }} – {{ $factura->forma_pago->descripcion() }}</dd></div>
            <div><dt>Moneda / tipo</dt><dd>{{ App\Models\Factura::MONEDA }} · Ingreso</dd></div>
            <div><dt>Creada</dt><dd>{{ $factura->created_at->setTimezone($zona)->format('d/m/Y H:i') }}</dd></div>
            @if ($factura->cotizacion)
                <div><dt>Origen</dt><dd><a href="{{ route('cotizaciones.show', $factura->cotizacion) }}">{{ $factura->cotizacion->folio_formateado }}</a></dd></div>
            @endif
            @if ($factura->pedido)
                <div><dt>Origen</dt><dd><a href="{{ route('pedidos.show', $factura->pedido) }}">Venta {{ $factura->pedido->folio_formateado }}</a> (autofactura)</dd></div>
            @endif
            @if ($factura->duplicadaDe)
                <div><dt>Duplicada de</dt><dd><a href="{{ route('facturas.show', $factura->duplicadaDe) }}">{{ $factura->duplicadaDe->folioVisible() }}</a></dd></div>
            @endif
            @if ($factura->motivo_cancelacion)
                <div><dt>Motivo de cancelación</dt><dd>{{ $factura->motivo_cancelacion->value }} – {{ $factura->motivo_cancelacion->descripcion() }}</dd></div>
                @if ($factura->sustituta)
                    <div><dt>Sustituida por</dt><dd><a href="{{ route('facturas.show', $factura->sustituta) }}">{{ $factura->sustituta->folioVisible() }}</a></dd></div>
                @endif
                <div><dt>Cancelada el</dt><dd>{{ $factura->fecha_cancelacion?->setTimezone($zona)->format('d/m/Y H:i') ?? 'En proceso' }}</dd></div>
            @endif
        </dl>
    </x-card>

    <x-card titulo="Emisor y receptor">
        <dl class="datos">
            <div><dt>Emisor</dt><dd>{{ $emisor ? $emisor['razon_social'].' — '.$emisor['rfc'] : 'Se toma de facturapi.io al timbrar' }}</dd></div>
            <div><dt>Receptor</dt><dd>{{ $receptor['razon_social'] }}</dd></div>
            <div><dt>RFC</dt><dd>{{ $receptor['rfc'] }}</dd></div>
            <div><dt>Régimen fiscal</dt><dd>{{ $receptor['regimen_fiscal'] }} – {{ App\Enums\RegimenFiscal::tryFrom($receptor['regimen_fiscal'])?->descripcion() }}</dd></div>
            <div><dt>Código postal</dt><dd>{{ $receptor['codigo_postal'] }}</dd></div>
            <div><dt>Correo</dt><dd>{{ $receptor['correo'] ?? '—' }}</dd></div>
        </dl>
    </x-card>

    <x-card titulo="Líneas" class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th class="numero">#</th>
                    <th class="numero">Cantidad</th>
                    <th>Clave SAT</th>
                    <th>Descripción</th>
                    <th>Modelo</th>
                    <th class="numero">Precio unitario</th>
                    <th class="numero">Descuento</th>
                    <th>IVA</th>
                    <th class="numero">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($factura->lineas as $linea)
                    <tr>
                        <td class="numero">{{ $linea->orden }}</td>
                        <td class="numero">{{ $linea->cantidad }}</td>
                        <td>{{ $linea->clave_prod_serv }} · {{ $linea->clave_unidad }}</td>
                        <td>{{ $linea->descripcion }}</td>
                        <td>{{ $linea->modelo }}</td>
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
            <div><dt>Subtotal</dt><dd>{{ $pesos($factura->subtotal) }}</dd></div>
            @if ((float) $factura->total_descuento > 0)
                <div><dt>Descuento</dt><dd>−{{ $pesos($factura->total_descuento) }}</dd></div>
            @endif
            <div><dt>IVA 16%</dt><dd>{{ $pesos($factura->total_iva_16) }}</dd></div>
            <div class="resumen-total"><dt>Total</dt><dd>{{ $pesos($factura->total) }}</dd></div>
        </dl>
    </x-card>

    @if ($factura->uuid_fiscal)
        <x-card titulo="Sellos digitales">
            <dl class="datos datos-sellos">
                <div><dt>No. de certificado SAT</dt><dd>{{ $factura->no_certificado_sat }}</dd></div>
                <div><dt>Sello digital del CFDI</dt><dd class="texto-sello">{{ $factura->sello_cfdi }}</dd></div>
                <div><dt>Sello del SAT</dt><dd class="texto-sello">{{ $factura->sello_sat }}</dd></div>
                <div><dt>Cadena original del complemento de certificación</dt><dd class="texto-sello">{{ $factura->cadena_original_sat }}</dd></div>
            </dl>
        </x-card>
    @endif

    @if ($complemento)
        <x-card titulo="Complemento de pago">
            <dl class="datos">
                <div><dt>Estado</dt><dd>{{ $complemento->estado->etiqueta() }}</dd></div>
                <div><dt>Fecha de pago</dt><dd>{{ $complemento->fecha_pago->format('d/m/Y') }}</dd></div>
                <div><dt>Monto</dt><dd>{{ $pesos($complemento->monto) }}</dd></div>
                <div><dt>Forma de pago</dt><dd>{{ $complemento->forma_pago->value }} – {{ $complemento->forma_pago->descripcion() }}</dd></div>
                @if ($complemento->uuid_fiscal)
                    <div><dt>UUID</dt><dd class="texto-uuid">{{ $complemento->uuid_fiscal }}</dd></div>
                @endif
                @if ($complemento->error_timbrado)
                    <div><dt>Error</dt><dd>{{ $complemento->error_timbrado }}</dd></div>
                @endif
            </dl>
        </x-card>
    @endif

    {{-- Diálogos. Sin JavaScript se muestran con el enlace (#id) gracias a :target. --}}
    @include('documentos._dialogo-duplicar', [
        'titulo' => 'Duplicar '.$factura->folioVisible(),
        'accion' => route('facturas.create'),
        'metodo' => 'GET',
        'ocultos' => ['duplicar' => $factura->id],
        'clienteActual' => $factura->cliente_id,
        'ayuda' => 'Se abre el formulario de una factura nueva con las mismas líneas, para revisarla antes de timbrar.',
    ])

    @if ($factura->puedeEnviarse())
        <dialog id="dialogo-envio" class="ficha dialogo" aria-labelledby="dialogo-envio-titulo" @if ($errors->envio->any()) data-abrir-al-cargar @endif>
            <form method="POST" action="{{ route('facturas.enviar', $factura) }}">
                @csrf
                <h2 id="dialogo-envio-titulo">Enviar por correo</h2>
                <x-campo nombre="destinatarios_texto" etiqueta="Destinatarios" :valor="$receptor['correo']" required
                    ayuda="Separa varios correos con comas (máximo {{ App\Http\Requests\EnviarFacturaRequest::MAX_DESTINATARIOS }}). Se adjuntan el XML y el PDF." />
                <div class="acciones">
                    <x-boton icono="send" data-enviar-una-vez>Enviar</x-boton>
                    <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
                </div>
            </form>
        </dialog>
    @endif

    @if ($factura->puedeCancelarse())
        <dialog id="dialogo-cancelar" class="ficha dialogo" aria-labelledby="dialogo-cancelar-titulo" @if ($errors->cancelacion->any()) data-abrir-al-cargar @endif>
            <form method="POST" action="{{ route('facturas.cancelar', $factura) }}" data-formulario-cancelacion>
                @csrf
                <h2 id="dialogo-cancelar-titulo">Cancelar factura {{ $factura->folioVisible() }}</h2>
                <p>La cancelación se solicita al SAT por medio de facturapi.io. Puede requerir la aceptación del receptor.</p>
                <x-campo nombre="motivo_cancelacion" etiqueta="Motivo" tipo="select" :opciones="$motivosCancelacion" vacia="Selecciona el motivo" required data-motivo-cancelacion />
                <div data-campo-sustituta>
                    <x-campo nombre="factura_sustituta_id" etiqueta="Factura que la sustituye (solo motivo 01)" tipo="select" :opciones="$sustitutas" vacia="Selecciona la factura sustituta" />
                </div>
                <div class="acciones">
                    <x-boton variante="peligro" icono="x-octagon" data-enviar-una-vez>Solicitar cancelación</x-boton>
                    <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Volver</x-boton>
                </div>
            </form>
        </dialog>
    @endif

    @if ($factura->puedeRegistrarComplemento())
        <dialog id="dialogo-complemento" class="ficha dialogo" aria-labelledby="dialogo-complemento-titulo" @if ($errors->complemento->any()) data-abrir-al-cargar @endif>
            <form method="POST" action="{{ route('facturas.complemento-pago', $factura) }}">
                @csrf
                <h2 id="dialogo-complemento-titulo">Complemento de pago</h2>
                <p>Total de la factura: <strong>{{ $pesos($factura->total) }}</strong>. Se timbra como un CFDI de pago aparte.</p>
                <x-campo nombre="fecha_pago" etiqueta="Fecha de pago" tipo="date" :valor="$complemento?->fecha_pago?->toDateString() ?? $hoy" :max="$hoy" required />
                <x-campo nombre="monto" etiqueta="Monto" tipo="number" :valor="$complemento?->monto ?? $factura->total" min="0.01" :max="$factura->total" step="0.01" inputmode="decimal" required />
                <x-campo nombre="forma_pago" etiqueta="Forma de pago" tipo="select" :opciones="$formasPagoComplemento" :valor="$complemento?->forma_pago?->value" vacia="Selecciona la forma de pago" required />
                <div class="acciones">
                    <x-boton icono="save" data-enviar-una-vez>Timbrar complemento</x-boton>
                    <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
    <script src="{{ asset('js/facturas.js') }}?v={{ filemtime(public_path('js/facturas.js')) }}"></script>
@endpush
