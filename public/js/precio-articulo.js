// Cadena de precios de un artículo en el navegador: resumen en vivo del
// formulario y aviso de porcentaje de utilidad alto.
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
    document.querySelectorAll('dl[data-resumen-precio]').forEach(function (resumen) {
        const precio = document.getElementById(resumen.dataset.precio);
        const utilidadCampo = document.getElementById(resumen.dataset.utilidad);
        const catalogo = document.getElementById(resumen.dataset.catalogo);
        const objeto = document.getElementById(resumen.dataset.objeto);
        const tasaIva = parseFloat(resumen.dataset.tasaIva);
        const herencia = resumen.dataset.herencia || 'utilidad';
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

        function actualizar() {
            const datos = catalogos[catalogo.value];
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

            if (sufijo) {
                sufijo.hidden = factor === 1;
            }

            mostrarPorcentaje('descuento', datos ? porcentajeTexto(datos.descuento) : '—');
            mostrarPorcentaje('utilidad', Number.isFinite(porcentaje) ? porcentajeTexto(porcentaje) : '—');

            if (!datos || !Number.isFinite(lista) || lista <= 0 || !Number.isFinite(porcentaje)) {
                ['lista', 'descuento', 'costo', 'utilidad', 'venta', 'iva', 'venta-con-iva', 'redondeo', 'final'].forEach(function (nombre) {
                    mostrar(nombre, '—');
                });
                ocultar('redondeo', true);

                return;
            }

            const costo = costoConDescuento(lista, datos.descuento);
            const venta = precioVentaSinIva(costo, porcentaje);
            const conIva = precioConIva(venta, factor === 1 ? 0 : tasaIva);
            const final = redondeo2(redondearAPesoEntero(venta, factor) * factor);
            const redondeo = redondeo2(final - conIva);

            mostrar('lista', formato.format(lista));
            mostrar('descuento', '−' + formato.format(redondeo2(lista - costo)));
            mostrar('costo', formato.format(costo));
            mostrar('utilidad', '+' + formato.format(utilidad(venta, costo)));
            mostrar('venta', formato.format(venta));
            mostrar('iva', '+' + formato.format(redondeo2(conIva - venta)));
            mostrar('venta-con-iva', formato.format(conIva));
            mostrar('redondeo', '+' + formato.format(redondeo));
            mostrar('final', formato.format(final));
            ocultar('redondeo', redondeo <= 0);
        }

        precio.addEventListener('input', actualizar);
        utilidadCampo.addEventListener('input', actualizar);
        catalogo.addEventListener('change', actualizar);

        if (objeto) {
            objeto.addEventListener('change', actualizar);
        }

        actualizar();
    });

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
