// Formulario de la orden de trabajo (022).
//
// Cada línea tiene un select de color [data-color-tinta="{id del campo}"] y un
// campo "¿Qué color?" [data-color-otro="{id}"] que solo cuenta con "Otro". Aquí
// se oculta mientras no se elija "Otro"; sin JavaScript se ve siempre y el
// servidor valida igual.
(function () {
    document.querySelectorAll('[data-color-tinta]').forEach(function (select) {
        const campo = document.querySelector('[data-color-otro="' + select.dataset.colorTinta + '"]');

        if (!campo) {
            return;
        }

        const entrada = campo.querySelector('input');

        function actualizar() {
            const esOtro = select.value === 'otro';

            campo.hidden = !esOtro;
            entrada.required = esOtro;
        }

        select.addEventListener('change', function () {
            actualizar();

            if (select.value === 'otro') {
                entrada.focus();
            }
        });

        actualizar();
    });
})();
