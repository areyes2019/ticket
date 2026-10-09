# Spec: Barra del mostrador (cotizaciones, facturas y catálogo desde el celular)

> **Estado: implementada** el 2026-10-08. Los puntos marcados **[decidir]** se asumieron al adaptar
> la remota y el usuario los aprobó en bloque el mismo día. Ver "Estado de implementación".

**Referencia:** reescritura de [remotas/031-mostrador-consulta.md](remotas/031-mostrador-consulta.md),
que se diseñó para la arquitectura anterior (Vue 3 + Vue Router + API + `lib/*.ts`). Se conservan sus
reglas de negocio: una barra al pie con **Cotizaciones, Facturas y Catálogo**; listas en tarjetas que
arrancan en los últimos 30 días y cuyo buscador alcanza cualquier fecha; detalle a pantalla completa;
**facturar una cotización** en pocos pasos y sin tocar sus renglones; **registrar un pago** con la
caja preseleccionada; reenviar por WhatsApp y por correo; la ficha del artículo para **enseñársela al
cliente** y compartirla; y **nada se edita** desde el celular. La parte del navegador se rehace sobre
lo que construyó [033](033-mostrador-pwa.md): layout `mostrador`, candado de sesión, parciales Blade de
tarjetas y `public/js/mostrador.js`.

**Modifica:**

- [033-mostrador-pwa.md](033-mostrador-pwa.md): el layout gana la barra; las pantallas "Listo" de
  cotización y factura **se vuelven el detalle** (ver "Una sola pantalla por documento"); el candado
  gana rutas.
- [011-cotizaciones.md](011-cotizaciones.md) y [029](029-pago-cotizacion-pedido-orden-trabajo.md):
  `CotizacionPagoController::store` regresa al mostrador con `origen=mostrador`.
- [012-facturacion.md](012-facturacion.md) / [020](020-dashboard-cotizaciones-facturas.md):
  `FacturaController::timbrarCotizacion` (y `store`, su camino sin JavaScript) respeta
  `origen=mostrador` también cuando la cotización **ya tenía factura**.
- [010-imagenes-articulos.md](010-imagenes-articulos.md): la conversión a JPEG de `ficha-articulo.js`
  se muda a un archivo compartido.

**Se retiró de la remota** lo que pertenecía a la arquitectura anterior o que aquí ya existe:

- **Los tres cambios de backend de la remota** (`search` en `GET cotizaciones`, `fecha_desde`/
  `fecha_hasta` en `GET facturas`, `cliente_rfc` en `FacturaResource`): aquí no hay API ni
  `Resource`. Las listas las pinta Blade desde el modelo, que ya tiene el RFC del cliente, y
  `Cotizacion::filtrar()` **ya busca con un solo texto** en folio, razón social, nombre comercial y
  RFC ([014](014-cotizaciones-bandeja.md)). Lo único que falta es la búsqueda de un solo texto en
  facturas (ver "Backend").
- `BarraMostrador.vue`, `ListaDocumentosMostrador.vue`, `SelectorCuentaMostrador.vue`, las seis
  vistas Vue, `lib/memoriaLista.ts`, `lib/pagoCotizacion.ts`, `lib/imagenCompartible.ts`,
  `RUTAS_PERMITIDAS` de `lib/modoMostrador.ts` y `AppLayout` con `pb-24`: aquí son un parcial Blade,
  vistas Blade, funciones de `mostrador.js`, `public/js/imagen-compartible.js` y la constante
  `CandadoMostrador::RUTAS`.
- **"La cuadrícula de dos por dos no cambia"**: aquí el inicio son **tres renglones** (033). Sigue
  sin cambiar: solo cede el alto que ocupa la barra.
- **"No hay refacturación de una cotización cuya factura se canceló"**: aquí el escritorio **sí** la
  permite (`facturaVigente` ignora las canceladas), y el mostrador hace lo mismo que el escritorio
  (ver "Facturar").
- **El ajuste al peso cerrado** ([remotas/030](remotas/030-total-al-peso-cerrado.md)): no existe
  aquí; los totales se muestran tal como están guardados.

## Historia de usuario

Como usuario de la aplicación de mostrador, quiero una barra de herramientas en la parte de abajo con
tres menús —**Cotizaciones**, **Facturas** y **Catálogo**— para poder, desde el celular:

- abrir una cotización y **timbrarla como factura**;
- ver una factura y **reenviarla por correo o compartirla** por otros canales, entre ellos WhatsApp;
- **mostrarle un artículo a un cliente** cuando no estoy en la oficina frente a la computadora y
  solo tengo el teléfono a la mano.

## Objetivo / Alcance

1. **Una barra fija al pie** del mostrador con tres secciones de consulta, sin tocar los tres
   accesos de captura.
2. **Dos listas de documentos** (cotizaciones y facturas) y **sus detalles**, con las acciones que
   el mostrador sí hace: facturar, cobrar, reenviar y reintentar el timbrado.
3. **El catálogo** en tarjetas y una ficha a pantalla completa para enseñar y compartir.

**Ninguna tabla ni columna nueva. Ninguna ruta `POST` nueva**: facturar, cobrar, enviar y reintentar
van a las rutas de siempre con `origen=mostrador`, igual que las capturas de 033. **Editar sigue
fuera**: desde el celular no se corrige una cotización, no se cancela una factura y no se toca un
artículo.

Arquitectura, según [001](001-inicio-proyecto.md) y [033](033-mostrador-pwa.md): pantallas Blade con
el layout `mostrador`; las páginas siguientes de cada lista llegan como **parcial Blade** (HTML), con
el mismo marcador `data-siguiente` de 033; JavaScript solo en `mostrador.js` (listas, pasos, memoria
de la lista, tipo de pago) y en el compartir que ya existe.

### Qué redefine de 033

- **"Editar o consultar documentos ya capturados desde el mostrador"** estaba fuera de alcance. Esta
  spec abre la **consulta** y deja cerrada la **edición**.
- **"Un cuarto acceso"** sigue fuera: los tres accesos son los mismos. La barra no es un acceso más
  de la pantalla de inicio, es la navegación entre secciones.

## Backend (Laravel)

### Rutas (web, dentro del grupo del candado, prefijo `mostrador`)

```
GET /mostrador/cotizaciones                       mostrador.cotizaciones            MostradorConsultaController@cotizaciones
GET /mostrador/cotizaciones/{cotizacion}          mostrador.cotizaciones.ver        MostradorConsultaController@cotizacion
GET /mostrador/cotizaciones/{cotizacion}/facturar mostrador.cotizaciones.facturar   MostradorConsultaController@facturar
GET /mostrador/cotizaciones/{cotizacion}/pago     mostrador.cotizaciones.pago       MostradorConsultaController@pago
GET /mostrador/facturas                           mostrador.facturas                MostradorConsultaController@facturas
GET /mostrador/facturas/{factura}                 mostrador.facturas.ver            MostradorConsultaController@factura
GET /mostrador/catalogo                           mostrador.catalogo                MostradorConsultaController@catalogo
GET /mostrador/catalogo/{articulo}                mostrador.catalogo.ver            MostradorConsultaController@articulo
GET /mostrador/tarjetas/cotizaciones              mostrador.tarjetas.cotizaciones   MostradorTarjetasController@cotizaciones
GET /mostrador/tarjetas/facturas                  mostrador.tarjetas.facturas       MostradorTarjetasController@facturas
GET /mostrador/tarjetas/catalogo                  mostrador.tarjetas.catalogo       MostradorTarjetasController@catalogo
```

**[decidir] Una sola pantalla por documento.** Las direcciones `/mostrador/cotizaciones/{id}` y
`/mostrador/facturas/{id}` **ya existen**: son las pantallas "Listo" de 033. En vez de tener dos
pantallas del mismo documento —la de "recién creada" y la de "consultada desde la lista"— con casi
los mismos botones, **el detalle reemplaza a la pantalla "Listo"**: crear una cotización termina en
su detalle, timbrar una factura termina en su detalle, y la lista lleva al mismo lugar. Las rutas
`mostrador.cotizacion.listo` y `mostrador.factura.listo` **cambian de nombre** a
`mostrador.cotizaciones.ver` y `mostrador.facturas.ver` (misma dirección), y `MostradorResultadoController`
se queda solo con lo de la venta (`cobro` y `venta`). Se actualizan sus usos: `CotizacionController`,
`FacturaController`, `EnvioCotizacionController`, `EnvioFacturaController` y las pruebas de 033.

### Candado

`CandadoMostrador::RUTAS` ya permite `mostrador.*`, así que las pantallas nuevas no piden nada. Se
agregan las dos acciones que usan:

- `cotizaciones.timbrar` (facturar la cotización);
- `cotizaciones.pagos.store` (registrar el pago).

Todo lo demás sigue redirigiendo a los tres accesos.

### `MostradorConsultaController` (nuevo)

Pinta las pantallas de consulta. Cada detalle autoriza con la Policy del documento (`operar`, la
misma de 033), así un id ajeno responde `403`; el artículo se busca entre los del usuario
(`404` si es ajeno o está borrado).

- `cotizaciones()`, `facturas()`, `catalogo()`: la pantalla con el buscador y **la primera página ya
  pintada** (sin esperar una petición), con el mismo parcial que sirve las siguientes.
- `cotizacion(Cotizacion)`: carga `cliente`, `lineas`, `pagos`, `facturaVigente` y
  `venta.facturaVigente`, y calcula el estado del botón "Facturar" (ver abajo).
- `facturar(Cotizacion)`: si `motivoNoFacturable()` no es `null`, regresa al detalle con ese motivo
  en `error`. Si no, pinta los pasos fiscales con `UsoCfdi::deFactura()`, `FormaPago::cases()` y
  `MetodoPago::cases()`, como la captura de factura de 033.
- `pago(Cotizacion)`: si `puedeRegistrarPago()` es falso, regresa al detalle con el motivo. Si no,
  pinta el cobro (ver "Registrar pago") con las cuentas activas, la **caja** preseleccionada con la
  misma regla de 033 (cuenta activa de tipo `efectivo` con menor `id`) y `destinoAlCobrar()`.
- `factura(Factura)`: carga `cliente` y `lineas`.
- `articulo(Articulo)`: la ficha.

La regla de la caja hoy vive dentro de `MostradorResultadoController::cobro`; **se extrae** a un
método (`Cuenta::cajaDe(User)` o un `scope`) para que el cobro de la venta y el pago de la cotización
la compartan en vez de copiarla.

### `MostradorTarjetasController` (se amplía)

Tres acciones nuevas, con el mismo contrato que `clientes` y `articulos`: un parcial Blade con una
página de tarjetas y `data-siguiente` salvo en la última.

- `cotizaciones(Request)`: cotizaciones del usuario, más recientes primero (`created_at` desc, `id`
  desc), 20 por página.
  - **Sin `q`**: solo las creadas en los últimos 30 días (`Cotizacion::DIAS_CADUCIDAD`), desde el
    inicio del día de hace 30 días en la zona del negocio.
  - **Con `q`**: sin límite de fecha, con `Cotizacion::filtrar(['texto' => …, 'folio' => …])`, la
    misma búsqueda de la bandeja (folio con o sin `COT-`, razón social, nombre comercial o RFC). La
    interpretación del folio se reutiliza de `ListadoCotizacionesRequest::folio()` (se vuelve
    estática o se mueve al modelo), no se copia.
- `facturas(Request)`: facturas del usuario, más recientes primero, 20 por página, con la misma regla
  de los 30 días sin `q`. Con `q`, el scope nuevo `Factura::buscarTexto()` (abajo).
- `catalogo(Request)`: artículos del usuario sin borrados, con `Articulo::buscarTexto()` de 033
  (nombre, modelo o proveedor), ordenados por `id` de menor a mayor —el orden de la lista de
  artículos del escritorio— (corrección 1), 24 por página.

**Los 30 días** salen de la caducidad de la cotización ([011](011-cotizaciones.md)): la lista sin
filtrar es, casi exactamente, lo que sigue vivo. Las facturas usan el mismo plazo para que las dos
listas hermanas se comporten igual.

### `Factura::buscarTexto(string $q)` (scope nuevo)

El listado de facturas filtra **columna por columna con Y** (`filtrar()`), y el mostrador busca **un
solo texto en varias columnas con O**, igual que pasó con clientes y artículos en 033. Busca en:

- folio interno y folio fiscal, con la misma lectura de `ListadoFacturasRequest::folio()` ("12",
  "FAC-0012", "A12"), reutilizada, no copiada;
- razón social, nombre comercial o RFC del cliente (con borrados, como `filtrar()`);
- UUID (folio fiscal SAT), por coincidencia parcial.

`filtrar()` no cambia y el escritorio no lo usa.

### Regreso al mostrador (`origen=mostrador`)

| Acción | Sin `origen` (hoy) | Con `origen=mostrador` |
|---|---|---|
| `CotizacionController::store` | `cotizaciones.show` | `mostrador.cotizaciones.ver` (antes `…cotizacion.listo`) |
| `EnvioCotizacionController::correo` | `destinoCotizacion()` | `mostrador.cotizaciones.ver` con el flash |
| `FacturaController::store` / `timbrar` | `facturas.show` o `facturas.edit` | `mostrador.facturas.ver` con el flash |
| `FacturaController::timbrarCotizacion` (sin JSON) | igual que `store` | `mostrador.facturas.ver`; **si ya tenía factura**, a esa factura en `mostrador.facturas.ver` con el aviso, en vez de `facturas.show` (que el candado no deja ver) |
| `EnvioFacturaController::correo` | `facturas.show` | `mostrador.facturas.ver` con el flash |
| `CotizacionPagoController::store` | `destinoCotizacion()` | `mostrador.cotizaciones.ver` con el aviso del pago (sin los enlaces "Ver venta" / "Ver orden de trabajo", que llevan a pantallas del escritorio) |

Los errores de validación siguen regresando con `back()`, que es la propia pantalla del mostrador:
los pasos de facturar o la pantalla de pago.

## Vistas (Blade)

### La barra (`mostrador/_barra.blade.php`)

Fija al pie, con tres enlaces del mismo ancho —ícono arriba, texto debajo— y toda la superficie
tocable.

| Sección | Ícono (Bootstrap Icons, el del menú de escritorio) | Destino |
|---|---|---|
| Cotizaciones | `file-earmark-text` | `mostrador.cotizaciones` |
| Facturas | `receipt` | `mostrador.facturas` |
| Catálogo | `box-seam` **[decidir]** | `mostrador.catalogo` |

Los dos primeros son los íconos con que la factura y la cotización aparecen en los tres accesos, para
que el mismo documento se reconozca por el mismo dibujo.

- **La sección actual se ve marcada** (`aria-current="page"` y color), por nombre de ruta:
  `mostrador.cotizaciones*` marca Cotizaciones, etc.
- **Dónde aparece**: en el inicio y en las tres secciones con sus detalles. La vista lo pide con
  `@section('barra')` y el layout la incluye solo entonces. **No aparece** en las capturas por pasos
  (factura, cotización, venta), en el alta de cliente, en el cobro de la venta, ni en **los pasos de
  facturar y la pantalla de pago** de una cotización: ahí abajo vive el botón que cierra la
  operación, y un toque de más tiraría una captura a medias. **[decidir]**
- **No hay botón de "Inicio"**: se vuelve a los tres accesos con el nombre del sistema en la barra
  de arriba, como hoy.
- `contenido-mostrador` deja al pie el hueco del alto de la barra cuando la lleva, para que el último
  renglón de una lista no quede debajo. Los tres accesos descuentan ese alto y **siguen cabiendo sin
  desplazar en 375 puntos**.

### Las dos listas de documentos

Cotizaciones y facturas son **la misma pantalla con otra lista**: un parcial
`mostrador/_lista-documentos` con el buscador arriba y la lista de tarjetas debajo, al que se le dice
qué parcial de tarjetas usar y de dónde trae las siguientes. Mismo JavaScript de listas de 033
(`IntersectionObserver` sobre `data-siguiente`, buscador con espera de 300 ms, "Sin conexión" con
"Reintentar").

- **Sin escribir nada**: los últimos 30 días, del más reciente al más viejo, cargando más al llegar
  al final.
- **El buscador ignora la fecha**: en cuanto hay texto la búsqueda alcanza cualquier documento; al
  borrarlo, la lista vuelve a los 30 días. Una línea de ayuda bajo el buscador lo dice: "Últimos 30
  días. Busca para ver anteriores."
  - Cotizaciones: **folio, cliente o RFC**.
  - Facturas: **folio, cliente, RFC o folio fiscal (UUID)**.
- **No hay más filtros**: ni estado, ni rango de fechas, ni exportación.

**La tarjeta** (`mostrador/_tarjetas-cotizaciones`, `mostrador/_tarjetas-facturas`): un `<a>` de
bloque al detalle con **folio** y **cliente** en grande, **fecha** y **estado** en chico (con la
misma `etiqueta-*` de color del escritorio) y el **total** destacado a la derecha. La factura muestra
`folioVisible()` (el folio fiscal cuando está timbrada).

#### La lista recuerda dónde ibas

Al volver de un detalle, la lista aparece **con lo que estaba escrito en el buscador, lo que ya se
había cargado y a la altura en la que ibas**.

**[decidir]** Cómo, sin guardar datos del sistema en el aparato: `mostrador.js` guarda en
`sessionStorage` (clave `mostrador:lista:{seccion}`) **solo** el texto buscado, cuántas páginas
había cargadas y la posición del scroll — **nunca las tarjetas**. Al volver, pide de nuevo esas
páginas al servidor (el parcial acepta `?paginas=n` para traer de la 1 a la n en una sola petición,
con tope de 10) y regresa a la altura. Así lo que se ve siempre sale de la red, como manda 033, y la
memoria muere al cerrar la ventana o al **cerrar sesión** (el formulario de "Cerrar sesión" borra
las claves `mostrador:*`, junto con los borradores de captura).

### Una sola pantalla por documento

Las vistas `mostrador/cotizacion-listo` y `mostrador/factura-listo` se reemplazan por
`mostrador/cotizacion` y `mostrador/factura`. Arriba, un enlace discreto de regreso
("‹ Cotizaciones", "‹ Facturas") a la lista, además del gesto de atrás del teléfono. Al llegar desde
una captura, el aviso de éxito del layout dice qué pasó ("Cotización COT-0124 creada.", "Factura
timbrada…") y la pantalla es la misma que desde la lista. **[decidir]** Los botones "Nueva
cotización", "Nueva factura" e "Inicio" de las pantallas "Listo" se van: la barra y el nombre del
sistema ya llevan a esos lugares.

`data-mostrador-limpiar` (borrar el borrador de la captura) **se conserva** en las dos vistas: el
documento ya existe.

### Cotización — `/mostrador/cotizaciones/{id}`

Pantalla completa, no un diálogo. Muestra el folio, el estado, el cliente con su RFC, la fecha, los
renglones (descripción, cantidad, precio unitario e importe), los totales al pie y, si tiene pagos,
**pagado y saldo pendiente**. Si tiene venta o factura, una línea lo dice con su folio.

Debajo, los botones, en este orden:

#### "Facturar"

Lleva a `mostrador.cotizaciones.facturar`: **los pasos fiscales de la captura de factura de 033**,
con el cliente y los renglones ya resueltos por la cotización:

```
Uso de CFDI → Forma de pago → Método de pago → Revisar → Listo
```

- **Cinco pasos, no ocho.** Los tres primeros no existen en este camino.
- **Arriba, una línea fija**: "Facturando la cotización COT-0124 — {cliente}".
- **Los renglones no se editan.** El formulario va a `cotizaciones.timbrar` con solo `uso_cfdi`,
  `forma_pago`, `metodo_pago` y `origen=mostrador`; `TimbrarCotizacionRequest` toma cliente, descuento
  global y líneas de la cotización tal como están e **ignora lo que llegue** en esos campos. Es **el
  mismo camino del timbrado directo del escritorio** ([020](020-dashboard-cotizaciones-facturas.md)),
  no uno paralelo.
- **Las pantallas de opción son las de 033** (`mostrador/_paso-opciones`, `MetodoPago` en dos botones,
  sin preselección, filtro local). El paso "Revisar" muestra nombre, RFC y total en grande, los tres
  datos fiscales en chico, los **avisos de precio** de la cotización si los hay (`avisosDePrecio()`,
  como el diálogo de timbrar del escritorio) y un solo botón **"Timbrar"** (`data-enviar-una-vez`).
- **Volver atrás desde el primer paso** regresa al detalle de la cotización.
- **Listo** es el detalle de la factura recién creada.

El botón según la cotización —la misma regla del escritorio, `motivoNoFacturable()`:

| Situación | Qué muestra |
|---|---|
| Se puede facturar | **"Facturar"**, activo. |
| Tiene factura **pendiente** o **borrador** (sin timbrar) | **"Ver su factura"**, que abre esa factura en el mostrador para reintentar el timbrado. No crea otra. |
| Tiene factura **timbrada** | Apagado, "Ya facturada en {folio}", con enlace a esa factura. |
| Su **venta** ya se facturó | Apagado, con el motivo y enlace a esa factura. |
| En **borrador** | Apagado, "Envíala primero": se manda por WhatsApp o correo justo debajo. |
| Tiene **líneas libres** | Apagado, con el motivo: se corrigen en la computadora. |

**Una cotización cuya factura se canceló vuelve a poder facturarse**, como en el escritorio
(`facturaVigente` ignora las canceladas). **[decidir]** La remota lo prohibía, pero allá también lo
prohibía el escritorio; aquí el mostrador sigue al escritorio.

#### "Enviar por WhatsApp" y "Enviar por correo"

Los mismos de la pantalla "Listo" de 033, sin cambios: `data-compartir-pdf` con
`cotizaciones.pdf`, `data-marcar` = `cotizaciones.marcar-enviada` y `data-precargar="al-cargar"` (el
PDF se baja **al entrar** y el botón dice "Preparando..." mientras tanto); cancelar el menú no marca
nada. El correo es el `<dialog>` con la dirección del cliente ya escrita y corregible, a
`cotizaciones.enviar` con `origen=mostrador`.

Compartir **mueve el estado en pantalla** de borrador a enviada sin recargar (`data-estado-documento`,
como ya hace `compartir-pdf.js`), y entonces "Facturar" sigue apagado hasta recargar. **[decidir]**
Se acepta: al volver a abrir la cotización, "Facturar" ya aparece activo.

#### "Registrar pago" — `/mostrador/cotizaciones/{id}/pago`

Solo si `puedeRegistrarPago()`. Si no, en su lugar va una línea con el motivo de
`motivoRechazoPago()` ("Los pagos se registran en su venta …", "La cotización ya está pagada por
completo.").

Pantalla propia, **la misma forma que el cobro de la venta de 033**:

- El **saldo pendiente** a la vista y el **monto ya escrito con ese saldo**, que se puede bajar.
- La **fecha de hoy**, editable (no futura: la regla de `CotizacionPagoRequest`).
- La **cuenta** con la caja preseleccionada y las demás activas en el `select`. Sin cuentas activas,
  el aviso de 033 ("da de alta una cuenta desde la computadora").
- **Si este pago crea la venta** (`destinoAlCobrar()->creaVenta()`, [029](029-pago-cotizacion-pedido-orden-trabajo.md)):
  los datos que la venta necesita —**nombre, teléfono** (10 dígitos, teclado numérico) y correo
  opcional—, **ya escritos con los del cliente** y corregibles, y una línea que avisa "Este pago crea
  la venta y su orden de trabajo". Son los mismos campos del diálogo del escritorio
  (`cotizaciones/_primer-pago`). Si el pago no crea venta y hay razón (`razonSinVenta()`), la línea
  la dice.

**El tipo de pago no se pregunta: se deduce del monto**, que es el único dato que el usuario sabe.

- Monto **igual al saldo**: `pago_total` si no había pagos, `saldo` si ya había uno.
- Monto **menor**: `anticipo`.
- **Si ya tiene anticipo**, el monto queda **fijo en el saldo** (se muestra, no se captura) y una
  línea lo explica: "Ya tiene un anticipo: se registra el saldo." — en vez de dejar capturar algo que
  el servidor rechazaría.

La deducción vive en `mostrador.js` como función pura (`tipoDePago(monto, saldo, tienePagos)`),
**comparando en centavos**, y escribe el campo oculto `tipo` al enviar. El servidor revisa todo con
`CotizacionPagoRequest` y `motivoRechazoPago()`, **sin cambios**: si el navegador se equivocara, el
pago se rechaza, no se registra mal.

Se envía a `cotizaciones.pagos.store` con `origen=mostrador` y `data-enviar-una-vez`. Al terminar
regresa al detalle con el aviso: el saldo baja y, si llegó a cero, el estado cambia (pagada, o la
venta lleva el estado del cobro, como en 029). El movimiento de Tesorería lo genera el servidor,
como siempre. Un error regresa a la pantalla de pago con el motivo (bolsa `pago`).

#### Lo que el detalle no ofrece

Editar, eliminar, duplicar, aceptar, marcar entregada y eliminar un pago.

### Factura — `/mostrador/facturas/{id}`

Pantalla completa con el folio (`folioVisible()`), el estado, el cliente con su RFC, la fecha, el
folio fiscal y el UUID cuando está timbrada, los renglones y el total.

- **Timbrada**: "Enviar por WhatsApp" (PDF, `data-precargar="al-cargar"`) y "Enviar por correo" (PDF
  y XML, correo del cliente ya escrito y corregible), como la pantalla "Listo" de 033. **Bajo los dos
  botones, en letra chica**: "Por WhatsApp va el PDF; el XML se manda por correo." Compartir no cambia
  el estado.
- **Pendiente o borrador**: el motivo del fallo (`error_timbrado`) y, si es reintentable,
  **"Reintentar timbrado"** a `facturas.timbrar`; si fue rechazada por datos, "La factura quedó
  guardada; corrige los datos desde la computadora." Las mismas reglas de 033.
- **Cancelada**: lo dice, con su motivo de cancelación, y **no ofrece ningún envío**.

**No ofrece**: cancelar, editar, eliminar, duplicar ni emitir el complemento de pago.

### Catálogo — `/mostrador/catalogo`

Todos los artículos del usuario, de todos los catálogos, en las **mismas tarjetas del paso de
artículos** de 033 (imagen o recuadro con ícono, nombre, modelo, precio), con el buscador por nombre,
modelo o proveedor y carga al llegar al final. No hay pantalla previa para elegir catálogo.

- **El precio va con IVA** (`precio_unitario_con_iva`), con la etiqueta que usa la ficha del
  escritorio ("con IVA", o sin ella si el artículo no es objeto de impuesto). Es el precio que se le
  dice al cliente; en la captura va sin IVA porque de ahí sale un renglón.
- **Tocar una tarjeta no agrega nada a ningún carrito**: es un `<a>` a la ficha. No hay barra de
  carrito ni contadores, y el título dice "Catálogo". Por eso es un parcial propio
  (`mostrador/_tarjetas-catalogo`) que reutiliza las clases de la tarjeta, no el de la captura con
  sus `data-articulo`.

#### La ficha — `/mostrador/catalogo/{id}`

**Pantalla completa**, para voltearle el teléfono al cliente:

- **La imagen en grande** a todo lo ancho; sin imagen, el marcador "Sin imagen" del mismo tamaño.
- **Nombre, modelo y precio con IVA**.
- El botón **"Compartir"**.

**Nunca se muestran el costo, el precio del proveedor, la utilidad ni las existencias.** **[decidir]**
Tampoco el **precio distribuidor**: la ficha del escritorio lo enseña y lo comparte con su propio
botón, pero esta es la pantalla que ve el cliente, y un segundo precio más bajo a la vista es
información que se le dio. Quien necesite cotizar a un distribuidor lo hace con "Generar cotización",
que ya aplica su precio.

**"Compartir"** manda **la foto y el texto** por el menú del aparato. El texto es el de la ficha del
escritorio: `{nombre} — Modelo {modelo} — ${precio con IVA}`.

- **La foto sale en JPEG**, porque WhatsApp trata los `.webp` como calcomanías. La conversión de
  `ficha-articulo.js` (`imagenComoJpeg`) **se muda a `public/js/imagen-compartible.js`**, y la ficha
  del escritorio y la del mostrador la usan desde ahí.
- **La imagen se convierte al entrar a la ficha**, no al tocar el botón, para que el menú del aparato
  abra con el gesto todavía vivo (la regla de 033 con los PDF). Mientras tanto el botón dice
  "Preparando...".
- **Sin foto, comparte solo el texto.** Donde el navegador no puede compartir, el texto se copia,
  como el respaldo de la ficha del escritorio.

**Desde el catálogo no se modifica nada.**

## JavaScript

### `public/js/mostrador.js` (se amplía)

- **Listas**: las de documentos y la del catálogo usan el mismo `iniciarLista` de 033, sacado de la
  captura para que funcione también fuera del `form[data-mostrador-captura]`.
- **Memoria de la lista**: guardar y restaurar `{q, paginas, scroll}` por sección; borrar las claves
  `mostrador:*` al cerrar sesión.
- **Facturar una cotización**: los pasos de 033 (`#hash` con `pushState`, opción que elige y avanza,
  palomita al volver, revisión) sobre un formulario `data-mostrador-facturar` **sin carrito**.
- **Pago**: `tipoDePago()` y el campo oculto `tipo`.

Las funciones puras nuevas (`tipoDePago`, armar la URL de restaurar una lista) se exportan con
`module.exports`, como las del carrito, para `node --test`.

### `public/js/imagen-compartible.js` (nuevo)

`ImagenCompartible.comoJpeg(img, nombre)` → `Promise<File>`, el cuerpo de `imagenComoJpeg` de hoy
(lienzo, fondo blanco, `image/jpeg` a 0.9). `ficha-articulo.js` lo usa y deja de tener su copia; la
ficha del mostrador también.

### Los que ya existen

`compartir-pdf.js`, `totales-documento.js` y `pwa.js`, sin cambios.

## Sin conexión

Las tres secciones **leen siempre de la red**, como todo el mostrador: una lista vieja guardada en el
teléfono terminaría enseñando un precio que ya cambió o un total que ya se pagó. Sin red, la
navegación cae en `sin-conexion.html` (033) y una página de tarjetas que no llega muestra "Sin
conexión" con "Reintentar".

## Estilos (`public/css/app.css`)

En la sección "Mostrador", con las reglas de [003](003-estilo-uniforme.md):

- la barra al pie (`position: fixed`, tres columnas iguales, mínimo 56 puntos de alto, respeta
  `env(safe-area-inset-bottom)`), su sección marcada y el hueco del contenido;
- la tarjeta de documento (folio y cliente grandes, total a la derecha);
- la ficha del catálogo a pantalla completa;
- nada de desplazamiento horizontal en 375 puntos.

## Pruebas

### Pest (`tests/Feature/MostradorConsultaTest.php`)

- **Candado**: con la marca, las ocho pantallas nuevas responden; `cotizaciones.timbrar` y
  `cotizaciones.pagos.store` pasan; `cotizaciones.show`, `facturas.show` y `articulos.edit` siguen
  llevando al inicio.
- **Listas**: sin `q`, solo los documentos de los últimos 30 días, del más reciente al más viejo; con
  `q`, uno de hace 40 días aparece; cotizaciones por folio, razón social y RFC; facturas por folio
  interno, folio fiscal, razón social, RFC y UUID; solo los del usuario; `data-siguiente` salvo en la
  última página; `?paginas=3` trae tres páginas con el marcador de la cuarta.
- **Detalle de cotización**: ajena `403`; el botón "Facturar" en cada fila de la tabla (facturable,
  pendiente, timbrada, venta facturada, borrador, líneas libres, factura cancelada → activo).
- **Facturar**: `facturar` de una no facturable regresa al detalle con el motivo; `cotizaciones.timbrar`
  con `origen=mostrador` y el timbrado simulado termina en `mostrador.facturas.ver`, la factura queda
  ligada a la cotización y **las líneas que lleguen en la petición se ignoran**; con factura vigente
  regresa a esa factura en el mostrador y **no crea otra**.
- **Pago**: la pantalla preselecciona la caja; con anticipo previo el monto no se captura; el
  primer pago que crea venta pide nombre y teléfono ya llenos con los del cliente;
  `cotizaciones.pagos.store` con `origen=mostrador` regresa al detalle y un error regresa a la
  pantalla de pago.
- **Factura**: ajena `403`; cancelada sin botones de envío; pendiente con "Reintentar".
- **Catálogo**: solo los del usuario y sin borrados; la tarjeta y la ficha llevan el precio con IVA y
  **no** contienen costo, precio de proveedor, utilidad, existencias ni precio distribuidor; artículo
  ajeno `404`.
- **Las pruebas de 033** que usaban `mostrador.cotizacion.listo` / `mostrador.factura.listo` pasan a
  los nombres nuevos.

### `node --test "tests/js/*.test.js"`

- `tipoDePago`: igual al saldo sin pagos → `pago_total`; con pagos → `saldo`; menor → `anticipo`;
  montos que difieren en la decimoquinta cifra cuentan como iguales.
- `imagen-compartible.js` no se prueba en Node (necesita lienzo); se revisa en el aparato.

### Revisión en un aparato real

Antes de dar por terminada: la barra y su sección marcada, la lista que recuerda al volver,
facturar una cotización hasta el timbrado, cobrar con la caja, compartir una factura y la ficha con
foto en JPEG por WhatsApp.

## Fuera de alcance

- **Editar cualquier cosa desde el celular**: cotizaciones, facturas, artículos o sus imágenes.
- **Cancelar una factura**, duplicar, aceptar, marcar entregada, eliminar pagos.
- **Emitir complementos de pago** desde el mostrador.
- **Ver existencias**, costos, precio de proveedor, utilidad o precio distribuidor.
- **Filtros de estado, rangos de fecha y exportación** en las listas.
- **Ventas, órdenes de trabajo, órdenes de compra, clientes, proveedores, Tesorería o Configuración**
  desde el mostrador: el candado sigue cerrado sobre todo lo demás.
- **Un cuarto botón en la barra** o en el inicio, y cualquier cifra o gráfica.
- **Guardar listas o documentos en el aparato** para verlos sin internet.
- **Mandar el XML por WhatsApp**, que el navegador no admite.
- **Un catálogo público** o cualquier dirección que se abra sin iniciar sesión.

## Criterios de aceptación

1. En el mostrador, una barra al pie muestra **Cotizaciones, Facturas y Catálogo**, con la sección
   actual marcada, tocable en toda su superficie.
2. La barra **no aparece** durante las capturas, el alta de cliente, el cobro de la venta, los pasos
   de facturar ni el pago de una cotización.
3. El inicio sigue mostrando **los tres accesos** sin cifras y caben sin desplazar en 375 puntos con
   la barra puesta.
4. El nombre del sistema arriba regresa a los tres accesos desde cualquier pantalla nueva.
5. Con el candado encendido, escribir a mano cualquier pantalla de escritorio sigue llevando a los
   tres accesos.
6. Las listas de cotizaciones y facturas muestran **sin escribir nada** los últimos 30 días, del más
   reciente al más viejo, cargando más al llegar al final, con folio, cliente, fecha, total y estado.
7. Escribir en el buscador encuentra documentos **de cualquier fecha**; borrarlo regresa a 30 días.
8. Cotizaciones se encuentran por folio, cliente o RFC; facturas por folio, cliente, RFC o UUID.
9. Al volver de un detalle, la lista aparece con el texto buscado, lo cargado y a la altura en que
   iba, y nada de eso queda guardado al cerrar sesión.
10. Crear una cotización o timbrar una factura en el mostrador termina en **el mismo detalle** que se
    abre desde la lista.
11. El detalle de la cotización muestra cliente, RFC, fecha, renglones, totales y, con pagos, pagado y
    saldo.
12. "Facturar" pide uso de CFDI, forma de pago y método de pago en **cinco pasos** con la cotización a
    la vista, timbra y termina en el detalle de la factura, que queda ligada a la cotización. Los
    renglones no se pueden cambiar en ningún punto.
13. Con una factura sin timbrar, "Facturar" lleva a esa factura y **no crea otra**; con una timbrada,
    o si no se puede facturar, el botón está apagado y la pantalla dice por qué. Si su factura se
    canceló, se puede volver a facturar, como en el escritorio.
14. WhatsApp comparte el PDF de la cotización y la deja en "enviada"; cancelar el menú la deja como
    estaba. El correo sale con la dirección del cliente ya escrita y corregible.
15. "Registrar pago" abre el cobro con el saldo escrito, la fecha de hoy y la caja preseleccionada; si
    el pago crea la venta, pide nombre y teléfono ya llenos. Al guardar, el saldo baja y se ve en el
    detalle, con su movimiento de Tesorería.
16. Con un anticipo registrado, el monto no se puede bajar y la pantalla explica por qué.
17. La factura timbrada se comparte por WhatsApp (PDF) y por correo (PDF y XML) con la línea que
    aclara que el XML va por correo; la pendiente muestra el motivo y, si aplica, "Reintentar"; la
    cancelada no ofrece envíos.
18. Ningún detalle ofrece editar, eliminar, cancelar, duplicar ni complemento de pago.
19. El catálogo muestra sin escribir nada los artículos con imagen, nombre, modelo y precio **con
    IVA**, con buscador y carga al final; tocar uno **no agrega nada** y abre la ficha a pantalla
    completa.
20. La ficha nunca muestra costo, precio de proveedor, utilidad, existencias ni precio distribuidor.
21. "Compartir" abre el menú del aparato con la foto en **JPEG** y el texto
    `{nombre} — Modelo {modelo} — ${precio con IVA}`; sin foto, solo el texto.
22. La ficha del escritorio comparte exactamente igual que antes, ya con la conversión compartida.
23. Los listados y detalles de escritorio de cotizaciones, facturas y artículos no cambian.
24. Sin internet, las tres secciones muestran el aviso con "Reintentar".
25. `php artisan test`, `vendor/bin/pint --dirty` y `node --test "tests/js/*.test.js"` en verde; sin
    `package.json`, Vite ni CDN.

## Supuestos asumidos (registro completo)

**Heredados de la remota sin cambio de fondo** (aprobados allá; adaptados solo en forma)

1. Barra fija al pie con tres secciones, sección actual marcada, sin botón de "Inicio".
2. La barra no aparece durante las capturas.
3. Los accesos del inicio no cambian.
4. Listas en tarjetas de los últimos 30 días; el buscador ignora la fecha.
5. Cada tarjeta lleva folio, cliente, fecha, total y estado; sin más filtros que el buscador.
6. Detalle a pantalla completa.
7. Facturar salta directo a los datos fiscales, en cinco pasos, sin editar renglones, con la
   cotización a la vista.
8. Con factura sin timbrar, "Facturar" lleva a reintentarla; con una timbrada, apagado.
9. WhatsApp y correo desde el detalle; WhatsApp marca enviada.
10. Registrar pago con la pantalla de cobro de la venta: saldo escrito, fecha de hoy, caja
    preseleccionada; el tipo de pago se deduce del monto; con anticipo, el monto queda fijo.
11. Factura: los dos envíos y la línea del XML; pendiente con reintento; cancelada sin envíos.
12. Catálogo de todos los artículos, precio con IVA, ficha a pantalla completa, compartir foto en
    JPEG y texto, sin foto solo texto; nunca costo, utilidad ni existencias.
13. La lista recuerda dónde ibas y lo olvida al cerrar sesión.
14. El PDF y la foto se preparan al entrar, no al tocar.
15. Todo se lee de la red; nada se edita.

**Adaptaciones a la arquitectura actual**

16. Sin cambios de API: la búsqueda de cotizaciones ya existe (`Cotizacion::filtrar`) y el RFC lo
    pinta Blade. Solo nace `Factura::buscarTexto()`.
17. Las páginas de las listas llegan como parciales Blade con `data-siguiente`, como en 033.
18. Facturar va a `cotizaciones.timbrar`, el timbrado directo que ya existe, con `origen=mostrador`;
    pagar va a `cotizaciones.pagos.store`. No hay rutas `POST` nuevas.
19. Las dos rutas de acción se suman a `CandadoMostrador::RUTAS`.
20. La regla de la caja se extrae para que la compartan el cobro de la venta y el pago de la
    cotización.
21. La conversión a JPEG se muda a `public/js/imagen-compartible.js`.
22. El primer pago que crea la venta (029) pide nombre y teléfono ya llenos, como el escritorio.

**[decidir] Asumidos al adaptar, aprobados en bloque por el usuario**

23. **El detalle reemplaza a las pantallas "Listo"** de cotización y factura de 033: una sola pantalla
    por documento, con las rutas renombradas a `mostrador.cotizaciones.ver` y
    `mostrador.facturas.ver` (misma dirección).
24. Sin "Nueva cotización", "Nueva factura" ni "Inicio" en esos detalles: la barra y el nombre del
    sistema ya llevan ahí.
25. La barra tampoco aparece en los pasos de facturar ni en la pantalla de pago de la cotización.
26. Ícono del catálogo: `box-seam`.
27. La memoria de la lista guarda en `sessionStorage` solo texto, páginas y altura (nunca tarjetas) y
    vuelve a pedir las páginas al servidor con `?paginas=n` (tope 10).
28. Una cotización con su factura cancelada se puede volver a facturar, como en el escritorio.
29. Compartir una cotización en borrador la marca enviada en pantalla, pero "Facturar" se activa
    hasta volver a abrirla.
30. La ficha del mostrador no muestra ni comparte el precio distribuidor.
31. La búsqueda de facturas incluye también el RFC del cliente (la remota decía folio, cliente y
    UUID).
32. Las listas de documentos cargan de 20 en 20; el catálogo, de 24 en 24 como la captura.

## Estado de implementación

Implementada el 2026-10-08.

- **Archivos nuevos**:
  - `MostradorConsultaController` y el trait `Concerns\PaginaTarjetasMostrador` (las tres consultas
    de lista, compartidas por la pantalla y las tarjetas);
  - vistas `mostrador/` `_barra`, `lista`, `_siguiente`, `_tarjetas-cotizaciones`,
    `_tarjetas-facturas`, `_tarjetas-catalogo`, `_documento`, `cotizacion`, `factura`, `facturar`,
    `pago` y `articulo`;
  - `public/js/imagen-compartible.js`;
  - pruebas `tests/Feature/MostradorConsultaTest.php` y cuatro casos nuevos en
    `tests/js/mostrador.test.js`.
- **Archivos modificados**: `routes/web.php`, `CandadoMostrador` (dos rutas), `MostradorTarjetasController`
  (tres acciones), `MostradorResultadoController` (se queda con el cobro y el ticket),
  `CotizacionController`, `CotizacionPagoController`, `FacturaController`, `EnvioCotizacionController`,
  `EnvioFacturaController`, `Cuenta` (`cajaEntre()`), `Cotizacion` (`folioBuscado()`), `Factura`
  (`folioBuscado()` y el scope `buscarTexto()`), `ListadoCotizacionesRequest` y `ListadoFacturasRequest`
  (usan la lectura del folio del modelo), `layouts/mostrador`, `mostrador/inicio`, `articulos/index`,
  `ficha-articulo.js`, `mostrador.js`, la sección "Mostrador" de `app.css` y `MostradorTest` (nombres de
  ruta). Se borraron `mostrador/cotizacion-listo` y `mostrador/factura-listo`.
- **Decisiones al implementar**:
  - **La memoria de la lista va en la URL, no en `sessionStorage`** (cambia la forma del supuesto 27,
    no su fondo). `mostrador.js` deja `?q=` y `?paginas=n` en la dirección con `replaceState`; al
    volver con "atrás", el servidor pinta lo mismo y el navegador regresa solo a la altura. Guarda
    todavía menos que lo aprobado: nada en el aparato, ni siquiera el texto. Por eso no hay nada que
    borrar al cerrar sesión. El enlace "‹ Cotizaciones" usa `history.back()` cuando se llegó desde
    esa lista.
  - **La lectura del folio vive en el modelo** (`Cotizacion::folioBuscado()`, `Factura::folioBuscado()`):
    la usan los Form Requests del listado y el buscador del mostrador, y el modelo no depende de un
    request.
  - **`tipoDePago()` decide por el anticipo, no por "tener pagos"**: aquí `saldo` exige un anticipo
    previo y `pago_total` exige que no lo haya (`motivoRechazoPago()`). Con anticipo, Blade escribe
    `saldo` y no pinta el monto; sin él, el campo oculto arranca en `pago_total` (sirve sin
    JavaScript) y `mostrador.js` lo cambia a `anticipo` si el monto es menor. El monto lleva
    `max` = saldo.
  - El pago reutiliza el parcial `cotizaciones/_primer-pago` del escritorio (aviso de lo que nace,
    faltantes de existencia y contacto de la venta), en vez de copiarlo.
  - El motor de listas, las pantallas de opción y su filtro salieron de la captura dentro de
    `mostrador.js` (`crearLista`, `marcarOpcion`, `textoDeOpcion`, `activarFiltroOpciones`) y los usan
    la captura, las listas de consulta y facturar.
  - Un documento o artículo ajeno responde **404** (las Policies responden "no encontrado"), igual que
    en 033.
  - La barra marca la sección con `aria-current="page"` y una franja arriba del ícono; los pies fijos
    (`mostrador-pie`) suben el alto de la barra donde la hay.
- **Verificación**: `php artisan test` con 1368 pruebas en verde (29 nuevas en
  `MostradorConsultaTest`), `node --test "tests/js/*.test.js"` (81) y Pint sin cambios. Además, un
  recorrido con Chrome headless a 375 × 760 contra una base SQLite temporal: los tres accesos caben
  sin desplazar con la barra, la lista de cotizaciones abre con los últimos 30 días y carga más al
  final, "‹ Cotizaciones" desde un detalle regresa a la misma lista y altura, el buscador encuentra
  fuera de los 30 días, facturar recorre sus cinco pasos con filtro, palomita y "atrás", el pago
  preselecciona la caja y registra anticipo y saldo hasta "Pagada", y la factura y la ficha del
  catálogo se pintan sin desplazamiento horizontal, sin errores de consola.

  **Falta probar en el celular real**: timbrar desde una cotización contra facturapi.io, el menú de
  compartir con el PDF y con la foto en JPEG, y la barra con la zona segura del aparato.

### Corrección 1 (2026-10-08): el catálogo en el orden de la lista de artículos

**Pedido del usuario:** el catálogo del mostrador se ordena como la lista de artículos del
escritorio, por `id` de menor a mayor (el orden de alta o de importación), en vez de por nombre. Con
búsqueda se conserva ese orden. Solo cambia `PaginaTarjetasMostrador::paginaCatalogo()`; las tarjetas
del paso de artículos de la captura (033) siguen por nombre.
