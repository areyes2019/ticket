// Recorre el fixture compartido contra el espejo en JavaScript de
// CalculadoraPrecioArticulo. Se corre con: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const PrecioArticulo = require(path.join(__dirname, '../../public/js/precio-articulo.js'));
const casos = require(path.join(__dirname, '../Fixtures/precios-articulos.json'));

const TASA_IVA = 0.16;

for (const caso of casos) {
    test(caso.descripcion, function () {
        const factor = PrecioArticulo.factorIva(caso.objeto_imp, TASA_IVA);
        const costo = PrecioArticulo.costoConDescuento(parseFloat(caso.precio_proveedor), parseFloat(caso.descuento));
        const crudo = PrecioArticulo.precioVentaSinIva(costo, parseFloat(caso.utilidad_porcentaje));
        // 037: con precio fijo, el precio de venta sale de ese número y no del markup.
        const venta = caso.precio_fijo !== undefined
            ? PrecioArticulo.precioFijoSinIva(parseFloat(caso.precio_fijo), factor)
            : PrecioArticulo.redondearAPesoEntero(crudo, factor);

        assert.equal(costo.toFixed(2), caso.costo);
        assert.equal(crudo.toFixed(2), caso.precio_venta_crudo);
        assert.equal(venta.toFixed(2), caso.precio_venta);
        assert.equal(PrecioArticulo.precioConIva(venta, factor === 1 ? 0 : TASA_IVA).toFixed(2), caso.precio_con_iva);
        assert.equal(PrecioArticulo.utilidad(venta, costo).toFixed(2), caso.utilidad);

        if (caso.utilidad_porcentaje_equivalente !== undefined) {
            const equivalente = PrecioArticulo.utilidadPorcentajeEquivalente(venta, costo);

            assert.equal(equivalente === null ? null : equivalente.toFixed(2), caso.utilidad_porcentaje_equivalente);
        }

        // 028: el precio distribuidor parte del mismo costo con su propia utilidad.
        if (caso.utilidad_distribuidor_porcentaje !== undefined) {
            const distribuidor = PrecioArticulo.redondearAPesoEntero(PrecioArticulo.precioVentaSinIva(costo, parseFloat(caso.utilidad_distribuidor_porcentaje)), factor);

            assert.equal(distribuidor.toFixed(2), caso.precio_distribuidor);
            assert.equal(PrecioArticulo.precioConIva(distribuidor, factor === 1 ? 0 : TASA_IVA).toFixed(2), caso.precio_distribuidor_con_iva);
        }
    });
}

test('aplica IVA solo al objeto de impuesto 02', function () {
    assert.equal(PrecioArticulo.factorIva('02', TASA_IVA), 1.16);
    assert.equal(PrecioArticulo.factorIva('01', TASA_IVA), 1);
    assert.equal(PrecioArticulo.factorIva('03', TASA_IVA), 1);
    assert.equal(PrecioArticulo.factorIva('04', TASA_IVA), 1);
});

// Barrido de $0.01 a $2,000.00: encuentra los pesos inalcanzables con 1.16.
for (const factor of [1.16, 1]) {
    test('barrido con factor ' + factor + ': peso entero, nunca baja, ajuste menor a $2', function () {
        const fallos = [];

        for (let centavos = 1; centavos <= 200000; centavos++) {
            const crudo = centavos / 100;
            const final = PrecioArticulo.redondearAPesoEntero(crudo, factor);
            const conIva = PrecioArticulo.redondeo2(final * factor);
            const ajuste = conIva - PrecioArticulo.redondeo2(crudo * factor);

            if (!Number.isInteger(conIva) || Math.round(final * 100) < centavos || ajuste >= 2) {
                fallos.push(crudo);
            }
        }

        assert.deepEqual(fallos, []);
    });
}

// 037: barrido de precios fijos de $0.01 a $2,000.00, igual que en PHP.
for (const factor of [1.16, 1]) {
    test('barrido de precio fijo con factor ' + factor + ': nunca baja ni sube más de un centavo', function () {
        const fallos = [];

        for (let centavos = 1; centavos <= 200000; centavos++) {
            const sinIva = PrecioArticulo.precioFijoSinIva(centavos / 100, factor);
            const conIva = Math.round(PrecioArticulo.redondeo2(sinIva * factor) * 100);
            const anterior = Math.round(PrecioArticulo.redondeo2((sinIva - 0.01) * factor) * 100);

            if (conIva < centavos || conIva > centavos + 1 || anterior >= centavos) {
                fallos.push(centavos / 100);
            }
        }

        assert.deepEqual(fallos, []);
    });
}

test('porcentajeAlto no avisa con valores a medio escribir', function () {
    assert.equal(PrecioArticulo.porcentajeAlto('', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('abc', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('400', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('400.01', 400), true);
});
