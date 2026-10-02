# Spec: Dashboard con bandeja de correo de demostración

**Referencia visual:** [Spike · app-email](https://bootstrapdemos.wrappixel.com/spike/dist/main/app-email.html).
Se toma la **distribución** (tres columnas: carpetas, lista y correo abierto), no los colores ni el
código de la plantilla. Todo se construye con el estilo propio de la app
([003-estilo-uniforme.md](003-estilo-uniforme.md)): esquinas rectas, Bootstrap Icons y las variables
de color de `public/css/app.css`. No se instala Bootstrap.

> **Desde [020](020-dashboard-cotizaciones-facturas.md)** el inicio del dashboard muestra
> cotizaciones y facturas reales. Esta bandeja de correo de demostración sigue igual, pero ya no es
> lo primero que se ve: se abre desde "Correo" en el menú de aplicaciones (`?app=correo`).

## Historia de usuario

Como usuario, quiero ver en mi panel de inicio una interfaz como la de un gestor de correo, con datos
ficticios, solo para ver cómo queda.

**Ruta:** `http://ticket_factura.test/public/dashboard` (ruta `dashboard`, ya existente).

## Objetivo / Alcance

- Reemplazar el contenido actual del dashboard (el saludo "Hola, …") por una bandeja de correo
  **de demostración**.
- Todo es visual: no se lee ni se envía ningún correo real, no se crea ninguna tabla y nada se guarda.
  Al recargar la página, la bandeja vuelve a su estado inicial.
- La bandeja se arma con piezas reutilizables, para que en el futuro pueda alimentarse con datos
  reales sin rehacer el diseño.

## Backend (Laravel)

### Datos de ejemplo (`App\Support\Demo\BandejaCorreoDemo`)

- Clase aparte, **única fuente** de los datos ficticios. La vista no contiene correos escritos a mano.
- Expone:
  - `carpetas(): array`: clave, nombre, icono y cantidad de no leídos, calculada a partir de los correos.
  - `etiquetas(): array`: clave, nombre y color.
  - `correos(): array`: entre 10 y 12 correos.
- Cada correo tiene: `id`, `carpeta`, `etiqueta` (o `null`), `remitente` (nombre y correo),
  `destinatario`, `asunto`, `cuerpo` (varios párrafos), `fecha`, `leido`, `destacado`, `importante` y
  `adjunto` (o `null`; nombre y tamaño de archivo de ejemplo, por ejemplo `COT-0042.pdf · 184 KB`).
- Las fechas se calculan **a partir del momento actual** (hace 15 minutos, hace 3 horas, ayer, hace
  5 días…) para que la bandeja siempre se vea reciente.
- Los textos están en español y hablan del negocio. Ejemplos:
  - un cliente que acepta una cotización,
  - un cliente que pide su factura,
  - un proveedor que envía su lista de precios nueva,
  - el aviso de un pago recibido,
  - un borrador de seguimiento a una cotización,
  - un correo enviado con una factura,
  - un spam evidente.
- Nombres, correos y empresas son **inventados**. No se usan datos reales de clientes ni de
  proveedores de la base de datos. Los dominios de correo son de ejemplo (`@ejemplo.com`,
  `@ejemplo.mx`).
- Todas las carpetas tienen al menos un correo, salvo Papelera, que puede quedar vacía para mostrar
  el estado "Sin correos".

### Controlador (`DashboardController`)

- Sigue siendo invocable y sin cambios de ruta ni de middleware (`auth`, usuario activo).
- Pasa a la vista `carpetas`, `etiquetas` y `correos` desde `BandejaCorreoDemo`.

## Vistas (Blade)

### `dashboard.blade.php`

- Usa `@section('contenido-clase', 'contenido-bandeja')`: la bandeja ocupa **toda la pantalla
  debajo del menú**, a lo ancho y a lo alto, sin márgenes ni ancho máximo. El alto se ajusta solo
  aunque la barra del menú ocupe varias líneas (la página es una columna flexible del alto de la
  ventana; `body:has(.contenido-bandeja)` en `app.css`, sin tocar el layout).
- Arriba se mantiene el aviso de sesión (`session('status')` con `<x-alerta tipo="exito">`).
- Luego la bandeja, armada con los componentes de abajo.

### Componentes (`resources/views/components/bandeja/`)

| Componente | Contenido |
|---|---|
| `<x-bandeja.carpetas>` | Botón "Redactar" (`bi-pencil-square`), carpetas con contador y etiquetas con su color |
| `<x-bandeja.fila-correo>` | Una fila de la lista: iniciales, remitente, asunto, fragmento, fecha, estrella, etiqueta, clip si hay adjunto |
| `<x-bandeja.visor-correo>` | El correo abierto: asunto, remitente, destinatario, fecha, cuerpo, adjunto y barra de acciones |
| `<x-bandeja.redactar>` | Ventana (`<dialog>`) con Para, Asunto, Mensaje y "Enviar" |

Los componentes reciben los datos por parámetros y no llaman a `BandejaCorreoDemo`. Así pueden recibir
correos reales en el futuro.

### Columna izquierda: carpetas

| Carpeta | Icono |
|---|---|
| Bandeja de entrada | `bi-inbox` |
| Enviados | `bi-send` |
| Borradores | `bi-file-earmark` |
| Spam | `bi-exclamation-octagon` |
| Papelera | `bi-trash` |
| Destacados | `bi-star` |
| Importantes | `bi-bookmark` |

- Cada carpeta muestra su número de no leídos cuando es mayor que cero.
- "Destacados" e "Importantes" no son carpetas propias: muestran los correos marcados, vengan de la
  carpeta que vengan.
- Etiquetas: **Clientes**, **Proveedores**, **Facturas** y **Cotizaciones**, cada una con un
  cuadrito de color. Los colores se definen como variables nuevas en `:root` de `app.css`
  (`--color-etiqueta-clientes`, etc.).
- La carpeta activa se resalta. Al abrir la página está activa "Bandeja de entrada".

### Columna central: lista

- Arriba, un buscador (`bi-search`) con el texto de ayuda "Buscar correo".
- Los correos no leídos se ven en **negritas**.
- Cada fila muestra:
  - un avatar con las **iniciales** del remitente (sin fotos), con fondo de color fijo según el nombre,
  - remitente,
  - asunto,
  - un fragmento del cuerpo cortado con "…" en una línea,
  - la fecha: la **hora** si es de hoy (`10:42`), "Ayer" si es de ayer y la fecha corta en otro caso
    (`24 sep`),
  - la estrella: `bi-star-fill` si está destacado y `bi-star` si no,
  - la etiqueta, si tiene,
  - un clip (`bi-paperclip`) si trae adjunto.
- Los textos largos se cortan con "…" y nunca provocan scroll horizontal.
- Los correos van del más reciente al más antiguo.
- Una carpeta o búsqueda sin resultados muestra "Sin correos" con el icono `bi-envelope-open`.

### Columna derecha: correo abierto

- Muestra el asunto, la etiqueta, el remitente (iniciales, nombre y correo), el destinatario, la
  fecha completa (`30 de septiembre de 2026, 10:42`) y el cuerpo con sus párrafos.
- Si trae adjunto, se muestra como una tarjeta con icono `bi-file-earmark-pdf`, nombre y tamaño.
  No descarga nada.
- Barra de acciones, con botones de solo icono y su descripción accesible (003, sección 5):
  - Responder (`bi-reply`),
  - Reenviar (`bi-forward`),
  - Marcar como no leído (`bi-envelope`),
  - Eliminar (`bi-trash`).
- Al abrir la página se muestra el primer correo de la Bandeja de entrada. Si la carpeta elegida no
  tiene correos, esta columna muestra "Selecciona un correo para leerlo".

### Ventana "Redactar"

- `<dialog>` con los campos Para, Asunto y Mensaje (`<x-campo>`), más los botones "Enviar"
  (`bi-send`) y "Cancelar".
- No valida ni envía nada. "Enviar" cierra la ventana y muestra el aviso de demostración.
- Se cierra con "Cancelar", con Esc o con un clic en el fondo, igual que la ficha de artículos.

## JavaScript (`public/js/bandeja-correo.js`)

JavaScript nativo, cargado con `@push('scripts')` solo en el dashboard. Todo ocurre en el navegador,
sin peticiones al servidor:

- **Cambiar de carpeta o de etiqueta** filtra la lista y resalta la opción elegida.
- **El buscador** filtra mientras se escribe, por remitente, asunto y cuerpo, sin distinguir
  mayúsculas ni acentos, dentro de la carpeta activa.
- **Clic en un correo** lo muestra en el visor, lo marca como leído (quita las negritas) y actualiza
  el contador de su carpeta.
- **La estrella** se marca y desmarca sin abrir el correo, y "Destacados" refleja el cambio.
- **"Marcar como no leído"** vuelve a poner el correo en negritas y suma uno al contador.
- **Responder, Reenviar y Eliminar** solo muestran el aviso de demostración.
- **Aviso de demostración:** un mensaje flotante con el texto "Esto es una demostración: no se envió
  ni se guardó nada." Desaparece solo después de unos 3 segundos.

El cuerpo de cada correo ya viene en el HTML (un visor oculto por correo, o `data-*` en la fila). No
se generan correos desde JavaScript, para que el contenido tenga una sola fuente.

**Sin JavaScript:** la bandeja se ve con la Bandeja de entrada y el primer correo abierto, y los
botones no hacen nada. Es aceptable por tratarse de una demostración.

## Diseño adaptable

- **Escritorio (≥1024px):**
  - tres columnas, con carpetas de ancho fijo (~14rem), lista (~22rem) y visor con el resto,
  - a la izquierda, una barra gris angosta (`.bandeja-riel`) con la hamburguesa (`bi-list`) arriba,
  - la columna de carpetas empieza oculta (clase `bandeja-carpetas-ocultas` en `.bandeja-plegable`);
    la hamburguesa la desliza desde la izquierda y otro clic la vuelve a plegar,
  - la lista y el visor tienen su propio scroll vertical, y la bandeja ocupa todo el ancho y el
    alto de la pantalla debajo de la barra superior (en todos los tamaños).
- **Tableta (768–1023px):** dos columnas, lista y visor. Las carpetas pasan a un botón de solo icono
  (`bi-list`, etiqueta accesible "Carpetas") que las despliega.
- **Celular (<768px):**
  - una columna a la vez, con el botón "Carpetas" arriba de la lista,
  - tocar un correo muestra el visor a pantalla completa con un botón "Volver" (`bi-arrow-left`)
    que regresa a la lista.
- En ningún tamaño hay scroll horizontal.
- Todo lleva esquinas rectas: filas, avatares, botones, etiquetas, adjunto y ventana.

## Pruebas (Pest)

`tests/Feature/DashboardTest.php`, prueba básica:

1. Con sesión iniciada, `/dashboard` responde 200 y muestra la bandeja (sin el aviso "Bandeja de
   demostración"): la carpeta "Bandeja de entrada" y el asunto de al menos un correo de
   `BandejaCorreoDemo`, tomado de la clase y no escrito a mano en la prueba.
2. Sin sesión, `/dashboard` redirige al login.

Las pruebas actuales que abren `/dashboard` para revisar el menú (artículos, catálogos, clientes,
proveedores, cotizaciones, facturas) y las de autenticación deben seguir pasando sin cambios.

## Fuera de alcance

- Leer, enviar o guardar correos reales (IMAP, SMTP, Gmail u otros), o conectar la bandeja con los
  correos que la app ya envía por cotizaciones y facturas.
- Tablas, migraciones o modelos nuevos.
- Guardar el estado (leídos, destacados, búsqueda) entre recargas, ni siquiera en `localStorage`.
- Arrastrar correos entre carpetas, seleccionar varios, paginar la lista o crear etiquetas.
- Fotos de remitentes: solo iniciales.
- Descargar adjuntos.
- Indicadores o estadísticas del negocio en el dashboard.
- Permisos distintos por rol: todo usuario con sesión ve la misma bandeja.
- Agregar los componentes de la bandeja a la página `/estilos`.

## Estado de implementación

Implementada el 2026-09-30.

- **Archivos nuevos**:
  - `app/Support/Demo/BandejaCorreoDemo.php`,
  - los componentes `carpetas`, `fila-correo`, `visor-correo`, `redactar`, `avatar` y
    `encabezado-lista` en `resources/views/components/bandeja/`,
  - `public/js/bandeja-correo.js`,
  - `tests/Feature/DashboardTest.php`.
- **Archivos modificados**: `DashboardController`, `dashboard.blade.php` y `public/css/app.css`
  (variables de la bandeja en `:root`, estilos al final y `.campo textarea` junto a los demás campos).
- **Dos piezas más que las cuatro previstas**:
  - `<x-bandeja.avatar>` (iniciales y color) la comparten la fila y el visor.
  - `<x-bandeja.encabezado-lista>` (botón "Carpetas" y buscador) existe porque la revisión automática
    del estilo (003, sección 7) no permite `<input>` ni clases `boton-*` sueltas fuera de
    `components/`.
- **Datos**: 11 correos (7 en entrada, 2 enviados, 1 borrador, 1 spam) y la Papelera vacía. Las
  fechas se calculan en la zona `app.zona_negocio`.
- **Leído / no leído**: solo un clic marca un correo como leído. El correo que se muestra solo al
  abrir la página o al cambiar de carpeta conserva su estado, igual que sin JavaScript.
- **Etiquetas**: elegir una etiqueta muestra sus correos de todas las carpetas.
- **Verificación**:
  - la suite Pest pasa completa (617 pruebas),
  - Pint no reporta cambios,
  - `node --check` valida `bandeja-correo.js`.

  **No se revisó la UI en un navegador real.** Falta abrir `/dashboard` en escritorio, tableta y
  celular.

## Criterios de aceptación

1. Al entrar a `/dashboard` con sesión iniciada se ve la bandeja de tres columnas en lugar del saludo,
   con el menú principal de la app intacto.
2. El aviso de sesión (por ejemplo, al iniciar sesión) sigue apareciendo arriba de la bandeja.
3. Se ve el aviso "Bandeja de demostración: los correos son de ejemplo."
4. Se ven las siete carpetas con sus iconos y contadores, y las cuatro etiquetas con su color.
5. La lista muestra entre 10 y 12 correos ficticios del negocio en total, en español, con iniciales,
   remitente, asunto, fragmento, fecha, estrella, etiqueta y clip cuando aplica; los no leídos van en
   negritas.
6. Al abrir la página se muestra abierto el primer correo de la Bandeja de entrada.
7. Hacer clic en un correo lo abre a la derecha sin recargar, lo marca como leído y actualiza el
   contador.
8. Cambiar de carpeta o etiqueta filtra la lista; una vacía muestra "Sin correos".
9. El buscador filtra por remitente, asunto y cuerpo, sin importar mayúsculas ni acentos.
10. La estrella se marca y desmarca, y "Destacados" muestra los marcados.
11. "Redactar" abre la ventana; "Enviar" la cierra y muestra el aviso de demostración. Responder,
    Reenviar y Eliminar también muestran el aviso.
12. Al recargar, todo vuelve al estado inicial.
13. En celular se ve una columna a la vez, las carpetas se despliegan con "Carpetas" y el correo
    abierto tiene "Volver". No hay scroll horizontal en ningún tamaño, ni siquiera con asuntos largos.
14. Todo tiene esquinas rectas y los iconos son de Bootstrap Icons.
15. Los datos ficticios viven solo en `BandejaCorreoDemo`, y la bandeja está hecha con los cuatro
    componentes de `components/bandeja/`.
16. Sin sesión, `/dashboard` lleva al login.
17. Pint no reporta cambios, la suite Pest pasa completa y `node --check` valida
    `public/js/bandeja-correo.js`.

## Supuestos asumidos (registro completo)

Todos fueron aprobados sin cambios.

1. La bandeja reemplaza el contenido actual del dashboard; los avisos de sesión se siguen mostrando
   arriba.
2. Se mantiene el menú principal; la bandeja vive en el área de contenido del layout actual.
3. Tres columnas como en la referencia: carpetas y "Redactar"; lista; correo abierto.
4. Carpetas: Bandeja de entrada, Enviados, Borradores, Spam, Papelera, Destacados e Importantes, con
   contador de no leídos.
5. Etiquetas de colores: Clientes, Proveedores, Facturas y Cotizaciones.
6. Entre 10 y 12 correos ficticios sobre temas del negocio, en español.
7. Cada correo en la lista muestra avatar o iniciales, remitente, asunto, fragmento, fecha, estado
   leído/no leído, estrella y etiqueta.
8. Al hacer clic, el correo se abre a la derecha sin recargar, con remitente, fecha, asunto, cuerpo y
   a veces un adjunto de ejemplo.
9. Interacciones solo visuales (filtrar carpetas, estrella, búsqueda); nada se guarda al recargar.
10. "Redactar" abre un formulario de ejemplo que no envía nada; "Enviar" muestra el aviso de
    demostración.
11. Responder, Reenviar, Eliminar y Marcar como no leído son decorativos o muestran el aviso.
12. En celular, una columna a la vez; el correo abierto a pantalla completa con botón para regresar;
    las carpetas en un menú desplegable.
13. Un letrero discreto indica que los datos son de ejemplo.
14. Cualquier usuario con sesión ve la misma bandeja, sin permisos distintos.
15. Se respeta el estilo de la app (003); se sigue la distribución de la referencia, no una copia
    literal.

**Adiciones técnicas aprobadas:**

1. Los datos de ejemplo viven en un archivo aparte (`BandejaCorreoDemo`).
2. La bandeja se construye con piezas reutilizables (carpetas, fila de correo, visor y ventana de
   "Redactar").
3. Una prueba básica: que el dashboard cargue, que muestre la bandeja y que sin sesión mande al login.
