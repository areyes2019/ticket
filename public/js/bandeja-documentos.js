// Bandeja de documentos: cotizaciones (spec 014), órdenes de compra (017) y el
// dashboard, con cotizaciones y facturas en dos listas y un solo visor.
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
//                       Varios, separados por espacio, si hay varias listas
//                       ("cotizacion factura"); la vista previa dice de cuál
//                       es con data-documento.
//   data-sin-seleccion  texto del visor vacío.
// El visor es [data-visor-documento].
(function () {
    const bandeja = document.querySelector('[data-bandeja-documentos]');

    if (!bandeja) {
        return;
    }

    const parametros = bandeja.dataset.parametro.split(' ');
    const selectorFila = parametros.map(function (parametro) { return '[data-' + parametro + ']'; }).join(', ');
    const visor = bandeja.querySelector('[data-visor-documento]');
    const botonCarpetas = bandeja.querySelector('[data-mostrar-carpetas]');
    const alertaError = document.querySelector('[data-vista-previa-error]');
    // La abierta es "<parametro>:<id>", para no confundir la cotización 3 con la factura 3.
    let abierta = idAbierta();
    let peticionActual = null;

    function idAbierta() {
        const documento = visor.querySelector('[data-vista-previa-de]');

        return documento ? (documento.dataset.documento || parametros[0]) + ':' + documento.dataset.vistaPreviaDe : null;
    }

    function clave(fila) {
        const parametro = parametros.find(function (nombre) { return fila.dataset[nombre] !== undefined; });

        return parametro + ':' + fila.dataset[parametro];
    }

    function filas() {
        return Array.from(bandeja.querySelectorAll(selectorFila));
    }

    function marcarActiva() {
        filas().forEach(function (fila) {
            const activa = clave(fila) === abierta;
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

        parametros.forEach(function (parametro) {
            url.searchParams.delete(parametro);
        });

        if (abierta) {
            const [parametro, id] = abierta.split(':');

            url.searchParams.set(parametro, id);
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
        abierta = clave(fila);
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

        if (lista.some(function (fila) { return clave(fila) === abierta; })) {
            marcarActiva();
            recordarEnUrl();
        } else if (lista.length > 0) {
            abrir(lista[0], false);
        } else {
            sinSeleccion();
        }
    });

    // Otro script cambió el documento abierto (p. ej. timbrar-cotizacion.js al
    // facturarlo): se vuelve a pedir su vista previa.
    document.addEventListener('documento:recargar', function () {
        const fila = filas().find(function (candidata) { return clave(candidata) === abierta; });

        if (fila) {
            abrir(fila, bandeja.classList.contains('bandeja-leyendo'));
        }
    });

    function normalizar(texto) {
        return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    // Listas [data-filtro-local] (dashboard): su buscador filtra las filas ya
    // cargadas, sin ir al servidor, sin distinguir mayúsculas ni acentos.
    bandeja.querySelectorAll('[data-filtro-local]').forEach(function (lista) {
        const buscador = lista.querySelector('[data-buscar]');
        const vacia = lista.querySelector('[data-vacia]');

        buscador.addEventListener('input', function () {
            const texto = normalizar(buscador.value.trim());
            let visibles = 0;

            lista.querySelectorAll(selectorFila).forEach(function (fila) {
                fila.hidden = texto !== '' && !normalizar(fila.textContent).includes(texto);
                visibles += fila.hidden ? 0 : 1;
            });

            vacia.hidden = visibles > 0;
        });
    });
})();
