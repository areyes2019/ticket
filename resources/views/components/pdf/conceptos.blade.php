{{-- Tabla de conceptos de los PDF (026). Unidad solo en factura (dato del CFDI).
     La Clave SAT es la copia de la línea en factura y la del artículo en las demás
     (la relación ya trae los borrados); una línea de texto libre la deja vacía. --}}
@props([
    'lineas',
    'etiquetaPrecio' => 'Precio unitario',
    'conUnidad' => false,
    'descuentoCfdi' => false,
])

@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
@endphp

<table class="conceptos">
    <thead>
        <tr>
            <th class="numero">Cant.</th>
            @if ($conUnidad)
                <th>Unidad</th>
            @endif
            <th>Clave SAT</th>
            <th>Descripción</th>
            <th>Modelo</th>
            <th class="numero">{{ $etiquetaPrecio }}</th>
            <th class="numero">Desc.</th>
            <th>IVA</th>
            <th class="numero">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lineas as $linea)
            <tr>
                <td class="numero">{{ $linea->cantidad }}</td>
                @if ($conUnidad)
                    <td>{{ $linea->clave_unidad }}</td>
                @endif
                <td>{{ $conUnidad ? $linea->clave_prod_serv : $linea->articulo?->clave_prod_serv }}</td>
                <td>{{ $linea->descripcion }}</td>
                <td>{{ $linea->modelo }}</td>
                <td class="numero">{{ $pesos($linea->precio_unitario) }}</td>
                @if ($descuentoCfdi)
                    <td class="numero">{{ (float) $linea->descuentoCfdi() > 0 ? $pesos($linea->descuentoCfdi()) : '—' }}</td>
                @else
                    <td class="numero">{{ $linea->descuentoTexto() ?: '—' }}</td>
                @endif
                <td>{{ $linea->tasa_iva->etiqueta() }}</td>
                <td class="numero">{{ $pesos($linea->importe) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
