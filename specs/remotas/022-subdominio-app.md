# Spec: Mudanza del sistema a `app.prosello.com.mx`

## Historia de usuario

Como desarrollador único del sistema, quiero mover la aplicación publicada a
`https://app.prosello.com.mx`, de forma que `prosello.com.mx` quede libre para alojar en el futuro
una página web dirigida a clientes, sin que el sistema y esa página compartan carpeta, reglas de
servidor ni sesión.

## Objetivo / Alcance

Trasladar el sistema —SPA y API— del dominio raíz a un subdominio propio, dejar el dominio raíz
vacío y redirigiendo temporalmente al sistema, y actualizar los artefactos de despliegue para que
el procedimiento siga siendo repetible desde la máquina de desarrollo.

**Esta spec no cambia ninguna regla de negocio.** Todo lo que toca es infraestructura y
configuración. Reemplaza la decisión de host de [018-despliegue-hostinger.md](018-despliegue-hostinger.md);
todo lo demás de aquella —origen único, código fuera del docroot, restricciones de `proc_open`,
sincronización de los catálogos SAT— sigue vigente sin cambios.

## Lo que no cambia

Conviene decirlo antes que nada, porque delimita el trabajo real.

**El esquema de origen único se conserva.** El SPA y Laravel siguen bajo el mismo host, ahora
`app.prosello.com.mx`:

```
https://app.prosello.com.mx/            → el SPA
https://app.prosello.com.mx/api/v1/*    → Laravel
https://app.prosello.com.mx/sanctum/*   → Laravel (cookie CSRF)
https://app.prosello.com.mx/up          → Laravel (healthcheck)
```

Los tres motivos que la 018 dio para descartar `app.` + `api.` siguen siendo válidos: la
autenticación es Sanctum por cookie de sesión, y separar API y SPA en dos hosts obligaría a mantener
una lista de orígenes en `config/cors.php`, a emitir la cookie en `.prosello.com.mx` —visible para
cualquier subdominio que llegue a existir, incluida la futura página de clientes— y a pagar un
`preflight` en cada `POST`. Mudarse de host no cambia nada de eso.

**El frontend no se reconstruye por el cambio de dominio.** `frontend/.env.production` declara
`VITE_API_URL=/api/v1`, una ruta relativa. El SPA compilado no contiene el nombre del host en
ninguna parte, así que el mismo `dist/` sirve igual en cualquier dominio.

**El front controller no se toca.** `deploy/hostinger/index.php` busca el código de Laravel con
`__DIR__.'/../facturacion'`, una ruta **relativa**. En la carpeta nueva esa ruta apunta al mismo
lugar relativo que en la vieja, porque la estructura es idéntica. Ese acierto de la 018 es lo que
convierte esta mudanza en copiar carpetas y cambiar tres variables.

**La base de datos no se toca.** Misma base, mismo usuario, mismos datos, misma `APP_KEY`. No hay
migración de información ni resiembra.

## Topología en el servidor

La cuenta de Hostinger organiza todo bajo `~/domains/<dominio>/`, y cada dominio o sitio recibe su
propia carpeta con su propio `public_html`. El subdominio se creó desde hPanel como **sitio web**
(*Añadir sitio web → Sitio web PHP/HTML personalizado*) y no desde *Subdominios*: esa segunda
pantalla fuerza la casilla «Carpeta personalizada para subdominio» y deja el docroot **dentro** de
`public_html/` del dominio raíz, que es justo lo que hay que evitar.

Antes:

```
~/domains/
└── prosello.com.mx/
    ├── facturacion/            ← código de Laravel, fuera del docroot
    └── public_html/            ← docroot: SPA + front controller
```

Después:

```
~/domains/
├── app.prosello.com.mx/
│   ├── facturacion/            ← código de Laravel, fuera del docroot
│   └── public_html/            ← docroot: SPA + front controller
└── prosello.com.mx/
    └── public_html/            ← solo .htaccess y sw.js; libre para la página de clientes
```

### Por qué el docroot del subdominio no puede colgar de `public_html/`

El primer intento dejó el subdominio en `domains/prosello.com.mx/public_html/app`. Se descartó por
tres razones, y quedan escritas porque la pantalla de hPanel empuja hacia ahí por omisión:

1. **El sistema tendría dos puertas.** Todo lo servido por `app.prosello.com.mx` sería también
   alcanzable como `https://prosello.com.mx/app/…`: los mismos archivos vistos desde el docroot del
   dominio raíz. La mudanza no habría liberado nada.
2. **La futura página de clientes podría romper el sistema.** Apache aplica los `.htaccess` de los
   directorios superiores además del de la carpeta pedida. Las reglas del dominio raíz —hoy las del
   sistema, mañana las de la página web— caerían sobre las peticiones del subdominio. Es la causa
   clásica de «el subdominio dejó de funcionar y nadie tocó el subdominio».
3. **El código de Laravel se quedaría sin lugar seguro.** O se mete dentro del docroot del dominio
   raíz, donde el `.env` sería descargable por URL, o se encadena la ruta relativa dos niveles hacia
   arriba, atándola a esa anidación concreta.

## El dominio raíz durante la transición

`prosello.com.mx` queda vacío de sistema, pero no en blanco: mientras no exista la página de
clientes, redirige a `https://app.prosello.com.mx` para que ningún marcador ni enlace existente
muera. Eso lo hace un `.htaccess` propio, `deploy/hostinger/htaccess-apex`.

### La redirección es 302, no 301

Es la única decisión de esta spec que hay que tomar mirando al futuro. Un 301 es una redirección
**permanente**: los navegadores la cachean y dejan de preguntar, a veces durante meses. El día que
la página de clientes exista y se quite la regla, todo usuario que haya entrado antes a
`prosello.com.mx` seguiría siendo lanzado al sistema sin ver jamás la página nueva, y limpiar eso
exige que cada uno vacíe su caché.

Con 302 —temporal— el navegador vuelve a preguntar cada vez. Cuesta una petición extra por visita, a
cambio de que el día del cambio no haya nada que deshacer.

Esto es deliberadamente distinto del 301 que el propio sistema usa para llevar `http://` a `https://`
y `www.` al host sin `www`: aquellas sí son permanentes y nunca van a dejar de serlo.

### El service worker de la instalación vieja

Quien tenga la PWA instalada desde `prosello.com.mx` conserva un service worker registrado en ese
origen. Un service worker sirve la aplicación desde su propia caché, así que **la redirección no lo
alcanza**: la PWA vieja seguiría abriendo la interfaz cacheada y pidiendo `/api/v1/…` contra un host
donde ya no hay API.

Y no se desactiva solo. El navegador comprueba las actualizaciones del service worker pidiendo su
archivo (`/sw.js`) en el origen donde quedó registrado, y **una redirección en esa petición se trata
como error**: la actualización falla, el service worker viejo sobrevive intacto y sigue sirviendo su
caché indefinidamente.

Por eso el docroot del dominio raíz conserva un archivo real, `sw.js`, excluido de la redirección.
No es la PWA: es un service worker de tres líneas cuyo único trabajo es borrar las cachés,
desregistrarse y recargar la ventana. La PWA vieja se apaga sola la primera vez que se abre con red,
y a partir de ahí la ventana ve la redirección como cualquier otro visitante.

Sin este archivo, la asunción de que «basta con desinstalar y volver a instalar» depende de que cada
usuario sepa que tiene que hacerlo, y de que entienda por qué la aplicación parece funcionar pero no
carga datos.

## Cambios en el repositorio

### `deploy/hostinger/htaccess-public_html` deja de nombrar el dominio

Las dos reglas del bloque de host canónico tenían el dominio escrito literalmente:

```apache
RewriteRule ^ https://prosello.com.mx%{REQUEST_URI} [L,R=301]
```

Pasan a derivar el destino de la petición misma:

```apache
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !=https
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
RewriteRule ^ https://%1%{REQUEST_URI} [L,R=301]
```

Con el dominio adentro, mudarse obliga a editar el archivo, y editarlo mal produce un fallo
particularmente desorientador: el usuario entra al sistema y el servidor lo empuja al dominio raíz,
es decir, a la futura página de clientes, sin ningún error visible. Derivando el host de la petición
el archivo sirve igual para cualquier dominio y una futura mudanza se reduce a `deploy/config.sh`.

La contrapartida es que una visita a `http://www.…` ahora resuelve en dos saltos —primero a HTTPS
conservando el `www`, después al host sin `www`— en vez de uno. Son dos redirecciones que ocurren una
sola vez por visitante y no se notan; el orden inverso de las reglas no evitaría el doble salto,
solo lo movería de sitio.

### `deploy/hostinger/htaccess-apex` (nuevo)

El `.htaccess` del docroot del dominio raíz. Redirige todo a `https://app.prosello.com.mx` con 302,
**excepto** `/sw.js`, que se sirve como archivo real por lo explicado arriba.

Este archivo sí nombra el dominio de destino, y es correcto que lo haga: su único propósito es
apuntar a **otro** host, así que el nombre no es un dato del entorno sino el contenido mismo de la
regla.

Se acompaña de `deploy/hostinger/sw-apex.js`, el service worker de apagado, que se copia al docroot
raíz como `sw.js`.

### `deploy/hostinger/env.production.example`

| Variable | Antes | Después |
|---|---|---|
| `APP_URL` | `https://prosello.com.mx` | `https://app.prosello.com.mx` |
| `FRONTEND_URL` | `https://prosello.com.mx` | `https://app.prosello.com.mx` |
| `SANCTUM_STATEFUL_DOMAINS` | `prosello.com.mx` | `app.prosello.com.mx` |

`SESSION_DOMAIN` se queda en `null`. Es lo que mantiene la cookie **host-only**: la sesión pertenece
a `app.prosello.com.mx` y la futura página de clientes no la ve nunca. Ponerla en
`.prosello.com.mx` para «compartir» sesión entre ambas sería exactamente el riesgo que la 018
evitó.

`MAIL_FROM_ADDRESS` se queda en `no-reply@prosello.com.mx`. Es la identidad de la empresa, no la
dirección del sistema, y el buzón vive en el dominio raíz.

También cambia el comentario de cabecera, que documenta la ruta del `.env` en el servidor.

### `deploy/config.sh` y `deploy/config.example.sh`

`config.sh` no se versiona (tiene el identificador real de la cuenta). Sus tres valores de ruta y
URL pasan al subdominio, y gana uno nuevo:

```bash
REMOTE_APP="/home/u648715341/domains/app.prosello.com.mx/facturacion"
REMOTE_DOCROOT="/home/u648715341/domains/app.prosello.com.mx/public_html"
SITE_URL="https://app.prosello.com.mx"
APEX_URL="https://prosello.com.mx"      # nuevo
```

`APEX_URL` existe para que `verify.sh` sepa qué dominio raíz comprobar sin deducirlo del subdominio.
`config.example.sh` recibe los mismos campos con valores de ejemplo, y `lib.sh` agrega `APEX_URL` a
las variables obligatorias: un `config.sh` viejo debe fallar al arrancar con un mensaje claro, no a
mitad del verificador.

### `deploy/verify.sh`

Gana un bloque de cuatro comprobaciones sobre el dominio raíz:

1. `https://prosello.com.mx/` responde **302** (no 301: un 301 aquí sería el error descrito arriba).
2. Su `Location` es `https://app.prosello.com.mx/`.
3. `https://www.prosello.com.mx/` también termina en `https://app.prosello.com.mx/`.
4. `https://prosello.com.mx/sw.js` responde **200**, sin redirección. Si esa exclusión se pierde en
   un `.htaccess` futuro, el service worker de apagado deja de ejecutarse y nadie se entera.

Mientras `APEX_URL` valga lo mismo que `SITE_URL` —es decir, mientras la mudanza no se haya
completado— el bloque entero se **omite con un aviso** en vez de reportar cuatro fallos por algo que
todavía no debería funcionar.

Y **pierde** una: la que hoy comprueba que `www.<host>` redirige. Con `SITE_URL` apuntando al
subdominio, esa comprobación pasaría a interrogar a `www.app.prosello.com.mx`, un nombre con doble
prefijo que no tiene registro DNS ni cobertura en el certificado y que nadie teclea jamás. La regla
de `www` sigue en el `.htaccess` —no cuesta nada y cubre el caso genérico— pero deja de ser un
criterio de verificación, porque fallaría siempre por una razón que no es un problema.

El día que exista la página de clientes y se retire la redirección, estas tres comprobaciones
empezarán a fallar. Eso es deseable: el verificador avisará de que hay algo que actualizar en vez de
callarse.

### `deploy/README.md`

Se actualizan las rutas del servidor, el diagrama de topología, la línea del cron, los comandos de
diagnóstico que citan rutas absolutas y la sección de restauración de respaldos. Se agrega una
sección corta sobre el docroot del dominio raíz: qué contiene, por qué, y qué hay que hacer el día
que se publique la página de clientes.

Se documenta también una restricción encontrada al preparar esta spec: **el servidor no tiene
`crontab` por línea de comandos**. La tarea programada se administra únicamente desde hPanel, así
que su ruta es un paso manual del procedimiento y no algo que un script pueda cambiar.

### `.claude/commands/deploy.md` y `deploy/hostinger/index.php`

Ambos mencionan `prosello.com.mx` en texto —la descripción del comando y un comentario de ejemplo
de la topología—. Se actualizan para que no describan una estructura que ya no existe.

### `specs/018-despliegue-hostinger.md`

Dos retoques, ninguno de contenido:

- El estado pasa de «Pendiente» a **implementada el 2026-08-11**, que es la fecha registrada en
  `deploy/README.md` como día de verificación en el servidor. Estaba desactualizado: el sistema
  llevaba semanas publicado.
- Una nota al inicio indicando que su decisión de host quedó reemplazada por esta spec, con enlace.
  Lo demás de la 018 sigue siendo la referencia válida.

## Procedimiento de mudanza

Por copia, no por movimiento: la instalación actual sigue viva y atendiendo usuarios hasta que la
nueva pase el verificador. Si algo sale mal en cualquier punto, la salida es no continuar — el
sistema viejo nunca dejó de funcionar.

Las dos copias comparten base de datos, así que no hay datos que sincronizar después: es el mismo
sistema visto por dos puertas.

**1. Preparar el repositorio.** Todos los cambios de la sección anterior, con `deploy/config.sh`
todavía **apuntando al dominio raíz**.

**2. Copiar en el servidor.**

```bash
cp -a ~/domains/prosello.com.mx/facturacion  ~/domains/app.prosello.com.mx/facturacion
cp -a ~/domains/prosello.com.mx/public_html/. ~/domains/app.prosello.com.mx/public_html/
rm -f ~/domains/app.prosello.com.mx/public_html/default.php
```

`cp -a` conserva permisos, fechas y archivos ocultos, que aquí incluyen el `.env` y el `.htaccess`.
El `/.` del segundo comando no es adorno: sin él, `cp` copiaría el directorio *dentro* del destino en
vez de su contenido.

El `rm` va **después** de la copia, no antes. Hostinger deja su `default.php` de bienvenida en todo
docroot nuevo, pero también lo había dejado en el del dominio raíz, así que borrarlo primero no sirve
de nada: la copia lo vuelve a traer.

**3. Subir el `.htaccess` nuevo al docroot del subdominio.**

```bash
scp deploy/hostinger/htaccess-public_html \
    prosello:/home/<cuenta>/domains/app.prosello.com.mx/public_html/.htaccess
```

Este paso no es opcional ni cosmético. El `.htaccess` que acaba de llegar con la copia es el
**anterior**, el que todavía nombra el dominio a mano:

```apache
RewriteRule ^ https://prosello.com.mx%{REQUEST_URI} [L,R=301]
```

Esa regla, sirviendo bajo el subdominio, rebota **todas** las visitas de vuelta al dominio raíz. El
sistema nuevo sería inalcanzable desde el primer segundo, y el síntoma —«entro al subdominio y
aparezco en el viejo»— es exactamente el que la versión sin dominio escrito a mano viene a evitar.

De los cuatro archivos de `deploy/hostinger/` que viven en el docroot, este es el único cuyo
contenido cambió: `index.php` solo cambió un comentario y `robots.txt` no cambió.

**4. Ajustar el `.env` nuevo.** Las tres variables de la tabla de arriba, con `nano` en el servidor.

**5. Vaciar la configuración cacheada de la copia.** Es el paso que más silenciosamente se olvida.
`bootstrap/cache/config.php` guarda **rutas absolutas resueltas en el momento de cachear**: la copia
recién hecha traería dentro las rutas de `domains/prosello.com.mx/…`, y la instalación nueva
escribiría sus logs y su caché en la carpeta vieja mientras aparenta funcionar con normalidad.

Con `deploy/config.sh` ya apuntando al subdominio:

```bash
bash deploy/artisan.sh config:clear
bash deploy/artisan.sh route:clear
bash deploy/artisan.sh view:clear
bash deploy/artisan.sh config:cache
bash deploy/artisan.sh route:cache
bash deploy/artisan.sh event:cache
```

**6. Verificar la copia nueva.**

```bash
bash deploy/verify.sh
```

En esta fase **fallan las tres comprobaciones de la redirección del dominio raíz**, y es lo esperado:
el dominio raíz todavía sirve el sistema, así que responde 200 en vez de 302. (La cuarta, la de
`sw.js`, pasa por casualidad: ahí sigue el service worker real de la PWA vieja.) Todo lo demás tiene
que pasar.

El bloque se omitiría por completo sólo antes del paso 5, cuando `SITE_URL` y `APEX_URL` todavía
valen lo mismo.

Y a mano, en el navegador, lo que ningún `curl` puede comprobar: iniciar sesión, recargar para
confirmar que la sesión persiste, y generar un PDF. Este es el punto de no retorno del
procedimiento — todo lo anterior es reversible borrando la copia; a partir del paso 7 se desarma la
instalación vieja.

**7. Mover el cron.** hPanel → *Avanzado → Trabajos Cron*. La línea de
`cotizaciones:purgar-vencidas` pasa a la ruta nueva. Debe quedar **una sola**: dos líneas apuntando
a las dos copias ejecutarían la purga dos veces contra la misma base.

**8. Liberar el dominio raíz.** Borrar del docroot raíz todo lo del sistema y dejar solo los dos
archivos nuevos:

```bash
scp deploy/hostinger/htaccess-apex prosello:<docroot-raiz>/.htaccess
scp deploy/hostinger/sw-apex.js    prosello:<docroot-raiz>/sw.js
```

**9. Verificación final.** `bash deploy/verify.sh` completo, ahora sí con las tres comprobaciones del
dominio raíz en verde. Y un despliegue de prueba —`bash deploy/deploy-frontend.sh`— para confirmar
que los scripts operan correctamente contra las rutas nuevas.

**10. Retirar la copia vieja.** Solo cuando todo lo anterior pasó:
`rm -rf ~/domains/prosello.com.mx/facturacion`. El `~/backups/` de la cuenta no se toca: es común a
las dos y sigue sirviendo.

## Pasos manuales en hPanel

Ni un script puede hacerlos ni conviene que lo intente:

- El subdominio, ya creado como sitio web independiente. **Hecho**, verificado en el servidor.
- El certificado SSL de `app.prosello.com.mx`. **Hecho**: el host ya responde 200 por HTTPS.
- La línea del cron (paso 6), porque no hay `crontab` en la shell.

## Fuera de alcance

- **La página web de clientes.** Esta spec libera `prosello.com.mx` y lo deja redirigiendo; construir
  el sitio es un trabajo aparte, con su propia spec, que decidirá también cuándo retirar la
  redirección y el `sw.js` de apagado.
- Separar el API en `api.prosello.com.mx`. Descartado en la 018 por las razones citadas arriba, que
  esta mudanza no altera.
- Compartir sesión entre el sistema y la futura página web.
- CI/CD, staging, VPS, colas con worker.
- El paso de Facturapi a `live`.
- Cualquier cambio de lógica de negocio, pantallas o endpoints.

## Criterios de aceptación

1. `https://app.prosello.com.mx/` sirve el SPA; `http://` redirige con 301 a la forma canónica.
2. `https://app.prosello.com.mx/up` responde el healthcheck de Laravel, no `index.html`.
3. El login funciona y la sesión persiste al recargar. Una petición al API sin sesión devuelve
   **401 JSON**.
4. Una ruta profunda escrita a mano (`/facturas/nueva`) carga la aplicación en vez de dar 404.
5. `/.env`, `/composer.json`, `/storage/logs/laravel.log` y `/artisan` no exponen nada bajo el
   subdominio.
6. Chrome ofrece «Instalar» la aplicación desde `app.prosello.com.mx`, y los assets con hash llegan
   con `immutable` mientras `index.html` y `sw.js` llegan con `no-cache`.
7. Se genera un PDF de factura sin errores, y un correo de recuperación de contraseña llega con su
   enlace apuntando a `https://app.prosello.com.mx`.
8. Los catálogos SAT responden: las búsquedas de clave de producto/servicio y de unidad devuelven
   resultados y los `<select>` de régimen fiscal y forma de pago vienen poblados.
9. `https://prosello.com.mx/` y `https://www.prosello.com.mx/` responden **302** hacia
   `https://app.prosello.com.mx/`. No 301.
10. `https://prosello.com.mx/sw.js` devuelve el service worker de apagado como archivo real, sin
    redirección.
11. Una PWA instalada desde el origen viejo, al abrirse con red, se desregistra y deja de servir su
    caché.
12. En el servidor no queda ningún archivo del sistema bajo `domains/prosello.com.mx/`: ni
    `facturacion/`, ni el SPA, ni el front controller. Solo `.htaccess` y `sw.js` en su docroot.
13. El cron corre desde la ruta nueva, hay una sola línea, y deja rastro de su ejecución.
14. `deploy-backend.sh`, `deploy-frontend.sh` y `verify.sh` funcionan contra las rutas nuevas, y un
    `config.sh` sin `APEX_URL` falla al arrancar con un mensaje que dice qué falta.
15. `deploy/hostinger/htaccess-public_html` no contiene la cadena `prosello`.

## Estado de implementación

**Repositorio: implementado** (commit `d69583c`). `verify.sh` omite las comprobaciones del dominio
raíz mientras `APEX_URL` valga lo mismo que `SITE_URL`, en vez de reportar fallos por una mudanza que
aún no ocurrió.

**Servidor: en curso.** Hechos los pasos 1 a 3 —repositorio, copia de `facturacion/` y del docroot
verificada por `md5sum`, y `.htaccess` nuevo en el subdominio—. Faltan los pasos 4 a 10.

De los manuales de hPanel ya están hechos la creación del subdominio como sitio web independiente y
su certificado SSL; falta mover la línea del cron.

## Supuestos asumidos (registro completo)

1. Se mueve **todo el sistema** —SPA y API— a `app.prosello.com.mx`, conservando el esquema de un
   solo origen. No se separa en `app.` + `api.`.
2. Es una mudanza, no una duplicación permanente: al terminar, el sistema se sirve únicamente desde
   el subdominio.
3. Los archivos del sistema se retiran del dominio raíz; nada del sistema queda alcanzable desde
   `prosello.com.mx`.
4. `prosello.com.mx` queda libre para la futura página web de clientes. Construirla no es parte de
   esta spec.
5. Mientras esa página no exista, el dominio raíz redirige al subdominio con **302 temporal**.
6. El host canónico es `https://app.prosello.com.mx`, sin `www`. La regla de `www` se conserva en el
   `.htaccess` pero no se verifica, porque `www.app.…` no existe en DNS ni en el certificado.
7. La cookie de sesión sigue siendo host-only (`SESSION_DOMAIN=null`). La página de clientes nunca
   verá la sesión del sistema, y `config/cors.php` no se toca.
8. Todos los usuarios vuelven a iniciar sesión una vez. La sesión vieja pertenece a otro host.
9. Quien tenga la PWA instalada debe reinstalarla desde la URL nueva. El `sw.js` de apagado en el
   dominio raíz garantiza que la instalación vieja no se quede sirviendo su caché para siempre.
10. Se conserva la misma base de datos, con los mismos datos, el mismo usuario y la misma `APP_KEY`.
11. Los enlaces de los correos pasan a apuntar al subdominio.
12. La dirección remitente sigue siendo del dominio raíz (`no-reply@prosello.com.mx`).
13. `app.prosello.com.mx` sigue fuera de los buscadores: `robots.txt` con `Disallow: /`.
14. Cambio solo de infraestructura: no se toca lógica de negocio, pantallas, endpoints ni el modo de
    Facturapi, que sigue en `test`.
15. La mudanza se hace por copia y la indisponibilidad es prácticamente nula. La copia vieja se
    retira solo después de la verificación completa.
16. Los scripts de despliegue existentes siguen siendo los mismos; solo cambian rutas y URL de su
    configuración.
17. Esta spec reemplaza la decisión de host de la 018, que queda marcada como implementada el
    2026-08-11 y con una nota que apunta aquí.
18. El certificado SSL del subdominio lo emite Hostinger; ya está activo y verificado.
19. El subdominio se creó como **sitio web independiente**, no desde la pantalla de subdominios, para
    que su docroot fuera hermano del dominio raíz y no colgara de `public_html/`.
20. El `.htaccess` del sistema deriva el host canónico de la petición y no lo nombra, de modo que una
    futura mudanza no obligue a editarlo.
21. El cron se administra solo desde hPanel: el servidor no expone `crontab` por línea de comandos.
