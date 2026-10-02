// Bandeja de correo de demostración del dashboard (spec 013).
//
// Todo ocurre en el navegador: filtrar carpetas y etiquetas, buscar, abrir,
// destacar y marcar como no leído. Nada se envía ni se guarda; al recargar la
// bandeja vuelve a su estado inicial. Los correos ya vienen en el HTML.
(function () {
    const bandeja = document.querySelector('[data-bandeja]');

    if (!bandeja) {
        return;
    }

    const filas = Array.from(bandeja.querySelectorAll('[data-correo]'));
    const visores = Array.from(bandeja.querySelectorAll('[data-visor]'));
    const opciones = Array.from(bandeja.querySelectorAll('[data-filtro]'));
    // Incluye la insignia del sobre en el menú de aplicaciones, fuera de la bandeja.
    const contadores = Array.from(document.querySelectorAll('[data-contador]'));
    const buscador = bandeja.querySelector('[data-buscar]');
    const vacia = bandeja.querySelector('[data-vacia]');
    const sinSeleccion = bandeja.querySelector('[data-sin-seleccion]');
    const botonesCarpetas = Array.from(bandeja.querySelectorAll('[data-mostrar-carpetas]'));
    const redactar = document.getElementById('bandeja-redactar');
    const aviso = document.querySelector('[data-bandeja-aviso]');
    // En escritorio "Carpetas" pliega la columna; en tableta y celular la despliega encima.
    const escritorio = window.matchMedia('(min-width: 1024px)');

    const filtro = { tipo: 'carpeta', valor: 'entrada' };
    let activa = filas.find(function (fila) {
        return fila.classList.contains('bandeja-fila-activa');
    }) || null;
    let temporizadorAviso = null;

    // Sin acentos ni mayúsculas, para que "cotizacion" encuentre "Cotización".
    function normalizar(texto) {
        return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function enCarpeta(fila, carpeta) {
        if (carpeta === 'destacados') {
            return fila.dataset.destacado === '1';
        }

        if (carpeta === 'importantes') {
            return fila.dataset.importante === '1';
        }

        return fila.dataset.carpeta === carpeta;
    }

    function coincide(fila, texto) {
        const enFiltro = filtro.tipo === 'etiqueta'
            ? fila.dataset.etiqueta === filtro.valor
            : enCarpeta(fila, filtro.valor);

        return enFiltro && normalizar(fila.dataset.texto).includes(texto);
    }

    function actualizarContadores() {
        contadores.forEach(function (contador) {
            const total = filas.filter(function (fila) {
                return fila.dataset.leido === '0' && enCarpeta(fila, contador.dataset.contador);
            }).length;

            contador.textContent = total;
            contador.hidden = total === 0;
        });
    }

    // Muestra el correo en el visor. Solo un clic lo marca como leído: al
    // cambiar de carpeta se muestra el primero sin tocar su estado.
    function mostrar(fila, marcarLeido) {
        activa = fila;

        filas.forEach(function (otra) {
            otra.classList.toggle('bandeja-fila-activa', otra === fila);
        });

        visores.forEach(function (visor) {
            visor.hidden = !fila || visor.dataset.visor !== fila.dataset.correo;
        });

        sinSeleccion.hidden = Boolean(fila);

        if (fila && marcarLeido) {
            fila.dataset.leido = '1';
            fila.classList.remove('bandeja-fila-no-leida');
            actualizarContadores();
        }
    }

    function filtrar() {
        const texto = normalizar(buscador.value.trim());
        const visibles = filas.filter(function (fila) {
            fila.hidden = !coincide(fila, texto);

            return !fila.hidden;
        });

        vacia.hidden = visibles.length > 0;

        if (!activa || activa.hidden) {
            mostrar(visibles[0] || null, false);
            bandeja.classList.remove('bandeja-leyendo');
        }
    }

    function elegirOpcion(opcion) {
        filtro.tipo = opcion.dataset.filtro;
        filtro.valor = opcion.dataset.valor;

        opciones.forEach(function (otra) {
            const elegida = otra === opcion;

            otra.classList.toggle('bandeja-opcion-activa', elegida);
            otra.setAttribute('aria-pressed', elegida ? 'true' : 'false');
        });

        activa = null;
        filtrar();

        if (!escritorio.matches) {
            mostrarCarpetas(false);
        }
    }

    function carpetasVisibles() {
        return escritorio.matches
            ? !bandeja.classList.contains('bandeja-carpetas-ocultas')
            : bandeja.classList.contains('bandeja-carpetas-abiertas');
    }

    function actualizarBotonCarpetas() {
        botonesCarpetas.forEach(function (boton) {
            boton.setAttribute('aria-expanded', carpetasVisibles() ? 'true' : 'false');
        });
    }

    function mostrarCarpetas(visibles) {
        if (escritorio.matches) {
            bandeja.classList.toggle('bandeja-carpetas-ocultas', !visibles);
        } else {
            bandeja.classList.toggle('bandeja-carpetas-abiertas', visibles);
        }

        actualizarBotonCarpetas();
    }

    function avisar() {
        aviso.textContent = 'Esto es una demostración: no se envió ni se guardó nada.';
        aviso.hidden = false;

        clearTimeout(temporizadorAviso);
        temporizadorAviso = setTimeout(function () {
            aviso.hidden = true;
        }, 3000);
    }

    function alternarEstrella(fila) {
        const destacado = fila.dataset.destacado !== '1';
        const boton = fila.querySelector('[data-estrella]');
        const icono = boton.querySelector('.bi');

        fila.dataset.destacado = destacado ? '1' : '0';
        boton.setAttribute('aria-pressed', destacado ? 'true' : 'false');
        icono.classList.toggle('bi-star-fill', destacado);
        icono.classList.toggle('bi-star', !destacado);

        actualizarContadores();

        if (filtro.valor === 'destacados') {
            filtrar();
        }
    }

    bandeja.addEventListener('click', function (evento) {
        const objetivo = evento.target.closest('button');

        if (!objetivo) {
            return;
        }

        if (objetivo.matches('[data-filtro]')) {
            elegirOpcion(objetivo);
        } else if (objetivo.matches('[data-estrella]')) {
            alternarEstrella(objetivo.closest('[data-correo]'));
        } else if (objetivo.matches('[data-abrir]')) {
            mostrar(objetivo.closest('[data-correo]'), true);
            bandeja.classList.add('bandeja-leyendo');
        } else if (objetivo.matches('[data-volver]')) {
            bandeja.classList.remove('bandeja-leyendo');
        } else if (objetivo.matches('[data-marcar-no-leido]') && activa) {
            activa.dataset.leido = '0';
            activa.classList.add('bandeja-fila-no-leida');
            actualizarContadores();
        } else if (objetivo.matches('[data-demo]')) {
            avisar();
        } else if (objetivo.matches('[data-mostrar-carpetas]')) {
            mostrarCarpetas(!carpetasVisibles());
        } else if (objetivo.matches('[data-bandeja-redactar]') && redactar && typeof redactar.showModal === 'function') {
            redactar.showModal();
        }
    });

    buscador.addEventListener('input', filtrar);

    actualizarBotonCarpetas();
    escritorio.addEventListener('change', actualizarBotonCarpetas);

    if (redactar) {
        redactar.addEventListener('close', function () {
            if (redactar.returnValue === 'enviar') {
                avisar();
            }

            redactar.returnValue = '';
            redactar.querySelector('form').reset();
        });

        // Un clic en el fondo (fuera del contenido) cierra la ventana.
        redactar.addEventListener('click', function (evento) {
            if (evento.target === redactar) {
                redactar.close();
            }
        });
    }
})();
