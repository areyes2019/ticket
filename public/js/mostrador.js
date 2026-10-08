// Captura por pasos de la aplicación de mostrador (033).
//
// Se activa en un form[data-mostrador-captura] con data-flujo (venta, factura o
// cotizacion). Muestra un paso a la vez (<section data-paso>), lleva el carrito
// en memoria y, al enviar, lo escribe como campos ocultos lineas[i][...] del
// formulario, que va a la ruta de alta de siempre. No hay reglas de negocio
// aquí: los totales son los de TotalesDocumento (informativos) y el servidor
// valida y recalcula todo al guardar.
//
// - Cada paso queda en el #hash con pushState: "atrás" regresa un paso.
// - Clientes y artículos llegan como HTML de Blade, una página a la vez, al
//   llegar al marcador [data-siguiente] (IntersectionObserver).
// - El borrador vive en sessionStorage (mostrador:{flujo}) mientras la ventana
//   esté abierta; tras un error de validación manda lo enviado (data-anterior).
// - Descuento permanente solo en la cotización (023) y precio distribuidor en
//   cotización y factura (028), igual que documento-lineas.js.
(function (raiz) {
    // ---- Funciones puras del carrito (se prueban con node --test) ----

    function reglasDe(flujo) {
        return { descuento: flujo === 'cotizacion', distribuidor: flujo === 'cotizacion' || flujo === 'factura' };
    }

    function precioQueToca(linea, cliente, reglas) {
        if (reglas.distribuidor && cliente && cliente.distribuidor && linea.precio_distribuidor !== undefined && linea.precio_distribuidor !== null && linea.precio_distribuidor !== '') {
            return String(linea.precio_distribuidor);
        }

        return linea.precio_directo !== undefined && linea.precio_directo !== null && linea.precio_directo !== '' ? String(linea.precio_directo) : String(linea.precio_unitario);
    }

    function descuentoDe(cliente, reglas) {
        return reglas.descuento && cliente && Number(cliente.descuento) > 0 ? String(cliente.descuento) : null;
    }

    // Un toque suma una unidad: si el artículo ya está, crece su cantidad.
    function agregarArticulo(lineas, articulo, cliente, reglas) {
        const indice = lineas.findIndex(function (linea) {
            return String(linea.articulo_id) === String(articulo.id);
        });

        if (indice >= 0) {
            return cambiarCantidad(lineas, indice, 1);
        }

        const descuento = descuentoDe(cliente, reglas);
        const linea = {
            articulo_id: articulo.id,
            cantidad: 1,
            descripcion: articulo.nombre,
            modelo: articulo.modelo,
            precio_directo: String(articulo.precio_unitario),
            precio_distribuidor: articulo.precio_distribuidor === undefined ? null : articulo.precio_distribuidor,
            tasa_iva: String(articulo.tasa_iva),
            descuento_tipo: descuento ? 'porcentaje' : null,
            descuento_valor: descuento,
        };

        linea.precio_unitario = precioQueToca(linea, cliente, reglas);

        return lineas.concat([linea]);
    }

    function agregarLibre(lineas, datos) {
        return lineas.concat([{
            articulo_id: null,
            cantidad: 1,
            descripcion: datos.descripcion,
            modelo: null,
            precio_unitario: String(datos.precio_unitario),
            tasa_iva: String(datos.tasa_iva),
            descuento_tipo: null,
            descuento_valor: null,
        }]);
    }

    // Llegar a 0 quita el renglón.
    function cambiarCantidad(lineas, indice, delta) {
        return lineas.map(function (linea, i) {
            return i === indice ? Object.assign({}, linea, { cantidad: Number(linea.cantidad) + delta }) : linea;
        }).filter(function (linea) {
            return linea.cantidad > 0;
        });
    }

    function quitar(lineas, indice) {
        return lineas.filter(function (linea, i) {
            return i !== indice;
        });
    }

    // Cambiar de cliente reemplaza precio y descuento de las líneas de
    // artículo; las libres no cambian.
    function aplicarCliente(lineas, cliente, reglas) {
        const descuento = descuentoDe(cliente, reglas);

        return lineas.map(function (linea) {
            if (!linea.articulo_id) {
                return linea;
            }

            return Object.assign({}, linea, {
                precio_unitario: precioQueToca(linea, cliente, reglas),
                descuento_tipo: reglas.descuento ? (descuento ? 'porcentaje' : null) : linea.descuento_tipo,
                descuento_valor: reglas.descuento ? descuento : linea.descuento_valor,
            });
        });
    }

    function totales(lineas, calculadora) {
        return calculadora.calcular(lineas, null, null);
    }

    function piezas(lineas) {
        return lineas.reduce(function (suma, linea) {
            return suma + Number(linea.cantidad);
        }, 0);
    }

    const CAMPOS = ['articulo_id', 'cantidad', 'descripcion', 'modelo', 'precio_unitario', 'descuento_tipo', 'descuento_valor', 'tasa_iva'];

    // [[nombre, valor], ...] en el orden del carrito, como los manda el escritorio.
    function camposFormulario(lineas) {
        const campos = [];

        lineas.forEach(function (linea, i) {
            CAMPOS.forEach(function (campo) {
                const valor = linea[campo];

                campos.push(['lineas[' + i + '][' + campo + ']', valor === null || valor === undefined ? '' : String(valor)]);
            });
        });

        return campos;
    }

    // Las líneas que regresan del servidor tras un error de validación.
    function lineasDeAnterior(anteriores) {
        return (anteriores || []).filter(function (linea) {
            return linea && (linea.articulo_id || linea.descripcion);
        }).map(function (linea) {
            return {
                articulo_id: linea.articulo_id ? Number(linea.articulo_id) : null,
                cantidad: Number(linea.cantidad) > 0 ? Number(linea.cantidad) : 1,
                descripcion: linea.descripcion || '',
                modelo: linea.modelo || null,
                precio_unitario: String(linea.precio_unitario || ''),
                precio_directo: linea.precio_directo,
                precio_distribuidor: linea.precio_distribuidor,
                tasa_iva: String(linea.tasa_iva || ''),
                descuento_tipo: linea.descuento_tipo || null,
                descuento_valor: linea.descuento_valor || null,
            };
        });
    }

    const Carrito = {
        reglasDe: reglasDe,
        agregarArticulo: agregarArticulo,
        agregarLibre: agregarLibre,
        cambiarCantidad: cambiarCantidad,
        quitar: quitar,
        aplicarCliente: aplicarCliente,
        totales: totales,
        piezas: piezas,
        camposFormulario: camposFormulario,
        lineasDeAnterior: lineasDeAnterior,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = Carrito;
    }

    if (!raiz || typeof document === 'undefined') {
        return;
    }

    // ---- Borrador: se borra en las pantallas de resultado ----

    function leerBorrador(clave) {
        try {
            const texto = raiz.sessionStorage.getItem(clave);

            return texto ? JSON.parse(texto) : null;
        } catch (error) {
            return null;
        }
    }

    function guardarBorrador(clave, datos) {
        try {
            raiz.sessionStorage.setItem(clave, JSON.stringify(datos));
        } catch (error) {
            // Sin almacenamiento la captura funciona igual, solo sin borrador.
        }
    }

    function borrarBorradores() {
        try {
            ['venta', 'factura', 'cotizacion'].forEach(function (flujo) {
                raiz.sessionStorage.removeItem('mostrador:' + flujo);
            });
        } catch (error) {
            // Nada que borrar.
        }
    }

    if (document.querySelector('[data-mostrador-limpiar]')) {
        borrarBorradores();
    }

    const formulario = document.querySelector('form[data-mostrador-captura]');

    if (!formulario || !raiz.TotalesDocumento) {
        return;
    }

    // ---- La captura ----

    const flujo = formulario.dataset.flujo;
    const reglas = reglasDe(flujo);
    const claveBorrador = 'mostrador:' + flujo;
    const formato = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
    const secciones = Array.from(formulario.querySelectorAll('[data-paso]'));
    const pasos = secciones.map(function (seccion) {
        return seccion.dataset.paso;
    });
    const campoCliente = formulario.querySelector('[data-campo-cliente]');
    const ESPERA_MS = 300;

    const estado = {
        lineas: [],
        cliente: null,
        opciones: { uso: '', forma: '', metodo: '' },
        paso: pasos[0],
        enviando: false,
    };

    function leerJson(texto) {
        try {
            return texto ? JSON.parse(texto) : null;
        } catch (error) {
            return null;
        }
    }

    function pesos(valor) {
        return formato.format(Number(valor) || 0);
    }

    // -- Pasos --

    function pasoPermitido(paso) {
        const indice = pasos.indexOf(paso);

        if (indice < 0) {
            return false;
        }

        if (indice > pasos.indexOf('cliente') && flujo !== 'venta' && !estado.cliente) {
            return false;
        }

        if (indice > pasos.indexOf('carrito') && estado.lineas.length === 0) {
            return false;
        }

        // Los fiscales (solo la factura) se recorren en orden: no se salta uno sin elegir.
        return ['forma', 'metodo', 'revisar'].every(function (fiscal, i) {
            const posicion = pasos.indexOf(fiscal);

            return posicion < 0 || indice < posicion || estado.opciones[['uso', 'forma', 'metodo'][i]] !== '';
        });
    }

    function mostrarPaso(paso, agregarHistoria) {
        if (!pasoPermitido(paso)) {
            paso = pasos.filter(pasoPermitido).pop() || pasos[0];
        }

        estado.paso = paso;
        secciones.forEach(function (seccion) {
            seccion.hidden = seccion.dataset.paso !== paso;
        });

        const indice = pasos.indexOf(paso);
        const seccion = secciones[indice];

        formulario.querySelector('[data-indicador]').textContent = 'Paso ' + (indice + 1) + ' de ' + pasos.length + ' · ' + seccion.dataset.titulo;

        if (agregarHistoria) {
            history.pushState({ paso: paso }, '', '#' + paso);
        } else {
            history.replaceState({ paso: paso }, '', '#' + paso);
        }

        if (paso === 'cliente') {
            iniciarLista('clientes');
        }

        if (paso === 'articulos') {
            iniciarLista('articulos');
        }

        if (paso === 'revisar') {
            pintarRevision();
        }

        window.scrollTo(0, 0);
        guardar();
    }

    function siguientePaso() {
        const indice = pasos.indexOf(estado.paso);

        if (indice < pasos.length - 1) {
            mostrarPaso(pasos[indice + 1], true);
        }
    }

    window.addEventListener('popstate', function (evento) {
        const paso = (evento.state && evento.state.paso) || location.hash.slice(1);

        mostrarPaso(pasos.indexOf(paso) >= 0 ? paso : pasos[0], false);
    });

    // -- Listas de tarjetas (HTML de Blade) --

    const listas = {};

    function iniciarLista(nombre) {
        const contenedor = formulario.querySelector('[data-lista="' + nombre + '"]');

        if (!contenedor || listas[nombre]) {
            return;
        }

        const url = nombre === 'clientes' ? formulario.dataset.tarjetasClientes : formulario.dataset.tarjetasArticulos;
        const buscador = formulario.querySelector('[data-buscar-lista="' + nombre + '"]');
        const lista = { contenedor: contenedor, url: url, pedida: 0, observador: null };
        let temporizador = null;

        listas[nombre] = lista;

        lista.observador = 'IntersectionObserver' in window ? new IntersectionObserver(function (entradas) {
            entradas.forEach(function (entrada) {
                if (entrada.isIntersecting) {
                    cargarSiguiente(lista, entrada.target);
                }
            });
        }, { rootMargin: '200px' }) : null;

        if (buscador) {
            buscador.addEventListener('input', function () {
                clearTimeout(temporizador);
                temporizador = setTimeout(function () {
                    cargar(lista, buscador.value.trim(), true);
                }, ESPERA_MS);
            });
        }

        cargar(lista, buscador ? buscador.value.trim() : '', true);
    }

    function cargar(lista, termino, reiniciar) {
        const pedida = ++lista.pedida;
        const url = lista.url + (termino ? '?q=' + encodeURIComponent(termino) : '');

        traer(lista, url, pedida, reiniciar ? null : lista.contenedor.querySelector('[data-siguiente]'));
    }

    function cargarSiguiente(lista, marcador) {
        if (marcador.dataset.cargando) {
            return;
        }

        marcador.dataset.cargando = '1';
        traer(lista, marcador.dataset.siguiente, lista.pedida, marcador);
    }

    // Sin marcador reinicia la lista; con él, agrega la página en su lugar. Se
    // pide como AJAX (Accept JSON de app.js) para que una sesión caída responda
    // 401 en lugar de la página del login; el cuerpo es el HTML de Blade.
    function traer(lista, url, pedida, marcador) {
        axios.get(url, { responseType: 'text' })
            .then(function (respuesta) {
                if (pedida !== lista.pedida) {
                    return;
                }

                if (marcador) {
                    marcador.insertAdjacentHTML('beforebegin', respuesta.data);
                    marcador.remove();
                } else {
                    lista.contenedor.innerHTML = respuesta.data;
                }

                prepararLista(lista);
            })
            .catch(function (error) {
                const codigo = error.response && error.response.status;

                if (codigo === 401 || codigo === 419) {
                    window.location.reload();
                    return;
                }

                if (pedida !== lista.pedida) {
                    return;
                }

                mostrarErrorLista(lista, url, marcador);
            });
    }

    function mostrarErrorLista(lista, url, marcador) {
        const aviso = document.createElement('p');
        const boton = document.createElement('button');

        aviso.className = 'mostrador-vacio';
        aviso.textContent = navigator.onLine === false ? 'Sin conexión. ' : 'No se pudo cargar la lista. ';
        boton.type = 'button';
        boton.className = 'boton boton-secundario';
        boton.textContent = 'Reintentar';
        boton.addEventListener('click', function () {
            aviso.remove();
            traer(lista, url, lista.pedida, marcador ? marcador : null);
        });
        aviso.appendChild(boton);

        if (marcador) {
            delete marcador.dataset.cargando;
            marcador.before(aviso);
        } else {
            lista.contenedor.innerHTML = '';
            lista.contenedor.appendChild(aviso);
        }
    }

    function prepararLista(lista) {
        const marcador = lista.contenedor.querySelector('[data-siguiente]');

        if (marcador) {
            if (lista.observador) {
                lista.observador.observe(marcador);
            } else {
                cargarSiguiente(lista, marcador);
            }
        }

        marcarCantidades();
    }

    // -- Cliente --

    function elegirCliente(cliente) {
        estado.cliente = cliente;
        estado.lineas = aplicarCliente(estado.lineas, cliente, reglas);

        if (campoCliente) {
            campoCliente.value = cliente ? cliente.id : '';
        }

        const elegido = formulario.querySelector('[data-cliente-elegido]');

        if (elegido) {
            elegido.hidden = !cliente;
            elegido.textContent = cliente ? cliente.razon_social + ' · ' + cliente.rfc : '';
        }

        pintarCarrito();
    }

    formulario.addEventListener('click', function (evento) {
        const fichaCliente = evento.target.closest('[data-cliente]');
        const fichaArticulo = evento.target.closest('[data-articulo]');
        const opcion = evento.target.closest('[data-opcion]');
        const irPaso = evento.target.closest('[data-ir-paso]');
        const siguiente = evento.target.closest('[data-siguiente-paso]');

        if (fichaCliente) {
            evento.preventDefault();
            elegirCliente(leerJson(fichaCliente.dataset.cliente));
            mostrarPaso('articulos', true);
        } else if (fichaArticulo) {
            evento.preventDefault();
            estado.lineas = agregarArticulo(estado.lineas, leerJson(fichaArticulo.dataset.articulo), estado.cliente, reglas);
            pintarCarrito();
            guardar();
        } else if (opcion) {
            evento.preventDefault();
            elegirOpcion(opcion.closest('[data-opciones]').dataset.opciones, opcion.dataset.opcion);
            siguientePaso();
        } else if (irPaso) {
            mostrarPaso(irPaso.dataset.irPaso, true);
        } else if (siguiente) {
            if (estado.paso === 'cliente' && flujo === 'venta' && !clienteVentaValido()) {
                return;
            }

            siguientePaso();
        }
    });

    function clienteVentaValido() {
        return ['cliente_telefono', 'cliente_nombre', 'cliente_correo'].every(function (nombre) {
            const campo = formulario.elements.namedItem(nombre);

            return !campo || campo.reportValidity();
        });
    }

    // Enter en un buscador o un campo no envía la captura a medias.
    formulario.addEventListener('keydown', function (evento) {
        if (evento.key === 'Enter' && evento.target.tagName === 'INPUT') {
            evento.preventDefault();
        }
    });

    // -- Opciones fiscales (factura) --

    function elegirOpcion(nombre, valor) {
        estado.opciones[nombre] = valor;

        const campo = formulario.querySelector('[data-campo-opcion="' + nombre + '"]');

        if (campo) {
            campo.value = valor;
        }

        formulario.querySelectorAll('[data-opciones="' + nombre + '"] [data-opcion]').forEach(function (ficha) {
            const elegida = ficha.dataset.opcion === valor;

            ficha.classList.toggle('mostrador-opcion-elegida', elegida);
            ficha.setAttribute('aria-current', elegida ? 'true' : 'false');
        });

        guardar();
    }

    function sinAcentos(texto) {
        return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    formulario.querySelectorAll('[data-filtrar-opciones]').forEach(function (buscador) {
        const contenedor = formulario.querySelector('[data-opciones="' + buscador.dataset.filtrarOpciones + '"]');

        buscador.addEventListener('input', function () {
            const termino = sinAcentos(buscador.value.trim());
            let visibles = 0;

            contenedor.querySelectorAll('[data-opcion]').forEach(function (ficha) {
                ficha.hidden = termino !== '' && sinAcentos(ficha.dataset.texto).indexOf(termino) === -1;
                visibles += ficha.hidden ? 0 : 1;
            });

            contenedor.querySelector('[data-opciones-vacio]').hidden = visibles > 0;
        });
    });

    function textoOpcion(nombre) {
        const ficha = formulario.querySelector('[data-opciones="' + nombre + '"] [data-opcion="' + estado.opciones[nombre] + '"]');

        return ficha ? ficha.dataset.texto : '';
    }

    function pintarRevision() {
        const resumen = function (clave, texto) {
            formulario.querySelector('[data-resumen="' + clave + '"]').textContent = texto;
        };

        resumen('cliente', estado.cliente ? estado.cliente.razon_social : '');
        resumen('rfc', estado.cliente ? estado.cliente.rfc : '');
        resumen('total', 'Total ' + pesos(totales(estado.lineas, raiz.TotalesDocumento).total));
        resumen('uso', textoOpcion('uso'));
        resumen('forma', textoOpcion('forma'));
        resumen('metodo', textoOpcion('metodo'));
    }

    // -- Carrito --

    let errores = leerJson(formulario.dataset.errores) || {};

    function crearBoton(texto, etiqueta, alHacerClic, clase) {
        const boton = document.createElement('button');

        boton.type = 'button';
        boton.className = 'boton ' + (clase || 'boton-secundario');
        boton.textContent = texto;
        boton.setAttribute('aria-label', etiqueta);
        boton.addEventListener('click', alHacerClic);

        return boton;
    }

    function pintarCarrito() {
        const lista = formulario.querySelector('[data-carrito]');
        const calculo = totales(estado.lineas, raiz.TotalesDocumento);
        const resumen = piezas(estado.lineas) + (piezas(estado.lineas) === 1 ? ' artículo' : ' artículos') + ' · ' + pesos(calculo.total);

        lista.textContent = '';

        estado.lineas.forEach(function (linea, i) {
            const renglon = document.createElement('li');
            const datos = document.createElement('div');
            const titulo = document.createElement('strong');
            const detalle = document.createElement('span');
            const controles = document.createElement('div');
            const cantidad = document.createElement('span');
            const importe = document.createElement('span');

            renglon.className = 'mostrador-renglon';
            datos.className = 'mostrador-renglon-datos';
            titulo.textContent = linea.descripcion;
            detalle.className = 'mostrador-ficha-dato';
            detalle.textContent = (linea.modelo ? linea.modelo + ' · ' : '') + pesos(linea.precio_unitario) + ' c/u' + (linea.descuento_tipo === 'porcentaje' && linea.descuento_valor ? ' · desc. ' + linea.descuento_valor + '%' : '');
            datos.append(titulo, detalle);

            Object.keys(errores).filter(function (clave) {
                return clave.indexOf('lineas.' + i + '.') === 0;
            }).forEach(function (clave) {
                const error = document.createElement('span');

                error.className = 'mostrador-renglon-error';
                error.textContent = errores[clave][0];
                datos.appendChild(error);
            });

            controles.className = 'mostrador-renglon-controles';
            cantidad.className = 'mostrador-renglon-cantidad';
            cantidad.textContent = linea.cantidad;
            importe.className = 'mostrador-renglon-importe';
            importe.textContent = pesos(Number(calculo.lineas[i].importe) + Number(calculo.lineas[i].iva_importe));

            controles.append(
                crearBoton('−', 'Una menos de ' + linea.descripcion, function () {
                    actualizarLineas(cambiarCantidad(estado.lineas, i, -1));
                }),
                cantidad,
                crearBoton('+', 'Una más de ' + linea.descripcion, function () {
                    actualizarLineas(cambiarCantidad(estado.lineas, i, 1));
                }),
                crearBoton('Quitar', 'Quitar ' + linea.descripcion, function () {
                    actualizarLineas(quitar(estado.lineas, i));
                }, 'boton-suave'),
            );

            renglon.append(datos, controles, importe);
            lista.appendChild(renglon);
        });

        formulario.querySelector('[data-carrito-vacio]').hidden = estado.lineas.length > 0;
        formulario.querySelectorAll('[data-resumen-carrito]').forEach(function (salida) {
            salida.textContent = resumen;
        });
        formulario.querySelectorAll('[data-requiere-lineas]').forEach(function (boton) {
            boton.disabled = estado.lineas.length === 0;
        });

        marcarCantidades();
    }

    // Los errores por renglón dejan de valer en cuanto el carrito cambia.
    function actualizarLineas(lineas) {
        errores = {};
        estado.lineas = lineas;
        pintarCarrito();
        guardar();
    }

    function marcarCantidades() {
        const cantidades = {};

        estado.lineas.forEach(function (linea) {
            if (linea.articulo_id) {
                cantidades[linea.articulo_id] = linea.cantidad;
            }
        });

        formulario.querySelectorAll('[data-articulo]').forEach(function (ficha) {
            const articulo = leerJson(ficha.dataset.articulo);
            const marca = ficha.querySelector('[data-cantidad-ficha]');
            const cantidad = articulo ? cantidades[articulo.id] : undefined;

            marca.hidden = !cantidad;
            marca.textContent = cantidad ? '× ' + cantidad : '';
            ficha.classList.toggle('mostrador-ficha-agregada', Boolean(cantidad));
        });
    }

    function pintarErroresGenerales() {
        const generales = Object.keys(errores).filter(function (clave) {
            return !/^lineas\.\d+\./.test(clave);
        });
        const alerta = formulario.querySelector('[data-errores-generales]');

        if (!generales.length) {
            return;
        }

        const contenido = alerta.querySelector('.alerta-contenido');

        contenido.textContent = '';
        generales.forEach(function (clave) {
            const parrafo = document.createElement('p');

            parrafo.textContent = errores[clave][0];
            contenido.appendChild(parrafo);
        });
        alerta.hidden = false;
    }

    // -- Artículo suelto (venta) --

    const suelto = document.querySelector('[data-articulo-suelto]');

    if (suelto) {
        suelto.querySelector('[data-agregar-suelto]').addEventListener('click', function () {
            const descripcion = suelto.querySelector('[name="suelto_descripcion"]');
            const precio = suelto.querySelector('[name="suelto_precio"]');
            const tasa = suelto.querySelector('[name="suelto_tasa"]');
            const valido = descripcion.value.trim() !== '' && Number(precio.value) > 0;

            suelto.querySelector('[data-suelto-error]').hidden = valido;

            if (!valido) {
                return;
            }

            actualizarLineas(agregarLibre(estado.lineas, {
                descripcion: descripcion.value.trim(),
                precio_unitario: Number(precio.value).toFixed(2),
                tasa_iva: tasa.value,
            }));
            descripcion.value = '';
            precio.value = '';
            suelto.closest('dialog').close();
        });
    }

    // -- Borrador --

    function camposVenta() {
        const datos = {};

        ['cliente_telefono', 'cliente_nombre', 'cliente_correo'].forEach(function (nombre) {
            const campo = formulario.elements.namedItem(nombre);

            if (campo) {
                datos[nombre] = campo.value;
            }
        });

        return datos;
    }

    function guardar() {
        if (estado.enviando) {
            return;
        }

        guardarBorrador(claveBorrador, {
            lineas: estado.lineas,
            cliente: estado.cliente,
            opciones: estado.opciones,
            venta: camposVenta(),
            paso: estado.paso,
        });
    }

    formulario.addEventListener('input', function (evento) {
        if (/^cliente_/.test(evento.target.name || '')) {
            guardar();
        }
    });

    // -- Salir y enviar --

    window.addEventListener('beforeunload', function (evento) {
        if (estado.lineas.length && !estado.enviando) {
            evento.preventDefault();
            evento.returnValue = '';
        }
    });

    document.querySelectorAll('[data-salir-captura]').forEach(function (enlace) {
        enlace.addEventListener('click', function (evento) {
            if (estado.lineas.length && !window.confirm('¿Salir? Se pierde lo capturado.')) {
                evento.preventDefault();
                return;
            }

            estado.enviando = true;
            borrarBorradores();
        });
    });

    // Ir al alta de cliente no es salir: el borrador lo trae de vuelta.
    document.querySelectorAll('[data-salir-a-alta]').forEach(function (enlace) {
        enlace.addEventListener('click', function () {
            guardar();
            estado.enviando = true;
        });
    });

    formulario.addEventListener('submit', function (evento) {
        if (evento.defaultPrevented) {
            return;
        }

        if (!estado.lineas.length) {
            evento.preventDefault();
            return;
        }

        const contenedor = formulario.querySelector('[data-campos-lineas]');

        contenedor.textContent = '';
        camposFormulario(estado.lineas).forEach(function (par) {
            const campo = document.createElement('input');

            campo.type = 'hidden';
            campo.name = par[0];
            campo.value = par[1];
            contenedor.appendChild(campo);
        });

        guardar();
        estado.enviando = true;
    });

    // -- Arranque --

    function restaurar() {
        const anterior = leerJson(formulario.dataset.anterior);
        const clienteInicial = leerJson(formulario.dataset.clienteInicial);
        const borrador = leerBorrador(claveBorrador);

        // Tras un error de validación manda lo enviado y se abre el carrito.
        if (anterior) {
            estado.lineas = lineasDeAnterior(anterior.lineas);
            ['uso_cfdi', 'forma_pago', 'metodo_pago'].forEach(function (campo, i) {
                if (anterior[campo]) {
                    elegirOpcion(['uso', 'forma', 'metodo'][i], anterior[campo]);
                }
            });

            if (clienteInicial) {
                elegirCliente(clienteInicial);
            }

            pintarCarrito();
            pintarErroresGenerales();

            return 'carrito';
        }

        if (borrador) {
            estado.lineas = borrador.lineas || [];

            Object.keys(borrador.opciones || {}).forEach(function (nombre) {
                if (borrador.opciones[nombre]) {
                    elegirOpcion(nombre, borrador.opciones[nombre]);
                }
            });

            Object.keys(borrador.venta || {}).forEach(function (nombre) {
                const campo = formulario.elements.namedItem(nombre);

                if (campo && campo.value === '') {
                    campo.value = borrador.venta[nombre];
                }
            });
        }

        // Al regresar del alta de cliente, ese cliente queda elegido.
        if (clienteInicial) {
            elegirCliente(clienteInicial);
            pintarCarrito();

            return 'articulos';
        }

        if (borrador && borrador.cliente) {
            elegirCliente(borrador.cliente);
        }

        pintarCarrito();

        return borrador && borrador.paso ? borrador.paso : (location.hash.slice(1) || pasos[0]);
    }

    mostrarPaso(restaurar(), false);
})(typeof window !== 'undefined' ? window : null);
