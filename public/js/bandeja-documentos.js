// Bandeja de documentos: cotizaciones (spec 014) y órdenes de compra (017).
//
// Filtrar, buscar y paginar lo hace busqueda-dinamica.js. Este script muestra
// en el visor el documento elegido sin recargar: pide su vista previa en HTML
// al servidor (data-vista-previa de la fila) y deja su id en la URL
// (?cotizacion=15, ?orden=8) para que recargar o volver de una acción lo
// mantenga abierto. Además maneja "Carpetas" y "Volver" en pantallas chicas.
//
// Se activa en [data-bandeja-documentos]. Atributos:
//   data-parametro      nombre del parámetro de la URL; las filas llevan
//                       data-<parametro>="<id>" (data-cotizacion, data-orden).
//   data-sin-seleccion  texto del visor vacío.
// El visor es [data-visor-documento].
(function () {
    const bandeja = document.querySelector('[data-bandeja-documentos]');

    if (!bandeja) {
        return;
    }

    const parametro = bandeja.dataset.parametro;
    const selectorFila = '[data-' + parametro + ']';
    const visor = bandeja.querySelector('[data-visor-documento]');
    const botonCarpetas = bandeja.querySelector('[data-mostrar-carpetas]');
    const alertaError = document.querySelector('[data-vista-previa-error]');
    let abierta = idAbierta();
    let peticionActual = null;

    function idAbierta() {
        const documento = visor.querySelector('[data-vista-previa-de]');

        return documento ? documento.dataset.vistaPreviaDe : null;
    }

    function filas() {
        return Array.from(bandeja.querySelectorAll(selectorFila));
    }

    function marcarActiva() {
        filas().forEach(function (fila) {
            const activa = fila.dataset[parametro] === abierta;
            const enlace = fila.querySelector('[data-vista-previa]');

            fila.classList.toggle('bandeja-fila-activa', activa);

            if (activa) {
                enlace.setAttribute('aria-current', 'true');
            } else {
                enlace.removeAttribute('aria-current');
            }
        });
    }

    function recordarEnUrl() {
        const url = new URL(window.location.href);

        if (abierta) {
            url.searchParams.set(parametro, abierta);
        } else {
            url.searchParams.delete(parametro);
        }

        history.replaceState(null, '', url);
    }

    function sinSeleccion() {
        abierta = null;
        visor.innerHTML = '';

        const aviso = document.createElement('p');
        aviso.className = 'bandeja-sin-seleccion';
        aviso.textContent = bandeja.dataset.sinSeleccion;
        visor.appendChild(aviso);

        marcarActiva();
        recordarEnUrl();
    }

    // Pide la vista previa y la pone en el visor. Si llega una redirección
    // (sesión vencida, usuario suspendido) se recarga para seguirla.
    function abrir(fila, leyendo) {
        const url = fila.querySelector('[data-vista-previa]').dataset.vistaPrevia;

        if (peticionActual) {
            peticionActual.abort();
        }

        peticionActual = new AbortController();
        abierta = fila.dataset[parametro];
        marcarActiva();
        visor.setAttribute('aria-busy', 'true');

        axios.get(url, { signal: peticionActual.signal })
            .then(function (respuesta) {
                const urlRespuesta = respuesta.request && respuesta.request.responseURL;

                if (urlRespuesta && new URL(urlRespuesta).pathname !== new URL(url, window.location.href).pathname) {
                    window.location.reload();
                    return;
                }

                visor.innerHTML = respuesta.data;
                visor.scrollTop = 0;
                bandeja.classList.toggle('bandeja-leyendo', leyendo);
                recordarEnUrl();

                if (alertaError) {
                    alertaError.hidden = true;
                }
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

                if (alertaError) {
                    alertaError.hidden = false;
                }
            })
            .finally(function () {
                visor.removeAttribute('aria-busy');
            });
    }

    function mostrarCarpetas(abiertas) {
        bandeja.classList.toggle('bandeja-carpetas-abiertas', abiertas);
        botonCarpetas.setAttribute('aria-expanded', abiertas ? 'true' : 'false');
    }

    bandeja.addEventListener('click', function (evento) {
        const enlace = evento.target.closest('[data-vista-previa]');

        // Ctrl/Cmd/Mayús + clic abre el detalle en otra pestaña, como un enlace normal.
        if (enlace && !evento.ctrlKey && !evento.metaKey && !evento.shiftKey) {
            evento.preventDefault();
            abrir(enlace.closest(selectorFila), true);
        } else if (evento.target.closest('[data-volver]')) {
            bandeja.classList.remove('bandeja-leyendo');
        } else if (evento.target.closest('[data-mostrar-carpetas]')) {
            mostrarCarpetas(!bandeja.classList.contains('bandeja-carpetas-abiertas'));
        } else if (evento.target.closest('.bandeja-carpetas a[data-busqueda-enlace]')) {
            mostrarCarpetas(false);
        }
    });

    // Tras filtrar, buscar o cambiar de página: si la abierta sigue en la lista
    // se resalta; si no, se abre la primera, o el visor queda vacío.
    document.addEventListener('busqueda:actualizada', function () {
        const lista = filas();

        if (lista.some(function (fila) { return fila.dataset[parametro] === abierta; })) {
            marcarActiva();
            recordarEnUrl();
        } else if (lista.length > 0) {
            abrir(lista[0], false);
        } else {
            sinSeleccion();
        }
    });
})();
