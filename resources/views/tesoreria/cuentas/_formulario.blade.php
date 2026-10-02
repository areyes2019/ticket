@include('tesoreria._mensajes')

<form method="POST" action="{{ $accion }}">
    @csrf
    @isset($cuenta)
        @method('PUT')
    @endisset

    <x-campo nombre="nombre" etiqueta="Nombre" :valor="$cuenta->nombre ?? null" maxlength="100" required autofocus
        ayuda="Por ejemplo: Caja General, BBVA, Mercado Pago." />
    <x-campo nombre="tipo" etiqueta="Tipo" tipo="select" :opciones="$tipos" :valor="isset($cuenta) ? $cuenta->tipo->value : null" vacia="Selecciona el tipo" required />

    @isset($cuenta)
        {{-- El saldo inicial es fijo desde el alta: se muestra, pero no se envía. --}}
        <x-campo nombre="saldo_inicial_fijo" etiqueta="Saldo inicial" :valor="'$'.number_format((float) $cuenta->saldo_inicial, 2)" disabled
            ayuda="El saldo inicial no se modifica. Para corregirlo, registra un ajuste en Movimientos." />
        <x-campo nombre="activa" etiqueta="Cuenta activa" tipo="checkbox" :valor="$cuenta->activa" />
    @else
        <x-campo nombre="saldo_inicial" etiqueta="Saldo inicial" tipo="number" step="0.01" min="0" inputmode="decimal" :valor="0" required
            ayuda="Lo que hay hoy en la cuenta. Después ya no se cambia: las correcciones se hacen con un ajuste." />
    @endisset

    <div class="acciones">
        <x-boton icono="save">Guardar</x-boton>
        <x-boton :href="route('tesoreria.cuentas.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>
