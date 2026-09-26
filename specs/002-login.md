# Especificación: Autenticación de usuarios

**Historia de usuario:** Como usuario, quiero poder iniciar sesión en mi sistema Laravel.

**Alcance:** inicio de sesión, registro de cuentas nuevas, recuperación de contraseña, cierre de sesión e historial de accesos.

**Fuera de alcance:** verificación de correo, verificación en dos pasos (queda para una historia futura) y pantalla para administrar usuarios o roles.

---

## 1. Inicio de sesión

### 1.1 Formulario
- Campos: **correo electrónico** y **contraseña**.
- Casilla **"Recordarme"**.
- Botón para **mostrar u ocultar la contraseña**.
- Botón **"Iniciar sesión"**.
- Enlaces a **"¿Olvidaste tu contraseña?"** y **"Crear cuenta"**.
- Todos los usuarios, sin importar su rol, entran por esta misma pantalla.

### 1.2 Reglas
- El correo **no distingue** mayúsculas de minúsculas.
- La contraseña **sí distingue** mayúsculas de minúsculas.
- Si el correo o la contraseña son incorrectos, se muestra un mensaje genérico ("Credenciales incorrectas") que no dice cuál de los dos falló.
- Después de un error, el correo que escribió el usuario se queda en el campo y la contraseña se borra.
- Después de **5 intentos fallidos seguidos**, el acceso se bloquea **1 minuto** y se muestra un mensaje con el tiempo de espera.
- Un usuario **suspendido o desactivado** no puede entrar, aunque su contraseña sea correcta, y ve un mensaje que se lo indica.
- No se exige verificar el correo para poder entrar.

### 1.3 Después de entrar
- El usuario va al **dashboard**.
- Si antes de entrar había intentado abrir una página protegida, se le lleva **a esa página** y no al dashboard.
- Si un usuario que ya inició sesión abre la pantalla de login, se le manda directo al dashboard.

### 1.4 Sesión
- La sesión se cierra sola después de **2 horas sin actividad**, salvo que el usuario haya marcado "Recordarme".
- Un mismo usuario puede tener **varias sesiones abiertas a la vez** en distintos dispositivos.
- Dentro del sistema hay un botón **"Cerrar sesión"** siempre visible.

---

## 2. Registro de cuentas nuevas

### 2.1 Formulario
- **Nombre**
- **Correo electrónico**: único en el sistema.
- **Contraseña**: debe cumplir las reglas de la sección 4.
- **Confirmar contraseña**: debe ser igual a la contraseña.

### 2.2 Reglas
- Si el correo ya está registrado, se muestra un mensaje de error.
- Toda cuenta creada desde esta pantalla es un **usuario normal** y queda **activa**.
- Al registrarse, el usuario **queda con la sesión iniciada** y va directo al **dashboard**.

---

## 3. Recuperación de contraseña

### 3.1 Solicitud
- El usuario escribe su correo en la pantalla "¿Olvidaste tu contraseña?".
- El sistema le envía un correo con un enlace para crear una contraseña nueva.
- En desarrollo, los correos se revisan en **Mailpit**.
- Se permiten como máximo **3 solicitudes cada 15 minutos** por correo electrónico. Si se pasa del límite, se muestra un mensaje pidiendo que espere.

### 3.2 Enlace
- El enlace **vence a los 15 minutos**.
- Si el enlace ya venció o no es válido, se muestra un mensaje y se ofrece pedir uno nuevo.

### 3.3 Nueva contraseña
- Campos: **nueva contraseña** y **confirmar contraseña**.
- La nueva contraseña debe cumplir las reglas de la sección 4.
- Al guardarla, el usuario **queda con la sesión iniciada** y va directo al **dashboard**.

---

## 4. Reglas de contraseña

Se aplican al registrar una cuenta y al cambiar la contraseña:
- Mínimo **8 caracteres**.
- Al menos una **mayúscula**.
- Al menos una **minúscula**.
- Al menos un **número**.
- Al menos un **símbolo** (por ejemplo `$`, `#`, `!`).

---

## 5. Usuarios, roles y estados

- **Roles:** `usuario` (el que se asigna por defecto) y `administrador`.
- El rol de administrador **se asigna a mano**, por ejemplo directo en la base de datos o con un comando. No hay pantalla para hacerlo.
- **Estados:** `activo` y `suspendido`. Solo los usuarios activos pueden iniciar sesión.

---

## 6. Historial de inicios de sesión

- Se guarda cada intento de inicio de sesión, **exitoso o fallido**, con:
  - fecha y hora
  - correo usado
  - usuario (si existe)
  - resultado (exitoso, fallido o bloqueado por suspensión)
  - dirección IP
  - navegador y dispositivo
- **Solo el administrador** puede ver el historial.

---

## 7. Idioma

- Todas las pantallas, mensajes de error y correos están en **español**.

---

## 8. Decisiones técnicas

| # | Tema | Decisión |
|---|------|----------|
| 1 | Base del sistema | Kit de inicio oficial de Laravel con **Blade** |
| 2 | Contraseñas | Regla fuerte (sección 4) |
| 3 | Límite de recuperación | 3 correos cada 15 minutos por correo electrónico |
| 4 | Historial de accesos | Se guarda y solo lo ve el administrador |
| 5 | Verificación en dos pasos | No se incluye, queda para una historia futura |
| 6 | Pruebas automáticas | Solo las que trae el kit |

### Notas para la implementación
- El kit trae por defecto algunos comportamientos distintos a esta especificación. Hay que ajustarlos:
  - El enlace de recuperación dura 60 minutos; debe durar **15**.
  - Al cambiar la contraseña manda al usuario al login; debe **iniciar la sesión y llevarlo al dashboard**.
  - El límite de solicitudes de recuperación es de 1 por minuto; debe ser **3 cada 15 minutos**.
  - No revisa si el usuario está suspendido; debe **impedirle la entrada** (sección 1.2).
  - Los textos vienen en inglés; deben estar **en español**.
- Las pruebas del kit usan contraseñas simples (por ejemplo `password`). Con la regla fuerte, las pruebas de registro y de cambio de contraseña **van a fallar** si no se actualizan con una contraseña válida.

---

## 9. Criterios de aceptación

1. Un usuario activo con correo y contraseña correctos entra y llega al dashboard.
2. Con credenciales incorrectas aparece "Credenciales incorrectas" y el correo sigue en el campo.
3. Al sexto intento fallido seguido, el acceso queda bloqueado 1 minuto.
4. Un usuario suspendido no puede entrar aunque su contraseña sea correcta.
5. Una persona nueva puede registrarse y queda dentro del dashboard.
6. El registro rechaza contraseñas que no cumplen la sección 4 y correos ya registrados.
7. Al pedir la recuperación, el correo llega a Mailpit con un enlace válido.
8. El enlace de recuperación deja de funcionar a los 15 minutos.
9. La cuarta solicitud de recuperación dentro de 15 minutos es rechazada.
10. Al cambiar la contraseña, el usuario queda dentro del dashboard.
11. Cada intento de inicio de sesión queda en el historial, y solo el administrador puede verlo.
12. El botón "Cerrar sesión" termina la sesión y lleva al login.
13. Todos los textos aparecen en español.
