// Tabla de líneas de un documento (cotización hoy, factura después).
//
// Se activa en un formulario con data-documento-lineas y data-sugerencias="<url>".
// Agrega artículos con el buscador (la URL responde JSON
// [{ id, nombre, modelo, precio_unitario, tasa_iva }]), agrega líneas libres,
// quita líneas, avisa cuando un artículo ya está en el documento y muestra los
// totales en vivo con TotalesDocumento. Los totales son informativos: los que
// cuentan los calcula el servidor al guardar.
(function () {
    const ESPERA_MS = 300;
    const formulario = document.querySelector('form[data-documento-lineas]');

    if (!formulario || !window.TotalesDocumento) {
        return;
    }

    const cuerpo = formulario.querySelector('[data-lineas]');
    const plantilla = document.getElementById('plantilla-linea');
    const sinLineas = formulario.querySelector('[data-sin-lineas]');
    const buscador = formulario.querySelector('[data-buscar-articulo]');
    const dialogo = document.getElementById('aviso-duplicado');
    const formato = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
    let lineaDuplicada = null;

    function filas() {
        return Array.from(cuerpo.querySelectorAll('tr[data-linea]'));
    }

    function campo(fila, nombre) {
        return fila.querySelector('[data-campo="' + nombre + '"]');
    }

    // Índices consecutivos lineas[0], lineas[1]… y el número visible de cada fila.
    function renumerar() {
        filas().forEach(function (fila, indice) {
            fila.querySelectorAll('[name^="lineas["]').forEach(function (elemento) {
                elemento.name = elemento.name.replace(/^lineas\[[^\]]*\]/, 'lineas[' + indice + ']');
            });
            fila.querySelector('[data-linea-numero]').textContent = indice + 1;
        });

        if (sinLineas) {
            sinLineas.hidden = filas().length > 0;
        }
    }

    function valor(nombre) {
        const elemento = formulario.elements.namedItem(nombre);

        return elemento ? elemento.value : '';
    }

    function recalcular() {
        const lineas = filas().map(function (fila) {
            return {
                cantidad: campo(fila, 'cantidad').value,
                precio_unitario: campo(fila, 'precio_unitario').value,
                descuento_tipo: campo(fila, 'descuento_tipo').value,
                descuento_valor: campo(fila, 'descuento_valor').value,
                tasa_iva: campo(fila, 'tasa_iva').value,
            };
        });

        const resultado = window.TotalesDocumento.calcular(lineas, valor('descuento_global_tipo'), valor('descuento_global_valor'));

        filas().forEach(function (fila, indice) {
            fila.querySelector('[data-linea-importe]').value = formato.format(Number(resultado.lineas[indice].importe));
        });

        formulario.querySelectorAll('[data-total]').forEach(function (salida) {
            const monto = formato.format(Number(resultado[salida.dataset.total]));

            salida.value = salida.dataset.total === 'total_descuento' ? '−' + monto : monto;
        });
    }

    function prepararFila(fila) {
        const quitar = fila.querySelector('[data-quitar-linea]');

        if (quitar) {
            quitar.hidden = false;
        }
    }

    function nuevaFila(datos) {
        const fila = plantilla.content.firstElementChild.cloneNode(true);

        Object.keys(datos).forEach(function (nombre) {
            const elemento = campo(fila, nombre);

            if (elemento) {
                elemento.value = datos[nombre] === null || datos[nombre] === undefined ? '' : datos[nombre];
            }
        });

        prepararFila(fila);
        cuerpo.appendChild(fila);
        renumerar();
        recalcular();

        return fila;
    }

    function agregarArticulo(articulo) {
        const existente = filas().find(function (fila) {
            return campo(fila, 'articulo_id').value === String(articulo.id);
        });

        if (existente) {
            avisarDuplicado(existente);
            return;
        }

        const fila = nuevaFila({
            articulo_id: articulo.id,
            cantidad: 1,
            descripcion: articulo.nombre,
            modelo: articulo.modelo,
            precio_unitario: articulo.precio_unitario,
            tasa_iva: articulo.tasa_iva,
        });

        campo(fila, 'cantidad').focus();
        campo(fila, 'cantidad').select();
    }

    // Aviso de duplicado: sumar unidades a la línea existente o cancelar. Nunca
    // agrega una segunda línea del mismo artículo.
    function avisarDuplicado(fila) {
        if (!dialogo) {
            return;
        }

        lineaDuplicada = fila;
        dialogo.querySelector('[data-duplicado-numero]').textContent = fila.querySelector('[data-linea-numero]').textContent;
        dialogo.querySelector('[data-duplicado-descripcion]').textContent = campo(fila, 'descripcion').value;
        dialogo.querySelector('[data-duplicado-modelo]').textContent = campo(fila, 'modelo').value || '—';
        dialogo.querySelector('[data-duplicado-cantidad]').textContent = campo(fila, 'cantidad').value;
        dialogo.querySelector('[data-duplicado-sumar]').value = 1;
        dialogo.returnValue = '';
        dialogo.showModal();
    }

    if (dialogo) {
        dialogo.addEventListener('close', function () {
            const sumar = parseInt(dialogo.querySelector('[data-duplicado-sumar]').value, 10);

            if (dialogo.returnValue === 'sumar' && lineaDuplicada && Number.isInteger(sumar) && sumar >= 1) {
                const cantidad = campo(lineaDuplicada, 'cantidad');
                const actual = parseInt(cantidad.value, 10);

                cantidad.value = (Number.isInteger(actual) ? actual : 0) + sumar;
                recalcular();
            }

            lineaDuplicada = null;
            buscador.focus();
        });
    }

    // Buscador de artículos (combobox con lista de sugerencias).
    function iniciarBuscador() {
        const lista = document.createElement('ul');
        let temporizador = null;
        let peticionActual = null;
        let sugerencias = [];
        let activa = -1;

        lista.id = 'sugerencias-articulos';
        lista.className = 'sugerencias';
        lista.setAttribute('role', 'listbox');
        lista.hidden = true;

        buscador.parentElement.classList.add('con-sugerencias');
        buscador.insertAdjacentElement('afterend', lista);
        buscador.setAttribute('role', 'combobox');
        buscador.setAttribute('aria-autocomplete', 'list');
        buscador.setAttribute('aria-controls', lista.id);
        buscador.setAttribute('aria-expanded', 'false');

        function cerrar() {
            lista.hidden = true;
            buscador.setAttribute('aria-expanded', 'false');
            buscador.removeAttribute('aria-activedescendant');
            activa = -1;
        }

        function marcar(indice) {
            const opciones = lista.querySelectorAll('[role="option"]');

            opciones.forEach(function (opcion, i) {
                opcion.setAttribute('aria-selected', i === indice ? 'true' : 'false');
            });

            activa = indice;

            if (opciones[indice]) {
                buscador.setAttribute('aria-activedescendant', opciones[indice].id);
                opciones[indice].scrollIntoView({ block: 'nearest' });
            }
        }

        function elegir(indice) {
            const articulo = sugerencias[indice];

            if (!articulo) {
                return;
            }

            buscador.value = '';
            cerrar();
            agregarArticulo(articulo);
        }

        function mostrar(resultados) {
            sugerencias = resultados;
            lista.innerHTML = '';

            if (!resultados.length) {
                const vacio = document.createElement('li');

                vacio.textContent = 'Sin coincidencias';
                vacio.className = 'ayuda';
                lista.appendChild(vacio);
            }

            resultados.forEach(function (articulo, i) {
                const opcion = document.createElement('li');

                opcion.id = lista.id + '-' + i;
                opcion.setAttribute('role', 'option');
                opcion.setAttribute('aria-selected', 'false');
                opcion.textContent = articulo.nombre + ' · ' + articulo.modelo + ' · ' + formato.format(Number(articulo.precio_unitario));
                opcion.addEventListener('mousedown', function (evento) {
                    evento.preventDefault();
                    elegir(i);
                });
                lista.appendChild(opcion);
            });

            lista.hidden = false;
            buscador.setAttribute('aria-expanded', 'true');
            activa = -1;
        }

        function buscar() {
            const termino = buscador.value.trim();

            if (peticionActual) {
                peticionActual.abort();
            }

            if (termino === '') {
                cerrar();
                return;
            }

            peticionActual = new AbortController();

            axios.get(formulario.dataset.sugerencias, { params: { q: termino }, signal: peticionActual.signal })
                .then(function (respuesta) {
                    mostrar(Array.isArray(respuesta.data) ? respuesta.data : []);
                })
                .catch(function (error) {
                    if (axios.isCancel(error)) {
                        return;
                    }

                    const estado = error.response && error.response.status;

                    if (estado === 401 || estado === 419) {
                        window.location.reload();
                    }
                });
        }

        buscador.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, ESPERA_MS);
        });

        buscador.addEventListener('keydown', function (evento) {
            if (evento.key === 'ArrowDown' && !lista.hidden) {
                evento.preventDefault();
                marcar(Math.min(activa + 1, sugerencias.length - 1));
            } else if (evento.key === 'ArrowUp' && !lista.hidden) {
                evento.preventDefault();
                marcar(Math.max(activa - 1, 0));
            } else if (evento.key === 'Enter') {
                // Enter en el buscador nunca envía el formulario.
                evento.preventDefault();

                if (!lista.hidden && activa >= 0) {
                    elegir(activa);
                } else if (!lista.hidden && sugerencias.length === 1) {
                    elegir(0);
                }
            } else if (evento.key === 'Escape') {
                cerrar();
            }
        });

        buscador.addEventListener('blur', cerrar);
    }

    // Arranque: sin JavaScript la tabla trae filas vacías para capturar líneas
    // libres; con él se quitan y aparecen el buscador y los botones.
    cuerpo.querySelectorAll('tr[data-linea-vacia]').forEach(function (fila) {
        fila.remove();
    });
    filas().forEach(prepararFila);
    formulario.querySelector('[data-controles-lineas]').hidden = false;

    formulario.querySelector('[data-agregar-linea-libre]').addEventListener('click', function () {
        const fila = nuevaFila({ cantidad: 1, tasa_iva: '16' });

        campo(fila, 'descripcion').focus();
    });

    cuerpo.addEventListener('click', function (evento) {
        const boton = evento.target.closest('[data-quitar-linea]');

        if (!boton) {
            return;
        }

        boton.closest('tr[data-linea]').remove();
        renumerar();
        recalcular();
    });

    formulario.addEventListener('input', recalcular);
    formulario.addEventListener('change', recalcular);

    iniciarBuscador();
    renumerar();
    recalcular();
})();
