// Bandeja de WhatsApp (041): refresco cada 20 segundos de la lista y de la
// conversación abierta (regla 63), desplazamiento al último mensaje y vista
// previa de plantillas. Sin JavaScript la bandeja funciona recargando.
(function () {
    const REFRESCO_MS = 20000;
    const bandeja = document.querySelector('[data-whatsapp]');

    function alFinal(contenedor) {
        contenedor.scrollTop = contenedor.scrollHeight;
    }

    if (bandeja) {
        const lista = bandeja.querySelector('[data-whatsapp-lista]');
        const mensajes = bandeja.querySelector('[data-whatsapp-mensajes]');
        const encabezado = bandeja.querySelector('[data-whatsapp-encabezado]');

        if (mensajes) {
            alFinal(mensajes);
        }

        function refrescarLista() {
            return axios.get(bandeja.dataset.lista).then(function (respuesta) {
                lista.innerHTML = respuesta.data;
            });
        }

        function refrescarConversacion() {
            if (!mensajes || !bandeja.dataset.nuevos) {
                return Promise.resolve();
            }

            const pegado = mensajes.scrollHeight - mensajes.scrollTop - mensajes.clientHeight < 40;

            return axios.get(bandeja.dataset.nuevos, { params: { desde: mensajes.dataset.ultimo } }).then(function (respuesta) {
                const datos = respuesta.data;

                if (datos.html) {
                    mensajes.insertAdjacentHTML('beforeend', datos.html);
                    mensajes.dataset.ultimo = datos.ultimo;

                    if (pegado) {
                        alFinal(mensajes);
                    }
                }

                Object.keys(datos.estados || {}).forEach(function (id) {
                    const estado = mensajes.querySelector('[data-mensaje="' + id + '"] [data-estado-envio]');

                    if (estado && datos.estados[id]) {
                        estado.textContent = datos.estados[id];
                    }
                });

                if (encabezado && datos.encabezado) {
                    encabezado.innerHTML = datos.encabezado;
                }
            });
        }

        function refrescar() {
            // Con la pestaña oculta no se gasta: se refresca al volver.
            if (document.hidden) {
                return;
            }

            Promise.all([refrescarLista(), refrescarConversacion()]).catch(function () {
                // Sin conexión: el siguiente intento lo vuelve a pedir.
            });
        }

        setInterval(refrescar, REFRESCO_MS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refrescar();
            }
        });

        // Carpetas en tableta y celular, como las demás bandejas.
        bandeja.addEventListener('click', function (evento) {
            const boton = evento.target.closest('[data-mostrar-carpetas]');

            if (boton) {
                const abiertas = bandeja.classList.toggle('bandeja-carpetas-abiertas');
                boton.setAttribute('aria-expanded', abiertas ? 'true' : 'false');
            }
        });

        // Enter envía y Mayús+Enter hace salto de línea.
        const caja = bandeja.querySelector('#wa-texto');

        if (caja) {
            caja.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter' && !evento.shiftKey && caja.value.trim() !== '') {
                    evento.preventDefault();
                    caja.form.requestSubmit();
                }
            });
        }
    }

    // Plantillas: muestra los campos de sus variables y cómo se verá.
    const dialogo = document.querySelector('[data-dialogo-plantilla]');
    const elegir = dialogo && dialogo.querySelector('[data-plantilla-elegir]');

    if (elegir) {
        const vista = dialogo.querySelector('[data-plantilla-vista]');
        const campos = Array.from(dialogo.querySelectorAll('[data-plantilla-variable]'));
        const plantillas = JSON.parse(dialogo.querySelector('[data-plantillas]').textContent);

        function actualizar() {
            const plantilla = plantillas[elegir.value] || { cuerpo: '', variables: 0 };
            const total = plantilla.variables;

            campos.forEach(function (campo, i) {
                const entrada = campo.querySelector('input');
                const usada = i < total;

                campo.hidden = !usada;
                entrada.disabled = !usada;
                entrada.required = usada;
            });

            vista.textContent = plantilla.cuerpo.replace(/\{\{(\d+)\}\}/g, function (todo, n) {
                const entrada = campos[parseInt(n, 10) - 1] && campos[parseInt(n, 10) - 1].querySelector('input');

                return entrada && entrada.value ? entrada.value : todo;
            });
        }

        elegir.addEventListener('change', actualizar);
        dialogo.addEventListener('input', actualizar);
        actualizar();
    }
})();
