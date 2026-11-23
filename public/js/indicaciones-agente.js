// Indicaciones de los agentes de WhatsApp por secciones (045). El servidor ya
// pinta las preguntas guardadas más un par vacío (adición 2); este script
// abre otro par al escribir en el último (regla 8), quita pares con ✕
// (regla 9), renumera (adición 4), cuida el foco y los avisos para lectores
// de pantalla (adición 5) y actualiza el contador de caracteres (regla 18).
(function () {
    const bloques = document.querySelectorAll('[data-indicaciones]');

    bloques.forEach(function (bloque) {
        const lista = bloque.querySelector('[data-preguntas]');
        const molde = bloque.querySelector('[data-molde-pregunta]');
        const anuncio = bloque.querySelector('[data-anuncio]');
        const tope = bloque.querySelector('[data-tope]');
        const contador = bloque.querySelector('[data-contador]');

        if (!lista || !molde) {
            return;
        }

        const maximo = parseInt(lista.dataset.max, 10) || 30;

        function pares() {
            return Array.from(lista.querySelectorAll('[data-pregunta]'));
        }

        function campos(par) {
            return Array.from(par.querySelectorAll('input, textarea'));
        }

        function vacio(par) {
            return campos(par).every(function (campo) {
                return campo.value.trim() === '';
            });
        }

        function anunciar(texto) {
            if (!anuncio) {
                return;
            }
            // Vaciar y volver a escribir hace que se repita el mismo aviso.
            anuncio.textContent = '';
            window.setTimeout(function () {
                anuncio.textContent = texto;
            }, 50);
        }

        function renumerar() {
            pares().forEach(function (par, i) {
                par.querySelectorAll('[name]').forEach(function (campo) {
                    campo.name = campo.name.replace(/\[preguntas\]\[[^\]]+\]/, '[preguntas][' + i + ']');
                });
                par.querySelectorAll('[id]').forEach(function (campo) {
                    campo.id = campo.id.replace(/-p[^-]+-/, '-p' + i + '-');
                });
                par.querySelectorAll('label[for]').forEach(function (etiqueta) {
                    etiqueta.htmlFor = etiqueta.htmlFor.replace(/-p[^-]+-/, '-p' + i + '-');
                });

                const leyenda = par.querySelector('legend');
                if (leyenda) {
                    leyenda.textContent = 'Pregunta ' + (i + 1);
                }

                const quitar = par.querySelector('[data-quitar]');
                if (quitar) {
                    quitar.setAttribute('aria-label', 'Quitar la pregunta ' + (i + 1));
                }
            });

            if (tope) {
                tope.hidden = pares().length < maximo || !pares().every(function (par) {
                    return !vacio(par);
                });
            }
        }

        function prepararPar(par) {
            const quitar = par.querySelector('[data-quitar]');
            if (quitar) {
                quitar.hidden = false;
            }
        }

        function agregarPar() {
            const copia = molde.content.firstElementChild.cloneNode(true);
            prepararPar(copia);
            lista.appendChild(copia);
            renumerar();

            return copia;
        }

        function actualizarContador() {
            if (!contador) {
                return;
            }

            let total = 0;
            bloque.querySelectorAll('textarea, input[type="text"]').forEach(function (campo) {
                if (!campo.closest('template')) {
                    total += campo.value.length;
                }
            });

            contador.textContent = total.toLocaleString('es-MX') + ' caracteres en total';
        }

        pares().forEach(prepararPar);

        // Regla 8: al escribir en el último par se abre otro debajo.
        lista.addEventListener('input', function (evento) {
            const par = evento.target.closest('[data-pregunta]');
            const todos = pares();

            if (par && par === todos[todos.length - 1] && !vacio(par)) {
                if (todos.length < maximo) {
                    agregarPar();
                    anunciar('Pregunta agregada.');
                } else {
                    renumerar();
                    anunciar(tope ? tope.textContent.trim() : '');
                }
            }
        });

        // Regla 9 y adición 5: quitar el par y dejar el foco en el que quedó en su lugar.
        lista.addEventListener('click', function (evento) {
            const quitar = evento.target.closest('[data-quitar]');
            if (!quitar) {
                return;
            }

            const par = quitar.closest('[data-pregunta]');
            const siguiente = par.nextElementSibling || par.previousElementSibling;
            par.remove();

            // Siempre queda un par vacío al final para seguir agregando.
            const todos = pares();
            const ultimo = todos[todos.length - 1];
            if (!ultimo || (!vacio(ultimo) && todos.length < maximo)) {
                agregarPar();
            }

            renumerar();
            actualizarContador();
            anunciar('Pregunta quitada.');

            const destino = (siguiente && siguiente.isConnected ? siguiente : pares()[0]);
            const campo = destino && destino.querySelector('input, textarea');
            if (campo) {
                campo.focus();
            }
        });

        bloque.addEventListener('input', actualizarContador);
    });
})();
