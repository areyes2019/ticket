// Lista desplegable con buscador (al estilo de Select2) para selects largos,
// como el de clientes.
//
// Se activa en cualquier <select data-buscable>. El select original se queda en
// el formulario, oculto, y sigue siendo el que viaja al servidor: al elegir una
// opción se le escribe el valor y se dispara "change", así que quien ya lo
// escuchaba (descuento del cliente, precio distribuidor) no cambia. Sin
// JavaScript se ve el select de siempre.
//
// El contenido que llega después (la vista previa de la bandeja) se activa con
// window.SelectBuscable.activar(raiz).
(function () {
    if (window.SelectBuscable) {
        return;
    }

    const MAXIMO_VISIBLES = 100;
    let contador = 0;

    function normalizar(texto) {
        return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function mejorar(select) {
        if (select.disabled || select.multiple || select.dataset.buscableActivo) {
            return;
        }

        select.dataset.buscableActivo = 'true';

        contador++;
        const prefijo = 'select-buscable-' + contador;
        const etiqueta = document.querySelector('label[for="' + select.id + '"]');
        const vacia = Array.from(select.options).find(function (opcion) { return opcion.value === ''; });
        const opciones = Array.from(select.options)
            .filter(function (opcion) { return opcion.value !== ''; })
            .map(function (opcion) {
                return { valor: opcion.value, texto: opcion.text, buscable: normalizar(opcion.text) };
            });

        const contenedor = document.createElement('div');
        const boton = document.createElement('button');
        const textoBoton = document.createElement('span');
        const panel = document.createElement('div');
        const buscador = document.createElement('input');
        const lista = document.createElement('ul');
        const aviso = document.createElement('p');
        let visibles = [];
        let activa = -1;

        contenedor.className = 'select-buscable';

        boton.type = 'button';
        boton.id = prefijo + '-boton';
        boton.className = 'select-buscable-boton';
        boton.setAttribute('aria-haspopup', 'listbox');
        boton.setAttribute('aria-expanded', 'false');
        textoBoton.className = 'select-buscable-texto';
        boton.append(textoBoton);

        panel.className = 'select-buscable-panel';
        panel.hidden = true;

        buscador.type = 'search';
        buscador.className = 'select-buscable-buscador';
        buscador.placeholder = select.dataset.buscable || 'Buscar…';
        buscador.autocomplete = 'off';
        buscador.setAttribute('role', 'combobox');
        buscador.setAttribute('aria-autocomplete', 'list');
        buscador.setAttribute('aria-controls', prefijo + '-lista');
        buscador.setAttribute('aria-expanded', 'true');

        lista.id = prefijo + '-lista';
        lista.className = 'select-buscable-lista';
        lista.setAttribute('role', 'listbox');

        aviso.className = 'select-buscable-aviso';
        aviso.setAttribute('aria-live', 'polite');

        panel.append(buscador, lista, aviso);
        contenedor.append(boton, panel);
        select.insertAdjacentElement('afterend', contenedor);

        // El select queda debajo del botón, invisible pero presente, para que el
        // navegador muestre ahí su aviso de "campo obligatorio".
        select.classList.add('select-buscable-original');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        contenedor.prepend(select);

        if (etiqueta) {
            etiqueta.htmlFor = boton.id;
            etiqueta.id = etiqueta.id || prefijo + '-etiqueta';
            buscador.setAttribute('aria-labelledby', etiqueta.id);
        }

        function mostrarSeleccion() {
            const elegida = select.options[select.selectedIndex];
            const hayValor = elegida && elegida.value !== '';

            textoBoton.textContent = hayValor ? elegida.text : (vacia ? vacia.text : '');
            boton.classList.toggle('select-buscable-vacio', !hayValor);
        }

        function marcar(indice) {
            const elementos = lista.querySelectorAll('[role="option"]');

            elementos.forEach(function (elemento, i) {
                elemento.classList.toggle('select-buscable-activa', i === indice);
            });
            activa = indice;

            if (elementos[indice]) {
                buscador.setAttribute('aria-activedescendant', elementos[indice].id);
                elementos[indice].scrollIntoView({ block: 'nearest' });
            } else {
                buscador.removeAttribute('aria-activedescendant');
            }
        }

        function filtrar() {
            const palabras = normalizar(buscador.value).split(/\s+/).filter(Boolean);
            const coincidencias = opciones.filter(function (opcion) {
                return palabras.every(function (palabra) { return opcion.buscable.includes(palabra); });
            });

            visibles = coincidencias.slice(0, MAXIMO_VISIBLES);
            lista.replaceChildren();

            visibles.forEach(function (opcion, i) {
                const elemento = document.createElement('li');

                elemento.id = prefijo + '-opcion-' + i;
                elemento.setAttribute('role', 'option');
                elemento.setAttribute('aria-selected', String(opcion.valor === select.value));
                elemento.textContent = opcion.texto;
                elemento.addEventListener('mousedown', function (evento) {
                    evento.preventDefault();
                    elegir(opcion);
                });
                lista.append(elemento);
            });

            if (coincidencias.length === 0) {
                aviso.textContent = 'Sin resultados para “' + buscador.value.trim() + '”.';
            } else if (coincidencias.length > visibles.length) {
                aviso.textContent = 'Mostrando ' + visibles.length + ' de ' + coincidencias.length + '. Escribe más para afinar.';
            } else {
                aviso.textContent = '';
            }
            aviso.hidden = aviso.textContent === '';

            const seleccionada = visibles.findIndex(function (opcion) { return opcion.valor === select.value; });
            marcar(palabras.length === 0 && seleccionada >= 0 ? seleccionada : (visibles.length ? 0 : -1));
        }

        function abrir(textoInicial) {
            panel.hidden = false;
            boton.setAttribute('aria-expanded', 'true');
            contenedor.classList.add('select-buscable-abierto');
            buscador.value = textoInicial || '';
            filtrar();
            buscador.focus();
        }

        function cerrar(devolverFoco) {
            if (panel.hidden) {
                return;
            }

            panel.hidden = true;
            boton.setAttribute('aria-expanded', 'false');
            contenedor.classList.remove('select-buscable-abierto');

            if (devolverFoco) {
                boton.focus();
            }
        }

        function elegir(opcion) {
            const cambio = select.value !== opcion.valor;

            select.value = opcion.valor;
            mostrarSeleccion();
            cerrar(true);

            if (cambio) {
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        boton.addEventListener('click', function () {
            panel.hidden ? abrir() : cerrar(true);
        });

        boton.addEventListener('keydown', function (evento) {
            if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
                evento.preventDefault();
                abrir();
            } else if (evento.key.length === 1 && evento.key !== ' ' && !evento.ctrlKey && !evento.metaKey && !evento.altKey) {
                // Escribir sobre el botón abre el buscador con esa letra.
                evento.preventDefault();
                abrir(evento.key);
            }
        });

        buscador.addEventListener('input', filtrar);

        buscador.addEventListener('keydown', function (evento) {
            if (evento.key === 'ArrowDown') {
                evento.preventDefault();
                marcar(Math.min(activa + 1, visibles.length - 1));
            } else if (evento.key === 'ArrowUp') {
                evento.preventDefault();
                marcar(Math.max(activa - 1, 0));
            } else if (evento.key === 'Enter') {
                evento.preventDefault();
                if (visibles[activa]) {
                    elegir(visibles[activa]);
                }
            } else if (evento.key === 'Escape') {
                // Dentro de un <dialog>, Escape cerraría la ventana completa.
                evento.preventDefault();
                evento.stopPropagation();
                cerrar(true);
            } else if (evento.key === 'Tab') {
                cerrar(false);
            }
        });

        document.addEventListener('mousedown', function alPresionarFuera(evento) {
            if (!contenedor.isConnected) {
                // La vista previa que lo contenía ya se reemplazó.
                document.removeEventListener('mousedown', alPresionarFuera);
            } else if (!contenedor.contains(evento.target)) {
                cerrar(false);
            }
        });

        // Si el navegador enfoca el select (aviso de obligatorio), pasa al botón.
        select.addEventListener('focus', function () {
            boton.focus();
        });

        select.addEventListener('change', mostrarSeleccion);

        if (select.form) {
            select.form.addEventListener('reset', function () {
                setTimeout(mostrarSeleccion);
            });
        }

        mostrarSeleccion();
    }

    window.SelectBuscable = {
        activar: function (raiz) {
            (raiz || document).querySelectorAll('select[data-buscable]').forEach(mejorar);
        },
    };

    window.SelectBuscable.activar();
})();
