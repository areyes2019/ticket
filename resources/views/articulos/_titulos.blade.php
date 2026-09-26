@php
    $columnas = ['nombre' => 'Nombre', 'modelo' => 'Modelo', 'proveedor' => 'Proveedor', 'precio' => 'Precio con IVA'];
@endphp

{{-- Cada título ordena por su columna; un segundo clic invierte la dirección. --}}
<tr id="articulos-titulos">
    @foreach ($columnas as $columna => $titulo)
        @php
            $activa = $orden === $columna;
            $siguiente = $activa && $direccion === 'asc' ? 'desc' : 'asc';
            $enlace = route('articulos.index', array_filter([
                ...Illuminate\Support\Arr::except($parametros, ['orden', 'direccion']),
                'orden' => $columna,
                'direccion' => $siguiente === 'desc' ? 'desc' : null,
            ]));
        @endphp
        <th @if ($activa) aria-sort="{{ $direccion === 'asc' ? 'ascending' : 'descending' }}" @endif>
            <a href="{{ $enlace }}" class="orden" data-busqueda-enlace>
                {{ $titulo }}<x-icono :nombre="$activa ? ($direccion === 'asc' ? 'arrow-up' : 'arrow-down') : 'arrow-down-up'" />
            </a>
        </th>
    @endforeach
    <th>Acciones</th>
</tr>
