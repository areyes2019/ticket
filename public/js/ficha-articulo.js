// Ficha visual de un artículo en el listado.
//
// Los enlaces del nombre llevan data-ficha y los datos en data-nombre,
// data-modelo, data-precio y data-imagen (URL o vacío). Se usa delegación
// porque busqueda-dinamica.js reemplaza las filas. Sin JavaScript, el enlace
// lleva a la edición del artículo.
(function () {
    const ficha = document.getElementById('ficha-articulo');

    if (!ficha || typeof ficha.showModal !== 'function') {
        return;
    }

    const imagen = ficha.querySelector('[data-ficha-imagen]');
    const sinImagen = ficha.querySelector('[data-ficha-sin-imagen]');
    const nombre = ficha.querySelector('[data-ficha-nombre]');
    const modelo = ficha.querySelector('[data-ficha-modelo]');
    const precio = ficha.querySelector('[data-ficha-precio]');
    const editar = ficha.querySelector('[data-ficha-editar]');
    const compartir = ficha.querySelector('[data-ficha-compartir]');
    const aviso = ficha.querySelector('[data-ficha-aviso]');
    const respaldo = ficha.querySelector('[data-ficha-copiar]');
    const campoTexto = respaldo.querySelector('input');
    let actual = null;

    function abrir(enlace) {
        actual = {
            nombre: enlace.dataset.nombre,
            modelo: enlace.dataset.modelo,
            precio: enlace.dataset.precio,
            imagen: enlace.dataset.imagen,
        };

        nombre.textContent = actual.nombre;
        modelo.textContent = actual.modelo;
        precio.textContent = actual.precio;
        editar.href = enlace.href;
        aviso.textContent = '';
        respaldo.hidden = true;

        imagen.hidden = !actual.imagen;
        sinImagen.hidden = Boolean(actual.imagen);

        if (actual.imagen) {
            imagen.src = actual.imagen;
            imagen.alt = actual.nombre;
        } else {
            imagen.removeAttribute('src');
        }

        ficha.showModal();
    }

    function textoCompartido() {
        return actual.nombre + ' — Modelo ' + actual.modelo + ' — ' + actual.precio;
    }

    // WhatsApp trata los .webp como calcomanías: se comparte una copia en JPEG.
    function imagenComoJpeg() {
        return new Promise(function (resolver, rechazar) {
            const lienzo = document.createElement('canvas');
            lienzo.width = imagen.naturalWidth;
            lienzo.height = imagen.naturalHeight;

            const contexto = lienzo.getContext('2d');
            // El JPEG no tiene transparencia: fondo blanco en lugar de negro.
            contexto.fillStyle = 'white';
            contexto.fillRect(0, 0, lienzo.width, lienzo.height);
            contexto.drawImage(imagen, 0, 0);

            lienzo.toBlob(function (blob) {
                if (blob) {
                    resolver(new File([blob], actual.modelo.replace(/[^\w.-]+/g, '_') + '.jpg', { type: 'image/jpeg' }));
                } else {
                    rechazar(new Error('No se pudo convertir la imagen.'));
                }
            }, 'image/jpeg', 0.9);
        });
    }

    function puedeCompartirArchivos() {
        if (!navigator.canShare) {
            return false;
        }

        try {
            return navigator.canShare({ files: [new File([''], 'prueba.jpg', { type: 'image/jpeg' })] });
        } catch (error) {
            return false;
        }
    }

    function copiar() {
        const texto = textoCompartido();

        window.copiarTexto(texto).then(function (copiado) {
            if (copiado) {
                aviso.textContent = 'Copiado';
                return;
            }

            respaldo.hidden = false;
            campoTexto.value = texto;
            campoTexto.focus();
            campoTexto.select();
        });
    }

    function compartirFicha() {
        if (!puedeCompartirArchivos()) {
            copiar();
            return;
        }

        const archivos = actual.imagen && imagen.complete && imagen.naturalWidth > 0 ? imagenComoJpeg().then(function (archivo) {
            return [archivo];
        }) : Promise.resolve([]);

        archivos
            .then(function (lista) {
                return navigator.share(lista.length ? { files: lista, text: textoCompartido() } : { text: textoCompartido() });
            })
            .catch(function (error) {
                // Cerrar el menú de compartir no es un error.
                if (error && error.name === 'AbortError') {
                    return;
                }

                copiar();
            });
    }

    document.addEventListener('click', function (evento) {
        const enlace = evento.target.closest('a[data-ficha]');

        if (!enlace || evento.ctrlKey || evento.metaKey || evento.shiftKey) {
            return;
        }

        evento.preventDefault();
        abrir(enlace);
    });

    compartir.addEventListener('click', compartirFicha);

    // Clic en el fondo oscuro (fuera del recuadro, no en su margen interior): cierra.
    ficha.addEventListener('click', function (evento) {
        const caja = ficha.getBoundingClientRect();
        const fuera = evento.clientX < caja.left || evento.clientX > caja.right || evento.clientY < caja.top || evento.clientY > caja.bottom;

        if (evento.target === ficha && fuera) {
            ficha.close();
        }
    });
})();
