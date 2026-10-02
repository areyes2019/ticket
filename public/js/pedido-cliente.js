// Sugerencia del cliente de mostrador por teléfono (pedidos, 019).
//
// Al dejar de escribir el teléfono (o al salir del campo), si tiene 10 dígitos,
// pregunta al servidor por la venta más reciente con ese número y ofrece sus
// datos en [data-sugerencia-cliente]. Es una sugerencia: "Usar esos datos"
// solo rellena los campos vacíos y nunca pisa lo que el usuario escribió.
(function () {
    const contenedor = document.querySelector('[data-cliente-pedido]');
    const aviso = document.querySelector('[data-sugerencia-cliente]');

    if (!contenedor || !aviso) {
        return;
    }

    const telefono = contenedor.querySelector('[data-telefono-cliente]');
    const nombre = contenedor.querySelector('[name="cliente_nombre"]');
    const correo = contenedor.querySelector('[name="cliente_correo"]');
    const ESPERA_MS = 500;
    let temporizador = null;
    let consultado = '';
    let sugerencia = null;

    function digitos(valor) {
        return valor.replace(/\D/g, '').slice(-10);
    }

    function ocultar() {
        aviso.hidden = true;
        sugerencia = null;
    }

    function consultar() {
        const numero = digitos(telefono.value);

        if (numero.length !== 10) {
            consultado = '';
            ocultar();
            return;
        }

        if (numero === consultado) {
            return;
        }

        consultado = numero;

        axios.get(contenedor.dataset.sugerir, { params: { telefono: numero, excepto: contenedor.dataset.excepto || '' } })
            .then(function (respuesta) {
                const datos = respuesta.data || {};

                if (!datos.nombre || digitos(telefono.value) !== numero) {
                    ocultar();
                    return;
                }

                sugerencia = datos;
                aviso.querySelector('[data-sugerencia-nombre]').textContent = datos.nombre;
                aviso.querySelector('[data-sugerencia-correo]').textContent = datos.correo ? ' (' + datos.correo + ')' : '';
                aviso.hidden = false;
            })
            .catch(function (error) {
                // Es una comodidad: un error de red no estorba la captura.
                if (error.response && (error.response.status === 401 || error.response.status === 419)) {
                    window.location.reload();
                }
            });
    }

    telefono.addEventListener('input', function () {
        clearTimeout(temporizador);
        temporizador = setTimeout(consultar, ESPERA_MS);
    });

    telefono.addEventListener('blur', function () {
        clearTimeout(temporizador);
        consultar();
    });

    aviso.querySelector('[data-usar-sugerencia]').addEventListener('click', function () {
        if (!sugerencia) {
            return;
        }

        if (nombre.value.trim() === '') {
            nombre.value = sugerencia.nombre;
        }

        if (correo && correo.value.trim() === '' && sugerencia.correo) {
            correo.value = sugerencia.correo;
        }

        ocultar();
    });
})();
