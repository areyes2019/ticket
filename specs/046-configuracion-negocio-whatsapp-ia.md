# Spec: Configuración en tres pestañas: Negocio, WhatsApp y Agentes IA

> **Estado: implementada** el 2026-11-23 (ver "Estado de implementación" al final). El usuario
> aprobó el 2026-11-23, sin cambios, las 20 asunciones funcionales y las 4 adiciones técnicas.

**Modifica:**

- [042-configuracion-pestanas.md](042-configuracion-pestanas.md): reglas 2, 5, 6, 9, 10, 17, 18 y
  40 (las pestañas y el acomodo de WhatsApp).
- [043-respuestas-rapidas.md](043-respuestas-rapidas.md): su pestaña propia pasa a ser un bloque de
  la pestaña *WhatsApp*.
- [041-agentes-whatsapp.md](041-agentes-whatsapp.md): el horario de atención deja de ser del
  número de WhatsApp y pasa a ser del negocio; el guardado de WhatsApp se parte en dos.
- No cambia ningún campo, texto, validación ni la forma en que contestan los agentes.

## Historia de usuario

Ya tengo todo el formulario de configuración de WhatsApp, pero ¿no sería lógico tener una pestaña
adicional para el agente de IA? Abriría estas secciones:

- **Negocio**: dirección, datos fiscales, horarios, etc.
- **WhatsApp**: API, número de teléfono, token, respuestas rápidas, etc.
- **IA**: preguntas frecuentes, condiciones (formas de pago, etc.), prender / apagar.

```
Configuración
 🏪 Negocio •    💬 WhatsApp •    🤖 Agentes IA
 ━━━━━━━━━━━
 Negocio:     Datos del emisor · Datos bancarios · Horario de atención · Mensajes de pedidos
 WhatsApp:    Estado del número · Conexión con Meta · Respuestas rápidas
 Agentes IA:  Agente de ventas · Agente de facturación · Avisos al pasar con una persona · Límites
```

## Objetivo / Alcance

Separar lo que se llena una vez (el canal de WhatsApp) de lo que se ajusta seguido (los agentes),
y juntar lo que es del negocio en una sola pestaña.

**Fuera de alcance:** cambiar campos o textos, subpestañas, guardar todo con un solo botón y usar
el horario en otros lugares (PDF, mostrador).

## Reglas de negocio

### Pestañas (asunciones 1 a 3)

1. Configuración tiene 3 pestañas, en este orden:

   | Pestaña | Ancla | Icono | Contenido |
   |---|---|---|---|
   | Negocio | `#negocio` | `shop` | Datos del emisor, Datos bancarios, Horario de atención, Mensajes de pedidos |
   | WhatsApp | `#whatsapp` | `whatsapp` | Estado del número, Conexión con Meta, Respuestas rápidas |
   | Agentes IA | `#agentes` | `robot` | Agente de ventas, Agente de facturación, Avisos al pasar con una persona, Límites |

2. Desaparecen como pestañas sueltas *Emisor*, *Datos bancarios*, *Respuestas rápidas* y
   *Mensajes de pedidos*; ahora son bloques dentro de una pestaña.
3. El acomodo de los bloques (título a la izquierda y campos a la derecha, 042 reglas 34 a 36) no
   cambia.

### Negocio (asunciones 4 a 7)

4. Bloques, en este orden, cada uno con su ancla: **Datos del emisor** (`#emisor`), **Datos
   bancarios** (`#datos-bancarios`), **Horario de atención** (`#horario`) y **Mensajes de
   pedidos** (`#mensajes`).
5. Cada bloque guarda con su propio botón: *Guardar emisor*, los botones de la lista de bancos,
   *Guardar horario* y *Guardar mensajes*. Guardar uno no toca los demás.
6. El **horario de atención es del negocio**: uno para toda la instalación, guardado con los datos
   del emisor (adición 1). Se puede capturar aunque no haya WhatsApp conectado. Los agentes (datos
   del negocio en sus indicaciones) y el `{horario}` del aviso fuera de horario lo leen de ahí.
7. El horario que ya estaba capturado en el número de WhatsApp pasa tal cual al negocio.

### WhatsApp (asunciones 8 a 10)

8. Contenido: la caja de estado (número, *Probar conexión*, *Actualizar plantillas* y el
   desplegable *Registrar número*), el bloque **Conexión con Meta** con su botón **Guardar
   conexión**, y el bloque **Respuestas rápidas** (`#respuestas-rapidas`), la lista de 043 sin
   cambios.
9. *Guardar conexión* solo guarda los identificadores, el token, la clave secreta y el token de
   verificación. No toca los agentes, los avisos ni los límites (adición 2).
10. La caja de estado deja de mostrar el gasto de Claude del mes; se ve en *Agentes IA*.

### Agentes IA (asunciones 11 a 13)

11. Bloques, en este orden: **Agente de ventas** y **Agente de facturación** (casilla de
    encendido, condiciones, preguntas frecuentes y otras indicaciones, igual que en 045), **Avisos
    al pasar con una persona** y **Límites**. En *Límites* se ve el gasto estimado de Claude del
    mes junto al tope.
12. Un solo botón al final, **Guardar agentes**, que guarda todo lo de esta pestaña y nada de la
    conexión (adición 2).
13. Sin número de WhatsApp conectado, la pestaña solo muestra el aviso "Primero conecta WhatsApp"
    con un enlace a la pestaña *WhatsApp*; no muestra el formulario. Si aun así llega un guardado
    de agentes sin número, regresa a *WhatsApp* con ese mismo aviso como error.

### Quién ve qué (asunciones 14 y 15)

14. El administrador (`can:editar-emisor`) ve las 3 pestañas completas.
15. Un usuario normal ve **Negocio**, solo con *Mensajes de pedidos*, y **WhatsApp**, solo con
    *Respuestas rápidas*. No ve *Agentes IA*.

### Señales, enlaces y regreso al guardar (asunciones 16 a 20)

16. Punto de advertencia (042, regla 19) en *Negocio* cuando faltan datos fiscales
    (`Emisor::estaCompleto()` falso) y en *WhatsApp* cuando el administrador no tiene número
    conectado. Solo para el administrador. *Agentes IA* no lleva punto.
17. Las anclas de los bloques funcionan como enlaces: `/configuracion#datos-bancarios` abre
    *Negocio* y baja hasta *Datos bancarios*. Siguen funcionando los enlaces que ya existen
    (`#emisor` del aviso de emisor incompleto, `#whatsapp` de la bandeja, `#respuestas-rapidas` del
    chat).
18. Al guardar, la página regresa a la pestaña y al bloque de lo guardado:

    | Acción | Regresa a |
    |---|---|
    | Guardar emisor | `#emisor` |
    | Bancos (crear, editar, borrar, mostrar, mover, cancelar) | `#datos-bancarios` |
    | Guardar horario | `#horario` |
    | Guardar mensajes | `#mensajes` |
    | Guardar conexión, probar, registrar, actualizar plantillas | `#whatsapp` |
    | Respuestas rápidas (crear, editar, borrar, cancelar) | `#respuestas-rapidas` |
    | Guardar agentes | `#agentes` |

19. Si el navegador recordaba una pestaña vieja (`emisor`, `datos-bancarios`, `respuestas-rapidas`
    o `mensajes`, de 042), abre la pestaña nueva que contiene ese bloque.
20. No cambia ningún campo, texto, validación ni la forma en que contestan los agentes.

## Adición técnica 1: el horario se guarda con el negocio

21. Migración: columna `horario` (JSON, nullable) en la tabla `emisor`. Copia el horario del número
    de WhatsApp más antiguo que lo tenga; si no hay fila de emisor, la crea solo con el horario.
    Después quita la columna `horario` de `whatsapp_numeros`. El `down()` hace lo contrario.
22. `Emisor` recibe `DIAS`, `HORARIO_POR_DEFECTO`, `horarioSemanal()`, `enHorario()` y
    `horarioTexto()` (antes en `WhatsappNumero`). `WhatsappNumero::avisoHumano()` y el prompt de
    los agentes leen el horario de `Emisor::actual()`.
23. Ruta nueva `PUT configuracion/horario` (`configuracion.horario`, `can:editar-emisor`) en
    `ConfiguracionController::actualizarHorario()`, con la misma validación y limpieza de tramos
    que tenía WhatsApp (un día cerrado o con apertura no menor que el cierre queda `null`).

## Adición técnica 2: guardado de WhatsApp en dos

24. `PUT configuracion/whatsapp` (`configuracion.whatsapp`) guarda solo la conexión (regla 9).
    Mensaje: "Conexión con Meta guardada."
25. `PUT configuracion/whatsapp/agentes` (`configuracion.whatsapp.agentes`) guarda encendidos,
    indicaciones, avisos y límites, con las validaciones de 041 y 045. Mensaje: "Ajustes de los
    agentes guardados." Sin número: regla 13.
26. Un número recién conectado toma los valores por defecto de agentes y límites (apagados, US$ 50,
    20 por hora y 100 por día), como hoy.

## Adición técnica 3: anclas de bloques

27. `configuracion-pestanas.js` resuelve un ancla así: si es una pestaña, la abre; si no, busca un
    elemento con ese `id` dentro del contenedor de pestañas y abre la pestaña que lo contiene. Lo
    usa para el ancla de la dirección, para la pestaña recordada (regla 19) y para `hashchange`.
28. Al abrir por un bloque, la dirección queda con el ancla de la pestaña (042, regla 12), se
    recuerda la pestaña y la página baja al bloque (`scrollIntoView`). Al abrir por una pestaña,
    la página queda arriba, como en 042.
29. Sin JavaScript, todo se ve una sección debajo de otra y las anclas bajan a cada bloque (042,
    adición 2).

## Adición técnica 4: pruebas

En `tests/Feature/ConfiguracionPestanasTest.php`:

30. El administrador ve las 3 pestañas en orden y todos los bloques con su `id`; el usuario normal
    ve *Negocio* y *WhatsApp* con solo *Mensajes de pedidos* y *Respuestas rápidas*.
31. Cada guardado regresa al ancla de la regla 18.
32. Guardar la conexión no cambia los agentes ni los límites, y guardar los agentes no cambia el
    token ni los identificadores.
33. Guardar agentes sin número regresa a `#whatsapp` con error; la pestaña muestra el aviso.
34. El horario se guarda en el emisor y la migración copia el horario del número.
35. Señales de las pestañas y ningún `id` de pestaña o bloque repetido.

## Estado de implementación

Implementada el 2026-11-23, con las pruebas de `tests/Feature/ConfiguracionPestanasTest.php`
(30 a 35). La suite completa pasa.

- **Migración:** `2026_11_25_100000_horario_del_negocio.php`. Hay que correrla al desplegar
  (`php artisan migrate`).
- **Vistas:** `_horario.blade.php` (Negocio) y `_agentes.blade.php` (Agentes IA) son nuevas;
  `_whatsapp.blade.php` quedó solo con el estado y la conexión. Las anclas de los bloques van en
  el `id` de su `<x-card>`; las de *Mensajes de pedidos*, en su `<form>`.
- **Botón de los mensajes:** pasó de *Guardar* a *Guardar mensajes*, porque ahora comparte la
  pestaña con otros bloques. El mensaje de éxito sigue siendo "Configuración guardada.".
- **Pruebas ajustadas:** `WhatsappAgentesTest` (la conexión ya no lleva horario y el aviso fuera
  de horario lee el horario del emisor), `WhatsappIndicacionesTest` (guarda en
  `/configuracion/whatsapp/agentes`) y `RespuestasRapidasTest` (el bloque va en *WhatsApp*).
- **Pendiente:** revisar a mano en el navegador que las anclas viejas (`#datos-bancarios`,
  `#respuestas-rapidas`) abran la pestaña y bajen al bloque.
