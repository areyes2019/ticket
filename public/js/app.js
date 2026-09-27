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
