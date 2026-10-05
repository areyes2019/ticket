{{-- Aviso de cliente distribuidor (028), dentro del formulario y sobre la tabla de líneas.
     Su presencia es lo que activa el precio distribuidor en documento-lineas.js: lleva los
     clientes distribuidores ({id: razón social}). El JS lo muestra, lo oculta y le cambia el
     nombre. Espera $distribuidores, $clienteInicial (el cliente del documento o null) y, en la
     cotización, $excepcion (true) para invitar a cambiar el precio línea por línea. --}}
@php
    $clienteElegido = (string) old('cliente_id', $clienteInicial);
    $nombreInicial = $distribuidores[$clienteElegido] ?? null;
@endphp
<x-alerta tipo="info" :hidden="$nombreInicial === null"
          data-aviso-distribuidor
          :data-clientes-distribuidores="json_encode((object) $distribuidores)">
    <p>
        <strong data-aviso-distribuidor-nombre>{{ $nombreInicial }}</strong> es distribuidor: cada línea usa el precio distribuidor.
        @if ($excepcion ?? false)
            Puedes cambiarlo línea por línea si esta cotización es una excepción.
        @endif
    </p>
</x-alerta>
