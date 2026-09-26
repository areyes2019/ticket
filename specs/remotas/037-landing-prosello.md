# Spec: Landing page B2B en `prosello.com.mx`

## Historia de usuario

Como desarrollador único del sistema, quiero publicar una página pública en `https://prosello.com.mx`
—el dominio raíz que [022](022-subdominio-app.md) dejó libre y redirigiendo temporalmente al
sistema— para captar contactos comerciales de imprentas, fabricantes de sellos y distribuidores,
sin construir tienda, catálogo ni sistema de pedidos: solo una presentación que lleve al visitante a
escribir por WhatsApp o dejar sus datos en un formulario de contacto.

## Datos de contacto

1. WhatsApp de negocio: **461 358 1090**.
2. Correo del formulario (`LANDING_CONTACTO_EMAIL`): **ventas@prosello.com.mx**.
3. Teléfono: mismo número que WhatsApp, **461 358 1090**. No hay un segundo número de voz.
4. Ubicación: **Celaya, Gto.** (referencial, solo ciudad/estado — sin dirección completa).
5. Horario de atención: **no se muestra** en esta primera versión.
6. Nombre comercial: **Prosello Distribuciones** (confirmado).

## Objetivo / Alcance

Una página **one page** (una sola URL, navegación por scroll/anclas), estática en su contenido,
sin login, sin conexión a la base de datos de artículos/inventario del sistema, con dos vías de
conversión: botones de WhatsApp con mensaje prellenado (distinto según la sección) y un formulario
de contacto que envía un correo.

**No es una tienda ni un catálogo.** No muestra precios, SKU, existencias ni botón de compra. La
[historia original del negocio](../catalogo/) ya reunió fotografía real de producto
(`catalogo/Autoentintable`, `catalogo/Fechadores`, `catalogo/E`) que se usa como material visual,
no como listado de artículos consultables.

## Estructura de contenido

Secciones, en orden, todas dentro de la misma página:

1. **Header** — logo, menú de anclas (Inicio / Soluciones / Para quién / Nosotros / Contacto) y
   botón "WhatsApp" destacado. Fijo al hacer scroll. En móvil: logo + menú hamburguesa + botón
   WhatsApp.
2. **Hero** — título dirigido al cliente ("Sellos e insumos para quienes viven de vender sellos."),
   subtítulo, botón primario "Hablar por WhatsApp" y botón secundario "Conocer más" (ancla a
   Soluciones). Fotografía real de producto.
3. **Frase de posicionamiento** — una sola frase fija (sin A/B testing en esta versión; ver
   supuesto 10).
4. **El problema** — identificación con el dolor del cliente ("te falta un insumo a media orden") +
   CTA "Pregúntanos por WhatsApp".
5. **Propuesta de valor** — 4 bloques cortos: Disponibilidad, Atención directa, Enfoque B2B,
   Cercanía.
6. **Productos/soluciones** — 4 a 6 bloques visuales (Sellos, Mecanismos, Fechadores, Tintas y
   almohadillas, Materiales, Soluciones especiales), cada uno con imagen + nombre + una línea de
   descripción. Sin precio, sin SKU, sin botón "comprar". Cierra con "¿Buscas algo específico?
   Pregúntanos" → botón WhatsApp.
7. **Composición visual de producto** — colage fotográfico, sin listar productos individuales.
8. **¿Para quién es?** — tres bloques: Imprentas, Fabricantes de sellos, Distribuidores, cada uno
   con foto relacionada.
9. **Sección distribuidores** — la de mayor peso de conversión. Botón "Quiero información para
   distribuir" con WhatsApp prellenado: *"Hola, me interesa conocer información para trabajar como
   distribuidor."*
10. **Diferenciador** — bloque de diseño distinto, frase grande "¿Lo necesitas? Pregúntanos." + botón
    "Hablar con Prosello".
11. **Marcas/calidad** — **se omite en esta versión** (supuesto 8: sin logos reales de marca
    todavía).
12. **Por qué Prosello** — 4-5 razones con ícono simple.
13. **CTA principal** — bloque de fondo distinto, título "¿Qué necesitas para tu negocio?" + botón
    grande "Hablar por WhatsApp".
14. **Contacto** — WhatsApp/teléfono (mismo número: 461 358 1090), correo (ventas@prosello.com.mx),
    ubicación (Celaya, Gto.) y el **formulario de contacto** (ver siguiente sección). Sin horario de
    atención en esta versión. El número tocable enlaza a `tel:` y a `https://wa.me/` desde móvil.
15. **Footer** — datos legales mínimos, mismos enlaces del header.

Los mensajes de WhatsApp prellenados varían según el botón de origen (general / productos /
distribuidor / imprenta), igual que ya lo distingue el documento original, para poder diferenciar
de dónde viene cada contacto con solo leer el primer mensaje.

## Formulario de contacto

Definido en la ronda de preguntas de esta spec:

- **Campos**: nombre, correo, teléfono, mensaje. Sin campo de tipo de negocio (se decidió el juego
  de campos "Básico").
- **Destino**: únicamente correo electrónico a `LANDING_CONTACTO_EMAIL`. No se guarda ningún
  registro en base de datos; no hay listado de leads en el sistema.
- **Después de enviar**: la sección del formulario se reemplaza por un mensaje de agradecimiento y
  un botón adicional ("¿Prefieres una respuesta más rápida? Habla por WhatsApp") para quien no
  quiera esperar el correo.
- **Antispam**: un campo honeypot (input oculto por CSS, nunca visible ni tabulable) que debe llegar
  vacío; si llega lleno, el backend responde éxito sin enviar el correo, para no delatar el filtro a
  quien lo automatizó. Además, límite de peticiones por IP (ver Backend).

## Backend (Laravel)

Todo dentro del mismo proyecto `backend/` que ya sirve `app.prosello.com.mx` — no se levanta un
segundo backend.

- **Ruta nueva**, sin `auth:sanctum`, en `routes/api.php`: `POST /contacto` (queda en
  `https://app.prosello.com.mx/api/v1/contacto`), con `throttle:10,1`. El comentario que hoy dice
  "Son las únicas rutas que cualquiera en internet puede llamar" (línea 39) deja de ser cierto y se
  actualiza para incluir esta.
- **`StoreContactoLandingRequest`**: valida `nombre`, `correo` (formato email), `telefono`,
  `mensaje` y el campo honeypot (debe ir vacío o ausente), mismo patrón que las demás
  `Http\Requests` del proyecto.
- **`ContactoLandingController@store`**: si el honeypot llegó lleno, responde `200` sin hacer nada
  más. Si no, envía un `Mailable` (`ContactoLandingMail`) a `LANDING_CONTACTO_EMAIL` con los cuatro
  campos, usando el mismo `MAIL_MAILER` que ya configura el sistema para correos existentes (no se
  agrega un proveedor de correo nuevo).
- **`.env`**: variable nueva `LANDING_CONTACTO_EMAIL`.

### CORS: alcance deliberadamente angosto

`config/cors.php` hoy tiene `allowed_origins => [env('FRONTEND_URL', …)]` con
`supports_credentials => true` sobre `paths => ['*']` — es decir, cualquier origen que se agregue
ahí queda habilitado para pedir **con cookies** cualquier ruta del API, incluidas las autenticadas.
Agregar `https://prosello.com.mx` a esa lista global ensancharía la superficie de ataque de todo el
sistema (justo lo que [022](022-subdominio-app.md) evitó separando orígenes), por una sola ruta
pública que ni siquiera usa sesión.

Por eso `/contacto` **no toca `config/cors.php`**: el propio controlador agrega
`Access-Control-Allow-Origin: https://prosello.com.mx` únicamente en su respuesta, sin
`Access-Control-Allow-Credentials`. El navegador exige un preflight (`OPTIONS`) porque el `POST`
lleva `Content-Type: application/json`, pero es una sola petición extra por envío de formulario, no
por cada llamada autenticada del sistema — el problema que 018/022 sí evitaron para el SPA.

## Frontend: proyecto nuevo `landing/`

Independiente de `frontend/` (el SPA del sistema). No reutiliza su Vue Router, sus stores ni sus
llamadas al API de negocio.

- Carpeta `landing/`, hermana de `frontend/` y `backend/`.
- HTML/CSS estático para el contenido (no cambia por usuario ni por sesión), con **un único
  componente Vue 3** montado solo en el bloque del formulario de contacto, que hace el `fetch` a
  `POST https://app.prosello.com.mx/api/v1/contacto` y maneja el estado de envío/confirmación.
  El resto de la página no necesita reactividad.
- Tailwind para estilos, reusando los tokens de color/tipografía del design system del sistema
  ([003](003-design-system-tailwind.md)) donde tenga sentido para la identidad de marca, sin
  importar sus componentes Vue (son de otra app).
- Los botones de WhatsApp son enlaces `<a href="https://wa.me/<numero>?text=<mensaje-codificado>">`
  planos, sin JavaScript.
- Compila a un `dist/` estático de solo HTML/CSS/JS, sin depender de Node en el servidor de
  producción (igual que ya hace `frontend/`).

### Imágenes

Curación manual, no automatizada, para esta primera versión: las fotos ya reunidas en
[catalogo/](../catalogo/) (`Autoentintable/`, `Fechadores/`, `E/`, `prosello-ejemplo.webp`) se
seleccionan, recortan y convierten a WebP a mano antes de incorporarlas a `landing/src/assets/` (o
`landing/public/img/`). No se agrega un paso de build que optimice imágenes automáticamente.

### SEO técnico

- `landing/public/robots.txt` **permite** el rastreo (al contrario del `Disallow: /` de
  `app.prosello.com.mx`).
- `landing/public/sitemap.xml` con la única URL de la página.
- `index.html` con `<title>`, meta description, Open Graph (`og:title`, `og:description`,
  `og:image`) y un bloque `schema.org` tipo `LocalBusiness`/`Organization` con los datos de
  contacto reales (pendientes, ver arriba).
- Enfocado a búsquedas de: sellos de goma, sellos para imprentas, proveedor de sellos, mecanismos
  para sellos, insumos para sellos/imprentas, distribuidor de sellos.

## Despliegue

Reemplaza, para `prosello.com.mx`, lo que [022](022-subdominio-app.md) dejó como transición:
redirección 302 a `app.prosello.com.mx` + `sw.js` de apagado de la PWA vieja.

**Precondición**: confirmar que la mudanza de 022 en el servidor ya terminó por completo (pasos 4 a
10 de esa spec) antes de tocar el docroot de `prosello.com.mx`. Si un usuario todavía tiene la PWA
vieja instalada y nunca la abrió con red desde el 302, retirar la redirección antes de que ese
`sw.js` de apagado alcance a correr la dejaría atrapada.

- `deploy/config.sh` y `config.example.sh` ganan `REMOTE_LANDING_DOCROOT`
  (`~/domains/prosello.com.mx/public_html`). `APEX_URL` ya existe y pasa a ser la URL real de la
  landing en vez de un destino de verificación de redirección.
- **`deploy/deploy-landing.sh`** (nuevo, paralelo a `deploy-frontend.sh`): compila `landing/` y sube
  `dist/` a `REMOTE_LANDING_DOCROOT` por `scp`/`rsync`, mismo patrón que ya usa
  `deploy-frontend.sh` para `frontend/dist/`.
- **`deploy/verify.sh`**: el bloque que hoy comprueba que `prosello.com.mx` responde 302 hacia el
  subdominio (criterios 9-10 de 022) deja de tener sentido y se reemplaza por comprobaciones de la
  landing real: `200` en `https://prosello.com.mx/`, el `<title>` contiene "Prosello", y
  `POST /api/v1/contacto` (contra `app.prosello.com.mx`) con honeypot lleno responde `200` sin
  reventar.
- El `.htaccess` y el `sw.js` de apagado (`deploy/hostinger/htaccess-apex`,
  `deploy/hostinger/sw-apex.js`) se retiran del docroot de `prosello.com.mx` una vez confirmado que
  ya cumplieron su función (precondición de arriba), y el `.htaccess` de la landing pasa a ser el
  estándar de sitio estático (sin las reglas de redirección al subdominio).

## Fuera de alcance

- Panel de administración para editar el contenido de la landing: es estático, se edita en el
  repositorio.
- Guardar los envíos del formulario en base de datos o mostrarlos en algún listado del sistema:
  solo correo (ver ronda de preguntas).
- Analítica (Google Analytics, Meta Pixel, GTM) y pruebas A/B de frases.
- Sección de marcas/logos de terceros, hasta contar con logos reales.
- Carrito, checkout, catálogo completo con precios/SKU/inventario, sistema de pedidos, registro de
  usuarios del lado de la landing.
- Multilenguaje.
- Cambiar `config/cors.php` de forma global o compartir sesión entre `app.prosello.com.mx` y
  `prosello.com.mx` — sigue descartado por las mismas razones de 018/022.

## Criterios de aceptación

1. `https://prosello.com.mx/` sirve la landing (ya no redirige a `app.prosello.com.mx`), con las 15
   secciones del apartado "Estructura de contenido" navegables por scroll y por el menú de anclas.
2. Todos los botones de WhatsApp abren `wa.me` con el número real y el mensaje prellenado correcto
   según la sección de origen (general / productos / distribuidor / imprenta).
3. El formulario de contacto, con los cuatro campos completos y el honeypot vacío, hace `POST` a
   `app.prosello.com.mx/api/v1/contacto` y llega un correo a `LANDING_CONTACTO_EMAIL` con los datos.
4. Tras un envío exitoso, la sección del formulario muestra el mensaje de agradecimiento y el botón
   de WhatsApp alterno, sin recargar la página.
5. Un envío con el campo honeypot lleno responde éxito pero no genera ningún correo.
6. Más de 10 envíos por minuto desde la misma IP reciben `429`.
7. No aparece ningún precio, SKU, cantidad en existencia ni botón "comprar" en toda la página.
8. La página es 100% responsiva: en móvil el header queda con menú hamburguesa, los botones son
   fáciles de tocar y no hace falta hacer zoom en ningún texto.
9. `https://prosello.com.mx/robots.txt` permite el rastreo; existe `sitemap.xml`; el `<head>` trae
   Open Graph y el JSON-LD de `LocalBusiness`.
10. `bash deploy/deploy-landing.sh` publica `landing/dist/` en el servidor y `bash deploy/verify.sh`
    confirma la landing (ya no la redirección) en verde.
11. El sistema de facturación en `app.prosello.com.mx` sigue funcionando exactamente igual: login,
    generación de PDF y catálogos SAT sin cambios (esta spec no lo toca salvo la ruta nueva
    `/contacto` y su entrada en CORS controlada por el propio controlador).

## Supuestos asumidos (registro completo)

1. El dominio destino es `prosello.com.mx` (se corrigió el "prosellos.com.mx" del mensaje original,
   error de tecleo), el mismo que [022](022-subdominio-app.md) dejó libre.
2. Página completamente independiente del sistema de facturación: sin login, sin leer artículos ni
   inventario reales.
3. **(Corregido)** Sí hay formulario de contacto, además de los botones de WhatsApp. Definido en la
   ronda de preguntas: destino solo correo (`LANDING_CONTACTO_EMAIL`), campos básicos
   (nombre/correo/teléfono/mensaje), y tras enviar se muestra confirmación + botón de WhatsApp
   alterno.
4. El contenido (textos, orden de secciones, fotos) es estático en esta primera versión: sin panel
   de administración.
5. Un solo idioma: español.
6. Un solo número de WhatsApp de negocio para todos los CTA; lo que cambia entre botones es el
   mensaje prellenado, no el destino.
7. Las fotografías salen del material ya reunido en `catalogo/` más lo que se agregue; sin sesión de
   fotografía profesional nueva para el lanzamiento.
8. La sección "Marcas/calidad" se omite en esta versión por no contar con logos reales de marca.
9. Sin analítica avanzada (GA4, Meta Pixel) en esta primera versión.
10. Sin sistema de pruebas A/B de frases: una sola frase de posicionamiento fija.
11. El sitio se indexa en buscadores (a diferencia de `app.prosello.com.mx`, que tiene
    `Disallow: /`).
12. Los seis datos reales de contacto quedaron definidos en "Datos de contacto" al inicio: WhatsApp
    y teléfono son el mismo número (461 358 1090), correo ventas@prosello.com.mx, ubicación Celaya,
    Gto. (solo ciudad/estado), sin horario de atención visible, y nombre comercial "Prosello
    Distribuciones".
13. El formulario reutiliza el mismo Laravel que ya sirve `app.prosello.com.mx`, con una ruta
    pública nueva y throttling — no se levanta un backend independiente para la landing.
14. La protección antispam del formulario es honeypot + límite de peticiones; sin captcha visible.
15. La landing es un proyecto frontend nuevo y separado (`landing/`), no una vista más del SPA del
    sistema; comparte tokens visuales de Tailwind donde aplique, no componentes ni rutas.
16. Optimización de imágenes manual (curación y conversión a WebP a mano), sin pipeline automatizado
    en esta primera versión.
17. El acceso cruzado del formulario (`prosello.com.mx` → `app.prosello.com.mx/api/v1/contacto`) se
    resuelve con una cabecera CORS puesta solo en esa respuesta, sin tocar `config/cors.php` ni
    `supports_credentials`, para no ensanchar el origen permitido de las rutas autenticadas.
18. El despliegue de la landing extiende los scripts existentes de `deploy/` (nuevo
    `deploy-landing.sh`), en vez de publicarse a mano.
19. Retirar la redirección 302 y el `sw.js` de apagado de `prosello.com.mx` (transición que 022 dejó
    abierta) es parte del alcance de esta spec, condicionado a que la mudanza de 022 en el servidor
    esté completamente terminada primero.

## Estado de implementación

Pendiente. La spec ya está completa —incluidos los datos reales de contacto—; falta construir
`landing/`, la ruta `/contacto` en el backend y los scripts de despliegue.
