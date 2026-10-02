@extends('layouts.app')

@section('title', 'Movimientos · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@use('App\Enums\TipoMovimiento')

@php
    $pesos = fn ($monto) => '$'.number_format(abs((float) $monto), 2);
    $conSigno = fn ($monto) => ((float) $monto < 0 ? '−' : '+').$pesos($monto);
    $hayFiltros = collect($filtros)->contains(fn ($valor) => $valor !== '');
@endphp

@section('content')
    <div class="encabezado">
        <h1>Contabilidad</h1>
        @if ($cuentasActivas !== [])
            <div class="acciones">
                <x-boton href="#dialogo-ingreso" icono="plus-circle" data-abrir-dialogo>Registrar ingreso</x-boton>
                <x-boton href="#dialogo-egreso" variante="secundario" icono="dash-circle" data-abrir-dialogo>Registrar egreso</x-boton>
                <x-boton href="#dialogo-transferencia" variante="secundario" icono="arrow-left-right" data-abrir-dialogo>Registrar transferencia</x-boton>
                <x-boton href="#dialogo-ajuste" variante="secundario" icono="sliders" data-abrir-dialogo>Registrar ajuste</x-boton>
            </div>
        @endif
    </div>

    @include('tesoreria._pestanas')
    @include('tesoreria._mensajes')

    @if ($cuentasActivas === [])
        <x-alerta tipo="advertencia">
            Para registrar movimientos, primero crea una cuenta.
            <a href="{{ route('tesoreria.cuentas.create') }}">Crear una cuenta</a>
        </x-alerta>
    @endif

    <form method="GET" action="{{ route('tesoreria.movimientos.index') }}" class="buscador">
        <x-campo nombre="fecha_desde" etiqueta="Desde" tipo="date" :valor="$filtros['fecha_desde']" />
        <x-campo nombre="fecha_hasta" etiqueta="Hasta" tipo="date" :valor="$filtros['fecha_hasta']" />
        <x-campo nombre="cuenta_id" id="filtro-cuenta" etiqueta="Cuenta" tipo="select" :opciones="$cuentas" vacia="Todas" :valor="$filtros['cuenta_id']" />
        <x-campo nombre="tipo" id="filtro-tipo" etiqueta="Tipo" tipo="select" :opciones="$tipos" vacia="Todos" :valor="$filtros['tipo']" />
        <x-campo nombre="concepto" id="filtro-concepto" etiqueta="Concepto" tipo="search" :valor="$filtros['concepto']" />
        <x-boton icono="funnel">Filtrar</x-boton>
        @if ($hayFiltros)
            <x-boton :href="route('tesoreria.movimientos.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
        @endif
    </form>

    <x-card class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Cuenta</th>
                    <th>Tipo</th>
                    <th>Concepto</th>
                    <th class="numero">Monto</th>
                    <th class="numero">Utilidad</th>
                    <th>Origen</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($movimientos as $movimiento)
                    @php
                        $origen = $movimiento->documentoOrigen();
                        $contraparte = $contrapartes[$movimiento->id] ?? null;
                    @endphp
                    <tr @class(['fila-automatica' => $movimiento->esAutomatico()])>
                        <td>{{ $movimiento->fecha->format('d/m/Y') }}</td>
                        <td><span class="celda-truncada" title="{{ $movimiento->cuenta->nombre }}">{{ $movimiento->cuenta->nombre }}</span></td>
                        <td>{{ $movimiento->tipo->etiqueta() }}</td>
                        <td><span class="celda-truncada" title="{{ $movimiento->concepto }}">{{ $movimiento->concepto }}</span></td>
                        <td @class(['numero', 'monto-positivo' => $movimiento->sumaAlSaldo(), 'monto-negativo' => ! $movimiento->sumaAlSaldo()])>{{ $conSigno($movimiento->monto) }}</td>
                        <td class="numero">
                            @if ($origen !== null)
                                @if ($origen['utilidad'] === null)
                                    <span class="texto-discreto" title="La cotización no tiene costo capturado en sus líneas.">— No disponible</span>
                                @else
                                    <span @class(['monto-positivo' => (float) $origen['utilidad'] >= 0, 'monto-negativo' => (float) $origen['utilidad'] < 0])>{{ ((float) $origen['utilidad'] < 0 ? '−' : '').$pesos($origen['utilidad']) }}</span>
                                    @if ($origen['utilidad_parcial'])
                                        <span class="texto-discreto" title="La cotización tiene líneas sin costo conocido; no se incluyen en la utilidad.">Parcial</span>
                                    @endif
                                @endif
                            @endif
                        </td>
                        <td>
                            @if ($origen !== null)
                                <a href="{{ $origen['url'] }}">{{ $origen['etiqueta'] }}</a>
                                <span class="etiqueta etiqueta-automatico">Automático</span>
                            @elseif ($movimiento->esTransferencia())
                                Transferencia
                                @if ($contraparte)
                                    {{ $movimiento->sumaAlSaldo() ? '← '.$contraparte->nombre : '→ '.$contraparte->nombre }}
                                @endif
                            @else
                                Manual
                            @endif
                        </td>
                        <td>
                            <div class="acciones">
                                @if ($movimiento->esAutomatico())
                                    <x-boton variante="suave" icono="pencil" tipo="button" disabled title="Se corrige desde {{ $origen['etiqueta'] ?? 'su documento origen' }}" descripcion="Editar (se corrige desde {{ $origen['etiqueta'] ?? 'su documento origen' }})" />
                                    <x-boton variante="secundario" icono="trash" tipo="button" disabled title="Se corrige desde {{ $origen['etiqueta'] ?? 'su documento origen' }}" descripcion="Eliminar (se corrige desde {{ $origen['etiqueta'] ?? 'su documento origen' }})" />
                                @else
                                    @unless ($movimiento->esTransferencia())
                                        <x-boton :href="route('tesoreria.movimientos.edit', $movimiento)" variante="suave" icono="pencil" title="Editar" descripcion="Editar {{ $movimiento->concepto }}" />
                                    @endunless
                                    <form method="POST" action="{{ route('tesoreria.movimientos.destroy', $movimiento) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $movimiento->concepto }}"
                                            data-confirmar="{{ $movimiento->esTransferencia() ? 'Se eliminarán los dos movimientos de esta transferencia.' : '¿Eliminar este movimiento?' }}" />
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            {{ $hayFiltros ? 'No hay movimientos con estos filtros.' : 'Todavía no hay movimientos.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <x-paginacion :paginador="$movimientos" />

    @if ($cuentasActivas !== [])
        @include('tesoreria.movimientos._dialogo-movimiento', ['tipo' => TipoMovimiento::Ingreso, 'titulo' => 'Registrar ingreso'])
        @include('tesoreria.movimientos._dialogo-movimiento', ['tipo' => TipoMovimiento::Egreso, 'titulo' => 'Registrar egreso'])
        @include('tesoreria.movimientos._dialogo-movimiento', ['tipo' => TipoMovimiento::Ajuste, 'titulo' => 'Registrar ajuste'])
        @include('tesoreria.movimientos._dialogo-transferencia')
    @endif
@endsection
