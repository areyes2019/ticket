// Acordeón "Documentos" del dashboard (spec 020, corrección 1): Cotizaciones y
// Facturas son <details data-seccion> que se abren y cierran por separado.
//
// Sin JavaScript manda lo que pinta el servidor (Cotizaciones abierta y
// Facturas cerrada, salvo que la abierta sea una factura). Con él, lo que el
// usuario abrió o cerró se recuerda en este navegador. La sección que tiene el
// documento abierto en el visor siempre se ve.
(function () {
    const CLAVE = 'dashboard-secciones';
    const secciones = document.querySelectorAll('details[data-seccion]');

    if (secciones.length === 0) {
        return;
    }

    function leer() {
        try {
            return JSON.parse(localStorage.getItem(CLAVE)) || {};
        } catch (error) {
            return {};
        }
    }

    function guardar(estado) {
        try {
            localStorage.setItem(CLAVE, JSON.stringify(estado));
        } catch (error) {
            // Sin almacenamiento (ventana privada, sitio bloqueado): no se recuerda.
        }
    }

    const guardado = leer();

    secciones.forEach(function (seccion) {
        const nombre = seccion.dataset.seccion;

        if (typeof guardado[nombre] === 'boolean' && !seccion.querySelector('.bandeja-fila-activa')) {
            seccion.open = guardado[nombre];
        }

        seccion.addEventListener('toggle', function () {
            const estado = leer();
            estado[nombre] = seccion.open;
            guardar(estado);
        });
    });
})();
