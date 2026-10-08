// Aplicación instalable (033): registra el service worker, ofrece el botón
// "Instalar aplicación" y muestra el aviso sin conexión del layout.
//
// Todo es opcional: un navegador sin service worker o sin aviso de instalación
// sigue usando el sistema igual, solo sin esas comodidades.
(function () {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {
                // Sin service worker no hay aviso sin conexión; nada más cambia.
            });
        });
    }

    // -- Instalar --

    const botones = document.querySelectorAll('[data-instalar-app]');
    const instalada = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
    let aviso = null;

    function mostrarBotones(visibles) {
        botones.forEach(function (boton) {
            boton.hidden = !visibles;
        });
    }

    window.addEventListener('beforeinstallprompt', function (evento) {
        evento.preventDefault();

        if (instalada) {
            return;
        }

        aviso = evento;
        mostrarBotones(true);
    });

    window.addEventListener('appinstalled', function () {
        aviso = null;
        mostrarBotones(false);
    });

    botones.forEach(function (boton) {
        boton.addEventListener('click', function () {
            if (!aviso) {
                return;
            }

            aviso.prompt();
            aviso.userChoice.finally(function () {
                aviso = null;
                mostrarBotones(false);
            });
        });
    });

    // -- Sin conexión --

    const avisoSinConexion = document.querySelector('[data-sin-conexion]');

    function actualizarConexion() {
        if (avisoSinConexion) {
            avisoSinConexion.hidden = navigator.onLine !== false;
        }
    }

    window.addEventListener('online', actualizarConexion);
    window.addEventListener('offline', actualizarConexion);
    actualizarConexion();
})();
