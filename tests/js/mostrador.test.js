// Carrito de la captura del mostrador (033). Se corre con: node --test "tests/js/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const Carrito = require(path.join(__dirname, '../../public/js/mostrador.js'));
const TotalesDocumento = require(path.join(__dirname, '../../public/js/totales-documento.js'));
const casos = require(path.join(__dirname, '../Fixtures/totales-documentos.json'));

const sello = { id: 7, nombre: 'Sello automático', modelo: 'P-20', precio_unitario: '200.00', precio_distribuidor: '150.00', tasa_iva: '16' };
const fechador = { id: 9, nombre: 'Fechador', modelo: 'F-1', precio_unitario: '80.00', tasa_iva: '16' };
const distribuidor = { id: 1, razon_social: 'DIST SA', rfc: 'DIS010101AAA', descuento: '', distribuidor: true };
const conDescuento = { id: 2, razon_social: 'DESC SA', rfc: 'DES010101AAA', descuento: '10', distribuidor: false };

test('un toque agrega una unidad y el siguiente suma otra en el mismo renglón', function () {
    const reglas = Carrito.reglasDe('venta');
    let lineas = Carrito.agregarArticulo([], sello, null, reglas);

    lineas = Carrito.agregarArticulo(lineas, sello, null, reglas);
    lineas = Carrito.agregarArticulo(lineas, fechador, null, reglas);

    assert.equal(lineas.length, 2);
    assert.equal(lineas[0].cantidad, 2);
    assert.equal(lineas[0].precio_unitario, '200.00');
    assert.equal(Carrito.piezas(lineas), 3);
});

test('"−" hasta cero quita el renglón; quitar lo saca directo', function () {
    const reglas = Carrito.reglasDe('venta');
    let lineas = Carrito.agregarArticulo(Carrito.agregarArticulo([], sello, null, reglas), fechador, null, reglas);

    lineas = Carrito.cambiarCantidad(lineas, 0, -1);
    assert.deepEqual(lineas.map((linea) => linea.articulo_id), [9]);

    assert.deepEqual(Carrito.quitar(lineas, 0), []);
});

test('la línea libre de la venta no lleva artículo', function () {
    const lineas = Carrito.agregarLibre([], { descripcion: 'Diseño especial', precio_unitario: '50.00', tasa_iva: '16' });

    assert.equal(lineas[0].articulo_id, null);
    assert.equal(lineas[0].cantidad, 1);
});

test('el precio distribuidor aplica en cotización y factura, no en la venta', function () {
    assert.equal(Carrito.agregarArticulo([], sello, distribuidor, Carrito.reglasDe('cotizacion'))[0].precio_unitario, '150.00');
    assert.equal(Carrito.agregarArticulo([], sello, distribuidor, Carrito.reglasDe('factura'))[0].precio_unitario, '150.00');
    assert.equal(Carrito.agregarArticulo([], sello, distribuidor, Carrito.reglasDe('venta'))[0].precio_unitario, '200.00');
});

test('el descuento permanente solo aplica en la cotización', function () {
    const cotizacion = Carrito.agregarArticulo([], sello, conDescuento, Carrito.reglasDe('cotizacion'))[0];
    const factura = Carrito.agregarArticulo([], sello, conDescuento, Carrito.reglasDe('factura'))[0];

    assert.equal(cotizacion.descuento_tipo, 'porcentaje');
    assert.equal(cotizacion.descuento_valor, '10');
    assert.equal(factura.descuento_tipo, null);
});

test('cambiar de cliente reemplaza precio y descuento de las líneas de artículo, no de las libres', function () {
    const reglas = Carrito.reglasDe('cotizacion');
    let lineas = Carrito.agregarArticulo([], sello, conDescuento, reglas);

    lineas = Carrito.agregarLibre(lineas, { descripcion: 'Flete', precio_unitario: '30.00', tasa_iva: '16' });
    lineas = Carrito.aplicarCliente(lineas, distribuidor, reglas);

    assert.equal(lineas[0].precio_unitario, '150.00');
    assert.equal(lineas[0].descuento_tipo, null);
    assert.equal(lineas[1].precio_unitario, '30.00');

    lineas = Carrito.aplicarCliente(lineas, conDescuento, reglas);
    assert.equal(lineas[0].precio_unitario, '200.00');
    assert.equal(lineas[0].descuento_valor, '10');
});

test('arma los campos lineas[i][...] en el orden del carrito', function () {
    const lineas = Carrito.agregarArticulo(Carrito.agregarArticulo([], sello, null, Carrito.reglasDe('venta')), fechador, null, Carrito.reglasDe('venta'));
    const campos = Carrito.camposFormulario(lineas);

    assert.deepEqual(campos.slice(0, 8), [
        ['lineas[0][articulo_id]', '7'],
        ['lineas[0][cantidad]', '1'],
        ['lineas[0][descripcion]', 'Sello automático'],
        ['lineas[0][modelo]', 'P-20'],
        ['lineas[0][precio_unitario]', '200.00'],
        ['lineas[0][descuento_tipo]', ''],
        ['lineas[0][descuento_valor]', ''],
        ['lineas[0][tasa_iva]', '16'],
    ]);
    assert.equal(campos[8][0], 'lineas[1][articulo_id]');
});

test('reconstruye el carrito con lo que regresa del servidor tras un error', function () {
    const lineas = Carrito.lineasDeAnterior([
        { articulo_id: '7', cantidad: '3', descripcion: 'Sello', modelo: 'P-20', precio_unitario: '200.00', tasa_iva: '16', precio_directo: '200.00', precio_distribuidor: '150.00' },
        { articulo_id: '', descripcion: '', precio_unitario: '' },
    ]);

    assert.equal(lineas.length, 1);
    assert.equal(lineas[0].articulo_id, 7);
    assert.equal(lineas[0].cantidad, 3);
    assert.equal(lineas[0].precio_distribuidor, '150.00');
});

// Mismos números que el escritorio: el total del carrito es el de TotalesDocumento.
for (const caso of casos.filter((c) => !c.descuento_global_tipo)) {
    test('total del carrito igual al del escritorio: ' + caso.caso, function () {
        assert.deepEqual(Carrito.totales(caso.lineas, TotalesDocumento), caso.esperado);
    });
}
