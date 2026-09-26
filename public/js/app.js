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
