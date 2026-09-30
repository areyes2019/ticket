// Ayudas de captura de facturas. Sin JavaScript todo sigue funcionando: la
// validación del servidor exige las mismas reglas.
(function () {
    // Con PPD la forma de pago es 99 (Por definir); al volver a PUE se limpia
    // para que el usuario elija la forma real.
    const metodo = document.querySelector('[data-metodo-pago]');
    const forma = document.querySelector('[data-forma-pago]');

    if (metodo && forma) {
        metodo.addEventListener('change', function () {
            if (metodo.value === 'PPD') {
                forma.value = '99';
            } else if (forma.value === '99') {
                forma.value = '';
            }
        });
    }

    // La factura sustituta solo se pide con el motivo 01.
    const motivo = document.querySelector('[data-motivo-cancelacion]');
    const sustituta = document.querySelector('[data-campo-sustituta]');

    if (motivo && sustituta) {
        const actualizar = function () {
            const requerida = motivo.value === '01';
            const campo = sustituta.querySelector('select');

            sustituta.hidden = !requerida;
            campo.required = requerida;

            if (!requerida) {
                campo.value = '';
            }
        };

        motivo.addEventListener('change', actualizar);
        actualizar();
    }
})();
