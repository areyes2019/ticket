@extends('layouts.app')

@section('title', 'Escribir por WhatsApp · '.config('app.name'))

@section('content')
    <h1>Escribir por WhatsApp</h1>

    @include('documentos._mensajes')

    <x-card titulo="{{ $cliente->nombre_contacto ?: $cliente->razon_social }} · {{ App\Services\Whatsapp\Telefono::legible($cliente->telefono) }}">
        <p class="ayuda">Este cliente no tiene conversación. Para escribirle primero hay que mandar una plantilla aprobada por Meta. La conversación queda en tus manos, con el agente pausado.</p>

        @include('whatsapp._dialogo-plantilla', ['accion' => route('whatsapp.plantilla.nueva', $cliente)])

        <div class="acciones">
            <x-boton href="#dialogo-plantilla" icono="card-text" data-abrir-dialogo>Elegir plantilla</x-boton>
            <x-boton :href="route('clientes.edit', $cliente)" variante="secundario" icono="arrow-left">Volver al cliente</x-boton>
        </div>
    </x-card>
@endsection

@push('scripts')
    <script src="{{ asset('js/whatsapp.js') }}?v={{ filemtime(public_path('js/whatsapp.js')) }}"></script>
@endpush
