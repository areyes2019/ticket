// Copia el ticket de la venta como imagen al portapapeles, para pegarlo en un
// mensaje de WhatsApp. El HTML del ticket se dibuja con html2canvas.
//
// Botón [data-copiar-ticket="<selector del ticket>"]; data-archivo es el nombre
// del PNG que se descarga si el navegador no deja escribir imágenes en el
// portapapeles. El resultado se anuncia en [data-copiar-ticket-estado].
(function () {
    if (typeof document === 'undefined') {
        return;
    }

    function dibujar(elemento) {
        return window.html2canvas(elemento, {
            backgroundColor: '#ffffff',
            scale: Math.max(2, window.devicePixelRatio || 1),
            useCORS: true,
        }).then(function (lienzo) {
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

        const ticket = document.querySelector(boton.dataset.copiarTicket);
        const estado = boton.parentElement.querySelector('[data-copiar-ticket-estado]');
        const avisar = function (texto) { if (estado) { estado.textContent = texto; } };

        if (!ticket || typeof window.html2canvas !== 'function') {
            avisar('No se pudo generar la imagen del ticket.');
            return;
        }

        boton.disabled = true;
        avisar('Generando imagen…');

        const imagen = dibujar(ticket);
        let copiado;

        if (puedeCopiarImagen()) {
            // Safari exige crear el ClipboardItem dentro del clic, con la
            // imagen como promesa.
            copiado = navigator.clipboard.write([new ClipboardItem({ 'image/png': imagen })])
                .then(function () { avisar('Ticket copiado. Pégalo en el chat de WhatsApp.'); })
                .catch(function () {
                    return imagen.then(function (blob) {
                        descargar(blob, boton.dataset.archivo);
                        avisar('Tu navegador no deja copiar imágenes; se descargó el ticket.');
                    });
                });
        } else {
            copiado = imagen.then(function (blob) {
                descargar(blob, boton.dataset.archivo);
                avisar('Tu navegador no deja copiar imágenes; se descargó el ticket.');
            });
        }

        copiado
            .catch(function () { avisar('No se pudo generar la imagen del ticket.'); })
            .finally(function () { boton.disabled = false; });
    });
})();
