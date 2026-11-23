# Spec: Respuestas rápidas en el chat de WhatsApp

> **Estado: implementada el 2026-11-22, falta revisarla a mano en el navegador** (ver "Estado de
> implementación" al final). El usuario aprobó el 2026-11-22, sin cambios, las 21 asunciones
> funcionales y las 4 adiciones técnicas.

> **Cambiada por [046](046-configuracion-negocio-whatsapp-ia.md):** desde el 2026-11-23 la lista
> ya no tiene pestaña propia; es el bloque *Respuestas rápidas* (`#respuestas-rapidas`) de la
> pestaña *WhatsApp*, para todos los usuarios.

**Modifica:**

- [041-agentes-whatsapp.md](041-agentes-whatsapp.md): la caja de respuesta de la conversación
  (`whatsapp/_conversacion.blade.php`) gana la lista de respuestas rápidas al teclear "/". No cambia
  ninguna regla de envío, ventana de 24 horas, plantillas ni pausa del agente.
- [042-configuracion-pestanas.md](042-configuracion-pestanas.md): Configuración gana una quinta
  pestaña, *Respuestas rápidas*, que ven **todos** los usuarios. Por eso un usuario que no es
  administrador pasa de 1 a 2 pestañas y **ya ve la barra de pestañas** (cambia la regla 6 y la
  prueba 31 de 042).

## Historia de usuario

Como usuario, en el chat quiero tener la opción que tengo en WhatsApp: usar la barra diagonal "/"
para abrir respuestas guardadas. También quiero una página de configuración para poder guardar esas
respuestas.

```
Conversación con Juan Pérez
│ ...mensajes...                                                              │
│ ┌───────────────────────────────────────────────────────────┐               │
│ │ ▶ /horario      Nuestro horario es de lunes a viernes de…  │               │
│ │   /horno        Los sellos de horno se entregan en 3 días… │               │
│ └───────────────────────────────────────────────────────────┘               │
│ Respuesta                                                                   │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ /ho                                                                     │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│ [Enviar]                                                                    │

Configuración
 🏢 Emisor   🏦 Datos bancarios   💬 WhatsApp   ⚡ Respuestas rápidas   ✉ Mensajes de pedidos
                                               ━━━━━━━━━━━━━━━━━━━━
 Atajo        Texto                                           Acciones
 /horario     Nuestro horario es de lunes a viernes de…       [✎] [🗑]
 /pago        Hola {nombre}, puedes pagar por transferen…     [✎] [🗑]
                                                       [+ Nueva respuesta]
```

## Objetivo / Alcance

Que la persona que atiende el chat de WhatsApp inserte textos que usa mucho tecleando "/" y el
atajo, y que esos textos se den de alta, se editen y se borren en Configuración.

- Una tabla nueva, `respuestas_rapidas`, del negocio: sin `user_id`, como los datos bancarios (027).
- El alta, la edición y el borrado siguen el mismo patrón que los datos bancarios: lista en una
  pestaña de Configuración y páginas aparte para crear y editar.
- Un archivo JavaScript pequeño (`public/js/respuestas-rapidas.js`) arma la lista flotante en el
  chat. Sin librerías nuevas.

**Fuera de alcance:** imágenes o PDF dentro de una respuesta, respuestas por usuario, carpetas o
categorías, contar cuántas veces se usa cada una, ordenar por las más usadas, que el agente de IA
las use, usarlas fuera de la ventana de 24 horas, y usarlas en otras cajas de texto del sistema
(cotizaciones, correos, etc.).

## Reglas de negocio

### Qué es una respuesta rápida (asunciones 1 a 5)

1. Una respuesta rápida tiene un **atajo** y un **texto**. Solo texto: sin imágenes ni archivos.
2. **El atajo** es una sola palabra:
   - se guarda en minúsculas y sin acentos (`Horário` → `horario`, `Ñandú` → `nandu`);
   - después de eso solo puede tener letras `a-z`, números y guion medio o bajo (`-`, `_`);
   - mide de 1 a 30 caracteres;
   - si trae espacios, el formulario lo rechaza con "El atajo debe ser una sola palabra, sin
     espacios.";
   - se escribe **sin** la "/". Si el usuario la escribe al principio, se quita sola;
   - no se puede repetir: dos respuestas no pueden tener el mismo atajo después de pasarlo a
     minúsculas y quitarle los acentos ("Ya existe una respuesta con el atajo /horario.").
3. **El texto** es obligatorio y mide hasta 4000 caracteres, igual que la caja de respuesta del chat
   (041). Puede llevar saltos de línea y emojis, y se guarda tal cual.
4. **Variables.** El texto puede llevar estas dos, que se llenan al insertarlo en el chat:

   | Variable | Se cambia por | Si no hay dato |
   |---|---|---|
   | `{nombre}` | el nombre del cliente ligado a la conversación: `nombre_contacto` y, si está vacío, `razon_social` | queda vacía |
   | `{fecha}` | la fecha de hoy, en la zona horaria del sistema, escrita como "22 de noviembre de 2026" | (siempre hay) |

   - **(Decisión tomada al redactar)** `{nombre}` usa solo el cliente ligado, no el nombre de
     perfil de WhatsApp, para no saludar con apodos o nombres raros del perfil. Así lo dice la
     prueba de la adición 4.
   - Cualquier otro texto entre llaves (`{precio}`) se deja tal cual.
   - Las variables se escriben en minúsculas. `{Nombre}` no se cambia.
   - Una vez insertado, el texto ya va con los datos y se puede editar antes de enviar.
5. Las respuestas rápidas son **de toda la empresa**: todos los usuarios ven y usan la misma lista.

### Usar las respuestas en el chat (asunciones 6 a 14)

6. La lista flotante se abre al teclear `/` en la caja de respuesta (`#wa-texto`) cuando la "/"
   queda al **inicio** del texto, o después de un espacio o de un salto de línea. Una "/" pegada a
   otra palabra (`1/2`, `http://`) no abre nada.
7. Mientras el usuario sigue escribiendo después de la "/", la lista se filtra con lo escrito:
   - sin importar mayúsculas ni acentos;
   - primero los atajos que **empiezan** con lo escrito y después los que lo tienen **en el texto**;
   - dentro de cada grupo, en orden alfabético de atajo (asunción 21);
   - se ven como mucho **8** a la vez y el resto se alcanza desplazando la lista.

   Un espacio, borrar la "/" o salir de la caja cierra la lista.
8. Cada opción muestra el atajo con su "/" (`/horario`) y los primeros 60 caracteres del texto en
   una línea, con "…" si es más largo. Las variables se ven sin llenar.
9. **Cómo se elige:**
   - con un clic en la opción;
   - con **↑** y **↓** para moverse (dando la vuelta en los extremos) y **Enter** o **Tab** para
     elegir;
   - **Escape** cierra la lista sin insertar nada y deja lo escrito como está.

   Con la lista abierta, **Enter** elige y **no** envía el mensaje. La primera opción está marcada
   desde que se abre la lista.
10. Al elegir, el `/atajo` que se escribió (la "/" y lo que sigue hasta el cursor) se cambia por el
    texto completo, con las variables ya llenas (regla 4). El cursor queda al final del texto
    insertado. **El mensaje no se envía solo**: el usuario lo puede editar y luego pica **Enviar**.
    El texto que había antes y después del `/atajo` se queda igual.
11. Si nada coincide con lo escrito, la lista muestra "Sin respuestas guardadas" y un enlace
    "Agregar respuestas en Configuración" a `/configuracion#respuestas-rapidas`, que abre en otra
    pestaña del navegador para no perder lo escrito. Si todavía no hay ninguna respuesta guardada,
    teclear "/" muestra lo mismo.
12. Enviar un texto que salió de una respuesta rápida es igual que escribirlo a mano: se manda con
    `whatsapp.mensajes.store` y **pausa el agente** en ese chat, como ya pasa hoy (041). El
    servidor no sabe ni registra que vino de una respuesta rápida.
13. Las respuestas rápidas solo están cuando la caja de respuesta existe, es decir, con la ventana
    de 24 horas abierta (041, regla 65). Fuera de la ventana solo quedan las plantillas de Meta, que
    son otra cosa y no cambian.
14. El agente de IA **no** usa ni ve las respuestas rápidas. No entran en sus indicaciones ni en sus
    herramientas.

### La pestaña en Configuración (asunciones 15 a 21)

15. Configuración gana la pestaña *Respuestas rápidas*. La ven **todos** los usuarios. Con eso, la
    tabla de la regla 2 de 042 queda así:

    | Pestaña | Ancla | Icono | Quién la ve |
    |---|---|---|---|
    | Emisor | `#emisor` | `building` | administrador |
    | Datos bancarios | `#datos-bancarios` | `bank` | administrador |
    | WhatsApp | `#whatsapp` | `whatsapp` | administrador |
    | **Respuestas rápidas** | `#respuestas-rapidas` | `lightning-charge` | **todos** |
    | Mensajes de pedidos | `#mensajes` | `envelope` | todos |

    Todo lo demás de 042 (recordar la última pestaña, anclas, teclado, sin JavaScript) aplica igual
    a la pestaña nueva.
16. La pestaña muestra una explicación corta ("Escribe / en el chat de WhatsApp para insertarlas.
    Puedes usar {nombre} y {fecha}.") y una tabla con estas columnas:
    - **Atajo**: con su "/";
    - **Texto**: los primeros 80 caracteres en una línea, con "…" si es más largo;
    - **Acciones**: editar y borrar.

    Va ordenada por atajo. Sin respuestas, en lugar de la tabla dice: "Todavía no hay respuestas
    rápidas. Agrega los textos que mandas seguido, como tu horario o tus datos de pago."
17. El botón **Nueva respuesta** lleva a `/configuracion/respuestas-rapidas/crear`, una página con
    el formulario (*Atajo*, *Texto*) y los botones **Guardar** y **Cancelar**. Editar lleva a una
    página igual, con los datos cargados. Guardar o cancelar regresa a
    `/configuracion#respuestas-rapidas`, con el mensaje "Respuesta rápida guardada." al guardar.
18. Borrar pide confirmación ("¿Eliminar la respuesta /horario?") con el mismo `data-confirmar` de
    los datos bancarios. La respuesta se borra para siempre, sin papelera. Regresa con "Respuesta
    rápida eliminada.". Los mensajes ya enviados no cambian.
19. Cualquier usuario que entra a Configuración puede crear, editar y borrar respuestas. No hace
    falta ser administrador.
20. No hay límite de cuántas respuestas se guardan.
21. No se cuenta cuántas veces se usa cada respuesta. Tanto la tabla como la lista del chat van en
    orden alfabético de atajo.

## Adición técnica 1: la lista viaja con el chat

22. Al abrir una conversación con la ventana abierta, el servidor manda en la página todas las
    respuestas rápidas (atajo y texto), además del nombre (regla 4) y la fecha de hoy ya escrita.
    Van en un `<script type="application/json" id="wa-respuestas-rapidas">`.
23. El JavaScript lee ese bloque una vez. Filtrar y llenar variables pasa en el navegador, sin
    pedirle nada al servidor en cada tecla.
24. Si alguien da de alta una respuesta mientras el chat está abierto, aparece al recargar la
    conversación. El refresco cada 20 segundos de 041 no actualiza la lista.
25. El texto va escapado con `@json` (con `JSON_HEX_TAG`) para que un texto con `</script>` no rompa
    la página.

## Adición técnica 2: sin JavaScript

26. Sin JavaScript la caja de respuesta funciona como hoy. No sale la lista y `/horario` se manda
    como texto normal.
27. La pestaña y las páginas de crear, editar y borrar son formularios normales, así que funcionan
    sin JavaScript, como el resto de Configuración (042, adición 2). La confirmación de borrado
    (`data-confirmar`) necesita JavaScript. Sin él, se borra directo, igual que en los datos
    bancarios.

## Adición técnica 3: teclado y lectores de pantalla

28. La lista sigue el patrón accesible de "combobox con lista" (WAI-ARIA):
    - la caja `#wa-texto` gana `aria-autocomplete="list"`, `aria-controls` hacia la lista y
      `aria-expanded` (`true` con la lista abierta);
    - la lista es `role="listbox"` con `aria-label="Respuestas rápidas"` y cada opción es
      `role="option"` con su `id`. La opción marcada lleva `aria-selected="true"` y la caja apunta a
      ella con `aria-activedescendant`;
    - el foco **no** sale de la caja: las flechas mueven la marca, no el foco.
29. Una zona `aria-live="polite"` oculta a la vista (`.solo-lectores`) dice cuántas hay ("3
    respuestas rápidas. Usa las flechas para elegir.") o "Sin respuestas guardadas".
30. Todo se puede hacer sin mouse (regla 9). La opción marcada se ve con el mismo estilo de foco del
    resto del sistema, no solo con color.

## Backend (Laravel)

### Migración `create_respuestas_rapidas_table`

| Columna | Tipo |
|---|---|
| `id` | `id()` |
| `atajo` | `string(30)`, **único** |
| `texto` | `text` |
| `timestamps` | |

Sin `user_id` (regla 5). Sin `softDeletes` (regla 18).

### Modelo `App\Models\RespuestaRapida`

- `$table = 'respuestas_rapidas'`, `#[Fillable(['atajo', 'texto'])]`, `HasFactory`.
- `public static function normalizarAtajo(string $atajo): string`: quita espacios de los lados y
  la "/" del inicio, y aplica `Str::ascii` y `Str::lower` (regla 2).
- Scope `ordenadas()`: `orderBy('atajo')`.

### Form request `RespuestaRapidaRequest`

- `prepareForValidation()` pasa `atajo` por `normalizarAtajo`.
- Reglas:
  - `atajo`: `required`, `string`, `max:30`, `regex:/^[a-z0-9_-]+$/`, `Rule::unique('respuestas_rapidas')->ignore($this->route('respuestaRapida'))`;
  - `texto`: `required`, `string`, `max:4000`.
- Mensajes en español (regla 2). El `regex` lleva "El atajo debe ser una sola palabra, sin
  espacios." cuando trae espacios y "El atajo solo puede tener letras, números, - y _." en otro
  caso. **(Decisión tomada al redactar)** Basta con un mensaje que cubra los dos casos si separarlos
  complica el request.

### Controlador `RespuestaRapidaController`

`create`, `store`, `edit`, `update` y `destroy`, con el mismo estilo que `DatoBancarioController`.
Todas redirigen a `route('configuracion.edit').'#respuestas-rapidas'`. Sin middleware
`can:editar-emisor` (regla 19).

### Rutas

| Método | URL | Acción | Nombre |
|---|---|---|---|
| GET | `/configuracion/respuestas-rapidas/crear` | formulario nuevo | `configuracion.respuestas-rapidas.create` |
| POST | `/configuracion/respuestas-rapidas` | guardar nueva | `configuracion.respuestas-rapidas.store` |
| GET | `/configuracion/respuestas-rapidas/{respuestaRapida}/editar` | formulario editar | `configuracion.respuestas-rapidas.edit` |
| PUT | `/configuracion/respuestas-rapidas/{respuestaRapida}` | guardar cambios | `configuracion.respuestas-rapidas.update` |
| DELETE | `/configuracion/respuestas-rapidas/{respuestaRapida}` | borrar | `configuracion.respuestas-rapidas.destroy` |

Dentro del grupo con sesión, junto a las de datos bancarios.

### `ConfiguracionController`

- `edit()` pasa además `respuestasRapidas` (`RespuestaRapida::query()->ordenadas()->get()`).
- `pestanas()` agrega *Respuestas rápidas* antes de *Mensajes de pedidos*, fuera del
  `Gate::allows('editar-emisor')` (regla 15).

### `WhatsappController`

- Donde se arma la vista con la conversación abierta, y solo si `ventanaAbierta()`, pasa
  `respuestasRapidas` como lista `[{atajo, texto}]` ordenada por atajo, `nombreCliente`
  (`$abierta->cliente?->nombre_contacto ?: $abierta->cliente?->razon_social ?: ''`) y `fechaHoy`
  (`now()->translatedFormat('j \d\e F \d\e Y')`, en español), según la regla 4 y la adición 1.

## Vistas

- `configuracion/edit.blade.php`: nuevo
  `<div id="respuestas-rapidas" class="config-panel">@include('configuracion._respuestas-rapidas')</div>`
  antes de `#mensajes`, fuera del `@can('editar-emisor')`.
- `configuracion/_respuestas-rapidas.blade.php`: `<x-card titulo="Respuestas rápidas">` con
  `.encabezado` (explicación + `<x-boton icono="plus-lg">Nueva respuesta</x-boton>`) y la tabla
  `.tabla` de la regla 16, con botones `variante="suave"` (editar, `icono="pencil"`) y
  `variante="secundario"` (borrar, `icono="trash"`), cada uno con `descripcion` para lectores
  ("Editar /horario"). Igual que `_datos-bancarios`.
- `configuracion/respuestas-rapidas/_formulario.blade.php`, `crear.blade.php` y
  `editar.blade.php`, como los de `datos-bancarios/`. Los campos van con `<x-campo>` (lo exige
  `EstiloUniformeTest`):
  - `atajo` con prefijo visual "/" y ayuda "Una palabra, sin espacios. Se escribe en el chat como
    /atajo.";
  - `texto` como `textarea`, `rows="6"`, `maxlength="4000"`, con ayuda sobre `{nombre}` y `{fecha}`.
- `whatsapp/_conversacion.blade.php`, dentro de `.wa-redactar`:
  - el bloque JSON de la regla 22;
  - un `<div class="wa-respuestas" role="listbox" id="wa-respuestas-lista" hidden>` vacío, que
    llena el script;
  - la zona `aria-live` de la regla 29;
  - el placeholder de `#wa-texto` pasa a "Escribe una respuesta o / para respuestas rápidas…
    (contestar pausa el agente en este chat)";
  - `@push('scripts')` con `respuestas-rapidas.js?v=filemtime`, como los demás scripts.

## Frontend

### `public/js/respuestas-rapidas.js`

- Al cargar busca `#wa-texto` y `#wa-respuestas-rapidas`. Si falta cualquiera de los dos, no hace
  nada.
- En `input` y `keyup` revisa el texto del cursor hacia atrás: si hay una "/" al inicio o tras un
  espacio o salto de línea, y entre la "/" y el cursor no hay espacios, abre y filtra la lista
  (reglas 6 y 7). Si no, la cierra.
- Para comparar sin acentos usa `normalize('NFD').replace(/[̀-ͯ]/g, '')` y
  `toLowerCase()`.
- En `keydown`, con la lista abierta: ↑ ↓ Enter Tab Escape según la regla 9, con `preventDefault`
  para que Enter no envíe y Tab no salte.
- Al elegir: cambia `{nombre}` y `{fecha}` (regla 4), reemplaza el tramo `/atajo` con
  `setRangeText` (regla 10), mueve el cursor y dispara un evento `input` para que otros scripts
  noten el cambio.
- Cierra la lista con `blur`, pero dejando tiempo para que un clic en una opción alcance a
  registrarse (`mousedown` con `preventDefault` en las opciones).
- Pone los atributos ARIA de la adición 3.

### `public/css/app.css`

- `.wa-redactar` con `position: relative`. `.wa-respuestas` va encima de la caja
  (`position: absolute; bottom: 100%`), a lo ancho de la caja, con `max-height` para unas 8
  opciones y `overflow-y: auto`.
- Opción: atajo en negritas y vista previa en una línea con `text-overflow: ellipsis`. Opción
  marcada con el color de fondo de selección que ya existe y borde de foco.
- Sin `border-radius` y sin colores sueltos nuevos, solo variables existentes (lo exige
  `EstiloUniformeTest`).
- En el celular la lista usa el ancho de la caja y no hace que la página se mueva de lado.

## Pruebas (adición 4)

Feature tests en `tests/Feature/RespuestasRapidasTest.php` y una factory `RespuestaRapidaFactory`.

31. **Atajo único:** con `/horario` guardado, crear otra con `Horario` o `horário` es rechazado con
    error en `atajo`. Editar la misma respuesta sin cambiar el atajo sí se permite.
32. **Normalización:** guardar con atajo `/Horário` guarda `horario`. Un atajo con espacio (`mi
    horario`) es rechazado. Un texto de 4001 caracteres es rechazado.
33. **Permisos:**
    - un usuario que **no** es administrador puede crear, editar y borrar (regla 19);
    - sin sesión, las cinco rutas redirigen al inicio de sesión.
34. **Configuración:**
    - la pestaña *Respuestas rápidas* sale para el administrador (5 pestañas, en el orden de la
      regla 15) y para un usuario normal (2 pestañas, ahora **con** barra);
    - guardar y borrar redirigen a `/configuracion#respuestas-rapidas`.

    Se ajusta la prueba 31 de `ConfiguracionPestanasTest`.
35. **Variables:** al abrir una conversación ligada a un cliente con `nombre_contacto` "Juan
    Pérez", el bloque JSON trae `"nombre":"Juan Pérez"` y la fecha de hoy en español. Sin cliente
    ligado trae `"nombre":""`. Con `nombre_contacto` vacío usa `razon_social`.
36. **Ventana:** con la ventana de 24 horas abierta, la conversación trae el bloque
    `#wa-respuestas-rapidas` con las respuestas en orden de atajo. Con la ventana cerrada no lo
    trae.
37. **Escape:** una respuesta con texto `</script><b>x</b>` sale escapada en el bloque JSON y no
    rompe la página.

La lista flotante (filtrar, teclado, insertar) se revisa a mano en el navegador, porque el proyecto
no tiene pruebas de JavaScript.

## Orden de implementación

1. Migración, modelo, factory, request, controlador, rutas y vistas de Configuración, con las
   pruebas 31 a 34.
2. Datos para el chat en `WhatsappController` y en `_conversacion`, con las pruebas 35 a 37.
3. `respuestas-rapidas.js` y su CSS. Revisión a mano en el navegador: abrir, filtrar, elegir con
   mouse y teclado, variables y Escape.

## Estado de implementación

Implementada el 2026-11-22, con las 17 pruebas de `tests/Feature/RespuestasRapidasTest.php` y la
prueba 31 de `ConfiguracionPestanasTest` ajustada. La suite completa pasa.

- **Enter en el chat:** `whatsapp.js` ya mandaba el mensaje con Enter (041). Ahora no lo hace si el
  evento ya viene con `preventDefault` o si la caja tiene `aria-expanded="true"`. Además,
  `respuestas-rapidas.js` se carga antes que `whatsapp.js`.
- **Sin coincidencias:** con la lista abierta y "Sin respuestas guardadas", Enter cierra la lista y
  envía como siempre, porque no hay nada que elegir.
- **Atajo:** se validan por separado los espacios (`not_regex`) y los signos (`regex`), cada uno con
  su mensaje (regla 2). El campo acepta 31 caracteres para dejar lugar a la "/" que se quita sola.
- **JSON:** `@json` con `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE`. Los acentos se ven
  tal cual y `</script>` sale como `\u003C/script\u003E` (escapado).
- **Fecha:** `now()->locale('es')->translatedFormat('j \d\e F \d\e Y')`.
- **Pendiente:** correr la migración en producción y revisar a mano la lista: abrir, filtrar, elegir
  con mouse y teclado, variables y Escape.
