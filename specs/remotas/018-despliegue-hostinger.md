# Spec: Despliegue en Hostinger (producción, un solo origen)

> **El host de esta spec quedó reemplazado por [022-subdominio-app.md](022-subdominio-app.md).**
> El sistema ya no se publica en `prosello.com.mx` sino en `app.prosello.com.mx`; el dominio raíz
> quedó libre para una página web de clientes. Todo lo demás de este documento —origen único, código
> fuera del docroot, restricciones de `proc_open`, sincronización de los catálogos SAT, artefactos de
> `deploy/hostinger/`— sigue siendo la referencia válida. Donde aquí se lea `prosello.com.mx` como
> host del sistema, hoy corresponde `app.prosello.com.mx`.

## Historia de usuario

Como desarrollador único del sistema, quiero publicar la aplicación en el hosting compartido de
Hostinger bajo `https://prosello.com.mx`, de forma que el sistema quede utilizable desde internet,
instalable como PWA, y que volver a desplegar un cambio sea un procedimiento escrito y repetible en
vez de una serie de decisiones improvisadas cada vez.

## Objetivo / Alcance

Dejar en el repositorio los artefactos de despliegue (front controller de producción, `.htaccess`
del docroot, plantilla de variables de entorno y el procedimiento paso a paso) y los ajustes de
código que producción exige, de modo que el despliegue no dependa de archivos que solo existen en
el servidor ni de recordar qué se tocó a mano.

**Esta spec no cambia ninguna regla de negocio.** Todo lo que toca es infraestructura y
configuración.

### El sistema se sirve desde un solo origen

Backend y frontend son dos aplicaciones desacopladas (ver `001-inicio-proyecto.md`), pero en
producción se publican bajo **el mismo host**:

```
https://prosello.com.mx/            → el SPA (dist/ de Vite)
https://prosello.com.mx/api/v1/*    → Laravel
https://prosello.com.mx/sanctum/*   → Laravel (cookie CSRF)
https://prosello.com.mx/up          → Laravel (healthcheck)
```

La alternativa —`app.prosello.com.mx` para el SPA y `api.prosello.com.mx` para Laravel— se
descartó. La autenticación es Sanctum por **cookie de sesión**, no por token Bearer
(`EnsureFrontendRequestsAreStateful` en `bootstrap/app.php`, `withCredentials` en
`src/lib/http.ts`), y separar en subdominios obliga a sostener tres piezas que con un solo origen
sencillamente no existen:

- una lista de orígenes permitidos en `config/cors.php` que hay que mantener sincronizada,
- una cookie emitida en `.prosello.com.mx` en vez de host-only, es decir, una cookie de sesión
  visible para **cualquier** subdominio que llegue a existir en el dominio,
- peticiones cruzadas con `preflight` en cada `POST`, con el costo de latencia que eso agrega en un
  hosting compartido.

A cambio, el único costo real del origen único es que el `.htaccess` del docroot tiene que decidir
qué petición va al SPA y cuál a Laravel. Es un archivo, y queda versionado en el repositorio.

### Por qué el service worker no estorba

El riesgo conocido de servir SPA y API desde el mismo host es el `navigateFallback` del service
worker: si la PWA responde `/index.html` a una ruta del API, el frontend recibe HTML donde esperaba
JSON y el error que muestra no se parece en nada a la causa.

En este proyecto ese riesgo ya está acotado por diseño: `vite.config.ts` declara
`navigateFallbackDenylist: [/^\/api\//]`, y `/sanctum/csrf-cookie` lo pide axios por XHR, no por
navegación, así que el fallback ni siquiera se evalúa sobre esa ruta. Se agregan `/sanctum` y `/up`
a la lista de todas formas: cuestan una línea y cierran el caso de alguien que escriba esas URL a
mano en la barra de direcciones con la aplicación instalada.

## Topología en el servidor

El código de Laravel **no vive dentro del docroot**. Si viviera, `.env`, `storage/logs/laravel.log`
y `composer.lock` serían descargables por URL.

```
<padre>/
├── facturacion/                    ← fuera del docroot, inaccesible por web
│   ├── app/  bootstrap/  config/  database/  routes/  storage/  vendor/
│   ├── artisan
│   └── .env                        ← credenciales reales
│
└── public_html/                    ← docroot de prosello.com.mx
    ├── .htaccess                   ← deploy/hostinger/htaccess-public_html
    ├── index.php                   ← deploy/hostinger/index.php (front controller de Laravel)
    ├── index.html                  ← el SPA
    ├── assets/                     ← chunks con hash de Vite
    ├── sw.js  registerSW.js  manifest.webmanifest
    ├── favicon.svg  icons.svg  apple-touch-icon.png  pwa-*.png
    └── robots.txt
```

`facturacion/` es **hermano de `public_html/`**, no una ruta absoluta. Así el front controller la
alcanza con `__DIR__.'/../facturacion'` y el despliegue funciona igual si `prosello.com.mx` es el
dominio principal de la cuenta (`~/public_html`) o uno adicional
(`~/domains/prosello.com.mx/public_html`), sin ninguna ruta que mantener a mano.

Es decir: el contenido de `frontend/dist/` y el front controller de Laravel **comparten** el
docroot. `frontend/dist/` ya incluye su propio `.htaccess` (viene de `frontend/public/.htaccess`,
con las cabeceras de caché de la PWA), y ese archivo queda **reemplazado** por el del despliegue,
que lo incorpora íntegro.

## Artefactos nuevos en el repositorio

Se crea `deploy/hostinger/`. Ninguno de estos archivos participa del desarrollo local: existen para
copiarse al servidor.

### `deploy/hostinger/index.php`

Copia del `backend/public/index.php` con las tres rutas que apuntan al proyecto (maintenance,
autoload y `bootstrap/app.php`) resueltas contra `__DIR__.'/../facturacion'` en vez de
`__DIR__.'/../'`.

Se versiona como archivo aparte en vez de editar `backend/public/index.php` porque ese archivo
tiene que seguir funcionando en Laragon, donde el proyecto sí está en `../`. Un `index.php` con
rutas dependientes del entorno sería más frágil que dos archivos explícitos.

### `deploy/hostinger/htaccess-public_html`

El `.htaccess` del docroot. Reúne, en este orden:

1. **Host canónico y HTTPS.** Redirección 301 de `http://` y de `www.` hacia
   `https://prosello.com.mx`. No es cosmético: con cookie host-only, `www.prosello.com.mx` y
   `prosello.com.mx` son dos sitios distintos para el navegador, y un usuario que entra por uno
   después de haberse logueado en el otro aparece como no autenticado sin ninguna explicación
   visible. HTTPS además es requisito del service worker: sin él no hay PWA instalable.
2. **Las cabeceras `Authorization` y `X-XSRF-Token`**, tal como las reexpone hoy
   `backend/public/.htaccess`.
3. **Las rutas de Laravel al front controller**: `/api`, `/sanctum` y `/up` van a `index.php`.
4. **Todo lo demás**: si el archivo existe en disco se sirve tal cual; si no, se responde
   `index.html` y el router de Vue resuelve la ruta en el cliente.
5. **Las cabeceras de caché de `frontend/public/.htaccess`**, íntegras y sin cambios de criterio:
   assets con hash inmutables, `index.html` y las piezas de la PWA siempre revalidadas.

Con `DirectoryIndex index.html`, la raíz sirve el SPA.

#### El tipo de los módulos `.mjs`

*Agregado el 2026-08-19.*

```apache
AddType text/javascript .mjs
```

Es exactamente la misma clase de problema que el `.webmanifest` que ya estaba en el punto 5, y se
descubrió del mismo modo: por un fallo que no se parecía en nada a su causa. **Apache en Hostinger no
trae mapeada la extensión `.mjs` y la sirve como `text/plain`**, y un navegador **se niega a ejecutar
un módulo que no llega con un tipo de JavaScript**. El archivo se descarga completo, con `200`, y no
corre.

Quien lo destapó fue la lectura de la Constancia de Situación Fiscal
([016](016-constancia-situacion-fiscal-qr.md)). `pdfjs-dist` publica su *worker* **solo** en `.mjs`
—no hay variante `.js` entre la que elegir— y dibujar la primera página del PDF es lo primero que
ocurre al soltar el archivo. En producción, la zona de carga se quedaba en *"Leyendo el
documento…"* indefinidamente: el worker no arrancaba, y el fallo ni siquiera llegaba a ser un error
que la pantalla pudiera contar.

Tres cosas hicieron que esto viviera meses sin que nadie lo notara, y las tres conviene tenerlas
presentes porque volverán a pasar:

- **En local no falla.** El servidor de desarrollo de Vite manda el tipo correcto. El bug solo
  existe en producción.
- **Ninguna prueba podía verlo.** El `.mjs` no lo carga el código: lo carga el navegador, por una
  importación diferida, contra un servidor real. Ni Vitest ni `npm run build` ni Pest tocan ese
  camino.
- **El único `.mjs` del build es ese.** Es una extensión que aparece una sola vez, en un archivo que
  nadie escribió, dentro de una funcionalidad que se usa de vez en cuando.

**El patrón de caché inmutable tampoco lo cubría.** `<FilesMatch "-[hash]\.(js|css)$">` no incluía
`mjs`, así que el worker —1.2 MB, con hash en el nombre y por lo tanto inmutable por definición— se
revalidaba en cada carga. Se agrega la extensión a la lista.

Las dos correcciones van **en los dos archivos**, que esta misma spec declara copia íntegra uno del
otro: `deploy/hostinger/htaccess-public_html` y `frontend/public/.htaccess`.

### `deploy/hostinger/env.production.example`

Plantilla del `.env` de producción, sin secretos, con los valores que difieren de
`backend/.env.example` y el motivo de cada uno. Los que importan:

| Variable | Valor | Por qué |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Con `true`, cualquier error expone el stack y variables de entorno |
| `APP_URL` / `FRONTEND_URL` | `https://prosello.com.mx` | Enlaces de correo (reset de contraseña) y PDFs |
| `SANCTUM_STATEFUL_DOMAINS` | `prosello.com.mx` | Sin `www`, coherente con el host canónico |
| `SESSION_DOMAIN` | `null` | Host-only: la cookie no se comparte con subdominios |
| `SESSION_SECURE_COOKIE` | `true` | La cookie de sesión no viaja nunca por HTTP |
| `LOG_LEVEL` | `warning` | `debug` llena el disco del plan compartido |
| `DB_*` | los de hPanel | El usuario y la base los crea hPanel con prefijo `uXXXXXXXX_` |
| `FACTURAPI_ENV` | `test` | Primer despliegue sin timbrar contra el SAT real |
| `MAIL_*` | SMTP de Hostinger | `smtp.hostinger.com`, puerto 465, `MAIL_SCHEME=smtps` |

`SESSION_DRIVER`, `CACHE_STORE` y `QUEUE_CONNECTION` se quedan en `database`: el plan compartido no
ofrece Redis, y sin colas ni workers la base alcanza de sobra.

### `deploy/hostinger/robots.txt`

`Disallow: /` para todo. El sistema entero vive detrás del login: no hay nada que un buscador deba
indexar, y las URL del SPA filtrarían la estructura interna sin ningún beneficio. Reemplaza al
`robots.txt` por defecto de Laravel, que permite el rastreo completo.

### `deploy/hostinger/README.md`

El procedimiento operativo, en dos partes: la instalación inicial (una sola vez) y el despliegue de
un cambio (cada vez). Se detalla más abajo.

## Cambios en el código existente

### `frontend/.env.production`

```
VITE_API_URL=/api/v1
```

Ruta **relativa**. Vite la toma automáticamente en `npm run build` y deja `frontend/.env` intacto
para el desarrollo local. `ensureCsrfCookie()` en `src/lib/http.ts` deriva de ahí
`/sanctum/csrf-cookie`, también relativo, sin necesitar cambios.

El archivo se versiona: no contiene ningún secreto, y que el build de producción dependa de un
archivo no versionado es exactamente la clase de detalle que se olvida.

### `frontend/vite.config.ts`

`navigateFallbackDenylist` pasa de `[/^\/api\//]` a `[/^\/api\//, /^\/sanctum\//, /^\/up$/]`.

### `deploy/deploy-backend.sh` sincroniza la base de catálogos SAT

Los catálogos del SAT (`c_RegimenFiscal`, `c_CodigoPostal`, `c_ClaveProdServ`, `c_ClaveUnidad`,
`c_UsoCFDI` y `c_FormaPago`) no viven en MySQL: viven en una base SQLite de ~13 MB en
`backend/storage/app/sat-catalogos.sqlite`, que genera el comando `catalogos-sat:actualizar`
(ver `004-gestion-clientes.md`, `006-gestion-articulos.md` y `007-facturacion.md`).

Ese archivo cae en la intersección de dos reglas que, cada una por su lado, son correctas:

- **No está en git.** `backend/storage/app/.gitignore` ignora todo el directorio, y así debe seguir:
  son 13 MB binarios regenerables por comando, no código.
- **El despliegue no sube `storage/`.** Su contenido es del servidor —logs, sesiones, caché— y
  pisarlo desde local sería destruir estado vivo.

El resultado es que el único archivo de `storage/` que **sí** es un artefacto de la aplicación y no
estado del servidor no llegaba nunca a producción. Sin él, los seis catálogos quedan vacíos: los
`<select>` de régimen fiscal y forma de pago no ofrecen opciones, y las búsquedas de clave de
producto/servicio, clave de unidad, uso de CFDI y código postal no devuelven nada. La validación de
alta también rechaza cualquier clave, porque `App\Rules\*Valido` consulta esa misma base.

Por eso `deploy-backend.sh` gana un paso propio, **antes** de las migraciones:

1. Compara el `md5sum` del archivo local con el del remoto.
2. Si coinciden, no sube nada. El archivo cambia solo cuando se corre `catalogos-sat:actualizar`
   (dos o tres veces al año), y 13 MB en cada despliegue serían puro peaje.
3. Si difieren o el remoto no existe, lo sube comprimido con `gzip` sobre SSH (~3 MB en tránsito),
   **a un nombre temporal**, y solo entonces lo mueve sobre el definitivo.
4. Verifica el `md5sum` del resultado y aborta el despliegue si no coincide.

El nombre temporal más el `mv` no son ceremonia: escribir directo sobre el destino deja la
aplicación leyendo un archivo a medio escribir mientras dura la transferencia, y una transferencia
interrumpida deja una base truncada —o de cero bytes— que SQLite abre **sin error** y que responde
"no hay resultados" a todo. Es un modo de falla silencioso, indistinguible desde la interfaz de un
catálogo que simplemente no encuentra nada. `mv` dentro del mismo sistema de archivos es atómico:
o está la base completa y anterior, o está la completa y nueva.

Se sube el archivo desde local en vez de correr `catalogos-sat:actualizar` en el servidor. El
comando descarga un ZIP de ~20 MB desde GitHub y reconstruye la base; hacerlo en producción
significa que un despliegue depende de que GitHub responda, y que producción pueda quedar con una
versión de los catálogos que nunca se probó en desarrollo. Subir el archivo deja las dos máquinas
con exactamente los mismos datos, verificado por checksum.

### `backend/config/dompdf.php` (nuevo)

`barryvdh/laravel-dompdf` resuelve la ruta base de dompdf al construir el objeto:

```php
$path = realpath($app['config']->get('dompdf.public_path') ?: base_path('public'));
if ($path === false) {
    throw new \RuntimeException('Cannot resolve public path');
}
```

`dompdf.public_path` viene en `null` por omisión, así que cae en `base_path('public')`. **Ese
directorio no existe en producción**: el docroot es `public_html/`, hermano de la aplicación, y el
despliegue excluye `public/` justamente porque el front controller vive del otro lado. `realpath()`
devuelve `false` y el contenedor lanza la excepción.

Falla, entonces, **toda** generación de PDF: facturas, cotizaciones y órdenes de compra, tanto al
descargarlas como al enviarlas por correo o WhatsApp —`EnvioDocumentoService` genera el adjunto
antes de entregarlo a `Mail`—. Y el mensaje que llega a la interfaz es un `Server Error` genérico:
con `APP_DEBUG=false` el usuario ve un error que no menciona ni el PDF ni el correo.

Se publica un `config/dompdf.php` con una sola clave, `'public_path' => base_path()`. El resto de
las opciones las sigue aportando el paquete por `mergeConfigFrom`. `base_path()` existe en las dos
máquinas y coincide con el `chroot` que dompdf ya usa por omisión.

La ruta es hoy **nominal**: ninguna vista de `resources/views/pdf/` referencia imagen, hoja de
estilo ni fuente externa, así que dompdf nunca resuelve nada relativo contra ella. Se apunta a
`base_path()` y no al docroot de producción porque un asset que un PDF necesite debe vivir en el
repositorio —donde se versiona y se despliega— y no en `public_html/`, que en producción está fuera
de la aplicación y que `deploy-frontend.sh` sobrescribe con el build del SPA.

### `deploy/lib.sh` pasa la expresión de exclusión por archivo, no por la línea de comandos

`borrar_sobrantes()` borra en el servidor lo que ya no está en el repositorio, y para no borrar lo
que nunca se sube (`.env`, `storage/`, `vendor/`) le pasa a `find` una expresión de exclusión.
Esa expresión viajaba como variable de entorno del comando ssh:

```bash
remote "DEST='$destino' PRUNE='$prune' bash -s"
```

Y `$prune` contiene comillas simples: `-name '.env.*' -prune -o`. Al construirse la línea, esas
comillas **cierran** las de `PRUNE='...'`, así que el valor llega al servidor sin ellas. El `eval`
del script remoto expande entonces `.env.*` contra el directorio del proyecto y `find` recibe
`-name .env.save .env.save.1` —dos rutas donde esperaba un patrón— y aborta con
`paths must precede expression`.

El fallo depende de que exista un archivo que case con el patrón, así que el despliegue funcionó
hasta que editar el `.env` en el servidor con `nano` dejó sus `.env.save` al lado. Un error latente
que aparece por un archivo ajeno al repositorio no es algo que convenga dejar armado.

La expresión pasa ahora por un archivo (`/tmp/deploy-prune.txt`) que el script remoto lee dentro del
`eval`. Así el texto se interpreta **una sola vez**, en el `eval`, y las comillas hacen su trabajo.

### El script remoto fija `LC_ALL=C`

Segundo error latente en la misma función, aparecido al desplegar
[019](019-formato-pdf-documentos.md). Las dos listas que compara `borrar_sobrantes()` se ordenan con
`LC_ALL=C sort` —una en la máquina de desarrollo, otra en el servidor—, pero el `comm` que las
compara corría con el locale del servidor, que es `en_US.UTF-8`. Y las dos collations no coinciden:
en orden de bytes `CotizacionFormView` va antes que `CotizacionesListView`, y en `en_US.UTF-8` va
después. `comm` concluye que la entrada está desordenada, avisa por `stderr` y sale con estado
distinto de cero, que con `set -euo pipefail` aborta el script **justo antes del borrado**.

Consecuencia: el SPA se publica correctamente pero los bundles de la compilación anterior se quedan
en el docroot para siempre, y el despliegue termina en error sin que nada visible esté roto.

El fallo depende de que la lista contenga dos nombres cuyo orden relativo cambie entre collations,
y por eso el backend nunca lo disparó: sus rutas son casi todas minúsculas. Las del build del
frontend llevan el nombre de cada vista en CamelCase, así que era cuestión de tiempo.

El script remoto exporta ahora `LC_ALL=C` en su primera línea, con lo que `find`, `sort` y `comm`
comparten el mismo orden. Se eligió eso sobre ordenar en la collation del servidor porque el orden
de bytes es el único que no depende de cómo esté configurada una máquina que no controlamos.

### `deploy/verify.sh` comprueba el tipo de los `.mjs`

*Agregado el 2026-08-19.*

El `.htaccess` del docroot es **el único artefacto de producción que ningún script despliega**: se
sube a mano una vez, en el paso 4 de la instalación inicial. Es, por lo tanto, exactamente el archivo
que puede quedarse viejo en el servidor sin que nada lo delate — y el efecto de que le falte esta
línea es una pantalla que se queda pensando, no un error.

Por eso la comprobación se agrega al verificador, y no basta con haber arreglado el archivo:

- Toma **el primer `.mjs` de `frontend/dist/assets/`** y le pide esa URL al servidor. No se puede
  usar una ruta fija porque los assets llevan hash de contenido en el nombre.
- Pasa si el `Content-Type` es cualquier tipo de JavaScript: `text/javascript`,
  `application/javascript` o el heredado `application/x-javascript`, que es el que Hostinger devuelve
  hoy para los `.js`.
- Si no hay build en `frontend/dist/`, **lo dice y sigue** sin contarlo como fallo. `verify.sh` se
  puede correr sin haber compilado, y una comprobación que no se pudo hacer no es una que falló.

### Nada más

`config/cors.php` no se toca. Con un solo origen el navegador no manda `Origin` en peticiones del
mismo host, el middleware de CORS no interviene, y dejar la configuración como está mantiene el
proyecto funcionando en local contra el dev server de Vite.

## Requisitos del servidor

Verificables en hPanel antes de subir nada:

- **PHP 8.3 o superior** (`composer.json` exige `^8.3`; Laravel 13 también).
- **Extensiones**: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `dom`, `fileinfo`, `iconv`, `zip` y
  **`gd`**. `gd` no es opcional: `QrLector::aPng()` construye la imagen del QR de la constancia con
  `imagecreatetruecolor()`/`imagepng()`, y dompdf la necesita para las imágenes de los PDF. Sin
  ella, la lectura de la Constancia de Situación Fiscal y la generación de PDFs fallan.
- **Acceso SSH**, para correr `composer install` y `php artisan migrate` en el servidor. Si el plan
  no lo incluye, la alternativa es instalar `vendor/` en local con
  `composer install --no-dev --optimize-autoloader`, subirlo por FTP, y correr las migraciones
  desde una ruta temporal protegida — más lento y más frágil, pero viable.
- **Cron jobs**, para la única tarea programada del sistema.
- **Certificado SSL** activo para `prosello.com.mx` (Hostinger lo emite gratis).

Y una restricción del entorno que condiciona dos pasos: el hosting **deshabilita `proc_open`,
`exec`, `shell_exec`, `system`, `popen` y `symlink`**. Consecuencias, ambas verificadas en el
servidor:

- `composer install` corre con `--no-scripts`, porque el script `post-autoload-dump` de Laravel
  invoca `artisan package:discover` a través de `Symfony\Component\Process` y aborta la instalación.
  El descubrimiento de paquetes se hace después, llamando a `php artisan package:discover`
  directamente — desde la shell no interviene `Process` y funciona sin problema.
- El planificador de Laravel (`schedule:run`) queda descartado; ver más abajo.

`php artisan storage:link` tampoco funcionaría, por `symlink`. No hace falta: `FILESYSTEM_DISK` es
`local` y no se sirve ningún archivo desde `storage/`.

## Instalación inicial

1. En hPanel: PHP 8.3, SSL activo, base de datos MySQL creada (anotar nombre, usuario y
   contraseña, que llevan el prefijo de la cuenta).
2. Subir el backend a `~/facturacion/` **sin** `public/`, `tests/`, `node_modules/` ni `.env`.
3. `composer install --no-dev --optimize-autoloader --no-scripts`, seguido de
   `php artisan package:discover` (ver la restricción de `proc_open` arriba).
4. Crear `~/facturacion/.env` desde `deploy/hostinger/env.production.example`, con las credenciales
   reales. `php artisan key:generate`.
5. Permisos de escritura en `storage/` y `bootstrap/cache/`.
6. `php artisan migrate --force`.
7. `npm run build` **en local** → subir el contenido de `frontend/dist/` a `public_html/`. El plan
   compartido no tiene Node; el build siempre se hace en la máquina de desarrollo.
8. Copiar `deploy/hostinger/index.php` y `deploy/hostinger/htaccess-public_html` (renombrado a
   `.htaccess`) a `public_html/`, **después** del `dist/`, para que reemplacen al `.htaccess` que
   Vite copió.
9. `php artisan config:cache && php artisan route:cache && php artisan event:cache`. Es seguro: no
   hay una sola llamada a `env()` fuera de `config/`, que es lo que rompe con la configuración
   cacheada.
10. Cron para la purga de cotizaciones vencidas (ver abajo).

## Despliegue de un cambio posterior

- **Solo frontend**: `npm run build` en local, subir `dist/` a `public_html/`, restaurar
  `.htaccess` e `index.php` del despliegue.
- **Solo backend**: subir los archivos cambiados a `~/facturacion/`, y si hubo migraciones o
  cambios de configuración, `php artisan migrate --force` y
  `php artisan config:cache && php artisan route:cache && php artisan event:cache`.

`config:clear` antes de recachear cuando algo no toma efecto: la configuración cacheada es la causa
habitual de "cambié el `.env` y no pasó nada".

## Tarea programada

El sistema tiene **una** tarea: `cotizaciones:purgar-vencidas`, declarada en `routes/console.php`
como `dailyAt('03:00')`. No hay colas ni jobs, así que no hace falta ningún worker persistente —
que es justamente lo que un hosting compartido no puede sostener.

La forma canónica sería un cron por minuto con `schedule:run`. **En este servidor no es viable**, y
no por el intervalo del cron: Hostinger deshabilita `proc_open` (junto a `exec`, `shell_exec`,
`system`, `popen` y `symlink`), y `Illuminate\Console\Scheduling\Event::runCommandInForeground()`
lanza cada tarea con `Symfony\Component\Process`, que sin `proc_open` ni siquiera se construye.
Verificado en el servidor:

```
Symfony\Component\Process\Exception\LogicException: The Process class relies on proc_open,
which is not available on your PHP installation.
```

`schedule:run` no fallaría de forma visible: mientras ninguna tarea esté vencida no construye
ningún `Process`, así que el cron se vería sano y las tareas simplemente no correrían. Por eso el
comando se invoca directamente:

```
0 3 * * * /usr/bin/php <ruta>/facturacion/artisan cotizaciones:purgar-vencidas
```

La contrapartida honesta: con esta forma, toda tarea programada que se agregue en el futuro a
`routes/console.php` **no se ejecutará** hasta que se le agregue su propia línea de cron. Queda
anotado en `deploy/hostinger/README.md` como advertencia junto a la línea.

## Fuera de alcance

- CI/CD, despliegue automatizado por git push o GitHub Actions. El despliegue es manual y
  documentado.
- Entorno de staging. Hay un solo ambiente publicado.
- Paso de Facturapi a `live` y timbrado real contra el SAT.
- Migración a VPS, Redis, colas con worker o Horizon.
- Backups automatizados más allá de los que hace hPanel por su cuenta.
- Capacitor / empaquetado móvil.
- Cualquier cambio de lógica de negocio, pantallas o endpoints.

## Criterios de aceptación

1. `https://prosello.com.mx/` sirve el SPA; `http://` y `www.` redirigen con 301 a la forma
   canónica.
2. `https://prosello.com.mx/up` responde el healthcheck de Laravel, no `index.html`.
3. `GET /sanctum/csrf-cookie` deja la cookie XSRF, y el login por `POST /api/v1/...` devuelve 200 y
   sesión válida. Recargar la página mantiene la sesión.
4. Una ruta profunda del SPA escrita a mano en la barra de direcciones (por ejemplo
   `/facturas/nueva`) carga la aplicación en vez de dar 404.
5. Una petición al API sin sesión devuelve **401 JSON**, no HTML ni redirect.
6. `https://prosello.com.mx/.env`, `/composer.json`, `/storage/logs/laravel.log` y `/artisan`
   devuelven 404: nada del proyecto es descargable.
7. Chrome ofrece "Instalar" la aplicación (manifest y service worker servidos correctamente sobre
   HTTPS).
8. Los assets con hash llegan con `Cache-Control: immutable`; `index.html` y `sw.js`, con
   `no-cache`.
9. Se genera un PDF de factura y uno de cotización, y se leen sin errores de fuentes ni de
   imágenes.
10. El análisis de una Constancia de Situación Fiscal con QR devuelve los datos del contribuyente
    (comprueba que `gd` está presente y operativa).
11. Un correo de recuperación de contraseña llega, y su enlace apunta a `https://prosello.com.mx`.
12. `APP_DEBUG=false`: un error provocado devuelve la página genérica, sin stack ni variables de
    entorno.
13. El cron de `cotizaciones:purgar-vencidas` queda registrado y deja rastro de su ejecución.
14. Los catálogos SAT responden en producción: la búsqueda de clave de producto/servicio y la de
    clave de unidad devuelven resultados, los `<select>` de régimen fiscal y forma de pago vienen
    poblados, y guardar un artículo con una clave válida no es rechazado por la validación. El
    `md5sum` de `storage/app/sat-catalogos.sqlite` en el servidor coincide con el de local.
15. Un archivo `.mjs` del build se sirve con un tipo de JavaScript, no con `text/plain`, y llega con
    `Cache-Control: immutable` como cualquier otro asset con hash.
16. Soltar el PDF de una Constancia de Situación Fiscal en `/clientes/crear` **avanza más allá de**
    *"Leyendo el documento…"*: llega a buscar el QR y termina con datos o con un mensaje, nunca
    esperando para siempre.
17. `verify.sh` falla —no avisa: falla— si el `.htaccess` del servidor pierde el mapeo de `.mjs`.

## Estado de implementación

Implementada el 2026-08-11, fecha en que se verificaron en el servidor las restricciones registradas
en `deploy/README.md`. El host cambió después; ver [022-subdominio-app.md](022-subdominio-app.md).

### El `AddType` de los `.mjs` (2026-08-19)

- **Archivos modificados**: `deploy/hostinger/htaccess-public_html`, `frontend/public/.htaccess`
  —los dos, porque el primero incorpora al segundo íntegro— y `deploy/verify.sh`.
- **Antes de sobrescribir el `.htaccess` del servidor se comprobó que era byte a byte el del
  repositorio.** Es un archivo que se sube a mano y que nadie versiona desde el servidor, así que
  bien podía traer un ajuste hecho ahí y nunca traído de vuelta. No lo traía; de haberlo traído, el
  `scp` lo habría borrado sin dejar rastro.
- **La comprobación nueva se probó fallando primero.** Con el `.htaccess` viejo todavía en el
  servidor, `verify.sh` marcó los dos problemas —`text/plain` y la falta de `immutable`— y terminó
  con `2 comprobación(es) fallaron`. Una comprobación que solo se ha visto pasar no ha demostrado
  nada.
- **Aplicado en producción** con el `scp` del paso 4 de la instalación inicial. `verify.sh` pasa
  completo: `.mjs se sirve como JavaScript (text/javascript)` y `.mjs con hash se cachea como
  inmutable`.
- **No hizo falta redesplegar el SPA.** El `.htaccess` de `frontend/public/` viaja a `dist/` pero el
  despliegue lo excluye a propósito; el que manda en producción es el otro, y ese ya está arriba.

#### Pendiente de verificación visual

Falta que alguien suelte un PDF de constancia real en `/clientes/crear` y confirme que el flujo pasa
de *"Leyendo el documento…"*. Lo que está comprobado es que la causa desapareció: el worker se sirve
con el tipo correcto. Lo que no está comprobado es que no hubiera, detrás de esta, una segunda causa.

## Supuestos asumidos (registro completo)

1. Hosting compartido de Hostinger, plan Premium. No VPS: sin colas ni workers, no se justifica.
2. Dominio `prosello.com.mx`, con SSL de Hostinger.
3. **Un solo origen** para SPA y API. Se descartó el esquema de subdominios `app.` + `api.` por el
   costo en CORS y en alcance de la cookie de sesión.
4. Host canónico **sin `www`**, forzado por 301.
5. El código de Laravel vive fuera del docroot, como carpeta hermana de `public_html`; solo el
   front controller queda expuesto. La ruta es relativa para no depender de si el dominio es el
   principal de la cuenta o uno adicional.
6. El build del frontend se hace **en local** y se sube compilado. El plan compartido no tiene Node.
7. `vendor/` se instala en el servidor vía SSH; subirlo por FTP es el plan de contingencia.
8. Base de datos MySQL del propio hosting. Sesión, caché y cola sobre `database`.
9. Correo saliente por el SMTP de Hostinger.
10. Facturapi arranca en `test`. El paso a `live` es una decisión posterior y separada.
11. El despliegue es manual y documentado, sin CI/CD.
12. Un solo ambiente publicado, sin staging.
13. Los artefactos de despliegue se versionan en `deploy/hostinger/`, no se improvisan en el
    servidor.
14. `config:cache` es seguro porque no hay llamadas a `env()` fuera de `config/`; si eso cambia, el
    procedimiento deja de ser válido.
15. No se toca ninguna regla de negocio, pantalla ni endpoint.
16. `backend/storage/app/sat-catalogos.sqlite` sigue fuera de git y se sincroniza por checksum desde
    la máquina de desarrollo. Es la única excepción a "el despliegue no toca `storage/`", y lo es
    porque ese archivo es un artefacto de la aplicación, no estado del servidor.
17. **(Agregado el 2026-08-19)** El servidor **no** conoce todas las extensiones que produce un build
    moderno, y las que no conoce las sirve como `text/plain`. Ya había pasado con `.webmanifest`;
    pasó otra vez con `.mjs`. La regla que queda es que **cada extensión nueva que aparezca en
    `dist/` necesita su `AddType`**, y que el lugar donde se comprueba es `verify.sh`, no la memoria
    de quien despliega.
18. **(Agregado el 2026-08-19)** Un fallo que solo existe en producción y que ninguna prueba puede
    alcanzar se atrapa **desde fuera, con `curl`**, que es lo que `verify.sh` ya hacía para todo lo
    demás. No se agrega ninguna prueba de navegador ni ningún entorno de staging para cubrirlo.
