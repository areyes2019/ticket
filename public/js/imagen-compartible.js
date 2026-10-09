// Imagen de un artículo lista para compartir (010, 034).
//
// WhatsApp trata los .webp como calcomanías: se comparte una copia en JPEG,
// dibujada en un lienzo con fondo blanco (el JPEG no tiene transparencia).
// La usan la ficha del escritorio (ficha-articulo.js) y la del mostrador
// (mostrador.js), para que la conversión exista una sola vez.
(function (raiz) {
    // Nombre de archivo seguro a partir del modelo: "P-20/A" → "P-20_A.jpg".
    function nombreArchivo(base) {
        return String(base || 'imagen').replace(/[^\w.-]+/g, '_') + '.jpg';
    }

    // <img> ya cargada → Promise<File> image/jpeg.
    function comoJpeg(imagen, base) {
        return new Promise(function (resolver, rechazar) {
            const lienzo = document.createElement('canvas');
            lienzo.width = imagen.naturalWidth;
            lienzo.height = imagen.naturalHeight;

            const contexto = lienzo.getContext('2d');
            contexto.fillStyle = 'white';
            contexto.fillRect(0, 0, lienzo.width, lienzo.height);
            contexto.drawImage(imagen, 0, 0);

            lienzo.toBlob(function (blob) {
                if (blob) {
                    resolver(new File([blob], nombreArchivo(base), { type: 'image/jpeg' }));
                } else {
                    rechazar(new Error('No se pudo convertir la imagen.'));
                }
            }, 'image/jpeg', 0.9);
        });
    }

    const ImagenCompartible = { comoJpeg: comoJpeg, nombreArchivo: nombreArchivo };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = ImagenCompartible;
    }

    if (raiz) {
        raiz.ImagenCompartible = ImagenCompartible;
    }
})(typeof window !== 'undefined' ? window : null);
