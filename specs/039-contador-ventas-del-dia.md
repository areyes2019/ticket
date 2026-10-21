# Spec: Contador de ventas del día en el menú superior

> **Estado: implementada** el 2026-10-20 (ver "Estado de implementación" al final). El usuario
> aprobó el 2026-10-20, sin cambios, las 14 asunciones funcionales, las tres adiciones técnicas y
> las "decisiones tomadas al redactar".

**Modifica:**

- El layout `layouts/app.blade.php`: la barra superior gana el contador, a la izquierda de los
  botones que ya tiene.
- [029-pago-cotizacion-pedido-orden-trabajo.md](029-pago-cotizacion-pedido-orden-trabajo.md) y
  [019-pedidos-mostrador.md](019-pedidos-mostrador.md): `pedido_pagos.fecha_pago` y
  `cotizacion_pagos.fecha_pago` ganan un índice (adición 2). No cambia ninguna regla de pago.

## Historia de usuario

Como usuario quiero tener un rating de mis ventas del día en el panel principal, en el menú
superior: solo un icono y un número estilo notificación que me indique cuántas ventas llevo. Una
venta se cuenta en cuanto un cliente da el anticipo.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ ProSello                     (🛍 3)  [+ Crear pedido]  [Catálogo]  👤 Ana ▾ │
└──────────────────────────────────────────────────────────────────────────────┘
                                ▲
                                └── al pasar el mouse: "Ventas de hoy"
```

## Objetivo / Alcance

Un icono con una burbuja de número en la barra superior, que dice cuántas ventas recibieron hoy su
primer pago.

- Un servicio único, `ContadorVentas`, con **la regla de qué es una venta y qué día cuenta**
  (adición 3). El contador lo usa, y cualquier reporte futuro tiene que usar el mismo.
- Un componente Blade, `<x-ventas-hoy />`, que el layout pinta. **No se escribe JavaScript**: el
  número se calcula en el servidor al cargar cada pantalla.
- Una migración que agrega un índice a la fecha de los pagos (adición 2).

**Fuera de alcance:** montos en dinero, metas o comparación con días anteriores, detalle de las
ventas al hacer clic, actualización en tiempo real, sonido o aviso cuando entra una venta, el
contador en la pantalla de mostrador (`layouts/mostrador.blade.php`) y en las páginas públicas
(`layouts/publico.blade.php`), y un reporte de ventas.

## Reglas de negocio

### Qué es una venta (asunciones 1, 2 y 4)

1. Una venta es uno de estos documentos:
   - una **venta** (`Pedido`), de mostrador o nacida de una cotización;
   - una **cotización con pagos que no tiene venta** (distribuidor o solo suministros, 029).
2. Una cotización **con venta** (estado `aceptada`) no cuenta por su lado: es la misma venta que su
   `Pedido`. Así, una cotización que recibe el anticipo y después se convierte en venta cuenta
   **una sola vez**.
3. Los pagos de una venta son los suyos (`pedido_pagos`) y los de su cotización
   (`cotizacion_pagos`), igual que en el reembolso (038, regla 7). Los de una cotización sin venta
   son los suyos.
4. La venta se cuenta con su **primer pago**, del tipo que sea: anticipo, saldo, pago total o un
   pago de mostrador (que no tiene tipo). Una venta sin pagos no cuenta.
5. Varios pagos de la misma venta cuentan **una sola vez**.

### Qué día cuenta (asunciones 3 y 8)

6. La venta cuenta el día de su **primer pago**: la fecha más antigua entre todos sus pagos
   (regla 3). Si el anticipo fue ayer y el saldo hoy, hoy no suma.
7. **(Decisión tomada al redactar)** La fecha es la **fecha del pago** (`fecha_pago`), la que se
   captura con el pago, no el momento en que se guardó. Un pago capturado hoy con fecha de ayer
   cuenta ayer.
8. "Hoy" es la fecha actual en la zona del negocio (`config('app.zona_negocio')`,
   `America/Mexico_City`). El contador vuelve a 0 a la medianoche de esa zona.
9. Si se borra el primer pago de una venta, su día pasa al siguiente pago. Si no le queda ninguno,
   deja de contar. Sale solo de la regla 6.

### Reembolsos (asunción 5)

10. Una venta **reembolsada** (`reembolsado`) o una cotización **reembolsada** (`reembolsada`) no
    cuenta. Si se reembolsa hoy una venta de hoy, el número baja en la siguiente pantalla que se
    abra.

### De quién son las ventas y quién las ve (asunciones 6 y 7)

11. **(Decisión tomada al redactar)** En el sistema cada cuenta de usuario es su propio negocio:
    sus clientes, ventas y cotizaciones son suyos (`user_id`) y otra cuenta no los ve. Por eso
    "todo el negocio" son **todas las ventas de la cuenta** con la sesión abierta, y no las de otras
    cuentas. Hoy no hay usuarios que compartan un mismo negocio. Si algún día los hay, esta regla se
    revisa.
12. Ven el contador todos los usuarios con sesión iniciada, cada uno con el de su cuenta.

### Cómo se ve (asunciones 9 a 14 y adición 1)

13. Un icono de ventas (`bag-check` de Bootstrap Icons) con una burbuja de número encima, en la
    esquina superior derecha, estilo notificación.
14. Con 0 ventas se muestra el icono con **0**. No desaparece.
    - **(Decisión tomada al redactar)** La burbuja en 0 va en un tono apagado (neutro) y con 1 o
      más, en el color de acento. Así se distingue de un vistazo si ya hubo ventas.
15. **(Decisión tomada al redactar)** De 100 ventas en adelante la burbuja dice **"99+"**, para que
    no crezca y empuje los botones. El texto del mouse y el de lectores de pantalla dicen el número
    exacto.
16. Solo la **cantidad**, sin montos.
17. **(Adición técnica 1)** Al pasar el mouse dice **"Ventas de hoy"** (atributo `title`). Para
    lectores de pantalla, `aria-label="Ventas de hoy: 3"`.
18. El icono **no es un enlace ni un botón**: hacer clic no hace nada.
19. El número se calcula **al cargar cada pantalla**. No cambia mientras la pantalla está quieta.
    El service worker ya pide las pantallas a la red primero (033), así que no muestra un número
    viejo guardado.
20. Aparece en la barra de **todas las pantallas** que usan `layouts/app.blade.php` con sesión
    iniciada, a la izquierda de "Instalar aplicación" / "Crear pedido". No aparece en el mostrador
    ni en las páginas públicas, que usan otros layouts.
21. **(Decisión tomada al redactar)** En el celular el contador se queda visible en la barra, igual
    que en escritorio: es pequeño y es lo que se quiere ver de un vistazo.

## Adición técnica 2: índice en la fecha de los pagos

22. `pedido_pagos` y `cotizacion_pagos` ganan un índice en `fecha_pago`. Con él, la cuenta va
    directo a los pagos de hoy sin revisar todos los pagos guardados, y el panel no se vuelve lento
    cuando haya miles. La revisión de "¿esta venta tiene un pago anterior?" usa los índices que ya
    tienen `pedido_id` y `cotizacion_id`.

## Adición técnica 3: la regla en un solo lugar

23. La definición de las reglas 1 a 11 vive **solo** en `ContadorVentas`. El componente no arma
    ninguna consulta propia. Cualquier reporte, gráfica o resumen de ventas que se haga después
    tiene que usar este servicio, o ampliarlo, para que sus números coincidan con el contador.

## Backend (Laravel)

### Migración `2026_10_21_100000_add_fecha_pago_index_to_pagos`

- `pedido_pagos`: `index('fecha_pago')`.
- `cotizacion_pagos`: `index('fecha_pago')`.
- `down()` borra los dos índices.

### Servicio `App\Services\Ventas\ContadorVentas`

```php
public function delDia(User $user, CarbonInterface $dia): int
```

- Cuenta, del usuario:
  - los `Pedido` no reembolsados cuyo pago más antiguo (entre `pedido_pagos` y los
    `cotizacion_pagos` de su cotización) tiene `fecha_pago = $dia`;
  - más las `Cotizacion` no reembolsadas **sin venta** (sin `Pedido` con su `cotizacion_id`) cuyo
    pago más antiguo tiene `fecha_pago = $dia`.
- Una sola consulta por cada grupo, sin cargar modelos (`count`), para no pesar en cada pantalla:
  candidatos con un pago en `$dia` (índice de la adición 2) y sin pagos con fecha anterior
  (`whereDoesntHave` / `NOT EXISTS`).
- `hoy(User $user): int` llama a `delDia($user, now(config('app.zona_negocio'))->startOfDay())`.
- Comentario de clase: es la única definición de "venta del día" (adición 3).

### Componente `App\View\Components\VentasHoy`

- Recibe el `ContadorVentas` por inyección y el usuario de `auth()`.
- Pasa a la vista `total` (int), `etiqueta` (`"99+"` desde 100, regla 15) y `hayVentas` (bool,
  regla 14).
- Vista `components/ventas-hoy.blade.php`:

```blade
<span class="ventas-hoy @unless ($hayVentas) ventas-hoy-cero @endunless"
      title="Ventas de hoy" aria-label="Ventas de hoy: {{ $total }}" role="status">
    <x-icono nombre="bag-check" />
    <span class="ventas-hoy-numero" aria-hidden="true">{{ $etiqueta }}</span>
</span>
```

### Layout

- `layouts/app.blade.php`: `<x-ventas-hoy />` como primer elemento de `<nav class="menu">`, dentro
  de `@auth`.

## Frontend (CSS)

- `public/css/app.css`:
  - `.ventas-hoy`: `position: relative`, icono del tamaño de los demás de la barra, en el color de
    texto de la barra.
  - `.ventas-hoy-numero`: burbuja redonda arriba a la derecha del icono, texto pequeño en negritas,
    color de acento con texto invertido.
  - `.ventas-hoy-cero .ventas-hoy-numero`: tono neutro (regla 14).
  - Se ve igual en móvil (regla 21). Usa las variables de color que ya existen (003), sin colores
    nuevos sueltos.

## Pruebas

Feature tests en el estilo de las existentes, para `ContadorVentas` y para el layout.

24. Venta de mostrador con un pago hoy → 1. Con dos pagos hoy → 1.
25. Venta con anticipo ayer y saldo hoy → 0 hoy y 1 ayer.
26. Cotización sin venta (distribuidor) con anticipo hoy → 1. Cotización con anticipo hoy que se
    convierte en venta → 1, no 2.
27. Venta nacida de una cotización con pagos en los dos lados (antes de 029): cuenta el día del pago
    más antiguo de los dos.
28. Venta reembolsada con pago hoy → 0. Cotización reembolsada → 0.
29. Venta sin pagos y cotización enviada sin pagos → 0.
30. Borrar el único pago de hoy → 0. Borrar el primer pago (ayer) de una venta que tiene otro hoy →
    1 hoy.
31. Ventas de otra cuenta con pagos hoy no cuentan.
32. Zona del negocio: un pago con fecha de hoy cuenta aunque en UTC ya sea mañana (con
    `Carbon::setTestNow`).
33. Layout: el dashboard muestra el contador con `title="Ventas de hoy"` y el número; con 0 muestra
    "0" y la clase `ventas-hoy-cero`; con 100 ventas muestra "99+" y `aria-label` con 100; la
    pantalla de login y las páginas de mostrador no lo muestran.

## Estado de implementación

Implementada el 2026-10-20, con las 10 pruebas de `tests/Feature/ContadorVentasTest.php`.

- **Componente anónimo:** `<x-ventas-hoy />` es un componente Blade anónimo
  (`components/ventas-hoy.blade.php`) que pide `ContadorVentas` al contenedor, como los demás
  componentes del proyecto, que no tienen clase. No existe `App\View\Components\VentasHoy`.
- **Fecha por rango:** el servicio compara `fecha_pago >= hoy` y `< mañana` en lugar de `= hoy`. Así
  funciona igual con la columna `date` de MySQL y con el texto con hora que guarda SQLite en las
  pruebas, y sigue usando el índice.
- **Colores:** la burbuja usa `--color-insignia` (la misma de los avisos del menú de aplicaciones) y,
  en 0, `--color-neutro-oscuro`.
