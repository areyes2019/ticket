// Totales de un documento con líneas, en el navegador: resumen en vivo del
// formulario. Espejo exacto de CalculadoraTotalesDocumento (PHP); los dos
// recorren tests/Fixtures/totales-documentos.json. Todo es informativo: los
// totales que cuentan los calcula y los guarda el servidor.
(function (raiz) {
    const FACTORES_IVA = { '16': 0.16, '0': 0, exento: 0 };

    function sumar(valores) {
        return valores.reduce(function (a, b) {
            return a + b;
        }, 0);
    }

    // Redondeo al entero, mitad hacia arriba; el paso a 6 decimales quita el
    // ruido de punto flotante.
    function redondear(valor) {
        return Math.round(Number(valor.toFixed(6)));
    }

    function centavos(pesos) {
        const numero = parseFloat(pesos);

        return Number.isFinite(numero) ? redondear(numero * 100) : 0;
    }

    function pesos(cantidadCentavos) {
        const signo = cantidadCentavos < 0 ? '-' : '';
        const absoluto = Math.abs(cantidadCentavos);

        return signo + Math.floor(absoluto / 100) + '.' + String(absoluto % 100).padStart(2, '0');
    }

    function descuento(base, tipo, valor) {
        if (!tipo || valor === null || valor === undefined || String(valor).trim() === '') {
            return 0;
        }

        const numero = parseFloat(valor);

        if (!Number.isFinite(numero)) {
            return 0;
        }

        const monto = tipo === 'porcentaje' ? redondear(base * numero / 100) : centavos(numero);

        return Math.max(0, Math.min(monto, base));
    }

    function prorratear(monto, netos) {
        const suma = sumar(netos);

        if (monto === 0 || suma === 0) {
            return netos.map(function () {
                return 0;
            });
        }

        const partes = netos.map(function (neto) {
            return redondear(monto * neto / suma);
        });
        const mayor = netos.indexOf(Math.max.apply(null, netos));

        partes[mayor] += monto - sumar(partes);

        return partes;
    }

    // lineas: [{ cantidad, precio_unitario, descuento_tipo, descuento_valor, tasa_iva }]
    function calcular(lineas, descuentoGlobalTipo, descuentoGlobalValor) {
        const calculadas = lineas.map(function (linea) {
            const cantidad = parseInt(linea.cantidad, 10);
            const bruto = (Number.isFinite(cantidad) ? cantidad : 0) * centavos(linea.precio_unitario);
            const descuentoLinea = descuento(bruto, linea.descuento_tipo, linea.descuento_valor);

            return {
                bruto: bruto,
                descuento: descuentoLinea,
                neto: bruto - descuentoLinea,
                tasa: String(linea.tasa_iva),
            };
        });

        const netos = calculadas.map(function (linea) {
            return linea.neto;
        });
        const descuentoGlobal = descuento(sumar(netos), descuentoGlobalTipo, descuentoGlobalValor);
        const partes = prorratear(descuentoGlobal, netos);
        const totales = { subtotal: 0, total_descuento: descuentoGlobal, base_iva_16: 0, total_iva_16: 0, base_iva_0: 0, base_exento: 0 };

        const resultado = calculadas.map(function (linea, i) {
            const importe = linea.neto - partes[i];
            const iva = redondear(importe * (FACTORES_IVA[linea.tasa] || 0));
            const base = linea.tasa === '16' ? 'base_iva_16' : (linea.tasa === '0' ? 'base_iva_0' : 'base_exento');

            totales.subtotal += linea.bruto;
            totales.total_descuento += linea.descuento;
            totales.total_iva_16 += iva;
            totales[base] += importe;

            return { bruto: pesos(linea.bruto), descuento: pesos(linea.descuento), importe: pesos(importe), iva_importe: pesos(iva) };
        });

        const salida = { lineas: resultado };

        Object.keys(totales).forEach(function (clave) {
            salida[clave] = pesos(totales[clave]);
        });
        salida.total = pesos(totales.subtotal - totales.total_descuento + totales.total_iva_16);

        return salida;
    }

    const TotalesDocumento = {
        calcular: calcular,
        centavos: centavos,
        pesos: pesos,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = TotalesDocumento;
    }

    if (raiz) {
        raiz.TotalesDocumento = TotalesDocumento;
    }
})(typeof window !== 'undefined' ? window : null);
