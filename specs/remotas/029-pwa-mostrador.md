# Spec: PWA de mostrador (cuatro accesos, captura por pasos y escáner de etiquetas)

## Historia de usuario

Como usuario único del sistema, quiero una aplicación instalable en el celular, rápida, intuitiva y
de fácil acceso, con botones grandes para usarse con el dedo y **solo cuatro accesos**: generar
factura, generar cotización, generar venta al público y escanear etiquetas. Nada más que eso.

Tal como lo precisó el usuario al revisar las asunciones: **los demás módulos no se alcanzan por
ningún medio desde ese aparato**, porque se trabajan en la computadora.

## Objetivo / Alcance

La aplicación **ya es instalable**: el manifest, los iconos y el service worker existen desde que se
configuró `VitePWA` en `vite.config.ts`. Lo que esta spec agrega no es "hacerla PWA" sino las dos
cosas que faltan para que sirva en el mostrador:

1. **Un modo mostrador**: abierta desde el icono instalado, la aplicación muestra los cuatro accesos
   y **nada más** —sin menú y sin el resto de los módulos—; abierta en el navegador, sigue siendo el
   sistema completo de siempre.
2. **Cuatro caminos hechos para el dedo**: tres capturas por pasos (factura, cotización, venta) y un
   escáner de etiquetas que lee el QR con la cámara sin salir de la aplicación.

Casi todo ocurre en el frontend: no se agrega ninguna tabla ni ninguna columna, y los cuatro caminos
se arman con lo que el backend ya expone. Del servidor se tocan dos cosas, las dos porque estorban:
el **envío de la cotización por WhatsApp**, que hoy no funciona —sale por Twilio, que nunca se
configuró, y la pantalla responde "Error del servidor"— y pasa a compartirse con el menú del propio
aparato, como el sistema ya manda el ticket de [027](027-venta-mostrador-ticket.md); y la validación
que obliga a escribir para consultar el catálogo de **usos de CFDI**, que impide mostrarlo en una
pantalla de opciones.

### Qué ya existe y no se rehace

- **La instalación**: `VitePWA` con `registerType: 'autoUpdate'`, manifest con nombre, colores e
  iconos —incluido el `maskable` que Android recorta en círculo— y precache del shell sin
  `runtimeCaching`, para que ninguna respuesta autenticada quede guardada en disco.
- **La lectura de un QR con la cámara del aparato**: `leerQr()` en `lib/constanciaFiscal.ts`, que usa
  el detector nativo del navegador y devuelve `null` —sin lanzar— cuando el navegador no lo trae
  (ver [016](016-constancia-situacion-fiscal-qr.md)).
- **El alta de un cliente a partir de su constancia**: `POST clientes/constancia`, con su límite de
  10 peticiones por minuto, y el componente `ConstanciaFiscalDropzone`.
- **El compartir del sistema**: `compartirImagen` —que aquí pasa a llamarse `compartirArchivo`— y
  `compartirTexto` en `lib/compartir.ts`, que en celular abren el menú del aparato y en escritorio
  descargan y copian (ver [020](020-imagenes-articulos.md) y
  [027](027-venta-mostrador-ticket.md)).
- **La entrega por escaneo**: `PedidoEntregaView` y `POST pedidos/{pedido}/entregar`, que cobra el
  saldo, marca entregado y admite deshacerse durante diez segundos (ver
  [027](027-venta-mostrador-ticket.md)).
- **Las búsquedas del servidor**: `GET articulos` con `search` sobre nombre, modelo y proveedor, y
  `GET clientes` con `search` sobre razón social, nombre comercial y RFC, los dos ya paginados. Las
  pantallas de tarjetas del mostrador se arman sobre esos mismos endpoints; el buscador de escritorio
  (`ArticuloBuscador`) y el combo de clientes (`ClienteCombobox`) se quedan donde están.
- **El ticket dibujado en el servidor** y la sugerencia por teléfono (`GET pedidos/por-telefono`).
- **El PDF de la cotización**, generado al vuelo por `EnvioDocumentoService` y servido por `GET
  cotizaciones/{cotizacion}/pdf` (ver [008](008-cotizaciones.md) y
  [019](019-formato-pdf-documentos.md)).

### Por qué el interruptor es la instalación y no el tamaño de la pantalla

El sistema tiene que decidir, al abrirse, si muestra cuatro botones o el sistema entero. Se decide
por **cómo se abrió la aplicación**: desde el icono instalado, o desde el navegador.

Es la señal que dice lo que el usuario realmente quiso. Instalar la aplicación en el celular del
mostrador es un acto deliberado —alguien la instaló ahí para vender—, mientras que el ancho de la
pantalla es un accidente: una ventana angosta en la computadora, o una tableta grande, caerían del
lado equivocado sin que nadie lo haya pedido. Tampoco es una dirección aparte, que habría que
escribir o guardar la primera vez y que se podría abrir por error desde la computadora.

**Consecuencia asumida**: si algún día la aplicación se instala también en la computadora, esa
instalación mostrará los cuatro accesos. Se acepta a cambio de no tener que mantener un ajuste ni
adivinar por el tamaño; desinstalarla, o abrirla desde el navegador, devuelve el sistema completo.

## Backend (Laravel)

Ninguna tabla y ninguna columna. Los cuatro caminos consumen lo que ya existe:

| Camino     | Endpoints que usa                                                                        |
| ---------- | ---------------------------------------------------------------------------------------- |
| Venta      | `GET pedidos/por-telefono`, `POST pedidos`, `POST pedidos/{pedido}/pagos`, `GET pedidos/{pedido}/ticket` |
| Factura    | `GET clientes`, `POST clientes/constancia`, `POST clientes`, `GET catalogos/usos-cfdi`, `GET catalogos/formas-pago`, `POST facturas`, `POST facturas/{factura}/timbrar`, `GET facturas/{factura}/pdf`, `POST facturas/{factura}/enviar-correo` |
| Cotización | `GET clientes`, `POST clientes/constancia`, `POST clientes`, `POST cotizaciones`, `GET cotizaciones/{cotizacion}/pdf`, `POST cotizaciones/{cotizacion}/marcar-enviada`, `POST cotizaciones/{cotizacion}/enviar` |
| Escáner    | `POST pedidos/{pedido}/entregar`, `POST pedidos/{pedido}/deshacer-entrega`                |

Que el modo mostrador no invente endpoints es la prueba de que es **otra forma de llegar** a lo que
el sistema ya hace, y no un sistema paralelo con sus propias reglas. Las validaciones, los folios,
los totales y el timbrado siguen siendo exactamente los mismos.

Del servidor cambian dos cosas: el envío por WhatsApp de la cotización, porque está roto, y una
validación del catálogo de usos de CFDI, porque impide listarlo.

### `GET catalogos/usos-cfdi` sin `q`

Hoy `q` es obligatorio con dos caracteres mínimo, así que el endpoint solo sabe buscar: la pantalla
de uso de CFDI abriría vacía, esperando que alguien escriba, cuando lo que tiene que hacer es
mostrar el catálogo. `q` pasa a ser **opcional** y, sin él, se devuelve la lista completa ordenada
por clave; con él, la búsqueda responde exactamente como hasta ahora, que es de lo que depende el
portal de autofactura de [027](027-venta-mostrador-ticket.md).

Es un cambio de una regla de validación, no un endpoint nuevo: el catálogo es el mismo y quien lo
pide con `q` no nota nada.

### El WhatsApp de la cotización deja de salir del servidor

Hoy `POST cotizaciones/{cotizacion}/enviar` con `canal: whatsapp` le pide a Twilio que mande el
mensaje, con el PDF colgado de una URL firmada temporal (`cotizaciones.pdf-publico`) para que la
infraestructura de Twilio pueda descargarlo. Sin `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` y
`TWILIO_WHATSAPP_FROM` configuradas —y nunca lo estuvieron— el servicio lanza y el usuario ve un
error del servidor. El botón nunca mandó un solo mensaje.

- **`canal` solo acepta `correo`** en `EnviarCotizacionRequest`. El canal `whatsapp` se retira junto
  con `telefono`, que era su único parámetro.
- **Se retiran `cotizaciones.pdf-publico` y `Cotizacion::urlPdfPublico()`**, que existían nada más
  para que Twilio descargara el archivo. Una ruta fuera de `auth:sanctum` que ya no sirve a nadie es
  una puerta abierta sin razón, aunque esté firmada.
- **`TwilioWhatsAppService` se queda**: las órdenes de compra de [012](012-ordenes-compra.md) siguen
  enviándose por ahí y no son asunto de esta spec.

El PDF que el cliente recibe es el mismo de siempre, generado al vuelo por `EnvioDocumentoService`;
lo que cambia es quién lo entrega. En vez de un número de Twilio que el cliente no reconoce, sale de
la sesión de WhatsApp que el aparato ya tiene abierta, que es el criterio con el que
[027](027-venta-mostrador-ticket.md) manda el ticket y [020](020-imagenes-articulos.md) la ficha del
artículo.

### `POST cotizaciones/{cotizacion}/marcar-enviada`

Compartir ocurre en el teléfono, fuera del alcance del servidor. Sin avisarle, una cotización que el
cliente ya recibió por WhatsApp se quedaría en `borrador` para siempre, y el listado del escritorio
—que se lee por estado— mentiría.

El endpoint hace una sola cosa: si la cotización está en `borrador`, la pasa a `enviada`. Sobre una
ya `enviada` o `pagada` no hace nada y responde igual, porque compartir dos veces la misma cotización
es normal y no tiene por qué fallar. Es exactamente la transición que `enviar` ya disparaba como
efecto secundario, separada del envío ahora que el envío no lo hace el servidor.

## Frontend (Vue 3)

### El modo mostrador

`lib/modoMostrador.ts` expone `enModoMostrador(): boolean`, que responde
`window.matchMedia('(display-mode: standalone)').matches`.

Se evalúa **una sola vez al arrancar** y el resultado se guarda: una ventana o se abrió desde el
icono o se abrió desde el navegador, y eso no cambia mientras esté abierta. Consultarlo en cada
repintado solo abriría la puerta a que media aplicación se dibujara de un modo y media del otro.

En desarrollo hace falta poder ver el modo mostrador sin instalar nada, así que
`?mostrador=1` en la dirección lo fuerza **solo cuando `import.meta.env.DEV`**. En producción el
parámetro se ignora: si sirviera, cualquiera podría dejarse encerrado en los cuatro accesos sin
saber cómo salir.

#### El candado de navegación

"No se llega por ningún medio" no se cumple escondiendo el menú: se cumple en el guard del router.
En modo mostrador, solo estas rutas son alcanzables:

- `dashboard` — los cuatro accesos.
- `mostrador-venta`, `mostrador-factura`, `mostrador-cotizacion`, `mostrador-escanear`.
- `pedidos-entregar` — el destino del QR de la etiqueta, sin el cual el cuarto acceso no serviría.
- `login`, `forgot-password`, `reset-password` — la puerta de entrada.

Cualquier otro nombre de ruta redirige a `dashboard`. Así, una dirección vieja guardada en el
navegador, un enlace pegado o un botón que quedó apuntando a una pantalla de escritorio terminan en
los cuatro accesos en vez de mostrar media aplicación que ahí no se puede usar.

Las rutas públicas que no pertenecen al sistema con sesión —`autofactura`— quedan fuera del candado:
las abre el cliente desde su propio teléfono y no tienen nada que ver con el aparato del mostrador.

#### La barra superior

`AppLayout` gana la propiedad `mostrador`. Con ella:

- **Desaparecen** el menú de grupos y el menú de usuario: son puertas a lo que el candado ya cierra,
  y dejarlas a la vista sería ofrecer lo que no se puede dar.
- **Queda** el nombre del sistema, que en las pantallas interiores funciona como botón de regreso a
  los cuatro accesos.
- **Cerrar sesión** baja al pie de la pantalla de los cuatro accesos, como un enlace discreto. Tiene
  que existir en algún lado —un aparato del que no se puede salir es un aparato prestado para
  siempre— pero no compite con los cuatro botones.

### La pantalla de los cuatro accesos

Vive en `/dashboard`, la misma dirección de hoy, y **la misma dirección muestra dos cosas distintas**:
en modo mostrador, los cuatro accesos; en el navegador, la pantalla de inicio actual tal como está.
Una sola dirección de inicio, sin una segunda que aprender.

- Cuatro botones en **cuadrícula de dos por dos**, cada uno con su ícono grande arriba y su texto
  debajo, y **toda la superficie del cuadro es tocable**, no solo las letras.
- Ocupan el alto disponible en partes iguales: en un celular de 375 puntos cada botón queda por
  encima de los 44 puntos de lado que se consideran el mínimo para el dedo, con margen de sobra.
- **Sin cifras, sin gráficas y sin pendientes.** Ni ventas del día, ni saldos, ni trabajos
  pendientes. Un número en esta pantalla invita a interpretarlo, y esta pantalla no es para leer: es
  para arrancar a trabajar en un toque.
- **Los cuatro son fijos**: no se reordenan, no se configuran y no cambian según lo que más se use.
  Un botón que se mueve solo obliga a mirar antes de tocar, que es justo lo que se quiere evitar.

| Acceso                  | Ícono              | Destino                  |
| ----------------------- | ------------------ | ------------------------ |
| Generar factura         | `DocumentTextIcon` | `/mostrador/factura`     |
| Generar cotización      | `DocumentDuplicateIcon` | `/mostrador/cotizacion` |
| Venta al público        | `TicketIcon`       | `/mostrador/venta`       |
| Escanear etiquetas      | `QrCodeIcon`       | `/mostrador/escanear`    |

Los íconos son los mismos con que cada módulo aparece en el menú de [013](013-navegacion-principal.md),
para que quien use las dos caras del sistema reconozca el mismo dibujo en las dos.

### La captura por pasos

Los tres formularios de hoy son la misma cosa: una **tabla ancha de renglones** con buscador de
artículo, precio, descuento, IVA y totales al pie (`DocumentoLineas`). Está hecha para teclado,
ratón y una pantalla grande; en un celular hay que arrastrarla de lado para ver las columnas.

El modo mostrador no la adapta: la reemplaza por **una pantalla por paso**, con un solo asunto a la
vez, un botón grande abajo para seguir y el paso actual señalado arriba. Donde el toque ya decide
—elegir al cliente de la lista— no hay botón de "Siguiente": apretar un segundo botón para confirmar
lo que se acaba de tocar es un toque que no decide nada. Adaptar la tabla habría
dejado la peor versión de las dos cosas —una tabla incómoda que además hay que mantener en dos
anchos—, y agrandar los campos de hoy no arregla que sean demasiados a la vez.

Los formularios de escritorio **no se tocan**: siguen siendo los de siempre para quien trabaja en la
computadora.

#### Lo que se comparte entre los tres

- **Elegir artículos es tocar tarjetas**, con la misma pantalla en los tres caminos, y revisarlos es
  el **carrito** que viene después. Son dos pantallas, no una: buscar y revisar son dos trabajos
  distintos y meterlos en la misma obliga a que uno de los dos quede apretado contra el borde.
- **Los renglones salen del catálogo.** La factura y la cotización ya lo exigen en el backend
  (`lineas.*.articulo_id` y `lineas.*.modelo` son obligatorios), así que en esos dos no hay renglón
  libre. La venta al público sí lo admite —es la línea libre de 027— y ofrece un botón de "Artículo
  suelto" para capturar descripción y precio a mano.
- **La factura y la cotización eligen cliente con la misma pantalla**, la de tarjetas descrita
  abajo. La venta al público no la usa: ahí el cliente es un teléfono y un nombre, no una ficha
  fiscal.
- **Los totales se calculan con `lib/totalesDocumento.ts`**, el mismo módulo que ya usan los
  formularios de escritorio, **pidiéndole el ajuste al peso** de
  [030](030-total-al-peso-cerrado.md) igual que ellos. No se reimplementa la aritmética: un centavo
  de diferencia entre las dos caras del sistema sería imposible de explicarle a un cliente. El total
  que se ve en la barra del carrito ya viene en peso cerrado, que es el número que el cliente
  escucha en el mostrador.
- **Se puede volver atrás** paso por paso sin perder lo capturado. Salirse de la captura a medias
  pide confirmación: en un celular el gesto de "atrás" está a un dedo de distancia todo el tiempo.

#### El paso de cliente (factura y cotización)

Una sola pantalla con tres caminos hacia el mismo lugar: dos botones grandes arriba —**"Subir RFC"**
y **"Nuevo cliente"**—, el buscador debajo y la lista de clientes en tarjetas ocupando el resto.

- **La lista se ve desde que abre la pantalla**, sin escribir nada: los primeros clientes por razón
  social, y al llegar al final se carga sola la página siguiente. El combo de escritorio no muestra
  nada hasta escribir dos letras, lo que obliga a recordar cómo empieza el nombre; una lista que se
  recorre sirve también para el cliente que se reconoce al verlo.
- **El buscador filtra** por razón social, nombre comercial o RFC, contra `GET clientes`, que ya
  busca en esos tres campos.
- **Cada tarjeta** trae la razón social en grande, el RFC en monoespaciado, el teléfono y el correo
  si los tiene —son los datos con los que después se le manda el documento— y su descuento
  permanente cuando es mayor a cero (ver [015](015-descuento-permanente-cliente.md)). Toda la
  superficie es tocable, y **tocarla elige al cliente y avanza**.
- **"Subir RFC"** abre la Constancia de Situación Fiscal por las dos vías que tiene un celular:
  elegir el archivo —el PDF que el cliente trae en el teléfono, o una foto— con
  `ConstanciaFiscalDropzone`, o apuntar la cámara al QR. Las dos terminan en `POST
  clientes/constancia` y dejan al cliente dado de alta en el momento, con el camino de
  [016](016-constancia-situacion-fiscal-qr.md). Solo con la cámara no alcanzaba: la constancia suele
  llegar por WhatsApp y no siempre hay una hoja impresa que apuntar.
- **"Nuevo cliente"** da de alta a mano, con los cuatro datos que el CFDI exige —RFC, razón social,
  régimen fiscal y código postal fiscal— más teléfono y correo, que la pantalla final necesita para
  enviar. El régimen se elige de la lista del catálogo del SAT, la misma del alta de escritorio, no
  se escribe.

  Capturar a mano en el celular sí tiene el riesgo que la versión anterior de esta spec quiso
  evitar, pero **una cotización no se timbra**: un RFC mal escrito ahí se corrige antes de facturar y
  no cuesta un folio ni una cancelación. A cambio, sin este botón un cliente sin constancia a la mano
  no se puede cotizar desde el mostrador, que es justo el caso de quien llega preguntando precios.

#### El paso de artículos

- **Tarjetas, no renglones de una tabla.** Cada una lleva la imagen del artículo cuando la tiene
  (ver [020](020-imagenes-articulos.md)), su nombre, su modelo y su precio unitario sin IVA. Sin
  imagen, un recuadro con ícono: un hueco descuadraría la cuadrícula y haría dudar de si algo falló.
- **El catálogo se ve desde el arranque**, con la página siguiente cargándose al llegar al final, y
  el buscador lo filtra por nombre, modelo o proveedor —lo que `GET articulos` ya hace con `search`—.
  Se puede vender recorriendo el catálogo, sin saber cómo se llama lo que se busca.
- **Un toque suma una unidad y no sale de la pantalla.** La tarjeta ya agregada muestra su cantidad
  encima y volver a tocarla suma otra. Así se arma una cotización de diez renglones sin salir y
  volver diez veces; la cantidad grande se corrige después, en el carrito, que es donde se ve todo
  junto.
- **Una barra fija al pie** muestra "n artículos · $total" y el botón que cierra la captura
  —"Terminar cotización", "Terminar factura", "Terminar venta"—, apagado mientras el carrito esté
  vacío. El total a la vista mientras se agrega es lo que el cliente pregunta enfrente.
- **La venta al público conserva "Artículo suelto"**, que factura y cotización no ofrecen porque el
  backend les exige el artículo del catálogo.

#### El carrito

Es la lista de renglones que hoy vive debajo del buscador, ahora en su propia pantalla y sin
buscador: un renglón por artículo con su descripción, su precio unitario, los botones de "−" y "+",
el de quitar y el importe del renglón, con el total al pie.

- **Se puede volver a las tarjetas** a agregar más sin perder nada, y regresar.
- **El descuento permanente del cliente ya viene aplicado** en cada renglón, como en el formulario de
  escritorio (ver [015](015-descuento-permanente-cliente.md)). Cambiar de cliente lo reemplaza en
  todos los renglones ya capturados, misma regla de esa spec.
- **Nada se guarda hasta el botón final.** Salirse a medias pide confirmación y no deja documentos a
  medio capturar en el sistema.

#### Las pantallas de opción

Uso de CFDI y forma de pago son la **misma pantalla con otra lista**: buscador arriba, tarjetas
abajo con la clave y su descripción, la elegida marcada con una palomita, y un toque que elige y
avanza. Escritas dos veces se verían distintas el día que una de las dos se corrigiera.

- **La lista entera se trae en una sola petición** al abrir la pantalla, y se muestra **de 15 en 15**
  con el mismo scroll infinito de los clientes y los artículos.
- **El buscador filtra en el navegador**, sobre lo ya traído. Es la diferencia con las otras dos
  listas, y es a propósito: estos son catálogos cerrados del SAT, de unas dos docenas de entradas
  cada uno, así que pedirle una página al servidor por cada scroll y una búsqueda por cada letra
  serían peticiones por nada, y filtrar sin esperar respuesta se siente inmediato.

#### Venta al público — `/mostrador/venta`

Es el pedido de mostrador de [027](027-venta-mostrador-ticket.md), no un documento nuevo.

1. **Cliente** — teléfono y nombre. Al escribir el teléfono se consulta `GET pedidos/por-telefono` y,
   si ese número ya compró antes, se **ofrece** rellenar el nombre y el correo. Es una sugerencia que
   el usuario acepta con un toque, no un autocompletado que pisa lo que está escribiendo.
2. **Artículos** — las tarjetas del catálogo, con el botón de "Artículo suelto".
3. **Carrito** — los renglones y sus cantidades, y el botón que pasa al cobro.
4. **Cobro** — el total a la vista, el monto a cobrar ya escrito con el total (se puede bajar para
   registrar un anticipo) y la cuenta ya elegida: **la caja**, que es la cuenta de efectivo activa
   más antigua. Se puede cambiar a otra cuenta con un toque.

   Aquí sí se preselecciona, al revés que en la pantalla de entrega de
   [027](027-venta-mostrador-ticket.md), donde la cuenta se elige siempre. La diferencia es a quién
   se le está cobrando: la entrega cierra un pedido que pudo pagarse por transferencia días antes,
   mientras que esta pantalla cobra la venta que está ocurriendo enfrente, con el cliente pagando en
   el mostrador. Ahí la caja no es una comodidad que invite a confirmar por inercia: es lo que pasa
   casi siempre.
5. **Listo** — el ticket dibujado por el servidor, en grande, con **"Compartir por WhatsApp"**
   (`compartirImagen` con el mensaje de Configuración ya resuelto), más "Nueva venta" e "Inicio".

El paso de cobro hace **dos peticiones**: crea el pedido y luego registra el pago. Si la segunda
falla, el pedido **ya existe** y la pantalla lo dice con su número de ticket, ofreciendo reintentar
solo el cobro. Callarlo llevaría a capturar la venta otra vez y a terminar con dos pedidos por una
sola compra.

**La etiqueta adhesiva no se imprime aquí.** Un celular no tiene la impresora de etiquetas
conectada, y montar una cola de impresión hacia la computadora es un sistema entero por sí solo.
La venta queda registrada, cobrada y con su ticket en el teléfono del cliente; la etiqueta se
imprime desde la computadora como hasta hoy, y es esa etiqueta la que después se escanea.

#### Factura — `/mostrador/factura`

1. **Cliente** — la pantalla de tarjetas: elegir de la lista, subir la constancia o capturarlo a
   mano. Para la factura, "Subir RFC" es el camino recomendado y el que la pantalla sugiere: un CFDI
   exige RFC, régimen y código postal correctos, y escribir esos tres a mano con el cliente enfrente
   es la manera más segura de timbrar mal.
2. **Artículos** — las tarjetas del catálogo, sin artículo suelto.
3. **Carrito** — los renglones y sus cantidades.
4. **Uso de CFDI** — una pantalla de opciones, descrita abajo.
5. **Forma de pago** — la misma pantalla, con el otro catálogo.
6. **Método de pago** — dos botones grandes: **PUE**, "Pago en una sola exhibición", y **PPD**,
   "Pago en parcialidades o diferido". Son dos y son los mismos siempre, así que no hay lista que
   buscar ni petición que hacer —van escritos en la pantalla, como ya manda
   [007](007-facturacion.md)—: la clave sola no dice nada y el nombre completo evita elegir de
   memoria.
7. **Confirmar y timbrar** — nombre, RFC y total, grandes; debajo, en letra chica, los tres datos
   fiscales recién elegidos, y un solo botón: **"Timbrar"**.
8. **Listo** — el resultado.

Los tres datos que el backend exige (`uso_cfdi`, `forma_pago`, `metodo_pago`) tienen **una pantalla
cada uno** en vez de compartir un formulario. Los tres son listas del SAT en las que hay que
encontrar algo, y encontrarlo en un `<select>` de celular —una lista dentro de una ventanita, sin
buscador— es la parte más incómoda de la captura. En su propia pantalla cada uno tiene el ancho
entero, su buscador y el dedo entero para tocar.

**Tocar la opción elige y avanza**, como en el paso de cliente, y ninguna de las tres trae nada
preseleccionado: con avance automático un valor por omisión no ahorra el toque y sí puede colarse sin
que nadie lo mire. Al volver atrás, la que se había elegido se ve marcada.

El paso de revisión no es un trámite de más. Timbrar cuesta un folio, queda registrado ante la
autoridad y deshacerlo no es borrar sino cancelar con un motivo. Una pantalla limpia antes de apretar
es barata comparada con una cancelación, y los tres datos fiscales van ahí porque tres pantallas
atrás ya no se recuerdan de memoria.

Después del timbrado, la pantalla de resultado muestra el folio fiscal y ofrece **"Enviar por
WhatsApp"**, **"Enviar por correo"** (`POST facturas/{factura}/enviar-correo`, con el correo del
cliente ya escrito en su diálogo), "Nueva factura" e "Inicio" —la misma pantalla que cierra la
cotización—. Sin esos botones, el cliente se iría del mostrador con su factura timbrada y sin
recibirla.

Por WhatsApp va **solo el PDF**. El XML no puede salir por ahí: Chrome en Android comparte
únicamente los tipos de archivo de una lista fija —imágenes, audio, video, texto plano y
`application/pdf`— y **`.xml` no está en ella**, con ningún tipo MIME. No es que el aparato prefiera
un archivo a dos: es que el XML nunca va a pasar por ese menú (ver "El XML no cabe en el menú del
aparato").

**El XML le llega al cliente por correo**, que es el otro botón de esta misma pantalla y que sale del
servidor con los dos archivos adjuntos. Es el camino que el contador necesita, y era el que ya
existía. Desde la computadora se sigue bajando con su propio botón en el detalle de la factura.

El PDF se baja **al entrar a esta pantalla**, con las reglas de "El compartir del aparato": esperar a
la descarga después del toque agotaría el gesto que el menú necesita.

**Compartir no cambia el estado de la factura.** Una factura timbrada ya está timbrada, y no hay un
"enviada" que mover como en la cotización, así que aquí no hace falta ningún `marcar-enviada`.

Si el timbrado falla, la factura **queda guardada** y la pantalla muestra el motivo con un botón de
reintentar, que es como se comporta el timbrado del escritorio.

#### Cotización — `/mostrador/cotizacion`

Los pasos se llaman **Cliente, Artículos, Carrito y Listo**, con el indicador arriba de la pantalla.

1. **Cliente** — la pantalla de tarjetas: elegir de la lista, subir la constancia o capturarlo a
   mano. El backend exige `cliente_id` del catálogo fiscal, así que cotizarle a alguien que no está
   pasa por darlo de alta, y los tres caminos terminan en un cliente del catálogo.
2. **Artículos** — las tarjetas del catálogo, sin artículo suelto. Al pie, "Terminar cotización".
3. **Carrito** — los renglones, sus cantidades y el botón **"Guardar cotización"**, que es donde se
   crea el documento (`POST cotizaciones`).
4. **Listo** — folio, cliente, número de renglones y total, con cuatro botones: **"Enviar por
   WhatsApp"**, **"Enviar por correo"**, "Nueva cotización" e "Inicio".

##### Cómo sale por WhatsApp

El sistema descarga el PDF con su propia sesión (`GET cotizaciones/{cotizacion}/pdf`) y se lo entrega
al **menú de compartir del aparato**, con el resumen de la cotización como texto. El usuario elige el
contacto en WhatsApp y manda el PDF desde el número del negocio. Lo hace `compartirArchivo` de
`lib/compartir.ts`, descrito abajo en "El compartir del aparato".

- **No hay campo de teléfono.** El contacto se elige en el menú del aparato, que ya conoce los
  contactos; pedir el número en la pantalla sería capturar un dato que nadie va a usar.
- **Al volver del menú**, el sistema llama a `POST cotizaciones/{cotizacion}/marcar-enviada` y la
  cotización pasa a "enviada". **Si el usuario cancela el menú, no se marca**: cancelar es no haber
  mandado nada, y un estado que miente es peor que uno que se quedó corto.

##### Cómo sale por correo

`POST cotizaciones/{cotizacion}/enviar` con `canal: correo`, con el correo del cliente ya escrito y
editable antes de mandar. Ese envío lo sigue haciendo el servidor, que adjunta el PDF y ya deja la
cotización en "enviada". Un correo mandado desde el servidor queda registrado y sale del dominio del
negocio; no hay razón para bajarlo al teléfono.

### El compartir del aparato

Lo hace `compartirArchivo` de `lib/compartir.ts`, la misma función con la que
[027](027-venta-mostrador-ticket.md) manda el ticket. Se llamaba `compartirImagen`: siempre recibió
un `Blob`, un nombre y un texto, y ese nombre describía a su único usuario, no lo que hace.

**Comparte un archivo, no varios.** Los tres documentos mandan uno solo —el ticket, el PDF de la
cotización, el PDF de la factura— desde que el XML dejó de intentarlo.

Tres reglas, y las tres existen porque sin ellas el botón falla de maneras que no se explican solas:

- **Los archivos se preparan al llegar a la pantalla de resultado, no al apretar el botón.** El menú
  de compartir solo se abre mientras el gesto del usuario sigue vivo, y el navegador da unos pocos
  segundos: un `await` de por medio lo agota y el compartir se rechaza. Con una descarga corta a
  veces alcanza y a veces no, que es la peor de las conductas posibles. Así que al entrar a la
  pantalla el sistema baja lo que va a mandar, el botón dice **"Preparando..."** y queda apagado
  mientras tanto, y al tocarlo ya no hay nada que esperar: se comparte lo que está en la mano.
- **Si el menú del aparato no se puede abrir, el botón sigue mandando por WhatsApp**: descarga el
  archivo, copia el mensaje y **abre WhatsApp** —Web o Desktop, lo que el aparato tenga— con el texto
  ya escrito, para elegir el contacto y adjuntar lo descargado. El botón dice "Enviar por WhatsApp";
  dejar el archivo en la carpeta de descargas y callarse no es cumplir eso.
- **Cancelar el menú no es un error.** No se avisa nada, no se marca nada y la pantalla se queda
  como estaba.

Y lo que sí es un fallo —que la descarga del PDF o del XML no llegue— **se dice con su motivo**. Esas
peticiones se piden como `blob`, así que el JSON de error del servidor llega envuelto y hay que
leerlo antes de darlo por perdido; sin eso, un 404 o un 502 se verían como un "error inesperado" sin
pista de qué pasó, que es justo lo que un mostrador no puede permitirse.

La decisión entre un camino y otro se toma preguntándole al navegador si puede compartir archivos, no
por el ancho de la pantalla: en escritorio muchos navegadores no tienen ese menú, y un botón que a
veces hace una cosa y a veces otra según el tamaño de la ventana es peor que dos conductas claras.

### El WhatsApp de la cotización en el escritorio

El modal de "Enviar" del escritorio (ver [008](008-cotizaciones.md)) tiene el mismo botón roto, por
la misma razón. Su canal de WhatsApp deja de mandar el formulario al servidor y pasa a compartir el
PDF igual que el mostrador: descarga y menú del aparato, o `wa.me` con el archivo descargado donde
ese menú no existe. Con él se va el campo de teléfono del modal, que ya no tiene a quién servir; el
canal de correo se queda exactamente como está.

Es la única parte del escritorio que esta spec toca, y la toca porque **es el mismo botón**: dejarlo
llamando a Twilio sería sostener a propósito una pantalla que responde con un error, y mantener dos
formas distintas de mandar la misma cotización según desde dónde se abra.

### El escáner de etiquetas — `/mostrador/escanear`

Hoy, para entregar un pedido, hay que salir a la app de cámara del celular, que abre la dirección de
la etiqueta en el navegador. El escáner elimina ese rodeo: la cámara se abre dentro de la aplicación.

- **La cámara trasera a pantalla completa**, con un recuadro guía al centro.
- **Captura sola.** No hay botón de disparo: se leen los cuadros de video hasta reconocer un código.
  Apuntar y esperar es lo único que se puede hacer con un paquete en la otra mano.
- **La lectura se extrae a `lib/lectorQr.ts`**, con `leerQr()` mudándose ahí desde
  `lib/constanciaFiscal.ts`, que pasa a importarlo. Dos pantallas leen el mismo tipo de código con el
  mismo detector, y una copia se habría quedado atrás la primera vez que se corrigiera un caso.
- **Qué se acepta**: el QR de la etiqueta codifica `{frontend_url}/pedidos/{id}/entregar` —lo escribe
  `Pedido::urlEntrega()`—. Se acepta un código **del mismo origen que la aplicación** y que calce con
  esa forma; de ahí sale el número de pedido y se navega a `pedidos-entregar` **dentro** de la
  aplicación, sin recargar nada.
- **Un código ajeno se ignora**: aviso corto sobre el video —"Ese código no es de una etiqueta del
  sistema"— y el escáner sigue trabajando. Nunca se abre una dirección de afuera: un QR pegado en
  cualquier caja podría llevar a donde sea, y el escáner de un punto de venta no es un navegador.
- **Un código repetido no se procesa dos veces** mientras la etiqueta siga frente a la cámara.

Al terminar la entrega, `PedidoEntregaView` en modo mostrador cambia su pie: en vez de "Ver el
pedido completo" —una pantalla que el candado no permite— ofrece **"Escanear otra"** e "Inicio". En
el mostrador se entregan varios trabajos seguidos y volver al inicio entre uno y otro son dos toques
por paquete.

**Lo que hace el escaneo no cambia.** Cobra el saldo, marca entregado y admite deshacerse durante
diez segundos, exactamente como manda 027. Esta spec cambia cómo se llega, no lo que pasa al llegar.

#### Las cuatro ayudas de la cámara

- **Linterna**: un botón prende el foco del aparato sin salir de la aplicación
  (`applyConstraints({ torch: true })`), para las etiquetas bajo la sombra del mostrador. Se muestra
  solo si la cámara del aparato lo soporta.
- **Pantalla despierta**: mientras el escáner está a la vista se pide que el aparato no se bloquee
  (Wake Lock). Sin eso hay que desbloquear el celular entre etiqueta y etiqueta. Se suelta al salir
  de la pantalla y se vuelve a pedir al regresar a ella, que es lo que exige esa API cuando el
  aparato se va a segundo plano.
- **Vibración**: un zumbido corto (`navigator.vibrate(60)`) al reconocer un código válido. Es la
  confirmación que no obliga a mirar la pantalla.
- **Respaldo por foto**: cuando la cámara en vivo no se puede abrir —permiso denegado, o un
  navegador que no da `getUserMedia`—, en vez de una pantalla muerta se ofrece **tomarle una foto a
  la etiqueta**, que se lee con el mismo detector. Es el camino para el aparato que sí trae detector
  pero no deja mirar por la cámara.

**Lo que el respaldo por foto no puede cubrir**: un navegador que no trae el detector tampoco puede
leer la foto, porque quien la leería es el detector que falta. Decodificar un QR sin él exigiría una
librería de terceros en el navegador —o una en PHP, porque el backend tampoco decodifica imágenes:
el `qr_url` de la constancia siempre lo lee el navegador (ver
[016](016-constancia-situacion-fiscal-qr.md))—, y ninguna de las dos entra en el alcance de esta
spec. Ahí la pantalla lo dice con todas sus letras y manda a abrir la etiqueta con la app de cámara
del teléfono, que es lo que se hacía antes de esta spec. Se acepta porque el aparato del mostrador
es Android, donde el detector existe (supuesto 25); el día que se sume un aparato con Safari, sumar
la librería es el cambio que toca, y es una spec nueva.

Los cuatro son opcionales por naturaleza: cada uno se ofrece solo si el aparato lo soporta y su
ausencia nunca rompe el escaneo.

### Instalación

- **Botón "Instalar aplicación"**, dentro del sistema y a la vista. Hoy instalar depende de encontrar
  "Instalar" en el menú del navegador, que casi nadie encuentra. El navegador avisa cuando la
  instalación es posible (`beforeinstallprompt`); se guarda ese aviso y el botón lo dispara.
  - Vive en la pantalla de inicio del navegador y **desaparece** cuando ya está instalada o cuando el
    navegador no ofrece instalarla. Un botón que no puede cumplir lo que promete es peor que ninguno.
- **Atajos en el icono**: dos entradas en el manifest (`shortcuts`), "Nueva venta" y "Escanear", para
  que al dejar el dedo apretado sobre el icono se entre directo sin pasar por los cuatro accesos.
- **Arranque limpio**: `start_url` apunta a `/dashboard`. La aplicación instalada **siempre abre en
  los cuatro accesos**, no en la última pantalla que se estaba viendo. Abrir el punto de venta y
  encontrarse a medio capturar la venta de ayer es peor que empezar de cero.

**Dependencia**: fuera de `localhost` el navegador **no registra el service worker ni ofrece
instalar sin HTTPS**. Hasta que exista el servidor de [022](022-subdominio-app.md) con su
certificado, el modo mostrador se puede construir y probar en `localhost` —que está exento— pero no
se podrá instalar en el celular del mostrador. Nada de lo que esta spec define depende de esa
migración salvo la instalación misma.

### Actualización

`registerType` pasa de `'autoUpdate'` a `'prompt'`, y un aviso discreto —una barra al pie con "Hay
una versión nueva" y un botón de recargar— aparece cuando hay una lista.

Hoy la actualización es silenciosa, lo que en la práctica significa que una pestaña abierta desde
ayer sigue con la versión vieja hasta que alguien la cierre, sin que nadie lo sepa. Un aparato de
mostrador se queda abierto días enteros: es justo donde ese silencio dura más. El aviso no
interrumpe —se puede seguir vendiendo con la versión que ya está cargada— pero deja de ser un
secreto.

### Sin conexión

- La aplicación **abre**: el shell está en el precache, así que arranca aunque no haya internet.
- En cuanto una pantalla necesita datos y no los consigue, muestra un **aviso claro del sistema** —"Sin
  conexión. Revisa el internet e inténtalo de nuevo"— con un botón de reintentar, en lugar de una
  pantalla en blanco o el error crudo del navegador.
- **Los datos siguen saliendo siempre de la red.** No se guarda nada del API en disco ni se capturan
  ventas para mandarlas después: una venta que existe solo en un celular no existe en la caja, y el
  precache de respuestas autenticadas es la forma clásica de servir datos viejos sin que nadie se
  entere. "Rápida" significa que la aplicación arranca al instante, no que trabaje sola.

### Sesión

Sin sesión iniciada, el guard sigue mandando al login y regresando a donde iba, igual que hoy.
En modo mostrador, "donde iba" es casi siempre `/dashboard`, y el escaneo de una etiqueta con la
sesión caída sigue llevando a la entrega después de entrar, que es lo que 027 ya resuelve.

### Rutas nuevas

```
/mostrador/venta        → mostrador-venta
/mostrador/factura      → mostrador-factura
/mostrador/cotizacion   → mostrador-cotizacion
/mostrador/escanear     → mostrador-escanear
```

Van bajo un prefijo común para que el candado del guard sea una sola regla y no una lista que haya
que recordar ampliar. **No son una puerta de entrada**: nadie las escribe ni las guarda; se llega a
ellas tocando los cuatro accesos. Quien las abra desde el navegador verá la captura por pasos, que
funciona igual pero está pensada para el dedo.

Ninguna ruta existente cambia de dirección ni de nombre.

## Fuera de alcance

- **Que la aplicación funcione sin internet**: capturar ventas desconectado y sincronizarlas después.
- **Imprimir la etiqueta adhesiva desde el celular**, en cualquier forma, incluida una cola de
  impresión hacia la computadora.
- **Editar o consultar documentos ya capturados** desde el modo mostrador: los cuatro accesos son
  para crear y para entregar, no para revisar. Consultar es trabajo de computadora. *(La mitad de
  consultar la abrió después [031](031-mostrador-consulta.md), con sus propias pantallas de
  tarjetas; editar sigue fuera.)*
- **Cobrar con terminal bancaria** o cualquier medio de pago que no sea registrar el monto.
- **Leer códigos de barras de artículos** para agregarlos a la venta. El catálogo no tiene códigos
  de barras capturados; el escáner solo lee el QR de las etiquetas del sistema.
- **Decodificar códigos QR sin el detector del navegador**, con una librería de terceros en el
  frontend o en PHP. El escáner descansa en el detector nativo, y donde no lo hay lo dice en vez de
  fingir que puede (ver "Las cuatro ayudas de la cámara").
- **Un quinto acceso**, de cualquier clase, y cualquier resumen, cifra o gráfica en la pantalla de
  inicio. *(La pantalla de inicio se quedó en cuatro; [031](031-mostrador-consulta.md) sumó tres
  secciones más en una barra al pie, sin tocar la cuadrícula.)*
- **Notificaciones al celular** (push).
- **Publicar la aplicación en Google Play** o empaquetarla con Capacitor.
- **Cambiar los formularios de escritorio** de factura, cotización o venta. La única excepción es el
  botón de WhatsApp de la cotización, que está roto y comparte con el mostrador el mismo arreglo.
- **Cambiar el envío por WhatsApp de las órdenes de compra**, que siguen saliendo por Twilio con lo
  que eso implica: si algún día se quiere que también se compartan desde el aparato, es otra spec.
- **Guardar el PDF de la cotización en el servidor.** Se sigue generando al vuelo en cada envío,
  mismo criterio de [008](008-cotizaciones.md).
- **Elegir el contacto de WhatsApp desde el sistema.** Eso lo hace el menú del aparato; el sistema
  entrega el archivo y no sabe a quién se le mandó.
- **La migración a `app.prosello.com.mx` con HTTPS**, que es la spec [022](022-subdominio-app.md)
  y de la que esta depende solo para poder instalarse.

## Criterios de aceptación

1. Abierta desde el icono instalado, la aplicación muestra **cuatro botones grandes y nada más**: sin
   menú de grupos y sin menú de usuario.
2. Abierta en el navegador, la aplicación es la de siempre, con su menú completo, y su pantalla de
   inicio se ve tal como está hoy.
3. En modo mostrador, escribir a mano la dirección de cualquier otra pantalla del sistema —artículos,
   cuentas, configuración— lleva a los cuatro accesos.
4. Los cuatro botones son tocables en toda su superficie y caben sin desplazar la pantalla en un
   celular de 375 puntos de ancho.
5. "Venta al público" captura por pasos, registra el pago y termina mostrando el ticket, con un botón
   que lo comparte por WhatsApp.
6. Si el pago falla después de haberse creado el pedido, la pantalla lo dice con el número de ticket y
   permite reintentar solo el cobro, sin capturar la venta otra vez.
7. "Generar Factura" pide uso de CFDI, forma de pago y método de pago **en tres pantallas
   distintas**, cada una con un toque que elige y avanza y ninguna con opción preseleccionada, y
   termina en una pantalla de revisión con **nombre, RFC, total y esos tres datos**, desde donde
   timbra. El resultado muestra el folio fiscal y permite mandarla por WhatsApp —con el PDF— o por
   correo, que es por donde va el XML.
8. El paso de cliente muestra tarjetas **sin escribir nada**, el buscador las filtra, y tocar una
   elige al cliente y pasa a artículos sin apretar nada más.
9. Al cliente que no está en el catálogo se le da de alta de tres formas desde el celular: subiendo
   el archivo de su constancia, escaneando su QR con la cámara, o capturándolo a mano con RFC, razón
   social, régimen y código postal.
10. El paso de artículos muestra el catálogo en tarjetas con su imagen desde que abre, carga más al
    llegar al final, y **tocar una tarjeta suma una unidad sin salir de la pantalla**, con el conteo
    y el total siempre a la vista al pie.
11. Las pantallas de uso de CFDI y forma de pago muestran su catálogo **sin escribir nada**, de 15 en
    15, con un buscador que filtra al instante y una palomita sobre la opción ya elegida al volver
    atrás.
12. El carrito permite cambiar cantidades y quitar renglones antes de guardar, y volver a agregar
    artículos sin perder lo capturado.
13. "Enviar por WhatsApp" abre el menú de compartir del aparato con el **PDF de la cotización**
    adjunto —nunca responde "Error del servidor"— y al compartir la cotización queda en "enviada".
    Si el usuario cancela el menú, sigue en borrador.
14. El botón de compartir espera a tener los archivos antes de habilitarse, y al tocarlo el menú del
    aparato abre sin descargas de por medio. Donde el menú no se pueda abrir, el documento se
    descarga y la pantalla lo dice; ninguna de las dos caras muestra un "error inesperado".
15. "Enviar por correo" manda el PDF al correo del cliente, que viene escrito y se puede corregir.
16. El botón de WhatsApp del escritorio comparte el PDF de la misma forma, y ninguna pantalla del
    sistema le pide un envío de WhatsApp a Twilio para una cotización.
17. Ningún importe capturado en el celular difiere de lo que calcularía el formulario de escritorio con
    los mismos datos.
18. "Escanear etiquetas" abre la cámara dentro de la aplicación y **captura sola**, sin botón de
    disparo.
19. Un QR que no es de una etiqueta del sistema no abre nada: avisa y el escáner sigue trabajando.
20. Al leer una etiqueta, el pedido se cobra y se entrega igual que hoy, con su "Deshacer" de diez
    segundos, y al terminar se puede escanear la siguiente sin volver al inicio.
21. La linterna, la pantalla despierta y la vibración funcionan donde el aparato las soporta, y su
    ausencia no impide escanear.
22. Con el detector disponible pero la cámara en vivo cerrada, el escáner ofrece tomar una foto de la
    etiqueta y la lee. Sin detector, dice que ese navegador no puede leer códigos y manda a abrir la
    etiqueta con la app de cámara, en vez de fallar sin explicación.
23. Existe un botón visible para instalar la aplicación, que desaparece una vez instalada.
24. La aplicación instalada abre siempre en los cuatro accesos.
25. Con una versión nueva desplegada, la aplicación abierta muestra un aviso con un botón de recargar.
26. Sin internet la aplicación abre y explica que no hay conexión, con un botón de reintentar, en vez
    de quedarse en blanco.
27. Pint y `php artisan test` en verde; ESLint y Prettier sin errores sobre el código nuevo; `vitest`
    y `npm run build` en verde.

## Supuestos asumidos (registro completo)

Aprobados uno por uno con el usuario antes de redactar. Los que el usuario cambió van marcados como
**(redefinido)**.

**La pantalla de inicio**

1. Los cuatro accesos viven en la pantalla de inicio, en la misma dirección `/dashboard` de hoy.
2. **(Redefinido)** Los demás módulos **no se alcanzan por ningún medio** desde el aparato del
   mostrador, porque se trabajan en la computadora. (La propuesta original dejaba el menú completo a
   la vista y trataba los cuatro accesos como un simple atajo.)
3. La pantalla de inicio no muestra cifras ni gráficas: solo los cuatro accesos.
4. Los cuatro accesos son fijos: no se reordenan, no se configuran y no cambian con el uso.
5. **(Redefinido)** El celular y la computadora **no muestran lo mismo**: los cuatro accesos son
   exclusivos de la aplicación instalada, y el inicio del navegador se queda tal como está hoy. (La
   propuesta original era una sola pantalla igual en los dos lados.)
6. Cada botón lleva un ícono grande con su texto y toda su superficie es tocable.

**A dónde lleva cada acceso**

7. **(Redefinido)** "Generar Factura" **no** abre el formulario de escritorio: abre una captura por
   pasos, que termina en una pantalla de revisión con nombre, RFC y total y ahí timbra. (La propuesta
   original abría `/facturas/crear` tal como está.)
8. **(Redefinido dos veces)** "Generar Cotización" **no** abre el formulario de escritorio: abre una
   captura por pasos —cliente, artículos, carrito y listo—, con el cliente elegido de una lista de
   tarjetas y dado de alta ahí mismo si no está. (La propuesta original abría `/cotizaciones/crear`
   tal como está; la primera redefinición dejaba la constancia como único camino para el cliente
   nuevo. Ver el bloque final de este registro.)
9. **(Redefinido)** "Venta al público" sí es el pedido de mostrador de 027, pero **no** abre su
   formulario de escritorio: abre una captura por pasos que termina cobrando y compartiendo el
   ticket. La etiqueta se sigue imprimiendo desde la computadora. (La propuesta original abría
   `/pedidos/crear` tal como está.)
10. "Escanear etiquetas" abre una pantalla nueva con la cámara dentro de la aplicación.

**El escáner**

11. Lee el QR de la etiqueta de 027 y lleva a la entrega de ese pedido, sin salir a la app de cámara.
12. La cámara captura sola; no hay que apretar nada.
13. Un QR ajeno se ignora con un aviso corto y el escáner sigue trabajando.
14. Al terminar una entrega se puede volver a escanear de inmediato.
15. El escaneo sigue haciendo lo que manda 027 —cobra el saldo y entrega—; esta spec solo cambia cómo
    se llega.

**Instalación y velocidad**

16. La aplicación instalada abre siempre en los cuatro accesos, no en la última pantalla vista.
17. "Rápida" es que arranca sin volver a descargar su interfaz. No trabaja sin internet: los datos
    siempre salen de la red.
18. Sin conexión, la aplicación abre y da un aviso claro, no una pantalla en blanco.
19. Sin sesión sigue mandando al login y regresando a donde iba, igual que hoy.

**Preguntas resueltas al ajustar los supuestos redefinidos**

20. **El interruptor es la aplicación instalada**, no el tamaño de la pantalla, ni una dirección
    aparte, ni un ajuste en Configuración.
21. **El inicio de la computadora se queda como está**: los cuatro accesos son exclusivos de la
    aplicación instalada.
22. **La captura de los tres documentos es por pasos**, una pantalla por paso, y no una sola pantalla
    larga, ni una captura mínima que se termina en la computadora, ni los formularios de hoy
    agrandados.
23. **La factura llega hasta el timbrado**, con un paso de revisión antes.
24. **(Ampliado después)** Al cliente que no está en el catálogo se le escanea la constancia fiscal.
    (Sigue siendo el camino recomendado, pero ya no es el único: ver el supuesto 48 del bloque
    final.)
25. **El aparato del mostrador es Android**, así que el detector de códigos del navegador está
    disponible. Sobre eso descansa que el respaldo por foto no tenga que cubrir la ausencia del
    detector.
26. **La venta al público termina cobrando y compartiendo el ticket**; la etiqueta se imprime desde la
    computadora.

**Adiciones técnicas aceptadas**

27. Respaldo por foto cuando la cámara en vivo no se puede abrir.
28. Linterna dentro del escáner.
29. La pantalla no se apaga mientras el escáner está abierto.
30. Vibración corta al leer un código válido.
31. Atajos "Nueva venta" y "Escanear" colgados del icono instalado.
32. Botón "Instalar aplicación" dentro del sistema.
33. Aviso de versión nueva, en vez de la actualización silenciosa de hoy.

*(Se retiró una adición propuesta —una página propia de "sin conexión"— por quedar cubierta por el
supuesto 18.)*

**Decisiones de detalle tomadas al redactar** (no se consultaron una por una; se documentan para que
puedan corregirse antes de implementar)

34. **El candado vive en el guard del router**, no en esconder el menú: "no se llega por ningún
    medio" tiene que cumplirse también para una dirección escrita a mano o guardada de antes.
35. **`pedidos-entregar` es la única pantalla de escritorio que el candado deja pasar**, porque es el
    destino del QR y sin ella el cuarto acceso no serviría de nada.
36. **Cerrar sesión baja al pie de la pantalla de los cuatro accesos.** Tiene que existir en algún
    lado, pero no compite con los cuatro botones.
37. **El alta por constancia también se ofrece en la factura**, no solo en la cotización: la factura
    exige los mismos datos fiscales y con más razón.
38. **La pantalla de resultado de la factura ofrece enviarla por correo**, y la de la cotización
    enviarla por WhatsApp o por correo. Sin eso el cliente se iría del mostrador sin recibir su
    documento. (El WhatsApp ya no reusa el endpoint de envío: ver el supuesto 57.)
39. **El paso de cobro de la venta preselecciona la caja** —la cuenta de efectivo activa más
    antigua— y deja cambiarla. La pantalla de entrega de 027 no preselecciona nada, y es una
    diferencia buscada: allá se cierra un pedido que pudo pagarse por transferencia días antes, acá
    se cobra la venta que está ocurriendo enfrente.
40. **`leerQr()` se muda a `lib/lectorQr.ts`** y la constancia fiscal pasa a importarlo desde ahí:
    dos pantallas leen el mismo tipo de código y una copia se quedaría atrás.
41. **El escáner solo acepta códigos del mismo origen que la aplicación.** Un QR pegado en cualquier
    caja podría llevar a donde sea, y el escáner de un punto de venta no es un navegador.
42. **`?mostrador=1` fuerza el modo solo en desarrollo.** En producción se ignora, para que nadie
    quede encerrado en los cuatro accesos sin saber cómo salir.
43. **El modo se evalúa una sola vez al arrancar.** Una ventana o se abrió desde el icono o no; leerlo
    en cada repintado solo abriría la puerta a que media aplicación se dibujara de cada modo.
44. **Instalar la aplicación en la computadora la pondría en modo mostrador.** Es la consecuencia
    aceptada de elegir la instalación como interruptor; abrirla desde el navegador devuelve el
    sistema completo.

### Redefinición del flujo de cotización

Aprobados uno por uno con el usuario, después de usar la primera versión del mostrador. Cambian la
captura de la cotización —y, con ella, las pantallas que los tres caminos comparten— y arreglan el
envío por WhatsApp, que nunca funcionó.

**El paso de cliente**

45. **Tocar una tarjeta elige al cliente y avanza**, sin botón de "Siguiente".
46. **"Subir RFC" abre la constancia por las dos vías**: elegir el archivo (PDF o foto) o apuntar la
    cámara al QR. Antes solo existía la cámara.
47. **La lista de clientes se ve desde que abre la pantalla**, sin escribir nada, y carga más al
    llegar al final; el buscador solo la filtra.
48. **(Amplía el supuesto 24)** **"Nuevo cliente" da de alta a mano** con RFC, razón social, régimen
    fiscal y código postal, más teléfono y correo. La versión anterior lo prohibía por el riesgo de
    timbrar mal; se acepta porque una cotización no se timbra y sin ese botón no se le puede cotizar
    a quien llega sin constancia.
49. **La tarjeta de cliente** trae razón social, RFC, teléfono y correo si los tiene, y el descuento
    permanente cuando es mayor a cero.

**El paso de artículos**

50. **Las tarjetas de artículo llevan su imagen** cuando la tienen, con nombre, modelo y precio.
51. **El catálogo se ve desde el arranque** y el buscador lo filtra, igual que la lista de clientes.
52. **Un toque suma una unidad y no sale de la pantalla**; la tarjeta muestra la cantidad que lleva.
53. **Una barra fija al pie** muestra el conteo y el total, con el botón que cierra la captura.

**El carrito y la pantalla final**

54. **El carrito es una pantalla propia**, con "−", "+", quitar e importe por renglón: buscar y
    revisar son dos trabajos distintos y no caben cómodos en la misma pantalla.
55. **Se puede volver a artículos sin perder nada**, y **nada se guarda hasta el botón final**.
56. **La pantalla final** muestra folio, cliente, renglones y total, con "Enviar por WhatsApp",
    "Enviar por correo", "Nueva cotización" e "Inicio".

**El envío por WhatsApp**

57. **(Redefine el supuesto 38)** **WhatsApp deja de salir del servidor.** El PDF se descarga con la
    sesión del usuario y se comparte con el menú del propio aparato, como el ticket de 027. La ruta
    por Twilio nunca mandó un mensaje: sin credenciales configuradas respondía "Error del servidor".
58. **`POST cotizaciones/{cotizacion}/marcar-enviada`**, endpoint nuevo y mínimo: el compartir ocurre
    en el teléfono y sin avisarle al servidor la cotización se quedaría en borrador para siempre.
59. **No hay campo de teléfono** en la pantalla final: el contacto se elige en el menú del aparato.
60. **El correo se queda como está**, saliendo del servidor con el PDF adjunto y el correo del
    cliente ya escrito y editable.
61. **El arreglo alcanza al botón de WhatsApp del escritorio**, que está roto por la misma razón.
    **Las órdenes de compra siguen con Twilio** y quedan fuera de esta spec, junto con
    `TwilioWhatsAppService`, que se conserva para ellas.

**Decisiones de detalle de esta redefinición**

62. **Las pantallas nuevas las estrenan los tres caminos**: la de cliente en factura y cotización, la
    de artículos y el carrito en los tres. Dos formas distintas de elegir un artículo en la misma
    aplicación es lo que se quiso evitar.
63. **`compartirImagen` pasa a llamarse `compartirArchivo`.** Siempre recibió un `Blob`; el nombre
    describía a su único usuario, no lo que hace.
64. **El scroll infinito se hace con `IntersectionObserver`** y el paginador que el servidor ya
    devuelve, sin agregar ninguna librería.
65. **Se retiran `cotizaciones.pdf-publico` y `Cotizacion::urlPdfPublico()`**, que existían solo para
    que Twilio descargara el archivo: una ruta fuera de la sesión que ya nadie usa es una puerta
    abierta sin razón.
66. **Los pasos de la cotización se llaman Cliente, Artículos, Carrito y Listo**, conservando el
    indicador de paso actual arriba.

### Los datos fiscales de la factura, pantalla por pantalla

Aprobados uno por uno con el usuario después de la redefinición anterior. Parten en tres el paso de
datos fiscales de la factura y cierran su pantalla de resultado como la de la cotización.

67. **La factura pasa de seis a ocho pasos**: Cliente, Artículos, Carrito, Uso de CFDI, Forma de
    pago, Método de pago, Revisar y Listo.
68. **Tocar la opción elige y avanza** en las tres pantallas nuevas, sin botón de "Siguiente", igual
    que el paso de cliente.
69. **Ninguna trae opción preseleccionada**, y al volver atrás la elegida se ve marcada. Con avance
    automático, un valor por omisión no ahorra el toque y sí puede colarse sin que nadie lo mire.
70. **Uso de CFDI y forma de pago se traen enteros en una sola petición** y se muestran de 15 en 15
    con scroll infinito; el buscador filtra en el navegador. Son catálogos cerrados de unas dos
    docenas de entradas: una petición por scroll y otra por letra serían peticiones por nada.
71. **(Cambio de backend)** `GET catalogos/usos-cfdi` acepta `q` opcional y, sin `q`, devuelve el
    catálogo completo. Hoy lo exige con dos caracteres mínimo, así que la pantalla abriría vacía.
72. **Método de pago son dos botones** con clave y nombre completo —PUE, "Pago en una sola
    exhibición"; PPD, "Pago en parcialidades o diferido"—, sin buscador ni tarjetas.
73. **La pantalla de revisión muestra también los tres datos fiscales**, en letra chica bajo el
    total: tres pantallas atrás ya no se recuerdan de memoria, y corregirlos cuesta menos que
    cancelar un CFDI.
74. **La pantalla final de la factura queda como la de la cotización**: "Enviar por WhatsApp",
    "Enviar por correo" en su diálogo, "Nueva factura" e "Inicio". El correo deja de ser un campo
    suelto en la pantalla.
75. **Por WhatsApp va solo el PDF**, y el XML sale por correo. Chrome en Android no admite `.xml`
    entre los tipos que su menú de compartir acepta, así que un CFDI completo por esa vía no es
    posible (ver el bloque final de este registro).
76. **Compartir no cambia el estado de la factura.** Una timbrada ya está timbrada y no hay un
    "enviada" que mover, así que no hace falta ningún `marcar-enviada` de su lado.
77. **Uso de CFDI y forma de pago son el mismo componente** con otra lista: escritas dos veces se
    verían distintas el día que una de las dos se corrigiera.

### El compartir, corregido al probarlo

Al usar el botón de WhatsApp de la factura apareció "Ocurrió un error inesperado". El servidor no
tenía nada que ver —el PDF y el XML se sirven bien— y el mensaje era el texto que el frontend usa
cuando el fallo no viene de una petición. Estas tres reglas son la corrección, y valen para los tres
documentos.

78. **Los archivos se preparan al entrar a la pantalla de resultado**, no al apretar el botón. El
    menú de compartir del aparato solo se abre mientras el gesto del usuario sigue vivo, y esperar a
    dos descargas —una de ellas hasta facturapi, por el XML— lo agota. Con una sola descarga corta a
    veces alcanzaba y a veces no, que es la peor conducta posible.
79. **Donde el menú no se puede abrir, el botón sigue mandando por WhatsApp**: descarga el archivo,
    copia el mensaje y abre WhatsApp con el texto escrito para elegir contacto y adjuntarlo. El botón
    promete un envío, no una descarga, y quedarse en la carpeta de descargas sin decir nada es la
    peor de las salidas.
80. **Los errores de las descargas se leen aunque la respuesta venga como `blob`.** Pedir un archivo
    envuelve también el JSON de error del servidor, así que sin desenvolverlo un 404 o un 502 se ven
    como un "error inesperado" que no dice nada de lo que pasó.

### El XML no cabe en el menú del aparato

Al compartir una factura desde la aplicación instalada, el menú del aparato **no abría**: los dos
archivos se descargaban y se abría WhatsApp, que es el camino de respaldo. El ticket y la cotización
sí abrían el menú. La diferencia era el XML, y no se arregla con código: es un límite del navegador.

Chrome comparte únicamente los tipos de archivo de una **lista fija** —imágenes, audio, video, texto
plano y `application/pdf`—. **`.xml` no está en esa lista**, con ningún tipo MIME, así que el sistema
operativo rechaza el envío completo. Lo que lo hacía difícil de ver es que `navigator.canShare()`
**no comprueba el tipo de archivo**: contesta que sí y es `navigator.share()` quien falla después,
con un "permiso denegado" que no dice cuál de los dos archivos estorbaba.

81. **El XML se retira del compartir.** No se intenta mandar y ni siquiera se descarga al abrir la
    pantalla de resultado: una petición que viaja hasta facturapi para un archivo que nunca va a
    salir por ahí es una espera regalada.
82. **El XML le llega al cliente por correo**, que es el otro botón de la misma pantalla y que ya
    adjuntaba los dos archivos. El contador sigue recibiendo el CFDI completo; lo que cambia es por
    dónde. Desde la computadora se sigue bajando con su botón del detalle de la factura.
83. **`compartirArchivos` desaparece y queda solo `compartirArchivo`.** Existía para mandar el PDF y
    el XML juntos, con un respaldo que reintentaba con el primer archivo si el grupo entero no se
    admitía. Ese respaldo nunca corrió —esperaba un `canShare` en falso que Chrome no da— y sin el
    XML ya no hay nada que agrupar. Se quita en vez de arreglarse: un camino que ningún documento
    recorre es un camino que se rompe sin que nadie se entere.
