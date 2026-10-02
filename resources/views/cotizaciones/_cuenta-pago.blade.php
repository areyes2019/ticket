{{-- Cuenta a la que entra el pago (Tesorería). Parámetros: $cuentas, $id. --}}
@if ($cuentas === [])
    <x-alerta tipo="advertencia">
        Para registrar pagos, primero crea una cuenta en Contabilidad.
        <a href="{{ route('tesoreria.cuentas.create') }}">Crear una cuenta</a>
    </x-alerta>
@else
    <x-campo nombre="cuenta_id" :id="$id" etiqueta="Cuenta" tipo="select" :opciones="$cuentas" vacia="Selecciona la cuenta" required />
@endif
