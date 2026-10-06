// Ajuste de letra de las etiquetas de producción (030). Se corre con: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { tamanoQueCabe } = require(path.join(__dirname, '../../public/js/etiquetas-produccion.js'));

test('si el texto cabe, conserva el tamaño', function () {
    assert.equal(tamanoQueCabe(10, 80, 100, 7, 0.5), 10);
});

test('achica en proporción, en pasos de medio punto', function () {
    assert.equal(tamanoQueCabe(10, 120, 100, 7, 0.5), 8);
    assert.equal(tamanoQueCabe(14, 150, 100, 7, 0.5), 9);
});

test('no baja del mínimo', function () {
    assert.equal(tamanoQueCabe(10, 1000, 100, 7, 0.5), 7);
});
