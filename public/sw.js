/*
 * Service worker de la aplicación de mostrador (033).
 *
 * Hace dos cosas, y nada más:
 *
 * 1. Termina el APAGADO de la PWA anterior. Antes de esta aplicación, este
 *    mismo dominio servía el sistema "facturacion" como PWA, y quien la tenga
 *    instalada conserva su service worker sirviendo la interfaz vieja desde su
 *    caché. Cuando el navegador pide este archivo y lo encuentra distinto, lo
 *    instala; al activarse borra toda caché que no sea la suya y toma las
 *    ventanas abiertas. (La versión anterior de este archivo además se
 *    desregistraba; ahora se queda porque la aplicación sí lo usa.)
 *
 * 2. Avisa sin conexión. Las navegaciones van SIEMPRE a la red; si la red
 *    falla, responde el aviso sin-conexion.html guardado al instalarse.
 *
 * Nunca guarda una respuesta del sistema: ni páginas, ni PDF, ni tickets, ni
 * tarjetas. Cada pantalla la pinta el servidor en cada visita, así que no hay
 * versión vieja que servir ni datos de otra sesión que mostrar.
 *
 * Sube VERSION cuando cambien sin-conexion.html o los iconos.
 */

const VERSION = 1;
const CACHE = 'mostrador-' + VERSION;
const ARCHIVOS = ['/sin-conexion.html', '/img/pwa/icono-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(ARCHIVOS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const nombres = await caches.keys();
        await Promise.all(nombres.filter((nombre) => nombre !== CACHE).map((nombre) => caches.delete(nombre)));

        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const peticion = event.request;

    // El icono del aviso sin conexión: de la caché si no hay red.
    if (peticion.method === 'GET' && new URL(peticion.url).pathname === '/img/pwa/icono-192.png') {
        event.respondWith(fetch(peticion).catch(() => caches.match('/img/pwa/icono-192.png')));
        return;
    }

    if (peticion.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(peticion).catch(() => caches.match('/sin-conexion.html')));
});
