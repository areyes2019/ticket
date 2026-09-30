// Configuración global de Axios para comunicarse con Laravel.
(function () {
    const token = document.querySelector('meta[name="csrf-token"]');

    axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    axios.defaults.headers.common['Accept'] = 'application/json';

    if (token) {
        axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
    }
})();

// Pide confirmación antes de enviar un formulario cuyo botón tenga data-confirmar="…".
document.addEventListener('submit', function (evento) {
    const boton = evento.submitter || evento.target.querySelector('[data-confirmar]');

    if (boton && boton.dataset.confirmar && !window.confirm(boton.dataset.confirmar)) {
        evento.preventDefault();
    }
});

// Un botón con data-enviar-una-vez se deshabilita al enviar su formulario, para
// que un doble clic no repita la acción (p. ej. timbrar). Se registra después
// de la confirmación: si esta se rechazó, el botón queda como estaba.
document.addEventListener('submit', function (evento) {
    const boton = evento.submitter;

    if (!evento.defaultPrevented && boton && boton.hasAttribute('data-enviar-una-vez')) {
        setTimeout(function () {
            boton.disabled = true;
        }, 0);
    }
});

// Al volver con "Atrás" el navegador restaura la página tal cual: se reactivan.
window.addEventListener('pageshow', function (evento) {
    if (evento.persisted) {
        document.querySelectorAll('[data-enviar-una-vez]').forEach(function (boton) {
            boton.disabled = false;
        });
    }
});

// Diálogos: <a href="#id" data-abrir-dialogo> abre el <dialog id="id"> como
// modal y [data-cerrar-dialogo] lo cierra. Sin JavaScript el enlace funciona
// igual, porque el CSS muestra el diálogo señalado por la URL (:target).
// Un <dialog data-abrir-al-cargar> se abre solo (p. ej. con errores de validación).
document.addEventListener('click', function (evento) {
    const abrir = evento.target.closest('[data-abrir-dialogo]');
    const cerrar = evento.target.closest('[data-cerrar-dialogo]');

    if (abrir) {
        const dialogo = document.getElementById((abrir.getAttribute('href') || '').slice(1));

        if (dialogo && typeof dialogo.showModal === 'function') {
            evento.preventDefault();
            dialogo.showModal();
        }
    } else if (cerrar) {
        const dialogo = cerrar.closest('dialog');

        if (dialogo && dialogo.open) {
            evento.preventDefault();
            dialogo.close();
        }
    }
});

document.querySelectorAll('dialog[data-abrir-al-cargar]').forEach(function (dialogo) {
    if (typeof dialogo.showModal === 'function') {
        dialogo.showModal();
    }
});

// Copia un texto al portapapeles. Resuelve false si el navegador no lo permite
// (sitio sin HTTPS fuera de localhost): quien llama muestra el texto para copiarlo a mano.
window.copiarTexto = function (texto) {
    if (!window.isSecureContext || !navigator.clipboard) {
        return Promise.resolve(false);
    }

    return navigator.clipboard.writeText(texto).then(function () {
        return true;
    }, function () {
        return false;
    });
};
