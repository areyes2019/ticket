// Campo con sugerencias de un catálogo grande (por ejemplo, las claves del SAT).
//
// Se activa en cualquier campo con data-autocompletar="<url>". La URL responde
// JSON [{ clave, descripcion }]. Al elegir una sugerencia se escribe la clave en
// el campo y la descripción en su texto de ayuda (aria-describedby). Sin
// JavaScript, el usuario escribe la clave a mano y el servidor la valida.
(function () {
    const ESPERA_MS = 300;
    let contador = 0;

    document.querySelectorAll('input[data-autocompletar]').forEach(function (campo) {
        const url = campo.dataset.autocompletar;
        const ayuda = document.getElementById(campo.getAttribute('aria-describedby'));
        const lista = document.createElement('ul');
        let temporizador = null;
        let peticionActual = null;
        let sugerencias = [];
        let activa = -1;

        contador++;
        lista.id = 'sugerencias-' + contador;
        lista.className = 'sugerencias';
        lista.setAttribute('role', 'listbox');
        lista.hidden = true;

        campo.parentElement.classList.add('con-sugerencias');
        campo.insertAdjacentElement('afterend', lista);
        campo.setAttribute('role', 'combobox');
        campo.setAttribute('aria-autocomplete', 'list');
        campo.setAttribute('aria-controls', lista.id);
        campo.setAttribute('aria-expanded', 'false');

        function cerrar() {
            lista.hidden = true;
            campo.setAttribute('aria-expanded', 'false');
            campo.removeAttribute('aria-activedescendant');
            activa = -1;
        }

        function marcar(indice) {
            const opciones = lista.querySelectorAll('[role="option"]');

            opciones.forEach(function (opcion, i) {
                opcion.setAttribute('aria-selected', i === indice ? 'true' : 'false');
            });

            activa = indice;

            if (opciones[indice]) {
                campo.setAttribute('aria-activedescendant', opciones[indice].id);
                opciones[indice].scrollIntoView({ block: 'nearest' });
            }
        }

        function elegir(indice) {
            const sugerencia = sugerencias[indice];

            if (!sugerencia) {
                return;
            }

            campo.value = sugerencia.clave;

            if (ayuda) {
                ayuda.textContent = sugerencia.descripcion;
            }

            cerrar();
        }

        function mostrar(datos) {
            sugerencias = datos;
            lista.replaceChildren();

            if (datos.length === 0) {
                cerrar();
                return;
            }

            datos.forEach(function (sugerencia, indice) {
                const opcion = document.createElement('li');
                opcion.id = lista.id + '-' + indice;
                opcion.setAttribute('role', 'option');
                opcion.setAttribute('aria-selected', 'false');
                opcion.textContent = sugerencia.clave + ' – ' + sugerencia.descripcion;

                // mousedown en lugar de click: se dispara antes de que el campo pierda el foco.
                opcion.addEventListener('mousedown', function (evento) {
                    evento.preventDefault();
                    elegir(indice);
                });

                lista.appendChild(opcion);
            });

            lista.hidden = false;
            campo.setAttribute('aria-expanded', 'true');
            activa = -1;
        }

        function buscar(termino) {
            if (peticionActual) {
                peticionActual.abort();
            }

            if (termino === '') {
                cerrar();
                return;
            }

            peticionActual = new AbortController();

            axios.get(url, { params: { q: termino }, signal: peticionActual.signal })
                .then(function (respuesta) {
                    if (Array.isArray(respuesta.data)) {
                        mostrar(respuesta.data);
                    }
                })
                .catch(function (error) {
                    if (axios.isCancel(error)) {
                        return;
                    }

                    const estado = error.response && error.response.status;

                    // Sesión vencida: recargar para que Laravel lleve al login.
                    if (estado === 401 || estado === 419) {
                        window.location.reload();
                        return;
                    }

                    cerrar();
                });
        }

        campo.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(function () {
                buscar(campo.value.trim());
            }, ESPERA_MS);
        });

        campo.addEventListener('keydown', function (evento) {
            if (lista.hidden) {
                return;
            }

            if (evento.key === 'ArrowDown') {
                evento.preventDefault();
                marcar(Math.min(activa + 1, sugerencias.length - 1));
            } else if (evento.key === 'ArrowUp') {
                evento.preventDefault();
                marcar(Math.max(activa - 1, 0));
            } else if (evento.key === 'Enter' && activa >= 0) {
                evento.preventDefault();
                elegir(activa);
            } else if (evento.key === 'Escape') {
                cerrar();
            }
        });

        campo.addEventListener('blur', cerrar);
    });
})();
