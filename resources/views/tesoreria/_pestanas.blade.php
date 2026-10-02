{{-- Navegación interna de Contabilidad (Tesorería). --}}
<nav class="pestanas" aria-label="Contabilidad">
    <a href="{{ route('tesoreria.movimientos.index') }}" @if (request()->routeIs('tesoreria.movimientos.*')) aria-current="page" @endif><x-icono nombre="arrow-left-right" />Movimientos</a>
    <a href="{{ route('tesoreria.cuentas.index') }}" @if (request()->routeIs('tesoreria.cuentas.*')) aria-current="page" @endif><x-icono nombre="wallet2" />Cuentas</a>
    <a href="{{ route('tesoreria.saldos') }}" @if (request()->routeIs('tesoreria.saldos')) aria-current="page" @endif><x-icono nombre="bank" />Saldos</a>
</nav>
