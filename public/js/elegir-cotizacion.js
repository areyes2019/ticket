// Ventana "Desde cotización" del listado de facturas (spec 015).
//
// El botón es un enlace a la página facturas.cotizaciones (respaldo sin
// JavaScript). Aquí abre la ventana, pide la lista de cotizaciones por
// facturar (?fragmento=1) y la vuelve a pedir mientras se escribe en el
// buscador. Elegir una es un enlace normal al formulario de factura lleno.
(function () {
    const dialogo = document.querySelector('[data-elegir-cotizacion]');
    const boton = document.querySelector('[data-abrir-elegir-cotizacion]');

    if (!dialogo || !boton || typeof dialogo.showModal !== 'function') {
        return;
    }

    const url = dialogo.dataset.elegirCotizacion;
    const buscador = dialogo.querySelector('[data-elegir-cotizacion-buscador]');
    const campo = buscador.querySelector('input[name="q"]');
    const lista = dialogo.querySelector('[data-elegir-cotizacion-lista]');
    const alertaError = dialogo.querySelector('[data-elegir-cotizacion-error]');
    let peticionActual = null;
    let espera = null;

    // Si llega una redirección (sesión vencida, usuario suspendido) se
    // recarga para seguirla.
    function cargar() {
        if (peticionActual) {
            peticionActual.abort();
        }

        peticionActual = new AbortController();
        lista.setAttribute('aria-busy', 'true');

        axios.get(url, { params: { fragmento: 1, q: campo.value.trim() }, signal: peticionActual.signal })
            .then(function (respuesta) {
                const urlRespuesta = respuesta.request && respuesta.request.responseURL;

                if (urlRespuesta && new URL(urlRespuesta).pathname !== new URL(url, window.location.href).pathname) {
                    window.location.reload();
                    return;
                }

                lista.innerHTML = respuesta.data;
                alertaError.hidden = true;
            })
            .catch(function (error) {
                if (axios.isCancel(error)) {
                    return;
                }

                const estado = error.response && error.response.status;

                if (estado === 401 || estado === 419) {
                    window.location.reload();
                    return;
                }

                alertaError.hidden = false;
            })
            .finally(function () {
                lista.removeAttribute('aria-busy');
            });
    }

    boton.addEventListener('click', function (evento) {
        if (evento.ctrlKey || evento.metaKey || evento.shiftKey) {
            return;
        }

        evento.preventDefault();
        campo.value = '';
        dialogo.showModal();
        campo.focus();
        cargar();
    });

    campo.addEventListener('input', function () {
        clearTimeout(espera);
        espera = setTimeout(cargar, 300);
    });

    // Enter en el buscador busca ya, sin salir de la ventana.
    buscador.addEventListener('submit', function (evento) {
        evento.preventDefault();
        clearTimeout(espera);
        cargar();
    });
})();
