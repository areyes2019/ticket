# Spec: Inicio de proyecto — Laravel + Blade + JavaScript nativo

## Historia de usuario

Como desarrollador, quiero iniciar un proyecto de facturación utilizando Laravel como aplicación principal, Blade para las vistas y JavaScript nativo para las interacciones dinámicas, de manera que el sistema pueda ejecutarse directamente en Laragon sin depender de un frontend separado ni de procesos de compilación.

## Objetivo

Crear la base limpia del sistema de facturación utilizando una arquitectura monolítica Laravel.

Laravel será responsable de:

* Backend.
* Rutas.
* Controladores.
* Vistas Blade.
* Acceso a MySQL.
* Lógica de negocio.
* Validaciones.
* Respuestas JSON cuando sean necesarias.

JavaScript se utilizará únicamente para mejorar la interacción de las páginas.

Axios podrá utilizarse para peticiones AJAX cuando una operación necesite actualizar información sin recargar toda la página.

## Arquitectura

La aplicación tendrá una sola aplicación Laravel:

```text
Laravel
│
├── routes/
│   └── web.php
│
├── app/
│   ├── Models/
│   ├── Http/
│   │   └── Controllers/
│   └── Services/
│
├── resources/
│   └── views/
│       └── *.blade.php
│
├── public/
│   ├── css/
│   │   └── *.css
│   ├── js/
│   │   └── *.js
│   └── vendor/
│       └── axios.min.js
│
└── database/
```

El JavaScript y CSS propios se ubican en `public/js/` y `public/css/`, no en `resources/`, porque Laravel solo sirve al navegador los archivos de `public/` sin un proceso de compilación.

No se creará un frontend independiente.

No existirán las carpetas `backend/` y `frontend/`.

No se utilizará Vue.

No se utilizará Inertia.

No se construirá una SPA.

## Laravel

* Utilizar siempre la última versión estable disponible al momento de crear el proyecto.
* Utilizar Laragon como entorno de desarrollo local.
* Utilizar MySQL.
* Utilizar Blade como sistema de vistas.
* Utilizar las rutas web normales de Laravel.
* Utilizar controladores y servicios para organizar la lógica.
* Utilizar validaciones de Laravel.
* Utilizar Eloquent para trabajar con la base de datos.
* Utilizar Laravel Pint para mantener el código PHP consistente.
* Utilizar Pest para pruebas cuando corresponda.

## Frontend

El frontend será generado directamente por Laravel mediante Blade.

Se utilizará:

* HTML.
* CSS.
* JavaScript nativo.
* Axios cuando sea necesario realizar peticiones AJAX.

No se utilizará:

* Vue.
* React.
* Angular.
* Pinia.
* Vue Router.
* TypeScript.
* Vite como requisito del frontend.
* SPA.
* Node.js para ejecutar la aplicación.

## JavaScript

JavaScript tendrá una función complementaria.

Regla general:

> Si una interacción puede resolverse fácilmente con Laravel + Blade, se resolverá de esa manera.

JavaScript se utilizará cuando aporte una mejora real a la experiencia de usuario.

Ejemplos:

* Buscar clientes sin recargar la página.
* Autocompletar RFC.
* Agregar productos dinámicamente.
* Actualizar totales.
* Abrir/cerrar modales.
* Validaciones inmediatas de interfaz.
* Actualizar una tabla mediante AJAX.
* Consultar información al servidor sin abandonar la pantalla.
* Mostrar mensajes de éxito o error.

Cuando sea necesario comunicarse con Laravel desde JavaScript se podrá utilizar Axios.

Ejemplo:

```javascript
axios.get('/clientes/buscar', {
    params: {
        q: texto
    }
})
.then(response => {
    // Actualizar la interfaz
})
.catch(error => {
    // Mostrar error
});
```

Las peticiones AJAX devolverán JSON cuando corresponda.

No se creará una API REST independiente únicamente para ser consumida por el propio frontend.

## Recursos estáticos

El proyecto debe poder ejecutarse directamente desde Laragon.

No se deberá depender de:

```bash
npm run dev
```

ni de:

```bash
npm run build
```

para que la aplicación funcione.

Los archivos JavaScript y CSS necesarios para la aplicación deberán poder cargarse directamente desde Laravel.

Reglas:

* El JavaScript propio se ubicará en `public/js/`.
* El CSS propio se ubicará en `public/css/`.
* Las librerías de terceros se ubicarán en `public/vendor/`.
* Las vistas Blade cargarán estos archivos con `asset()`:

```blade
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<script src="{{ asset('vendor/axios.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}"></script>
```

* No se utilizará la directiva `@vite(...)`.

### Axios

Axios se incluirá como copia local del archivo minificado en `public/vendor/axios.min.js`.

No se cargará desde un CDN, para que la aplicación funcione sin conexión a internet y no dependa de servicios externos.

No se instalará mediante npm.

Las peticiones POST, PUT y DELETE enviarán el token CSRF de Laravel. El layout Blade incluirá:

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
```

y el JavaScript configurará Axios para enviarlo en el encabezado `X-CSRF-TOKEN`.

### Archivos de Vite del proyecto base

La instalación estándar de Laravel incluye archivos para Vite. Como no se utilizarán, se eliminarán:

* `vite.config.js`
* `package.json`
* `resources/js/`
* `resources/css/`
* Referencias a `@vite(...)` en las vistas.

Si alguna dependencia frontend requiere npm, deberá evaluarse primero si realmente es necesaria. Se evitarán dependencias que introduzcan una cadena de compilación innecesaria.

## Autenticación

La autenticación no forma parte de esta historia.

No se implementará:

* Login.
* Registro.
* Recuperación de contraseña.
* Roles.
* Permisos.

La arquitectura deberá mantenerse compatible con la autenticación estándar de Laravel para implementarla posteriormente.

No se instalará Sanctum únicamente por previsión de una futura SPA o aplicación móvil.

Si posteriormente se necesita una API externa para una aplicación móvil, se diseñará en ese momento según las necesidades reales del proyecto.

## API / AJAX

Laravel no será tratado inicialmente como un backend API independiente.

Las rutas principales serán rutas web normales:

```text
GET  /clientes
POST /clientes
PUT  /clientes/{cliente}
DELETE /clientes/{cliente}
```

Cuando una pantalla necesite AJAX, se podrán crear endpoints específicos:

```text
GET  /clientes/buscar
POST /facturas/calcular
POST /facturas/guardar
```

Estos endpoints podrán devolver JSON.

La existencia de endpoints AJAX no convierte al proyecto en una aplicación API-first.

## Base de datos

* MySQL.
* Migraciones de Laravel.
* Eloquent.
* Seeders y factories cuando sean necesarios.

No se crearán tablas relacionadas con facturación en esta historia.

## Alcance de esta historia

Esta historia solamente prepara el proyecto.

No se implementará todavía:

* Facturación.
* Clientes.
* Productos.
* CFDI.
* Timbrado.
* Proveedores.
* Inventario.
* Usuarios.
* Login.
* Roles.
* Permisos.

## Fuera de alcance

No se implementará:

* Vue.
* SPA.
* Inertia.
* Capacitor.
* PWA.
* Sanctum.
* API REST completa.
* Docker/Sail.
* Sistema de autenticación.
* Lógica de negocio de facturación.

## Criterios de aceptación

1. El proyecto Laravel se crea utilizando la última versión estable disponible.

2. El proyecto funciona directamente desde Laragon.

3. Una ruta Laravel puede mostrar una vista Blade correctamente.

4. Una vista Blade puede cargar JavaScript y CSS propios desde `public/` mediante `asset()`.

5. JavaScript puede ejecutar operaciones normales de interfaz sin necesidad de un framework.

6. Axios, cargado desde `public/vendor/axios.min.js`, puede realizar una petición AJAX a Laravel y recibir una respuesta JSON.

7. Una petición POST mediante Axios es aceptada por Laravel enviando el token CSRF.

8. La aplicación funciona sin ejecutar:

   ```bash
   npm run dev
   ```

9. La aplicación funciona sin ejecutar:

   ```bash
   npm run build
   ```

10. La aplicación funciona sin conexión a internet (no depende de CDN).

11. No existen `vite.config.js`, `package.json` ni referencias a `@vite(...)` en las vistas.

12. No existe un proyecto Vue separado.

13. No existe una carpeta `frontend/`.

14. No existe una carpeta `backend/`.

15. No se instala ninguna dependencia cuya única finalidad sea mantener una arquitectura SPA.

## Principio arquitectónico

La aplicación seguirá este principio:

```text
Laravel es la aplicación.
Blade muestra la interfaz.
JavaScript mejora la interacción.
Axios comunica el navegador con Laravel cuando sea necesario.
MySQL almacena los datos.
```

Se evitará agregar tecnologías o capas que no sean necesarias para resolver un problema real.

La prioridad será:

**simplicidad → estabilidad → mantenimiento → funcionalidad.**
