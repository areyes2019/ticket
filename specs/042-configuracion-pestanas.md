# Spec: Configuración en pestañas

> **Estado: implementada** el 2026-11-22 (ver "Estado de implementación" al final). El usuario
> aprobó el 2026-11-22, sin cambios, las 19 asunciones funcionales y las 4 adiciones técnicas (la
> adición 1 ajusta la asunción 5).

**Modifica:**

- La vista `configuracion/edit.blade.php`: sus secciones dejan de ir una debajo de otra y pasan a
  pestañas.
- Las redirecciones que regresan a Configuración (`ConfiguracionController`,
  `ConfiguracionWhatsappController` y `DatoBancarioController`): todas llevan el ancla de su
  pestaña.
- No cambia ningún campo, botón, permiso ni regla de
  [019-pedidos-mostrador.md](019-pedidos-mostrador.md) (mensajes),
  [026-formato-pdf-documentos.md](026-formato-pdf-documentos.md) (emisor),
  [027-datos-bancarios-cotizacion.md](027-datos-bancarios-cotizacion.md) (datos bancarios) ni [041-agentes-whatsapp.md](041-agentes-whatsapp.md) (WhatsApp).

## Historia de usuario

Como usuario quiero una experiencia más cómoda en la parte de Configuración
(`/configuracion`). Tal vez navegación por pestañas o algo así. En este momento hay que bajar mucho
para recorrer la página y quiero algo más práctico.

```
Configuración
┌─────────────────────────────────────────────────────────────────────────────┐
│ ✔ Datos del emisor guardados.                                               │
└─────────────────────────────────────────────────────────────────────────────┘
 🏢 Emisor •   🏦 Datos bancarios   💬 WhatsApp •   ✉ Mensajes de pedidos
 ━━━━━━━━━━
┌─────────────────────────────────────────────────────────────────────────────┐
│ Datos del emisor                                                            │
│ ...solo el contenido de la pestaña abierta...                               │
└─────────────────────────────────────────────────────────────────────────────┘
        • = señal de advertencia (emisor incompleto / WhatsApp sin conectar)
```

## Objetivo / Alcance

Partir la página de Configuración en pestañas para ver una sección a la vez, sin recargar la
página al cambiar de pestaña.

- Las pestañas se arman en la vista con Blade. Un archivo JavaScript pequeño
  (`public/js/configuracion-pestanas.js`) solo muestra y oculta.
- Sin JavaScript, la página se ve como hoy (adición 2).

**Fuera de alcance:** cambiar el contenido de las secciones, guardar todo con un solo botón,
avisar de cambios sin guardar, autoguardado, páginas separadas por sección y buscador de ajustes.

## Reglas de negocio

### Las pestañas (asunciones 1, 2, 3, 15 y 19)

1. La página tiene pestañas horizontales arriba, con el mismo aspecto que las de Contabilidad
   (`.pestanas`, `tesoreria/_pestanas.blade.php`).
2. Son 4, en este orden, cada una con icono:

   | Pestaña | Ancla | Icono | Contenido |
   |---|---|---|---|
   | Emisor | `#emisor` | `building` | `configuracion._emisor` |
   | Datos bancarios | `#datos-bancarios` | `bank` | `configuracion._datos-bancarios` |
   | WhatsApp | `#whatsapp` | `whatsapp` | `configuracion._whatsapp` |
   | Mensajes de pedidos | `#mensajes` | `envelope` | el formulario con los dos mensajes |

3. Los dos mensajes de pedidos (ticket y "listo") van juntos en la pestaña *Mensajes de pedidos*,
   con su botón **Guardar** común, como hoy.

### Qué se ve (asunciones 4, 6, 17 y 18)

4. Solo se ve el contenido de una pestaña a la vez. Las demás quedan ocultas hasta que se les da
   clic.
5. Las pestañas *Emisor*, *Datos bancarios* y *WhatsApp* solo existen para el administrador
   (`can:editar-emisor`), igual que hoy sus secciones.
6. Un usuario que no es administrador solo tendría *Mensajes de pedidos*. Con una sola pestaña no
   se dibuja la barra de pestañas: se ve el contenido directo.
7. Los mensajes de éxito y error (`documentos._mensajes`) se siguen viendo arriba, encima de las
   pestañas, sea cual sea la pestaña abierta.
8. El contenido de cada sección no cambia: mismos campos, botones, validaciones y permisos.

### Cuál pestaña se abre (asunciones 5, 7, 8 y 9, y adición 1)

9. Al abrir la página se elige la pestaña así, en este orden:
   1. la del **ancla de la dirección**, si es una de la tabla de la regla 2 y el usuario la ve
      (`/configuracion#whatsapp` abre *WhatsApp*);
   2. si no, **la última pestaña que el usuario abrió** en ese navegador (adición 1);
   3. si no, **la primera** que el usuario ve: *Emisor* para el administrador.
10. Al guardar en cualquier pestaña, la página vuelve abierta en esa misma pestaña. Para eso cada
    redirección a Configuración lleva el ancla de su pestaña (regla 9.1):
    - emisor → `#emisor` (ya lo hace);
    - WhatsApp: guardar, probar, registrar y actualizar plantillas → `#whatsapp` (ya lo hace);
    - datos bancarios: crear, editar, borrar, mostrar/ocultar y mover → `#datos-bancarios` (ya lo
      hace), y el botón **Cancelar** del formulario de banco también (ya lo hace);
    - mensajes de pedidos → `#mensajes` (**nuevo**: hoy redirige sin ancla).
11. Los enlaces que ya mandan a una sección (`#emisor` desde el aviso de emisor incompleto y
    `#whatsapp` desde la bandeja de WhatsApp) abren su pestaña sin cambios.
12. Cada vez que se abre una pestaña, la dirección cambia a su ancla sin recargar ni brincar en la
    página (`history.replaceState`). Así se puede copiar y mandar el enlace directo.

### Cambiar de pestaña (asunciones 10, 11 y 12)

13. Cambiar de pestaña es instantáneo: no recarga la página ni pide nada al servidor.
14. Lo que se escribió en una pestaña y no se guardó se queda en su campo mientras no se salga de
    la página, pero no se guarda solo. Cada pestaña guarda con su propio botón, como hoy.
15. No hay aviso de "tienes cambios sin guardar" al cambiar de pestaña.
16. **(Decisión tomada al redactar)** Guardar en una pestaña solo envía el formulario de esa
    sección. Si se cambió algo en otra pestaña sin guardar, eso se pierde al recargar después del
    guardado, igual que hoy entre secciones.

### Señales de advertencia (asunciones 13 y 14)

17. La pestaña *Emisor* lleva una señal (un punto de color de advertencia) cuando
    `$emisor->estaCompleto()` es falso, la misma condición de la alerta amarilla que ya tiene la
    sección.
18. La pestaña *WhatsApp* lleva la misma señal cuando el administrador **no tiene número
    conectado** (`auth()->user()->whatsappNumero` es nulo).
19. La señal tiene un texto para lectores de pantalla ("faltan datos" / "sin conectar") y un
    `title` con el mismo texto al pasar el mouse.

### En el celular (asunción 16)

20. Si las pestañas no caben a lo ancho, la barra se desliza de lado en una sola línea, sin
    amontonarse en varias filas y sin que la página entera se mueva de lado.

## Adición técnica 1: recordar la última pestaña

21. Cada vez que el usuario abre una pestaña, el navegador guarda su ancla en `localStorage`
    (clave `configuracion.pestana`).
22. La pestaña guardada solo se usa si sigue siendo una que el usuario ve (regla 9.2). Si no, o si
    no hay nada guardado, se abre la primera.
23. Leer y escribir `localStorage` va dentro de `try/catch`. En una ventana privada, o con el
    almacenamiento bloqueado, simplemente no recuerda nada y abre la primera pestaña.
24. Lo guardado vive solo en ese navegador: otro equipo o navegador abre en *Emisor* la primera vez.

## Adición técnica 2: sin JavaScript se ve todo

25. El HTML que manda el servidor tiene **todas las secciones visibles**, una debajo de otra, como
    hoy. Las pestañas se dibujan pero son enlaces normales a las anclas (`#emisor`, ...), así que
    sin JavaScript funcionan como un índice que baja a cada sección.
26. Las secciones se ocultan solo cuando el JavaScript ya cargó: el script pone una clase en el
    contenedor (`.pestanas-activas`) y solo con esa clase el CSS esconde las secciones no
    elegidas. Si el script falla o no carga, nada queda escondido.

## Adición técnica 3: teclado y lectores de pantalla

27. Con JavaScript activo, la barra sigue el patrón de pestañas accesibles (WAI-ARIA "tabs"):
    - la barra es `role="tablist"`, cada pestaña `role="tab"` con `aria-selected` y
      `aria-controls`, y cada sección `role="tabpanel"` con `aria-labelledby`;
    - solo la pestaña abierta entra con **Tab** (`tabindex="0"`; las demás `-1`), y de ahí **Tab**
      pasa directo al contenido;
    - las flechas **←** y **→** pasan a la pestaña anterior o siguiente y la abren, dando la vuelta
      en los extremos; **Inicio** y **Fin** van a la primera y a la última.
28. El lector de pantalla anuncia "pestaña 2 de 4, Datos bancarios, seleccionada" por los roles
    anteriores, sin texto extra.
29. Sin JavaScript (adición 2) no se ponen esos roles: los enlaces se quedan como enlaces normales.

## Backend (Laravel)

### `ConfiguracionController`

- `edit()` pasa además a la vista `pestanas`: la lista de pestañas que **este usuario** ve, en el
  orden de la regla 2, cada una con `ancla`, `titulo`, `icono` y `advertencia` (texto o `null`,
  reglas 17 y 18). Las tres primeras solo si `Gate::allows('editar-emisor')`.
- `update()` redirige a `route('configuracion.edit').'#mensajes'` (regla 10).

### Vistas

- `configuracion/edit.blade.php`:

```blade
<h1>Configuración</h1>

@include('documentos._mensajes')

<div class="config-pestanas" data-pestanas>
    @if (count($pestanas) > 1)
        @include('configuracion._pestanas')
    @endif

    @can('editar-emisor')
        <section id="emisor" class="config-panel">@include('configuracion._emisor')</section>
        ...
    @endcan

    <section id="mensajes" class="config-panel">
        <form method="POST" action="{{ route('configuracion.update') }}"> ... </form>
    </section>
</div>
```

- Las anclas pasan de las `<x-card>` a los contenedores de cada pestaña: `_emisor`, `_datos-bancarios` y `_whatsapp`
  dejan de llevar `id` en su `<x-card>` para no repetirlo.
- `configuracion/_pestanas.blade.php`: `<nav class="pestanas" aria-label="Secciones de
  Configuración">` con un `<a href="#ancla">` por pestaña, con icono, título y, si hay
  `advertencia`, `<span class="pestana-aviso" title="...">` con el texto oculto para lectores
  (`.solo-lectores`, que ya existe en `app.css`).
- `@push('scripts')` con `configuracion-pestanas.js` y su `?v=filemtime`, como los demás scripts.

## Frontend

### `public/js/configuracion-pestanas.js`

- Al cargar busca `[data-pestanas]`. Si no hay barra (usuario con una sola pestaña) no hace nada.
- Pone los roles de la regla 27 y la clase `.pestanas-activas`.
- Elige la pestaña inicial con la regla 9 y la abre sin brincar en la página (al cargar con ancla,
  `scrollTo(0, 0)` después de abrirla, para no quedar a media página).
- Clic en una pestaña: `preventDefault`, abre la pestaña, `history.replaceState` con su ancla
  (regla 12) y la guarda (adición 1).
- Escucha `hashchange` para que un enlace con ancla dentro de la misma página también cambie de
  pestaña.
- Teclado según la regla 27.

### `public/css/app.css`

- `.config-pestanas.pestanas-activas .config-panel:not(.activa) { display: none; }` (adición 2).
- En `.config-pestanas .pestanas`: una sola línea con desplazamiento horizontal
  (`flex-wrap: nowrap; overflow-x: auto;`) y las pestañas sin partirse (`white-space: nowrap`),
  regla 20. No se toca `.pestanas` de Contabilidad.
- La pestaña abierta usa el mismo estilo de `aria-current="page"`, extendido a
  `[aria-selected="true"]`.
- `.pestana-aviso`: punto pequeño con el color de advertencia que ya existe (variables de 003), sin
  colores sueltos nuevos.
- Foco visible en las pestañas (`:focus-visible`), con el mismo estilo de foco del resto del
  sistema.

## Pruebas (adición 4)

Feature tests en `tests/Feature/ConfiguracionPestanasTest.php`.

30. **Administrador:** `GET /configuracion` muestra las 4 pestañas en el orden de la regla 2, con
    sus enlaces `#emisor`, `#datos-bancarios`, `#whatsapp` y `#mensajes`, y las 4 secciones con sus
    `id`.
31. **Usuario normal:** ve la sección `#mensajes` y no ve la barra de pestañas ni las secciones
    `#emisor`, `#datos-bancarios` y `#whatsapp`.
32. **Regreso a la pestaña correcta al guardar:**
    - guardar los mensajes redirige a `/configuracion#mensajes`;
    - guardar el emisor redirige a `/configuracion#emisor`;
    - guardar WhatsApp redirige a `/configuracion#whatsapp`;
    - crear un banco redirige a `/configuracion#datos-bancarios`.
33. **Señales:** con el emisor incompleto, la pestaña *Emisor* lleva `pestana-aviso`; con el emisor
    completo, no. Sin número de WhatsApp, la pestaña *WhatsApp* la lleva; con número, no.

## Estado de implementación

Implementada el 2026-11-22, con las 4 pruebas de `tests/Feature/ConfiguracionPestanasTest.php`.

- **Contenedores `<div>`:** cada pestaña es un `<div class="config-panel">` y no un `<section>`,
  porque `<x-card>` ya pinta un `<section class="tarjeta">` adentro.
- **Punto de aviso cuadrado:** `.pestana-aviso` es un cuadrito sin `border-radius`, porque
  `EstiloUniformeTest` no permite esquinas redondeadas en `app.css`. Usa `--color-advertencia`.
- **Barra:** lleva `data-pestanas-barra` y cada enlace `data-pestana` e `id="pestana-<ancla>"`,
  que el script usa para `aria-labelledby`.
