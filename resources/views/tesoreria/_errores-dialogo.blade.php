{{-- Errores de una bolsa, dentro de su diálogo. Parámetro: $bolsa. --}}
@if ($errors->{$bolsa}->any())
    <x-alerta tipo="error">
        @foreach ($errors->{$bolsa}->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </x-alerta>
@endif
