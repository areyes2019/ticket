# Especificación: Estilo visual uniforme

**Historia de usuario:** Como usuario y desarrollador, quiero establecer un estilo en mi sistema donde todo sea uniforme: botones y cards con esquinas rectas (`rounded-0`) e iconos de [Bootstrap Icons](https://icons.getbootstrap.com/).

**Alcance:** todas las pantallas actuales (inicio, login, registro, recuperación de contraseña, enlace inválido, dashboard e historial de accesos) y todas las que se creen en el futuro.

**Fuera de alcance:** instalar Bootstrap completo (solo se usa la librería de iconos), cambiar el diseño de las pantallas y cambiar textos o comportamientos.

---

## 1. Esquinas rectas

### 1.1 Regla general
- "rounded-0" significa **esquinas rectas, sin ningún redondeo**. Se toma como regla visual y no implica usar Bootstrap.
- La regla aplica a **todo** elemento con forma de caja:
  - botones,
  - cards,
  - campos de texto, selects y casillas,
  - alertas y mensajes,
  - etiquetas de estado (por ejemplo "Exitoso", "Fallido" o "Suspendido" en el historial de accesos, que hoy tienen forma de píldora).
- **No hay excepciones.** No se permite redondear esquinas "a mano" en ninguna pantalla puntual.

### 1.2 Botones
- Llevan esquinas rectas **todos** los tipos de botón: principal, secundario (por ejemplo "Cerrar sesión"), de ancho completo y los enlaces con aspecto de botón.
- Solo cambian las esquinas. Colores, tamaños y textos se quedan como están.

### 1.3 Cards
- Todo lo que hoy se ve como tarjeta o caja cuenta como card: el cuadro del login y demás pantallas de acceso, los paneles del dashboard y el contenedor de la tabla del historial.
- Las cards conservan sus sombras y bordes. Solo pierden el redondeo.

---

## 2. Iconos

### 2.1 Librería
- **Bootstrap Icons** es la **única** librería de iconos del sistema. No se mezcla con otras.
- Los archivos de la librería se guardan **dentro del proyecto**, en `public/vendor/bootstrap-icons/`, igual que axios. No se cargan desde internet, así que funcionan sin conexión externa.
- La hoja de estilos de los iconos se incluye una sola vez, en el layout principal.

### 2.2 Uso
- Los iconos acompañan al texto; **no lo reemplazan**.
- Los iconos toman el **mismo color y tamaño** que el texto que acompañan.

### 2.3 Iconos en las pantallas actuales
| Elemento | Icono |
|---|---|
| Menú: Dashboard | `bi-speedometer2` |
| Menú: Historial de accesos | `bi-clock-history` |
| Menú: Iniciar sesión / botón "Iniciar sesión" | `bi-box-arrow-in-right` |
| Menú: Crear cuenta / botón "Crear cuenta" | `bi-person-plus` |
| Botón "Cerrar sesión" | `bi-box-arrow-right` |
| Botón para enviar el enlace de recuperación | `bi-envelope` |
| Botón para guardar la nueva contraseña | `bi-key` |
| Mostrar contraseña | `bi-eye` |
| Ocultar contraseña | `bi-eye-slash` |
| Alerta de éxito | `bi-check-circle` |
| Alerta de error | `bi-x-circle` |
| Alerta de advertencia | `bi-exclamation-triangle` |

- El botón de mostrar/ocultar contraseña pasa a mostrar **solo el icono de ojo**, que cambia entre `bi-eye` y `bi-eye-slash` según el estado, con su descripción accesible (ver sección 5).

---

## 3. Un solo lugar para las reglas de diseño

- Al inicio de `public/css/app.css` se define un bloque de **valores de diseño** (variables CSS en `:root`) que controla:
  - **esquinas**: el redondeo, con valor `0`,
  - **colores**: principal, secundario, texto, fondo, bordes, éxito, error y advertencia,
  - **tamaños de letra**: base, pequeño y títulos,
  - **espacios**: una escala fija de márgenes y rellenos,
  - **tamaño de los iconos**.
- El resto del archivo **usa siempre esas variables** y nunca escribe estos valores directamente.
- Para cambiar el aspecto del sistema basta con modificar ese bloque.

---

## 4. Moldes reutilizables (componentes Blade)

Se crean componentes Blade en `resources/views/components/`. Cada uno aplica solo las esquinas rectas, los colores y los iconos correctos.

| Componente | Uso de ejemplo | Qué resuelve |
|---|---|---|
| `<x-icono>` | `<x-icono nombre="eye" />` | Pinta un icono de Bootstrap Icons con la marca de accesibilidad. |
| `<x-boton>` | `<x-boton icono="save" variante="principal">Guardar</x-boton>` | Botón con variante (`principal`, `secundario`, `suave`), ancho completo opcional (`bloque`), icono opcional y tipo (`submit` por defecto, o `button`). Con `href` se convierte en un enlace con aspecto de botón. |
| `<x-card>` | `<x-card titulo="Iniciar sesión" :nivel="1" estrecha>…</x-card>` | Caja con borde y título opcional. `nivel` elige el tamaño del título (`h1` o `h2`) y `estrecha` la centra con ancho reducido, como en las pantallas de acceso. |
| `<x-alerta>` | `<x-alerta tipo="exito">Guardado</x-alerta>` | Mensaje de éxito, error o advertencia con su icono automático. |
| `<x-campo>` | `<x-campo nombre="email" etiqueta="Correo" tipo="email" />` | Etiqueta, campo, valor anterior y texto de ayuda. Tipos: texto, correo, `password` (con botón de ojo) y `checkbox`. Si el campo tiene error, se marca con borde rojo y `aria-invalid`. El texto del error sigue apareciendo en la alerta de arriba del formulario, como antes. |

- Todas las pantallas actuales se **reescriben usando estos componentes**.
- Toda pantalla nueva debe usarlos. No se permiten botones, cards, alertas ni campos armados a mano.

---

## 5. Accesibilidad de los iconos

- `<x-icono>` añade siempre `aria-hidden="true"`, para que los lectores de pantalla ignoren el icono cuando va junto a un texto.
- Si un botón tiene **solo icono**, sin texto visible, el componente **exige** una descripción (por ejemplo `descripcion="Mostrar contraseña"`) y la pone como `aria-label`.
- Si falta la descripción en un botón de solo icono, el componente **lanza un error** al mostrar la pantalla. No se permite dejarla vacía.
- La descripción del botón de contraseña cambia junto con el icono: "Mostrar contraseña" / "Ocultar contraseña".

---

## 6. Página de muestra de estilos

- Ruta `/estilos`, disponible **solo en el entorno local** (`APP_ENV=local`). En cualquier otro entorno responde 404.
- Muestra en una sola página:
  - todas las variantes de `<x-boton>`, con y sin icono, y el botón de solo icono,
  - `<x-card>` con y sin título,
  - `<x-alerta>` de los tres tipos,
  - `<x-campo>` normal y con error,
  - los iconos usados en el sistema.
- No aparece en el menú.

---

## 7. Revisión automática

Se agrega una prueba (Pest) en `tests/Unit/` que falla con un mensaje que indica el archivo y el problema cuando encuentra:

1. **Esquinas redondeadas**:
   - en `public/css/app.css`: cualquier `border-radius` con un valor distinto de `0` o de la variable de esquinas,
   - en las vistas: clases como `rounded`, `rounded-1`…`rounded-5` o `rounded-pill`, o estilos en línea con `border-radius`.
2. **Iconos de otras librerías**: clases o archivos de Font Awesome (`fa-`, `fas`, `far`), Material Icons, Heroicons, Feather u otras, y también SVG o emojis usados como icono.
3. **Piezas hechas a mano**: etiquetas `<button>` o las clases de botón, card, alerta o campo escritas directamente en las vistas, fuera de `resources/views/components/`.

Se agregan también pruebas para:
- el componente de icono (añade `aria-hidden` y lanza error si falta la descripción en un botón de solo icono),
- la página `/estilos` (responde en local y da 404 fuera de local),
- la existencia de los archivos de Bootstrap Icons en `public/vendor/bootstrap-icons/`.

---

## 8. Guía para el futuro

Reglas para toda pantalla nueva:
- esquinas rectas siempre,
- solo Bootstrap Icons, con `<x-icono>`,
- usar siempre los componentes `<x-boton>`, `<x-card>`, `<x-alerta>` y `<x-campo>`,
- colores, tamaños y espacios solo desde las variables de `:root` en `public/css/app.css`,
- antes de crear una pieza nueva, revisar `/estilos` en local.

La revisión automática de la sección 7 (`tests/Unit/EstiloUniformeTest.php`) hace cumplir estas reglas.

---

## 9. Criterios de aceptación

- [ ] Ningún botón, card, campo, alerta ni etiqueta del sistema tiene esquinas redondeadas.
- [ ] Bootstrap Icons se carga desde `public/vendor/bootstrap-icons/`, sin peticiones a internet.
- [ ] Todos los botones y enlaces de la tabla 2.3 muestran su icono junto al texto.
- [ ] El botón de mostrar/ocultar contraseña alterna entre `bi-eye` y `bi-eye-slash` y actualiza su descripción.
- [ ] Las alertas muestran su icono según el tipo.
- [ ] `app.css` define los valores de diseño en `:root` y el resto del archivo solo los usa por variable.
- [ ] Todas las vistas usan los componentes `x-boton`, `x-card`, `x-alerta`, `x-campo` y `x-icono`.
- [ ] `/estilos` funciona en local y responde 404 en otros entornos.
- [ ] La revisión automática pasa, y falla si se introduce una esquina redondeada, un icono de otra librería o un botón hecho a mano.
- [ ] Las pruebas existentes siguen pasando.
