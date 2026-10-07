// Etiquetas de producción (030, 031). Se corre con: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { tamanoQueCabe, distribucion, centrar, escalaLetra } = require(path.join(__dirname, '../../public/js/etiquetas-produccion.js'));
const casos = require(path.join(__dirname, '../Fixtures/planillas-etiquetas.json'));

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

// Mismo fixture que tests/Unit/MedidasPlanillaTest.php.
for (const caso of casos) {
    test(caso.descripcion, function () {
        const medidas = {};

        for (const [campo, valor] of Object.entries(caso.medidas)) {
            medidas[campo] = Math.round(parseFloat(valor) * 10);
        }

        const dist = distribucion(medidas);
        const margenes = centrar(medidas);

        assert.equal(dist.columnas, caso.columnas);
        assert.equal(dist.renglones, caso.renglones);
        assert.equal(dist.porHoja, caso.por_hoja);
        assert.equal(escalaLetra(medidas).toFixed(4), caso.escala_letra);
        assert.equal((margenes.margen_superior / 10).toFixed(1), caso.centrado.margen_superior);
        assert.equal((margenes.margen_izquierdo / 10).toFixed(1), caso.centrado.margen_izquierdo);
    });
}
