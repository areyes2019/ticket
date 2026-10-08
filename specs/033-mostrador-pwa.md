# Spec: Aplicación de mostrador (instalable, tres accesos y captura por pasos)

> **Estado: implementada** el 2026-10-08. Los puntos marcados **[decidir]** se asumieron al adaptar
> la remota y el usuario los aprobó en bloque el mismo día. Ver "Estado de implementación".

**Referencia:** reescritura de [remotas/029-pwa-mostrador.md](remotas/029-pwa-mostrador.md), que se
diseñó para la arquitectura anterior (Vue 3 + Vue Router + API + Sanctum + `VitePWA`). Se conservan
sus reglas de negocio: aplicación instalable en el celular, modo mostrador que **no deja llegar a
ningún otro módulo**, accesos fijos en una cuadrícula para el dedo, captura de una pantalla por paso
con tarjetas de clientes y artículos, carrito propio, pantallas de opción para los datos fiscales,
revisión antes de timbrar, compartir por el menú del aparato, cobro de la venta con la caja
preseleccionada, aviso claro sin conexión y ningún dato del sistema guardado en el aparato. La parte
del navegador y el protocolo entre navegador y servidor se rehicieron para Laravel + Blade +
JavaScript nativo ([001](001-inicio-proyecto.md)).

**Modifica (solo la redirección al terminar, con `origen=mostrador`; ninguna regla de negocio
cambia):**

- [005-gestion-clientes.md](005-gestion-clientes.md): `ClienteController::store`.
- [011-cotizaciones.md](011-cotizaciones.md): `CotizacionController::store` y
  `EnvioCotizacionController::correo`.
- [012-facturacion.md](012-facturacion.md): `FacturaController::store` y `timbrar`, y
  `EnvioFacturaController::correo`.
- [019-pedidos-mostrador.md](019-pedidos-mostrador.md): `PedidoController::store` y
  `PedidoPagoController::store`.
- `public/sw.js`: deja de ser solo el service worker de apagado de la PWA anterior (ver "El service
  worker").

**Se retiró de la remota** lo que pertenecía a la arquitectura anterior, lo que aquí ya existe y lo
que el usuario decidió quitar:

- **El cuarto acceso, "Escanear etiquetas"**, con todo lo suyo: la pantalla de cámara, `lectorQr.ts`,
  linterna, Wake Lock, vibración, respaldo por foto, el pie "Escanear otra" de la entrega y el atajo
  "Escanear" del icono. La corrección 1 de [022](022-ordenes-trabajo.md) quitó el QR del ticket y
  de la etiqueta y dejó la entrega como el botón **"Entregado"**, así que no hay código que leer.
  **Decisión del usuario:** el mostrador queda con **tres accesos**. La "cuadrícula de dos por dos"
  de la remota pasa a tres botones (ver "La pantalla de inicio del mostrador").
- `VitePWA`, `vite.config.ts`, `registerType`, el precache del shell de una SPA, `lib/modoMostrador.ts`
  con `matchMedia`, el guard de Vue Router, `AppLayout` con la propiedad `mostrador`, los componentes
  Vue (`ConstanciaFiscalDropzone`, `DocumentoLineas`, `ArticuloBuscador`, `ClienteCombobox`),
  `lib/compartir.ts` y `lib/totalesDocumento.ts`. Aquí el candado es un **middleware**, las
  pantallas son **Blade** y el JavaScript vive en `public/js`.
- **El cambio a `GET catalogos/usos-cfdi`**: aquí no existe ese endpoint. Uso de CFDI, forma de pago
  y método de pago son enums (`UsoCfdi`, `FormaPago`, `MetodoPago`) y Blade pinta la lista completa
  sin ninguna petición.
- **El arreglo del WhatsApp de la cotización** (retirar Twilio, `pdf-publico`, `urlPdfPublico()`,
  crear `marcar-enviada`, arreglar el modal del escritorio): **ya está hecho**. La cotización y la
  factura se comparten desde el aparato con `public/js/compartir-pdf.js` (menú del aparato, o
  descarga + `wa.me` donde no lo hay, con el archivo bajado antes del toque) y
  `POST cotizaciones/{cotizacion}/marcar-enviada` ya existe ([011](011-cotizaciones.md)). El servidor
  nunca manda WhatsApp.
- **El aviso de "Hay una versión nueva"** (`registerType: 'prompt'`): era la cura de una SPA que se
  queda con su JavaScript viejo días enteros. Aquí cada pantalla la pinta el servidor en cada visita,
  y `app.css` y los `.js` llevan `?v={filemtime}`: **no existe una versión vieja que avisar**. El
  objetivo de la remota (que el aparato nunca trabaje con una versión vieja sin saberlo) se cumple
  por construcción.
- **El ajuste al peso cerrado** ([remotas/030](remotas/030-total-al-peso-cerrado.md)): no existe
  aquí. Los totales salen de `totales-documento.js` y `CalculadoraTotalesDocumento` tal como están.
- **La dependencia de HTTPS** ([remotas/022](remotas/022-subdominio-app.md)): ya se cumple. El
  sistema corre en `https://app.prosello.com.mx` desde el 2026-10-02.
- **[remotas/031](remotas/031-mostrador-consulta.md)** (secciones de consulta en el mostrador): fuera
  de esta spec.

## Historia de usuario

Como usuario único del sistema, quiero una aplicación instalable en el celular, rápida, intuitiva y
de fácil acceso, con botones grandes para usarse con el dedo y **solo tres accesos**: generar
factura, generar cotización y venta al público. Nada más que eso.

Los demás módulos **no se alcanzan por ningún medio desde ese aparato**, porque se trabajan en la
computadora.

## Objetivo / Alcance

1. **La aplicación se vuelve instalable**: manifest, iconos y un service worker escritos a mano en
   `public/`, sin Vite ni npm.
2. **Un modo mostrador**: abierta desde el icono instalado, la aplicación muestra los tres accesos y
   **nada más**, sin menú y sin el resto de los módulos. Abierta en el navegador de la computadora,
   sigue siendo el sistema completo.
3. **Tres capturas por pasos hechas para el dedo** (factura, cotización y venta), que terminan
   llamando a **las mismas rutas web que ya usan los formularios de escritorio**.

**Ninguna tabla ni columna nueva.** Las validaciones, folios, totales, existencias, Tesorería y
timbrado siguen siendo exactamente los mismos: el mostrador es **otra forma de llegar** a lo que el
sistema ya hace, no un sistema paralelo con sus propias reglas.

Arquitectura, según [001](001-inicio-proyecto.md):

- **Laravel resuelve todo**: el candado (middleware), las pantallas (Blade), las tarjetas de
  clientes y artículos (parciales Blade), los resultados (vistas Blade) y la redirección de cada
  alta.
- **Cada alta es un envío normal de formulario** (`POST`, `@csrf`), sin AJAX. Un error de validación
  regresa con `back()->withInput()`, como en el escritorio.
- **JavaScript solo donde hay interacción real**, en archivos de `public/js`:
  - `mostrador.js` (nuevo): los pasos, el carrito, las tarjetas que suman y la lista que carga más al
    llegar al final;
  - `pwa.js` (nuevo): registrar el service worker y el botón "Instalar aplicación";
  - los que ya existen: `totales-documento.js` (totales), `compartir-pdf.js` (WhatsApp) y
    `pedido-cliente.js` (sugerencia por teléfono), sin cambios; `constancia-fiscal.js`, con un solo
    cambio (ver "Los que ya existen").
- **Las únicas peticiones de fondo nuevas** son las dos que traen tarjetas (clientes y artículos), y
  responden **HTML** pintado por Blade, no JSON, igual que las búsquedas dinámicas de los listados
  (`clientes.buscar`, `pedidos.buscar`).

### Por qué el interruptor es la sesión marcada por la `start_url`

El servidor tiene que saber en cada petición si pinta el mostrador o el sistema completo, y desde el
servidor **no se ve** si la ventana es la aplicación instalada (`display-mode` solo lo conoce el
navegador). **Decisión del usuario:** la aplicación instalada abre siempre `/mostrador` (la
`start_url` del manifest), esa ruta **marca la sesión** como de mostrador y un middleware aplica el
candado en cada petición.

Se conserva el motivo de la remota: instalar la aplicación es un acto deliberado, mientras que el
ancho de pantalla es un accidente.

**Consecuencias asumidas:**

- En Android, la aplicación instalada y Chrome **comparten la sesión**. Mientras la sesión del
  mostrador siga abierta, Chrome en ese mismo celular también verá los tres accesos. Se sale con
  **"Cerrar sesión"**, que borra la marca.
- Abrir `/mostrador` a mano en la computadora también marca esa sesión. Se sale igual, cerrando
  sesión.
- Instalar la aplicación en la computadora la pondría en modo mostrador, igual que en la remota.

## Backend (Laravel)

### Rutas (web, dentro del grupo `auth` + `AsegurarUsuarioActivo`)

```
GET  /mostrador                                  mostrador.inicio            MostradorController@inicio
GET  /mostrador/venta                            mostrador.venta             MostradorController@venta
GET  /mostrador/factura                          mostrador.factura           MostradorController@factura
GET  /mostrador/cotizacion                       mostrador.cotizacion        MostradorController@cotizacion
GET  /mostrador/{flujo}/cliente-nuevo            mostrador.cliente-nuevo     MostradorController@clienteNuevo   (flujo: factura|cotizacion)
GET  /mostrador/tarjetas/clientes                mostrador.tarjetas.clientes MostradorTarjetasController@clientes
GET  /mostrador/tarjetas/articulos               mostrador.tarjetas.articulos MostradorTarjetasController@articulos
GET  /mostrador/ventas/{pedido}/cobro            mostrador.venta.cobro       MostradorResultadoController@cobro
GET  /mostrador/ventas/{pedido}                  mostrador.venta.listo       MostradorResultadoController@venta
GET  /mostrador/cotizaciones/{cotizacion}        mostrador.cotizacion.listo  MostradorResultadoController@cotizacion
GET  /mostrador/facturas/{factura}               mostrador.factura.listo     MostradorResultadoController@factura
```

Van bajo el prefijo `/mostrador` para que el candado sea una sola regla. **Ninguna ruta existente
cambia de dirección ni de nombre**, y **no hay rutas `POST` nuevas**: las altas van a las de siempre.

### Middleware `CandadoMostrador` (nuevo)

Se agrega al grupo `auth` en `routes/web.php`, junto a `AsegurarUsuarioActivo`.

- `MostradorController@inicio` hace `session()->put('mostrador', true)`. Es la **única** forma de
  encender el modo.
- Con `session('mostrador')` encendido, una petición cuya ruta **no** está en la lista de permitidas:
  - `GET` que no espera JSON → redirige a `mostrador.inicio`, sin aviso;
  - cualquier otra → `403`.
- **Rutas permitidas** (constante `CandadoMostrador::RUTAS`, nombres de ruta con `routeIs`):
  - `mostrador.*`;
  - `logout`;
  - las que las tres capturas usan: `clientes.store`, `clientes.constancia`, `articulos.imagen`,
    `pedidos.cliente-por-telefono`, `pedidos.store`, `pedidos.pagos.store`, `pedidos.ticket`,
    `cotizaciones.store`, `cotizaciones.pdf`, `cotizaciones.enviar`, `cotizaciones.marcar-enviada`,
    `facturas.store`, `facturas.timbrar`, `facturas.pdf`, `facturas.enviar`.
- `dashboard` y `/` no están en la lista, así que llevan a los tres accesos. Una dirección vieja
  guardada, un enlace pegado o un botón que apunte a una pantalla de escritorio terminan en el
  mostrador en vez de mostrar media aplicación.
- El portal público de autofacturación (`autofactura.*`) queda fuera: no pasa por el grupo `auth`.
- **Sin sesión** el middleware `auth` sigue mandando al login y regresando a donde iba con
  `redirect()->intended()`. Como la aplicación instalada abre `/mostrador`, después de entrar cae ahí
  y se marca la sesión.
- `logout` invalida la sesión (ya lo hace `AuthenticatedSessionController::destroy`), así que la
  marca desaparece con ella.

"No se llega por ningún medio" se cumple aquí y no escondiendo el menú: también vale para una
dirección escrita a mano.

### `MostradorController` (nuevo)

- `inicio()`: marca la sesión y pinta `mostrador/inicio`.
- `venta()`, `factura()`, `cotizacion()`: pintan la captura por pasos de cada flujo (ver "Vistas"),
  con lo que necesita el primer paso. Si llega `?cliente={id}` (regreso del alta de cliente), el
  cliente se busca **entre los del usuario** (`$request->user()->clientes()->find()`) y la captura
  arranca en el paso de artículos con él elegido. Un id ajeno se ignora.
- `clienteNuevo(string $flujo)`: el alta de cliente para el mostrador (ver "Vistas").
- Datos que pasa la venta: las cuentas activas y la **caja** (ver "Cobro").
- Datos que pasa la factura: `UsoCfdi::opcionesFactura()`, `FormaPago::opciones()` y
  `MetodoPago::opciones()`, enteros, pintados en Blade. **No hay ninguna petición** para listarlos.

### `MostradorTarjetasController` (nuevo)

Dos acciones que responden **un parcial Blade** con una página de tarjetas, para la lista que carga
más al llegar al final:

- `clientes(Request)`: clientes del usuario, `?q=` sobre razón social, nombre comercial **o** RFC,
  ordenados por razón social, 25 por página. Parcial `mostrador/_tarjetas-clientes`.
- `articulos(Request)`: artículos del usuario (sin borrados), `?q=` sobre nombre, modelo **o**
  nombre del proveedor, ordenados por nombre, 24 por página.
  Parcial `mostrador/_tarjetas-articulos`.

Cada parcial termina con un marcador `data-siguiente="{url de la página siguiente}"` (o sin él en la
última página), así el navegador no arma URLs ni conoce la paginación.

**Búsqueda nueva en los modelos**: los scopes `filtrar()` de hoy filtran **columna por columna con
Y**, y el mostrador busca **una sola caja de texto en varias columnas con O**. Nacen
`Cliente::buscarTexto(string $q)` y `Articulo::buscarTexto(string $q)` (scopes, con `like` y la
misma normalización de RFC que `filtrar()`). `filtrar()` no cambia.

Cada tarjeta de artículo lleva en atributos `data-*` lo que el carrito necesita, y **los mismos
valores** que hoy devuelve `articulos.sugerencias` para una línea: `id`, `nombre`, `modelo`,
`precio_unitario` (`precio_unitario_sin_iva`), `precio_distribuidor`, `tasa_iva` y la URL de la
imagen. Cada tarjeta de cliente lleva `id`, razón social, RFC, `descuento_permanente` y
`es_distribuidor`. **El precio que se muestra sale del servidor**; el navegador solo lo lleva al
formulario, como ya hace `documento-lineas.js`.

### `MostradorResultadoController` (nuevo)

Las pantallas que cierran cada flujo. Cada acción autoriza con la Policy del documento
(`Gate::authorize('view', ...)` u `operar`, la misma que usa su detalle), así un id ajeno responde
`403`.

- `cobro(Pedido)`: el cobro de la venta recién creada (ver "Venta al público").
- `venta(Pedido)`: el ticket y su botón de compartir.
- `cotizacion(Cotizacion)`: folio, cliente, renglones, total, WhatsApp y correo.
- `factura(Factura)`: resultado del timbrado, WhatsApp y correo, o el motivo del fallo.

### Regreso al mostrador: `origen=mostrador`

Las capturas envían a las rutas de siempre con un campo oculto `origen=mostrador` (más `flujo` en el
alta de cliente). Las acciones existentes **solo cambian a dónde redirigen al terminar bien**; un
error de validación sigue regresando con `back()->withInput()`, que es la propia pantalla del
mostrador. Lo resuelve un trait nuevo `App\Http\Controllers\Concerns\RegresaAMostrador`, como
`RegresaABandeja` resuelve `origen=bandeja`:

| Acción | Sin `origen` (hoy) | Con `origen=mostrador` |
|---|---|---|
| `ClienteController::store` | `clientes.index` | `mostrador.{flujo}?cliente={id}` |
| `PedidoController::store` | `pedidos.show` | `mostrador.venta.cobro` |
| `PedidoPagoController::store` | `pedidos.show` | `mostrador.venta.listo` |
| `CotizacionController::store` | `cotizaciones.show` | `mostrador.cotizacion.listo` |
| `FacturaController::store` / `timbrar` | `facturas.show` o `facturas.edit` | `mostrador.factura.listo`, con el mismo flash del resultado |
| `EnvioCotizacionController::correo` | `destinoCotizacion()` | `mostrador.cotizacion.listo` con el flash |
| `EnvioFacturaController::correo` | `facturas.show` | `mostrador.factura.listo` con el flash |

`flujo` se valida contra `['factura', 'cotizacion']`; cualquier otro valor deja la redirección de
siempre. El `origen` se respeta haya o no sesión de mostrador: una captura abierta desde el navegador
también termina en su pantalla de resultado.

## Vistas (Blade)

### Layout `layouts/mostrador.blade.php` (nuevo)

Hereda la cabecera mínima de `layouts/app` (`csrf-token`, `app.css`, iconos, `axios`, `app.js`), sin
`<x-menu-apps />` y sin el menú de usuario:

- **Queda** el nombre del sistema, que en las pantallas interiores es el regreso a
  `mostrador.inicio` (con confirmación si hay captura a medias, ver "La captura por pasos").
- `<link rel="manifest">`, `theme-color` y `pwa.js` (ver "Instalación").
- El área de aviso sin conexión (ver "Sin conexión").

Las pantallas de `/mostrador/*` usan este layout; **nada del escritorio cambia de layout**.

### La pantalla de inicio del mostrador (`mostrador/inicio`)

- **Tres botones** que ocupan el alto disponible en partes iguales, uno debajo del otro, cada uno con
  su ícono grande y su texto, y **toda la superficie tocable** (cada botón es un `<a>` de bloque).
  En un celular de 375 puntos de ancho caben sin desplazar la pantalla. **[decidir]** Tres
  renglones a todo lo ancho en vez de la cuadrícula de 2 × 2 de la remota: con tres botones, la
  cuadrícula deja un hueco que parece un botón que falta.
- **Fijos**: no se reordenan, no se configuran, no cambian con el uso.
- **Sin cifras, gráficas ni pendientes.**
- **"Cerrar sesión"** al pie, como enlace discreto (formulario `POST` a `logout`).

| Acceso | Ícono (Bootstrap Icons, el mismo del menú de escritorio) | Destino |
|---|---|---|
| Generar factura | `receipt` | `mostrador.factura` |
| Generar cotización | `file-earmark-text` | `mostrador.cotizacion` |
| Venta al público | `ticket-perforated` | `mostrador.venta` |

### La captura por pasos (`mostrador/captura.blade.php` + parciales por paso)

Una sola página por flujo, con **un `<section data-paso>` por paso**; `mostrador.js` muestra uno a la
vez. Arriba, el indicador del paso actual; abajo, el botón grande para seguir. Donde el toque ya
decide (cliente, uso de CFDI, forma de pago, método de pago), **no hay botón de "Siguiente"**: tocar
elige y avanza.

Todo lo capturado vive en **un solo `<form method="POST">`** con campos ocultos, que es lo que se
envía al final a la ruta de siempre. Los formularios de escritorio **no se tocan**.

Reglas comunes a los tres flujos:

- **Volver atrás paso por paso sin perder nada.** Cada paso se refleja en el `#hash` de la URL
  (`/mostrador/cotizacion#articulos`) con `history.pushState`, así el gesto de "atrás" del celular
  regresa al paso anterior en lugar de salir de la página.
- **Salirse a medias pide confirmación** (`beforeunload` y `data-confirmar` en el enlace al inicio)
  cuando el carrito tiene algo. **Nada se guarda en el servidor hasta el botón final.**
- **Borrador en el navegador [decidir]:** el carrito, el cliente elegido y los datos fiscales se
  copian a `sessionStorage` (clave `mostrador:{flujo}`) en cada cambio, y se restauran al volver a
  la página. Así sobreviven a ir al alta de cliente y regresar, o a una recarga. `sessionStorage`
  muere al cerrar la ventana, así que **la aplicación nunca abre con la venta de ayer a medias**.
  Las pantallas de resultado lo borran. Envolver cada lectura y escritura en `try/catch`: si el
  navegador no lo permite, la captura funciona igual, solo sin borrador.
- **Tras un error de validación**, Laravel regresa a la misma página con `old()`. Blade imprime lo
  enviado (`@json(old())`) en un atributo `data-anterior` y `mostrador.js` reconstruye la captura
  desde ahí (tiene prioridad sobre el borrador) y la abre en el paso **Carrito**, con los errores
  junto a cada renglón (`lineas.{i}.*`) o arriba (los demás).
- **Los totales se calculan con `TotalesDocumento.calcular()`** de `totales-documento.js`, el mismo
  que usa el escritorio. No se reimplementa la aritmética. El servidor recalcula al guardar, como
  siempre; lo del navegador es informativo.
- **El descuento permanente del cliente** ([023](023-descuento-permanente-cliente.md)) se aplica a
  cada renglón como porcentaje, y **el precio distribuidor** ([028](028-precio-distribuidor.md)) se
  usa cuando el cliente lo es, con las mismas reglas que `documento-lineas.js`. Cambiar de cliente
  los reemplaza en todos los renglones ya capturados.

#### Paso de cliente (factura y cotización)

- Arriba, dos botones grandes: **"Subir constancia"** y **"Nuevo cliente"**. Debajo, el buscador; el
  resto, la lista de clientes en tarjetas.
- **La lista se ve desde que abre**, sin escribir nada; al llegar al final se pide la página
  siguiente (`IntersectionObserver` sobre el marcador `data-siguiente`). El buscador vuelve a pedir
  la primera página con `?q=` (con una espera de 300 ms entre teclas).
- **Cada tarjeta**: razón social grande, RFC en monoespaciado, teléfono y correo si los tiene, y el
  descuento permanente si es mayor a cero. Toda la superficie es tocable y **tocarla elige al
  cliente y avanza**.
- **"Subir constancia"** y **"Nuevo cliente"** llevan a `mostrador.cliente-nuevo` (ver abajo). Para
  la factura, "Subir constancia" es el botón principal y el que la pantalla sugiere: un CFDI exige
  RFC, régimen y código postal correctos.

#### Alta de cliente (`mostrador/cliente-nuevo`)

Página propia, sin AJAX nuevo:

- Arriba, la carga de la constancia: el parcial `clientes/_constancia` con `constancia-fiscal.js`.
  Lee el archivo (PDF o foto), consulta al SAT con `POST clientes/constancia` y
  **precarga** el formulario.
  - **[decidir]** En el celular, el campo de archivo ofrece elegir el PDF que el cliente trae en el
    teléfono **o tomar una foto** de la constancia (`accept` con PDF e imágenes; Android ofrece la
    cámara ahí mismo). El QR de la foto lo lee el detector del navegador si existe y, si no, **el
    servidor** con `QrLector` (chillerlan + GD), como hoy. La cámara en vivo de la remota no hace
    falta: una foto llega al mismo lugar sin abrir video.
  - Si el RFC ya existe, el aviso de 006 ofrece **"Usar este cliente"**, que regresa a
    `mostrador.{flujo}?cliente={id}`, en lugar de "Abrir su ficha" (una pantalla que el candado no
    permite).
- Debajo, un formulario con **solo** RFC, razón social, régimen fiscal (`select` de
  `RegimenFiscal::opciones()`), código postal fiscal, teléfono y correo. Campos ocultos
  `es_distribuidor=0`, `descuento_permanente=0`, `origen=mostrador` y `flujo`. Se envía a
  `clientes.store` con `ClienteRequest` **sin cambios**: mismas reglas que el alta de escritorio.
- Al guardar, regresa a `mostrador.{flujo}?cliente={id}` y la captura sigue en artículos, con el
  carrito restaurado del borrador si lo había.
- Una cotización no se timbra: un RFC mal escrito ahí se corrige antes de facturar. Por eso el alta
  a mano se acepta (supuesto 48 de la remota).

#### Paso de artículos (los tres flujos)

- **Tarjetas en cuadrícula**: imagen del artículo si la tiene (la ruta `articulos.imagen`, como el
  listado), o un recuadro con ícono si no; nombre, modelo y precio unitario sin IVA.
- **El catálogo se ve desde el arranque**, con la página siguiente al llegar al final y el buscador
  por nombre, modelo o proveedor, igual que los clientes.
- **Un toque suma una unidad y no sale de la pantalla.** La tarjeta ya agregada muestra su cantidad
  encima, y volver a tocarla suma otra.
- **Barra fija al pie**: "n artículos · $total" y el botón que pasa al carrito ("Ver carrito"),
  apagado mientras el carrito esté vacío.
- **Venta al público**: además, el botón **"Artículo suelto"** abre un `<dialog>` con descripción,
  precio unitario **sin IVA** y tasa (los mismos campos que la línea libre del escritorio), y lo agrega como línea libre (`articulo_id` vacío), como el formulario de
  019. Factura y cotización no lo ofrecen porque `FacturaRequest` y `CotizacionRequest` exigen
  artículo del catálogo.

#### Carrito (los tres flujos)

Un renglón por artículo: descripción, precio unitario, botones **"−"** y **"+"**, **quitar** e
importe, con el total al pie. Se puede volver a las tarjetas a agregar más sin perder nada. El botón
de abajo depende del flujo (ver cada uno).

#### Pantallas de opción (factura)

Uso de CFDI y forma de pago son **el mismo parcial** (`mostrador/_paso-opciones`) con otra lista:
buscador arriba y tarjetas abajo con la clave y su descripción. La elegida lleva palomita, y un toque
elige y avanza.

- **La lista entera la pinta Blade** desde el enum. No hay petición.
- **El buscador filtra en el navegador** sobre lo ya pintado (ocultar con `hidden`), al instante.
- **[decidir]** Se muestran **todas** las opciones, sin cargar de 15 en 15 como pedía la remota.
  Son 22 usos y 22 formas: el scroll infinito existía para no pedir de más al servidor, y aquí no
  se pide nada.
- **Sin opción preseleccionada.** Al volver atrás, la elegida se ve marcada.

### Venta al público (`/mostrador/venta`)

Es la venta de mostrador de [019](019-pedidos-mostrador.md), no un documento nuevo.

1. **Cliente**: teléfono y nombre (y correo, opcional), con teclado numérico para el teléfono. Al
   completar los 10 dígitos se consulta `pedidos.cliente-por-telefono` y, si ese número ya compró,
   se **ofrece** rellenar nombre y correo con un toque. Es una sugerencia, no un autocompletado que
   pisa lo escrito.
2. **Artículos**: las tarjetas, con "Artículo suelto".
3. **Carrito**: con el botón **"Guardar y cobrar"**, que envía el formulario a `pedidos.store` con
   `origen=mostrador`. Si un artículo no tiene existencia, la regla de 019 rechaza la venta completa y
   la página regresa al carrito con el aviso en ese renglón.
4. **Cobro** (`mostrador.venta.cobro`, pantalla del servidor): el número de ticket y el total a la
   vista, el monto **ya escrito con el saldo** (se puede bajar para registrar un anticipo) y la
   cuenta **ya elegida: la caja**, que es la cuenta activa de tipo `efectivo` más antigua del
   usuario (por `id`). Las demás cuentas activas se ofrecen en un `select` para cambiarla. Si no hay ninguna cuenta de efectivo activa, no se preselecciona nada. Si no hay cuentas
   activas, el aviso de 019 con su enlace (que en el mostrador lleva al inicio). Se envía a
   `pedidos.pagos.store` con `fecha_pago` = hoy en campo oculto y `origen=mostrador`, con
   `data-enviar-una-vez`.
5. **Listo** (`mostrador.venta.listo`): el ticket dibujado por el servidor (`pedidos.ticket`) en
   grande, **"Compartir por WhatsApp"** con el mismo botón `data-compartir-pdf` del detalle de la
   venta (`image/jpeg`, el mensaje de Configuración ya resuelto, `data-precargar="al-cargar"`), más
   **"Nueva venta"** e **"Inicio"**.

**El cobro es una pantalla aparte porque la venta ya existe.** Si el pago falla (cuenta inactiva,
saldo, red), la página de cobro regresa con el motivo y el número de ticket a la vista, y reintentar
es volver a tocar "Cobrar": **no se captura la venta otra vez** ni quedan dos ventas por una compra.
Si el usuario se sale ahí, la venta queda pendiente de pago y se cobra desde la computadora, como
cualquier venta de 019.

La caja se preselecciona aquí y no en la entrega de 022 a propósito: aquí se cobra la venta que está
ocurriendo enfrente, con el cliente pagando en el mostrador.

**La etiqueta adhesiva no se imprime aquí**: se imprime desde la computadora, como hoy.

### Factura (`/mostrador/factura`)

1. **Cliente**: la pantalla de tarjetas, con "Subir constancia" como camino recomendado.
2. **Artículos**: las tarjetas, sin artículo suelto.
3. **Carrito**.
4. **Uso de CFDI**: pantalla de opciones (`UsoCfdi::opcionesFactura()`).
5. **Forma de pago**: la misma pantalla con `FormaPago::opciones()`.
6. **Método de pago**: dos botones grandes, **PUE** "Pago en una sola exhibición" y **PPD** "Pago en
   parcialidades o diferido", desde `MetodoPago::opciones()`. Sin preselección.
7. **Revisar**: nombre, RFC y total en grande; debajo, en letra chica, uso de CFDI, forma de pago y
   método de pago con su clave y descripción, y un solo botón: **"Timbrar"** (`data-enviar-una-vez`).
   Envía el formulario a `facturas.store` con `origen=mostrador`, que guarda y timbra en el mismo
   paso, como en el escritorio.
8. **Listo** (`mostrador.factura.listo`).

Si `FacturaRequest` rechaza algo (por ejemplo, la regla que cruza forma y método de pago), la página
regresa al carrito con el aviso. Las reglas son las de siempre.

**Resultado:**

- **Timbrada**: el folio fiscal y el UUID, **"Enviar por WhatsApp"** (`data-compartir-pdf` con
  `facturas.pdf`, un texto de resumen y `data-precargar="al-cargar"`), **"Enviar por correo"** (un
  `<dialog>` con el correo del cliente ya escrito y editable, que envía a `facturas.enviar` con
  `origen=mostrador`), **"Nueva factura"** e **"Inicio"**.
- **Pendiente por el PAC** (`ErrorPac`, `EnCurso`): el motivo y **"Reintentar"**, un formulario
  `POST` a `facturas.timbrar` con `origen=mostrador`.
- **Rechazada por datos** (`ErrorDatos`): el motivo de facturapi.io y la indicación **"La factura
  quedó guardada; corrige los datos desde la computadora."** **[decidir]** Sin "Reintentar": los
  mismos datos volverían a fallar, y corregir es editar, que el mostrador no hace.

Por WhatsApp va **solo el PDF**: el menú de compartir de Chrome en Android no acepta `.xml`
(supuestos 75, 81 y 82 de la remota). **El XML le llega al cliente por correo**, que ya adjunta los
dos archivos. Compartir no cambia el estado de la factura.

### Cotización (`/mostrador/cotizacion`)

Pasos **Cliente, Artículos, Carrito y Listo**.

1. **Cliente**: la pantalla de tarjetas. `CotizacionRequest` exige un cliente del catálogo, así que
   los tres caminos (elegir, constancia, a mano) terminan en uno.
2. **Artículos**: las tarjetas, sin artículo suelto.
3. **Carrito**: con el botón **"Guardar cotización"**, que envía a `cotizaciones.store` con
   `origen=mostrador`.
4. **Listo** (`mostrador.cotizacion.listo`): folio, cliente, número de renglones y total, con:
   - **"Enviar por WhatsApp"**: `data-compartir-pdf` con `cotizaciones.pdf`, el texto de resumen,
     `data-marcar` = `cotizaciones.marcar-enviada` y `data-precargar="al-cargar"`. Sin campo de
     teléfono: el contacto se elige en el menú del aparato. Cancelar el menú no marca nada.
   - **"Enviar por correo"**: `<dialog>` con el correo del cliente ya escrito y editable, que envía a
     `cotizaciones.enviar` con `origen=mostrador`. El servidor adjunta el PDF y la deja en "enviada",
     como hoy.
   - **"Nueva cotización"** e **"Inicio"**.

## JavaScript

### `public/js/mostrador.js` (nuevo)

Se activa en `[data-mostrador-captura]`. Hace solo interfaz; ninguna regla de negocio:

- Mostrar un paso a la vez, el indicador, `pushState`/`popstate` con el `#hash`.
- El carrito en memoria: sumar con un toque, "−", "+", quitar, línea libre en la venta; volcarlo a
  los campos ocultos `lineas[i][...]` del formulario antes de enviar.
- Totales con `TotalesDocumento.calcular()`.
- Descuento permanente y precio distribuidor del cliente elegido.
- Listas con más páginas: `IntersectionObserver` sobre `[data-siguiente]`, `axios.get` del parcial y
  `insertAdjacentHTML`. Un `401`/`419` recarga la página (sesión caída), como los demás módulos.
- Filtro local de las pantallas de opción.
- Borrador en `sessionStorage` y reconstrucción desde `data-anterior`.
- Confirmación al salir con el carrito lleno.

Las funciones puras (agregar, quitar, cambiar cantidad, aplicar descuento del cliente, armar los
campos del formulario) se exportan con `module.exports` como `totales-documento.js`, para probarlas
con `node --test`.

### `public/js/pwa.js` (nuevo)

Ver "Instalación".

### Los que ya existen

`totales-documento.js`, `compartir-pdf.js` y `pedido-cliente.js` se usan **sin cambios**.

`constancia-fiscal.js` cambia en una línea: si el contenedor trae `data-url-existente`
(`…?cliente={id}`), el botón del cliente que ya existe lleva ahí en lugar de a su ficha. El parcial
`clientes/_constancia` acepta `$urlExistente` y `$textoAbrir` ("Usar este cliente") para pintarlo.
El escritorio no los pasa y no cambia.

## Instalación

### Manifest (`public/manifest.webmanifest`, nuevo, archivo estático)

```json
{
  "name": "<APP_NAME>",
  "short_name": "Mostrador",
  "start_url": "/mostrador",
  "scope": "/",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "<color de la barra en app.css>",
  "icons": [
    { "src": "/img/pwa/icono-192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/img/pwa/icono-512.png", "sizes": "512x512", "type": "image/png" },
    { "src": "/img/pwa/icono-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
  ],
  "shortcuts": [
    { "name": "Nueva venta", "url": "/mostrador/venta" },
    { "name": "Nueva cotización", "url": "/mostrador/cotizacion" }
  ]
}
```

- **`start_url` es `/mostrador`**: la aplicación instalada **siempre abre en los tres accesos** y
  marca la sesión cada vez.
- **Los atajos [decidir]**: "Nueva venta" se conserva; "Escanear" se va con el escáner y en su lugar
  queda "Nueva cotización". Los atajos no pasan por `/mostrador`, así que si la sesión todavía no
  estaba marcada, el primer atajo lleva a la captura sin candado. **[decidir]** Se acepta: el
  candado se enciende la primera vez que se abre la aplicación desde el icono, y lo normal es que eso
  ya haya pasado.
- **Iconos**: se generan una vez a partir de `public/img/marca/logo-sello-pronto-600.png` y se
  guardan en `public/img/pwa/`. El `maskable` lleva el logo dentro del 80 % central sobre fondo
  liso, porque Android lo recorta en círculo.
- `<link rel="manifest">` y `<meta name="theme-color">` van en `layouts/app` y
  `layouts/mostrador`, para que la instalación se ofrezca desde cualquier pantalla.

### El service worker (`public/sw.js`, se reescribe)

Hoy `sw.js` es el **apagado** de la PWA anterior ("facturacion"): borra sus cachés, se desregistra y
recarga. El nuevo **conserva ese trabajo** y además hace lo mínimo para instalar y avisar sin
conexión:

- `install`: guarda en una caché propia (`mostrador-{VERSION}`) **solo** `sin-conexion.html` y los
  iconos. `skipWaiting()`.
- `activate`: borra **todas las cachés que no sean la suya**, incluidas las de la PWA anterior, y
  `clients.claim()`. **Ya no se desregistra.** Los aparatos que todavía tengan el service worker
  viejo reciben este en su siguiente comprobación y quedan limpios igual.
- `fetch`: solo atiende **navegaciones** (`request.mode === 'navigate'`), que van **siempre a la
  red**; si la red falla, responde `sin-conexion.html` desde la caché. **Todo lo demás pasa sin
  tocarse**, y **nunca se guarda una respuesta del sistema**: ni páginas, ni PDF, ni tickets, ni
  tarjetas. Así es imposible servir datos viejos o de otra sesión.
- `VERSION` es una constante del archivo; se sube cuando cambian `sin-conexion.html` o los iconos.

**Por qué no se precachea la interfaz como en la remota:** allá el shell de la SPA podía arrancar sin
red. Aquí cada pantalla la pinta el servidor, así que sin red no hay pantalla que mostrar salvo el
aviso. "Rápida" aquí es que la aplicación abre directo en los tres accesos, y los recursos estáticos
(`app.css`, los `.js`, los iconos) los guarda el navegador con su caché HTTP normal, versionados con
`?v={filemtime}`.

### `public/js/pwa.js` (nuevo)

- Registra `/sw.js` si el navegador tiene `serviceWorker`. Si no lo tiene, no hace nada y el sistema
  funciona igual.
- **Botón "Instalar aplicación"**: escucha `beforeinstallprompt`, guarda el aviso y muestra el
  botón `[data-instalar-app]`, que lo dispara. Vive en el **dashboard del navegador**, junto al
  saludo o en la barra, oculto (`hidden`) por omisión. **Desaparece** al instalarse (`appinstalled`)
  y no aparece si ya se abre como aplicación (`display-mode: standalone`) o si el navegador no ofrece
  instalar.
- **Sin conexión**: escucha `offline`/`online` y muestra u oculta el aviso del layout (ver abajo).

`pwa.js` se carga en los dos layouts.

## Sin conexión

- **La aplicación abre**: sin red, la navegación cae en `public/sin-conexion.html` (archivo estático
  con los estilos en línea, porque no puede depender de nada más): "Sin conexión. Revisa el internet
  e inténtalo de nuevo." con un botón **"Reintentar"** que recarga la dirección que se pidió.
- **Dentro de una captura**: si la red se cae a medio camino, el aviso del layout lo dice. Las
  tarjetas que no carguen muestran "Sin conexión" con "Reintentar" en lugar de la lista, y un envío
  sin red cae en `sin-conexion.html`; al regresar, la captura sigue en el borrador.
- **Los datos siguen saliendo siempre de la red.** No se guarda nada del sistema en el aparato ni se
  capturan ventas para mandarlas después: una venta que existe solo en un celular no existe en la
  caja.

## Estilos (`public/css/app.css`)

Una sección nueva "Mostrador", con las reglas de [003](003-estilo-uniforme.md) (esquinas rectas,
Bootstrap Icons, componentes Blade existentes donde apliquen):

- botones de acceso a todo lo ancho y del alto disponible;
- tarjetas de cliente, artículo y opción con superficie completa tocable, mínimo 44 × 44 puntos;
- barra fija al pie (`position: sticky`) con conteo, total y botón;
- indicador de pasos;
- nada de desplazamiento horizontal en 375 puntos.

## Pruebas

### Pest

- **`MostradorCandadoTest`**:
  - `GET /mostrador` marca la sesión; sin marca, `/dashboard`, `/articulos` y
    `/tesoreria/cuentas` responden como siempre;
  - con la marca, esas tres redirigen a `mostrador.inicio`; una petición `POST` o JSON a una ruta no
    permitida responde `403`;
  - con la marca, cada ruta de `CandadoMostrador::RUTAS` sigue respondiendo (una por grupo, no todas);
  - `logout` borra la marca;
  - sin sesión, `/mostrador` manda al login y, después de entrar, regresa a `/mostrador`.
- **`MostradorTarjetasTest`**:
  - clientes y artículos: solo los del usuario; `?q=` por razón social, nombre comercial y RFC
    (clientes) y por nombre, modelo y proveedor (artículos); `data-siguiente` presente salvo en la
    última página;
  - la tarjeta de artículo lleva los mismos precios que `articulos.sugerencias` (precio de venta y
    distribuidor) y la tasa de IVA.
- **`MostradorRegresoTest`** (`origen=mostrador`):
  - `clientes.store` → `mostrador.{flujo}?cliente={id}`; un `flujo` inválido conserva la redirección de
    siempre;
  - `pedidos.store` → cobro; `pedidos.pagos.store` → listo; con error de pago regresa al cobro con el
    error y la venta sigue existiendo (una sola);
  - `cotizaciones.store` → listo; `cotizaciones.enviar` → listo con el flash;
  - `facturas.store` con el timbrado simulado: timbrada → listo con folio fiscal; `ErrorPac` → listo
    con "Reintentar"; `ErrorDatos` → listo con el motivo y sin "Reintentar";
  - sin `origen`, las cinco acciones redirigen exactamente como hoy (las pruebas de cada módulo ya lo
    cubren; aquí no se repiten).
- **`MostradorResultadoTest`**: los resultados de documentos ajenos responden `403`; el cobro
  preselecciona la cuenta de efectivo activa más antigua y no preselecciona nada sin ella.
- **`MostradorCapturaTest`**: `?cliente=` de otro usuario se ignora; la factura pinta todos los usos
  de CFDI de factura y todas las formas de pago sin ninguna preseleccionada.
- **Manifest**: `public/manifest.webmanifest` es JSON válido, con `start_url` `/mostrador` y los tres
  iconos existentes en disco.

### `node --test "tests/js/*.test.js"`

- **`mostrador.test.js`**: sumar con un toque, "−" hasta quitar, línea libre, descuento permanente
  aplicado y reemplazado al cambiar de cliente, precio distribuidor, campos `lineas[i][...]` armados
  en orden, y **el total del carrito igual al de `TotalesDocumento.calcular()`** con los mismos datos
  (contra `tests/Fixtures/totales-documentos.json` donde aplique).

### Revisión en un aparato real

No se automatiza y se hace antes de dar por terminada la spec: instalar desde Chrome en Android
contra producción, abrir desde el icono, recorrer los tres flujos, compartir por WhatsApp, probar sin
red y comprobar que Chrome en ese celular también queda en el mostrador hasta cerrar sesión.

## Fuera de alcance

- **Escanear etiquetas** o cualquier lectura de QR con la cámara en vivo (decisión del usuario).
- Que la aplicación **trabaje sin internet**: capturar desconectado y sincronizar después.
- **Imprimir la etiqueta adhesiva** desde el celular.
- **Editar o consultar** documentos ya capturados desde el mostrador
  ([remotas/031](remotas/031-mostrador-consulta.md) queda para otra spec).
- **Entregar ventas** desde el mostrador (el botón "Entregado" de 022 sigue en la computadora).
- Cobrar con terminal bancaria.
- Un cuarto acceso, o cualquier cifra, gráfica o resumen en la pantalla de inicio.
- Notificaciones push, Google Play, Capacitor.
- Cambiar los formularios de escritorio de factura, cotización o venta.
- Un ajuste en Configuración o un rol de usuario para el modo mostrador.

## Criterios de aceptación

1. Abierta desde el icono instalado, la aplicación muestra **tres botones grandes y nada más**: sin
   menú de aplicaciones y sin menú de usuario, con "Cerrar sesión" al pie.
2. Abierta en el navegador de la computadora (sin haber pasado por `/mostrador`), la aplicación es
   la de siempre y su dashboard se ve igual que hoy, más el botón "Instalar aplicación" cuando el
   navegador lo ofrece.
3. En modo mostrador, escribir a mano la dirección de cualquier otra pantalla (artículos, cuentas,
   configuración, dashboard) lleva a los tres accesos.
4. Los tres botones son tocables en toda su superficie y caben sin desplazar en 375 puntos de ancho.
5. "Venta al público" captura por pasos, guarda la venta, cobra con la caja preseleccionada y termina
   mostrando el ticket con un botón que lo comparte por WhatsApp.
6. Si el cobro falla, la pantalla lo dice con el número de ticket y permite reintentar solo el cobro,
   sin capturar la venta otra vez.
7. "Generar factura" pide uso de CFDI, forma de pago y método de pago en **tres pantallas
   distintas**, cada una con un toque que elige y avanza y ninguna con opción preseleccionada, y
   termina en una revisión con nombre, RFC, total y esos tres datos, desde donde timbra. El resultado
   muestra el folio fiscal y permite mandarla por WhatsApp (PDF) o por correo (PDF y XML).
8. El paso de cliente muestra tarjetas **sin escribir nada**, el buscador las filtra, carga más al
   llegar al final, y tocar una elige al cliente y pasa a artículos.
9. Al cliente que no está en el catálogo se le da de alta desde el celular subiendo su constancia
   (PDF o foto) o capturándolo a mano con RFC, razón social, régimen y código postal, y la captura
   sigue con él elegido sin perder el carrito.
10. El paso de artículos muestra el catálogo en tarjetas con su imagen desde que abre, carga más al
    llegar al final, y **tocar una tarjeta suma una unidad sin salir de la pantalla**, con conteo y
    total a la vista al pie.
11. Uso de CFDI y forma de pago muestran su catálogo completo sin escribir nada, con un buscador que
    filtra al instante y palomita sobre la opción elegida al volver atrás.
12. El carrito permite cambiar cantidades y quitar renglones, y volver a artículos sin perder nada.
    El gesto de "atrás" del celular regresa un paso.
13. Un error de validación regresa a la captura con todo lo capturado y el aviso junto al renglón.
14. "Enviar por WhatsApp" de la cotización abre el menú del aparato con el PDF y la deja en
    "enviada"; cancelar el menú la deja en borrador. "Enviar por correo" manda el PDF al correo del
    cliente, que viene escrito y se puede corregir.
15. Ningún importe capturado en el celular difiere del que guarda el servidor ni del que calcularía
    el formulario de escritorio con los mismos datos, incluidos descuento permanente y precio
    distribuidor.
16. Existe un botón visible para instalar la aplicación, que desaparece una vez instalada.
17. La aplicación instalada abre siempre en los tres accesos.
18. Sin internet la aplicación abre y explica que no hay conexión, con un botón de reintentar, en vez
    de quedarse en blanco; ninguna respuesta del sistema queda guardada en el aparato.
19. Los aparatos con el service worker de la PWA anterior quedan sin sus cachés viejas al recibir el
    nuevo.
20. `php artisan test`, `vendor/bin/pint --dirty` y `node --test "tests/js/*.test.js"` en verde. La
    aplicación sigue sin `package.json`, Vite ni CDN.

## Supuestos asumidos (registro completo)

**Decisiones del usuario al adaptar**

1. **Tres accesos**: se quita "Escanear etiquetas", porque la corrección 1 de 022 eliminó el QR de
   entrega y el usuario prefirió no reintroducirlo.
2. **El interruptor es la sesión marcada por la `start_url`** (`/mostrador`), con un middleware de
   candado. Se acepta que Chrome en el mismo celular comparta el modo hasta cerrar sesión.

**Heredados de la remota sin cambio de fondo** (aprobados allá; se adaptaron solo en forma)

3. Ningún otro módulo se alcanza desde el modo mostrador; el candado vive en el servidor, no en
   esconder el menú.
4. La pantalla de inicio no lleva cifras, gráficas ni pendientes, y sus accesos son fijos.
5. Captura de una pantalla por paso, con los formularios de escritorio intactos.
6. Clientes y artículos en tarjetas visibles sin escribir, con scroll infinito y buscador.
7. Un toque suma una unidad; el carrito es una pantalla aparte.
8. Factura en ocho pasos, con tres pantallas fiscales sin preselección y revisión antes de timbrar.
9. Alta de cliente por constancia o a mano, en factura y cotización.
10. WhatsApp desde el menú del aparato con el archivo bajado antes del toque; el XML solo por correo.
11. La venta cobra con la caja preseleccionada y en un paso aparte del alta, para reintentar solo el
    cobro.
12. La etiqueta se sigue imprimiendo desde la computadora.
13. La aplicación instalada abre siempre en el inicio del mostrador.
14. Sin conexión, aviso claro; nunca datos del sistema guardados en el aparato.
15. Botón "Instalar aplicación" en el navegador.

**Adaptaciones a la arquitectura actual**

16. Las altas son **envíos normales de formulario** a las rutas que ya existen, con `origen=mostrador`
    para la redirección; no hay rutas `POST` nuevas ni JSON nuevo.
17. Las tarjetas llegan como **parciales Blade** (HTML), no JSON.
18. Uso de CFDI, forma de pago y método de pago los pinta Blade desde los enums; no hay petición ni
    cambio de endpoint.
19. El alta de cliente es una **página propia** con la constancia de 006 sin cambios, en lugar de un
    diálogo con AJAX.
20. No hay aviso de "versión nueva": con pantallas pintadas por el servidor y recursos versionados no
    existe una versión vieja que avisar.
21. El service worker solo guarda el aviso sin conexión y los iconos, y conserva el apagado de la PWA
    anterior (borrar cachés ajenas) sin desregistrarse.
22. Manifest e iconos son archivos estáticos en `public/`, sin Vite ni npm.
23. El WhatsApp de la cotización y `marcar-enviada` ya existen; esta spec no los toca.

**[decidir] Asumidos al adaptar, aprobados en bloque por el usuario**

24. Tres botones en renglones a todo lo ancho, no una cuadrícula de 2 × 2 con un hueco.
25. Borrador de la captura en `sessionStorage`, que muere al cerrar la ventana.
26. Las pantallas de opción muestran todas sus opciones, sin cargar de 15 en 15.
27. La constancia se sube como archivo o como **foto tomada en el momento**, sin cámara en vivo; el
    QR lo lee el navegador o, si no puede, el servidor.
28. Factura rechazada por datos: se muestra el motivo y se corrige desde la computadora, sin
    "Reintentar" en el mostrador.
29. Atajos del icono: "Nueva venta" y "Nueva cotización".
30. Un atajo abierto antes de la primera apertura desde el icono no enciende el candado; se acepta.
31. La caja es la cuenta activa de tipo `efectivo` con menor `id`; sin ella no se preselecciona nada.
32. Con el candado encendido, una ruta no permitida responde redirección al inicio en `GET` y `403`
    en lo demás.

## Estado de implementación

Implementada el 2026-10-08.

- **Archivos nuevos**:
  - `App\Http\Middleware\CandadoMostrador`, `MostradorController`, `MostradorTarjetasController`,
    `MostradorResultadoController` y el trait `Concerns\RegresaAMostrador`;
  - vistas `layouts/mostrador`, `layouts/_pwa` y `mostrador/` (`inicio`, `captura`,
    `_paso-opciones`, `_tarjetas-clientes`, `_tarjetas-articulos`, `cliente-nuevo`, `cobro`,
    `venta-listo`, `cotizacion-listo`, `factura-listo`);
  - `public/js/mostrador.js`, `public/js/pwa.js`, `public/manifest.webmanifest`,
    `public/sin-conexion.html` y los iconos de `public/img/pwa/` (generados con GD desde
    `logo-sello-pronto-600.png`);
  - pruebas `tests/Feature/MostradorTest.php` y `tests/js/mostrador.test.js`.
- **Archivos modificados**: `routes/web.php` (rutas y middleware), `public/sw.js` (reescrito),
  `Cliente` y `Articulo` (scope `buscarTexto`), `ClienteController`, `PedidoController`,
  `PedidoPagoController`, `CotizacionController`, `FacturaController` (`timbrar` recibe el
  `Request`), `EnvioCotizacionController`, `EnvioFacturaController`, `layouts/app` (manifest,
  `pwa.js`, aviso sin conexión y "Instalar aplicación" en el dashboard), `clientes/_constancia`,
  `constancia-fiscal.js` y la sección "Mostrador" de `app.css`.
- **Decisiones al implementar**:
  - Las fichas tocables son `<a href="#">` y los botones del carrito los crea `mostrador.js`: la
    revisión de estilo (003) no deja escribir `<button>` ni `<input>` a mano en las vistas. Ninguna
    clase usa "tarjeta" por la misma razón (`mostrador-ficha`).
  - La captura lleva `novalidate`: el navegador no puede señalar un campo de un paso oculto.
    `mostrador.js` revisa el paso de cliente de la venta con `reportValidity()` y el servidor valida
    todo.
  - Las fichas de artículo no muestran la existencia: la regla de 019 rechaza la venta y la pantalla
    regresa al carrito con el aviso en el renglón.
  - `/` sigue llevando a `dashboard`, y con el candado encendido `dashboard` lleva al mostrador (dos
    saltos, sin regla nueva).
  - La línea suelta pide el precio **sin IVA**, igual que la línea libre del escritorio, para que el
    total no difiera por redondeo.
  - La cuenta del cobro es un `select` con la caja preseleccionada, no botones de opción.
  - Un solo campo de archivo para la constancia: en Android ya ofrece cámara o archivos.
- **Verificación**: `php artisan test` con 1316 pruebas en verde, `node --test "tests/js/*.test.js"`
  (72) y Pint sin cambios. Además, un recorrido con Chrome headless a 375 × 760 contra una base
  SQLite temporal: los tres accesos, el candado, las listas con scroll infinito y búsqueda, sumar con
  un toque, "atrás" entre pasos, carrito con "+", línea suelta, cobro con la caja preseleccionada,
  ticket, los tres pasos fiscales con filtro y palomita, revisión y resultado de la factura, sin
  errores de consola. Los totales guardados coincidieron con los del carrito.

  **Falta probar en el celular real**: instalar desde Chrome en Android contra producción, abrir
  desde el icono y los atajos, el menú de compartir con el PDF y el ticket, la foto de la constancia
  y el aviso sin conexión del service worker.

