// Puerta del botón de compartir: si el navegador no puede compartir archivos, el
// botón de la factura no se muestra. Se corre con: node --test "tests/js/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { puedeCompartirArchivos } = require(path.join(__dirname, '../../public/js/compartir-pdf.js'));

test('sin menú de compartir no se puede', function () {
    assert.equal(puedeCompartirArchivos(undefined), false);
    assert.equal(puedeCompartirArchivos({}), false);
    assert.equal(puedeCompartirArchivos({ share: function () {} }), false);
});

test('con menú que no acepta archivos no se puede', function () {
    assert.equal(puedeCompartirArchivos({ share: function () {}, canShare: function () { return false; } }), false);
});

test('si canShare falla no se puede', function () {
    const navegador = {
        share: function () {},
        canShare: function () {
            throw new TypeError('no soportado');
        },
    };

    assert.equal(puedeCompartirArchivos(navegador), false);
});

test('con menú que acepta un PDF se puede', function () {
    let recibido = null;
    const navegador = {
        share: function () {},
        canShare: function (datos) {
            recibido = datos;

            return true;
        },
    };

    assert.equal(puedeCompartirArchivos(navegador), true);
    assert.equal(recibido.files.length, 1);
    assert.equal(recibido.files[0].type, 'application/pdf');
    assert.equal(recibido.text, undefined);
});
