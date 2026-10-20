// Ficha visual de un artículo en el listado.
//
// Los enlaces del nombre llevan data-ficha y los datos en data-nombre,
// data-modelo, data-precio, data-precio-distribuidor, data-etiqueta-precio
// ("Precio con IVA" o "Precio", según el objeto de impuesto) y data-imagen
// (URL o vacío); data-clonar es la dirección del formulario "Clonar". "Copiar"
// (data-ficha-copiar-imagen) copia con html2canvas el recuadro de la foto con el
// precio al público y el nombre; data-ficha-compartir="distribuidor" comparte
// el precio distribuidor como texto. Se usa
// delegación porque busqueda-dinamica.js reemplaza las filas. Sin JavaScript,
// el enlace lleva a la edición del artículo.
(function () {
    const ficha = document.getElementById('ficha-articulo');

    if (!ficha || typeof ficha.showModal !== 'function') {
        return;
    }

    const imagen = ficha.querySelector('[data-ficha-imagen]');
    const sinImagen = ficha.querySelector('[data-ficha-sin-imagen]');
    const nombre = ficha.querySelector('[data-ficha-nombre]');
    const modelo = ficha.querySelector('[data-ficha-modelo]');
    const tipo = ficha.querySelector('[data-ficha-tipo]');
    const precio = ficha.querySelector('[data-ficha-precio]');
    const etiquetaPrecio = ficha.querySelector('[data-ficha-etiqueta-precio]');
    const precioDistribuidor = ficha.querySelector('[data-ficha-precio-distribuidor]');
    const etiquetaDistribuidor = ficha.querySelector('[data-ficha-etiqueta-distribuidor]');
    const editar = ficha.querySelector('[data-ficha-editar]');
    const clonar = ficha.querySelector('[data-ficha-clonar]');
    const aviso = ficha.querySelector('[data-ficha-aviso]');
    const respaldo = ficha.querySelector('[data-ficha-copiar]');
    const campoTexto = respaldo.querySelector('input');
    const captura = ficha.querySelector('[data-ficha-captura]');
    const capturaPrecio = ficha.querySelector('[data-ficha-captura-precio]');
    const capturaNombre = ficha.querySelector('[data-ficha-captura-nombre]');
    const botonCopiar = ficha.querySelector('[data-ficha-copiar-imagen]');
    let actual = null;

    function abrir(enlace) {
        actual = {
            nombre: enlace.dataset.nombre,
            modelo: enlace.dataset.modelo,
            precio: enlace.dataset.precio,
            precioDistribuidor: enlace.dataset.precioDistribuidor || '',
            imagen: enlace.dataset.imagen,
        };
        const etiqueta = enlace.dataset.etiquetaPrecio || 'Precio con IVA';

        nombre.textContent = actual.nombre;
        modelo.textContent = actual.modelo;
        // "Producción (por su catálogo)" o "Suministro (por su catálogo)" (029).
        tipo.textContent = enlace.dataset.tipo || '';
        precio.textContent = actual.precio;
        capturaPrecio.textContent = actual.precio;
        capturaNombre.textContent = actual.nombre;
        etiquetaPrecio.textContent = etiqueta;
        precioDistribuidor.textContent = actual.precioDistribuidor;
        // "Precio con IVA" → "Precio distribuidor con IVA"; "Precio" → "Precio distribuidor".
        etiquetaDistribuidor.textContent = etiqueta.replace(/^Precio/, 'Precio distribuidor');
        editar.href = enlace.href;
        clonar.action = enlace.dataset.clonar;
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

    function textoCompartido(cual) {
        return actual.nombre + ' — Modelo ' + actual.modelo + ' — ' + (cual === 'distribuidor' ? actual.precioDistribuidor : actual.precio);
    }

    // WhatsApp trata los .webp como calcomanías: se comparte una copia en JPEG
    // (imagen-compartible.js, la misma conversión que la ficha del mostrador).
    function imagenComoJpeg() {
        return window.ImagenCompartible.comoJpeg(imagen, actual.modelo);
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

    function copiar(cual) {
        const texto = textoCompartido(cual);

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

    function compartirFicha(cual) {
        if (!puedeCompartirArchivos()) {
            copiar(cual);
            return;
        }

        const archivos = actual.imagen && imagen.complete && imagen.naturalWidth > 0 ? imagenComoJpeg().then(function (archivo) {
            return [archivo];
        }) : Promise.resolve([]);

        archivos
            .then(function (lista) {
                return navigator.share(lista.length ? { files: lista, text: textoCompartido(cual) } : { text: textoCompartido(cual) });
            })
            .catch(function (error) {
                // Cerrar el menú de compartir no es un error.
                if (error && error.name === 'AbortError') {
                    return;
                }

                copiar(cual);
            });
    }

    // El recuadro (foto, precio al público y nombre) como PNG sobre blanco puro.
    function capturaEnPng() {
        const listo = imagen.hidden || (imagen.complete && imagen.naturalWidth > 0)
            ? Promise.resolve()
            : new Promise(function (resolver) {
                imagen.addEventListener('load', resolver, { once: true });
                imagen.addEventListener('error', resolver, { once: true });
            });

        return listo
            .then(function () {
                return window.html2canvas(captura, { backgroundColor: '#ffffff', scale: 2, useCORS: true, logging: false });
            })
            .then(function (lienzo) {
                return new Promise(function (resolver, rechazar) {
                    lienzo.toBlob(function (blob) {
                        blob ? resolver(blob) : rechazar(new Error('Sin imagen'));
                    }, 'image/png');
                });
            });
    }

    function descargar(blob) {
        const url = URL.createObjectURL(blob);
        const enlace = document.createElement('a');
        enlace.href = url;
        enlace.download = (actual.modelo || 'articulo').replace(/[\\/:*?"<>|]+/g, '-') + '.png';
        document.body.appendChild(enlace);
        enlace.click();
        enlace.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    // Copia el recuadro como imagen; si el navegador no deja escribir imágenes
    // en el portapapeles (sitio sin HTTPS, Firefox antiguo), la descarga.
    function copiarImagen() {
        if (typeof window.html2canvas !== 'function') {
            aviso.textContent = 'No se pudo copiar la imagen.';
            return;
        }

        botonCopiar.disabled = true;
        aviso.textContent = 'Copiando…';

        const png = capturaEnPng();
        const alDescargar = function (blob) {
            descargar(blob);
            aviso.textContent = 'Tu navegador no deja copiar imágenes; se descargó.';
        };
        let copiado;

        if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.write === 'function' && typeof window.ClipboardItem === 'function') {
            // Safari exige crear el ClipboardItem dentro del clic, con la imagen como promesa.
            copiado = navigator.clipboard.write([new ClipboardItem({ 'image/png': png })])
                .then(function () { aviso.textContent = 'Copiado. Pégalo en el chat.'; })
                .catch(function () { return png.then(alDescargar); });
        } else {
            copiado = png.then(alDescargar);
        }

        copiado
            .catch(function () { aviso.textContent = 'No se pudo copiar la imagen.'; })
            .finally(function () { botonCopiar.disabled = false; });
    }

    botonCopiar.addEventListener('click', copiarImagen);

    document.addEventListener('click', function (evento) {
        const enlace = evento.target.closest('a[data-ficha]');

        if (!enlace || evento.ctrlKey || evento.metaKey || evento.shiftKey) {
            return;
        }

        evento.preventDefault();
        abrir(enlace);
    });

    ficha.querySelectorAll('[data-ficha-compartir]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            compartirFicha(boton.dataset.fichaCompartir);
        });
    });

    // Clic en el fondo oscuro (fuera del recuadro, no en su margen interior): cierra.
    ficha.addEventListener('click', function (evento) {
        const caja = ficha.getBoundingClientRect();
        const fuera = evento.clientX < caja.left || evento.clientX > caja.right || evento.clientY < caja.top || evento.clientY > caja.bottom;

        if (evento.target === ficha && fuera) {
            ficha.close();
        }
    });
})();
