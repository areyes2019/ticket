{{-- Ventana de transferencia entre dos cuentas propias. Parámetros:
     $cuentasActivas, $hoy. --}}
<dialog id="dialogo-transferencia" class="ficha dialogo" aria-labelledby="dialogo-transferencia-titulo" @if ($errors->transferencia->any()) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ route('tesoreria.transferencias.store') }}">
        @csrf
        <h2 id="dialogo-transferencia-titulo">Registrar transferencia</h2>

        @include('tesoreria._errores-dialogo', ['bolsa' => 'transferencia'])

        <x-campo nombre="cuenta_origen_id" id="transferencia-origen" etiqueta="Cuenta origen" tipo="select" :opciones="$cuentasActivas" vacia="Selecciona la cuenta" required />
        <x-campo nombre="cuenta_destino_id" id="transferencia-destino" etiqueta="Cuenta destino" tipo="select" :opciones="$cuentasActivas" vacia="Selecciona la cuenta" required />
        <x-campo nombre="monto" id="transferencia-monto" etiqueta="Monto" tipo="number" step="0.01" min="0.01" inputmode="decimal" required />
        <x-campo nombre="fecha" id="transferencia-fecha" etiqueta="Fecha" tipo="date" :valor="$hoy" :max="$hoy" required />
        <x-campo nombre="concepto" id="transferencia-concepto" etiqueta="Concepto" maxlength="255" required />

        <div class="acciones">
            <x-boton icono="save" data-enviar-una-vez>Registrar</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
