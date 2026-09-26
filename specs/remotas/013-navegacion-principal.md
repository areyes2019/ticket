# Spec: Navegación principal agrupada

## Historia de usuario

Como usuario, quiero una mejor experiencia de navegación. La barra superior muestra el título
"Facturación" y nueve enlaces planos (Inicio, Clientes, Proveedores, Catálogos, Artículos,
Facturas, Cotizaciones, Órdenes de compra y Contabilidad) que ya se ven amontonados. Quiero que
los destinos se agrupen en menús lógicos, siguiendo el estilo de la entrada "Contabilidad", que
agrupa varios sub-menús bajo un solo nombre.

## Objetivo / Alcance

Reorganizar la navegación del `AppLayout` en **cuatro grupos desplegables** más el título, y
convertir esta spec en la **dueña única de la navegación principal**.

Hasta ahora la barra no tenía dueño: [004-gestion-clientes.md](004-gestion-clientes.md) extrajo
`src/layouts/AppLayout.vue` y estableció la costumbre de "agregar un enlace más", que repitieron
[005](005-gestion-proveedores.md), [006](006-gestion-articulos.md), [007](007-facturacion.md),
[008](008-cotizaciones.md), [009](009-catalogos.md) y [012](012-ordenes-compra.md) — siete
decisiones locales, ninguna mirando el conjunto. [010-tesoreria.md](010-tesoreria.md) introdujo el
único caso de agrupación (el `Popover` de "Contabilidad"); esta spec generaliza ese patrón a toda
la barra.

Es una historia puramente de UI/navegación: no cambia ninguna ruta, ninguna URL ni ninguna regla
de negocio. Se apoya en el design system de
[003-design-system-tailwind.md](003-design-system-tailwind.md).

## Frontend (Vue 3)

### Estructura de la barra

De nueve elementos sueltos se pasa a cuatro grupos más el título, con el menú de usuario al
extremo opuesto:

| Elemento | Ícono | Destinos |
| --- | --- | --- |
| **Facturación** | — | enlace directo a `/dashboard` |
| **Ventas** | `BanknotesIcon` | Facturas · Cotizaciones · Clientes |
| **Compras** | `ShoppingCartIcon` | Órdenes de compra · Proveedores |
| **Inventario** | `CubeIcon` | Artículos · Catálogos |
| **Contabilidad** | `ChartBarIcon` | Cuentas · Movimientos · Saldos |
| *nombre del usuario* | `UserCircleIcon` | Configuración · Cerrar sesión |

- **El título "Facturación" es el enlace al dashboard**: desaparece el enlace "Inicio" de la barra.
  El título conserva su estilo actual (`text-primary font-heading uppercase`) y suma estado de
  hover para señalar que es navegable.
- **El nombre del grupo no navega**: solo abre y cierra su desplegable. No existen pantallas índice
  por grupo (no hay `/ventas`, `/compras` ni `/inventario`).
- **Orden**: Ventas, Compras, Inventario, Contabilidad — siguiendo el flujo comercial.
- **El menú de usuario vive al extremo derecho**, separado de los grupos, y no forma parte del
  flujo comercial (ver "Menú de usuario").
- **Íconos en ambos niveles**: cada grupo lleva su ícono en la barra y cada opción del desplegable
  lleva el suyo, todos de Heroicons (`@heroicons/vue/24/outline`), en línea con la iconografía
  fijada en [003](003-design-system-tailwind.md).

Íconos de las opciones: Facturas `DocumentTextIcon`, Cotizaciones `DocumentDuplicateIcon`,
Clientes `UsersIcon`, Órdenes de compra `ClipboardDocumentListIcon`, Proveedores `TruckIcon`,
Artículos `TagIcon`, Catálogos `RectangleStackIcon`, Cuentas `WalletIcon`, Movimientos
`ArrowsRightLeftIcon`, Saldos `ScaleIcon`, Configuración `Cog6ToothIcon`, Cerrar sesión
`ArrowRightStartOnRectangleIcon`.

### Menú de usuario

El extremo derecho de la barra muestra el **nombre del usuario autenticado** como disparador de un
desplegable con dos opciones:

```
                                                          Abdias Reyes ▾
                                                          ├─ Configuración
                                                          └─ Cerrar sesión
```

- **El nombre sale de `auth.user.name`**, que el store ya obtiene de `GET /api/v1/user`. No
  requiere endpoint nuevo ni campo nuevo.
- **El nombre no navega**: solo abre y cierra el desplegable, misma regla que los nombres de grupo.
- **"Configuración"** navega a `/configuracion`. **"Cerrar sesión"** conserva exactamente el
  comportamiento actual del botón que sustituye.
- **Se trunca con elipsis** a un ancho máximo, para que un nombre largo no empuje la barra.

**Por qué el menú de usuario y no un quinto grupo.** Ventas, Compras, Inventario y Contabilidad son
el flujo comercial del negocio; la configuración del sistema no lo es, y sumarla como quinto grupo
diluiría el criterio con el que se agruparon los otros cuatro. El menú de usuario es además la
convención universal para ajustes y salida, y de paso muestra quién está dentro del sistema, que
hasta ahora no se veía en ninguna pantalla.

**Primitivo**: `DropdownMenu` de shadcn-vue / Reka UI, **no** `NavigationMenu`. La distinción está
fijada en [003](003-design-system-tailwind.md): este menú mezcla un destino de navegación
(Configuración) con una acción (Cerrar sesión), y `NavigationMenu` se anuncia a los lectores de
pantalla como un bloque de navegación, donde una acción no encaja.

### Módulo de configuración de la navegación

La definición del menú se extrae de `AppLayout.vue` a un módulo de datos declarativo,
`src/config/navegacion.ts`. `AppLayout.vue` deja de contener enlaces escritos a mano: solo recorre
esa estructura y la renderiza.

Cada grupo declara su etiqueta, su ícono y sus hijos; cada hijo declara su etiqueta, su ícono, el
`name` de su ruta y los `name` de sus rutas hermanas (crear / editar / detalle), que no aparecen en
el menú pero sí cuentan para el resaltado.

Sin este módulo, cuatro grupos con tres hijos cada uno producirían un template más largo que el
actual, y agregar una funcionalidad futura seguiría siendo cirugía sobre el `<template>`.

### Estado activo

- Un **grupo** se muestra activo cuando la ruta actual pertenece a cualquiera de sus hijos.
- Dentro del desplegable, la **opción** correspondiente a la ruta actual también se resalta.
- El grupo permanece resaltado mientras se está en una subruta de alguno de sus hijos (por ejemplo
  `/facturas/crear` mantiene "Ventas" activo).
- El **menú de usuario** sigue la misma regla: se resalta cuando la ruta actual es `/configuracion`
  o una de sus subrutas.

La pertenencia se resuelve comparando `route.name` contra los `name` declarados en
`navegacion.ts`, **no** por prefijo de path. Los grupos nuevos no comparten prefijo — "Ventas"
abarca `/facturas`, `/cotizaciones` y `/clientes` —, de modo que la comparación por prefijo que
usaba la entrada de Contabilidad (`route.path.startsWith('/tesoreria')`) no se generaliza y se
rompería en silencio si una ruta cambiara de path.

### Componente base

Los **grupos de navegación** usan `NavigationMenu` de shadcn-vue / Reka UI, que hoy **no** está en
`src/components/ui/` (están `alert, badge, button, card, combobox, dialog, input, label, popover,
select, table`) y debe traerse con `npx shadcn-vue add navigation-menu`.

Es el primitivo pensado para navegación: aporta navegación por teclado entre grupos, cierre con
`Escape`, cierre al navegar y roles ARIA de menú, todo lo cual con `Popover` habría que cablear a
mano y aun así se anunciaría como un popover genérico.

El **menú de usuario** usa `DropdownMenu`, que tampoco está y se trae con
`npx shadcn-vue add dropdown-menu`. Ambos primitivos y la regla para elegir entre uno y otro quedan
documentados en [003](003-design-system-tailwind.md).

**Paso obligatorio tras el `add`**: verificar `src/style.css` contra su estado previo y revertir lo
que el CLI haya sobrescrito. [003-design-system-tailwind.md](003-design-system-tailwind.md)
documenta que cada `npx shadcn-vue add` reescribe bloques enteros de ese archivo (variables CSS,
imports de Google Fonts, `@layer base`), pisando el `@theme` y los tokens propios.

### Comportamiento de los desplegables

- **Apertura por clic**, no por hover, para que el comportamiento sea idéntico en táctil y en
  escritorio.
- Al navegar a una opción, el desplegable se cierra automáticamente.
- Abrir un grupo cierra el que estuviera abierto: nunca hay dos grupos abiertos a la vez.
- El **menú de usuario es un primitivo aparte**, así que puede quedar abierto al mismo tiempo que un
  grupo. Se acepta: están en extremos opuestos de la barra, no se solapan visualmente, y coordinar
  dos primitivos distintos costaría más de lo que resuelve.

### Responsive

- En viewports angostos la barra colapsa en un botón hamburguesa (`Bars3Icon` / `XMarkIcon`).
- El panel móvil muestra los mismos cuatro grupos como acordeón: tocar un grupo expande sus
  opciones en línea, sin desplegables flotantes.
- **El menú de usuario cae al pie del panel móvil**, separado por una línea divisoria: el nombre
  como encabezado de la sección y sus dos opciones planas debajo, sin acordeón — son dos y
  esconderlas tras un toque más no aporta nada.
- El título "Facturación" permanece visible en la barra móvil.
- Se respeta el enfoque mobile-first y los breakpoints default de Tailwind fijados en
  [003](003-design-system-tailwind.md).

### Regla para specs futuras

Esta spec es la dueña de la navegación principal. Toda funcionalidad nueva que necesite entrada de
menú **declara dónde pertenece** (por ejemplo, "se agrega Recibos al grupo Ventas") y se implementa
como una entrada en `src/config/navegacion.ts`.

El criterio de reparto es el flujo comercial:

- Las pantallas de **negocio** entran en uno de los cuatro grupos.
- Las pantallas de **configuración del sistema o de la cuenta** entran en el menú de usuario.

Ninguna spec agrega enlaces sueltos al header. Si una funcionalidad de negocio no encaja en ningún
grupo existente, eso es señal de que la estructura de grupos debe revisarse en esta spec, no de que
haya que sumar un botón más a la barra.

## Fuera de alcance

- Cambios de rutas o URLs: `/facturas`, `/clientes`, `/tesoreria/cuentas`, etc. quedan exactamente
  como están.
- Pantallas índice por grupo (`/ventas`, `/compras`, `/inventario`): no se crean.
- Renombrar el módulo de Tesorería: sus rutas y clases siguen llamándose `tesoreria` mientras la
  etiqueta visible es "Contabilidad", como fijó [010-tesoreria.md](010-tesoreria.md).
- Navegación diferenciada por rol o permiso: el menú es idéntico para todos los usuarios.
- Entrada de menú para `/design-system`: sigue siendo accesible solo por URL y solo en desarrollo.
- Avatar con foto, pantalla de perfil y cambio de contraseña: el menú de usuario ya existe y es su
  lugar natural, pero esas pantallas quedan para una historia futura.
- Menú de usuario con datos de la empresa emisora o cambio de cuenta: no hay multiempresa.
- Breadcrumbs, buscador global, favoritos o atajos de teclado de navegación.
- Cualquier cambio de lógica de negocio: esta historia es puramente de UI.
- Modo oscuro (sigue fuera de alcance desde [003](003-design-system-tailwind.md)).

## Estado de implementación

Implementada el 2026-08-06.

- **El clobber de `style.css` ocurrió**, tal como anticipaba
  [003](003-design-system-tailwind.md). `npx shadcn-vue add navigation-menu` hizo dos cosas sobre
  `src/style.css`: reemplazó el `@import` de Google Fonts por uno que **solo pedía Roboto**,
  eliminando Open Sans (la tipografía de cuerpo de todo el sistema), y anexó un `@layer base`
  duplicado cuyo `body` no incluía `font-sans`. Se revirtió el archivo completo con
  `git checkout`: las dos incorporaciones del CLI eran duplicados de bloques que ya existían, así
  que no se perdió nada propio del componente.
- **`@lucide/vue` subió de `^1.28.0` a `^1.29.0`** en `package.json` como efecto colateral del
  `add`. Se dejó: sigue siendo dependencia de scaffolding sin uso en código propio.
- **`NavigationMenuTrigger.vue` se editó tras generarse**: el registry lo trae importando
  `ChevronDown` de `@lucide/vue`, lo que habría metido lucide en el render real. Se cambió por
  `ChevronDownIcon` de `@heroicons/vue/24/outline`, respetando la iconografía fijada en
  [003](003-design-system-tailwind.md).
- **Apertura solo por clic sin hacks**: `reka-ui` 2.10.1 expone `disable-hover-trigger` como prop
  de `NavigationMenuRoot`, y sus handlers de `pointerenter`/`pointermove`/`pointerleave` consultan
  esa bandera. No hizo falta el workaround habitual de interceptar eventos de puntero con
  `.prevent`.
- **Cierre al navegar**: `NavigationMenu` se controla con `v-model` desde `AppLayout.vue` y un
  `watch` sobre `route.fullPath` limpia el grupo abierto, el panel móvil y su acordeón. El cierre
  automático de Reka UI aplica a la interacción con el menú, no a un cambio de ruta disparado por
  `RouterLink` dentro del contenido.
- **El botón "Cerrar sesión" ocultaba su texto por debajo de `sm`** (`hidden sm:inline`), quedando
  solo el ícono, para dejar lugar al hamburguesa en 375px. El menú de usuario que lo sustituye
  hereda ese criterio: por debajo de `sm` muestra solo `UserCircleIcon`, sin el nombre.
- Verificado: `vue-tsc --noEmit` sin errores, `npm run build` exitoso, `npm test` con 30 tests en
  verde y ESLint limpio sobre los archivos nuevos y modificados.
- **Pendiente**: la verificación visual en 375 / 768 / 1440 px (criterio 13) todavía no se hizo —
  requiere abrir la app en un navegador.
- **El menú de usuario** (criterios 15 a 19) se incorporó a esta spec el 2026-08-07, al detectarse
  que la pantalla de Configuración de [014](014-costo-elaboracion-goma.md) no encajaba en ninguno de
  los cuatro grupos — exactamente el caso que la sección "Regla para specs futuras" anticipa. Se
  resolvió aquí, en la dueña del menú, en vez de agregar un quinto grupo o un enlace suelto.
  Implementado el mismo día junto con 014.
- **`npx shadcn-vue add dropdown-menu` volvió a clobberear `src/style.css`** exactamente como lo
  hizo `navigation-menu`: quitó Open Sans del `@import` de Google Fonts y anexó un `@layer base`
  duplicado. Se revirtió con `git checkout`. La verificación posterior al `add` no es opcional.

## Criterios de aceptación

1. La barra superior muestra el título "Facturación", cuatro grupos —Ventas, Compras, Inventario y
   Contabilidad— y, al extremo derecho, el nombre del usuario autenticado. No quedan enlaces planos
   de sección en el header.
2. El título "Facturación" navega a `/dashboard`, y no existe un enlace "Inicio" separado.
3. Cada grupo abre por clic un desplegable con sus opciones: Ventas (Facturas, Cotizaciones,
   Clientes), Compras (Órdenes de compra, Proveedores), Inventario (Artículos, Catálogos),
   Contabilidad (Cuentas, Movimientos, Saldos).
4. El nombre de un grupo no navega a ninguna parte; solo abre y cierra su desplegable.
5. Cada grupo y cada opción del desplegable muestran su ícono de Heroicons.
6. El grupo se resalta cuando la ruta actual pertenece a alguno de sus hijos, incluidas las
   subrutas de crear, editar y detalle; la opción activa dentro del desplegable también se
   resalta.
7. El resaltado se resuelve por `name` de ruta declarado en `src/config/navegacion.ts`, sin
   comparaciones por prefijo de path.
8. La estructura del menú vive en `src/config/navegacion.ts`; `AppLayout.vue` la recorre y no
   contiene enlaces de sección escritos a mano.
9. Los desplegables usan `NavigationMenu` de shadcn-vue / Reka UI, se cierran al navegar y
   responden a `Escape`; nunca hay dos abiertos simultáneamente.
10. Tras instalar `navigation-menu`, `src/style.css` conserva íntegros el `@theme` y los tokens
    propios del design system.
11. En viewport móvil la barra colapsa en un menú hamburguesa que presenta los mismos cuatro
    grupos como acordeón, y al pie del panel, tras una línea divisoria, el nombre del usuario con
    Configuración y Cerrar sesión como opciones planas.
12. Ninguna URL cambia: todas las rutas existentes siguen respondiendo igual que antes.
13. Verificado visualmente en viewports de 375px, 768px y 1440px: los desplegables abren, el
    hamburguesa aparece en móvil y nada desborda horizontalmente.
14. ESLint/Prettier corren sin errores sobre el código nuevo.
15. El extremo derecho de la barra muestra el nombre del usuario autenticado, tomado de
    `auth.user.name`, como disparador de un desplegable con dos opciones: Configuración y Cerrar
    sesión. El nombre no navega a ninguna parte.
16. "Cerrar sesión" dentro del desplegable conserva el comportamiento del botón que sustituye, y no
    queda ningún botón suelto de cerrar sesión en la barra.
17. "Configuración" navega a `/configuracion`, y el menú de usuario se resalta mientras la ruta
    actual sea esa o una de sus subrutas.
18. El menú de usuario usa `DropdownMenu` de shadcn-vue / Reka UI, no `NavigationMenu`; tras
    instalarlo, `src/style.css` conserva íntegros el `@theme` y los tokens propios.
19. Un nombre de usuario largo (más de 25 caracteres) se trunca con elipsis y no desborda ni
    desplaza los grupos de la barra.

## Supuestos asumidos (registro completo)

1. La navegación se reorganiza en grupos desplegables (el mismo patrón que hoy usa Contabilidad),
   no en un sidebar lateral permanente.
2. Los grupos son Ventas, Compras, Inventario y Contabilidad, agrupados por flujo comercial.
3. Ventas agrupa Facturas, Cotizaciones y Clientes.
4. Compras agrupa Órdenes de compra y Proveedores.
5. Inventario agrupa Artículos y Catálogos.
6. Contabilidad se mantiene exactamente como está: Cuentas, Movimientos y Saldos.
7. **(Redefinido)** No existe un enlace "Inicio" separado: el título **"Facturación"** es el enlace
   al dashboard, lo que baja la barra de nueve elementos a cuatro grupos más el título.
8. Un grupo se marca como activo cuando la ruta actual pertenece a cualquiera de sus hijos.
9. Dentro del desplegable, la opción correspondiente a la ruta actual también se resalta.
10. No cambian las rutas ni las URLs; solo cambia cómo se llega a ellas desde el menú.
11. No se agregan pantallas nuevas: no hay página índice por grupo, y el nombre del grupo solo abre
    el desplegable.
12. En viewport móvil la barra colapsa en un menú hamburguesa con los mismos grupos expandidos como
    acordeón.
13. El desplegable se abre con clic (no con hover), para que funcione igual en táctil y escritorio.
14. Los desplegables se cierran automáticamente al navegar a una de sus opciones.
15. **(Redefinido)** El botón "Cerrar sesión" se sustituye por un **menú de usuario** en la misma
    posición: el nombre del usuario autenticado abre un desplegable con Configuración y Cerrar
    sesión. Cerrar sesión conserva su comportamiento; lo que cambia es que ahora vive a un clic de
    profundidad, a cambio de tener un lugar donde poner los ajustes y de mostrar quién está dentro
    del sistema.
16. **(Redefinido)** Los íconos aparecen en ambos niveles: cada grupo en la barra y cada opción del
    desplegable llevan su ícono de Heroicons.
17. El orden de los grupos es Ventas, Compras, Inventario, Contabilidad.
18. La navegación es idéntica para todos los usuarios; no hay ocultamiento por permisos ni roles.
19. `/design-system` sigue siendo accesible solo por URL en desarrollo, sin entrada en el menú.
20. La reorganización es puramente visual: ninguna spec de negocio existente cambia su alcance
    funcional.
21. Las pantallas de configuración del sistema o de la cuenta **no** entran en los cuatro grupos:
    van al menú de usuario. Los cuatro grupos son el flujo comercial y sumarles un quinto grupo de
    ajustes diluiría el criterio con el que se agruparon.
22. El nombre del usuario sale de `auth.user.name`, que el store ya obtiene de `GET /api/v1/user`.
    No hace falta endpoint ni campo nuevo, y de paso queda visible quién está dentro del sistema,
    que hasta ahora no se veía en ninguna pantalla.
23. El menú de usuario puede quedar abierto a la vez que un grupo de navegación, porque son
    primitivos distintos. Se acepta: están en extremos opuestos de la barra y no se solapan.
24. El menú de usuario no incluye avatar, foto ni pantalla de perfil; son dos opciones de texto con
    ícono.

### Adiciones técnicas acordadas

- **A.** La definición del menú se extrae a `src/config/navegacion.ts` y `AppLayout.vue` solo la
  renderiza, en lugar de mantener los enlaces en el template.
- **B.** El estado activo de cada grupo se deriva de los `name` de sus rutas hijas, no de
  comparaciones `startsWith` sobre el path.
- **C.** Se adopta `NavigationMenu` de shadcn-vue / Reka UI en lugar de `Popover`, con verificación
  obligatoria de `src/style.css` tras el `npx shadcn-vue add`.
- **D.** Esta spec queda como dueña única de la navegación y fija la regla de que las specs futuras
  declaren grupo en lugar de agregar enlaces sueltos.
- **E.** La verificación de viewports (375 / 768 / 1440 px) es una comprobación visual puntual, sin
  suite E2E versionada ni tests de Vitest sobre la navegación. Playwright no es dependencia del
  proyecto y no se incorpora.
