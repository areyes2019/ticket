// Tandas de la carga masiva de imágenes. Se corre con: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const CargaImagenes = require(path.join(__dirname, '../../public/js/carga-imagenes.js'));

const MB = 1024 * 1024;

function archivos(tamanos) {
    return tamanos.map(function (size, indice) {
        return { name: indice + '.jpg', size: size };
    });
}

function cantidades(tandas) {
    return tandas.map(function (tanda) {
        return tanda.length;
    });
}

test('corta cada 20 archivos', function () {
    const tandas = CargaImagenes.partirEnTandas(archivos(new Array(45).fill(MB)), 20, 40 * MB);

    assert.deepEqual(cantidades(tandas), [20, 20, 5]);
});

test('corta antes de pasar de 40 MB', function () {
    const tandas = CargaImagenes.partirEnTandas(archivos([15 * MB, 15 * MB, 15 * MB, 5 * MB]), 20, 40 * MB);

    assert.deepEqual(cantidades(tandas), [2, 2]);
});

test('un archivo más grande que el tope va solo en su tanda', function () {
    const tandas = CargaImagenes.partirEnTandas(archivos([MB, 50 * MB, MB]), 20, 40 * MB);

    assert.deepEqual(cantidades(tandas), [1, 1, 1]);
});

test('sin archivos no hay tandas', function () {
    assert.deepEqual(CargaImagenes.partirEnTandas([], 20, 40 * MB), []);
});
