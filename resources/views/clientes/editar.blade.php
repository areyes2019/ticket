@extends('layouts.app')

@section('title', 'Editar cliente · '.config('app.name'))

@section('content')
    <h1>Editar cliente</h1>

    {{-- 041, regla 60: abre su conversación o, si no tiene, el envío de una plantilla. --}}
    @if (auth()->user()->whatsappNumero && App\Services\Whatsapp\Telefono::diezDigitos($cliente->telefono))
        <div class="acciones">
            <x-boton :href="route('whatsapp.cliente.abrir', $cliente)" variante="secundario" icono="whatsapp">Escribir por WhatsApp</x-boton>
        </div>
    @endif

    @include('clientes._formulario', ['accion' => route('clientes.update', $cliente)])
@endsection
