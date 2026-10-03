{{-- Aviso del descuento permanente del cliente (023), dentro del formulario y
     sobre la tabla de líneas. Su presencia es lo que activa el descuento en
     documento-lineas.js: lleva los descuentos de los clientes y, al editar, el
     congelado de la cotización (que manda mientras no se cambie de cliente).
     El JS lo muestra, lo oculta y le cambia nombre y porcentaje. Espera
     $descuentosClientes y $cotizacion (null en el alta). --}}
@php
    $porcentajeTexto = App\Models\Cliente::porcentajeTexto(...);
    $congelado = $cotizacion === null ? null : [
        'cliente_id' => $cotizacion->cliente_id,
        'nombre' => $cotizacion->cliente->razon_social,
        'porcentaje' => $porcentajeTexto($cotizacion->descuento_cliente_porcentaje),
    ];
    $clienteElegido = (string) old('cliente_id', $cotizacion?->cliente_id);
    $inicial = $congelado !== null && $clienteElegido === (string) $congelado['cliente_id']
        ? $congelado
        : ($descuentosClientes[$clienteElegido] ?? null);

    if ($inicial !== null && (float) $inicial['porcentaje'] <= 0) {
        $inicial = null;
    }
@endphp
<x-alerta tipo="info" :hidden="$inicial === null"
          data-aviso-descuento-cliente
          :data-descuentos-cliente="json_encode((object) $descuentosClientes)"
          :data-descuento-congelado="$congelado === null ? null : json_encode($congelado)">
    <p>
        <strong data-aviso-descuento-nombre>{{ $inicial['nombre'] ?? '' }}</strong> tiene un descuento permanente de
        <strong><span data-aviso-descuento-porcentaje>{{ $inicial['porcentaje'] ?? '' }}</span>%</strong>, ya aplicado en cada línea.
        Puedes modificarlo línea por línea si esta cotización es una excepción.
    </p>
</x-alerta>
