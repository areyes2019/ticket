<tbody id="clientes-filas" aria-live="polite">
    @forelse ($clientes as $cliente)
        <tr>
            <td>{{ $cliente->razon_social }}</td>
            <td>{{ $cliente->nombre_comercial ?? '—' }}</td>
            <td>{{ $cliente->nombre_contacto ?? '—' }}</td>
            <td>{{ $cliente->rfc }}</td>
            <td title="{{ $cliente->regimen_fiscal->descripcion() }}">{{ $cliente->regimen_fiscal->value }}</td>
            <td>{{ $cliente->telefono ?? '—' }}</td>
            <td class="numero">{{ $cliente->descuentoPermanenteTexto() }}</td>
            <td>
                <div class="acciones">
                    <x-boton :href="route('clientes.edit', $cliente)" variante="suave" icono="pencil" title="Editar" descripcion="Editar {{ $cliente->razon_social }}" />

                    <form method="POST" action="{{ route('clientes.destroy', $cliente) }}">
                        @csrf
                        @method('DELETE')
                        <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $cliente->razon_social }}" data-confirmar="¿Eliminar este cliente?" />
                    </form>
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="8">
                {{ array_filter($filtros) !== [] ? 'Ningún cliente coincide con la búsqueda.' : 'Todavía no tienes clientes registrados.' }}
            </td>
        </tr>
    @endforelse
</tbody>
