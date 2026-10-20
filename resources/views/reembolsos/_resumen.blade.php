{{-- Recuadro del documento reembolsado (038): cuándo, quién, cuánto, por qué,
     los egresos y lo que pasó con la factura. Parámetro: $reembolso. --}}
@php
    $zona = config('app.zona_negocio');
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $avisoFactura = $reembolso->avisoFactura();
    $resumenFactura = $reembolso->resumenFactura();
@endphp

<x-card titulo="Reembolso" data-resumen-reembolso>
    <dl class="datos">
        <div><dt>Fecha</dt><dd>{{ $reembolso->created_at->setTimezone($zona)->format('d/m/Y H:i') }}</dd></div>
        <div><dt>Usuario</dt><dd>{{ $reembolso->user->name }}</dd></div>
        <div><dt>Monto devuelto</dt><dd>{{ $pesos($reembolso->monto) }}</dd></div>
        <div><dt>Motivo</dt><dd>{{ $reembolso->motivo }}</dd></div>
        <div>
            <dt>Egresos</dt>
            <dd>
                @foreach ($reembolso->movimientos as $movimiento)
                    <a href="{{ route('tesoreria.movimientos.index', ['cuenta_id' => $movimiento->cuenta_id]) }}">{{ $movimiento->cuenta->nombre }} {{ $pesos(ltrim($movimiento->monto, '-')) }}</a>@if (! $loop->last) · @endif
                @endforeach
            </dd>
        </div>
        @if ($resumenFactura)
            <div>
                <dt>Factura</dt>
                <dd>
                    @if ($reembolso->factura)
                        <a href="{{ route('facturas.show', $reembolso->factura) }}">{{ $resumenFactura }}</a>
                    @else
                        {{ $resumenFactura }}
                    @endif
                </dd>
            </div>
        @endif
    </dl>

    @if ($avisoFactura)
        <x-alerta tipo="advertencia" data-aviso-factura-reembolso>
            {{ $avisoFactura }}
            @if ($reembolso->puedeReintentarCancelacion())
                <x-boton :href="route('facturas.show', ['factura' => $reembolso->factura, 'regresar_a' => request()->getRequestUri()]).'#dialogo-cancelar'" variante="secundario" icono="x-octagon">Volver a cancelar factura</x-boton>
            @endif
        </x-alerta>
    @endif
</x-card>
