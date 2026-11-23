# Spec: Indicaciones de los agentes de WhatsApp por secciones

> **Estado: implementada el 2026-11-23, falta revisarla a mano en el navegador y correr la
> migración en producción** (ver "Estado de implementación" al final). El usuario aprobó sin cambios las 21
> asunciones funcionales y las 7 adiciones técnicas.

**Modifica:**

- [041-agentes-whatsapp.md](041-agentes-whatsapp.md): la regla 81 y las columnas
  `instrucciones_ventas` / `instrucciones_facturacion` de `whatsapp_numeros`. El cuadro único de
  indicaciones de cada agente se cambia por secciones y una lista de preguntas frecuentes. **No
  cambia** cómo trabaja el agente: sigue recibiendo un texto bajo "## Indicaciones del negocio",
  después de las reglas fijas y con caché.
- [042-configuracion-pestanas.md](042-configuracion-pestanas.md): la regla 40, bloque **Agentes**.
  Los dos agentes dejan de ir lado a lado y cada uno pasa a ser su propio bloque.

## Historia de usuario

Las indicaciones del agente de ventas son hoy un solo cuadro de texto. Quiero reorganizarlo en
secciones y que, al llegar a Preguntas frecuentes, una función en JavaScript vaya abriendo más
campos conforme se requieran.

```
Hoy:
 ☑ Agente de ventas encendido
 Indicaciones para el agente de ventas
 ┌──────────────────────────────┐
 │                              │
 └──────────────────────────────┘
 Políticas, tiempos de entrega, preguntas frecuentes, tono.

Nuevo:
 Agente de ventas            ☑ Agente de ventas encendido
 (texto de ayuda)            ┌ Formas de pago ───────┐ ┌ Entregas y envíos ────┐
                             └───────────────────────┘ └───────────────────────┘
                             ┌ Cambios y devoluciones┐ ┌ Tono y forma de hablar┐
                             └───────────────────────┘ └───────────────────────┘
                             ▾ Preguntas frecuentes
                               Pregunta ┌─────────────────────────────────┐ ✕
                               Respuesta┌─────────────────────────────────┐
                               Pregunta ┌─────────────────────────────────┐ ✕   ← se abre sola
                               Respuesta┌─────────────────────────────────┐
                             ▸ Otras indicaciones
                             2 340 caracteres en total
```

## Objetivo / Alcance

Cambiar el cuadro único de indicaciones de cada agente (ventas y facturación) por secciones con
título y una lista de preguntas frecuentes que crece sola mientras se escribe.

- Backend: dos columnas JSON nuevas en `whatsapp_numeros`, validación nueva y una clase que arma el
  texto para el agente.
- Frontend: vista, CSS y un archivo JavaScript nuevo, `public/js/indicaciones-agente.js`. Sin
  librerías nuevas.
- Respeta el estilo uniforme del sistema: esquinas rectas, colores con variables y `<x-campo>` y
  `<x-boton>` para todos los campos y botones (`EstiloUniformeTest`).

**Fuera de alcance:** reordenar preguntas arrastrándolas, importar preguntas desde un archivo,
secciones creadas por el usuario, vista previa del texto que recibe el agente y cambios en las
reglas fijas (`resources/prompts/whatsapp/*.md`).

## Reglas de negocio

### Secciones (asunciones 1 a 5)

1. Cada agente tiene sus secciones, en este orden fijo:

   | Agente | Clave | Título | Ejemplo (placeholder) |
   |---|---|---|---|
   | Ventas | `pago` | Formas de pago | Transferencia, tarjeta y efectivo. Pedimos 50 % de anticipo en pedidos grandes. |
   | Ventas | `entregas` | Entregas y envíos | Entregamos en 3 a 5 días hábiles; envíos a todo México por paquetería. |
   | Ventas | `devoluciones` | Cambios y devoluciones | Los sellos personalizados no tienen cambio; si llega dañado lo reponemos. |
   | Ventas | `tono` | Tono y forma de hablar | Trata de "tú", con amabilidad y sin tecnicismos. |
   | Ventas | `preguntas` | Preguntas frecuentes | (lista de pares, reglas 6 a 13) |
   | Ventas | `otras` | Otras indicaciones | Lo que no quepa en las secciones anteriores. |
   | Facturación | `requisitos` | Requisitos para facturar | RFC, razón social, código postal, régimen fiscal y uso del CFDI. |
   | Facturación | `plazos` | Plazos para pedir factura | Se puede facturar dentro del mismo mes de la compra. |
   | Facturación | `tono` | Tono y forma de hablar | Trata de "tú", con amabilidad y sin tecnicismos. |
   | Facturación | `preguntas` | Preguntas frecuentes | (lista de pares) |
   | Facturación | `otras` | Otras indicaciones | Lo que no quepa en las secciones anteriores. |

2. *Otras indicaciones* es un cuadro de texto libre para lo que no quepa en las demás.
3. Cada sección (salvo *Preguntas frecuentes*) es un cuadro de texto con su ejemplo en gris
   (`placeholder`).
4. Todas las secciones son opcionales. Una sección vacía, o con solo espacios, no se le manda al
   agente.
5. El agente de facturación usa el mismo acomodo que el de ventas, con sus propias secciones.

### Preguntas frecuentes (asunciones 6 a 13)

6. Cada pregunta frecuente es un **par**: un campo de una línea para la **pregunta** y un cuadro de
   3 renglones para la **respuesta**.
7. Al abrir la pantalla aparecen las preguntas guardadas y, debajo, **un par vacío** (adición 2).
8. En cuanto se escribe algo en el último par, se abre **otro par vacío** debajo, sin pulsar ningún
   botón. Siempre queda un lugar libre al final.
9. Cada par tiene un botón **✕** para quitarlo.
10. El tope es de **30 preguntas** por agente. Con 30 pares llenos no se abre otro y aparece el
    aviso "Llegaste al máximo de 30 preguntas frecuentes." Si ya hay 30 guardadas, el servidor no
    pinta el par vacío.
11. Un par con pregunta y sin respuesta, o al revés, no se guarda. El formulario regresa con el par
    marcado en rojo y el mensaje "Falta la respuesta." o "Falta la pregunta.", según el caso.
12. Los pares totalmente vacíos se ignoran al guardar. Sin JavaScript, para quitar una pregunta se
    vacían sus dos campos y se guarda.
13. Las preguntas se guardan y se le mandan al agente en el orden en que aparecen en pantalla.

### Acomodo en pantalla (asunciones 14 a 16)

14. El bloque **Agentes** de 042 se parte en dos bloques (`.config-bloque`), **Agente de ventas** y
    **Agente de facturación**, uno debajo del otro. Cada uno lleva a la izquierda su título y su
    explicación, y a la derecha, en este orden:
    - la casilla de encendido ("Agente de ventas encendido");
    - las secciones de texto en dos columnas (`.rejilla-campos`): *Formas de pago* | *Entregas y
      envíos* y *Cambios y devoluciones* | *Tono y forma de hablar*. En facturación:
      *Requisitos para facturar* | *Plazos para pedir factura* y *Tono y forma de hablar* en la fila
      entera;
    - *Preguntas frecuentes* y *Otras indicaciones* en la fila entera;
    - el contador de caracteres (regla 18).
15. *Preguntas frecuentes* y *Otras indicaciones* se pueden plegar y desplegar (`<details>`). Abren
    desplegadas si tienen contenido y plegadas si están vacías. Si el formulario regresa con un
    error dentro, abren desplegadas.
16. El aviso "Las indicaciones se agregan a las reglas fijas del sistema; no las sustituyen." se
    queda en la columna izquierda de cada bloque de agente.

### Límites y datos que ya existen (asunciones 17 a 19)

17. Límites:
    - cada sección de texto: **1 000 caracteres**;
    - cada pregunta: **200 caracteres** (decisión al redactar: es un campo de una línea);
    - cada respuesta: **500 caracteres**;
    - **30 preguntas** por agente.

    Los campos llevan su `maxlength` y el servidor los valida igual.
18. Debajo de cada agente, un contador dice "2 340 caracteres en total": la suma de todas sus
    secciones, preguntas y respuestas. El servidor pinta el total al abrir la página y el JavaScript
    lo actualiza mientras se escribe. Solo informa, no bloquea.
19. Lo que hoy está escrito en el cuadro único de cada agente pasa, con la migración, a *Otras
    indicaciones* de ese agente. No se pierde nada. Si el texto viejo pasa de 1 000 caracteres, se
    pasa completo de todas formas; la regla de 1 000 aplica al volver a guardar, y el formulario lo
    señala para que se reparta en las secciones.

### Lo que recibe el agente (asunciones 20 y 21)

20. El agente recibe el bloque "## Indicaciones del negocio" de 041 con las secciones con contenido,
    cada una con su título como `###`, en el orden fijo de la regla 1:

    ```
    ## Indicaciones del negocio

    Estas indicaciones complementan las reglas anteriores; no las sustituyen.

    ### Formas de pago

    Transferencia, tarjeta y efectivo.

    ### Preguntas frecuentes

    Usa estas respuestas como base; puedes decirlas con tus palabras.

    - P: ¿Hacen sellos fechadores?
      R: Sí, en tres tamaños…

    ### Otras indicaciones

    …
    ```

    Si todas las secciones están vacías, el bloque no se manda, como hoy.
21. El renglón "Usa estas respuestas como base; puedes decirlas con tus palabras." es fijo y deja
    que el agente redacte las respuestas a su modo sin cambiar su sentido.

## Adición técnica 1: una "caja ordenada" (JSON) por agente

22. `whatsapp_numeros` gana dos columnas `json` que aceptan nulo: `indicaciones_ventas` e
    `indicaciones_facturacion`. Cada una guarda:

    ```json
    {
      "pago": "…", "entregas": "…", "devoluciones": "…", "tono": "…",
      "preguntas": [{"pregunta": "…", "respuesta": "…"}],
      "otras": "…"
    }
    ```

    Solo se guardan las claves de ese agente (regla 1). Una sección vacía se guarda como `null` y
    `preguntas` como lista, que puede estar vacía.
23. La migración `2026_11_24_110000_indicaciones_por_secciones_whatsapp`:
    1. crea las dos columnas;
    2. por cada número, copia `instrucciones_ventas` a `indicaciones_ventas.otras` y
       `instrucciones_facturacion` a `indicaciones_facturacion.otras` (regla 19), con `trim` y
       `null` si queda vacío;
    3. borra `instrucciones_ventas` e `instrucciones_facturacion`.

    El `down()` hace lo contrario: crea las columnas de texto y las llena con el texto armado de la
    regla 20, sin el encabezado.
24. Agregar una sección en el futuro es agregar una entrada al catálogo de la regla 1
    (`AgenteWhatsapp::secciones()`). La base no cambia.

## Adición técnica 2: sin JavaScript sigue funcionando

25. El servidor pinta las preguntas guardadas y **un** par vacío al final, salvo que ya haya 30
    (regla 10). Sin JavaScript se agrega una pregunta por cada vez que se guarda.
26. Los botones ✕ se pintan ocultos (`hidden`). Solo el script los muestra. Sin JavaScript, una
    pregunta se quita vaciando sus campos (regla 12).
27. `<details>` se pliega y despliega sin JavaScript.

## Adición técnica 3: un molde escondido

28. Cada lista de preguntas trae un `<template data-molde-pregunta>` con un par vacío ya
    diseñado, con el mismo parcial Blade que los pares guardados (`_pregunta-frecuente.blade.php`).
    En los `name` e `id` usa la marca `__i__` en lugar del número.
29. El script copia el molde, cambia `__i__` por el número que le toca y lo coloca al final. El
    diseño vive solo en Blade.
30. El contenido de un `<template>` no se envía con el formulario.

## Adición técnica 4: renumerar

31. Cada vez que se agrega o quita un par, el script renumera **todos** los pares de arriba abajo
    (0, 1, 2…) en `name`, `id`, `for` y la descripción del botón ✕ (regla 34). Así llegan al
    servidor en orden, sin huecos ni números repetidos.
32. Antes de validar, el servidor quita los pares totalmente vacíos y vuelve a numerar la lista
    (`array_values`). Si el formulario regresa con error, el número de cada error coincide con el
    par en pantalla.

## Adición técnica 5: teclado y lectores de pantalla

33. Al quitar un par, el foco pasa a la pregunta del par que quedó en su lugar o, si era el último,
    a la del anterior.
34. Cada par es un `<fieldset>` con `<legend class="solo-lectores">Pregunta 3</legend>`. Su botón ✕
    es `<x-boton tipo="button" variante="suave" icono="x-lg" descripcion="Quitar la pregunta 3">`.
35. Cada lista tiene una zona `aria-live="polite"` (`.solo-lectores`) donde el script escribe
    "Pregunta agregada.", "Pregunta quitada." y el aviso de las 30 preguntas (regla 10).

## Adición técnica 6: el mismo texto en cada turno (caché)

36. El texto de la regla 20 lo arma una sola clase, `App\Services\Whatsapp\TextoIndicaciones`,
    siempre en el orden fijo del catálogo y con el mismo formato. No mete fechas, horas ni nada que
    cambie entre turnos.
37. Al guardar, cada texto se limpia: `trim` y saltos de línea `\r\n` cambiados por `\n`. Así lo
    guardado ya es el texto final y armarlo dos veces da lo mismo.
38. `WhatsappNumero::instrucciones(AgenteWhatsapp)` sigue existiendo y regresa el texto armado (o
    `null`). `OrquestadorTurno` no cambia y la caché de 041 sigue funcionando.

## Backend (Laravel)

### `App\Enums\AgenteWhatsapp`

- `secciones(): array<string, array{titulo: string, ejemplo: string}>` con el catálogo de la
  regla 1 en orden, incluido `preguntas`.
- `columnaIndicaciones(): string` → `indicaciones_ventas` / `indicaciones_facturacion`.

### `App\Models\WhatsappNumero`

- `$fillable`: quita `instrucciones_*` y agrega `indicaciones_ventas` e `indicaciones_facturacion`.
- Casts `array` para las dos columnas.
- `indicaciones(AgenteWhatsapp): array` regresa la caja con todas las claves de ese agente (las que
  falten en `null` y `preguntas` en `[]`).
- `instrucciones(AgenteWhatsapp): ?string` → `TextoIndicaciones::armar($agente, $this->indicaciones($agente))`.

### `App\Services\Whatsapp\TextoIndicaciones`

- `armar(AgenteWhatsapp, array $caja): ?string`: arma las secciones de la regla 20, sin el
  encabezado "## Indicaciones del negocio" (lo sigue poniendo `OrquestadorTurno`). Si no hay nada,
  regresa `null`.
- `limpiar(?string): ?string`: regla 37. La usan el controlador y la migración.
- `caracteres(array $caja): int`: el total de la regla 18.

### `ConfiguracionWhatsappController::update`

- Antes de validar, por cada agente: quita los pares vacíos de `indicaciones_{agente}.preguntas` y
  renumera (regla 32), con `$request->merge`.
- Reglas, por cada agente (`{a}` = `indicaciones_ventas` o `indicaciones_facturacion`):

  ```php
  '{a}' => ['nullable', 'array'],
  '{a}.{seccion}' => ['nullable', 'string', 'max:1000'],          // cada sección de texto del agente
  '{a}.preguntas' => ['nullable', 'array', 'max:30'],
  '{a}.preguntas.*.pregunta' => ['required_with:{a}.preguntas.*.respuesta', 'nullable', 'string', 'max:200'],
  '{a}.preguntas.*.respuesta' => ['required_with:{a}.preguntas.*.pregunta', 'nullable', 'string', 'max:500'],
  ```

  Con los mensajes "Falta la pregunta.", "Falta la respuesta." y "Llegaste al máximo de 30
  preguntas frecuentes."
- Guarda cada caja con `TextoIndicaciones::limpiar` aplicado a cada texto y solo con las claves del
  catálogo.

### `<x-campo>`

- Gana la propiedad opcional `clave` (por defecto, `$nombre`): la ruta con puntos
  (`indicaciones_ventas.preguntas.2.respuesta`) que usan `old()` y `$errors`. Hoy el componente
  usa `$nombre` para los dos, y con corchetes no encuentra nada.

## Vista

`configuracion/_whatsapp.blade.php`: el bloque **Agentes** se cambia por un parcial por agente.

```blade
@foreach (App\Enums\AgenteWhatsapp::cases() as $agente)
    @include('configuracion._whatsapp-agente', ['agente' => $agente, 'numero' => $numero])
@endforeach
```

`configuracion/_whatsapp-agente.blade.php`:

```blade
@php
    $columna = $agente->columnaIndicaciones();
    $caja = $numero?->indicaciones($agente) ?? [];
    $preguntas = old("{$columna}.preguntas", $caja['preguntas'] ?? []);
@endphp

<section class="config-bloque" data-indicaciones>
    <div class="config-bloque-texto">
        <h3>Agente de {{ Str::lower($agente->etiqueta()) }}</h3>
        <p class="ayuda">Las indicaciones se agregan a las reglas fijas del sistema; no las sustituyen.</p>
    </div>
    <div class="rejilla-campos">
        <div class="ocupa-fila">
            <x-campo nombre="{{ $agente->value }}_activo" etiqueta="Agente de {{ Str::lower($agente->etiqueta()) }} encendido" tipo="checkbox" … />
        </div>

        {{-- secciones de texto (regla 14), cada una: --}}
        <x-campo nombre="{{ $columna }}[{{ $clave }}]" clave="{{ $columna }}.{{ $clave }}" id="{{ $columna }}-{{ $clave }}"
                 etiqueta="{{ $seccion['titulo'] }}" tipo="textarea" rows="3" maxlength="1000"
                 placeholder="{{ $seccion['ejemplo'] }}" :valor="$caja[$clave] ?? null" />

        <details class="ocupa-fila wa-indicaciones-plegable" @if (count($preguntas) || $errors->has($columna.'.preguntas*')) open @endif>
            <summary>Preguntas frecuentes</summary>
            <div class="wa-preguntas" data-preguntas data-max="30" data-columna="{{ $columna }}">
                @foreach ($preguntas as $i => $par)
                    @include('configuracion._pregunta-frecuente', [...])
                @endforeach
                @if (count($preguntas) < 30)
                    @include('configuracion._pregunta-frecuente', ['i' => count($preguntas), 'par' => []])
                @endif
            </div>
            <template data-molde-pregunta>
                @include('configuracion._pregunta-frecuente', ['i' => '__i__', 'par' => []])
            </template>
            <p class="solo-lectores" aria-live="polite" data-anuncio></p>
            <p class="ayuda" data-tope hidden>Llegaste al máximo de 30 preguntas frecuentes.</p>
        </details>

        <details class="ocupa-fila wa-indicaciones-plegable" …>
            <summary>Otras indicaciones</summary>
            <x-campo nombre="{{ $columna }}[otras]" … rows="4" maxlength="1000" />
        </details>

        <p class="ayuda ocupa-fila" data-contador>{{ number_format($total) }} caracteres en total</p>
    </div>
</section>
```

`configuracion/_pregunta-frecuente.blade.php`:

```blade
<fieldset class="wa-pregunta" data-pregunta>
    <legend class="solo-lectores">Pregunta {{ is_int($i) ? $i + 1 : '' }}</legend>
    <x-campo nombre="{{ $columna }}[preguntas][{{ $i }}][pregunta]" clave="{{ $columna }}.preguntas.{{ $i }}.pregunta"
             id="{{ $columna }}-p{{ $i }}-pregunta" etiqueta="Pregunta" maxlength="200" :valor="$par['pregunta'] ?? null" />
    <x-campo nombre="{{ $columna }}[preguntas][{{ $i }}][respuesta]" clave="{{ $columna }}.preguntas.{{ $i }}.respuesta"
             id="{{ $columna }}-p{{ $i }}-respuesta" etiqueta="Respuesta" tipo="textarea" rows="3" maxlength="500" :valor="$par['respuesta'] ?? null" />
    <x-boton tipo="button" variante="suave" icono="x-lg" descripcion="Quitar la pregunta {{ is_int($i) ? $i + 1 : '' }}" data-quitar hidden />
</fieldset>
```

- `configuracion/edit.blade.php`: `@push('scripts')` con `indicaciones-agente.js?v=filemtime`,
  después de `configuracion-pestanas.js`.

## Frontend

### `public/js/indicaciones-agente.js`

- Busca cada `[data-preguntas]`. Si no hay ninguno, no hace nada.
- Muestra los botones ✕ (adición 2).
- **Abrir pares** (regla 8): en `input` dentro del último par, si alguno de sus dos campos tiene
  texto y hay menos de `data-max` pares, copia el molde (adición 3), renumera y anuncia "Pregunta
  agregada.". Al llegar al tope muestra `[data-tope]` y lo anuncia (regla 10).
- **Quitar** (regla 9): en clic de `[data-quitar]`, quita el par, renumera, mueve el foco
  (regla 33) y anuncia "Pregunta quitada.". Si no queda ningún par vacío al final, copia uno.
- **Renumerar** (adición 4): recorre los pares y cambia el número en `name`, `id`, `for`, el
  `<legend>` y el `aria-label` del ✕.
- **Contador** (regla 18): en `input` dentro de `[data-indicaciones]`, suma la longitud de todos los
  `textarea` e `input` de texto del bloque y actualiza `[data-contador]` con `toLocaleString('es-MX')`.

### `public/css/app.css`

- Se quita `.wa-agente`, que ya no se usa.
- `.wa-indicaciones-plegable > summary`: el mismo peso y tamaño que la etiqueta de un campo, con el
  cursor de mano.
- `.wa-pregunta`: sin borde de `fieldset`, en rejilla de dos columnas (campos | ✕): pregunta y
  respuesta a la izquierda y ✕ arriba a la derecha. Los pares se separan con una línea
  `--color-borde`.
- Con error (`.campo-error`) se usan los colores de error del sistema, como en los demás campos.
- Todas las esquinas con `var(--radio)` y todos los colores con variables de `:root`
  (`EstiloUniformeTest`).
- En pantallas angostas, una columna (regla 36 de 042) y el ✕ en su renglón.

## Pruebas (adición 7)

Feature tests en `tests/Feature/WhatsappIndicacionesTest.php`, con el administrador y el número de
`WhatsappAgentesTest`.

39. **Guardar:** secciones y dos preguntas → la caja queda con esas claves, los textos limpios y las
    preguntas en orden.
40. **Par incompleto:** pregunta sin respuesta → error "Falta la respuesta." en
    `indicaciones_ventas.preguntas.0.respuesta`, y al revés "Falta la pregunta.".
41. **Pares vacíos:** pares vacíos al principio, en medio y al final → se guardan solo los llenos, en
    orden y numerados desde 0.
42. **Límites:** 31 preguntas, una sección de 1 001 caracteres, una pregunta de 201 o una respuesta
    de 501 → error de validación.
43. **Texto viejo:** la migración pasa `instrucciones_ventas` a `indicaciones_ventas.otras` y
    `instrucciones_facturacion` a `indicaciones_facturacion.otras`, completos.
44. **Lo que recibe el agente:** `TextoIndicaciones::armar` pone las secciones con su `###` en el
    orden fijo, omite las vacías, pone las preguntas como "- P: … / R: …" con el renglón de la
    regla 21 y regresa `null` si todo está vacío.
45. **Caché:** con los mismos datos, `armar` da exactamente el mismo texto dos veces, y lo guardado
    con `\r\n` arma lo mismo que con `\n`.
46. **Pantalla:** la pestaña WhatsApp muestra los dos bloques de agente, las preguntas guardadas más
    un par vacío, el `<template data-molde-pregunta>` y el contador. Con 30 guardadas no hay par
    vacío.

Además se ajustan las pruebas de `WhatsappAgentesTest` que usan `instrucciones_ventas` para que
usen `indicaciones_ventas`.

Abrir pares solos, renumerar, quitar, mover el foco y el contador se revisan a mano en el
navegador, porque el proyecto no tiene pruebas de JavaScript.

## Orden de implementación

1. Migración, catálogo en `AgenteWhatsapp`, modelo y `TextoIndicaciones`, con las pruebas 43 a 45.
2. Controlador y la propiedad `clave` de `<x-campo>`, con las pruebas 39 a 42.
3. Vista y CSS (funciona sin JavaScript: adición 2), con la prueba 46.
4. `indicaciones-agente.js`.
5. Suite completa, `EstiloUniformeTest` incluida.
6. Revisión a mano en escritorio y celular: abrir pares, quitar con mouse y teclado, tope de 30,
   error de par incompleto y contador.
7. En producción: correr la migración y revisar que el texto viejo quedó en *Otras indicaciones*.

## Estado de implementación

Implementada el 2026-11-23, con las 8 pruebas (11 casos) de
`tests/Feature/WhatsappIndicacionesTest.php`. La suite completa pasa.

- **Migración:** `2026_11_24_110000_indicaciones_por_secciones_whatsapp` (la `100000` ya era la de
  respuestas rápidas, 043).
- **Catálogo:** `AgenteWhatsapp::secciones()` y `seccionesTexto()` (sin `preguntas`).
- **`TextoIndicaciones`:** además de `armar`, `limpiar` y `caracteres`, tiene `normalizar` (la caja
  que se guarda, solo con pares completos) y `sinVacios` (quita pares vacíos y renumera antes de
  validar).
- **Si la petición no trae la caja de un agente**, la caja guardada no se toca.
- **Errores del par:** el mensaje ("Falta la respuesta.") sale dentro del par, en rojo, además de
  la lista de errores de arriba. `.campo-error textarea` también se marca en rojo.
- **Otras indicaciones:** su etiqueta queda solo para lectores de pantalla, porque repite el título
  del desplegable.
- **Pendiente:** revisión a mano en escritorio y celular (paso 6) y correr la migración en
  producción (paso 7).
