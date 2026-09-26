# Spec: Barra del mostrador (cotizaciones, facturas y catálogo desde el celular)

## Historia de usuario

Como usuario de la PWA de facturación, quiero una barra de herramientas en la parte de abajo con
tres menús —**Cotizaciones**, **Facturas** y **Catálogo**— para poder, desde el celular:

- abrir una cotización y **timbrarla como factura**;
- ver una factura y **reenviarla por correo o compartirla** por otros canales, entre ellos WhatsApp;
- **mostrarle un artículo a un cliente** cuando no estoy en la oficina frente a la computadora y
  solo tengo el teléfono a la mano.

## Objetivo / Alcance

[029](029-pwa-mostrador.md) construyó un mostrador que **solo crea**: cuatro accesos para capturar
una factura, una cotización o una venta, y para entregar un pedido escaneando su etiqueta. Esta spec
agrega la otra mitad, la de **consultar lo ya capturado y enseñar el catálogo**, y lo hace sin tocar
los cuatro accesos: se suma una barra al pie con tres secciones nuevas.

Casi todo ocurre en el frontend. Del servidor se tocan tres cosas menores, las tres porque falta un
dato o un filtro que la pantalla necesita, y ninguna cambia lo que el sistema ya hace: un buscador
único en el listado de cotizaciones, un filtro por fecha en el de facturas y el RFC del cliente en
el recurso de la factura.

### Qué redefine de 029

Dos cosas que 029 dejó escritas como fuera de alcance:

- **"Un quinto acceso, de cualquier clase"**. Sigue habiendo cuatro accesos y siguen siendo cuatro
  —no se suma un quinto botón a la cuadrícula—, pero ya no son la única puerta: hay una barra debajo
  con tres secciones más. Lo que aquella regla protegía era la **pantalla de inicio**, para que no se
  convirtiera en un menú disfrazado, y eso se conserva: la cuadrícula de dos por dos no cambia ni
  gana un recuadro.
- **"Editar o consultar documentos ya capturados desde el modo mostrador: consultar es trabajo de
  computadora"**. Esta spec abre la consulta y deja cerrada la edición. La razón por la que aquello
  se cerró entero era que las pantallas de escritorio son tablas anchas que en un celular hay que
  arrastrar de lado; la respuesta no es prohibir la consulta, es darle sus propias pantallas de
  tarjetas, que es lo que 029 hizo con la captura.

**Editar sigue fuera**, y con todas sus letras: desde el celular no se corrige una cotización, no se
cancela una factura y no se toca un artículo.

### Qué ya existe y no se rehace

- **El modo mostrador y su candado**: `lib/modoMostrador.ts` con `enModoMostrador()` y
  `rutaBloqueadaEnMostrador()`, evaluados una sola vez al arrancar, y `AppLayout` con su propiedad
  `mostrador` (ver [029](029-pwa-mostrador.md)).
- **Las tarjetas y el scroll infinito**: `PasoArticulosTarjetas` y `PasoClienteTarjetas` ya son
  listas de tarjetas que se ven desde que abre la pantalla, con buscador y con
  `lib/scrollInfinito.ts` sobre el paginador que el servidor devuelve.
- **El compartir del aparato**: `compartirArchivo` y `compartirTexto` en `lib/compartir.ts`, con sus
  tres reglas —archivos preparados antes del toque, respaldo que abre WhatsApp donde no hay menú, y
  cancelar no es error— y la lectura del error del servidor aunque venga envuelto en un `blob`.
- **La conversión de la cotización en factura**: `POST facturas` acepta `cotizacion_id`, valida que
  esa cotización no tenga ya factura y le graba el `factura_id` al crearla (ver
  [008](008-cotizaciones.md), "Conversión a factura").
- **El timbrado y sus reintentos**: `POST facturas/{factura}/timbrar` sobre una factura que quedó
  `pendiente`, exactamente como ya lo usa la pantalla de resultado del mostrador.
- **Los envíos**: `GET cotizaciones/{cotizacion}/pdf`, `POST cotizaciones/{cotizacion}/enviar` con
  `canal: correo`, `POST cotizaciones/{cotizacion}/marcar-enviada`, `GET facturas/{factura}/pdf` y
  `POST facturas/{factura}/enviar-correo`, que manda el PDF y el XML juntos.
- **El cobro**: `POST cotizaciones/{cotizacion}/pagos`, que registra el pago, genera su movimiento de
  Tesorería y pasa la cotización a `pagada` cuando la suma alcanza el total (ver
  [008](008-cotizaciones.md) y [010](010-tesoreria.md)).
- **La ficha del artículo y su compartir**: `ArticuloDetalleDialog.vue`, con la conversión de la
  imagen a JPEG antes de mandarla, porque WhatsApp trata los `.webp` como calcomanías (ver
  [020](020-imagenes-articulos.md)).
- **Las imágenes**: `GET articulos/{articulo}/imagen` y el helper `imagenUrl()` con su
  `imagen_version`.

## Backend (Laravel)

Ninguna tabla y ninguna columna. Tres cambios, cada uno de una línea de intención:

### `GET cotizaciones` acepta `search`

Hoy el listado filtra con tres parámetros separados —`cliente`, `rfc` y `folio`—, que es lo que la
tabla de filtros por columna del escritorio necesita. La pantalla del celular tiene **un solo campo**
donde se escribe lo que sea, así que alguien tiene que decidir a cuál de los tres mandar el texto.

Se agrega `search`, que busca a la vez en **folio, razón social del cliente y RFC del cliente**, con
la misma forma que `GET facturas` ya tiene. Los tres parámetros de hoy **se quedan intactos** y
siguen sirviendo al escritorio; `search` convive con ellos y se acumula, como cualquier otro filtro
del listado.

Se elige enseñarle al servidor a buscar en los tres campos, y no que el celular adivine a cuál
mandar el texto, porque adivinar falla de una manera que nadie podría explicarse: un cliente cuya
razón social empiece con números no aparecería nunca.

### `GET facturas` acepta `fecha_desde` y `fecha_hasta`

El listado de facturas no sabe filtrar por fecha; el de cotizaciones sí, desde
[008](008-cotizaciones.md). Como las dos listas del celular arrancan acotadas a los últimos 30 días,
la de facturas necesita el mismo filtro.

Se copian tal cual los de cotizaciones, con su misma interpretación: la fecha se recibe como día del
negocio, se resuelve en la zona horaria del negocio y se compara contra `created_at` en UTC. Dos
listados que filtran por fecha con la misma palabra tienen que entenderla igual.

### `FacturaResource` expone `cliente_rfc`

El recurso de la factura trae la razón social y el correo del cliente, pero no su RFC, mientras que
el de la cotización sí lo trae. La pantalla de detalle del mostrador muestra el RFC —es el dato con
el que se comprueba de un vistazo que la factura salió a nombre de quien debía—, y sin él habría que
pedir el cliente en una segunda petición para pintar un renglón de texto.

Es el mismo campo que `CotizacionResource` ya publica, con el mismo nombre.

### Lo que no cambia

- `GET articulos` ya busca por nombre, modelo y proveedor con `search`, y ya pagina. La pantalla de
  catálogo se arma sobre eso.
- El resto de los endpoints se consume tal como está. **Esta spec no crea ningún endpoint nuevo**,
  que es la misma prueba que 029 se puso: las tres secciones son otra forma de llegar a lo que el
  sistema ya hace.

## Frontend (Vue 3)

### La barra

`components/mostrador/BarraMostrador.vue`, fija al pie de la pantalla, con tres botones del mismo
ancho —ícono arriba, texto debajo— y toda la superficie tocable.

| Sección      | Ícono                   | Destino                    |
| ------------ | ----------------------- | -------------------------- |
| Cotizaciones | `DocumentDuplicateIcon` | `/mostrador/cotizaciones`  |
| Facturas     | `DocumentTextIcon`      | `/mostrador/facturas`      |
| Catálogo     | `CubeIcon`              | `/mostrador/catalogo`      |

Los dos primeros son los mismos íconos con que la factura y la cotización aparecen en los cuatro
accesos y en el menú de [013](013-navegacion-principal.md), para que el mismo documento se reconozca
por el mismo dibujo en las dos caras del sistema.

- **Solo en modo mostrador.** En el navegador no aparece: ahí está el menú completo de arriba, que ya
  lleva a los listados de escritorio. La barra es para el aparato donde no hay menú.
- **La sección actual se ve marcada** —ícono y texto en color, el resto apagado—, para saber de un
  vistazo dónde se está parado.
- **Dónde aparece**: en la pantalla de los cuatro accesos y en las tres secciones nuevas, incluidas
  sus pantallas de detalle. **No aparece** durante las capturas por pasos (factura, cotización,
  venta) ni en el escáner: ahí abajo ya vive la barra del carrito con el total y el botón que cierra
  la captura, y un segundo juego de botones en el mismo lugar sería, en el mejor caso, un estorbo, y
  en el peor, un toque que tira una venta a medio capturar.
- **No hay un cuarto botón de "Inicio".** Se vuelve a los cuatro accesos tocando "Facturación" en la
  barra de arriba, que es como ya se vuelve hoy desde cualquier pantalla interior del mostrador. Un
  cuarto botón angostaría los tres por una función que ya tiene su lugar.

La pantalla de los cuatro accesos **no cambia**: los mismos cuatro botones en cuadrícula de dos por
dos, sin cifras ni gráficas, y el enlace de cerrar sesión al pie. Lo único que ocurre es que la
cuadrícula reparte un poco menos de alto, porque la barra se lo lleva.

### El candado

`RUTAS_PERMITIDAS` de `lib/modoMostrador.ts` gana las seis rutas nuevas. Todo lo demás sigue
redirigiendo a los cuatro accesos, igual que hoy.

```
/mostrador/cotizaciones       → mostrador-cotizaciones
/mostrador/cotizaciones/:id   → mostrador-cotizacion-ver
/mostrador/facturas           → mostrador-facturas
/mostrador/facturas/:id       → mostrador-factura-ver
/mostrador/catalogo           → mostrador-catalogo
/mostrador/catalogo/:id       → mostrador-articulo-ver
```

Van bajo el mismo prefijo `/mostrador` que las cuatro de 029, para que el candado siga siendo una
regla y no una lista que haya que acordarse de ampliar. Los nombres de las rutas de captura
—`mostrador-cotizacion`, `mostrador-factura`— **no cambian**: las nuevas son el plural para la lista
y el sufijo `-ver` para el detalle.

### Las dos listas de documentos

Cotizaciones y facturas son **la misma pantalla con otra lista**, igual que uso de CFDI y forma de
pago en 029: buscador arriba, tarjetas debajo, scroll infinito y nada más. Escritas dos veces se
verían distintas el día que una de las dos se corrigiera, así que viven en un componente compartido
—`ListaDocumentosMostrador.vue`— al que se le dice de dónde traer, cómo pintar cada tarjeta y a
dónde lleva el toque.

#### Los últimos 30 días

Las dos listas arrancan mostrando **los documentos de los últimos 30 días**, de la más reciente a la
más vieja, cargando la página siguiente al llegar al final.

Los 30 días no son un número al azar: es el plazo con el que una cotización sin movimiento se borra
sola ([008](008-cotizaciones.md), "Caducidad automática"), así que la lista sin filtrar es, casi
exactamente, lo que sigue vivo. Las facturas usan el mismo plazo por una razón distinta y más
simple: dos listas hermanas que se comportan igual no obligan a recordar cuál era cuál.

**El buscador ignora la fecha.** En cuanto se escribe algo, la búsqueda sale sin el límite de 30 días
y alcanza cualquier documento, por viejo que sea. Es la salida para el cliente que pide su factura de
hace cuatro meses, y no hay que aprender ningún botón: buscar es la manera obvia de llegar a algo que
no está a la vista. Al borrar el texto, la lista vuelve a los 30 días.

- **Cotizaciones**: el buscador filtra por **folio, cliente o RFC**, contra el `search` nuevo.
- **Facturas**: por **folio, cliente o folio fiscal**, contra el `search` que ya existe.

**No hay más filtros**: ni por estado, ni por rango de fechas, ni exportación. La pantalla es un
campo arriba y lista para abajo. Cada filtro que se agrega es una decisión que hay que tomar antes de
poder ver nada, y en el mostrador lo que se busca se busca por nombre o por número.

#### La tarjeta

Cada tarjeta lleva **folio, cliente, fecha, total y estado**, con toda su superficie tocable.

- El **folio** y el **cliente** en grande: son con lo que se reconoce el documento.
- La **fecha** y el **estado** en chico. El estado va con el mismo color con que el escritorio lo
  pinta hoy, para que "timbrada", "cancelada" o "borrador" se lean sin acercar el ojo.
- El **total** destacado a la derecha, en peso cerrado, como ya lo devuelve el servidor (ver
  [030](030-total-al-peso-cerrado.md)).

#### La lista recuerda dónde ibas

Al volver de un detalle, la lista aparece **donde la dejaste**: con lo que ya se había cargado, con
lo que estaba escrito en el buscador y a la altura en la que ibas. Reabrirla desde cero después de
mirar una cotización obligaría a recorrer otra vez todo lo que ya se había recorrido, y revisar
varias seguidas es justo lo que se hace en esta pantalla.

Se guarda en memoria mientras la aplicación está abierta, por nombre de ruta, y **se olvida al
cerrar sesión**. No se guarda en disco: los datos del sistema no se escriben en el aparato, misma
regla de 029.

### Cotizaciones — `/mostrador/cotizaciones/:id`

El detalle ocupa **la pantalla completa**, no un modal: hay que leer renglones y apretar botones
grandes, y un modal en un celular es la pantalla completa con menos espacio y un marco alrededor.

Muestra el folio, el estado, el cliente con su RFC, la fecha, los renglones con cantidad, precio e
importe, y los totales al pie, incluido el saldo pendiente cuando hay pagos registrados.

Debajo, los botones:

#### "Facturar"

Es el motivo por el que existe esta pantalla. Entra a la captura por pasos de la factura
—`/mostrador/factura?cotizacion_id={id}`— con el **cliente y los renglones ya cargados**, y arranca
directo en los datos fiscales:

```
Uso de CFDI → Forma de pago → Método de pago → Revisar → Listo
```

- **El indicador de pasos muestra cinco**, no ocho. Los tres primeros no se saltan por atajo: es que
  no existen en este camino, porque la cotización ya los resolvió. Un indicador que dijera "paso 4 de
  8" con los tres primeros inalcanzables mentiría sobre lo que falta.
- **Arriba, una línea fija** dice de qué cotización se trata: "Facturando la cotización #124 —
  {cliente}". Tres pantallas de opciones adentro, sin ella, ya no hay forma de saber qué se está por
  timbrar.
- **Los renglones no se editan.** Se timbra exactamente lo que dice la cotización: mismos artículos,
  cantidades, precios y descuentos, incluido el descuento congelado del cliente
  ([015](015-descuento-permanente-cliente.md)) y el descuento global. Corregir una cantidad con el
  pulgar, con el cliente enfrente y un folio fiscal de por medio, es la manera más barata de timbrar
  algo distinto de lo que se cotizó; si hay que cambiar algo, se cambia la cotización en la
  computadora y se factura después.
- **Volver atrás desde el primer paso** regresa al detalle de la cotización, no a un carrito que aquí
  no existe.
- Del paso de revisión en adelante todo es idéntico a la captura de 029: nombre, RFC y total en
  grande, los tres datos fiscales en letra chica, un solo botón de "Timbrar", y la pantalla de
  resultado con el folio fiscal y sus envíos.
- El documento se crea con `POST facturas` mandando `cotizacion_id`, que es lo que le graba a la
  cotización su `factura_id`. **Es el mismo camino del escritorio**, no uno paralelo.

**Cuando la cotización ya tiene factura**, el botón se comporta como el del escritorio (ver
[008](008-cotizaciones.md)):

| Estado de la factura asociada | Qué hace el botón                                                     |
| ----------------------------- | --------------------------------------------------------------------- |
| No hay factura                | Entra a los datos fiscales, como se describió arriba.                  |
| `pendiente`                   | Abre esa factura en `/mostrador/facturas/{id}` para reintentar el timbrado, sin crear otra. |
| `timbrada`                    | Apagado, con la leyenda "Ya facturada" y un enlace a esa factura.      |
| `cancelada`                   | Apagado, con la leyenda "Su factura fue cancelada" y el enlace.        |

No hay refacturación automática de una cancelada: la vía sigue siendo duplicar la cotización desde la
computadora, misma decisión de 008.

#### "Enviar por WhatsApp" y "Enviar por correo"

Los mismos dos botones que cierran la captura de la cotización, con el mismo comportamiento: el PDF
se comparte con el menú del aparato y, **al volver del menú**, se llama a `marcar-enviada`, que pasa
la cotización de `borrador` a `enviada`; si el usuario cancela el menú, no se marca nada. El correo
sale del servidor con `canal: correo`, con la dirección del cliente ya escrita y corregible.

#### "Registrar pago"

Abre **la pantalla de cobro de la venta al público**, la misma de 029, con lo que aquí corresponde:

- El **saldo pendiente** a la vista y el monto ya escrito con ese saldo, que se puede bajar.
- La **fecha de hoy**, editable.
- La **caja** ya elegida —la cuenta de efectivo activa más antigua—, cambiable con un toque. Aquí
  vale el mismo criterio que en la venta: se está cobrando enfrente, y la caja no es una comodidad
  que invite a confirmar por inercia, es lo que pasa casi siempre.

El tipo de pago que exige el backend no se le pregunta al usuario: se deduce del monto, que es el
único dato que el usuario sí sabe.

- Monto **igual al saldo pendiente**: `pago_total` si la cotización no tenía ningún pago, `saldo` si
  ya tenía uno.
- Monto **menor**: `anticipo`. Como una cotización admite **un solo anticipo**, si ya tiene uno el
  monto queda fijo en el saldo pendiente y la pantalla lo explica en una línea, en vez de dejar
  capturar algo que el servidor va a rechazar.

Al terminar, el detalle se recarga: el saldo baja y, si quedó en cero, el estado pasa a `pagada`. El
movimiento de Tesorería lo genera el servidor, como siempre.

#### Lo que el detalle no ofrece

Editar, eliminar, duplicar y marcar el producto como entregado. Las cuatro existen en el escritorio y
ahí se quedan.

### Facturas — `/mostrador/facturas/:id`

Pantalla completa con el folio, el estado, el cliente con su RFC, la fecha, el folio fiscal (UUID)
cuando está timbrada, los renglones y el total.

- **"Enviar por correo"** — `POST facturas/{factura}/enviar-correo`, con el correo del cliente ya
  escrito en su diálogo y corregible antes de mandar. Van **el PDF y el XML**.
- **"Enviar por WhatsApp"** — el PDF por el menú del aparato, con `compartirArchivo`.
- **Bajo los dos botones, una línea en letra chica**: "Por WhatsApp va el PDF; el XML se manda por
  correo". No es un detalle técnico de más: es la diferencia entre creer que se le mandó el CFDI
  completo al contador del cliente y habérselo mandado. El navegador del celular **no admite
  archivos XML** en su menú de compartir, con ningún tipo MIME, y esa limitación no se puede rodear
  (ver [029](029-pwa-mostrador.md), "El XML no cabe en el menú del aparato").
- **Compartir no cambia el estado de la factura**: una timbrada ya está timbrada y no hay ningún
  "enviada" que mover.

**Si la factura quedó `pendiente`** —guardada pero sin timbrar—, la pantalla muestra el motivo del
fallo (`error_timbrado`) y un botón **"Reintentar timbrado"** que llama a
`POST facturas/{factura}/timbrar`. Es exactamente lo que ya ofrece la pantalla de resultado de la
captura; lo único nuevo es poder volver a ella al día siguiente, desde la lista. Los tres datos
fiscales no se pueden cambiar aquí: si el timbrado falló por uno de ellos, la factura se corrige en
la computadora.

**Si la factura está `cancelada`**, la pantalla lo dice con su motivo y no ofrece ningún envío.

**Lo que el detalle no ofrece**: cancelar, editar, eliminar y emitir el complemento de pago. Cancelar
un CFDI exige un motivo, queda registrado ante la autoridad y no se deshace; no es algo que se
aprieta con el pulgar en el mostrador.

### Catálogo — `/mostrador/catalogo`

La lista de **todos** los artículos, de todos los catálogos y proveedores, en las mismas tarjetas del
paso de artículos: imagen cuando la hay —y el recuadro con ícono cuando no—, nombre, modelo y precio,
con buscador por nombre, modelo o proveedor contra el `search` de `GET articulos`, y la página
siguiente cargándose al llegar al final.

No hay una pantalla previa para elegir el catálogo. El caso de uso es enseñarle algo a un cliente que
está preguntando ahora; obligar a acordarse de qué proveedor es antes de poder buscarlo es
justamente el trabajo que el buscador hace mejor.

**Tocar una tarjeta no agrega nada a ningún carrito**: abre la ficha. Es la misma tarjeta que en la
captura suma una unidad, y aquí significa otra cosa, así que la pantalla tiene que dejarlo claro sin
que haya que probarlo — no hay barra de carrito al pie, no hay contadores sobre las tarjetas y el
título dice "Catálogo".

#### La ficha — `/mostrador/catalogo/:id`

**Pantalla completa, no un modal.** Esta es la pantalla que se le voltea al cliente para que vea el
producto: tiene que ocupar el aparato entero, sin un marco alrededor ni el listado asomándose atrás.

- **La imagen en grande** arriba, ocupando el ancho. Sin imagen, el marcador de "Sin imagen" del
  mismo tamaño, para que la ficha no cambie de forma según haya o no foto.
- Debajo, **nombre, modelo y precio**.
- Abajo, el botón **"Compartir"**.

**El precio va con IVA**, aquí y en las tarjetas de esta sección. Es el precio que se le dice al
cliente enfrente, el mismo `precio_unitario_con_iva` que muestra la ficha del escritorio.

Es una diferencia buscada con las tarjetas del paso de artículos de la captura, donde el precio va
**sin** IVA: allá el número es el que va a formar un renglón del documento, y el IVA se desglosa
después; acá el número es el que el cliente escucha. Son dos preguntas distintas y por eso son dos
respuestas distintas.

**Nunca se muestran el costo, el precio del proveedor ni la utilidad**, ni las existencias. La ficha
es lo que se le enseña o se le manda a un cliente, y con el teléfono en la mano del cliente todo lo
que esté en la pantalla es información que se le dio. Misma regla que la ficha del escritorio
([020](020-imagenes-articulos.md)).

#### "Compartir"

Manda **la foto y el texto** por el menú del propio aparato, con `compartirArchivo`. El texto es el
mismo de la ficha del escritorio: `{nombre} — Modelo {modelo} — ${precio con IVA}`.

- **La foto se convierte a JPEG antes de salir**, porque WhatsApp trata los `.webp` como
  calcomanías y el cliente recibiría un sticker en vez de la imagen del producto. Esa conversión ya
  existe dentro de `ArticuloDetalleDialog.vue`; **se muda a `lib/imagenCompartible.ts`** y el modal
  del escritorio pasa a importarla desde ahí. Dos pantallas hacen exactamente lo mismo con la misma
  imagen, y una copia se habría quedado atrás la primera vez que se corrigiera.
- **Un artículo sin foto comparte solo el texto**, con `compartirTexto`. El nombre, el modelo y el
  precio siguen siendo lo que el cliente necesita; apagar el botón porque falta una foto le quitaría
  a la ficha su única función.
- **La imagen se prepara al entrar a la ficha**, no al apretar el botón, con las reglas del compartir
  de 029.

**Desde el catálogo no se modifica nada**: no se edita el artículo, no se sube ni se cambia su
imagen, y no se ven ni se mueven existencias.

### El PDF se baja al entrar

En los dos detalles de documento, el PDF se descarga **al abrir la pantalla**. Mientras tanto el
botón de WhatsApp dice "Preparando..." y está apagado; al tocarlo ya no hay nada que esperar.

Es la regla que 029 fijó después de que el botón fallara al probarlo: el menú de compartir del
aparato solo se abre mientras el gesto del usuario sigue vivo, y una descarga de por medio lo agota.
Con una descarga corta a veces alcanza y a veces no, que es la peor de las conductas posibles.

El costo asumido es bajar el PDF de documentos que solo se abrieron a mirar. Se acepta: es un archivo
chico contra un botón que funciona siempre.

### Sin conexión

Las tres secciones **leen siempre de la red**, como todo el mostrador. Nada se guarda en disco: una
lista vieja guardada en el teléfono terminaría enseñándole a un cliente un precio que ya cambió o un
total que ya se pagó. Sin internet sale el aviso del sistema —"Sin conexión. Revisa el internet e
inténtalo de nuevo"— con su botón de reintentar.

### El escritorio no cambia

Los listados y detalles de cotizaciones, facturas y artículos de la computadora se quedan
exactamente como están, con sus tablas, sus filtros por columna y sus botones. Lo único que los roza
es que `GET cotizaciones` aprende un parámetro más que ellos no usan, y que `FacturaResource` publica
un campo más que ya publicaba su hermano.

## Fuera de alcance

- **Editar cualquier cosa desde el celular**: corregir una cotización, cancelar o editar una factura,
  modificar un artículo o subirle una imagen.
- **Duplicar una cotización** y **marcar el producto como entregado**.
- **Emitir complementos de pago** desde el mostrador.
- **Ver existencias**, movimientos de inventario o cualquier dato de costo, precio de proveedor o
  utilidad.
- **Filtros de estado, rangos de fecha y exportación** en las listas de documentos.
- **Ver órdenes de compra, pedidos, clientes, proveedores, tesorería o configuración** desde el
  mostrador: el candado sigue cerrado sobre todo lo demás.
- **Un cuarto botón en la barra**, de cualquier clase, y cualquier cifra, resumen o gráfica en la
  pantalla de los cuatro accesos.
- **Guardar listas o documentos en el aparato** para consultarlos sin internet.
- **Elegir el contacto de WhatsApp desde el sistema**: lo hace el menú del aparato.
- **Mandar el XML por WhatsApp**, que el navegador no admite.
- **Un catálogo público** o cualquier dirección que se abra sin iniciar sesión. Lo que sale hacia
  afuera sale por el botón "Compartir", pieza por pieza.

## Estado de implementación

Implementada el 2026-08-17.

- **Backend, los tres cambios previstos y nada más**: `search` en `CotizacionController::index`
  (folio, razón social y RFC, conviviendo con los tres filtros por columna del escritorio),
  `fecha_desde`/`fecha_hasta` en `FacturaController::index` —copiados de los de cotizaciones, con su
  `ZONA_HORARIA_NEGOCIO`— y `cliente_rfc` en `FacturaResource`. Ningún endpoint nuevo, ninguna tabla,
  ninguna columna.
- **Archivos nuevos del frontend**: `components/mostrador/BarraMostrador.vue`,
  `ListaDocumentosMostrador.vue`, `SelectorCuentaMostrador.vue`;
  `views/mostrador/MostradorCotizacionesView.vue`, `MostradorCotizacionDetalleView.vue`,
  `MostradorFacturasView.vue`, `MostradorFacturaDetalleView.vue`, `MostradorCatalogoView.vue`,
  `MostradorArticuloView.vue`; `lib/imagenCompartible.ts`, `lib/memoriaLista.ts` y
  `lib/pagoCotizacion.ts`.
- **El selector de cuenta salió de la venta al público** en vez de copiarse. "La misma pantalla de
  cobro" era, en código, una sola pieza compartible: la regla de que **la caja viene
  preseleccionada**. Vive en `SelectorCuentaMostrador.vue` y la usan el paso de cobro de la venta y
  el pago de la cotización; lo demás de esa pantalla —el monto y su ayuda, la fecha, el reintento de
  solo-el-cobro— es distinto en cada una y se quedó en su vista.
- **`tipoDePago()` se extrajo a `lib/pagoCotizacion.ts`** para poder probarla: es la regla que
  traduce el monto capturado al `anticipo`/`saldo`/`pago_total` que el backend exige. Compara **en
  centavos**, no en pesos: dos flotantes que valen lo mismo pueden diferir en la decimoquinta cifra,
  y ahí un pago completo se mandaría como anticipo.
- **La memoria de posición se olvida en `auth.logout()`**, que es el único punto por el que se sale
  de la sesión.
- **`CuatroAccesos` descuenta ahora el alto de la barra** (`calc(100svh-15rem)` en vez de `-9rem`),
  y `AppLayout` deja el mismo hueco al pie con `pb-24` cuando lleva barra. Sin las dos cosas, el
  último renglón de cualquier lista quedaría escondido detrás de ella.
- **Decisiones de detalle que la spec no fijaba**:
  - Cada pantalla de detalle abre con un botón discreto de regreso —"Cotizaciones", "Facturas",
    "Catálogo"— además del gesto de atrás del teléfono. Es lo que hace útil la memoria de posición:
    volver a la lista es el gesto que más se repite.
  - Facturada una cotización, la pantalla de resultado cambia "Nueva factura" por **"Cotizaciones"**:
    "Nueva factura" volvería a la misma cotización, que ya quedó facturada.
  - El botón "Facturar" trata `borrador` igual que `pendiente` —lleva a esa factura a reintentar el
    timbrado—: las dos son facturas sin timbrar, y el estado `borrador` solo existe el instante que
    va entre el `POST` y el timbrado.
  - Compartir o mandar por correo desde el detalle **mueve el estado en pantalla** de `borrador` a
    `enviada` sin recargar el documento, que es lo que el servidor acaba de hacer.
- **Las seis pantallas nuevas se ven con la barra también abiertas desde el navegador**, igual que
  las cuatro de captura de 029 se ven en modo mostrador ahí. Es la misma consecuencia aceptada: son
  direcciones a las que nadie llega desde el escritorio, porque ningún menú lleva a ellas.
- **Verificación**: `php artisan test` en verde (570 tests, 4 nuevos entre `CotizacionesTest` y
  `FacturasTest`), Pint sin cambios; ESLint y Prettier limpios, `vitest` en verde (86 tests, 5 nuevos
  de `pagoCotizacion.test.ts`) y `npm run build` compilando la SPA con `vue-tsc` sin errores. **No se
  pudo verificar visualmente en un aparato real** (misma limitación de entorno que el resto de las
  historias) — falta abrir la aplicación instalada en el celular y confirmar la barra, el salto de
  la cotización al timbrado, el reenvío de una factura y el compartir de la ficha del catálogo.

## Criterios de aceptación

1. En la aplicación instalada, una barra al pie muestra **Cotizaciones, Facturas y Catálogo**, con la
   sección actual marcada, y la sección se abre tocando cualquier parte del botón.
2. La barra **no aparece** en el navegador de escritorio, ni durante la captura de una factura,
   cotización o venta, ni en el escáner.
3. La pantalla de los cuatro accesos sigue mostrando **cuatro botones** en cuadrícula de dos por dos,
   sin cifras ni gráficas, y caben sin desplazar la pantalla en un celular de 375 puntos de ancho con
   la barra puesta.
4. Tocando "Facturación" en la barra de arriba se vuelve a los cuatro accesos desde cualquiera de las
   seis pantallas nuevas.
5. En modo mostrador, escribir a mano la dirección de cualquier pantalla que no sea una de las
   permitidas sigue llevando a los cuatro accesos.
6. Las listas de cotizaciones y de facturas muestran **sin escribir nada** los documentos de los
   últimos 30 días, del más reciente al más viejo, cargando más al llegar al final, y cada tarjeta
   trae folio, cliente, fecha, total y estado.
7. Escribir en el buscador encuentra documentos **de cualquier fecha**, incluidos los de más de 30
   días atrás; borrar el texto devuelve la lista a los últimos 30 días.
8. El buscador de cotizaciones encuentra por folio, por razón social y por RFC del cliente en un solo
   campo; el de facturas, por folio, razón social y folio fiscal.
9. Al volver de un detalle, la lista aparece con lo ya cargado, el texto del buscador y la altura en
   la que iba.
10. Tocar una cotización abre una pantalla completa con cliente, RFC, fecha, renglones, totales y
    saldo pendiente.
11. "Facturar" entra a la captura de factura con el cliente y los renglones de la cotización ya
    cargados, mostrando **cinco pasos** —uso de CFDI, forma de pago, método de pago, revisar y
    listo—, con el folio de la cotización a la vista, y termina timbrando. Los renglones no se pueden
    modificar en ningún punto de ese camino.
12. La factura creada así queda asociada a la cotización: el detalle de la cotización deja de ofrecer
    "Facturar" y muestra el enlace a su factura.
13. Con una factura `pendiente` asociada, "Facturar" abre esa factura para reintentar el timbrado y
    **no crea una segunda**; con una `timbrada` o `cancelada`, el botón está apagado y la pantalla
    dice por qué.
14. Desde el detalle de la cotización se comparte el PDF por WhatsApp y, al compartir, una cotización
    en borrador pasa a "enviada"; si se cancela el menú, sigue en borrador. "Enviar por correo" lo
    manda con la dirección del cliente ya escrita y corregible.
15. "Registrar pago" abre la pantalla de cobro con el saldo pendiente ya escrito, la fecha de hoy y
    la caja preseleccionada; al guardar, el saldo baja, y si queda en cero la cotización pasa a
    "pagada" con su movimiento de Tesorería.
16. Una cotización que ya tiene un anticipo registrado no deja capturar un monto menor al saldo: el
    monto queda fijo y la pantalla lo explica.
17. El detalle de la cotización no ofrece editar, eliminar, duplicar ni marcar entregada.
18. Tocar una factura abre una pantalla completa con cliente, RFC, fecha, folio fiscal, renglones y
    total, con "Enviar por WhatsApp" y "Enviar por correo", y una línea que aclara que el XML va por
    correo.
19. "Enviar por correo" manda el PDF y el XML al correo del cliente, que viene escrito y se puede
    corregir; "Enviar por WhatsApp" abre el menú del aparato con el PDF, sin descargas de por medio y
    sin cambiar el estado de la factura.
20. Una factura `pendiente` muestra el motivo del fallo y un botón que reintenta el timbrado; una
    `cancelada` muestra su motivo y no ofrece envíos.
21. El detalle de la factura no ofrece cancelar, editar, eliminar ni emitir complemento de pago.
22. El catálogo muestra **sin escribir nada** los artículos con su imagen, nombre, modelo y precio
    **con IVA**, carga más al llegar al final, y el buscador filtra por nombre, modelo o proveedor.
23. Tocar una tarjeta del catálogo **no agrega nada a ningún carrito**: abre la ficha a pantalla
    completa con la imagen en grande, nombre, modelo y precio con IVA.
24. La ficha no muestra en ningún caso el costo, el precio del proveedor, la utilidad ni las
    existencias, y no ofrece editar el artículo ni cambiar su imagen.
25. "Compartir" abre el menú del aparato con la foto —en **JPEG**, no WEBP— y el texto
    `{nombre} — Modelo {modelo} — ${precio con IVA}`; un artículo sin foto comparte solo el texto.
26. El modal de ficha del escritorio sigue compartiendo exactamente igual que antes, con la
    conversión a JPEG ya mudada a su módulo compartido.
27. Los listados y formularios de escritorio de cotizaciones, facturas y artículos se comportan
    exactamente como antes, incluidos sus filtros por columna.
28. Sin internet, las tres secciones muestran el aviso de sin conexión con su botón de reintentar, en
    vez de quedarse en blanco.
29. Pint y `php artisan test` en verde; ESLint y Prettier sin errores sobre el código nuevo; `vitest`
    y `npm run build` en verde.

## Supuestos asumidos (registro completo)

Aprobados uno por uno con el usuario antes de redactar. Los que el usuario cambió van marcados como
**(redefinido)**.

**La barra**

1. La barra vive **solo en modo mostrador**, la aplicación abierta desde el icono instalado. En el
   navegador no aparece.
2. Está fija al pie, con tres botones de ícono y texto, toda su superficie tocable.
3. Aparece en los cuatro accesos y en las tres secciones nuevas, **no** durante las capturas por
   pasos ni en el escáner.
4. El botón de la sección actual se ve marcado.
5. **No hay un cuarto botón de "Inicio"**: se vuelve con "Facturación" de la barra de arriba.
6. Los cuatro accesos no cambian: siguen siendo los mismos cuatro botones en cuadrícula de dos por
   dos.
7. Las tres secciones nuevas se suman al candado del router; todo lo demás sigue cerrado.

**Cotizaciones**

8. **(Redefinido)** La lista arranca en **los últimos 30 días**, no en todas. (La propuesta original
   era la lista completa sin límite de fecha.)
9. **(Definido al redefinir el 8)** El buscador **ignora el límite de fecha** y alcanza cualquier
   cotización. Se prefirió a un botón de "Ver todas" porque buscar es la manera obvia de llegar a
   algo que no está a la vista, y no hay que aprenderlo.
10. Cada tarjeta lleva folio, cliente, fecha, total y estado.
11. El buscador es un solo campo que entiende folio o nombre del cliente.
12. Tocar una tarjeta abre el detalle en **pantalla completa**, no en un modal ni desplegando la
    tarjeta.
13. "Facturar" **salta directo a los datos fiscales** —uso de CFDI, forma de pago, método de pago,
    revisar y timbrar—, con cliente y renglones ya cargados.
14. **Los renglones no se editan** al facturar desde el celular: se timbra lo que dice la cotización.
15. Con una factura ya asociada, el botón se comporta **como en el escritorio**: apagado si está
    timbrada o cancelada, y lleva a reintentar si quedó pendiente.
16. Desde el detalle se puede mandar la cotización por **WhatsApp y por correo**.
17. **(Redefinido)** Además de consultar, enviar y facturar, se puede **registrar un pago**. (La
    propuesta original no permitía ninguna acción que tocara el documento.)
18. **(Definido al redefinir el 17)** El pago se registra con **la pantalla de cobro de la venta al
    público**: saldo pendiente ya escrito y bajable, fecha de hoy y la caja preseleccionada.
19. No se puede editar, eliminar, duplicar ni marcar entregada.

**Facturas**

20. La lista usa **el mismo criterio de 30 días** que la de cotizaciones, con el buscador ignorando
    la fecha. Dos listas hermanas que se comportan igual no obligan a recordar cuál era cuál.
21. Cada tarjeta lleva folio, cliente, fecha, total y estado, y el buscador entiende folio, nombre
    del cliente y folio fiscal.
22. El detalle es pantalla completa con los datos y los **dos envíos**, correo y WhatsApp.
23. **La pantalla dice, en una línea, que por WhatsApp va el PDF y que el XML se manda por correo.**
    Sin decirlo, el usuario cree que mandó el CFDI completo.
24. Una factura pendiente se puede **reintentar timbrar** desde el celular, con el motivo del fallo a
    la vista. Los datos fiscales no se pueden cambiar aquí.
25. No se puede cancelar, editar, eliminar ni emitir complemento de pago.

**Catálogo**

26. Muestra **todos** los artículos, de todos los catálogos, con buscador. No hay pantalla previa
    para elegir proveedor.
27. Tocar una tarjeta **no agrega nada a ningún carrito**: abre la ficha a **pantalla completa**.
28. La ficha muestra foto, nombre, modelo y precio. **Nunca** costo, precio de proveedor, utilidad ni
    existencias.
29. **El precio va con IVA** en la tarjeta y en la ficha, a diferencia de las tarjetas de la captura,
    donde va sin IVA porque de ahí sale un renglón del documento.
30. "Compartir" manda **la foto y el texto** por el menú del aparato, con la foto convertida a JPEG.
31. Un artículo **sin foto comparte solo el texto**, en vez de dejar el botón apagado.
32. Desde el catálogo no se edita nada: ni el artículo, ni su imagen, ni sus existencias.

**Generales**

33. El escritorio no cambia.
34. Las tres secciones **siempre leen de la red**; sin conexión sale el aviso con botón de
    reintentar. No se guarda nada en el aparato.
35. **No hay filtros** además del buscador: ni por estado, ni por rango de fechas, ni exportación.

**Adiciones técnicas aceptadas**

36. **`GET cotizaciones` aprende un `search` único** que busca en folio, cliente y RFC a la vez, como
    el de facturas. Se descartó que el celular adivinara a cuál de los tres filtros de hoy mandar el
    texto: una razón social que empiece con números no aparecería nunca y nadie podría explicarse por
    qué.
37. **La lista recuerda dónde ibas** al volver de un detalle: lo cargado, lo escrito y la altura.
38. **El PDF se baja al entrar** a las pantallas de detalle, con el botón en "Preparando..." mientras
    tanto, para que el menú del aparato abra siempre.

*(Se retiraron dos adiciones propuestas: los filtros de estado en fila, descartados por el supuesto
35, y el botón de reintentar timbrado, que quedó aprobado como el supuesto 24.)*

**Decisiones de detalle tomadas al redactar** (no se consultaron una por una; se documentan para que
puedan corregirse antes de implementar)

39. **Esta spec redefine dos exclusiones de [029](029-pwa-mostrador.md)** —"un quinto acceso" y "no
    consultar documentos ya capturados"— y lo deja escrito. La pantalla de inicio, que era lo que
    aquella regla protegía, no cambia.
40. **Las dos listas de documentos son el mismo componente** con otra lista, otro pintado de tarjeta
    y otro destino, como uso de CFDI y forma de pago en 029. Escritas dos veces se verían distintas
    el día que una de las dos se corrigiera.
41. **Los 30 días salen de la caducidad automática de la cotización** ([008](008-cotizaciones.md)):
    la lista sin filtrar es, casi exactamente, lo que sigue vivo. Las facturas heredan el plazo por
    coherencia, no por una razón propia.
42. **`GET facturas` gana `fecha_desde` y `fecha_hasta`**, copiados de los de cotizaciones y con su
    misma interpretación de zona horaria. Sin ellos la lista de facturas no puede acotarse a 30 días.
43. **`FacturaResource` expone `cliente_rfc`**, el campo que su hermano `CotizacionResource` ya
    publica. Sin él, pintar el RFC en el detalle costaría una segunda petición.
44. **El camino de facturar desde la cotización muestra cinco pasos, no ocho con tres saltados.** Los
    tres primeros no existen en ese camino, y un indicador que dijera "4 de 8" con los primeros
    inalcanzables mentiría sobre lo que falta.
45. **Una línea fija arriba dice qué cotización se está facturando.** Tres pantallas de opciones
    adentro ya no hay forma de saberlo.
46. **El tipo de pago no se le pregunta al usuario: se deduce del monto.** Igual al saldo es
    `pago_total` o `saldo` según haya pagos previos; menor es `anticipo`, y como solo se admite un
    anticipo, con uno ya registrado el monto queda fijo en el saldo.
47. **La conversión de la imagen a JPEG se muda a `lib/imagenCompartible.ts`** y el modal del
    escritorio pasa a importarla, en vez de quedar copiada en dos pantallas. Mismo criterio con el
    que `leerQr()` se mudó a `lib/lectorQr.ts` en 029.
48. **La memoria de posición de las listas vive en memoria y se olvida al cerrar sesión.** No se
    escribe en disco: los datos del sistema no se guardan en el aparato.
49. **Las rutas nuevas van bajo el prefijo `/mostrador`**, en plural para la lista y con sufijo
    `-ver` para el detalle. Los nombres de las cuatro rutas de captura de 029 no cambian.
50. **La pantalla de catálogo no lleva barra de carrito ni contadores sobre las tarjetas.** Es la
    misma tarjeta que en la captura suma una unidad, y aquí significa otra cosa: la pantalla tiene
    que dejarlo claro sin que haya que probarlo.
