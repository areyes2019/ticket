// Timbrado directo desde la vista previa de una cotización (spec 020).
//
// La ventana de confirmación (form[data-timbrar-cotizacion]) llega con la
// vista previa, a veces por AJAX, así que se escucha por delegación. Al
// confirmar se manda con Axios y, sin recargar:
//   - la factura nueva entra arriba en la lista de facturas, si la página la
//     tiene ([data-lista-facturas], dashboard),
//   - la fila de la cotización se reemplaza (ya dice "Facturada"),
//   - la vista previa se vuelve a pedir (evento documento:recargar de
//     bandeja-documentos.js),
//   - el aviso de la página ([data-timbrado-aviso-exito] o
//     [data-timbrado-aviso-error]) muestra el resultado.
// Los errores (datos fiscales, ya facturada, no facturable) se ven dentro de
// la ventana, que sigue abierta.
(function () {
    const MAX_FACTURAS = 25;
    const RESALTE_MS = 2500;

    function crear(html) {
        const plantilla = document.createElement('template');
        plantilla.innerHTML = html.trim();

        return plantilla.content.firstElementChild;
    }

    function mostrarError(formulario, mensajes) {
        const alerta = formulario.querySelector('[data-timbrar-error]');
        const contenido = alerta.querySelector('.alerta-contenido');

        contenido.innerHTML = '';
        mensajes.forEach(function (mensaje) {
            const parrafo = document.createElement('p');
            parrafo.textContent = mensaje;
            contenido.appendChild(parrafo);
        });
        alerta.hidden = false;
    }

    function avisar(tipo, mensaje) {
        document.querySelectorAll('[data-timbrado-aviso-exito], [data-timbrado-aviso-error]').forEach(function (alerta) {
            alerta.hidden = true;
        });

        const alerta = document.querySelector(tipo === 'exito' ? '[data-timbrado-aviso-exito]' : '[data-timbrado-aviso-error]');

        if (alerta) {
            alerta.querySelector('.alerta-contenido').textContent = mensaje;
            alerta.hidden = false;
        }
    }

    function agregarFactura(html) {
        const lista = document.querySelector('[data-lista-facturas]');

        if (!lista) {
            return;
        }

        // En el dashboard la lista vive en una sección del acordeón: se abre
        // para que se vea la factura nueva.
        const seccion = lista.closest('details');

        if (seccion) {
            seccion.open = true;
        }

        const fila = crear(html);
        lista.prepend(fila);
        fila.classList.add('bandeja-fila-nueva');
        setTimeout(function () {
            fila.classList.remove('bandeja-fila-nueva');
        }, RESALTE_MS);

        while (lista.children.length > MAX_FACTURAS) {
            lista.lastElementChild.remove();
        }

        const vacia = lista.closest('[data-filtro-local], .bandeja-lista').querySelector('[data-vacia]');

        if (vacia) {
            vacia.hidden = true;
        }
    }

    function reemplazarCotizacion(id, html) {
        const anterior = document.querySelector('[data-cotizacion="' + id + '"]');

        if (anterior) {
            anterior.replaceWith(crear(html));
        }
    }

    document.addEventListener('submit', function (evento) {
        const formulario = evento.target.closest('form[data-timbrar-cotizacion]');

        if (!formulario || !window.axios) {
            return;
        }

        evento.preventDefault();

        const boton = formulario.querySelector('[data-timbrar-enviar]');
        const textoBoton = boton.lastChild.textContent;
        const dialogo = formulario.closest('dialog');

        if (boton.disabled) {
            return;
        }

        boton.disabled = true;
        boton.lastChild.textContent = 'Timbrando…';
        formulario.querySelector('[data-timbrar-error]').hidden = true;

        function reactivar() {
            boton.disabled = false;
            boton.lastChild.textContent = textoBoton;
        }

        axios.post(formulario.action, new FormData(formulario), { headers: { Accept: 'application/json' } })
            .then(function (respuesta) {
                const datos = respuesta.data;

                if (!datos || !datos.fila) {
                    window.location.reload();
                    return;
                }

                const cotizacion = formulario.action.match(/cotizaciones\/(\d+)\/timbrar/)[1];

                if (dialogo && dialogo.open) {
                    dialogo.close();
                }

                agregarFactura(datos.fila);
                reemplazarCotizacion(cotizacion, datos.filaCotizacion);
                avisar(datos.tipo, datos.mensaje);
                document.dispatchEvent(new CustomEvent('documento:recargar'));
            })
            .catch(function (error) {
                const estado = error.response && error.response.status;
                const datos = error.response && error.response.data;

                if (estado === 401 || estado === 419) {
                    window.location.reload();
                    return;
                }

                reactivar();

                if (datos && datos.errors) {
                    mostrarError(formulario, Object.values(datos.errors).flat());
                } else if (datos && datos.mensaje) {
                    mostrarError(formulario, [datos.mensaje]);
                } else {
                    mostrarError(formulario, ['No se pudo timbrar. Intenta de nuevo.']);
                }
            });
    });
})();
