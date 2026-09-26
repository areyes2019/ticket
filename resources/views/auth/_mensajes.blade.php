@if (session('status'))
    <x-alerta tipo="exito">{{ session('status') }}</x-alerta>
@endif

@if ($errors->any())
    <x-alerta tipo="error">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </x-alerta>
@endif
