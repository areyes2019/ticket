// Carga de la Constancia de Situación Fiscal en el formulario de cliente.
//
// Se activa en un elemento con data-constancia="<url del análisis>". Todo el
// trámite lo resuelve Laravel en una sola petición; aquí solo se recibe el
// archivo, se intenta leer el QR de una foto con el lector nativo del
// navegador (si existe) y se escriben en el formulario los datos devueltos.
(function () {
    const TAMANO_MAXIMO = 10 * 1024 * 1024;
    const TIPOS = ['application/pdf', 'image/jpeg', 'image/png'];

    document.querySelectorAll('[data-constancia]').forEach(function (contenedor) {
        const url = contenedor.dataset.constancia;
        const clienteId = contenedor.dataset.clienteId || null;
        const zona = contenedor.querySelector('[data-constancia-zona]');
        const entrada = contenedor.querySelector('[data-constancia-archivo]');
        const estado = contenedor.querySelector('[data-constancia-estado]');
        const alertaError = contenedor.querySelector('[data-constancia-error]');
        const alertaAviso = contenedor.querySelector('[data-constancia-aviso]');
        const alertaExistente = contenedor.querySelector('[data-constancia-existente]');
        const formulario = contenedor.parentElement.querySelector('form');
        let pendiente = null;
        let trabajando = false;

        contenedor.hidden = false;

        function texto(selector, valor) {
            contenedor.querySelector(selector).textContent = valor;
        }

        function limpiar() {
            [alertaError, alertaAviso, alertaExistente].forEach(function (alerta) {
                alerta.hidden = true;
            });
            estado.textContent = '';
        }

        function mostrarError(mensaje) {
            texto('[data-constancia-error-texto]', mensaje);
            alertaError.hidden = false;
        }

        // Lector de QR nativo del navegador (Chrome/Edge). Si no existe o no
        // lo logra, el servidor lee el QR por su cuenta. Nunca lanza.
        function leerQrDeFoto(archivo) {
            if (!('BarcodeDetector' in window) || archivo.type === 'application/pdf') {
                return Promise.resolve(null);
            }

            return window.BarcodeDetector.getSupportedFormats()
                .then(function (formatos) {
                    if (formatos.indexOf('qr_code') === -1) {
                        return null;
                    }

                    return createImageBitmap(archivo).then(function (imagen) {
                        return new window.BarcodeDetector({ formats: ['qr_code'] }).detect(imagen);
                    }).then(function (codigos) {
                        const validador = codigos.find(function (codigo) {
                            return /[?&]D3=\d+_/.test(codigo.rawValue);
                        });

                        return validador ? validador.rawValue : null;
                    });
                })
                .catch(function () {
                    return null;
                });
        }

        function procesar(archivo) {
            if (trabajando || !archivo) {
                return;
            }

            limpiar();

            if (TIPOS.indexOf(archivo.type) === -1) {
                mostrarError('La constancia debe ser un PDF, JPG o PNG.');
                return;
            }

            if (archivo.size > TAMANO_MAXIMO) {
                mostrarError('La constancia no debe pesar más de 10 MB.');
                return;
            }

            trabajando = true;
            contenedor.setAttribute('aria-busy', 'true');
            estado.textContent = 'Leyendo el documento…';

            leerQrDeFoto(archivo)
                .then(function (qrUrl) {
                    const datos = new FormData();
                    datos.append('archivo', archivo);

                    if (qrUrl) {
                        datos.append('qr_url', qrUrl);
                    }

                    estado.textContent = 'Consultando al SAT…';

                    return axios.post(url, datos);
                })
                .then(function (respuesta) {
                    // Si Laravel redirigió (usuario suspendido), la respuesta no es
                    // el análisis: se recarga para seguir la redirección.
                    const urlRespuesta = respuesta.request && respuesta.request.responseURL;

                    if (urlRespuesta && new URL(urlRespuesta).pathname !== new URL(url, window.location.href).pathname) {
                        window.location.reload();
                        return;
                    }

                    estado.textContent = '';
                    recibir(respuesta.data);
                })
                .catch(function (error) {
                    estado.textContent = '';
                    manejarError(error);
                })
                .finally(function () {
                    trabajando = false;
                    contenedor.removeAttribute('aria-busy');
                    entrada.value = '';
                });
        }

        function manejarError(error) {
            const respuesta = error.response;
            const codigo = respuesta && respuesta.status;

            if (codigo === 401 || codigo === 419) {
                window.location.reload();
                return;
            }

            if (codigo === 429) {
                mostrarError('Vas muy rápido. Espera un momento antes de subir otra constancia.');
                return;
            }

            if (codigo === 422 && respuesta.data) {
                if (respuesta.data.mensaje) {
                    mostrarError(respuesta.data.mensaje);
                    return;
                }

                const errores = respuesta.data.errors ? Object.values(respuesta.data.errors) : [];

                if (errores.length) {
                    mostrarError(errores[0][0]);
                    return;
                }
            }

            mostrarError('No se pudo leer el documento. Captura los datos manualmente.');
        }

        function recibir(resultado) {
            const existente = resultado.cliente_existente;

            if (existente && String(existente.id) !== clienteId) {
                texto('[data-constancia-existente-nombre]', existente.razon_social);
                // El mostrador (033) sigue con ese cliente en lugar de abrir su ficha.
                contenedor.querySelector('[data-constancia-abrir]').href = contenedor.dataset.urlExistente
                    ? contenedor.dataset.urlExistente.replace('{id}', existente.id)
                    : existente.url_editar;
                alertaExistente.hidden = false;
                pendiente = resultado;
                return;
            }

            precargar(resultado);
        }

        // Solo se escriben los campos que trae la constancia; nombre comercial,
        // contacto, correo y teléfono nunca se tocan. Nada se guarda solo.
        function precargar(resultado) {
            Object.keys(resultado.data).forEach(function (nombre) {
                const campo = formulario && formulario.elements.namedItem(nombre);

                if (!campo || typeof resultado.data[nombre] !== 'string') {
                    return;
                }

                campo.value = resultado.data[nombre];
                campo.classList.add('precargado');
                campo.dispatchEvent(new Event('input', { bubbles: true }));
                campo.dispatchEvent(new Event('change', { bubbles: true }));
            });

            mostrarAviso(resultado);
        }

        function mostrarAviso(resultado) {
            const lista = contenedor.querySelector('[data-constancia-advertencias]');
            lista.textContent = '';

            (resultado.advertencias || []).forEach(function (advertencia) {
                const elemento = document.createElement('li');
                elemento.textContent = advertencia;
                lista.appendChild(elemento);
            });

            texto('[data-constancia-aviso-texto]', resultado.aviso || '');
            alertaAviso.hidden = !resultado.aviso && !lista.children.length;
            estado.textContent = alertaAviso.hidden ? 'Datos de la constancia cargados. Revísalos y guarda.' : '';
        }

        contenedor.querySelector('[data-constancia-precargar]').addEventListener('click', function () {
            alertaExistente.hidden = true;

            if (pendiente) {
                precargar(pendiente);
                pendiente = null;
            }
        });

        // Al editar un campo precargado deja de resaltarse.
        if (formulario) {
            formulario.addEventListener('input', function (evento) {
                if (evento.isTrusted) {
                    evento.target.classList.remove('precargado');
                }
            });
        }

        entrada.addEventListener('change', function () {
            procesar(entrada.files[0]);
        });

        zona.addEventListener('click', function (evento) {
            if (evento.target !== entrada && evento.target.tagName !== 'LABEL') {
                entrada.click();
            }
        });

        zona.addEventListener('dragover', function (evento) {
            evento.preventDefault();
            zona.classList.add('constancia-zona-encima');
        });

        zona.addEventListener('dragleave', function () {
            zona.classList.remove('constancia-zona-encima');
        });

        zona.addEventListener('drop', function (evento) {
            evento.preventDefault();
            zona.classList.remove('constancia-zona-encima');

            if (evento.dataTransfer.files.length > 1) {
                limpiar();
                mostrarError('Sube un solo archivo a la vez.');
                return;
            }

            procesar(evento.dataTransfer.files[0]);
        });
    });
})();
