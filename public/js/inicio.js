(function () {
    // Interacción de interfaz sin framework.
    const contador = document.getElementById('contador');
    let clics = 0;

    document.getElementById('btn-contador').addEventListener('click', function () {
        clics++;
        contador.textContent = clics;
    });

    // GET: consulta JSON a Laravel.
    const resultadoEstado = document.getElementById('resultado-estado');

    document.getElementById('btn-estado').addEventListener('click', function () {
        resultadoEstado.textContent = 'Consultando…';

        axios.get('/estado')
            .then(response => {
                resultadoEstado.textContent = JSON.stringify(response.data, null, 2);
            })
            .catch(error => {
                resultadoEstado.textContent = 'Error: ' + error.message;
            });
    });

    // POST: envía datos con el token CSRF configurado en app.js.
    const formEco = document.getElementById('form-eco');
    const resultadoEco = document.getElementById('resultado-eco');

    formEco.addEventListener('submit', function (event) {
        event.preventDefault();

        axios.post('/eco', { mensaje: formEco.mensaje.value })
            .then(response => {
                resultadoEco.textContent = JSON.stringify(response.data, null, 2);
                formEco.reset();
            })
            .catch(error => {
                const errores = error.response?.data?.errors;
                resultadoEco.textContent = errores
                    ? Object.values(errores).flat().join('\n')
                    : 'Error: ' + error.message;
            });
    });
})();
