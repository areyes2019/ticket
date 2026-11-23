// Respuestas rápidas en el chat de WhatsApp (043). Teclear "/" al inicio, o
// después de un espacio o salto de línea, abre una lista con las respuestas
// guardadas; se filtra con lo que sigue y al elegir cambia "/atajo" por el
// texto, con {nombre} y {fecha} ya llenos. Nunca envía el mensaje.
// La lista viaja en la página (adición 1); sin JavaScript no hay lista y la
// caja funciona igual (adición 2). Patrón combobox de WAI-ARIA (adición 3).
(function () {
    const caja = document.getElementById('wa-texto');
    const datos = document.getElementById('wa-respuestas-rapidas');
    const lista = document.getElementById('wa-respuestas-lista');
    const aviso = document.getElementById('wa-respuestas-aviso');

    if (!caja || !datos || !lista) {
        return;
    }

    let config;

    try {
        config = JSON.parse(datos.textContent);
    } catch (error) {
        return;
    }

    const respuestas = (config.respuestas || []).map(function (respuesta) {
        return {
            atajo: respuesta.atajo,
            texto: respuesta.texto,
            atajoPlano: plano(respuesta.atajo),
            textoPlano: plano(respuesta.texto),
        };
    });

    const VISTA_PREVIA = 60;
    let visibles = [];
    let marcada = 0;
    let tramo = null; // {inicio, fin}: posición de "/atajo" en la caja.

    caja.setAttribute('aria-autocomplete', 'list');
    caja.setAttribute('aria-controls', lista.id);
    caja.setAttribute('aria-expanded', 'false');

    // Sin mayúsculas ni acentos (regla 7).
    function plano(texto) {
        return String(texto).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    // Regla 6: la "/" debe ir al inicio o tras espacio/salto de línea, y entre
    // ella y el cursor no puede haber espacios.
    function buscarTramo() {
        if (caja.selectionStart !== caja.selectionEnd) {
            return null;
        }

        const cursor = caja.selectionStart;
        const antes = caja.value.slice(0, cursor);
        const coincidencia = antes.match(/(^|\s)\/([^\s\/]*)$/);

        if (!coincidencia) {
            return null;
        }

        return { inicio: cursor - coincidencia[2].length - 1, fin: cursor, consulta: coincidencia[2] };
    }

    // Regla 7: primero los atajos que empiezan con lo escrito, luego los que
    // lo tienen en el texto; cada grupo en orden alfabético (ya vienen así).
    function filtrar(consulta) {
        const buscado = plano(consulta);

        if (buscado === '') {
            return respuestas.slice();
        }

        const empiezan = respuestas.filter(function (r) { return r.atajoPlano.startsWith(buscado); });
        const contienen = respuestas.filter(function (r) {
            return !r.atajoPlano.startsWith(buscado) && (r.atajoPlano.includes(buscado) || r.textoPlano.includes(buscado));
        });

        return empiezan.concat(contienen);
    }

    function vistaPrevia(texto) {
        const linea = texto.replace(/\s+/g, ' ').trim();

        return linea.length > VISTA_PREVIA ? linea.slice(0, VISTA_PREVIA) + '…' : linea;
    }

    function pintar() {
        lista.replaceChildren();

        if (visibles.length === 0) {
            const vacia = document.createElement('div');
            vacia.className = 'wa-respuestas-vacia';
            vacia.textContent = 'Sin respuestas guardadas. ';

            const enlace = document.createElement('a');
            enlace.href = datos.dataset.configuracion;
            enlace.target = '_blank';
            enlace.rel = 'noopener';
            enlace.textContent = 'Agregar respuestas en Configuración';
            vacia.appendChild(enlace);

            lista.appendChild(vacia);
            caja.removeAttribute('aria-activedescendant');
            anunciar('Sin respuestas guardadas.');

            return;
        }

        visibles.forEach(function (respuesta, indice) {
            const opcion = document.createElement('div');
            opcion.className = 'wa-respuesta';
            opcion.id = 'wa-respuesta-' + indice;
            opcion.setAttribute('role', 'option');
            opcion.setAttribute('aria-selected', indice === marcada ? 'true' : 'false');

            const atajo = document.createElement('strong');
            atajo.textContent = '/' + respuesta.atajo;

            const texto = document.createElement('span');
            texto.textContent = vistaPrevia(respuesta.texto);

            opcion.append(atajo, texto);

            // mousedown con preventDefault: la caja no pierde el foco y el
            // clic alcanza a registrarse antes de que blur cierre la lista.
            opcion.addEventListener('mousedown', function (evento) {
                evento.preventDefault();
            });
            opcion.addEventListener('click', function () {
                elegir(indice);
            });

            lista.appendChild(opcion);
        });

        marcar(marcada);

        const total = visibles.length;
        anunciar(total + (total === 1 ? ' respuesta rápida' : ' respuestas rápidas') + '. Usa las flechas para elegir.');
    }

    function marcar(indice) {
        marcada = indice;

        Array.prototype.forEach.call(lista.querySelectorAll('[role="option"]'), function (opcion, i) {
            opcion.setAttribute('aria-selected', i === indice ? 'true' : 'false');
        });

        const actual = document.getElementById('wa-respuesta-' + indice);

        if (actual) {
            caja.setAttribute('aria-activedescendant', actual.id);
            actual.scrollIntoView({ block: 'nearest' });
        }
    }

    let ultimoAviso = '';

    function anunciar(texto) {
        if (aviso && texto !== ultimoAviso) {
            aviso.textContent = texto;
            ultimoAviso = texto;
        }
    }

    function abierta() {
        return !lista.hidden;
    }

    function abrir() {
        lista.hidden = false;
        caja.setAttribute('aria-expanded', 'true');
    }

    function cerrar() {
        if (!abierta()) {
            return;
        }

        lista.hidden = true;
        lista.replaceChildren();
        caja.setAttribute('aria-expanded', 'false');
        caja.removeAttribute('aria-activedescendant');
        tramo = null;
        visibles = [];
        ultimoAviso = '';
    }

    function revisar() {
        const encontrado = buscarTramo();

        if (!encontrado) {
            cerrar();

            return;
        }

        const cambio = !tramo || tramo.consulta !== encontrado.consulta;
        tramo = encontrado;

        if (cambio || !abierta()) {
            visibles = filtrar(encontrado.consulta);
            marcada = 0;
            abrir();
            pintar();
        }
    }

    // Regla 4: {nombre} y {fecha}, en minúsculas; lo demás se deja igual.
    function llenar(texto) {
        return texto.replace(/\{nombre\}/g, config.nombre || '').replace(/\{fecha\}/g, config.fecha || '');
    }

    // Regla 10: cambia el tramo "/atajo" por el texto, cursor al final, sin
    // enviar nada.
    function elegir(indice) {
        const respuesta = visibles[indice];

        if (!respuesta || !tramo) {
            return;
        }

        const texto = llenar(respuesta.texto);
        caja.focus();
        caja.setRangeText(texto, tramo.inicio, tramo.fin, 'end');
        cerrar();
        caja.dispatchEvent(new Event('input', { bubbles: true }));
    }

    caja.addEventListener('input', revisar);
    caja.addEventListener('click', revisar);
    caja.addEventListener('keyup', function (evento) {
        if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(evento.key)) {
            revisar();
        }
    });
    caja.addEventListener('blur', cerrar);

    // Regla 9. Se registra antes que whatsapp.js (Enter envía), y además
    // marca el evento con preventDefault para que aquel no lo envíe.
    caja.addEventListener('keydown', function (evento) {
        if (!abierta()) {
            return;
        }

        if (evento.key === 'Escape') {
            evento.preventDefault();
            evento.stopPropagation();
            cerrar();

            return;
        }

        if (visibles.length === 0) {
            if (evento.key === 'Enter' && !evento.shiftKey) {
                // Sin coincidencias, Enter sigue enviando como siempre.
                cerrar();
            }

            return;
        }

        if (evento.key === 'ArrowDown') {
            evento.preventDefault();
            marcar((marcada + 1) % visibles.length);
        } else if (evento.key === 'ArrowUp') {
            evento.preventDefault();
            marcar((marcada - 1 + visibles.length) % visibles.length);
        } else if ((evento.key === 'Enter' && !evento.shiftKey) || (evento.key === 'Tab' && !evento.shiftKey)) {
            evento.preventDefault();
            elegir(marcada);
        }
    });
})();
