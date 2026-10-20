{{-- Ficha visual de un artículo. ficha-articulo.js la llena con los data-* del enlace del nombre y la abre. --}}
<dialog id="ficha-articulo" class="ficha" aria-labelledby="ficha-nombre">
    <div class="ficha-cuerpo">
        {{-- Los botones van bajo la foto. --}}
        <div class="ficha-columna">
            {{-- Lo que copia el botón "Copiar" como imagen (html2canvas): foto, precio al público y nombre, sobre blanco. --}}
            <div class="ficha-captura" data-ficha-captura>
                {{-- El marcador ocupa el mismo espacio que la foto, para que la ficha no cambie de tamaño. --}}
                <div class="ficha-foto">
                    <img alt="" data-ficha-imagen hidden>
                    <p class="ficha-sin-imagen" data-ficha-sin-imagen><x-icono nombre="image" />Sin imagen</p>
                </div>
                <p class="ficha-captura-precio" data-ficha-captura-precio></p>
                <p class="ficha-captura-nombre" data-ficha-captura-nombre></p>
            </div>

            <form method="dialog" class="acciones ficha-pie">
                <x-boton href="#" variante="secundario" icono="pencil" data-ficha-editar>Editar</x-boton>
                {{-- Envía el formulario de abajo (no se anidan formularios); ficha-articulo.js le pone la dirección. --}}
                <x-boton variante="secundario" icono="copy" form="ficha-clonar" data-enviar-una-vez>Clonar</x-boton>
                <x-boton variante="secundario" icono="x-lg">Cerrar</x-boton>
                {{-- Copia el recuadro de arriba como imagen; el distribuidor se comparte como texto. --}}
                <x-boton tipo="button" icono="clipboard" data-ficha-copiar-imagen>Copiar</x-boton>
                <x-boton tipo="button" variante="secundario" icono="share" data-ficha-compartir="distribuidor">Compartir precio distribuidor</x-boton>
            </form>

            <form id="ficha-clonar" method="POST" action="#" hidden data-ficha-clonar>
                @csrf
            </form>

            {{-- Respaldo cuando el navegador no permite compartir ni copiar (sitio sin HTTPS). --}}
            <div hidden data-ficha-copiar>
                <x-campo nombre="ficha_texto" etiqueta="Copia el texto" readonly />
            </div>
            <p class="ficha-aviso" role="status" data-ficha-aviso></p>
        </div>

        <div class="ficha-datos">
            <h2 id="ficha-nombre" data-ficha-nombre></h2>
            <p>Modelo <strong data-ficha-modelo></strong></p>
            <p class="ayuda" data-ficha-tipo></p>
            <p class="ficha-precio" data-ficha-precio></p>
            <p class="ayuda" data-ficha-etiqueta-precio>Precio con IVA</p>
            <p class="ficha-precio" data-ficha-precio-distribuidor></p>
            <p class="ayuda" data-ficha-etiqueta-distribuidor>Precio distribuidor con IVA</p>
        </div>
    </div>
</dialog>
