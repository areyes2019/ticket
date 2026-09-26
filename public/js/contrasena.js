// Botones para mostrar u ocultar el contenido de los campos de contraseña.
(function () {
    document.querySelectorAll('[data-mostrar-contrasena]').forEach(function (boton) {
        const campo = document.getElementById(boton.dataset.mostrarContrasena);
        const icono = boton.querySelector('.bi');

        if (!campo) {
            return;
        }

        boton.addEventListener('click', function () {
            const mostrar = campo.type === 'password';

            campo.type = mostrar ? 'text' : 'password';
            icono.classList.toggle('bi-eye', !mostrar);
            icono.classList.toggle('bi-eye-slash', mostrar);
            boton.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });
})();
