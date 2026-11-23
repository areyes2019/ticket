// Pestañas de Configuración (042). El servidor manda todas las secciones
// visibles; este script las convierte en pestañas (roles WAI-ARIA, flechas,
// Inicio/Fin), elige la inicial (ancla > última usada > primera) y recuerda
// la última en localStorage. Si no carga, la página se ve completa.
(function () {
    const CLAVE = 'configuracion.pestana';
    const contenedor = document.querySelector('[data-pestanas]');
    const barra = contenedor && contenedor.querySelector('[data-pestanas-barra]');

    if (!barra) {
        return;
    }

    const pestanas = Array.from(barra.querySelectorAll('[data-pestana]'));
    const paneles = {};

    pestanas.forEach(function (pestana) {
        const ancla = pestana.dataset.pestana;
        // Solo entre las secciones del contenedor: un campo de un formulario
        // puede tener el mismo id (el WhatsApp del emisor).
        const panel = Array.from(contenedor.children).find(function (hijo) {
            return hijo.classList.contains('config-panel') && hijo.id === ancla;
        });

        paneles[ancla] = panel;
        pestana.setAttribute('role', 'tab');
        pestana.setAttribute('aria-controls', ancla);
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', pestana.id);
    });
    barra.setAttribute('role', 'tablist');

    function existe(ancla) {
        return Object.prototype.hasOwnProperty.call(paneles, ancla);
    }

    function leerGuardada() {
        try {
            return localStorage.getItem(CLAVE);
        } catch (e) {
            return null;
        }
    }

    function guardar(ancla) {
        try {
            localStorage.setItem(CLAVE, ancla);
        } catch (e) {
            // Sin almacenamiento simplemente no se recuerda (regla 23).
        }
    }

    function abrir(ancla, opciones) {
        pestanas.forEach(function (pestana) {
            const activa = pestana.dataset.pestana === ancla;

            pestana.setAttribute('aria-selected', activa ? 'true' : 'false');
            pestana.setAttribute('tabindex', activa ? '0' : '-1');
            paneles[pestana.dataset.pestana].classList.toggle('activa', activa);
        });

        if (location.hash !== '#' + ancla) {
            history.replaceState(null, '', '#' + ancla);
        }
        guardar(ancla);

        if (opciones && opciones.foco) {
            barra.querySelector('[data-pestana="' + ancla + '"]').focus();
        }
    }

    function anclaActual() {
        return decodeURIComponent(location.hash.replace(/^#/, ''));
    }

    const inicial = [anclaActual(), leerGuardada()].find(existe) || pestanas[0].dataset.pestana;

    contenedor.classList.add('pestanas-activas');
    abrir(inicial);

    // Al llegar con ancla el navegador ya bajó hasta la sección; con las
    // pestañas no hace falta (regla de la sección Frontend).
    if (anclaActual()) {
        window.scrollTo(0, 0);
    }

    barra.addEventListener('click', function (evento) {
        const pestana = evento.target.closest('[data-pestana]');

        if (pestana) {
            evento.preventDefault();
            abrir(pestana.dataset.pestana);
        }
    });

    barra.addEventListener('keydown', function (evento) {
        const actual = pestanas.indexOf(document.activeElement);

        if (actual === -1) {
            return;
        }

        let siguiente = null;

        if (evento.key === 'ArrowRight') {
            siguiente = (actual + 1) % pestanas.length;
        } else if (evento.key === 'ArrowLeft') {
            siguiente = (actual - 1 + pestanas.length) % pestanas.length;
        } else if (evento.key === 'Home') {
            siguiente = 0;
        } else if (evento.key === 'End') {
            siguiente = pestanas.length - 1;
        }

        if (siguiente !== null) {
            evento.preventDefault();
            abrir(pestanas[siguiente].dataset.pestana, { foco: true });
        }
    });

    // Un enlace con ancla dentro de la misma página también cambia de pestaña.
    window.addEventListener('hashchange', function () {
        if (existe(anclaActual())) {
            abrir(anclaActual());
            window.scrollTo(0, 0);
        }
    });
})();
