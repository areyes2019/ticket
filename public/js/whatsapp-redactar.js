// Barra de respuesta estilo WhatsApp (044): la caja crece sola, el clip abre
// un menú hacia arriba (Documento, Imagen y Catálogo de adorno), el archivo
// elegido se ve en una tira encima de la barra y el botón de enviar se
// atenúa cuando no hay nada que mandar. Sin JavaScript se ve el campo de
// archivo de siempre y todo funciona igual (adición 1).
(function () {
    const formulario = document.querySelector('[data-redactar]');

    if (!formulario) {
        return;
    }

    const caja = formulario.querySelector('#wa-texto');
    const archivo = formulario.querySelector('#wa-archivo');
    const clip = formulario.querySelector('[data-clip]');
    const abrirMenu = formulario.querySelector('[data-clip-boton]');
    const menu = formulario.querySelector('#wa-menu-adjuntar');
    const tira = formulario.querySelector('[data-adjunto]');
    const enviar = formulario.querySelector('[data-enviar]');

    if (!caja || !archivo || !clip || !abrirMenu || !menu || !tira || !enviar) {
        return;
    }

    const maxBytes = Number(formulario.dataset.maxBytes) || 10 * 1024 * 1024;
    const maxMb = formulario.dataset.maxMb || '10';
    const TIPOS = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    const ACCEPT_TODOS = archivo.getAttribute('accept');
    const opciones = Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));

    let miniatura = null;   // URL temporal de la vista previa
    let errorArchivo = null;
    let enviando = false;

    formulario.classList.add('redactar-activo');
    clip.hidden = false;

    // --- La caja crece hasta 6 líneas (regla 2) ---

    function ajustarAltura() {
        caja.style.height = 'auto';
        caja.style.height = caja.scrollHeight + 'px';
    }

    caja.rows = 1;
    caja.addEventListener('input', function () {
        ajustarAltura();
        actualizarEnviar();
    });
    ajustarAltura();

    // --- Menú del clip (reglas 8 a 12, adición 3) ---

    function menuAbierto() {
        return !menu.hidden;
    }

    function abrir() {
        menu.hidden = false;
        abrirMenu.setAttribute('aria-expanded', 'true');
        enfocar(0);
    }

    function cerrar(regresarFoco) {
        if (!menuAbierto()) {
            return;
        }

        menu.hidden = true;
        abrirMenu.setAttribute('aria-expanded', 'false');

        if (regresarFoco) {
            abrirMenu.focus();
        }
    }

    function enfocar(indice) {
        const total = opciones.length;
        opciones[((indice % total) + total) % total].focus();
    }

    abrirMenu.addEventListener('click', function () {
        if (menuAbierto()) {
            cerrar(false);
        } else {
            abrir();
        }
    });

    abrirMenu.addEventListener('keydown', function (evento) {
        if (evento.key === 'ArrowUp' || evento.key === 'ArrowDown') {
            evento.preventDefault();
            abrir();
            enfocar(evento.key === 'ArrowUp' ? opciones.length - 1 : 0);
        }
    });

    function elegir(opcion) {
        if (opcion.getAttribute('aria-disabled') === 'true') {
            return; // Catálogo: de adorno por ahora (regla 11).
        }

        cerrar(false);
        archivo.setAttribute('accept', opcion.dataset.accept || ACCEPT_TODOS);
        archivo.click();
    }

    opciones.forEach(function (opcion) {
        opcion.addEventListener('click', function () {
            elegir(opcion);
        });
    });

    menu.addEventListener('keydown', function (evento) {
        const actual = opciones.indexOf(document.activeElement);

        switch (evento.key) {
            case 'ArrowDown':
                evento.preventDefault();
                enfocar(actual + 1);
                break;
            case 'ArrowUp':
                evento.preventDefault();
                enfocar(actual - 1);
                break;
            case 'Home':
                evento.preventDefault();
                enfocar(0);
                break;
            case 'End':
                evento.preventDefault();
                enfocar(opciones.length - 1);
                break;
            case 'Escape':
                evento.preventDefault();
                evento.stopPropagation();
                cerrar(true);
                break;
            case 'Tab':
                cerrar(false);
                break;
            case 'Enter':
            case ' ':
                evento.preventDefault();
                if (actual >= 0) {
                    elegir(opciones[actual]);
                }
                break;
        }
    });

    document.addEventListener('pointerdown', function (evento) {
        if (menuAbierto() && !clip.contains(evento.target)) {
            cerrar(false);
        }
    });

    // --- Archivo elegido (reglas 13, 14 y 16) ---

    function tamano(bytes) {
        if (bytes < 1024 * 1024) {
            return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }

        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function liberarMiniatura() {
        if (miniatura) {
            URL.revokeObjectURL(miniatura);
            miniatura = null;
        }
    }

    function icono(nombre) {
        const i = document.createElement('i');
        i.className = 'bi bi-' + nombre + ' icono';
        i.setAttribute('aria-hidden', 'true');

        return i;
    }

    function quitarArchivo() {
        archivo.value = '';
        archivo.setAttribute('accept', ACCEPT_TODOS);
        pintarTira();
        tira.hidden = false;
        tira.replaceChildren();
        const aviso = document.createElement('span');
        aviso.className = 'solo-lectores';
        aviso.textContent = 'Archivo quitado';
        tira.appendChild(aviso);
        tira.classList.remove('wa-adjunto-error');
        // Se oculta después de que el lector alcance a anunciarlo.
        setTimeout(function () {
            if (!archivo.files.length) {
                tira.hidden = true;
                tira.replaceChildren();
            }
        }, 1500);
        caja.focus();
    }

    function pintarTira() {
        liberarMiniatura();
        tira.replaceChildren();
        tira.classList.remove('wa-adjunto-error');
        errorArchivo = null;

        const elegido = archivo.files && archivo.files[0];

        if (!elegido) {
            tira.hidden = true;
            actualizarEnviar();

            return;
        }

        if (!TIPOS.includes(elegido.type)) {
            errorArchivo = 'Solo se pueden mandar PDF o imágenes JPG, PNG o WEBP.';
        } else if (elegido.size > maxBytes) {
            errorArchivo = 'El archivo pesa más de ' + maxMb + ' MB.';
        }

        if (elegido.type.startsWith('image/') && !errorArchivo) {
            miniatura = URL.createObjectURL(elegido);
            const img = document.createElement('img');
            img.className = 'wa-adjunto-miniatura';
            img.src = miniatura;
            img.alt = '';
            tira.appendChild(img);
        } else {
            tira.appendChild(icono(elegido.type === 'application/pdf' ? 'file-earmark-pdf' : 'file-earmark'));
        }

        const datos = document.createElement('div');
        datos.className = 'wa-adjunto-datos';

        const nombre = document.createElement('span');
        nombre.className = 'wa-adjunto-nombre';
        nombre.textContent = elegido.name;
        nombre.title = elegido.name;

        const detalle = document.createElement('span');
        detalle.className = 'wa-adjunto-detalle';
        detalle.textContent = errorArchivo || tamano(elegido.size);

        const lector = document.createElement('span');
        lector.className = 'solo-lectores';
        lector.textContent = errorArchivo ? 'Archivo no válido: ' : 'Archivo adjunto: ';

        datos.append(lector, nombre, detalle);
        tira.appendChild(datos);

        const quitar = document.createElement('button');
        quitar.type = 'button';
        quitar.className = 'boton boton-suave boton-icono';
        quitar.setAttribute('aria-label', 'Quitar archivo');
        quitar.title = 'Quitar archivo';
        quitar.appendChild(icono('x-lg'));
        quitar.addEventListener('click', quitarArchivo);
        tira.appendChild(quitar);

        tira.classList.toggle('wa-adjunto-error', Boolean(errorArchivo));
        tira.hidden = false;
        actualizarEnviar();
    }

    archivo.addEventListener('change', function () {
        pintarTira();
        caja.focus();
    });

    // --- Botón de enviar (reglas 19 y 20) ---

    function hayQueMandar() {
        const conArchivo = archivo.files && archivo.files.length > 0;

        if (conArchivo && errorArchivo) {
            return false;
        }

        return conArchivo || caja.value.trim() !== '';
    }

    function actualizarEnviar() {
        if (enviando) {
            return;
        }

        enviar.setAttribute('aria-disabled', hayQueMandar() ? 'false' : 'true');
    }

    formulario.addEventListener('submit', function (evento) {
        if (enviando || !hayQueMandar()) {
            evento.preventDefault();

            return;
        }

        enviando = true;
        enviar.setAttribute('aria-disabled', 'true');
        enviar.classList.add('wa-enviando');
        enviar.setAttribute('aria-label', 'Enviando…');

        const iconoEnviar = enviar.querySelector('.icono');

        if (iconoEnviar) {
            iconoEnviar.className = 'bi bi-arrow-repeat icono';
        }

        caja.readOnly = true;
        abrirMenu.disabled = true;
    });

    actualizarEnviar();
    pintarTira();
})();
