{{-- Ventana de ingreso, egreso o ajuste manual. Parámetros: $tipo
     (TipoMovimiento), $titulo, $cuentasActivas, $hoy. Con errores de su bolsa
     se abre sola. --}}
@php
    $bolsa = $tipo->value;
    $esAjuste = $tipo === App\Enums\TipoMovimiento::Ajuste;
@endphp

<dialog id="dialogo-{{ $bolsa }}" class="ficha dialogo" aria-labelledby="dialogo-{{ $bolsa }}-titulo" @if ($errors->{$bolsa}->any()) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ route('tesoreria.movimientos.store') }}">
        @csrf
        <input type="hidden" name="tipo" value="{{ $bolsa }}">
        <h2 id="dialogo-{{ $bolsa }}-titulo">{{ $titulo }}</h2>

        @include('tesoreria._errores-dialogo', ['bolsa' => $bolsa])

        <x-campo nombre="cuenta_id" id="{{ $bolsa }}-cuenta" etiqueta="Cuenta" tipo="select" :opciones="$cuentasActivas" vacia="Selecciona la cuenta" required />
        @if ($esAjuste)
            <x-campo nombre="monto" id="{{ $bolsa }}-monto" etiqueta="Monto" tipo="number" step="0.01" inputmode="decimal" required
                ayuda="Usa un monto negativo para restar." />
        @else
            <x-campo nombre="monto" id="{{ $bolsa }}-monto" etiqueta="Monto" tipo="number" step="0.01" min="0.01" inputmode="decimal" required />
        @endif
        <x-campo nombre="fecha" id="{{ $bolsa }}-fecha" etiqueta="Fecha" tipo="date" :valor="$hoy" :max="$hoy" required />
        <x-campo nombre="concepto" id="{{ $bolsa }}-concepto" :etiqueta="$esAjuste ? 'Motivo' : 'Concepto'" maxlength="255" required />

        <div class="acciones">
            <x-boton icono="save" data-enviar-una-vez>Registrar</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
