// Cadena de precios de un artículo en el navegador: resumen en vivo del
// formulario, aviso de porcentaje de utilidad alto y el modo de precio fijo
// (037).
//
// Las funciones puras son espejo exacto de CalculadoraPrecioArticulo (PHP);
// las dos recorren tests/Fixtures/precios-articulos.json. Todo es informativo:
// el precio que cuenta lo calcula y lo guarda el servidor.
(function (raiz) {
    // Redondea a 6 decimales para quitar el ruido de punto flotante.
    function sinRuido(valor) {
        return Number(valor.toFixed(6));
    }

    function redondeo2(valor) {
        return Math.round(sinRuido(valor * 100)) / 100;
    }

    function techo2(valor) {
        return Math.ceil(sinRuido(valor * 100)) / 100;
    }

    function costoConDescuento(precioProveedor, descuento) {
        return redondeo2(precioProveedor * (1 - descuento / 100));
    }

    function precioVentaSinIva(costo, utilidadPorcentaje) {
        return techo2(costo * (1 + utilidadPorcentaje / 100));
    }

    // Espejo de CalculadoraPrecioArticulo::redondearAPesoEntero().
    function redondearAPesoEntero(precioCrudoSinIva, factorIva) {
        if (precioCrudoSinIva <= 0) {
            return 0;
        }

        let objetivo = Math.ceil(sinRuido(precioCrudoSinIva * factorIva));

        for (;;) {
            const centavos = Math.floor(sinRuido(objetivo * 100 / factorIva));

            for (const candidato of [centavos, centavos + 1]) {
                if (redondeo2(candidato / 100 * factorIva) === objetivo) {
                    return candidato / 100;
                }
            }

            objetivo++;
        }
    }

    // Solo el objeto de impuesto 02 lleva IVA encima; tasaIva viene del servidor.
    function factorIva(objetoImp, tasaIva) {
        return objetoImp === '02' ? 1 + tasaIva : 1;
    }

    // Espejo de CalculadoraPrecioArticulo::precioFijoSinIva().
    function precioFijoSinIva(precioFijo, factorIva) {
        const objetivo = Math.round(sinRuido(precioFijo * 100));
        const centavos = Math.floor(sinRuido(objetivo / factorIva));

        for (const candidato of [centavos, centavos + 1]) {
            if (Math.round(redondeo2(candidato / 100 * factorIva) * 100) >= objetivo) {
                return candidato / 100;
            }
        }

        return (centavos + 2) / 100;
    }

    // Espejo de CalculadoraPrecioArticulo::utilidadPorcentajeEquivalente(); null sin costo.
    function utilidadPorcentajeEquivalente(precioVentaSinIva, costo) {
        if (costo <= 0) {
            return null;
        }

        const porcentaje = (precioVentaSinIva / costo - 1) * 100;
        const redondeado = redondeo2(Math.abs(porcentaje));

        return porcentaje < 0 && redondeado > 0 ? -redondeado : redondeado;
    }

    function utilidad(precioVentaSinIva, costo) {
        return redondeo2(precioVentaSinIva - costo);
    }

    function precioConIva(precioSinIva, tasaIva) {
        return redondeo2(precioSinIva * (1 + tasaIva));
    }

    // Un valor no numérico (campo a medio escribir) no avisa.
    function porcentajeAlto(valor, umbral) {
        const numero = parseFloat(valor);

        return Number.isFinite(numero) && numero > umbral;
    }

    const PrecioArticulo = {
        redondeo2: redondeo2,
        techo2: techo2,
        costoConDescuento: costoConDescuento,
        precioVentaSinIva: precioVentaSinIva,
        utilidad: utilidad,
        precioConIva: precioConIva,
        redondearAPesoEntero: redondearAPesoEntero,
        precioFijoSinIva: precioFijoSinIva,
        utilidadPorcentajeEquivalente: utilidadPorcentajeEquivalente,
        factorIva: factorIva,
        porcentajeAlto: porcentajeAlto,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = PrecioArticulo;
    }

    if (typeof document === 'undefined') {
        return;
    }

    raiz.PrecioArticulo = PrecioArticulo;

    const formato = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

    function porcentajeTexto(valor) {
        return String(Number(valor.toFixed(2))) + '%';
    }

    // Resumen de la cadena: <dl data-resumen-precio> con un <output data-valor="…">
    // por renglón y un <span data-porcentaje="…"> en las etiquetas con porcentaje.
    // Cada renglón es un <div data-renglon="…">: los de IVA se ocultan si el
    // objeto de impuesto no es 02, y el de redondeo cuando no hubo ajuste. Un
    // renglón que el resumen no trae se salta (el del distribuidor no repite
    // lista ni descuento). data-herencia dice qué utilidad del catálogo se
    // hereda con el campo vacío: "utilidad" o "utilidad_distribuidor".
    //
    // Con data-fijo (037), el radio data-modo en "fijo" cambia la utilidad por el
    // precio escrito en ese campo: el renglón de utilidad muestra lo que deja ese
    // precio y su porcentaje equivalente, y no hay redondeo. También maneja los
    // avisos data-aviso-costo y data-aviso-centavo y la etiqueta del campo.
    document.querySelectorAll('dl[data-resumen-precio]').forEach(function (resumen) {
        const precio = document.getElementById(resumen.dataset.precio);
        const utilidadCampo = document.getElementById(resumen.dataset.utilidad);
        const catalogo = document.getElementById(resumen.dataset.catalogo);
        const objeto = document.getElementById(resumen.dataset.objeto);
        const tasaIva = parseFloat(resumen.dataset.tasaIva);
        const herencia = resumen.dataset.herencia || 'utilidad';
        const fijoCampo = resumen.dataset.fijo ? document.getElementById(resumen.dataset.fijo) : null;
        const radiosModo = resumen.dataset.modo ? document.querySelectorAll('input[name="' + resumen.dataset.modo + '"]') : [];
        const avisoCosto = resumen.dataset.avisoCosto ? document.getElementById(resumen.dataset.avisoCosto) : null;
        const avisoCentavo = resumen.dataset.avisoCentavo ? document.getElementById(resumen.dataset.avisoCentavo) : null;
        const etiquetaFijo = fijoCampo ? document.querySelector('label[for="' + fijoCampo.id + '"]') : null;
        let catalogos;

        try {
            catalogos = JSON.parse(resumen.dataset.catalogos);
        } catch (error) {
            return;
        }

        if (!precio || !utilidadCampo || !catalogo || Number.isNaN(tasaIva)) {
            return;
        }

        function mostrar(nombre, texto) {
            const destino = resumen.querySelector('[data-valor="' + nombre + '"]');

            if (destino) {
                destino.value = texto;
            }
        }

        function ocultar(nombre, oculto) {
            const renglon = resumen.querySelector('[data-renglon="' + nombre + '"]');

            if (renglon) {
                renglon.hidden = oculto;
            }
        }

        function mostrarPorcentaje(nombre, texto) {
            const destino = resumen.querySelector('[data-porcentaje="' + nombre + '"]');

            if (destino) {
                destino.textContent = texto;
            }
        }

        function enModoFijo() {
            return fijoCampo !== null && Array.prototype.some.call(radiosModo, function (radio) {
                return radio.checked && radio.value === 'fijo';
            });
        }

        function texto(destino, selector, valor) {
            const nodo = destino.querySelector(selector);

            if (nodo) {
                nodo.textContent = valor;
            }
        }

        function actualizar() {
            const datos = catalogos[catalogo.value];
            const fijo = enModoFijo();
            const precioFijo = fijo ? parseFloat(fijoCampo.value) : NaN;
            const lista = parseFloat(precio.value);

            const heredada = datos ? Number(datos[herencia]) : NaN;

            utilidadCampo.placeholder = Number.isFinite(heredada) ? 'Hereda ' + porcentajeTexto(heredada) + ' del catálogo' : '';

            const propia = parseFloat(utilidadCampo.value);
            const porcentaje = utilidadCampo.value.trim() !== '' && Number.isFinite(propia) ? propia : heredada;

            // Sin objeto de impuesto elegido se supone 02, el caso de casi todos.
            const factor = factorIva(objeto && objeto.value !== '' ? objeto.value : '02', tasaIva);
            const sufijo = resumen.querySelector('[data-sufijo-iva]');

            ocultar('iva', factor === 1);
            ocultar('venta-con-iva', factor === 1);

            if (etiquetaFijo) {
                etiquetaFijo.textContent = factor === 1 ? 'Precio fijo' : 'Precio fijo con IVA';
            }

            if (avisoCosto) {
                avisoCosto.hidden = true;
            }

            if (avisoCentavo) {
                avisoCentavo.hidden = true;
            }

            if (sufijo) {
                sufijo.hidden = factor === 1;
            }

            mostrarPorcentaje('descuento', datos ? porcentajeTexto(datos.descuento) : '—');
            mostrarPorcentaje('utilidad', !fijo && Number.isFinite(porcentaje) ? porcentajeTexto(porcentaje) : '—');

            const sinPrecio = fijo ? !(Number.isFinite(precioFijo) && precioFijo > 0) : !Number.isFinite(porcentaje);

            if (!datos || !Number.isFinite(lista) || lista <= 0 || sinPrecio) {
                ['lista', 'descuento', 'costo', 'utilidad', 'venta', 'iva', 'venta-con-iva', 'redondeo', 'final'].forEach(function (nombre) {
                    mostrar(nombre, '—');
                });
                ocultar('redondeo', true);

                return;
            }

            const costo = costoConDescuento(lista, datos.descuento);
            const venta = fijo ? precioFijoSinIva(precioFijo, factor) : precioVentaSinIva(costo, porcentaje);
            const conIva = precioConIva(venta, factor === 1 ? 0 : tasaIva);
            const final = fijo ? conIva : redondeo2(redondearAPesoEntero(venta, factor) * factor);
            const redondeo = redondeo2(final - conIva);
            const ganancia = utilidad(venta, costo);

            if (fijo) {
                const equivalente = utilidadPorcentajeEquivalente(venta, costo);

                mostrarPorcentaje('utilidad', equivalente === null ? '—' : porcentajeTexto(equivalente));

                if (avisoCosto && venta < costo) {
                    texto(avisoCosto, '[data-monto]', formato.format(redondeo2(costo - venta)));
                    avisoCosto.hidden = false;
                }

                if (avisoCentavo && final !== redondeo2(precioFijo)) {
                    texto(avisoCentavo, '[data-capturado]', formato.format(precioFijo));
                    texto(avisoCentavo, '[data-final]', formato.format(final));
                    avisoCentavo.hidden = false;
                }
            }

            mostrar('lista', formato.format(lista));
            mostrar('descuento', '−' + formato.format(redondeo2(lista - costo)));
            mostrar('costo', formato.format(costo));
            mostrar('utilidad', (ganancia < 0 ? '−' : '+') + formato.format(Math.abs(ganancia)));
            mostrar('venta', formato.format(venta));
            mostrar('iva', '+' + formato.format(redondeo2(conIva - venta)));
            mostrar('venta-con-iva', formato.format(conIva));
            mostrar('redondeo', '+' + formato.format(redondeo));
            mostrar('final', formato.format(final));
            ocultar('redondeo', redondeo <= 0);
        }

        precio.addEventListener('input', actualizar);
        utilidadCampo.addEventListener('input', actualizar);

        if (fijoCampo) {
            fijoCampo.addEventListener('input', actualizar);
        }

        radiosModo.forEach(function (radio) {
            radio.addEventListener('change', actualizar);
        });
        catalogo.addEventListener('change', actualizar);

        if (objeto) {
            objeto.addEventListener('change', actualizar);
        }

        actualizar();
    });

    // Modo del precio de venta (037): muestra solo el bloque [data-bloque-modo] del
    // radio elegido. Los campos ocultos se siguen enviando.
    const radiosModoPrecio = document.querySelectorAll('input[name="modo_precio"]');

    if (radiosModoPrecio.length > 0) {
        const bloques = document.querySelectorAll('[data-bloque-modo]');

        const aplicarModo = function () {
            const elegido = Array.prototype.find.call(radiosModoPrecio, function (radio) {
                return radio.checked;
            });

            bloques.forEach(function (bloque) {
                bloque.hidden = !elegido || bloque.dataset.bloqueModo !== elegido.value;
            });
        };

        radiosModoPrecio.forEach(function (radio) {
            radio.addEventListener('change', aplicarModo);
        });
        aplicarModo();
    }

    // Aviso no bloqueante: <input data-aviso-utilidad="id-del-aviso" data-umbral="400">.
    document.querySelectorAll('input[data-aviso-utilidad]').forEach(function (campo) {
        const aviso = document.getElementById(campo.dataset.avisoUtilidad);
        const umbral = parseFloat(campo.dataset.umbral);
        const factor = aviso ? aviso.querySelector('[data-factor]') : null;

        if (!aviso || Number.isNaN(umbral)) {
            return;
        }

        function actualizar() {
            const alto = porcentajeAlto(campo.value, umbral);

            aviso.hidden = !alto;

            if (alto && factor) {
                factor.textContent = String(Number((1 + parseFloat(campo.value) / 100).toFixed(4)));
            }
        }

        campo.addEventListener('input', actualizar);
        actualizar();
    });
})(typeof window !== 'undefined' ? window : this);
