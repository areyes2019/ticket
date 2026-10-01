// Compartir el PDF de un documento desde el aparato del usuario, con el menú de
// compartir del sistema operativo (Web Share API).
//
// Atiende todos los botones [data-compartir-pdf] de la página, incluidos los que
// llegan después con la búsqueda dinámica. Atributos:
//   data-pdf       URL del PDF (mismo origen: viaja la cookie de sesión).
//   data-archivo   nombre del archivo.
//   data-texto     opcional. Con texto (cotizaciones) se comparte archivo y
//                  texto; si el navegador no comparte archivos, se descarga y se
//                  abre wa.me con el texto (data-telefono, opcional).
//                  Sin texto (facturas) sale solo el archivo, y si el navegador
//                  no puede compartir archivos el botón no se muestra.
//   data-marcar    opcional: URL a la que se hace POST al terminar de compartir.
//   data-precargar opcional: "al-cargar" o "al-apuntar". El menú del sistema
//                  solo abre mientras dura el gesto del usuario, y esperar una
//                  descarga ahí lo agota: se baja antes. Sin él, al hacer clic.
// El servidor nunca manda mensajes de WhatsApp.
(function () {
    // Si este navegador tiene menú de compartir y acepta archivos PDF en él.
    function puedeCompartirArchivos(navegador) {
        if (!navegador || typeof navegador.share !== 'function' || typeof navegador.canShare !== 'function' || typeof File !== 'function') {
            return false;
        }

        try {
            return navegador.canShare({ files: [new File([''], 'prueba.pdf', { type: 'application/pdf' })] }) === true;
        } catch (error) {
            return false;
        }
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { puedeCompartirArchivos: puedeCompartirArchivos };
    }

    if (typeof document === 'undefined' || typeof fetch !== 'function') {
        return;
    }

    const conMenu = puedeCompartirArchivos(navigator);
    const pendientes = new WeakMap();
    const listos = new WeakMap();

    function mostrarAviso(texto) {
        const alerta = document.querySelector('[data-compartir-error]');

        if (alerta) {
            alerta.querySelector('.alerta-contenido').textContent = texto;
            alerta.hidden = false;
        }
    }

    // Cada PDF se baja una sola vez por página; si falla, el siguiente intento
    // lo vuelve a pedir.
    function obtenerArchivo(boton) {
        if (!pendientes.has(boton)) {
            const promesa = fetch(boton.dataset.pdf, { credentials: 'same-origin' }).then(function (respuesta) {
                // Sin sesión Laravel redirige al login: la respuesta no es el PDF.
                if (respuesta.status === 401 || respuesta.redirected || !respuesta.ok) {
                    throw new Error(respuesta.redirected ? 'sesion' : 'pdf');
                }

                return respuesta.blob();
            }).then(function (blob) {
                const archivo = new File([blob], boton.dataset.archivo, { type: 'application/pdf' });

                listos.set(boton, archivo);

                return archivo;
            });

            promesa.catch(function () {
                pendientes.delete(boton);
            });
            pendientes.set(boton, promesa);
        }

        return pendientes.get(boton);
    }

    function guardarArchivo(archivo) {
        const enlace = document.createElement('a');
        const url = URL.createObjectURL(archivo);

        enlace.href = url;
        enlace.download = archivo.name;
        document.body.appendChild(enlace);
        enlace.click();
        enlace.remove();
        setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 1000);
    }

    function abrirWhatsApp(boton) {
        const telefono = boton.dataset.telefono || '';
        const texto = encodeURIComponent(boton.dataset.texto + '. Te adjunto el PDF.');

        window.open('https://wa.me/' + telefono + '?text=' + texto, '_blank', 'noopener');
    }

    function marcar(boton) {
        if (!boton.dataset.marcar) {
            return;
        }

        return axios.post(boton.dataset.marcar).then(function (respuesta) {
            const etiqueta = document.querySelector('[data-estado-documento]');

            if (etiqueta && respuesta.data && respuesta.data.etiqueta) {
                etiqueta.textContent = respuesta.data.etiqueta;
                etiqueta.className = 'etiqueta etiqueta-' + respuesta.data.estado.replace(/_/g, '-');
            }
        });
    }

    // Con texto: archivo y texto al menú, o descarga + wa.me. Sin texto: solo el
    // archivo; si el menú se rechaza (p. ej. se agotó el gesto), se descarga.
    function compartir(boton, archivo) {
        if (boton.dataset.texto) {
            if (navigator.canShare && navigator.canShare({ files: [archivo] })) {
                return navigator.share({ files: [archivo], text: boton.dataset.texto }).then(function () {
                    return marcar(boton);
                }, function (error) {
                    if (error && error.name === 'AbortError') {
                        throw error;
                    }

                    guardarArchivo(archivo);
                    abrirWhatsApp(boton);

                    return marcar(boton);
                });
            }

            guardarArchivo(archivo);
            abrirWhatsApp(boton);

            return Promise.resolve(marcar(boton));
        }

        return navigator.share({ files: [archivo] }).then(function () {
            return marcar(boton);
        }, function (error) {
            if (error && error.name === 'AbortError') {
                throw error;
            }

            guardarArchivo(archivo);
            mostrarAviso('No se pudo abrir el menú de compartir; el PDF se descargó.');
        });
    }

    function ponerPreparando(boton, preparando) {
        const texto = Array.from(boton.childNodes).reverse().find(function (nodo) {
            return nodo.nodeType === Node.TEXT_NODE && nodo.textContent.trim() !== '';
        });

        if (preparando) {
            boton.dataset.textoOriginal = texto ? texto.textContent : boton.getAttribute('aria-label') || '';
        }

        const valor = preparando ? 'Preparando...' : boton.dataset.textoOriginal;

        if (texto) {
            texto.textContent = valor;
        } else {
            boton.setAttribute('aria-label', valor);
            boton.title = valor;
        }

        boton.setAttribute('aria-busy', preparando ? 'true' : 'false');
    }

    function alHacerClic(boton) {
        const alerta = document.querySelector('[data-compartir-error]');

        if (alerta) {
            alerta.hidden = true;
        }

        boton.disabled = true;

        // Si ya se bajó, el menú abre dentro del mismo gesto del usuario.
        const archivoListo = listos.get(boton);
        let proceso;

        if (archivoListo) {
            proceso = compartir(boton, archivoListo);
        } else {
            ponerPreparando(boton, true);
            proceso = obtenerArchivo(boton).then(function (archivo) {
                ponerPreparando(boton, false);

                return compartir(boton, archivo);
            });
        }

        proceso
            .catch(function (error) {
                // Cerrar el menú de compartir no es un error ni descarga nada.
                if (error && error.name === 'AbortError') {
                    return;
                }

                const estado = error && error.response && error.response.status;

                if ((error && error.message === 'sesion') || estado === 401 || estado === 419) {
                    window.location.reload();
                    return;
                }

                mostrarAviso('No se pudo compartir el PDF. Intenta de nuevo.');
            })
            .finally(function () {
                if (boton.getAttribute('aria-busy') === 'true') {
                    ponerPreparando(boton, false);
                }

                boton.disabled = false;
            });
    }

    function preparar(boton) {
        if (boton.dataset.compartirListo) {
            return;
        }

        boton.dataset.compartirListo = '1';

        // Sin texto no hay respaldo que valga la pena: sin menú, no hay botón.
        if (!boton.dataset.texto && !conMenu) {
            return;
        }

        (boton.closest('[data-compartir-contenedor]') || boton).hidden = false;
        boton.hidden = false;
        boton.addEventListener('click', function () {
            alHacerClic(boton);
        });

        if (boton.dataset.precargar === 'al-cargar') {
            obtenerArchivo(boton).catch(function () {});
        } else if (boton.dataset.precargar === 'al-apuntar') {
            const precargar = function () {
                obtenerArchivo(boton).catch(function () {});
            };

            boton.addEventListener('mouseenter', precargar, { once: true });
            boton.addEventListener('focus', precargar, { once: true });
        }
    }

    function prepararTodos() {
        document.querySelectorAll('[data-compartir-pdf]').forEach(preparar);
    }

    prepararTodos();

    // La búsqueda dinámica reemplaza las filas del listado.
    new MutationObserver(prepararTodos).observe(document.body, { childList: true, subtree: true });
})();
