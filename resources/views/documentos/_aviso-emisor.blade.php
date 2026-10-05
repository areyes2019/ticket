{{-- Aviso de emisor incompleto junto a los PDF de cotización y orden de compra
     (026). La factura no lo lleva: su emisor fiscal viene del timbrado. --}}
@unless (App\Models\Emisor::actual()->estaCompleto())
    <x-alerta tipo="advertencia">
        Tus documentos se imprimen sin datos del emisor.
        @can('editar-emisor')
            <a href="{{ route('configuracion.edit') }}#emisor">Captúralos en Configuración</a>.
        @else
            Pídele al administrador que los capture.
        @endcan
    </x-alerta>
@endunless
