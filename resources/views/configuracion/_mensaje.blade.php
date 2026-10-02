{{-- Un mensaje de pedido con su lista de huecos. Parámetros: $clave, $valor, $huecos, $ejemplo. --}}
<x-card :titulo="$clave->etiqueta()">
    <x-campo :nombre="$clave->value" :etiqueta="$clave->etiqueta()" tipo="textarea" :valor="$valor" rows="8" maxlength="2000" :ayuda="$clave->ayuda()" />

    <details class="huecos-mensaje">
        <summary>Datos que se rellenan solos</summary>
        <table class="tabla">
            <thead>
                <tr>
                    <th>Escribe</th>
                    <th>Se reemplaza por</th>
                    <th>Ejemplo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($huecos as $hueco => $descripcion)
                    <tr>
                        <td><code>{{ $hueco }}</code></td>
                        <td>{{ $descripcion }}</td>
                        <td>{{ $ejemplo[$hueco] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</x-card>
