# Spec: Barra de respuesta estilo WhatsApp

> **Estado: implementada el 2026-11-23, falta revisarla a mano en el navegador** (ver "Estado de
> implementación" al final). El 2026-11-23 el usuario aprobó las 23 asunciones
> funcionales, con cambios en la 3 (esquinas rectas) y la 6 (color del sistema), y las 4 adiciones
> técnicas.

**Modifica:**

- [041-agentes-whatsapp.md](041-agentes-whatsapp.md): cambia la forma de la caja de respuesta de la
  conversación (`whatsapp/_conversacion.blade.php`, formulario `.wa-redactar`). **No cambia** el
  servidor: misma ruta `whatsapp.mensajes.store`, mismos campos `texto` y `archivo`, mismas
  validaciones, misma ventana de 24 horas y misma pausa del agente al contestar.
- [043-respuestas-rapidas.md](043-respuestas-rapidas.md): la lista de "/" se queda igual y se
  acomoda encima de la barra nueva (regla 21).

## Historia de usuario

En la caja de respuesta del chat quiero la misma interfaz de WhatsApp: una barra para poner texto
a lo largo del espacio, un botón con flecha al final para enviar y un clip para adjuntar archivo.
Al abrir el clip, un menú hacia arriba con lo que se puede adjuntar: por lo pronto uno para
archivos y otro para imágenes. Deja también un icono para catálogo, pero solo de adorno: después
diré qué debe hacer.

```
Hoy:
 Respuesta                     Adjuntar imagen o PDF (máx. 10 MB)
 ┌──────────────┐              ┌──────────────────────────────────────┐ ┌──────────┐
 │ Escribe una… │              │ [Seleccionar archivo] Sin archivos…  │ │ ➤ Enviar │
 └──────────────┘              └──────────────────────────────────────┘ └──────────┘

Nuevo:
                 ┌──────────────────────┐
                 │ 📄 Documento          │
                 │ 🖼 Imagen             │
                 │ 🛍 Catálogo  Próximam.│   ← menú hacia arriba, pegado al clip
                 └──────────────────────┘
 ┌──────────────────────────────────────────────────────────────────────────┐ ┌───┐
 │ 📎 │ Escribe un mensaje o / para respuestas rápidas                      │ │ ➤ │
 └──────────────────────────────────────────────────────────────────────────┘ └───┘
   Contestar pausa el agente en este chat.            (solo con el agente activo)
```

## Objetivo / Alcance

Cambiar la caja de respuesta de la conversación por una barra al estilo de WhatsApp: clip a la
izquierda, texto a todo lo ancho y botón de enviar a la derecha, con un menú de adjuntos que se
abre hacia arriba. Sin cambios en el servidor.

- Todo es vista, CSS y un archivo JavaScript nuevo, `public/js/whatsapp-redactar.js`. Sin
  librerías nuevas.
- Respeta el estilo uniforme del sistema: **esquinas rectas** y **colores del sistema**.

**Fuera de alcance:** emojis, stickers, programar mensajes, notas de voz o micrófono, varios
archivos en un mismo mensaje, arrastrar y soltar archivos, pegar imágenes del portapapeles, y lo
que hará *Catálogo* (otra spec).

## Reglas de negocio

### Forma de la barra (asunciones 1 a 7)

1. La caja de respuesta es **una sola barra horizontal** a lo ancho de la conversación. De
   izquierda a derecha lleva:
   1. el **clip** (icono `paperclip`), dentro de la barra;
   2. la **caja de texto**, que ocupa todo el espacio que sobra;
   3. el **botón de enviar**, pegado a la derecha de la barra.
2. La caja de texto empieza de **una línea** y crece sola mientras se escribe, hasta **6 líneas**.
   De ahí en adelante sale barra de desplazamiento dentro de la caja. Al enviar o vaciar la caja,
   regresa a una línea.
3. **Esquinas rectas** en la barra, el botón, el menú y la tira del archivo, como todo el sistema
   (`--radio: 0`). No hay excepción al estilo uniforme.
4. Sin etiqueta visible "Respuesta". El texto de ayuda va dentro de la caja: "Escribe un mensaje o /
   para respuestas rápidas". La etiqueta "Respuesta" se queda solo para lectores de pantalla
   (`.solo-lectores`).
5. El aviso "Contestar pausa el agente en este chat." sale del texto de ayuda. Ahora es un renglón
   pequeño en gris (`.ayuda`) debajo de la barra, y **solo** aparece cuando la conversación está con
   el agente (`EstadoConversacion::Agente`). Con la conversación en manos de una persona o cerrada,
   no aparece.
6. El botón de enviar es un **cuadro** con solo el icono `send-fill` en blanco, con el color
   principal del sistema: `<x-boton>` variante `principal`, como los demás botones de guardar y
   enviar. Lleva `descripcion="Enviar"` para lectores de pantalla.
7. La barra solo lleva clip, texto y enviar. No lleva emojis ni reloj.

### El clip y su menú (asunciones 8 a 13)

8. Al picar el clip se abre un **menú hacia arriba**, pegado al clip por su borde izquierdo, con 3
   opciones en este orden:

   | Opción | Icono | Qué hace |
   |---|---|---|
   | Documento | `file-earmark-pdf` | abre el selector de archivos, solo PDF |
   | Imagen | `image` | abre el selector de archivos, solo JPG, PNG y WEBP |
   | Catálogo | `shop` | nada por ahora (regla 11) |

9. *Documento* abre el selector con `accept="application/pdf"`.
10. *Imagen* abre el selector con `accept="image/jpeg,image/png,image/webp"`. En el celular el
    propio sistema ofrece galería o cámara.
11. *Catálogo* se ve como las otras opciones, con la leyenda "Próximamente" a la derecha, atenuado
    y **desactivado**: picarlo no hace nada y no cierra el menú. Se anuncia como no disponible
    (adición 3).
12. El menú se cierra al elegir *Documento* o *Imagen*, al picar fuera de él, con **Escape** o al
    volver a picar el clip.
13. Se manda **un solo archivo** por mensaje, hasta **10 MB** (`config('whatsapp.max_mb_archivo')`),
    como hoy. Elegir otro archivo reemplaza al anterior.

### El archivo elegido (asunciones 14 a 16)

14. Al elegir un archivo aparece **arriba de la barra** una tira con:
    - una **miniatura** si es imagen, o el icono `file-earmark-pdf` si es PDF;
    - el **nombre** del archivo, cortado con "…" si no cabe;
    - el **tamaño** legible ("230 KB", "1.4 MB");
    - un botón **✕** (`x-lg`, `descripcion="Quitar archivo"`) que lo quita y deja el campo vacío.
15. Lo que se escriba en la caja se manda como **pie** del archivo, como hoy (041).
16. Si el archivo pesa más de 10 MB o no es de los tipos permitidos, la tira lo dice en rojo
    ("El archivo pesa más de 10 MB." / "Solo se pueden mandar PDF o imágenes JPG, PNG o WEBP.") y
    el envío se bloquea hasta quitarlo o cambiarlo. El servidor lo sigue validando igual que hoy.

### Enviar (asunciones 17 a 21)

17. **Enter** envía y **Mayús+Enter** hace salto de línea, como hoy. Con la lista de respuestas
    rápidas abierta, Enter elige la respuesta (043, regla 9).
18. El botón de enviar está **siempre visible**. No cambia a micrófono.
19. Con la caja vacía (o solo espacios) y sin archivo, el botón se ve atenuado y no envía
    (`aria-disabled="true"`). Enter tampoco envía.
20. Al enviar, el botón cambia a un indicador de carga (icono `arrow-repeat` girando) y se
    desactiva, igual que la caja y el clip, para no mandar el mensaje dos veces. La página recarga
    como hoy al terminar.
21. La lista de respuestas rápidas (043) sale **encima de la barra**, del mismo ancho que la barra
    (sin el botón de enviar). Si hay tira de archivo, la lista sale encima de la tira.

### Otros casos (asunciones 22 y 23)

22. Fuera de la ventana de 24 horas no hay barra: se sigue mostrando el aviso amarillo de 041
    (regla 65), sin cambios.
23. En el celular la barra se ve igual, a todo lo ancho y pegada abajo de la conversación. El menú
    del clip no se sale de la pantalla.

## Adición técnica 1: sin JavaScript sigue funcionando

24. El HTML que manda el servidor trae el campo de archivo **visible**, como hoy: "Adjuntar imagen
    o PDF (máx. 10 MB)" con su botón del navegador, debajo de la barra. El clip y el menú se dibujan
    ocultos (`hidden`).
25. Cuando el script carga, pone la clase `.redactar-activo` en el formulario. Solo con esa clase el
    CSS esconde el campo de archivo y el script muestra el clip. Si el script falla o no carga,
    nada queda escondido y se puede adjuntar y enviar con el botón de enviar.
26. Sin JavaScript la caja no crece sola. Se queda en 2 líneas y se puede estirar a mano
    (`resize: vertical`), como hoy.

## Adición técnica 2: un solo campo de archivo

27. Hay **un solo** campo de archivo, `archivo`, hecho con `<x-campo tipo="file">` (lo exige
    `EstiloUniformeTest`). *Documento* e *Imagen* le cambian el `accept` (reglas 9 y 10) y le hacen
    `click()` para abrir el selector del sistema.
28. Sin JavaScript el campo conserva el `accept` de hoy: `image/jpeg,image/png,image/webp,application/pdf`.
29. Al servidor le llega lo mismo que hoy. `enviarMensaje` no cambia.

## Adición técnica 3: teclado y lectores de pantalla

30. El clip es un `<x-boton tipo="button" variante="suave" icono="paperclip" descripcion="Adjuntar">`
    con `aria-haspopup="menu"`, `aria-controls` hacia el menú y `aria-expanded` (`true`/`false`).
31. El menú sigue el patrón "menu button" de WAI-ARIA:
    - el contenedor es `role="menu"` con `aria-label="Adjuntar"`, y cada opción es
      `role="menuitem"`;
    - al abrir, el foco pasa a la primera opción;
    - **↑** y **↓** se mueven entre las opciones (dando la vuelta) y también se detienen en
      *Catálogo*; **Inicio** y **Fin** van a la primera y la última;
    - **Enter** o **Espacio** eligen. **Escape** y **Tab** cierran, y Escape regresa el foco al clip;
    - *Catálogo* lleva `aria-disabled="true"` y se anuncia como no disponible.
32. La tira del archivo es una zona `aria-live="polite"`. Al elegir un archivo se anuncia "Archivo
    adjunto: cotizacion.pdf, 230 KB", y al quitarlo, "Archivo quitado".
33. El botón de enviar atenuado lleva `aria-disabled="true"` en lugar de `disabled`, para que se
    pueda seguir alcanzando con Tab y el lector diga por qué no envía.

## Vista

`whatsapp/_conversacion.blade.php`, dentro del `@if ($abierta->ventanaAbierta())`:

```blade
<form method="POST" action="{{ route('whatsapp.mensajes.store', $abierta) }}" enctype="multipart/form-data"
      class="wa-redactar" data-redactar data-max-bytes="{{ config('whatsapp.max_mb_archivo') * 1024 * 1024 }}">
    @csrf
    {{-- 043: JSON de respuestas rápidas, lista y aviso, sin cambios --}}

    <div class="wa-adjunto" data-adjunto aria-live="polite" hidden></div>   {{-- regla 14 --}}

    <div class="wa-barra">
        <div class="wa-clip" data-clip hidden>
            <x-boton tipo="button" variante="suave" icono="paperclip" descripcion="Adjuntar"
                     aria-haspopup="menu" aria-expanded="false" aria-controls="wa-menu-adjuntar" />
            <div class="wa-menu-adjuntar" id="wa-menu-adjuntar" role="menu" aria-label="Adjuntar" hidden>
                {{-- tres opciones de la regla 8, cada una con data-adjuntar="documento|imagen|catalogo" --}}
            </div>
        </div>

        <x-campo nombre="texto" id="wa-texto" etiqueta="Respuesta" tipo="textarea" rows="1" maxlength="4000"
                 placeholder="Escribe un mensaje o / para respuestas rápidas" class="wa-redactar-texto" />

        <x-boton icono="send-fill" descripcion="Enviar" class="wa-enviar" data-enviar />
    </div>

    <x-campo nombre="archivo" id="wa-archivo" etiqueta="Adjuntar imagen o PDF (máx. {{ config('whatsapp.max_mb_archivo') }} MB)"
             tipo="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="wa-archivo" />

    @if ($abierta->estado === EstadoConversacion::Agente)
        <p class="ayuda wa-aviso-pausa">Contestar pausa el agente en este chat.</p>   {{-- regla 5 --}}
    @endif
</form>
```

- La etiqueta "Respuesta" de `<x-campo>` se esconde con CSS (`.wa-barra .campo > label` con el
  mismo efecto que `.solo-lectores`), sin tocar el componente.
- Las opciones del menú son `<x-boton tipo="button" variante="suave">` con su icono y texto, más
  `role="menuitem"`. *Catálogo* lleva `aria-disabled="true"` y un `<span class="wa-proximamente">`.
- El contenedor del campo de archivo se esconde solo con `.redactar-activo` (regla 25).
- `whatsapp/index.blade.php`: `@push('scripts')` con `whatsapp-redactar.js?v=filemtime`, **después**
  de `respuestas-rapidas.js` y antes de `whatsapp.js`.

## Frontend

### `public/js/whatsapp-redactar.js`

- Busca `[data-redactar]`. Si no existe (ventana cerrada), no hace nada.
- Pone `.redactar-activo` en el formulario y quita `hidden` al clip (adición 1).
- **Crecer la caja** (regla 2): en `input` ajusta `height` a `scrollHeight`, con tope de 6 líneas
  calculado del `line-height`. Escucha también el `input` que dispara `respuestas-rapidas.js` al
  insertar.
- **Menú** (reglas 8 a 12 y adición 3): abrir y cerrar, teclado, clic fuera (`pointerdown` en
  `document`) y Escape.
- **Archivo** (reglas 13, 14 y 16): en `change` del campo, revisa el tipo (`file.type`) y el peso
  (`data-max-bytes`) y pinta la tira. La miniatura usa `URL.createObjectURL` y se libera con
  `revokeObjectURL` al quitarla o reemplazarla. ✕ vacía el campo (`value = ''`).
- **Botón de enviar** (reglas 19 y 20): recalcula `aria-disabled` en cada `input` y `change`. En
  `submit`, si no hay nada que mandar o el archivo no es válido, `preventDefault`. Si sí, pone el
  estado "enviando" y desactiva la caja (`readOnly`), el clip y el botón.

### `public/js/whatsapp.js`

- El Enter que envía (041) ya no revisa solo `caja.value.trim() !== ''`. Ahora también envía con la
  caja vacía si hay archivo, y nunca envía si el botón tiene `aria-disabled="true"` (regla 19).
  Sigue respetando la lista de respuestas rápidas (043).

### `public/js/respuestas-rapidas.js`

- Sin cambios de lógica. La lista ya se ancla a `.wa-redactar`. Solo cambia su posición en el CSS
  (regla 21).

### `public/css/app.css`

- `.wa-barra`: `display: flex; align-items: flex-end`, borde `--color-borde-campo` y fondo
  `--color-superficie`. Al tener foco dentro (`:focus-within`), borde `--color-primario`, igual que
  los campos del sistema.
- La caja de texto va dentro de la barra sin borde propio: `flex: 1; min-width: 0`, `resize: none`
  con `.redactar-activo` y `max-height` de 6 líneas.
- `.wa-enviar`: cuadrado, del alto de una línea de la barra, con el color principal del botón
  `principal`. `[aria-disabled="true"]` atenuado con la opacidad que ya usan los botones
  desactivados del sistema.
- `.wa-menu-adjuntar`: `position: absolute; bottom: 100%; left: 0`, fondo `--color-superficie`,
  borde y la sombra `--sombra-menu-panel`, como los menús del sistema. Cada opción a lo ancho del
  menú, con icono y texto. `.wa-proximamente` en `--color-texto-suave`.
- `.wa-adjunto`: tira encima de la barra con miniatura de 2.5rem (`object-fit: cover`), nombre con
  `text-overflow: ellipsis` y el tamaño en `--color-texto-suave`. Con error usa `--color-error` y
  `--color-error-fondo`.
- `.wa-respuestas` (043) toma la posición encima de la barra, o encima de la tira si hay archivo.
- Giro del indicador de carga con `@keyframes`, desactivado con `prefers-reduced-motion`.
- Todas las esquinas con `var(--radio)` o sin `border-radius` y todos los colores con variables de
  `:root`, como exige `EstiloUniformeTest` (reglas 3 y 6).
- En pantallas angostas (regla 23): la barra sigue en una línea y el menú no pasa del ancho de la
  barra (`max-width: calc(100vw - 2 * var(--espacio-4))`).

## Pruebas (adición 4)

Feature tests en `tests/Feature/WhatsappBarraRespuestaTest.php`, con conversación propia como en
`RespuestasRapidasTest`.

34. **La barra:** con la ventana abierta, la conversación trae `.wa-barra` con el clip
    (`aria-haspopup="menu"`), el menú `#wa-menu-adjuntar` con las opciones *Documento*, *Imagen* y
    *Catálogo* en ese orden, el botón `wa-enviar` con `aria-label="Enviar"` y el campo
    `name="archivo"`.
35. **Catálogo de adorno:** la opción *Catálogo* lleva `aria-disabled="true"` y "Próximamente".
36. **Aviso de pausa:** con la conversación en `Agente` sale "Contestar pausa el agente en este
    chat."; en `Humano`, no.
37. **Ventana cerrada:** sin `.wa-barra` y con el aviso "Solo puedes mandar una plantilla.".
38. **Enviar sigue igual** (con `Http::fake` de Meta, como en `WhatsappAgentesTest`):
    - solo texto → se envía como texto;
    - imagen JPG con pie → se envía como imagen con ese pie;
    - PDF sin texto → se envía como documento;
    - un archivo de más de 10 MB o un `.docx` → error de validación en `archivo`.

El menú, crecer la caja, la tira del archivo y el bloqueo del botón se revisan a mano en el
navegador, porque el proyecto no tiene pruebas de JavaScript.

## Orden de implementación

1. Vista y CSS de la barra con el campo de archivo visible (sin JavaScript ya funciona: regla 24),
   con las pruebas 34 a 38.
2. `whatsapp-redactar.js`: crecer la caja, menú, tira del archivo y botón de enviar.
3. Ajuste del Enter en `whatsapp.js`.
4. Revisión a mano en escritorio y celular: menú con mouse y teclado, PDF, imagen, archivo pesado,
   respuestas rápidas encima de la barra, y Enter y Mayús+Enter.

## Estado de implementación

Implementada el 2026-11-23, con las 9 pruebas (12 casos) de
`tests/Feature/WhatsappBarraRespuestaTest.php`. La suite completa pasa.

- **Enviar, fuera de la barra:** el botón de enviar va junto a la barra (`.wa-fila`), no dentro de
  ella, como en WhatsApp y como en el dibujo de la historia.
- **Clase del clip:** `wa-clip-abrir` y no `wa-clip-boton`, porque `EstiloUniformeTest` no permite
  clases propias que lleven la palabra "boton".
- **Enter con la caja vacía:** ya no mete un salto de línea. No hace nada, como en WhatsApp. Si
  `whatsapp-redactar.js` no cargó, Enter vuelve a revisar solo el texto, como antes.
- **"Archivo quitado":** la tira se queda un momento con ese aviso, solo para lectores de pantalla, y
  luego se oculta.
- **Orden de los scripts:** `respuestas-rapidas.js`, `whatsapp-redactar.js` y `whatsapp.js`.
- **Pendiente:** revisión a mano en escritorio y celular (paso 4 del orden de implementación).
