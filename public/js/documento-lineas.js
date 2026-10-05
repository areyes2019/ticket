// Tabla de líneas de un documento (cotización o factura).
//
// Se activa en un formulario con data-documento-lineas y data-sugerencias="<url>".
// Agrega artículos con el buscador (la URL responde JSON
// [{ id, nombre, modelo, precio_unitario, tasa_iva }]), agrega líneas libres
// (salvo con data-sin-lineas-libres),
// quita líneas, avisa cuando un artículo ya está en el documento y muestra los
// totales en vivo con TotalesDocumento. Los totales son informativos: los que
// cuentan los calcula el servidor al guardar.
//
// Con un select[data-proveedor-orden] (orden de compra), el buscador solo
// ofrece artículos de ese proveedor (manda proveedor_id), queda deshabilitado
// mientras no haya proveedor, y cambiar de proveedor pide confirmar antes de
// quitar las líneas de artículos (las libres se quedan).
//
// Con un [data-aviso-descuento-cliente] dentro (solo la cotización, 023), cada
// artículo que se agrega trae el descuento permanente del cliente elegido, y
// cambiar de cliente reemplaza el descuento de todas las líneas por el del
// nuevo (o lo quita si no tiene). Al editar manda el porcentaje congelado de
// la cotización mientras no se cambie de cliente. Factura y orden de compra no
// traen el aviso y no cambian.
//
// Con un [data-aviso-distribuidor] dentro (cotización y factura que no viene
// de una cotización, 028), cada artículo que se agrega para un cliente
// distribuidor nace con su precio distribuidor (precio_distribuidor de las
// sugerencias), y cambiar de cliente reemplaza el precio de todas las líneas
// de artículo por el que le toca al nuevo, aunque se hayan editado a mano.
// Cada fila guarda los dos precios en data-precio-directo y
// data-precio-distribuidor (los pinta el servidor en las líneas ya
// guardadas); una fila sin ellos conserva su precio.
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
    const selectorProveedor = formulario.querySelector('select[data-proveedor-orden]');
    const avisoDescuento = formulario.querySelector('[data-aviso-descuento-cliente]');
    const avisoDistribuidor = formulario.querySelector('[data-aviso-distribuidor]');
    let lineaDuplicada = null;
    let descuentoCliente = null;
    let clienteDistribuidor = false;

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

    // El precio con el que nace la línea: el distribuidor si el cliente lo es (028).
    function precioQueToca(articulo) {
        return avisoDistribuidor && clienteDistribuidor && articulo.precio_distribuidor !== undefined ? articulo.precio_distribuidor : articulo.precio_unitario;
    }

    function agregarArticulo(articulo) {
        const existente = filas().find(function (fila) {
            return campo(fila, 'articulo_id').value === String(articulo.id);
        });

        if (existente) {
            avisarDuplicado(existente);
            return;
        }

        const conDistribuidor = avisoDistribuidor && articulo.precio_distribuidor !== undefined;
        const fila = nuevaFila({
            articulo_id: articulo.id,
            cantidad: 1,
            descripcion: articulo.nombre,
            modelo: articulo.modelo,
            precio_unitario: precioQueToca(articulo),
            tasa_iva: articulo.tasa_iva,
            descuento_tipo: descuentoCliente ? 'porcentaje' : null,
            descuento_valor: descuentoCliente ? descuentoCliente.porcentaje : null,
        });

        if (conDistribuidor) {
            fila.dataset.precioDirecto = articulo.precio_unitario;
            fila.dataset.precioDistribuidor = articulo.precio_distribuidor;
        }

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
                opcion.textContent = articulo.nombre + ' · ' + articulo.modelo + ' · ' + formato.format(Number(precioQueToca(articulo)));
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

            const parametros = { q: termino };

            if (selectorProveedor) {
                parametros.proveedor_id = selectorProveedor.value;
            }

            axios.get(formulario.dataset.sugerencias, { params: parametros, signal: peticionActual.signal })
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

    // Proveedor de la orden de compra: sin él no hay a quién buscarle artículos.
    function iniciarProveedor() {
        const textoNormal = buscador.placeholder;
        let anterior = selectorProveedor.value;

        function actualizarBuscador() {
            const sinProveedor = selectorProveedor.value === '';

            buscador.disabled = sinProveedor;
            buscador.placeholder = sinProveedor ? (buscador.dataset.sinProveedor || '') : textoNormal;
        }

        selectorProveedor.addEventListener('change', function () {
            const deArticulo = lineasDeArticulo();

            if (deArticulo.length && !window.confirm('Cambiar de proveedor quitará ' + (deArticulo.length === 1 ? 'la línea de artículo capturada' : 'las ' + deArticulo.length + ' líneas de artículos capturadas') + '. ¿Continuar?')) {
                selectorProveedor.value = anterior;
                return;
            }

            deArticulo.forEach(function (fila) {
                fila.remove();
            });
            anterior = selectorProveedor.value;
            buscador.value = '';
            actualizarBuscador();
            renumerar();
            recalcular();
        });

        actualizarBuscador();
    }

    // Descuento permanente del cliente (023).
    function iniciarDescuentoCliente() {
        const selectorCliente = formulario.querySelector('select[name="cliente_id"]');
        const descuentos = leerJson(avisoDescuento.dataset.descuentosCliente) || {};
        const congelado = leerJson(avisoDescuento.dataset.descuentoCongelado);

        function descuentoDe(clienteId) {
            const descuento = congelado && String(congelado.cliente_id) === clienteId ? congelado : descuentos[clienteId];

            return descuento && Number(descuento.porcentaje) > 0 ? descuento : null;
        }

        function mostrarAviso() {
            avisoDescuento.hidden = !descuentoCliente;

            if (descuentoCliente) {
                avisoDescuento.querySelector('[data-aviso-descuento-nombre]').textContent = descuentoCliente.nombre;
                avisoDescuento.querySelector('[data-aviso-descuento-porcentaje]').textContent = descuentoCliente.porcentaje;
            }
        }

        descuentoCliente = descuentoDe(selectorCliente.value);
        mostrarAviso();

        // Las ediciones a mano de las líneas no se respetan: eran para otro cliente.
        selectorCliente.addEventListener('change', function () {
            descuentoCliente = descuentoDe(selectorCliente.value);

            filas().forEach(function (fila) {
                campo(fila, 'descuento_tipo').value = descuentoCliente ? 'porcentaje' : '';
                campo(fila, 'descuento_valor').value = descuentoCliente ? descuentoCliente.porcentaje : '';
            });

            mostrarAviso();
            recalcular();
        });
    }

    // Precio distribuidor del cliente (028). Las ediciones a mano del precio
    // no se respetan al cambiar de cliente, igual que el descuento.
    function iniciarPrecioDistribuidor() {
        const selectorCliente = formulario.querySelector('select[name="cliente_id"]');
        const distribuidores = leerJson(avisoDistribuidor.dataset.clientesDistribuidores) || {};

        function mostrarAviso() {
            const nombre = distribuidores[selectorCliente.value];

            clienteDistribuidor = nombre !== undefined;
            avisoDistribuidor.hidden = !clienteDistribuidor;

            if (clienteDistribuidor) {
                avisoDistribuidor.querySelector('[data-aviso-distribuidor-nombre]').textContent = nombre;
            }
        }

        mostrarAviso();

        selectorCliente.addEventListener('change', function () {
            mostrarAviso();

            filas().forEach(function (fila) {
                const precio = clienteDistribuidor ? fila.dataset.precioDistribuidor : fila.dataset.precioDirecto;

                if (precio !== undefined && precio !== '') {
                    campo(fila, 'precio_unitario').value = precio;
                }
            });

            recalcular();
        });
    }

    function leerJson(texto) {
        try {
            return texto ? JSON.parse(texto) : null;
        } catch (error) {
            return null;
        }
    }

    function lineasDeArticulo() {
        return filas().filter(function (fila) {
            return campo(fila, 'articulo_id').value !== '';
        });
    }

    // Arranque: sin JavaScript la tabla trae filas vacías para capturar líneas
    // libres; con él se quitan y aparecen el buscador y los botones. Un
    // documento sin líneas libres (factura) no trae filas vacías ni el botón.
    cuerpo.querySelectorAll('tr[data-linea-vacia]').forEach(function (fila) {
        fila.remove();
    });
    filas().forEach(prepararFila);
    formulario.querySelector('[data-controles-lineas]').hidden = false;

    const agregarLineaLibre = formulario.querySelector('[data-agregar-linea-libre]');

    if (agregarLineaLibre && !formulario.hasAttribute('data-sin-lineas-libres')) {
        agregarLineaLibre.addEventListener('click', function () {
            const fila = nuevaFila({ cantidad: 1, tasa_iva: '16' });

            campo(fila, 'descripcion').focus();
        });
    }

    cuerpo.addEventListener('click', function (evento) {
        const boton = evento.target.closest('[data-quitar-linea]');

        if (!boton) {
            return;
        }

        boton.closest('tr[data-linea]').remove();
        renumerar();
        recalcular();
    });

    if (selectorProveedor) {
        iniciarProveedor();
    }

    if (avisoDescuento) {
        iniciarDescuentoCliente();
    }

    if (avisoDistribuidor) {
        iniciarPrecioDistribuidor();
    }

    formulario.addEventListener('input', recalcular);
    formulario.addEventListener('change', recalcular);

    iniciarBuscador();
    renumerar();
    recalcular();
})();
