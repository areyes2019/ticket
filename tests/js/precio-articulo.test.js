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
        const venta = PrecioArticulo.redondearAPesoEntero(crudo, factor);

        assert.equal(costo.toFixed(2), caso.costo);
        assert.equal(crudo.toFixed(2), caso.precio_venta_crudo);
        assert.equal(venta.toFixed(2), caso.precio_venta);
        assert.equal(PrecioArticulo.precioConIva(venta, factor === 1 ? 0 : TASA_IVA).toFixed(2), caso.precio_con_iva);
        assert.equal(PrecioArticulo.utilidad(venta, costo).toFixed(2), caso.utilidad);
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

test('porcentajeAlto no avisa con valores a medio escribir', function () {
    assert.equal(PrecioArticulo.porcentajeAlto('', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('abc', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('400', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('400.01', 400), true);
});
