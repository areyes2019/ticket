/*
 * Service worker de APAGADO de la PWA anterior.
 *
 * Esta aplicación no registra ningún service worker. Pero antes de ella,
 * este mismo dominio servía el sistema "facturacion" como PWA, y quien la
 * tenga instalada conserva registrado su service worker, que sigue sirviendo la
 * interfaz vieja desde su caché sin que la petición llegue nunca al servidor.
 *
 * Cuando el navegador comprueba si hay versión nueva, pide este archivo. Al ser
 * distinto del anterior, se instala, y este es todo su trabajo: borrar las
 * cachés, desregistrarse y recargar las ventanas abiertas. A partir de ahí el
 * origen queda limpio y la ventana carga esta aplicación como cualquier visitante.
 *
 * Se ejecuta una sola vez por dispositivo. Después de eso el archivo sigue aquí
 * para los que todavía no hayan abierto la aplicación instalada.
 */

self.addEventListener('install', () => {
    // Sin esperar a que se cierren las ventanas de la versión anterior: el
    // objetivo es justamente reemplazar a un service worker que está sirviendo
    // una aplicación que ya no existe.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const nombres = await caches.keys();
        await Promise.all(nombres.map((nombre) => caches.delete(nombre)));

        await self.registration.unregister();

        // Recargar las ventanas abiertas. Sin esto, la pestaña que ya estaba
        // abierta sigue mostrando la aplicación vieja hasta que el usuario la
        // recargue a mano, y lo que ve es una interfaz que no responde.
        const ventanas = await self.clients.matchAll({ type: 'window' });
        for (const ventana of ventanas) {
            ventana.navigate(ventana.url);
        }
    })());
});
