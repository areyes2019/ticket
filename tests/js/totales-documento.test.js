// Recorre el fixture compartido contra el espejo en JavaScript de
// CalculadoraTotalesDocumento. Se corre con: node --test "tests/js/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const TotalesDocumento = require(path.join(__dirname, '../../public/js/totales-documento.js'));
const casos = require(path.join(__dirname, '../Fixtures/totales-documentos.json'));

for (const caso of casos) {
    test(caso.caso, function () {
        const resultado = TotalesDocumento.calcular(caso.lineas, caso.descuento_global_tipo, caso.descuento_global_valor);

        assert.deepEqual(resultado, caso.esperado);
    });
}

test('campos a medio escribir no rompen el cálculo', function () {
    const resultado = TotalesDocumento.calcular([
        { cantidad: '', precio_unitario: '12.', descuento_tipo: 'porcentaje', descuento_valor: '', tasa_iva: '16' },
    ], 'monto', 'abc');

    assert.equal(resultado.total, '0.00');
});

test('convierte entre pesos y centavos', function () {
    assert.equal(TotalesDocumento.centavos('10.05'), 1005);
    assert.equal(TotalesDocumento.centavos(0.1 + 0.2), 30);
    assert.equal(TotalesDocumento.pesos(1005), '10.05');
    assert.equal(TotalesDocumento.pesos(-7), '-0.07');
});
