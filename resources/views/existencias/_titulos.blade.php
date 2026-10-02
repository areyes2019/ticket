@php
    $columnas = [
        'modelo' => 'Modelo',
        'catalogo' => 'Catálogo',
        'existencia' => 'Existencia',
        'faltante' => 'Faltante',
        'minimo' => 'Mínimo',
        'maximo' => 'Máximo',
        'invertido' => 'Invertido',
        'beneficio' => 'Beneficio',
    ];
    $numericas = ['existencia', 'faltante', 'minimo', 'maximo', 'invertido', 'beneficio'];
@endphp

{{-- Cada título ordena por su columna (salvo el máximo); un segundo clic invierte la dirección. --}}
<tr id="existencias-titulos">
    @foreach ($columnas as $columna => $titulo)
        @php
            $activa = $orden === $columna;
            $siguiente = $activa && $direccion === 'asc' ? 'desc' : 'asc';
            $enlace = route('existencias.index', array_filter([
                ...Illuminate\Support\Arr::except($parametros, ['orden', 'direccion']),
                'orden' => $columna,
                'direccion' => $siguiente === 'desc' ? 'desc' : null,
            ]));
        @endphp
        <th @class(['numero' => in_array($columna, $numericas, true)]) @if ($activa) aria-sort="{{ $direccion === 'asc' ? 'ascending' : 'descending' }}" @endif>
            @if (in_array($columna, App\Http\Requests\ListadoExistenciasRequest::ORDENES, true))
                <a href="{{ $enlace }}" class="orden" data-busqueda-enlace>
                    {{ $titulo }}<x-icono :nombre="$activa ? ($direccion === 'asc' ? 'arrow-up' : 'arrow-down') : 'arrow-down-up'" />
                </a>
            @else
                {{ $titulo }}
            @endif
        </th>
    @endforeach
</tr>
