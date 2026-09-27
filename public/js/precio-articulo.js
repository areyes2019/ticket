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
    document.querySelectorAll('dl[data-resumen-precio]').forEach(function (resumen) {
        const precio = document.getElementById(resumen.dataset.precio);
        const utilidadCampo = document.getElementById(resumen.dataset.utilidad);
        const catalogo = document.getElementById(resumen.dataset.catalogo);
        const tasaIva = parseFloat(resumen.dataset.tasaIva);
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

        function mostrarPorcentaje(nombre, texto) {
            const destino = resumen.querySelector('[data-porcentaje="' + nombre + '"]');

            if (destino) {
                destino.textContent = texto;
            }
        }

        function actualizar() {
            const datos = catalogos[catalogo.value];
            const lista = parseFloat(precio.value);

            utilidadCampo.placeholder = datos ? 'Hereda ' + porcentajeTexto(datos.utilidad) + ' del catálogo' : '';

            const propia = parseFloat(utilidadCampo.value);
            const porcentaje = utilidadCampo.value.trim() !== '' && Number.isFinite(propia) ? propia : (datos ? datos.utilidad : NaN);

            mostrarPorcentaje('descuento', datos ? porcentajeTexto(datos.descuento) : '—');
            mostrarPorcentaje('utilidad', Number.isFinite(porcentaje) ? porcentajeTexto(porcentaje) : '—');

            if (!datos || !Number.isFinite(lista) || lista <= 0 || !Number.isFinite(porcentaje)) {
                ['lista', 'descuento', 'costo', 'utilidad', 'venta', 'iva', 'venta-con-iva'].forEach(function (nombre) {
                    mostrar(nombre, '—');
                });

                return;
            }

            const costo = costoConDescuento(lista, datos.descuento);
            const venta = precioVentaSinIva(costo, porcentaje);
            const conIva = precioConIva(venta, tasaIva);

            mostrar('lista', formato.format(lista));
            mostrar('descuento', '−' + formato.format(redondeo2(lista - costo)));
            mostrar('costo', formato.format(costo));
            mostrar('utilidad', '+' + formato.format(utilidad(venta, costo)));
            mostrar('venta', formato.format(venta));
            mostrar('iva', '+' + formato.format(redondeo2(conIva - venta)));
            mostrar('venta-con-iva', formato.format(conIva));
        }

        precio.addEventListener('input', actualizar);
        utilidadCampo.addEventListener('input', actualizar);
        catalogo.addEventListener('change', actualizar);
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
