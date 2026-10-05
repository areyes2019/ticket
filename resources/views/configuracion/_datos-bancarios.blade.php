{{-- Datos bancarios (027): cuentas a las que el cliente paga, impresas en el
     encabezado del PDF de las cotizaciones nuevas. Del negocio, como el
     emisor; no son las Cuentas de Tesorería. --}}
<x-card titulo="Datos bancarios" id="datos-bancarios">
    <div class="encabezado">
        <p class="ayuda">Se imprimen en el encabezado del PDF de las cotizaciones nuevas, en este orden. Las cotizaciones ya creadas conservan los datos con los que se hicieron.</p>
        <x-boton :href="route('configuracion.datos-bancarios.create')" icono="plus-lg">Agregar banco</x-boton>
    </div>

    @if ($datosBancarios->isEmpty())
        <p>Todavía no hay bancos. Agrega la cuenta, tarjeta o CLABE a la que te pagan para que tus clientes la vean en cada cotización.</p>
    @else
        <table class="tabla datos-bancarios">
            <thead>
                <tr>
                    <th>Banco</th>
                    <th>Números</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($datosBancarios as $dato)
                    <tr @class(['banco-oculto' => ! $dato->visible_en_cotizaciones])>
                        <td>
                            <span class="banco-nombre">
                                @if ($dato->tiene_logo)
                                    <img class="icono-banco" src="{{ route('configuracion.datos-bancarios.logo', [$dato, 'v' => $dato->logo_version]) }}" alt="">
                                @endif
                                <strong>{{ $dato->nombre_banco }}</strong>
                            </span>
                            @if ($dato->beneficiario)
                                <br>{{ $dato->beneficiario }}
                            @endif
                            @unless ($dato->visible_en_cotizaciones)
                                <br><span class="etiqueta etiqueta-inactiva">No se muestra en cotizaciones</span>
                            @endunless
                        </td>
                        <td>
                            @if ($dato->numero_cuenta)
                                Cta: {{ $dato->numero_cuenta }}<br>
                            @endif
                            @if ($dato->tarjeta)
                                Tarjeta: {{ $dato->tarjeta }}<br>
                            @endif
                            @if ($dato->clabe)
                                CLABE: {{ $dato->clabe }}
                            @endif
                        </td>
                        <td>
                            <div class="acciones">
                                @unless ($loop->first)
                                    <form method="POST" action="{{ route('configuracion.datos-bancarios.mover', [$dato, 'arriba']) }}">
                                        @csrf
                                        @method('PATCH')
                                        <x-boton variante="suave" icono="arrow-up" title="Subir" descripcion="Subir {{ $dato->nombre_banco }}" />
                                    </form>
                                @endunless
                                @unless ($loop->last)
                                    <form method="POST" action="{{ route('configuracion.datos-bancarios.mover', [$dato, 'abajo']) }}">
                                        @csrf
                                        @method('PATCH')
                                        <x-boton variante="suave" icono="arrow-down" title="Bajar" descripcion="Bajar {{ $dato->nombre_banco }}" />
                                    </form>
                                @endunless

                                <form method="POST" action="{{ route('configuracion.datos-bancarios.visible', $dato) }}">
                                    @csrf
                                    @method('PATCH')
                                    @if ($dato->visible_en_cotizaciones)
                                        <x-boton variante="suave" icono="eye-slash" title="Ocultar en cotizaciones" descripcion="Ocultar {{ $dato->nombre_banco }} en cotizaciones" />
                                    @else
                                        <x-boton variante="suave" icono="eye" title="Mostrar en cotizaciones" descripcion="Mostrar {{ $dato->nombre_banco }} en cotizaciones" />
                                    @endif
                                </form>

                                <x-boton :href="route('configuracion.datos-bancarios.edit', $dato)" variante="suave" icono="pencil" title="Editar" descripcion="Editar {{ $dato->nombre_banco }}" />

                                <form method="POST" action="{{ route('configuracion.datos-bancarios.destroy', $dato) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $dato->nombre_banco }}"
                                        data-confirmar="¿Eliminar {{ $dato->nombre_banco }}? Las cotizaciones ya creadas lo conservan." />
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-card>
