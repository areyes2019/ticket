// Compartir una cotización por WhatsApp desde el aparato del usuario.
//
// El botón [data-compartir-cotizacion] trae la URL del PDF (data-pdf), la de
// marcar como enviada (data-marcar), el nombre del archivo, el teléfono del
// cliente y un texto resumen. Con Web Share el PDF va al menú de compartir;
// si el navegador no comparte archivos (escritorio), se descarga y se abre
// wa.me con el texto para adjuntarlo a mano. Al terminar, la cotización se
// marca como enviada. El servidor nunca manda mensajes de WhatsApp.
(function () {
    const boton = document.querySelector('[data-compartir-cotizacion]');

    if (!boton || typeof fetch !== 'function') {
        return;
    }

    const alerta = document.querySelector('[data-compartir-error]');
    const etiquetaEstado = document.querySelector('[data-estado-cotizacion]');

    boton.hidden = false;

    function mostrarError(texto) {
        if (alerta) {
            alerta.querySelector('.alerta-contenido').textContent = texto;
            alerta.hidden = false;
        }
    }

    function descargarPdf() {
        return fetch(boton.dataset.pdf, { credentials: 'same-origin' }).then(function (respuesta) {
            // Sin sesión Laravel redirige al login: la respuesta no es el PDF.
            if (respuesta.status === 401 || respuesta.redirected || !respuesta.ok) {
                throw new Error(respuesta.redirected ? 'sesion' : 'pdf');
            }

            return respuesta.blob();
        }).then(function (blob) {
            return new File([blob], boton.dataset.archivo, { type: 'application/pdf' });
        });
    }

    function marcarEnviada() {
        return axios.post(boton.dataset.marcar).then(function (respuesta) {
            if (etiquetaEstado && respuesta.data && respuesta.data.etiqueta) {
                etiquetaEstado.textContent = respuesta.data.etiqueta;
                etiquetaEstado.className = 'etiqueta etiqueta-' + respuesta.data.estado.replace(/_/g, '-');
            }
        });
    }

    function guardarArchivo(archivo) {
        const enlace = document.createElement('a');
        const url = URL.createObjectURL(archivo);

        enlace.href = url;
        enlace.download = archivo.name;
        document.body.appendChild(enlace);
        enlace.click();
        enlace.remove();
        setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 1000);
    }

    function abrirWhatsApp() {
        const telefono = boton.dataset.telefono || '';
        const texto = encodeURIComponent(boton.dataset.texto + '. Te adjunto el PDF.');

        window.open('https://wa.me/' + telefono + '?text=' + texto, '_blank', 'noopener');
    }

    boton.addEventListener('click', function () {
        boton.disabled = true;

        if (alerta) {
            alerta.hidden = true;
        }

        descargarPdf()
            .then(function (archivo) {
                if (navigator.canShare && navigator.canShare({ files: [archivo] })) {
                    return navigator.share({ files: [archivo], text: boton.dataset.texto });
                }

                guardarArchivo(archivo);
                abrirWhatsApp();
            })
            .then(marcarEnviada)
            .catch(function (error) {
                // Cerrar el menú de compartir no es un error ni cuenta como envío.
                if (error && error.name === 'AbortError') {
                    return;
                }

                const estado = error && error.response && error.response.status;

                if ((error && error.message === 'sesion') || estado === 401 || estado === 419) {
                    window.location.reload();
                    return;
                }

                mostrarError('No se pudo compartir la cotización. Intenta de nuevo.');
            })
            .finally(function () {
                boton.disabled = false;
            });
    });
})();
