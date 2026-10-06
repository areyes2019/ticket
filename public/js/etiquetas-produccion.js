// Etiquetas de producción (030): cada renglón que no cabe en su etiqueta
// achica su letra hasta un mínimo legible; si aun así no cabe, el CSS lo corta
// con "…". También el botón "Imprimir" de la barra.
(function (raiz) {
    // 7 pt en píxeles CSS.
    const MINIMO_PX = 7 * 96 / 72;

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

    const EtiquetasProduccion = { tamanoQueCabe, MINIMO_PX };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = EtiquetasProduccion;
    }

    if (typeof document === 'undefined') {
        return;
    }

    // Medio punto en píxeles CSS.
    const PASO_PX = 0.5 * 96 / 72;

    function ajustar() {
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

    document.querySelectorAll('[data-imprimir]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            window.print();
        });
    });

    window.addEventListener('beforeprint', ajustar);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ajustar);
    } else {
        ajustar();
    }
})(typeof window !== 'undefined' ? window : this);
