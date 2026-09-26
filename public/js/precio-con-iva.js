// Muestra el precio con IVA mientras se escribe el precio sin IVA.
//
// Es solo informativo: el precio que cuenta lo calcula el servidor. La tasa
// llega en data-tasa-iva desde Articulo::TASA_IVA; aquí no se escribe.
(function () {
    document.querySelectorAll('output[data-precio-con-iva]').forEach(function (salida) {
        const campo = document.getElementById(salida.htmlFor.value);
        const tasa = parseFloat(salida.dataset.tasaIva);
        const formato = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

        if (!campo || Number.isNaN(tasa)) {
            return;
        }

        function actualizar() {
            const precio = parseFloat(campo.value);

            salida.value = Number.isFinite(precio) && precio > 0
                ? formato.format(Math.round(precio * (1 + tasa) * 100) / 100)
                : '—';
        }

        campo.addEventListener('input', actualizar);
        actualizar();
    });
})();
