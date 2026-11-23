# Spec: Agentes de WhatsApp (ventas y facturación) con la API de Claude

> **Estado: aprobada, por implementar.** El 2026-11-22 el usuario aprobó las 27 asunciones
> funcionales (con cambios en la 5, la 7 y la 18), las seis adiciones técnicas y, sin cambios, las
> "decisiones tomadas al redactar" (A a H, resumidas al final).

**Modifica:**

- [011-cotizaciones.md](011-cotizaciones.md): `cotizaciones` gana `notas`, `contacto_nombre`,
  `contacto_telefono` y `whatsapp_conversacion_id`. La creación sale de `CotizacionController::store`
  a un servicio (`CreadorCotizacion`) que usan el controlador y el agente. El PDF muestra
  "Atención: {contacto}" y las notas cuando existen.
- [019-pedidos-mostrador.md](019-pedidos-mostrador.md): `Autofacturador::facturar` acepta `correo`
  vacío (no envía correo) y devuelve la factura para mandarla por WhatsApp. Las reglas de cuándo se
  puede facturar (`motivoAutofacturaNoDisponible()`) **no cambian**.
- [012-facturacion.md](012-facturacion.md): `facturas` gana `whatsapp_conversacion_id`.
- [039-contador-ventas-del-dia.md](039-contador-ventas-del-dia.md): el layout gana un segundo
  contador, el de chats que requieren humano, con el mismo estilo de burbuja.
- `ClaveConfiguracion` (Configuración por cuenta) gana las claves de los agentes.
- Despliegue en Hostinger ([018-despliegue-hostinger.md](remotas/018-despliegue-hostinger.md)): una
  segunda tarea programada en hPanel para la cola (adición 4).

## Historia de usuario

Como usuario quiero implementar un número nuevo con la API de WhatsApp. Ya tengo mi token y un
número en producción con otro sistema. Este número nuevo no está vinculado a ninguna cuenta de
WhatsApp.

**Objetivo:**

- Tener un agente de ventas que conteste con la API de Claude.
- Tener un agente de facturación, también con la API de Claude.

```
Cliente (WhatsApp)                     Sistema (sellopronto)                         Meta / Claude / SAT
──────────────────                     ─────────────────────                         ───────────────────
"¿Cuánto cuesta un sello 4x2?"  ──▶  Puerta (webhook): revisa el sello de Meta,
                                     guarda el mensaje, responde "recibido"  ◀───── Meta
                                            │
                                            ▼ (cola, segundo plano)
                                     Agente de ventas ──"ventanillas"──▶ catálogo  ───▶ Claude
                                            │
"El 4x2 cuesta $280. ¿Qué texto     ◀───────┘ envía la respuesta              ─────▶ Meta
 llevaría?"
                                     Bandeja de WhatsApp: el usuario ve todo, contesta, pausa
```

## Objetivo / Alcance

Conectar al sistema un número nuevo de la **API de WhatsApp Cloud de Meta**, con dos agentes que
contestan solos usando la API de Claude (Messages API), y una bandeja para que el usuario vea y
atienda las conversaciones.

- **Conexión con Meta:** webhook público (verificación y recepción), envío de mensajes, descarga y
  subida de archivos, y lista de plantillas aprobadas.
- **Agente de ventas:** contesta con el catálogo, arma y manda cotizaciones en PDF, da datos
  bancarios y pasa a humano.
- **Agente de facturación:** factura ventas pagadas reutilizando el autofacturador de 019, lee la
  constancia fiscal (006) y reenvía facturas.
- **Bandeja `/whatsapp`:** carpetas, buscador, conversación, respuesta manual, plantillas, pausa del
  agente y cierre.
- **Configuración:** datos de conexión, encendido de cada agente, instrucciones editables, horario,
  tope de gasto y límites por cliente.
- Seguridad y confiabilidad: las seis adiciones técnicas.

**Fuera de alcance:**

- El número que ya está en producción con el otro sistema. Este sistema no lo lee ni lo usa.
- Crear o editar plantillas: se hacen en el administrador de WhatsApp de Meta. El sistema solo lee
  las aprobadas.
- Campañas o envíos masivos.
- Mensajes que inicie un agente (asunción 5).
- Notas de voz, transcripción y respuestas con audio.
- Llamadas.
- Precios o trato de distribuidor por WhatsApp (asunción 7).
- Que el agente cree ventas (`Pedido`) u órdenes de trabajo, valide pagos, cancele o corrija
  facturas.
- Varios números o varias cuentas con WhatsApp al mismo tiempo (ver regla 4).
- Etiquetas y notas internas en la bandeja.
- Actualización en tiempo real de la bandeja: se refresca al recargar o cada 20 segundos (regla 63).

## Antes de empezar: alta del número en Meta (lo hace el usuario)

Esto no es código. Son los pasos en el panel de Meta, y la spec los deja escritos para no
perderlos.

1. **Elegir la app de Meta.** El token que ya tienes pertenece a una "app" de Meta. Cada app tiene
   **una sola dirección de webhook**: si el número nuevo queda en la misma app que el otro sistema,
   Meta le mandaría sus mensajes **al otro sistema**. Hay dos caminos:
   - **Recomendado:** crear una app nueva de Meta (tipo "Negocio", producto WhatsApp) dentro del
     mismo portafolio comercial, solo para este sistema. Su webhook apunta aquí y el otro sistema
     no se entera.
   - Usar la misma app y que este número tenga su propia dirección de webhook (Meta permite
     sobrescribir el webhook por cuenta de WhatsApp o por número). Es más frágil: un cambio en la
     configuración del otro sistema puede pisarlo.
2. **Agregar el número** en el administrador de WhatsApp. Meta pide verificarlo por SMS o llamada.
   El número no debe tener la app de WhatsApp instalada; el usuario confirmó que no está vinculado
   a ninguna cuenta.
3. **Nombre para mostrar:** Meta lo revisa y lo aprueba antes de que el número pueda enviar a todo
   el mundo.
4. **Registrar el número para la API** con un PIN de 6 dígitos (verificación en dos pasos). Lo hace
   el sistema con el botón "Registrar número" de Configuración (regla 7).
5. **Token permanente:** un token de "usuario del sistema" del portafolio con permisos
   `whatsapp_business_messaging` y `whatsapp_business_management` sobre la cuenta de WhatsApp del
   número nuevo. Si el token actual es de otro usuario del sistema o vence, se genera uno nuevo.
6. **Datos que se copian a Configuración:** identificador del número (*Phone number ID*),
   identificador de la cuenta de WhatsApp (*WABA ID*), token, **clave secreta de la app** (*App
   secret*, adición 1) y un "token de verificación" que inventa el usuario para el webhook.
7. **Webhook en Meta:** dirección `https://sellopronto.sellopronto.com.mx/webhooks/whatsapp`, el
   mismo token de verificación del punto 6 y suscripción al campo `messages`.
8. **Método de pago** en el portafolio: las plantillas y algunas conversaciones se cobran.
9. **Clave de la API de Claude** (`ANTHROPIC_API_KEY`) en el `.env` del servidor.

## Reglas de negocio

### Número, cuenta y conexión (asunciones 1 a 4)

1. Hay **un solo número nuevo** y lo atienden los dos agentes. El cliente no elige agente: cada
   conversación tiene un **agente actual** (`ventas` o `facturacion`), y toda conversación nueva
   empieza con **ventas**.
2. Los agentes se pasan la conversación entre sí con una herramienta (`transferir_a_facturacion` /
   `transferir_a_ventas`, regla 47). El agente que recibe la conversación lee el mismo historial y
   contesta en ese mismo turno, así el cliente no repite nada.
3. El número del otro sistema no se toca (asunción 2). Este sistema solo conoce el *Phone number ID*
   del número nuevo, y el webhook **ignora** cualquier aviso de otro número (regla 13).
4. **(Decisión tomada al redactar)** En el sistema cada cuenta de usuario es su propio negocio
   (039, regla 11). Por eso el número queda **ligado a una cuenta**: la que lo configura. Clientes,
   catálogo, ventas y facturas que usan los agentes son los de esa cuenta. Si otra cuenta intenta
   configurar el mismo *Phone number ID*, el sistema lo rechaza ("Este número ya está conectado a
   otra cuenta"). Solo un **administrador** puede configurar el número.
5. Los datos de conexión se guardan en la tabla `whatsapp_numeros`. El token y la clave secreta van
   **cifrados** (cast `encrypted`) y nunca se vuelven a mostrar completos: Configuración enseña solo
   los últimos 4 caracteres y un campo para reemplazarlos.
6. Configuración tiene un botón **"Probar conexión"**: consulta el número en Meta y muestra su
   nombre para mostrar, su calidad y si está registrado. Si el token no sirve, muestra el error de
   Meta en español.
7. Botón **"Registrar número"** (paso 4 del alta): pide el PIN de 6 dígitos y llama al registro de
   Meta. El PIN no se guarda.

### Quién inicia la conversación (asunción 5)

8. **Los agentes nunca inician una conversación** ni mandan mensajes por su cuenta fuera de un turno
   de respuesta al cliente.
9. Solo el **usuario**, a mano, puede mandar una **plantilla**: desde la conversación en la bandeja
   o desde la ficha del cliente ("Escribir por WhatsApp"), si el cliente tiene teléfono.
10. Las plantillas son las **aprobadas en Meta** para la cuenta de WhatsApp. El sistema las lee de
    Meta con un botón "Actualizar plantillas" en Configuración y las guarda en
    `whatsapp_plantillas` (nombre, idioma, categoría, cuerpo y número de variables). Si una
    plantilla tiene variables (`{{1}}`, `{{2}}`…), el diálogo pide un valor para cada una y muestra
    cómo se verá el mensaje antes de enviarlo.
11. Mandar una plantilla a un teléfono sin conversación **crea** la conversación, la deja en manos
    de humano y con el agente pausado. Si el cliente contesta, el agente **no** responde hasta que
    el usuario lo reactive: quien inició la plática fue una persona.

### Recepción de mensajes (adiciones 1, 3 y 4)

12. **(Adición 1)** Cada aviso de Meta trae la cabecera `X-Hub-Signature-256`, una firma HMAC-SHA256
    del cuerpo **tal como llegó**, hecha con la clave secreta de la app. El sistema calcula la
    misma firma y compara con `hash_equals`. Si no coincide o falta, responde `403`, no guarda nada
    y lo anota en el log como intento rechazado (sin el cuerpo).
13. Si la firma es buena pero el aviso es de un *Phone number ID* que no está en `whatsapp_numeros`,
    se responde `200` y se ignora.
14. **(Adición 3)** Cada mensaje trae un identificador único de Meta (`wamid`). El sistema lo guarda
    con un **índice único**. Si Meta reenvía el mismo aviso, el `INSERT` choca y el mensaje no se
    vuelve a procesar.
15. **(Adición 4)** El webhook solo revisa la firma, guarda los mensajes y los cambios de estado, y
    pone en la cola un trabajo por conversación. Responde `200` a Meta **en menos de un segundo**,
    sin llamar a Claude ni al SAT.
16. Los avisos de **estado** de mensajes enviados (`sent`, `delivered`, `read`, `failed`) actualizan
    el mensaje correspondiente. Un `failed` guarda el motivo de Meta y se ve en la bandeja como
    "No entregado: {motivo}".
17. Tipos de mensaje que se aceptan del cliente:
    - **texto**;
    - **imagen** (JPG/PNG/WebP) y **documento PDF**: se descargan de Meta, se guardan en
      `storage/app/private/whatsapp/{conversacion}/` y se le pasan al agente;
    - **botones y respuestas a plantilla**: como texto;
    - **ubicación y contactos**: como texto descriptivo ("[Ubicación: …]").
18. **Notas de voz, audio, video y stickers** no se procesan (asunción 24). El sistema contesta una
    sola vez por conversación y día: "Por ahora no puedo escuchar audios ni ver videos. ¿Me lo
    escribes, por favor?". El mensaje queda en la bandeja y se puede escuchar desde ahí.
19. Archivos de más de 10 MB o de otro tipo no se descargan. El agente recibe "[El cliente mandó un
    archivo que no puedo abrir]".

### Turnos del agente (adición 4)

20. Un **turno** es una vuelta del agente: lee los mensajes del cliente que todavía no tienen
    respuesta, piensa, usa sus herramientas y contesta.
21. **Mensajes seguidos se juntan.** Los clientes suelen escribir en varios mensajes cortos ("hola",
    "quiero un sello", "de 4x2"). El trabajo de la conversación toma **todos** los mensajes
    pendientes en un solo turno. Así se contesta una vez y no tres.
22. Solo corre **un turno a la vez por conversación** (trabajo único por conversación más un candado
    de caché de 120 segundos). Si llegan mensajes mientras corre un turno, se atienden en el
    siguiente, que se encola al terminar el actual si quedan pendientes.
23. Al empezar un turno, el sistema marca los mensajes como **leídos** y muestra
    **"escribiendo…"** en el WhatsApp del cliente (indicador de escritura de la API de Meta, que
    dura hasta 25 segundos o hasta que llega la respuesta).
24. **(Decisión tomada al redactar)** Por la forma en que funciona la cola en Hostinger (adición 4),
    la respuesta puede tardar **hasta un minuto** en empezar. Es aceptable para un negocio: un
    humano tardaría más. Si después se quiere menos espera, se revisa en otra spec.
25. Si un paso falla por algo pasajero (Claude saturado o caído, Meta no responde, el SAT lento), el
    trabajo **se reintenta** hasta 3 veces con espera creciente (30 s, 2 min, 5 min). Si a la
    tercera tampoco, la conversación pasa a humano con el motivo "Falla técnica: {resumen}" y el
    cliente recibe: "Tuve un problema para responderte. Un asesor te contesta en breve."
26. Un turno nunca manda más de **5 mensajes** al cliente ni hace más de **10 llamadas a
    herramientas**. Si se pasa, se corta, se manda lo que haya y la conversación pasa a humano
    ("El agente no terminó de resolver").
27. El agente solo contesta si la conversación está **en manos del agente** y el agente actual está
    **encendido** en Configuración. Si no, los mensajes se guardan y esperan al humano.

### Pasar a humano (asunciones 11, 12, 17, 20 y 22)

28. Una conversación tiene un **estado**:
    - `agente`: contestan los agentes;
    - `humano`: el agente está pausado y la atiende el usuario;
    - `cerrada`: terminada.
29. Pasa a `humano` cuando:
    - el cliente escribe **"asesor"**, "humano", "persona" o "agente humano" como mensaje completo, o
      empieza con esa palabra (se detecta **sin** llamar a Claude, sin importar mayúsculas ni
      acentos);
    - el agente usa la herramienta `pasar_a_humano` (molestia, algo que no puede resolver, petición
      fuera de sus reglas, comprobante de pago, cancelación o corrección de factura, distribuidor
      que pide trato especial);
    - Claude rechaza contestar (`stop_reason: refusal`);
    - falla técnica (regla 25), límite por cliente (regla 70) o tope de gasto (regla 74);
    - el usuario escribe desde la bandeja (regla 58) o pulsa "Pausar agente".
30. Al pasar a humano se guarda el **motivo**, y el cliente recibe **una sola vez**:
    - en horario de atención: "Te comunico con un asesor, en breve te responde.";
    - fuera de horario: "Te comunico con un asesor. Nuestro horario es {horario}; te respondemos en
      cuanto abramos."

    Los dos textos son editables en Configuración. Si el que pasó a humano fue el usuario desde la
    bandeja, no se manda ningún aviso.
31. El **horario de atención** se captura en Configuración por día de la semana (abre y cierra, o
    cerrado) y se evalúa en la zona del negocio (`config('app.zona_negocio')`).
32. La conversación vuelve a `agente` solo cuando el usuario pulsa **"Reactivar agente"**. Al
    reactivarla, el agente no contesta nada de inmediato: espera al siguiente mensaje del cliente.
33. **Cerrar** una conversación la deja en `cerrada`. Si el cliente vuelve a escribir, se **reabre
    sola** en estado `agente`, con el agente de ventas y el mismo historial (regla 75).

### Agente de ventas (asunciones 6 a 12)

34. Contesta dudas de productos, medidas, tintas, tiempos de entrega y precios. Para productos y
    precios usa **solo** la herramienta `buscar_articulos`, nunca su propia memoria. Si algo no está
    en el catálogo, lo dice y ofrece pasar con un asesor.
35. El precio que da es el mismo que el sistema pone al agregar ese artículo a una cotización: la
    cadena de `CalculadoraPrecioArticulo` (009, 025, 036 y 037), con IVA y en pesos enteros.
36. **Distribuidores (asunción 7):** se tratan como cualquier cliente. Precio público y nunca se
    menciona el trato de distribuidor. El precio de distribuidor (028) **no** se usa por WhatsApp.
37. **Descuento permanente (023):** si el teléfono es de un cliente con descuento permanente, la
    cotización lo aplica igual que siempre (`congelarDescuentoCliente()`). El agente puede decir
    "con tu descuento de cliente queda en $X" usando el total que calculó el sistema.
38. Antes de cotizar, el agente pide los datos del sello que hagan falta: texto, tamaño o modelo,
    color de tinta y, si aplica, el logo en imagen. Los deja en las **notas** de la cotización
    (asunción 10). Un logo que mandó el cliente queda ligado como "imagen del chat" (se ve en la
    bandeja; no se copia a la cotización).
39. **Cotización (asunciones 8 y 9):** con `crear_cotizacion`, el agente arma una cotización
    **solo con artículos del catálogo** (por `articulo_id` y cantidad). **El precio nunca lo pone el
    agente**: lo calcula el sistema. Luego la manda en PDF por el chat con `enviar_cotizacion`, sin
    revisión humana. Al enviarse queda en estado `enviada`, igual que al compartirla desde el
    sistema (`EnvioCotizacionController::marcarEnviada`).
40. **¿A nombre de quién va la cotización?**
    - Si el teléfono coincide con **un** cliente de la cuenta (regla 76), va a su nombre.
    - Si coincide con varios, el agente pregunta el nombre o RFC para elegir.
    - **(Decisión tomada al redactar)** Si no coincide con ninguno, el agente pide el nombre de la
      persona y la cotización va al cliente **"PÚBLICO EN GENERAL"** de la cuenta (RFC
      `XAXX010101000`), que se crea solo si no existe (régimen 616 y código postal del emisor). En
      `contacto_nombre` y `contacto_telefono` queda la persona, y el PDF dice
      "Atención: {nombre}". Hay que hacerlo así porque un cliente exige RFC y razón social (005), y
      el RFC genérico solo puede existir una vez por cuenta. El usuario puede cambiar el cliente
      editando la cotización, como siempre.
41. **Cuando el cliente acepta (asunción 11):** el agente da los **datos bancarios** de la cuenta
    (los mismos de la cotización, 027) con `dar_datos_bancarios`, avisa que con el anticipo se
    arranca el trabajo y **pasa a humano** con el motivo "Cliente aceptó la cotización {folio}". No
    crea la venta ni la orden de trabajo: eso lo hace el usuario.
42. **Comprobantes (asunción 12):** si el cliente manda un comprobante de pago (imagen o PDF), el
    agente no lo valida. Contesta "Gracias, un asesor confirma tu pago en breve" y pasa a humano con
    el motivo "Comprobante de pago".
43. El agente no promete fechas de entrega exactas, no da descuentos extra, no regatea y no
    inventa promociones. Si le piden algo de eso, lo pasa a humano.

### Agente de facturación (asunciones 13 a 17)

44. **Qué venta se factura y cómo se demuestra que es del cliente.** El folio del ticket es un
    número consecutivo (`0042`) que cualquiera puede adivinar, y facturar la venta de otra persona a
    tu RFC le quita a ella la factura. Por eso se usa la misma protección del portal de 019:
    **(decisión tomada al redactar)** el agente solo factura una venta si se cumple una de estas
    dos:
    - el teléfono del chat es el **mismo** que el `cliente_telefono` de la venta (regla 76), y el
      cliente da el folio del ticket; o
    - el cliente manda el **enlace de autofactura** del ticket o una **foto del QR** (el sistema
      saca el token de 64 caracteres del texto o de la imagen).

    Si no se cumple ninguna, el agente pide el enlace o la foto del QR. Si el cliente no los tiene,
    pasa a humano.
45. Las reglas de **cuándo** se puede facturar son exactamente las del portal:
    `motivoAutofacturaNoDisponible()`. Venta pagada al 100 %, sin factura (ni de la venta ni de su
    cotización), no reembolsada y antes del cierre del mes de la venta (asunción 14). Si no se puede,
    el agente explica el motivo con el texto del sistema. Si el motivo es el vencimiento del mes,
    ofrece pasar a humano.
46. **Datos fiscales (asunción 15):**
    - Si el teléfono es de un cliente con RFC (no genérico), el agente le muestra RFC, razón social,
      régimen y código postal y pregunta si son correctos.
    - Si no, los pide. Puede leerlos de una **foto o PDF de la constancia de situación fiscal** con
      `leer_constancia` (el mismo `ConstanciaFiscalService` de 006, que lee el QR o el PDF).
    - Pide también el **uso de CFDI**. Si el cliente no sabe, propone `G03` (gastos en general) y
      espera su "sí".
    - El **correo** es opcional: si lo da, la factura también se le manda por correo.
47. **Confirmación antes de timbrar:** antes de `timbrar_factura`, el agente muestra un resumen
    (venta, total, RFC, razón social, régimen, código postal, uso de CFDI) y espera un "sí" explícito
    del cliente. Esta regla vive en las instrucciones del agente. Además, la herramienta exige el
    campo `cliente_confirmo: true`, que el agente solo debe mandar después de ese "sí".
48. **Timbrado:** `timbrar_factura` llama a `Autofacturador::facturar()`, el mismo de 019, que crea
    o actualiza el cliente por RFC, reutiliza la factura pendiente de un intento anterior y timbra
    bajo su candado. Según el resultado:
    - **timbrada:** manda el **PDF y el XML** por el chat (dos documentos) y, si hay correo, por
      correo;
    - **error de datos** (el SAT rechazó algo): el agente le explica al cliente el motivo en español
      y le pide corregir;
    - **error del servicio:** se reintenta como en la regla 25;
    - **en curso:** "Tu factura se está procesando, te la mando en un momento". El turno siguiente
      revisa de nuevo.
49. **(Adición 3, segundo candado)** No se puede timbrar dos veces la misma venta: el
    `Autofacturador` bloquea la venta (`lockForUpdate`) y revisa
    `motivoAutofacturaNoDisponible()` dentro de la transacción. Si el cliente pide "factúrame" dos
    veces seguidas, la segunda responde "Esta venta ya se facturó" y el agente reenvía la existente.
50. **Reenvío (asunción 16):** `reenviar_factura` manda el PDF y el XML de una factura **timbrada**
    solo si es del dueño del chat: el teléfono del chat coincide con el del cliente de la factura o
    con el `cliente_telefono` de su venta. Si no, responde "No encontré esa factura a tu nombre" (el
    mismo texto exista o no, para no confirmar folios ajenos).
51. **No cancela ni corrige facturas (asunción 17):** esas solicitudes pasan a humano con el motivo
    "Cancelación o corrección de factura {folio}".

### Herramientas ("ventanillas") de cada agente (adición 5)

52. Cada agente trabaja **solo** con sus herramientas. Las reglas de seguridad viven **dentro** de
    cada herramienta, en el servidor, y no dependen de que el agente obedezca. Toda herramienta
    recibe la conversación (y con ella la cuenta y el teléfono), **nunca** un `user_id` ni un
    teléfono que mande el agente.

    | Agente | Herramienta | Qué hace | Candados dentro |
    |---|---|---|---|
    | Ventas | `buscar_articulos(texto)` | Hasta 10 artículos activos del catálogo de la cuenta con modelo, descripción, medidas y precio público con IVA | Solo artículos de la cuenta; nunca costos, proveedor ni utilidad |
    | Ventas | `crear_cotizacion(lineas[{articulo_id, cantidad}], notas, contacto_nombre)` | Crea la cotización con `CreadorCotizacion` | Artículos de la cuenta; cantidad 1 a 500; máximo 20 líneas; el precio lo pone el sistema; cliente según la regla 40 |
    | Ventas | `enviar_cotizacion(cotizacion_id)` | Manda el PDF y la marca enviada | Solo cotizaciones creadas en **esta** conversación |
    | Ventas | `dar_datos_bancarios()` | Texto con los datos bancarios de la cuenta (027) | Solo los de la cuenta |
    | Facturación | `buscar_venta(folio?, token?)` | Folio, fecha, total, estado y si se puede facturar (motivo) | Regla 44: teléfono igual o token válido |
    | Facturación | `leer_constancia(archivo_id)` | RFC, razón social, régimen y CP de la constancia | Solo archivos de **esta** conversación |
    | Facturación | `datos_fiscales_guardados()` | Los datos del cliente del teléfono, si tiene RFC | Solo el cliente de este teléfono |
    | Facturación | `timbrar_factura(venta_id, rfc, razon_social, regimen_fiscal, codigo_postal, uso_cfdi, correo?, cliente_confirmo)` | Llama al `Autofacturador` | Venta validada por `buscar_venta` en esta conversación; `cliente_confirmo = true`; las validaciones de `AutofacturaRequest` |
    | Facturación | `reenviar_factura(folio)` | Manda PDF y XML | Regla 50 |
    | Los dos | `pasar_a_humano(motivo)` | Regla 29 | — |
    | Ventas | `transferir_a_facturacion()` | Regla 2 | — |
    | Facturación | `transferir_a_ventas()` | Regla 2 | — |

53. No existen herramientas para cancelar, borrar, cambiar precios, ver otros clientes ni consultar
    la base de datos libremente. Lo que no está en la tabla, el agente no lo puede hacer.
54. Cada uso de herramienta se guarda (nombre, entrada, resumen del resultado, error) en
    `whatsapp_mensajes` con tipo `herramienta`. En la bandeja se ve plegado, en gris, para auditar
    lo que hizo el agente: "🔧 consultó artículos: 'sello 4x2'", "🔧 timbró factura F-210".
55. Lo que el cliente escribe se le pasa al agente como **mensaje del cliente**, nunca como
    instrucción del sistema. Las instrucciones del agente le dicen que ignore las peticiones de
    cambiar sus reglas.

### Bandeja `/whatsapp` (asunción 18)

56. Una pantalla nueva **"WhatsApp"** en el menú principal, al estilo de las bandejas de
    cotizaciones y facturas (014 y la de facturas): carpetas a la izquierda, lista de chats con
    buscador y la conversación a la derecha.
    - **Carpetas:** Requieren humano (con contador), Ventas, Facturación, Cerradas y Todas.
    - **Lista:** nombre del cliente (o el nombre de perfil de WhatsApp, o el teléfono), último
      mensaje, hora, punto rojo si requiere humano y reloj si la ventana está por vencer (regla 66).
    - **Buscador:** por nombre, teléfono o texto de los mensajes.
57. **Conversación:** encabezado con nombre, teléfono, cliente ligado (enlace a su ficha), agente
    actual, estado y reloj de la ventana. Debajo, los mensajes en orden:
    - del cliente, a la izquierda;
    - del agente, a la derecha, con "🤖 Ventas" o "🤖 Facturación";
    - del usuario, a la derecha, con su nombre;
    - del sistema (pase a humano, reapertura), centrados;
    - herramientas, plegadas (regla 54).

    Las imágenes se ven en miniatura, los PDF y audios se abren, y cada mensaje enviado muestra su
    estado (enviado, entregado, leído, no entregado). Los documentos que generó el agente
    (cotizaciones y facturas) tienen enlace a su pantalla.
58. **Contestar:** caja de texto y "Enviar". Contestar **pasa la conversación a humano** (el
    usuario tomó el control). Se puede adjuntar una imagen o un PDF (máx. 10 MB).
59. **Botones:** "Pausar agente" / "Reactivar agente", "Plantilla" (regla 10), "Cerrar" /
    "Reabrir", y "Quitar límite" si la conversación está limitada (regla 71).
60. **Ficha del cliente:** "Escribir por WhatsApp" abre su conversación o, si no tiene, el diálogo de
    plantilla (regla 9).
61. Ven la bandeja todos los usuarios con sesión de la cuenta dueña del número. La configuración del
    número solo la toca un administrador (regla 4).
62. **Aviso en la barra (asunción 21):** un icono de chat con burbuja junto al de ventas del día
    (039). La burbuja cuenta las conversaciones en `humano` con mensajes del cliente sin respuesta.
    Es un enlace a la carpeta "Requieren humano". En 0, burbuja neutra; con 1 o más, color de
    acento.
63. **(Decisión tomada al redactar)** La bandeja abierta se refresca sola cada 20 segundos (lista y
    conversación) con una petición que regresa solo lo nuevo. No hay WebSockets: Hostinger no los
    sostiene y para esta cantidad de mensajes no hace falta.

### Ventana de 24 horas (adición 2)

64. Cada conversación guarda la hora del **último mensaje del cliente**. La ventana está **abierta**
    hasta 24 horas después de esa hora.
65. Con la ventana cerrada:
    - la caja de texto y el adjunto se **desactivan**, con el aviso "Pasaron más de 24 horas desde
      el último mensaje del cliente. Solo puedes mandar una plantilla.", y queda activo el botón
      "Plantilla";
    - el servidor valida lo mismo: un envío libre con la ventana cerrada se rechaza aunque alguien
      salte la pantalla.
66. La bandeja muestra el reloj "⏱ quedan 3 h" en la conversación. En la lista, los chats en
    `humano` con **menos de 2 horas** de ventana se marcan en rojo y suben al principio de "Requieren
    humano".
67. Si un turno del agente termina con la ventana cerrada (por ejemplo, un timbrado que tardó), no
    se manda el mensaje: la conversación pasa a humano con el motivo "Se cerró la ventana de 24 h
    antes de responder".

### Límite por cliente (adición 6)

68. Cada teléfono puede recibir hasta **20 turnos del agente por hora** y **100 por día** (valores
    por defecto, editables en Configuración). Cuenta el **turno**, no el mensaje: cinco mensajes
    seguidos que se juntan (regla 21) son un turno.
69. Las palabras "asesor"/"humano" (regla 29) y los mensajes de audio (regla 18) no cuentan, porque
    no llaman a Claude.
70. Al rebasar el límite, el agente deja de contestarle, el cliente recibe **una sola vez** "Un
    asesor continuará la conversación contigo en breve." y la conversación pasa a humano con el
    motivo "Límite de mensajes". Los mensajes se siguen guardando.
71. "Quitar límite" en la bandeja deja esa conversación **sin límite** hasta que se cierre.

### Tope de gasto mensual (asunción 27)

72. Cada llamada a Claude guarda sus tokens (de entrada, de salida, leídos de caché y escritos en
    caché) y su **costo estimado** en dólares, con los precios del modelo que están en
    `config/whatsapp.php`.
73. Configuración muestra el gasto estimado del mes en curso y el tope (por defecto **US$ 50**,
    editable). Al llegar al **80 %**, sale un aviso en la bandeja.
74. Al llegar al **100 %**, los agentes se pausan para todas las conversaciones: cada conversación
    que reciba un mensaje pasa a humano con el motivo "Tope de gasto mensual", y el cliente recibe
    el aviso de la regla 30. El mes siguiente se reactivan solos. El usuario puede subir el tope
    para reactivarlos antes.

### Historial y clientes (asunciones 15 y 25)

75. Una conversación es **una por teléfono y número**. Guarda todo su historial. Al agente se le
    pasan los **últimos 40 mensajes** de texto (sin los de herramienta de turnos anteriores) más un
    **resumen de lo anterior**, que el sistema genera con Claude cuando el historial pasa de 40 y se
    actualiza cada 20 mensajes nuevos. Así el cliente no repite datos y el costo no crece sin fin.
76. **Cómo se compara un teléfono:** se quitan los caracteres que no son dígitos y se comparan los
    **últimos 10 dígitos**. WhatsApp manda los números de México como `521XXXXXXXXXX` o
    `52XXXXXXXXXX`, y el sistema los guarda de varias formas (`+524491234567`, `449 123 4567`).
    Para no recorrer toda la tabla, `clientes` y `pedidos` ganan una columna `telefono_10` (los
    últimos 10 dígitos), indexada y llenada al guardar.
77. La conversación se **liga a un cliente** cuando el teléfono coincide con uno solo, o cuando el
    agente de facturación crea o actualiza uno al timbrar. Desde la bandeja se puede ligar o cambiar
    a mano.

### Comportamiento general (asunciones 22 a 26)

78. Los agentes contestan **las 24 horas** (asunción 22). El horario solo cambia el aviso al pasar a
    humano (regla 30).
79. Hablan en **español de México**, en tono amable, con mensajes **cortos** (idealmente menos de
    600 caracteres), sin tablas ni formato complejo. Solo usan `*negritas*` de WhatsApp para precios
    y folios.
80. Leen texto, imágenes y PDF (asunción 24). Las imágenes y los PDF se le pasan a Claude como
    contenido (imagen o documento) solo en el turno en que llegaron. En turnos posteriores quedan
    como "[imagen: logo.jpg, id 31]" para que el agente pueda referirse a ellos sin volver a gastar.
81. **Configuración de los agentes (asunción 26)**, por cuenta:
    - encender o apagar **cada agente** por separado (si el de facturación está apagado, el de
      ventas no transfiere: dice que un asesor ayuda con la factura y pasa a humano);
    - **instrucciones adicionales** de cada agente (área de texto, máx. 4 000 caracteres): políticas,
      tiempos de entrega, tono, preguntas frecuentes. Se agregan **después** de las reglas fijas del
      sistema y no las pueden quitar;
    - horario de atención, textos de pase a humano, tope de gasto y límites por cliente.

## Adiciones técnicas

### Adición 1: firma de Meta

- Reglas 12 y 13. El webhook lee el cuerpo crudo con `$request->getContent()`. La firma se valida
  **antes** de decodificar el JSON.
- Verificación inicial de Meta (`GET`): si `hub.mode = subscribe` y `hub.verify_token` coincide con
  el de algún número configurado, responde `hub.challenge` en texto plano. Si no, `403`.

### Adición 2: ventana de 24 horas

- Reglas 64 a 67. `WhatsappConversacion::ventanaAbierta(): bool` y `ventanaCierraEl(): ?Carbon`
  son la única definición de la regla. Las usan la pantalla, el servidor y el agente.

### Adición 3: no procesar ni timbrar dos veces

- Índice único en `whatsapp_mensajes.wamid`. El webhook usa `insertOrIgnore` y solo encola si
  insertó.
- Trabajo único por conversación (`ShouldBeUnique` con `uniqueId = conversacion_id`) y candado de
  turno (regla 22).
- El candado de facturación es el del `Autofacturador` (regla 49), sin código nuevo.

### Adición 4: cola en segundo plano

- `QUEUE_CONNECTION=database` (ya lo es en `.env.example`). Tablas `jobs` y `failed_jobs`.
- En Hostinger, el scheduler no sirve de lanzador fiable (sin `proc_open`). Se agrega en hPanel una
  **segunda tarea programada** cada minuto:

  ```
  php artisan queue:work --stop-when-empty --max-time=55 --tries=3 --timeout=50
  ```

  Arranca, atiende lo que haya en la cola durante 55 segundos y termina. Así nunca hay dos
  trabajadores encimados por mucho tiempo, y si uno muere, el siguiente minuto arranca otro. En
  Laragon, el `README.md` explica cómo correr `php artisan queue:work` en desarrollo.
- `deploy/deploy.sh` corre `php artisan queue:restart` al final, para que el trabajador tome el
  código nuevo.
- Reintentos: regla 25 (`$tries = 3`, `backoff = [30, 120, 300]`). En `failed()` la conversación
  pasa a humano.

### Adición 5: herramientas controladas

- Reglas 52 a 55. Cada herramienta es una clase que implementa
  `App\Services\Whatsapp\Herramientas\Herramienta` (`nombre()`, `descripcion()`, `esquema()`,
  `ejecutar(WhatsappConversacion, array): ResultadoHerramienta`).
- Los esquemas usan `strict: true` y `additionalProperties: false`, para que la entrada siempre
  tenga la forma esperada. Aun así, cada herramienta **vuelve a validar** su entrada con el
  validador de Laravel: si falla, regresa `is_error: true` con el motivo y el agente puede
  corregirse.

### Adición 6: límite por cliente

- Reglas 68 a 71, con `RateLimiter` de Laravel con llaves por conversación
  (`wa-hora:{id}`, `wa-dia:{id}`), aumentadas al empezar cada turno.

## Backend (Laravel)

### Dependencias

- `composer require anthropic-ai/sdk`: SDK oficial de Anthropic para PHP. Se usa la Messages API
  con un ciclo de herramientas propio (regla 52), porque las herramientas tienen que correr en este
  servidor, junto a la base de datos.
- Meta se llama con el cliente HTTP de Laravel (`Http::`). No hay SDK oficial de Meta para PHP que
  valga la pena.

### Configuración `config/whatsapp.php`

```php
return [
    'graph_url' => 'https://graph.facebook.com',
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v24.0'), // la vigente al implementar
    'claude' => [
        'modelo' => env('WHATSAPP_CLAUDE_MODELO', 'claude-opus-5-5'),
        'effort' => env('WHATSAPP_CLAUDE_EFFORT', 'low'),
        'max_tokens' => 4000,
        // US$ por millón de tokens, para el costo estimado (regla 72)
        'precios' => [
            'claude-opus-5-5' => ['entrada' => 4.00, 'salida' => 20.00, 'cache_lectura' => 0.20, 'cache_escritura' => 5.00],
            'claude-sonnet-5-5' => ['entrada' => 2.00, 'salida' => 10.00, 'cache_lectura' => 0.10, 'cache_escritura' => 2.50],
        ],
    ],
    'mensajes_historial' => 40,
    'max_mb_archivo' => 10,
];
```

- **(Decisión tomada al redactar)** Modelo **Claude Opus 5.5** con esfuerzo `low`: es el modelo
  vigente más capaz de su línea, y para una charla de mostrador el esfuerzo bajo da respuestas
  rápidas y baratas. Cambiar a `claude-sonnet-5-5` (la mitad del precio) es una línea del `.env`.
  Opus 5.5 **no** permite apagar el razonamiento: se controla solo con `effort`.
- Cada llamada usa **caché de instrucciones** (`cacheControl` en el último bloque de `system`):
  las reglas fijas, las instrucciones de la cuenta y las herramientas son iguales en cada turno y se
  cobran a una fracción del precio. La fecha y hora actual van en el mensaje, **no** en `system`,
  para no romper la caché.
- Se activa el **respaldo del servidor** ante rechazos (beta `server-side-fallback-2026-07-01` con
  `fallbacks: "default"`). Si a pesar de eso la respuesta llega con `stop_reason: refusal`, la
  conversación pasa a humano (regla 29). La forma exacta en el SDK de PHP se confirma contra los
  ejemplos del SDK al implementar.
- `ANTHROPIC_API_KEY` va en el `.env` de la instalación, no en la base de datos: la cuenta de
  Claude es del dueño del servidor.

### Migraciones

**`create_whatsapp_numeros_table`**

| Columna | Tipo |
|---|---|
| `id` | id |
| `user_id` | FK `users`, **único** (regla 4) |
| `phone_number_id` | `string(32)`, **único** |
| `waba_id` | `string(32)` |
| `numero_visible` | `string(20)` (p. ej. `+52 449 123 4567`, lo llena "Probar conexión") |
| `nombre_visible` | `string`, nullable |
| `token` | `text` (cast `encrypted`) |
| `app_secret` | `text` (cast `encrypted`) |
| `verify_token` | `string(64)` |
| timestamps | |

**`create_whatsapp_conversaciones_table`**

| Columna | Tipo |
|---|---|
| `id` | id |
| `whatsapp_numero_id` | FK |
| `user_id` | FK (cuenta dueña, para los `scope` y políticas) |
| `wa_id` | `string(20)`: teléfono del cliente tal como lo manda Meta |
| `telefono_10` | `string(10)`, índice |
| `nombre_perfil` | `string`, nullable (nombre de perfil de WhatsApp) |
| `cliente_id` | FK `clientes`, nullable |
| `estado` | `string(10)`: `agente` / `humano` / `cerrada` (enum `EstadoConversacion`) |
| `agente_actual` | `string(12)`: `ventas` / `facturacion` (enum `AgenteWhatsapp`) |
| `motivo_humano` | `string`, nullable |
| `aviso_humano_enviado` | `boolean` (regla 30) |
| `sin_limite` | `boolean` (regla 71) |
| `ultimo_mensaje_cliente_en` | `timestamp`, nullable (regla 64) |
| `ultimo_mensaje_en` | `timestamp`, índice (orden de la lista) |
| `pendientes_humano` | `unsignedInteger` (mensajes del cliente sin respuesta en `humano`, regla 62) |
| `resumen` | `text`, nullable (regla 75) |
| `resumen_hasta_mensaje_id` | `unsignedBigInteger`, nullable |
| timestamps | |

- Único (`whatsapp_numero_id`, `wa_id`). Índices (`user_id`, `estado`, `ultimo_mensaje_en`).

**`create_whatsapp_mensajes_table`**

| Columna | Tipo |
|---|---|
| `id` | id |
| `whatsapp_conversacion_id` | FK, índice |
| `wamid` | `string(128)`, nullable, **único** (los de herramienta y sistema no tienen) |
| `direccion` | `string(8)`: `entrante` / `saliente` / `interno` |
| `autor` | `string(12)`: `cliente` / `ventas` / `facturacion` / `usuario` / `sistema` |
| `user_id` | FK, nullable (quién escribió, si fue un usuario) |
| `tipo` | `string(16)`: `texto` / `imagen` / `documento` / `audio` / `video` / `plantilla` / `herramienta` / `otro` |
| `texto` | `text`, nullable |
| `archivo_ruta` | `string`, nullable |
| `archivo_nombre` / `archivo_mime` | `string`, nullable |
| `datos` | `json`, nullable (herramienta: nombre, entrada, resultado; plantilla: nombre y variables) |
| `estado_envio` | `string(10)`, nullable: `enviado` / `entregado` / `leido` / `fallido` |
| `error_envio` | `string`, nullable |
| `respondido` | `boolean` (entrantes: si ya entraron a un turno) |
| `enviado_en` | `timestamp` (hora de Meta o de envío) |
| timestamps | |

**`create_whatsapp_plantillas_table`**: `whatsapp_numero_id`, `nombre`, `idioma`, `categoria`,
`cuerpo` (text), `variables` (unsignedTinyInteger), `estado`, timestamps. Único (`whatsapp_numero_id`,
`nombre`, `idioma`).

**`create_whatsapp_uso_claude_table`**: `user_id`, `whatsapp_conversacion_id` (nullable),
`modelo`, `tokens_entrada`, `tokens_salida`, `tokens_cache_lectura`, `tokens_cache_escritura`,
`costo_usd` (`decimal(10,6)`), `created_at`. Índice (`user_id`, `created_at`).

**`add_whatsapp_to_cotizaciones_table`**: `notas` (text, nullable), `contacto_nombre` (string,
nullable), `contacto_telefono` (string(20), nullable), `whatsapp_conversacion_id` (FK nullable,
`nullOnDelete`).

**`add_whatsapp_conversacion_id_to_facturas_table`**: FK nullable, `nullOnDelete`.

**`add_telefono_10_to_clientes_and_pedidos`**: `telefono_10` (`string(10)`, nullable, índice
(`user_id`, `telefono_10`)) en las dos tablas, llenado en la misma migración para los registros
existentes. Los modelos lo llenan solos al guardar (`saving`).

### Configuración por cuenta (`ClaveConfiguracion`)

Claves nuevas, con sus reglas y textos por defecto:

- `whatsapp_ventas_activo`, `whatsapp_facturacion_activo` (booleanos, por defecto **apagados**: se
  encienden después de probar);
- `whatsapp_instrucciones_ventas`, `whatsapp_instrucciones_facturacion` (máx. 4 000);
- `whatsapp_horario` (JSON por día);
- `whatsapp_aviso_humano`, `whatsapp_aviso_humano_fuera_horario`;
- `whatsapp_tope_mensual_usd` (por defecto 50);
- `whatsapp_limite_hora` (20), `whatsapp_limite_dia` (100).

Se presentan en una sección nueva **"WhatsApp"** de Configuración, junto con los datos de conexión
(regla 5), "Probar conexión", "Registrar número" y "Actualizar plantillas".

### Servicios (`App\Services\Whatsapp\`)

- **`ClienteMeta`**: las únicas llamadas a la Graph API de Meta.
  - `enviarTexto(WhatsappNumero, string $waId, string $texto): string` regresa el `wamid`.
  - `enviarDocumento(..., string $ruta, string $nombre, ?string $pie)`: sube el archivo a
    `/{phone_number_id}/media` y lo manda por `media id`, sin URL pública.
  - `enviarImagen`, `enviarPlantilla(..., string $nombre, string $idioma, array $variables)`.
  - `marcarLeidoYEscribiendo(WhatsappNumero, string $wamid)`.
  - `descargarMedia(WhatsappNumero, string $mediaId): ArchivoDescargado`.
  - `plantillas(WhatsappNumero): array`, `consultarNumero()` y `registrar(string $pin)`.
  - Errores de Meta → `MetaException` con el mensaje en español cuando se pueda (`error_user_msg`).
- **`ReceptorWebhook`**: valida la firma (adición 1) y convierte el aviso en mensajes y estados
  guardados. Encuentra o crea la conversación, la reabre si estaba cerrada (regla 33), actualiza la
  ventana y encola `ProcesarConversacion`.
- **`Telefono`**: `diezDigitos(string): ?string` (regla 76), la única definición.
- **`OrquestadorTurno`**: corre un turno (reglas 20 a 27).
  1. Revisa estado, agente encendido, límite (adición 6) y tope (regla 74).
  2. Revisa palabras de humano (regla 29) y audios (regla 18) sin Claude.
  3. Marca leído y "escribiendo…".
  4. Arma la petición: `system` = reglas fijas del agente (`resources/prompts/whatsapp/ventas.md` y
     `facturacion.md`) + datos del negocio (`Emisor`) + instrucciones de la cuenta, con caché;
     `messages` = resumen + últimos 40 mensajes + pendientes (imágenes y PDF del turno como
     contenido, regla 80) + fecha y hora actual y estado de la ventana.
  5. Ciclo de herramientas: mientras `stop_reason = tool_use`, ejecuta las herramientas del agente
     actual, guarda cada una (regla 54) y devuelve **todos** los resultados en un solo mensaje. Si
     hay `transferir_a_*`, cambia de agente y sigue el ciclo con el otro `system` y sus
     herramientas.
  6. Manda el texto final al cliente, dividido en mensajes de menos de 1 000 caracteres por
     párrafo (regla 26).
  7. Guarda el uso y el costo (regla 72). Marca los entrantes como `respondido`.
- **`RegistroUso`**: costo por llamada, gasto del mes y si se pasó el tope (reglas 72 a 74).
- **`Resumidor`**: genera el resumen de la regla 75 con una llamada aparte, con esfuerzo `low`.
- **`Herramientas\*`**: una clase por herramienta de la tabla (regla 52).

### Extracciones en código existente

- **`App\Services\Cotizaciones\CreadorCotizacion::crear(User, Cliente, array $lineas, array $extra): Cotizacion`**
  con el cuerpo de la transacción de `CotizacionController::store` (folio, descuento congelado,
  datos bancarios congelados, totales y líneas). El controlador pasa a usarlo. El agente le pasa
  líneas con el precio que calcula el sistema para cada artículo.
- **`Autofacturador::facturar`**: `correo` pasa a opcional. Si es `null`, no se envía correo.
  `ResultadoAutofactura` expone la `Factura` timbrada para mandar el PDF
  (`GeneradorPdfFactura`) y el XML.
- `GeneradorPdfCotizacion` / `cotizaciones/pdf.blade.php`: "Atención: {contacto_nombre}" bajo el
  cliente y un bloque "Detalles" con `notas`, si existen.

### Trabajo `App\Jobs\ProcesarConversacion`

- `ShouldQueue`, `ShouldBeUnique` (`uniqueId` = conversación, `uniqueFor` = 120), `tries = 3`,
  `backoff = [30, 120, 300]` y `timeout = 50`.
- `handle()` toma el candado de turno y llama a `OrquestadorTurno`. Si al terminar quedan entrantes
  sin `respondido`, se encola de nuevo.
- `failed()` pasa a humano (regla 25).
- Errores de Claude: 429, 5xx y de conexión se relanzan (reintento); 400 pasa a humano de inmediato
  y queda en el log.

### Controladores y rutas

| Método | Ruta | Qué hace | Nombre |
|---|---|---|---|
| GET | `/webhooks/whatsapp` | **pública**: verificación de Meta | `whatsapp.webhook.verificar` |
| POST | `/webhooks/whatsapp` | **pública**: recepción (firma obligatoria) | `whatsapp.webhook.recibir` |
| GET | `/whatsapp` | bandeja (`?carpeta=`, `?q=`) | `whatsapp.index` |
| GET | `/whatsapp/{conversacion}` | conversación | `whatsapp.show` |
| GET | `/whatsapp/{conversacion}/nuevos?desde={id}` | mensajes nuevos (JSON, regla 63) | `whatsapp.nuevos` |
| POST | `/whatsapp/{conversacion}/mensajes` | respuesta del usuario (texto o archivo) | `whatsapp.mensajes.store` |
| POST | `/whatsapp/{conversacion}/plantilla` | enviar plantilla | `whatsapp.plantilla` |
| POST | `/whatsapp/plantilla` | plantilla a un cliente sin conversación (regla 11) | `whatsapp.plantilla.nueva` |
| PATCH | `/whatsapp/{conversacion}/estado` | pausar, reactivar, cerrar, reabrir | `whatsapp.estado` |
| PATCH | `/whatsapp/{conversacion}/limite` | quitar límite | `whatsapp.limite` |
| PATCH | `/whatsapp/{conversacion}/cliente` | ligar cliente (regla 77) | `whatsapp.cliente` |
| GET | `/whatsapp/archivos/{mensaje}` | ver o descargar un archivo del chat | `whatsapp.archivo` |
| PUT | `/configuracion/whatsapp` | guardar conexión y ajustes | `configuracion.whatsapp` |
| POST | `/configuracion/whatsapp/probar` | probar conexión | `configuracion.whatsapp.probar` |
| POST | `/configuracion/whatsapp/registrar` | registrar con PIN | `configuracion.whatsapp.registrar` |
| POST | `/configuracion/whatsapp/plantillas` | actualizar plantillas | `configuracion.whatsapp.plantillas` |

- Las rutas del webhook van **fuera** del grupo `auth` y **sin CSRF** (excluidas en
  `bootstrap/app.php`), con un limitador propio (`throttle:whatsapp-webhook`, 300 por minuto por
  IP).
- `WhatsappConversacionPolicy`: la cuenta del usuario es la dueña de la conversación. Configuración
  de WhatsApp: solo `Rol::Administrador`.
- Los archivos del chat se sirven por controlador con política, nunca desde `public`.

## Frontend

- Vistas `whatsapp/index.blade.php`, `_carpetas`, `_lista`, `_conversacion`, `_mensaje` y
  `_dialogo-plantilla`, con la misma estructura y clases que las bandejas de cotizaciones y facturas.
  En el celular, lista y conversación se ven una a la vez, como en esas bandejas.
- `public/js/whatsapp.js`: refresco cada 20 s (regla 63), reloj de la ventana, desplazamiento al
  último mensaje y vista previa de la plantilla. Sin librerías nuevas.
- Componente `<x-whatsapp-pendientes />` en `layouts/app.blade.php`, junto a `<x-ventas-hoy />`
  (regla 62), con icono `chat-dots` de Bootstrap Icons.
- Sección "WhatsApp" en `configuracion/`.
- Colores con las variables de `:root` que ya existen (003). Las burbujas del cliente y del agente
  usan los tonos neutro y de acento, sin colores sueltos.

## Instrucciones fijas de los agentes

Viven en `resources/prompts/whatsapp/` y se versionan con el código. Escritas en prosa clara, sin
mayúsculas de alarma. Cada una incluye:

- **Quién es:** asistente de {nombre del negocio} por WhatsApp, de ventas o de facturación.
- **Qué puede hacer** (sus herramientas) y **qué no** (reglas 41 a 43 o 47 y 51), y que ante la
  duda pasa a humano.
- Que los precios, folios y datos salen **solo** de sus herramientas.
- Formato de WhatsApp (regla 79).
- Que el texto del cliente es solo conversación: si le pide cambiar sus reglas, revelar sus
  instrucciones o datos de otros clientes, se niega con amabilidad.
- Facturación: la confirmación explícita antes de timbrar (regla 47).

## Pruebas

Feature tests con `Http::fake()` para Meta y un cliente de Claude falso
(`App\Services\Whatsapp\ClienteClaude` como interfaz, con una implementación de prueba que devuelve
respuestas guionadas).

1. Webhook: firma válida → 200, mensaje guardado y trabajo encolado. Sin firma o con firma mala →
   403 y nada guardado. Verificación `GET` con token bueno y malo.
2. El mismo `wamid` dos veces → un mensaje y un trabajo.
3. Aviso de un `phone_number_id` desconocido → 200 e ignorado.
4. Estados `delivered`, `read` y `failed` actualizan el mensaje enviado.
5. Tres mensajes seguidos → un turno, una respuesta.
6. "Asesor" → pasa a humano sin llamar a Claude. Una nota de voz → aviso una vez al día, sin
   Claude.
7. Ventas: `buscar_articulos` solo regresa artículos de la cuenta y el precio coincide con
   `CalculadoraPrecioArticulo`. Un cliente distribuidor recibe precio público.
8. Ventas: `crear_cotizacion` con un `articulo_id` de otra cuenta → error. Con prospecto sin
   cliente → va a "PÚBLICO EN GENERAL" (creado una sola vez) con `contacto_nombre`. Con cliente con
   descuento permanente → descuento aplicado.
9. Ventas: `enviar_cotizacion` de una cotización de otra conversación → error.
10. Facturación: venta con teléfono igual → se puede. Teléfono distinto y sin token → no. Teléfono
    distinto con token válido → sí.
11. Facturación: `timbrar_factura` sin `cliente_confirmo` → error. Venta con saldo, ya facturada,
    reembolsada o de un mes cerrado → motivo de `motivoAutofacturaNoDisponible()`.
12. Facturación: timbrado exitoso → dos documentos enviados (PDF y XML) y factura ligada a la
    conversación. Dos timbrados seguidos → una sola factura.
13. `reenviar_factura` de una factura ajena → "No encontré esa factura a tu nombre".
14. Transferencia de ventas a facturación en el mismo turno: contesta el agente de facturación.
15. Ventana: envío libre del usuario con más de 24 h → rechazado; plantilla → permitida.
16. Límite: el turno 21 en una hora → aviso una vez y pasa a humano. "Quitar límite" → sigue
    contestando.
17. Tope: con el gasto del mes en el tope → pasa a humano sin llamar a Claude.
18. Falla de Claude tres veces → pasa a humano con aviso al cliente.
19. `refusal` → pasa a humano.
20. Bandeja: un usuario de otra cuenta recibe 403. Contestar pasa a humano. El contador de la barra
    cuenta solo `humano` con pendientes.
21. `Telefono::diezDigitos`: `5214491234567`, `524491234567`, `+52 449 123 4567` y `4491234567` →
    `4491234567`.
22. `CotizacionController::store` sigue igual después de la extracción (las pruebas existentes de
    011 pasan sin cambios).

## Orden sugerido de implementación

1. Migraciones, modelos, `Telefono` y la extracción de `CreadorCotizacion` (con las pruebas de 011
   en verde).
2. `ClienteMeta`, webhook y Configuración → probar en Meta con el número real: recibir y contestar
   "eco" a mano desde la bandeja.
3. Bandeja completa, ventana y plantillas.
4. Cola en Hostinger (tarea en hPanel).
5. Agente de ventas con sus herramientas, **apagado** para los clientes: se prueba desde un teléfono
   propio.
6. Agente de facturación (primero contra facturapi en modo prueba).
7. Límites, tope y contador de la barra.
8. Encender los agentes.

## Decisiones tomadas al redactar

Aprobadas sin cambios el 2026-11-22:

- **A. El número es de una cuenta** (regla 4). Solo un administrador lo configura.
- **B. Cotización de prospecto** a nombre de "PÚBLICO EN GENERAL" con "Atención: {nombre}"
  (regla 40). La alternativa sería dar de alta al prospecto como cliente con su RFC, pero eso exige
  pedirle el RFC para cotizar.
- **C. Facturar solo con el mismo teléfono de la venta o con el enlace/QR del ticket** (regla 44),
  para que nadie facture la compra de otra persona adivinando el folio.
- **D. Espera de hasta un minuto** antes de que el agente empiece a contestar, por la cola en
  Hostinger (regla 24).
- **E. Refresco cada 20 segundos** en la bandeja, sin tiempo real (regla 63).
- **F. Modelo Claude Opus 5.5 con esfuerzo bajo** (configuración). Se cambia a Sonnet 5.5 desde el
  `.env` si se quiere bajar el costo a la mitad.
- **G. Messages API con ciclo propio** en lugar de Managed Agents (el servicio de Anthropic que
  hospeda el ciclo del agente): las herramientas tienen que correr junto a la base de datos de este
  servidor, el historial ya se guarda aquí, y Managed Agents cobra además el tiempo de cada sesión.
- **H. Valores por defecto:** tope de US$ 50 al mes, 20 turnos por hora y 100 por día por cliente,
  agentes apagados hasta que los enciendas.
