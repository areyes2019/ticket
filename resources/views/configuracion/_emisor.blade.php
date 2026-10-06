{{-- Datos del emisor (026): uno para toda la instalación, solo lo edita el
     administrador. Formulario propio: guardarlo no reenvía los mensajes. --}}
<x-card titulo="Datos del emisor" id="emisor">
    @unless ($emisor->estaCompleto())
        <x-alerta tipo="advertencia">Tus cotizaciones y órdenes de compra se están imprimiendo sin datos fiscales del emisor.</x-alerta>
    @endunless

    <p class="ayuda">Aparecen en el PDF de cotizaciones y órdenes de compra. En las facturas, el nombre, RFC y régimen son los del timbrado; de aquí solo se toman domicilio, correo y teléfono.</p>

    <form method="POST" action="{{ route('configuracion.emisor') }}">
        @csrf
        @method('PUT')

        <x-campo nombre="nombre" etiqueta="Nombre o razón social" :valor="$emisor->nombre" maxlength="255" required />
        <x-campo nombre="rfc" etiqueta="RFC" :valor="$emisor->rfc" maxlength="13" required />
        <x-campo nombre="regimen_fiscal" etiqueta="Régimen fiscal" tipo="select" :opciones="App\Enums\RegimenFiscal::opciones()" :valor="$emisor->regimen_fiscal?->value" vacia="Selecciona un régimen fiscal" required />
        <x-campo nombre="domicilio" etiqueta="Domicilio" :valor="$emisor->domicilio" maxlength="255" ayuda="Una línea, como se imprime: 38024, Celaya, Guanajuato." />
        <x-campo nombre="correo" etiqueta="Correo" tipo="email" :valor="$emisor->correo" maxlength="255" />
        <x-campo nombre="telefono" etiqueta="Teléfono" tipo="tel" :valor="$emisor->telefono" />
        <x-campo nombre="sitio_web" etiqueta="Sitio web" :valor="$emisor->sitio_web" maxlength="255" ayuda="Solo en la cotización." />
        <x-campo nombre="whatsapp" etiqueta="WhatsApp" tipo="tel" :valor="$emisor->whatsapp" ayuda="Solo en la cotización." />

        <figure class="logo-documentos">
            <img src="{{ asset(App\Services\Documentos\LogoDocumento::RUTA) }}" alt="Logo de Sello Pronto">
            <figcaption class="ayuda">Logo que aparece en los documentos.</figcaption>
        </figure>

        <div class="acciones">
            <x-boton icono="save">Guardar emisor</x-boton>
        </div>
    </form>
</x-card>
