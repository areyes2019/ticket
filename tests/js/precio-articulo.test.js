// Recorre el fixture compartido contra el espejo en JavaScript de
// CalculadoraPrecioArticulo. Se corre con: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const PrecioArticulo = require(path.join(__dirname, '../../public/js/precio-articulo.js'));
const casos = require(path.join(__dirname, '../Fixtures/precios-articulos.json'));

for (const caso of casos) {
    test(caso.descripcion, function () {
        const costo = PrecioArticulo.costoConDescuento(parseFloat(caso.precio_proveedor), parseFloat(caso.descuento));
        const venta = PrecioArticulo.precioVentaSinIva(costo, parseFloat(caso.utilidad_porcentaje));

        assert.equal(costo.toFixed(2), caso.costo);
        assert.equal(venta.toFixed(2), caso.precio_venta);
        assert.equal(PrecioArticulo.utilidad(venta, costo).toFixed(2), caso.utilidad);
    });
}

test('porcentajeAlto no avisa con valores a medio escribir', function () {
    assert.equal(PrecioArticulo.porcentajeAlto('', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('abc', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('400', 400), false);
    assert.equal(PrecioArticulo.porcentajeAlto('400.01', 400), true);
});
