{{-- Cotizaciones que se pueden facturar ya. Cada una abre el formulario de
     factura lleno con sus datos. La pinta la página facturas.cotizaciones y
     llega por AJAX a la ventana "Desde cotización" (?fragmento=1). --}}
@if ($cotizaciones->isEmpty())
    <p class="ayuda" data-sin-cotizaciones>No hay cotizaciones por facturar.</p>
@else
    <ul class="lista-cotizaciones-facturables">
        @foreach ($cotizaciones as $cotizacion)
            <li>
                <a href="{{ route('facturas.create', ['cotizacion' => $cotizacion->id]) }}">
                    <strong>{{ $cotizacion->folio_formateado }}</strong>
                    <span>{{ $cotizacion->cliente->razon_social }}</span>
                    <span class="ayuda">{{ $cotizacion->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</span>
                    <span @class(['etiqueta', $cotizacion->estado->claseEtiqueta()])>{{ $cotizacion->estado->etiqueta() }}</span>
                    <span class="total">${{ number_format((float) $cotizacion->total, 2) }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
