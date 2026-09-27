{{-- Ficha visual de un artículo. ficha-articulo.js la llena con los data-* del enlace del nombre y la abre. --}}
<dialog id="ficha-articulo" class="ficha" aria-labelledby="ficha-nombre">
    <div class="ficha-cuerpo">
        {{-- El marcador ocupa el mismo espacio que la foto, para que la ficha no cambie de tamaño. --}}
        <div class="ficha-foto">
            <img alt="" data-ficha-imagen hidden>
            <p class="ficha-sin-imagen" data-ficha-sin-imagen><x-icono nombre="image" />Sin imagen</p>
        </div>

        <div class="ficha-datos">
            <h2 id="ficha-nombre" data-ficha-nombre></h2>
            <p>Modelo <strong data-ficha-modelo></strong></p>
            <p class="ficha-precio" data-ficha-precio></p>
            <p class="ayuda">Precio con IVA</p>
        </div>
    </div>

    {{-- Respaldo cuando el navegador no permite compartir ni copiar (sitio sin HTTPS). --}}
    <div hidden data-ficha-copiar>
        <x-campo nombre="ficha_texto" etiqueta="Copia el texto" readonly />
    </div>
    <p class="ficha-aviso" role="status" data-ficha-aviso></p>

    <form method="dialog" class="acciones ficha-pie">
        <x-boton href="#" variante="secundario" icono="pencil" data-ficha-editar>Editar</x-boton>
        <x-boton variante="secundario" icono="x-lg">Cerrar</x-boton>
        <x-boton tipo="button" icono="share" data-ficha-compartir>Compartir</x-boton>
    </form>
</dialog>
