// Muestra el precio con el descuento del catálogo elegido mientras se escribe
// el precio sin IVA o se cambia de catálogo.
//
// Es solo informativo: el precio que cuenta lo calcula el servidor. Los
// descuentos llegan en data-descuentos como { catalogo_id: descuento }.
(function () {
    document.querySelectorAll('output[data-precio-con-descuento]').forEach(function (salida) {
        const precio = document.getElementById(salida.dataset.precio);
        const catalogo = document.getElementById(salida.dataset.catalogo);
        const formato = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
        let descuentos;

        try {
            descuentos = JSON.parse(salida.dataset.descuentos);
        } catch (error) {
            return;
        }

        if (!precio || !catalogo) {
            return;
        }

        function actualizar() {
            const valor = parseFloat(precio.value);
            const descuento = parseFloat(descuentos[catalogo.value]);

            salida.value = Number.isFinite(valor) && valor > 0 && Number.isFinite(descuento)
                ? formato.format(Math.round(valor * (1 - descuento / 100) * 100) / 100)
                : '—';
        }

        precio.addEventListener('input', actualizar);
        catalogo.addEventListener('change', actualizar);
        actualizar();
    });
})();
