@if (session('exito'))
    <x-alerta tipo="exito">{{ session('exito') }}</x-alerta>
@endif

@if (session('error'))
    <x-alerta tipo="error">{{ session('error') }}</x-alerta>
@endif

@if ($errors->any())
    <x-alerta tipo="error">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </x-alerta>
@endif
