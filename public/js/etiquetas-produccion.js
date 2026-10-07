// Etiquetas de producción (030, 031): vista previa en vivo de las medidas de
// la planilla, "Centrar", y el ajuste de letra de cada renglón que no cabe.
//
// distribucion() y centrar() son espejo exacto de MedidasPlanilla (PHP): las
// dos recorren tests/Fixtures/planillas-etiquetas.json. Se cuenta en décimas
// de milímetro para que las divisiones sean enteras.
(function (raiz) {
    // 7 pt en píxeles CSS.
    const MINIMO_PX = 7 * 96 / 72;

    // Hoja carta en décimas de milímetro.
    const HOJA_ANCHO = 2159;
    const HOJA_ALTO = 2794;
    const MARGEN_MAXIMO = 1000;

    // El tamaño con el que un texto de anchoTexto cabe en anchoDisponible,
    // en pasos de medio punto (en la misma unidad que tamano) y nunca menor
    // que minimo. El ancho del texto crece en proporción a la letra.
    function tamanoQueCabe(tamano, anchoTexto, anchoDisponible, minimo, paso) {
        if (anchoTexto <= anchoDisponible || anchoTexto <= 0) {
            return tamano;
        }

        const proporcional = Math.floor(tamano * anchoDisponible / anchoTexto / paso) * paso;

        return Math.max(minimo, proporcional);
    }

    function caben(espacio, tamano, separacion) {
        return Math.max(0, Math.floor((espacio + separacion) / (tamano + separacion)));
    }

    // Medidas en décimas de milímetro → { columnas, renglones, porHoja }.
    function distribucion(m) {
        const columnas = caben(HOJA_ANCHO - m.margen_izquierdo, m.ancho, m.separacion_horizontal);
        const renglones = caben(HOJA_ALTO - m.margen_superior, m.alto, m.separacion_vertical);

        return { columnas: columnas, renglones: renglones, porHoja: columnas * renglones };
    }

    function margenCentrado(hoja, cuantas, tamano, separacion) {
        if (cuantas === 0) {
            return 0;
        }

        const bloque = cuantas * tamano + (cuantas - 1) * separacion;

        return Math.min(MARGEN_MAXIMO, Math.max(0, Math.floor((hoja - bloque) / 2)));
    }

    // Los márgenes (en décimas) que dejan en medio de la hoja el bloque que se
    // ve ahora; si no cabe nada, lo que cabría con margen 0.
    function centrar(m) {
        const actual = distribucion(m);
        const columnas = actual.columnas || caben(HOJA_ANCHO, m.ancho, m.separacion_horizontal);
        const renglones = actual.renglones || caben(HOJA_ALTO, m.alto, m.separacion_vertical);

        return {
            margen_superior: margenCentrado(HOJA_ALTO, renglones, m.alto, m.separacion_vertical),
            margen_izquierdo: margenCentrado(HOJA_ANCHO, columnas, m.ancho, m.separacion_horizontal),
        };
    }

    const EtiquetasProduccion = { tamanoQueCabe: tamanoQueCabe, distribucion: distribucion, centrar: centrar, MINIMO_PX: MINIMO_PX };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = EtiquetasProduccion;
    }

    if (typeof document === 'undefined') {
        return;
    }

    // Medio punto en píxeles CSS.
    const PASO_PX = 0.5 * 96 / 72;

    function ajustarLetra() {
        document.querySelectorAll('.planilla-etiqueta p').forEach(function (renglon) {
            renglon.style.fontSize = '';

            let tamano = parseFloat(window.getComputedStyle(renglon).fontSize);

            tamano = tamanoQueCabe(tamano, renglon.scrollWidth, renglon.clientWidth, MINIMO_PX, PASO_PX);
            renglon.style.fontSize = tamano + 'px';

            // La proporción es aproximada: se termina de bajar paso a paso.
            while (renglon.scrollWidth > renglon.clientWidth && tamano > MINIMO_PX) {
                tamano = Math.max(MINIMO_PX, tamano - PASO_PX);
                renglon.style.fontSize = tamano + 'px';
            }
        });
    }

    function plural(n, singular, varios) {
        return n + ' ' + (n === 1 ? singular : varios);
    }

    document.querySelectorAll('[data-imprimir]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            window.print();
        });
    });

    // Esta página no carga app.js: la confirmación de "Eliminar" vive aquí.
    document.addEventListener('submit', function (evento) {
        const boton = evento.submitter;

        if (boton && boton.dataset.confirmar && !window.confirm(boton.dataset.confirmar)) {
            evento.preventDefault();
        }
    });

    document.querySelectorAll('[data-cargar-formato]').forEach(function (select) {
        select.addEventListener('change', function () {
            select.form.submit();
        });
    });

    const formulario = document.getElementById('planilla-medidas');
    const planilla = document.getElementById('planilla');

    if (formulario && planilla) {
        const campos = Array.from(formulario.querySelectorAll('[data-medida]'));
        const inicio = formulario.elements.inicio;
        const resumen = document.getElementById('planilla-distribucion');
        const aviso = document.getElementById('planilla-aviso');
        // Las etiquetas se pintan una vez; al cambiar las medidas se reparten de nuevo.
        const etiquetas = Array.from(planilla.querySelectorAll('.planilla-etiqueta'));

        function decimas(campo) {
            return Math.round(parseFloat(campo.value) * 10);
        }

        // Las medidas en décimas, o null si alguna está vacía o fuera de rango.
        function leerMedidas() {
            const medidas = {};

            for (const campo of campos) {
                if (campo.value === '' || !campo.checkValidity()) {
                    return null;
                }

                medidas[campo.name] = decimas(campo);
            }

            return medidas;
        }

        function repartir(porHoja, primera) {
            planilla.replaceChildren();

            if (porHoja === 0 || etiquetas.length === 0) {
                return 0;
            }

            const casillas = [];

            for (let i = 1; i < primera; i++) {
                const vacia = document.createElement('div');
                vacia.className = 'planilla-vacia';
                casillas.push(vacia);
            }

            casillas.push.apply(casillas, etiquetas);

            let hojas = 0;

            for (let i = 0; i < casillas.length; i += porHoja) {
                const hoja = document.createElement('div');
                hoja.className = 'planilla-hoja';
                hoja.append.apply(hoja, casillas.slice(i, i + porHoja));
                planilla.append(hoja);
                hojas++;
            }

            return hojas;
        }

        function actualizarDireccion() {
            const parametros = new URLSearchParams(window.location.search);

            campos.forEach(function (campo) {
                parametros.set(campo.name, campo.value);
            });
            parametros.set('inicio', inicio.value);
            parametros.set('formato', formulario.elements.formato.value);

            window.history.replaceState(null, '', window.location.pathname + '?' + parametros.toString());
        }

        function actualizar() {
            const medidas = leerMedidas();

            if (medidas === null) {
                return;
            }

            const dist = distribucion(medidas);
            const estilo = planilla.style;

            estilo.setProperty('--ancho', medidas.ancho / 10 + 'mm');
            estilo.setProperty('--alto', medidas.alto / 10 + 'mm');
            estilo.setProperty('--sep-h', medidas.separacion_horizontal / 10 + 'mm');
            estilo.setProperty('--sep-v', medidas.separacion_vertical / 10 + 'mm');
            estilo.setProperty('--margen-sup', medidas.margen_superior / 10 + 'mm');
            estilo.setProperty('--margen-izq', medidas.margen_izquierdo / 10 + 'mm');
            estilo.setProperty('--columnas', String(Math.max(1, dist.columnas)));
            estilo.setProperty('--renglones', String(Math.max(1, dist.renglones)));

            inicio.max = String(Math.max(1, dist.porHoja));

            let primera = parseInt(inicio.value, 10);

            if (!(primera >= 1 && primera <= dist.porHoja)) {
                primera = 1;
            }

            const hojas = repartir(dist.porHoja, primera);

            aviso.hidden = dist.porHoja > 0;
            resumen.textContent = dist.columnas + ' × ' + dist.renglones + ' = ' + dist.porHoja + ' por hoja'
                + (hojas > 0 ? ' · ' + plural(hojas, 'hoja', 'hojas') : '');

            actualizarDireccion();
            ajustarLetra();
        }

        campos.concat([inicio]).forEach(function (campo) {
            campo.addEventListener('input', actualizar);
        });

        formulario.querySelectorAll('[data-centrar]').forEach(function (boton) {
            boton.addEventListener('click', function (evento) {
                const medidas = leerMedidas();

                evento.preventDefault();

                if (medidas === null) {
                    return;
                }

                const margenes = centrar(medidas);

                formulario.elements.margen_superior.value = (margenes.margen_superior / 10).toFixed(1);
                formulario.elements.margen_izquierdo.value = (margenes.margen_izquierdo / 10).toFixed(1);
                actualizar();
            });
        });
    }

    window.addEventListener('beforeprint', ajustarLetra);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ajustarLetra);
    } else {
        ajustarLetra();
    }
})(typeof window !== 'undefined' ? window : this);
