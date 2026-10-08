@extends('layouts.mostrador')

@section('title', 'Nuevo cliente · Mostrador')

@section('content')
    {{--
        Alta de cliente del mostrador (033): la constancia de 006 precarga el
        formulario (archivo o foto; el QR lo lee el navegador o el servidor) y
        el alta va a clientes.store con las mismas reglas del escritorio. Al
        guardar regresa a la captura con el cliente elegido.
    --}}
    <div class="mostrador-alta">
        <p class="mostrador-indicador">
            <strong>Nuevo cliente</strong>
            <x-boton :href="route('mostrador.'.$flujo)" variante="suave" icono="arrow-left">Volver</x-boton>
        </p>

        @if ($errors->any())
            <x-alerta tipo="error">Revisa los datos marcados.</x-alerta>
        @endif

        @include('clientes._constancia', [
            'urlExistente' => route('mostrador.'.$flujo).'?cliente={id}',
            'textoAbrir' => 'Usar este cliente',
        ])

        <form method="POST" action="{{ route('clientes.store') }}" class="mostrador-formulario-cliente">
            @csrf
            <input type="hidden" name="origen" value="mostrador">
            <input type="hidden" name="flujo" value="{{ $flujo }}">
            <input type="hidden" name="es_distribuidor" value="0">
            <input type="hidden" name="descuento_permanente" value="0">

            <x-card titulo="Datos fiscales">
                <x-campo nombre="rfc" etiqueta="RFC" maxlength="13" autocomplete="off" autocapitalize="characters" required />
                <x-campo nombre="razon_social" etiqueta="Razón social" maxlength="255" autocomplete="off" required />
                <x-campo nombre="regimen_fiscal" etiqueta="Régimen fiscal" tipo="select" :opciones="$regimenes" required />
                <x-campo nombre="codigo_postal_fiscal" etiqueta="Código postal fiscal" inputmode="numeric" maxlength="5" autocomplete="off" required />
            </x-card>

            <x-card titulo="Contacto">
                <x-campo nombre="telefono" etiqueta="Teléfono (opcional)" tipo="tel" inputmode="tel" maxlength="20" autocomplete="off" />
                <x-campo nombre="correo" etiqueta="Correo (opcional)" tipo="email" maxlength="255" autocomplete="off" ayuda="Es a donde se le manda el documento." />
            </x-card>

            <div class="mostrador-pie">
                <x-boton icono="check-lg" bloque data-enviar-una-vez>Guardar y seguir</x-boton>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/constancia-fiscal.js') }}?v={{ filemtime(public_path('js/constancia-fiscal.js')) }}"></script>
@endpush
