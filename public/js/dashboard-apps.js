// Aplicaciones del dashboard (spec 013).
//
// El menú de aplicaciones está en todas las páginas; aquí, en el dashboard, sus
// enlaces [data-abrir-app] cambian la aplicación sin recargar: "correo" abre la
// que tiene el mismo [data-app] (otro clic la cierra) y "" (Dashboard) vuelve al
// inicio (cotizaciones y facturas). La dirección se actualiza para que recargar deje lo mismo.
(function () {
    const enlaces = Array.from(document.querySelectorAll('[data-abrir-app]'));
    const apps = Array.from(document.querySelectorAll('[data-app]'));
    const inicio = document.querySelector('[data-escritorio-inicio]');

    if (!inicio) {
        return;
    }

    function abrir(nombre) {
        apps.forEach(function (app) {
            app.hidden = app.dataset.app !== nombre;
        });

        enlaces.forEach(function (enlace) {
            if (enlace.dataset.abrirApp === nombre) {
                enlace.setAttribute('aria-current', 'page');
            } else {
                enlace.removeAttribute('aria-current');
            }
        });

        inicio.hidden = nombre !== '';

        const url = new URL(window.location.href);

        if (nombre === '') {
            url.searchParams.delete('app');
        } else {
            url.searchParams.set('app', nombre);
        }

        history.replaceState(null, '', url);
    }

    enlaces.forEach(function (enlace) {
        enlace.addEventListener('click', function (evento) {
            const nombre = enlace.dataset.abrirApp;

            if (nombre !== '' && !apps.some(function (app) { return app.dataset.app === nombre; })) {
                return;
            }

            evento.preventDefault();

            const abierta = enlace.getAttribute('aria-current') === 'page';

            abrir(abierta ? '' : nombre);
        });
    });
})();
