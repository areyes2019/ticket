// Pantalla de carga masiva de imágenes (articulos/imagenes.blade.php).
//
// - Aviso de catálogo vacío: data-articulos del formulario trae
//   { catalogo_id: { nombre, articulos } }. Avisa sin deshabilitar nada.
// - Tandas: PHP descarta en silencio los archivos que pasan de
//   max_file_uploads (20). Con más imágenes (o más de 40 MB) se mandan por
//   Axios en tandas y se pinta un solo reporte; con 20 o menos, o con un .zip,
//   el formulario se envía normal.
// - "Copiar reporte".
(function (raiz) {
    // Tandas de como máximo `maximo` archivos y `bytes` en total; un archivo
    // más grande que el tope va solo en su tanda.
    function partirEnTandas(archivos, maximo, bytes) {
        const tandas = [];
        let tanda = [];
        let tamano = 0;

        archivos.forEach(function (archivo) {
            if (tanda.length > 0 && (tanda.length >= maximo || tamano + archivo.size > bytes)) {
                tandas.push(tanda);
                tanda = [];
                tamano = 0;
            }

            tanda.push(archivo);
            tamano += archivo.size;
        });

        if (tanda.length > 0) {
            tandas.push(tanda);
        }

        return tandas;
    }

    const CargaImagenes = { partirEnTandas: partirEnTandas };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = CargaImagenes;
    }

    if (typeof document === 'undefined') {
        return;
    }

    raiz.CargaImagenes = CargaImagenes;

    const TAMANO_MAXIMO_IMAGEN = 10 * 1024 * 1024;

    function textoAsociadas(cantidad) {
        return cantidad === 1 ? '1 imagen asociada.' : cantidad + ' imágenes asociadas.';
    }

    // "Copiar reporte" (también en el reporte que pinta el servidor). Sin
    // portapapeles, deja el reporte seleccionado para copiarlo con Ctrl+C.
    function activarCopia(reporte) {
        const boton = reporte.querySelector('[data-copiar-reporte]');
        const estado = reporte.querySelector('[data-copiar-estado]');

        if (!boton) {
            return;
        }

        boton.hidden = false;
        boton.addEventListener('click', function () {
            const lineas = [reporte.querySelector('[data-reporte-asociadas]').textContent.trim()];

            reporte.querySelectorAll('[data-reporte-filas] tr').forEach(function (fila) {
                lineas.push(fila.cells[0].textContent.trim() + ' — ' + fila.cells[1].textContent.trim());
            });

            window.copiarTexto(lineas.join('\n')).then(function (copiado) {
                if (copiado) {
                    estado.textContent = 'Copiado';
                    return;
                }

                window.getSelection().selectAllChildren(reporte);
                estado.textContent = 'El navegador no permite copiar aquí: el reporte quedó seleccionado, cópialo con Ctrl+C.';
            });
        });
    }

    document.querySelectorAll('[data-reporte-destino] [data-reporte]').forEach(activarCopia);

    document.querySelectorAll('form[data-carga-imagenes]').forEach(function (formulario) {
        const catalogo = formulario.querySelector('select[name="catalogo_id"]');
        const campoArchivos = formulario.querySelector('[data-archivos]');
        const campoZip = formulario.querySelector('[data-zip]');
        const aviso = formulario.querySelector('[data-aviso-vacio]');
        const avisoCatalogo = formulario.querySelector('[data-aviso-catalogo]');
        const conteo = formulario.querySelector('[data-conteo-articulos]');
        const botonSubir = formulario.querySelector('[data-boton-subir]');
        const etiquetaSubir = formulario.querySelector('[data-etiqueta-subir]');
        const progreso = formulario.querySelector('[data-progreso]');
        const estado = formulario.querySelector('[data-estado-carga]');
        const destino = document.querySelector('[data-reporte-destino]');
        const plantilla = document.getElementById('plantilla-reporte-imagenes');
        const maximoArchivos = parseInt(formulario.dataset.maximoArchivos, 10);
        const tamanoMaximo = parseInt(formulario.dataset.tamanoMaximo, 10);
        let catalogos;

        try {
            catalogos = JSON.parse(formulario.dataset.articulos);
        } catch (error) {
            return;
        }

        function mostrarCatalogo() {
            const datos = catalogos[catalogo.value];
            const vacio = Boolean(datos) && datos.articulos === 0;

            aviso.hidden = !vacio;
            avisoCatalogo.textContent = datos ? datos.nombre : '';
            conteo.hidden = !datos || vacio;
            conteo.textContent = datos ? (datos.articulos === 1 ? '1 artículo en este catálogo.' : datos.articulos + ' artículos en este catálogo.') : '';
            etiquetaSubir.textContent = vacio ? 'Subir de todos modos' : 'Subir imágenes';
        }

        // Un reporte que sobrevive al cambio de catálogo hablaría de otro catálogo.
        catalogo.addEventListener('change', function () {
            campoArchivos.value = '';
            campoZip.value = '';
            destino.innerHTML = '';
            estado.textContent = '';
            mostrarCatalogo();
        });

        mostrarCatalogo();

        function pintarReporte(reporte) {
            const copia = plantilla.content.cloneNode(true);
            const nuevo = copia.querySelector('[data-reporte]');
            const filas = nuevo.querySelector('[data-reporte-filas]');

            nuevo.querySelector('[data-reporte-asociadas]').textContent = textoAsociadas(reporte.asociadas);
            nuevo.querySelector('[data-reporte-catalogo]').textContent = catalogos[catalogo.value].nombre;
            nuevo.querySelector('[data-reporte-ninguna]').hidden = !(reporte.asociadas === 0 && reporte.errores.length > 0);
            nuevo.querySelector('[data-reporte-rechazados]').hidden = reporte.errores.length === 0;

            reporte.errores.forEach(function (error) {
                const fila = filas.insertRow();
                fila.insertCell().textContent = error.archivo;
                fila.insertCell().textContent = error.motivo;
            });

            destino.innerHTML = '';
            destino.appendChild(copia);
            activarCopia(nuevo);
            nuevo.scrollIntoView({ block: 'start' });
        }

        function mensajeDeError(error) {
            const datos = error.response && error.response.data;

            if (datos && datos.errors) {
                return Object.values(datos.errors).flat().join(' ');
            }

            return 'No se pudo completar el envío. Revisa tu conexión e intenta de nuevo.';
        }

        function subirEnTandas(imagenes) {
            const reporte = { asociadas: 0, errores: [] };
            const aceptadas = [];

            // Las que pasan de 10 MB se reportan sin subirlas, como lo haría el servidor.
            imagenes.forEach(function (imagen) {
                if (imagen.size > TAMANO_MAXIMO_IMAGEN) {
                    reporte.errores.push({ archivo: imagen.name, motivo: 'pesa más de 10 MB' });
                } else {
                    aceptadas.push(imagen);
                }
            });

            const tandas = partirEnTandas(aceptadas, maximoArchivos, tamanoMaximo);
            let enviadas = 0;

            botonSubir.disabled = true;
            progreso.hidden = false;
            progreso.max = Math.max(tandas.length, 1);
            progreso.value = 0;

            tandas.reduce(function (cadena, tanda, indice) {
                return cadena.then(function () {
                    const datos = new FormData();
                    datos.append('catalogo_id', catalogo.value);
                    tanda.forEach(function (imagen) {
                        datos.append('archivos[]', imagen);
                    });

                    estado.textContent = 'Subiendo tanda ' + (indice + 1) + ' de ' + tandas.length + '…';

                    return axios.post(formulario.action, datos).then(function (respuesta) {
                        reporte.asociadas += respuesta.data.asociadas;
                        reporte.errores.push.apply(reporte.errores, respuesta.data.errores);
                        enviadas += tanda.length;
                        progreso.value = indice + 1;
                    });
                });
            }, Promise.resolve())
                .then(function () {
                    estado.textContent = '';
                    campoArchivos.value = '';
                })
                .catch(function (error) {
                    const codigo = error.response && error.response.status;

                    if (codigo === 401 || codigo === 419) {
                        window.location.reload();
                        return;
                    }

                    estado.textContent = 'La carga se detuvo: ' + mensajeDeError(error) + ' Se procesaron ' + enviadas + ' de ' + aceptadas.length
                        + ' imágenes; el reporte muestra lo que alcanzó a entrar.';
                })
                .finally(function () {
                    botonSubir.disabled = false;
                    progreso.hidden = true;
                    pintarReporte(reporte);
                });
        }

        formulario.addEventListener('submit', function (evento) {
            const imagenes = Array.from(campoArchivos.files);
            const zip = campoZip.files[0];
            const total = imagenes.reduce(function (suma, imagen) {
                return suma + imagen.size;
            }, 0);

            estado.textContent = '';

            if (zip && zip.size > tamanoMaximo) {
                evento.preventDefault();
                estado.textContent = 'El .zip pesa más de 40 MB; divídelo en varios.';
                return;
            }

            // Con un .zip, sin catálogo o con pocas imágenes, el envío es normal y el servidor valida.
            if (zip || !catalogo.value || (imagenes.length <= maximoArchivos && total <= tamanoMaximo)) {
                return;
            }

            evento.preventDefault();
            subirEnTandas(imagenes);
        });
    });
})(typeof window !== 'undefined' ? window : this);
