@props(['factura'])

{{-- La factura como hoja de papel, con los datos del PDF. La usa el visor del
     dashboard. Espera cliente y lineas cargados. --}}
@php
    $zona = config('app.zona_negocio');
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $receptor = $factura->receptor();
    $emisor = $factura->emisor();
@endphp

<article {{ $attributes->class('hoja') }} aria-label="Factura {{ $factura->folioVisible() }}">
    <header class="hoja-encabezado">
        <p class="hoja-negocio">{{ $emisor['razon_social'] ?? config('app.name') }}</p>
        <p class="hoja-folio">
            <strong>Factura {{ $factura->folioVisible() }}</strong><br>
            <span class="hoja-suave">{{ ($factura->fecha_timbrado ?? $factura->created_at)->setTimezone($zona)->format('d/m/Y') }}</span>
        </p>
    </header>

    <dl class="hoja-cliente">
        @if ($emisor)
            <div><dt>Emisor</dt><dd>{{ $emisor['rfc'] }}</dd></div>
        @endif
        <div><dt>Receptor</dt><dd><strong>{{ $receptor['razon_social'] }}</strong></dd></div>
        <div><dt>RFC</dt><dd>{{ $receptor['rfc'] }}</dd></div>
        <div><dt>Régimen</dt><dd>{{ $receptor['regimen_fiscal'] }} – {{ App\Enums\RegimenFiscal::tryFrom($receptor['regimen_fiscal'])?->descripcion() }}</dd></div>
        <div><dt>C.P.</dt><dd>{{ $receptor['codigo_postal'] }}</dd></div>
        <div><dt>Uso CFDI</dt><dd>{{ $factura->uso_cfdi->value }} – {{ $factura->uso_cfdi->descripcion() }}</dd></div>
        <div><dt>Pago</dt><dd>{{ $factura->metodo_pago->value }} · {{ $factura->forma_pago->value }} – {{ $factura->forma_pago->descripcion() }}</dd></div>
        @if ($factura->uuid_fiscal)
            <div><dt>UUID</dt><dd class="texto-uuid">{{ $factura->uuid_fiscal }}</dd></div>
        @endif
    </dl>

    <div class="hoja-lineas">
        <table>
            <thead>
                <tr>
                    <th class="numero">Cant.</th>
                    <th>Clave SAT</th>
                    <th>Descripción</th>
                    <th class="numero">Precio unitario</th>
                    <th class="numero">Descuento</th>
                    <th class="numero">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($factura->lineas as $linea)
                    <tr>
                        <td class="numero">{{ $linea->cantidad }}</td>
                        <td>{{ $linea->clave_prod_serv }} · {{ $linea->clave_unidad }}</td>
                        <td>{{ $linea->descripcion }}@if ($linea->modelo) <span class="hoja-suave">· {{ $linea->modelo }}</span>@endif</td>
                        <td class="numero">{{ $pesos($linea->precio_unitario) }}</td>
                        <td class="numero">{{ $linea->descuentoTexto() }}</td>
                        <td class="numero">{{ $pesos($linea->importe) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <dl class="hoja-totales">
        <div><dt>Subtotal</dt><dd>{{ $pesos($factura->subtotal) }}</dd></div>
        @if ((float) $factura->total_descuento > 0)
            <div><dt>Descuento</dt><dd>−{{ $pesos($factura->total_descuento) }}</dd></div>
        @endif
        <div><dt>IVA 16%</dt><dd>{{ $pesos($factura->total_iva_16) }}</dd></div>
        <div class="hoja-total"><dt>Total</dt><dd>{{ $pesos($factura->total) }}</dd></div>
    </dl>
</article>
