// Búsqueda dinámica de un listado, sin recargar la página.
//
// Se activa en un formulario con data-busqueda-dinamica="<url del fragmento>".
// Los campos del formulario (incluidos los que están fuera de él con form="…")
// disparan la búsqueda al escribir. La respuesta es HTML generado por Blade:
// cada elemento con id de la respuesta reemplaza al elemento con el mismo id
// en la página (por ejemplo, las filas de la tabla y la paginación).
// Los enlaces con data-busqueda-enlace se cargan por AJAX igual que la paginación.
(function () {
    const ESPERA_MS = 300;

    document.querySelectorAll('form[data-busqueda-dinamica]').forEach(function (formulario) {
        const urlFragmento = formulario.dataset.busquedaDinamica;
        const tabla = document.querySelector('[data-busqueda-tabla]');
        const alertaError = document.querySelector('[data-busqueda-error]');
        let temporizador = null;
        let peticionActual = null;

        function parametrosDelFormulario() {
            const parametros = new URLSearchParams();

            Array.from(formulario.elements).forEach(function (campo) {
                if (campo.name && campo.value.trim() !== '') {
                    parametros.append(campo.name, campo.value.trim());
                }
            });

            return parametros;
        }

        function buscar(parametros) {
            if (peticionActual) {
                peticionActual.abort();
            }

            peticionActual = new AbortController();

            if (tabla) {
                tabla.setAttribute('aria-busy', 'true');
            }

            axios.get(urlFragmento, { params: parametros, signal: peticionActual.signal })
                .then(function (respuesta) {
                    // Si Laravel redirigió (p. ej. al login por usuario suspendido), la
                    // respuesta no es el fragmento: se recarga para seguir la redirección.
                    const urlRespuesta = respuesta.request && respuesta.request.responseURL;

                    if (urlRespuesta && new URL(urlRespuesta).pathname !== new URL(urlFragmento, window.location.href).pathname) {
                        window.location.reload();
                        return;
                    }

                    reemplazarPorId(respuesta.data);

                    const consulta = parametros.toString();
                    history.replaceState(null, '', formulario.action + (consulta ? '?' + consulta : ''));

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
                    if (tabla) {
                        tabla.removeAttribute('aria-busy');
                    }
                });
        }

        function reemplazarPorId(html) {
            const plantilla = document.createElement('template');
            plantilla.innerHTML = html;

            plantilla.content.querySelectorAll('[id]').forEach(function (nuevo) {
                const actual = document.getElementById(nuevo.id);

                if (actual) {
                    actual.replaceWith(nuevo);
                }
            });
        }

        // Escribir en cualquier campo del formulario (esté dentro o fuera de él).
        document.addEventListener('input', function (evento) {
            if (evento.target.form !== formulario) {
                return;
            }

            clearTimeout(temporizador);
            temporizador = setTimeout(function () {
                buscar(parametrosDelFormulario());
            }, ESPERA_MS);
        });

        // Enter o botón "Buscar": buscar de inmediato sin recargar.
        formulario.addEventListener('submit', function (evento) {
            evento.preventDefault();
            clearTimeout(temporizador);
            buscar(parametrosDelFormulario());
        });

        // Enlaces de paginación y enlaces marcados con data-busqueda-enlace (por
        // ejemplo, los títulos que ordenan): cargar el resultado pedido por AJAX.
        document.addEventListener('click', function (evento) {
            const enlace = evento.target.closest('.paginacion a, a[data-busqueda-enlace]');

            if (!enlace || evento.ctrlKey || evento.metaKey || evento.shiftKey) {
                return;
            }

            evento.preventDefault();
            clearTimeout(temporizador);
            buscar(new URL(enlace.href).searchParams);
        });
    });
})();
