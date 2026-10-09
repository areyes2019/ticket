// Ficha visual de un artículo en el listado.
//
// Los enlaces del nombre llevan data-ficha y los datos en data-nombre,
// data-modelo, data-precio, data-precio-distribuidor, data-etiqueta-precio
// ("Precio con IVA" o "Precio", según el objeto de impuesto) y data-imagen
// (URL o vacío). Hay un botón de compartir por precio
// (data-ficha-compartir="precio" o "distribuidor"): lo compartido lleva solo
// el precio de su botón. Se usa
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
    const aviso = ficha.querySelector('[data-ficha-aviso]');
    const respaldo = ficha.querySelector('[data-ficha-copiar]');
    const campoTexto = respaldo.querySelector('input');
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
        etiquetaPrecio.textContent = etiqueta;
        precioDistribuidor.textContent = actual.precioDistribuidor;
        // "Precio con IVA" → "Precio distribuidor con IVA"; "Precio" → "Precio distribuidor".
        etiquetaDistribuidor.textContent = etiqueta.replace(/^Precio/, 'Precio distribuidor');
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
