@include('documentos._mensajes')

{{-- Banco y logo viajan juntos en una sola petición. Los números son texto
     con teclado numérico: un type="number" perdería el cero inicial. --}}
<form method="POST" action="{{ $accion }}" enctype="multipart/form-data">
    @csrf
    @isset($dato)
        @method('PUT')
    @endisset

    <x-campo nombre="nombre_banco" etiqueta="Banco" :valor="$dato->nombre_banco ?? null" maxlength="100" required autofocus
        ayuda="Por ejemplo: BBVA, Santander, Banorte." />
    <x-campo nombre="beneficiario" etiqueta="Beneficiario" :valor="$dato->beneficiario ?? null" maxlength="150"
        ayuda="A nombre de quién está la cuenta. Opcional." />
    <x-campo nombre="numero_cuenta" etiqueta="Número de cuenta" :valor="$dato->numero_cuenta ?? null" inputmode="numeric" autocomplete="off" />
    <x-campo nombre="tarjeta" etiqueta="Tarjeta" :valor="$dato->tarjeta ?? null" inputmode="numeric" autocomplete="off" />
    <x-campo nombre="clabe" etiqueta="CLABE" :valor="$dato->clabe ?? null" inputmode="numeric" autocomplete="off"
        ayuda="Captura al menos uno de los tres números. Puedes pegarlos con espacios o guiones." />
    <x-campo nombre="visible_en_cotizaciones" etiqueta="Mostrar en cotizaciones" tipo="checkbox" :valor="$dato->visible_en_cotizaciones ?? true" />

    <div class="logo-banco">
        @if (isset($dato) && $dato->tiene_logo)
            <img class="icono-banco" src="{{ route('configuracion.datos-bancarios.logo', [$dato, 'v' => $dato->logo_version]) }}" alt="Logo de {{ $dato->nombre_banco }}">
            <x-campo nombre="quitar_logo" etiqueta="Quitar logo" tipo="checkbox" />
        @endif
        <x-campo nombre="logo" etiqueta="{{ isset($dato) && $dato->tiene_logo ? 'Reemplazar logo' : 'Logo del banco' }}" tipo="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            ayuda="JPG, PNG o WEBP de hasta 2 MB. Se guarda reducido a un icono pequeño." />
    </div>

    <div class="acciones">
        <x-boton icono="save">Guardar</x-boton>
        <x-boton :href="route('configuracion.edit').'#datos-bancarios'" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>
