{{-- Una página de clientes para la captura del mostrador (033). Tocar una ficha
     elige al cliente; los datos que lee mostrador.js van en data-cliente. --}}
@foreach ($clientes as $cliente)
    <a href="#" class="mostrador-ficha mostrador-ficha-cliente" data-cliente="{{ json_encode(App\Http\Controllers\MostradorController::datosCliente($cliente)) }}">
        <strong class="mostrador-ficha-titulo">{{ $cliente->razon_social }}</strong>
        <span class="mostrador-rfc">{{ $cliente->rfc }}</span>
        @if ($cliente->telefono)
            <span class="mostrador-ficha-dato"><x-icono nombre="telephone" />{{ $cliente->telefono }}</span>
        @endif
        @if ($cliente->correo)
            <span class="mostrador-ficha-dato"><x-icono nombre="envelope" />{{ $cliente->correo }}</span>
        @endif
        @if ($cliente->tieneDescuentoPermanente())
            <span class="etiqueta etiqueta-exitoso">Descuento {{ $cliente->descuentoPermanenteTexto() }}</span>
        @endif
    </a>
@endforeach

@if ($clientes->isEmpty() && $clientes->onFirstPage())
    <p class="mostrador-vacio"><x-icono nombre="people" />Sin clientes con esa búsqueda.</p>
@endif

@if ($clientes->hasMorePages())
    <div class="mostrador-cargando" data-siguiente="{{ $clientes->nextPageUrl() }}">Cargando…</div>
@endif
