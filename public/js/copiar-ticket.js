// Copia el ticket de la venta (la imagen JPG que dibuja el servidor) al
// portapapeles, para pegarlo en un mensaje de WhatsApp. Los portapapeles solo
// aceptan PNG, así que la imagen se redibuja en un canvas.
//
// Botón [data-copiar-ticket="<selector del <img>>"]; data-archivo es el nombre
// del PNG que se descarga si el navegador no deja escribir imágenes en el
// portapapeles. El resultado se anuncia en [data-copiar-ticket-estado].
(function () {
    if (typeof document === 'undefined') {
        return;
    }

    function cargada(imagen) {
        if (imagen.complete && imagen.naturalWidth > 0) {
            return Promise.resolve(imagen);
        }

        imagen.loading = 'eager';

        return new Promise(function (resolver, rechazar) {
            imagen.addEventListener('load', function () { resolver(imagen); }, { once: true });
            imagen.addEventListener('error', rechazar, { once: true });
        });
    }

    function enPng(imagen) {
        return cargada(imagen).then(function () {
            const lienzo = document.createElement('canvas');
            lienzo.width = imagen.naturalWidth;
            lienzo.height = imagen.naturalHeight;
            lienzo.getContext('2d').drawImage(imagen, 0, 0);

            return new Promise(function (resolver, rechazar) {
                lienzo.toBlob(function (blob) {
                    blob ? resolver(blob) : rechazar(new Error('Sin imagen'));
                }, 'image/png');
            });
        });
    }

    function puedeCopiarImagen() {
        return !!(navigator.clipboard && typeof navigator.clipboard.write === 'function' && typeof window.ClipboardItem === 'function');
    }

    function descargar(blob, nombre) {
        const url = URL.createObjectURL(blob);
        const enlace = document.createElement('a');
        enlace.href = url;
        enlace.download = nombre || 'ticket.png';
        document.body.appendChild(enlace);
        enlace.click();
        enlace.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    document.addEventListener('click', function (evento) {
        const boton = evento.target.closest('[data-copiar-ticket]');

        if (!boton || boton.disabled) {
            return;
        }

        const imagen = document.querySelector(boton.dataset.copiarTicket);
        const estado = boton.parentElement.querySelector('[data-copiar-ticket-estado]');
        const avisar = function (texto) { if (estado) { estado.textContent = texto; } };
        const alDescargar = function (blob) {
            descargar(blob, boton.dataset.archivo);
            avisar('Tu navegador no deja copiar imágenes; se descargó el ticket.');
        };

        if (!imagen) {
            avisar('No se encontró el ticket.');
            return;
        }

        boton.disabled = true;
        avisar('Copiando…');

        const png = enPng(imagen);
        let copiado;

        if (puedeCopiarImagen()) {
            // Safari exige crear el ClipboardItem dentro del clic, con la
            // imagen como promesa.
            copiado = navigator.clipboard.write([new ClipboardItem({ 'image/png': png })])
                .then(function () { avisar('Ticket copiado. Pégalo en el chat de WhatsApp.'); })
                .catch(function () { return png.then(alDescargar); });
        } else {
            copiado = png.then(alDescargar);
        }

        copiado
            .catch(function () { avisar('No se pudo copiar el ticket.'); })
            .finally(function () { boton.disabled = false; });
    });
})();
