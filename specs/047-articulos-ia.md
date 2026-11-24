# Spec: Artículos IA, la lista que el agente de ventas ofrece primero

> **Estado: implementada el 2026-11-23, sin desplegar** (ver "Estado de implementación" al final).
> El usuario aprobó el 2026-11-23 las 20 asunciones funcionales (cambió la 1, la 3, la 5 y la 6) y
> las 5 adiciones técnicas, sin cambios.

**Modifica:**

- [041-agentes-whatsapp.md](041-agentes-whatsapp.md): reglas 34 y 35 y la fila de
  `buscar_articulos` en la tabla de herramientas. La búsqueda empieza por la lista IA y cada
  artículo dice si está en existencia o sobre pedido.
- No cambia el precio, el formulario de artículos, el clonado (035), el CSV ni el inventario (018).

## Historia de usuario

Un campo adicional en la tabla de artículos para la IA. El proceso, cuando un cliente pida un
tamaño, será:

1. Buscar en la lista de lo que esté marcado para la IA.
2. Verificar si está en existencia. Si no, la IA le aclara al cliente que, aunque no esté en
   stock, se puede pedir.
3. Al final, buscar en la lista general si lo que pide es muy especial.

La IA siempre empieza con la lista marcada para la IA.

```
Menú → Inventario
 📦 Artículos · 🗂 Catálogos · 📦 Existencias · 🤖 Artículos IA

Artículos IA                                        [ + Agregar ]
 Buscar: [__________]
 Modelo    Nombre                    Precio con IVA   Disponibilidad
 S-4020    Sello autoentintable 4x2  $350             En existencia   [Quitar]
 F-15      Sello fechador 4x2        $480             Sobre pedido    [Quitar]
```

## Objetivo / Alcance

Hacer que el agente de ventas ofrezca primero los artículos que el negocio prefiere vender, que
diga con honestidad qué hay en existencia y qué es sobre pedido, y que solo use el catálogo
completo cuando el cliente pide algo fuera de esa lista.

**Fuera de alcance:** el orden manual de la lista, notas por artículo para la IA, marcar desde el
formulario, el listado de artículos o el CSV, apartar piezas, tiempos de entrega por artículo y el
agente de facturación.

## Reglas de negocio

### La pantalla "Artículos IA" (asunciones 1 a 6)

1. Pantalla nueva **"Artículos IA"** en el grupo *Inventario* del menú (el de Artículos, Catálogos
   y Existencias), justo debajo de *Existencias*, con el icono `robot` y el detalle "Lo que la IA ofrece primero".
2. Funciona como Existencias: un listado propio y un botón **Agregar**. Agregar abre un buscador
   del catálogo general que solo muestra los artículos de la cuenta que todavía no están en la
   lista (por nombre o modelo, hasta 20), cada uno con el botón **Agregar a Artículos IA**.
3. El listado muestra **modelo, nombre, precio público con IVA** y la **disponibilidad** que verá la
   IA (*En existencia* o *Sobre pedido*, regla 10). Tiene un buscador por nombre o modelo y un botón
   **Quitar** en cada fila. Orden: por modelo, 25 por página.
4. La pueden usar los mismos usuarios que pueden editar artículos; no hay permiso nuevo. Cada
   cuenta tiene su propia lista y nadie puede agregar ni quitar artículos de otra cuenta.
5. Al principio todos los artículos están fuera de la lista.
6. La marca **solo** se ve y se cambia en esta pantalla. El listado de artículos, el formulario, el
   clonado (035) y el CSV no cambian, y la copia de un artículo clonado sale sin marca.

Mensajes: "{modelo} se agregó a Artículos IA." y "{modelo} se quitó de Artículos IA." Sin
artículos en la lista se ve: "Todavía no hay artículos. Mientras la lista esté vacía, la IA busca en
todo el catálogo."

### Cómo busca la IA (asunciones 7, 8 y 14 a 17)

7. **Paso 1:** cuando el cliente pide un producto o un tamaño, la IA siempre busca primero en la
   lista IA (adición 2).
8. Si encuentra algo en la lista IA, ofrece solo eso, sin mezclarlo con la lista general.
9. **Paso 3:** busca en la lista general (todo el catálogo) en dos casos:
   - Nada de la lista IA coincide. En ese caso la búsqueda pasa sola a la lista general.
   - El cliente pide algo muy especial que no está en la lista IA (una medida rara o un modelo
     concreto) o rechazó lo que se le ofreció. En ese caso la IA pide buscar en todo el catálogo.
10. **Paso 2:** con lo que encuentre en cualquiera de las dos listas, la IA sabe si cada artículo
    está **en existencia** o **sobre pedido**:
    - **En existencia:** el artículo está en Existencias (fila viva en `existencias`) con
      `existencia > 0`.
    - **Sobre pedido:** el artículo tiene 0 piezas, no está en Existencias o lo quitaron de ahí.
11. Si en el catálogo general tampoco hay nada, la IA dice que no lo tiene y ofrece un asesor (041,
    regla 34).
12. Si la lista IA está vacía, la IA busca directo en todo el catálogo, como hoy.

### Cómo le habla al cliente (asunciones 11 a 13, 18 y 19)

13. Si el artículo está sobre pedido, la IA se lo aclara al cliente con algo como: "Este modelo no
    lo tenemos en existencia ahora, pero se puede pedir sin problema". Lo puede cotizar igual.
14. Nunca dice cuántas piezas hay; solo dice si está disponible o es sobre pedido.
15. No promete tiempos de entrega de lo que es sobre pedido. Si el cliente pregunta cuánto tarda,
    ofrece pasarlo con un asesor.
16. El precio es el mismo en las dos listas: el precio público con IVA de siempre (041, regla 35).
17. Al cliente nunca le menciona la "lista IA" ni la "lista general". Para él solo son productos.

### Historial (asunción 20)

18. La línea de la herramienta en el historial de la conversación dice en qué lista encontró los
    artículos, por ejemplo: `consultó artículos: "sello 4x2" (lista IA, 3)` o
    `consultó artículos: "sello de madera" (todo el catálogo, 7)`.

## Adición técnica 1: columna `para_ia` en `articulos`

19. Migración: columna `para_ia` (boolean, no nula, `false` por defecto) en `articulos`, con el
    índice `(user_id, para_ia)`. Ningún artículo existente queda marcado. El `down()` quita el
    índice y la columna.
20. `para_ia` **no** va en `#[Fillable]`. Solo la escribe `ArticuloIaController` (regla 23), así el
    formulario y la importación no pueden cambiarla.
21. `Articulo` recibe el cast `para_ia => boolean` y el scope `paraIa()`.
22. El clonado (035) no copia `para_ia` sin tocar nada: `Articulo::clonar()` solo copia los campos
    de `getFillable()`, y la regla 20 deja `para_ia` fuera de esa lista.

## Adición técnica 1 bis: la pantalla

23. Rutas dentro del grupo autenticado, con las estáticas antes de `{articulo}`:

    | Método | Ruta | Nombre | Acción |
    |---|---|---|---|
    | GET | `articulos-ia` | `articulos-ia.index` | Listado con buscador |
    | GET | `articulos-ia/buscar` | `articulos-ia.buscar` | Solo los resultados, para la búsqueda dinámica |
    | GET | `articulos-ia/agregar` | `articulos-ia.agregar` | Buscador de artículos con `para_ia = false` |
    | POST | `articulos-ia/{articulo}` | `articulos-ia.store` | Pone `para_ia = true` |
    | DELETE | `articulos-ia/{articulo}` | `articulos-ia.destroy` | Pone `para_ia = false` |

24. `ArticuloIaController` autoriza con la política de `Articulo` (`update`), igual que la edición
    de artículos. Un artículo de otra cuenta da 403 o 404, como en Existencias.
25. Agregar un artículo que ya está, o quitar uno que no está, no da error: deja la marca igual y
    regresa con el mismo mensaje.
26. Vistas en `resources/views/articulos-ia/` (`index`, `agregar`, `_resultados`), copiando el
    acomodo de `existencias/index` y `existencias/agregar`. La disponibilidad se calcula con la
    misma consulta de la regla 29.
27. Entrada nueva en `components/menu-apps.blade.php`, debajo de Existencias.

## Adición técnica 2: el sistema asegura que se empiece por la lista IA

28. `buscar_articulos` recibe el parámetro `todo_el_catalogo` (boolean). En el esquema es
    obligatorio, porque las herramientas van en modo estricto (todas las propiedades se piden), pero
    la validación lo acepta ausente o nulo como `false`. Se describe así: "Ponlo en true solo si el cliente pide algo muy especial que no
    apareció o rechazó lo que le ofreciste".
    - Con `false`: busca con la misma lógica de hoy (todas las palabras, luego cualquiera y el orden
      por relevancia de 34ec725), pero solo entre `para_ia = true`. Si no encuentra nada, o la
      cuenta no tiene artículos marcados, repite la búsqueda en todo el catálogo en la misma
      llamada.
    - Con `true`: busca directo en todo el catálogo. Ahí también salen los marcados.
    - El resultado trae `lista`: `"ia"` o `"todo_el_catalogo"`. El límite de 10, `total_encontrados`
      y el aviso de "hay más resultados" se cuentan sobre la lista que se usó.
    - Cuando pasó solo a la lista general (con `false` y sin resultados en la lista IA), también
      trae el aviso: "Nada de la lista principal coincide; estos artículos son del catálogo
      completo."

## Adición técnica 3: disponibilidad junto con cada artículo

29. Cada artículo del resultado trae `disponibilidad`: `"en_existencia"` o `"sobre_pedido"`
    (regla 10). Se calcula en la misma consulta con
    `withExists(['existencia as en_existencia' => fn ($q) => $q->where('existencia', '>', 0)])`. La
    relación `existencia` ya excluye las filas borradas, así que no hay una consulta por artículo.
30. El resultado **nunca** trae `existencia`, `faltante_pendiente`, `minimo` ni ningún número de
    piezas.
31. La disponibilidad es la del momento de la búsqueda y no aparta piezas. La cotización no
    descuenta nada; la mercancía sigue saliendo al entregarse (018).
32. Se actualiza la descripción de la herramienta: menciona que primero busca en la lista principal
    del negocio y que cada artículo dice si está en existencia o sobre pedido.

## Adición técnica 4: indicaciones del agente de ventas

33. En [ventas.md](../resources/prompts/whatsapp/ventas.md), sección *Lo que haces*, la viñeta de
    productos queda así, en esencia:
    - Ofrece primero lo que regrese `buscar_articulos` en la búsqueda normal. Usa
      `todo_el_catalogo` solo si el cliente pide algo muy especial que no apareció o rechazó lo que
      le ofreciste.
    - *En existencia:* puede decir que lo tienen disponible. *Sobre pedido:* aclara que no está en
      existencia por ahora pero se puede pedir, y lo puede cotizar igual.
34. En *Lo que no haces*: no dice cuántas piezas hay, no promete cuánto tarda un pedido (ofrece un
    asesor) y no menciona listas internas.
35. No se edita desde Configuración. Las indicaciones del negocio de la pestaña *Agentes IA* (045)
    se siguen sumando igual.

## Adición técnica 5: pruebas

En `tests/Feature/WhatsappAgentesTest.php` (la búsqueda):

36. Con artículos marcados que coinciden, solo regresa esos (`lista = "ia"`), aunque haya más en el
    catálogo general.
37. Si ningún marcado coincide, regresa los del catálogo general (`lista = "todo_el_catalogo"`) con
    el aviso de la regla 28.
38. Con `todo_el_catalogo = true`, busca directo en todo el catálogo.
39. Si la cuenta no tiene artículos marcados, regresa lo mismo que hoy.
40. Un artículo con piezas sale `en_existencia`. Uno con 0 piezas, fuera de Existencias o quitado
    de ahí (borrado lógico) sale `sobre_pedido`.
41. El resultado nunca trae cantidades de piezas.
42. Nunca regresa artículos de otra cuenta ni artículos borrados, en ninguna de las dos listas.

En `tests/Feature/ArticulosIaTest.php` (la pantalla):

43. Agregar pone `para_ia = true` y Quitar lo regresa a `false`, con sus mensajes. Repetir
    cualquiera de los dos no da error.
44. El buscador de Agregar solo muestra artículos propios sin marcar.
45. No se puede agregar ni quitar un artículo de otra cuenta.
46. El listado muestra solo los marcados de la cuenta, con su precio con IVA y su disponibilidad
    correctos.
47. Guardar el formulario de artículos, importar un CSV y clonar no cambian ni copian `para_ia`.

## Estado de implementación

Implementada el 2026-11-23. Pruebas en `tests/Feature/WhatsappAgentesTest.php` (36 a 42),
`tests/Feature/ArticulosIaTest.php` (43 a 46 y el clonado de la 47) y
`tests/Feature/ArticuloTest.php` (el formulario de la 47). La suite completa pasa.

- **Migración:** `2026_11_26_100000_add_para_ia_to_articulos_table.php`. Hay que correrla al
  desplegar.
- **Código:** `ArticuloIaController`, vistas en `resources/views/articulos-ia/`, scopes `paraIa()` y
  `conDisponibilidad()` en `Articulo`, `BuscarArticulos` y `resources/prompts/whatsapp/ventas.md`.
- **Importación (47):** no hace falta prueba propia. La importación solo asigna campos de
  `#[Fillable]` y `para_ia` no está entre ellos.
