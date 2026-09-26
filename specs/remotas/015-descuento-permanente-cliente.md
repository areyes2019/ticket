# Spec: Descuento permanente por cliente

**Alcance:** Extiende [004](004-gestion-clientes.md) y [008](008-cotizaciones.md). Toca
[007](007-facturacion.md) únicamente en el punto de conversión cotización → factura.

## Historia de usuario

Como usuario registrado quiero poder ofrecer a mis clientes un descuento permanente. Esto es, poder
clasificar a cierto cliente con un descuento no mayor al 50%. Así cada vez que yo le cotice algo a
ese cliente, el descuento se genera automáticamente sobre cada línea de artículos. Este movimiento
debería ser transparente a la factura. Únicamente será visible en la cotización.

## Objetivo / Alcance

Agregar a la ficha del cliente un **porcentaje de descuento permanente** (0% a 50%) que se precarga
solo en cada línea de artículo de sus cotizaciones, donde es **visible y editable**, y que al
convertir la cotización en factura **desaparece de la vista**: se esconde dentro del precio
unitario de cada línea, de modo que el cliente paga exactamente lo mismo en los dos documentos pero
la factura no muestra en ninguna parte que hubo un descuento.

Se implementa sobre la base ya existente de Laravel API + Vue 3 SPA + Sanctum (ver
[001](001-inicio-proyecto.md), [002](002-login-auth.md)) y el design system de
[003](003-design-system-tailwind.md), siguiendo el patrón de 004/008/011/014.

**No** incluye descuentos por artículo, por volumen ni con vigencia; no toca
[Órdenes de compra](012-ordenes-compra.md), [Tesorería](010-tesoreria.md) ni la cadena de precios de
[011](011-precio-proveedor-utilidad.md)/[014](014-costo-elaboracion-goma.md).

### Qué significa exactamente "transparente a la factura"

Es la parte delicada de la historia y conviene fijarla con números antes de entrar al detalle
técnico. Cotización a un cliente con **15%** de descuento permanente, un artículo de **$333.33** por
**3** piezas, IVA 16%, sin descuento global:

| | Cotización | Factura |
| --- | --- | --- |
| Precio unitario | $333.33 | **$283.33** |
| Descuento de línea | 15% | **—** |
| Importe de línea | $849.99 | $849.99 |
| Subtotal | $849.99 | $849.99 |
| Descuento | $150.00 | **$0.00** |
| IVA 16% | $136.00 | $136.00 |
| **Total** | **$985.99** | **$985.99** |

Lo que cambia entre los dos documentos son **dos renglones**: el precio unitario, que baja, y el
descuento, que se va a cero. El importe de línea, el subtotal, el IVA y el total son idénticos.

> **Corrección respecto de la asunción 18 acordada.** Al redactar la spec se verificó contra
> `FacturaTotalesCalculator` que el `subtotal` del sistema **ya viene neto del descuento de línea**
> (`$importe = bruto − descuentoLinea`, y `$subtotal` es la suma de esos importes). Por eso el
> subtotal de la factura **no es menor** que el de la cotización, como decía esa asunción: es
> exactamente el mismo. La afirmación que sí se sostiene —y la que importa— es que **el total a
> pagar es idéntico** en ambos documentos. La asunción queda redactada abajo con el comportamiento
> correcto.

## Backend (Laravel)

### Cambios sobre `Cliente` (extiende 004)

- **Nueva columna `descuento_permanente`**: `decimal(5,2)`, **NOT NULL**, con **default `0.00`**.
- **No es nullable, a diferencia de `tamano_goma` en [014](014-costo-elaboracion-goma.md)**. Ahí
  `NULL` representaba un cuarto estado real ("este artículo no lleva goma", distinto de "lleva una
  goma que cuesta $0"). Aquí no hay tal estado: un cliente con `NULL` y uno con `0.00` reciben
  exactamente el mismo trato en todos los cálculos y en todas las pantallas. Dos representaciones
  para un solo significado obligarían a un `?? 0` en cada punto de uso sin comprar nada a cambio.
- Todos los clientes existentes quedan en `0.00` (ver Migración).
- **Sin historial**: cambiar el porcentaje sobrescribe el valor anterior. `updated_at` es la única
  referencia temporal, mismo criterio que 011/014.
- **Sin vigencia**: no hay fecha de inicio ni de fin. "Permanente" se toma literal.

`ClienteResource` suma `descuento_permanente` (float). `ClienteFactory` lo acepta y por defecto lo
deja en `0.00`, para que los tests existentes de 004/007/008 no cambien de resultado.

### Cambios sobre `Cotizacion` (extiende 008)

- **Nueva columna `descuento_cliente_porcentaje`**: `decimal(5,2)`, **NOT NULL**, default `0.00`.
  Es la **copia congelada** del `descuento_permanente` que tenía el cliente en el momento en que se
  capturó la cotización.
- **Nunca se acepta del frontend.** Cualquier valor que llegue en el body para este campo se
  **ignora en silencio**, mismo patrón que `costo_con_descuento` en 011 y `costo_goma` en 014. Lo
  escribe el servidor leyendo la ficha del cliente.
- **Cuándo se escribe**:
  - `POST /api/v1/cotizaciones`: se toma el `descuento_permanente` vigente del `cliente_id` enviado.
  - `PUT /api/v1/cotizaciones/{id}`: **solo se reescribe si cambió el `cliente_id`**. Si el cliente
    es el mismo, el valor guardado se respeta aunque la ficha del cliente haya cambiado desde
    entonces. Es lo que sostiene el supuesto 15 (una cotización guardada no se mueve sola) sin
    romper el supuesto 12 (cambiar de cliente reemplaza el descuento de las líneas).
  - `POST /api/v1/cotizaciones/{id}/duplicar`: **se copia tal cual** desde la cotización original,
    junto con sus líneas. La copia nace con las mismas líneas y los mismos descuentos que el
    original; leer el valor vigente del cliente dejaría el renglón informativo diciendo una cosa y
    las líneas mostrando otra.
- **Qué significa exactamente**: "el porcentaje que tenía este cliente cuando se capturó esta
  cotización". **No** es una garantía sobre cada línea: el usuario puede haber bajado, subido o
  quitado el descuento de una línea concreta (supuesto 11), y el valor congelado no cambia por eso.
  Es un dato de contexto para explicar de dónde salieron los porcentajes precargados, no una fuente
  de verdad para el cálculo — la fuente de verdad del cálculo siguen siendo el
  `descuento_tipo`/`descuento_valor` de cada línea, exactamente como hoy.

`CotizacionResource` suma `descuento_cliente_porcentaje` (float).

### Precio unitario de facturación (`precio_unitario_facturacion`)

Es el mecanismo que hace "transparente" el descuento. `CotizacionLineaResource` suma un atributo
calculado —no persistido— con el precio unitario que debe viajar a la factura:

```
precio_unitario_facturacion = redondeo2(importe / cantidad)
```

- Se calcula a partir del **`importe` ya persistido** de la línea (el neto de su propio descuento,
  antes del descuento global), no repitiendo la resta del descuento. Así vale igual para un
  descuento de tipo `porcentaje` que de tipo `monto`, sin ramificar.
- Una línea **sin descuento** produce `precio_unitario_facturacion == precio_unitario`. El
  mecanismo es transparente cuando no se usa, mismo criterio que el eslabón de goma en 014.
- **Vive únicamente en el backend.** Es toda la adición técnica 3: el frontend recibe el número ya
  hecho y no lo recalcula. La spec 008 documenta el riesgo de tener el algoritmo de totales duplicado
  en PHP y en TypeScript; esta cuenta no se suma a esa deuda. Por lo mismo **no se agrega ningún
  caso a `shared/fixtures/totales-documento.json`**: no hay dos implementaciones que atar.

#### El residuo de centavos

Dividir entre la cantidad y redondear puede dejar una diferencia. Con `importe = $100.00` y
`cantidad = 3`, el precio de facturación es $33.33 y la factura totaliza $99.99: un centavo menos.

Se acepta esa diferencia y **no se compensa** repartiéndola entre líneas. Compensarla exigiría que
una de las líneas llevara un precio unitario distinto al de sus hermanas idénticas —justo el tipo de
número inexplicable que esta historia trata de evitar— y el precio sigue siendo editable en el
formulario de factura si el usuario quiere ajustarlo (supuesto 20).

### Conversión a factura (extiende 008)

El flujo de 008 no cambia de forma: el frontend sigue navegando a
`/facturas/crear?cotizacion_id={id}` y `POST /api/v1/facturas` sigue aceptando el `cotizacion_id`
opcional. Lo único que cambia es **con qué valores se precarga el formulario**:

| Campo de la línea | Antes (008) | Ahora |
| --- | --- | --- |
| `precio_unitario` | `precio_unitario` de la cotización | **`precio_unitario_facturacion`** |
| `descuento_tipo` | el de la cotización | **`null`** |
| `descuento_valor` | el de la cotización | **`null`** |
| `cantidad`, `descripcion`, `modelo`, `tasa_iva` | sin cambios | sin cambios |

- **Se pliega el descuento de línea completo, venga de donde venga**: el precargado por el cliente,
  el que el usuario editó a mano, o uno capturado en una cotización de un cliente sin descuento
  permanente. La regla es una sola —"a la factura no viaja descuento de línea"— y no depende del
  origen del porcentaje. Un descuento de línea que sobreviviera visible en la factura solo porque el
  usuario lo tecleó a mano sería una excepción imposible de explicar en pantalla.
- **El descuento global de la cotización sí viaja tal cual**, como hoy. No hace falta plegarlo: se
  calcula sobre el `subtotal`, que no cambia al plegar los descuentos de línea, así que el total
  sigue cuadrando. Y es un descuento que el usuario capturó explícitamente para ese documento, no un
  arreglo permanente que quiera ocultar.
- El backend **no valida** que el total de la factura coincida con el de la cotización. Las líneas
  son libremente editables en ese formulario desde 008 y esa libertad se conserva; el `422` por total
  descuadrado de 007 sigue comparando la factura contra sí misma.

### Una factura creada desde cero no aplica nada

`POST /api/v1/facturas` sin `cotizacion_id` **no consulta** el `descuento_permanente` del cliente ni
precarga descuento alguno. El descuento nace en la cotización y solo ahí. Facturar directo es el
camino para "cóbrale precio de lista", y no hace falta un interruptor para conseguirlo.

### Validaciones (Form Requests)

- `descuento_permanente` en `POST`/`PUT /api/v1/clientes`: **opcional**; si viene, `numeric`,
  `min:0`, `max:50`, máximo 2 decimales (`decimal:0,2`). Ausente o cadena vacía se normaliza a
  `0.00` antes de validar, para que un campo que el usuario dejó en blanco y un campo que no se
  envió se comporten igual.
- **`51` se rechaza con `422`.** El tope de 50% se valida en el servidor, no solo en la pantalla.
- **El tope aplica al dato del cliente, no al resultado**: una cotización con 50% de línea más un
  descuento global capturado a mano puede terminar por encima del 50% efectivo, y eso se permite.
  Son dos decisiones distintas del usuario y la segunda es explícita.
- `descuento_cliente_porcentaje` en cotizaciones **no forma parte de las reglas**: se ignora.

### Migración de esquema y de datos

En un solo cambio:

1. `clientes` gana `descuento_permanente` `decimal(5,2)` not null default `0.00`.
2. `cotizaciones` gana `descuento_cliente_porcentaje` `decimal(5,2)` not null default `0.00`.
3. Todos los clientes existentes quedan en `0.00` y todas las cotizaciones existentes en `0.00`.

**No se recalcula ningún documento ni ningún precio.** Ninguna cotización ni factura ya guardada
cambia un centavo: la historia solo afecta a lo que se capture de aquí en adelante. El sistema no
está en producción y los datos en base son de ejemplo, así que esto es sembrado inicial y no rescate
de información.

### Tests

- **Cliente**: se guarda `0`, `50` y `12.5`; `50.01` y `51` se rechazan con `422`; un negativo se
  rechaza; el campo ausente deja `0.00`; el descuento de un cliente no es visible ni modificable por
  otro usuario.
- **Copia congelada**: crear una cotización de un cliente al 10% guarda `10.00`; subir después el
  cliente a 20% **no** cambia la cotización guardada; editar esa cotización sin cambiar de cliente
  la deja en `10.00`; editarla cambiando a un cliente al 30% la deja en `30.00`; duplicarla copia el
  valor del original y no el vigente del cliente.
- **`precio_unitario_facturacion`**: el caso de la tabla de arriba ($333.33 × 3 al 15% → $283.33 y
  $849.99); una línea sin descuento devuelve el mismo `precio_unitario`; una línea con descuento de
  tipo `monto` produce el precio neto correcto; el caso del residuo ($100.00 / 3 → $33.33) devuelve
  ese valor y documenta el centavo.
- **Equivalencia de totales cotización → factura**: se crea una cotización con descuento de cliente,
  se arma la factura con los valores que precarga el formulario (precio de facturación y descuentos
  en `null`) y se verifica que **`total` y `total_iva_16` son idénticos** y que
  `total_descuento` de la factura queda en `0.00`.
- **La misma equivalencia con descuento global encima**, verificando que el global sobrevive visible
  en la factura y que el total sigue cuadrando.
- **Una factura creada sin `cotizacion_id`** para un cliente con 30% de descuento permanente no
  aplica ningún descuento: sus líneas quedan a precio de lista.
- **Nada más se movió**: la suite existente de 004/007/008/012 pasa sin cambios de valores esperados,
  porque el default `0.00` deja la aritmética idéntica.

## Frontend (Vue 3)

### `/clientes/crear` y `/clientes/:id/editar`

Campo **"Descuento permanente"** (`Input` numérico con sufijo `%`) en la sección de datos
comerciales, junto a los campos opcionales. Valor por defecto `0`. Texto de ayuda debajo:

> Se aplicará automáticamente a cada línea de las cotizaciones de este cliente. Máximo 50%.

El error de validación del servidor se muestra por campo, patrón de 004 (`Input`/`Alert`).

### `/clientes` (listado)

Nueva columna **"Descuento"**, alineada a la derecha, mostrando `15%` o un guion cuando es `0`. Se
inserta antes de la columna de acciones, quedando: RFC | Razón social | Nombre comercial | Régimen
fiscal | **Descuento** | Acciones.

Es la única columna que se agrega y es corta, pero la tabla de clientes ya lleva cinco: la
verificación en 375 px queda anotada como pendiente explícito (ver Estado de implementación), por el
desborde que hubo que corregir en la tabla de artículos de [006](006-gestion-articulos.md).

### `ClienteCombobox`

Su `ClienteResultado` suma `descuento_permanente`, y el componente emite un evento `seleccion` con
el cliente completo además del `v-model` del id. Es más barato que una segunda petición a
`GET /clientes/{id}` desde el formulario: el objeto ya viajó en la respuesta de la búsqueda.

Los otros consumidores del combobox (factura, listados) ignoran el evento y no cambian de
comportamiento.

### `DocumentoLineas`

Nueva prop opcional **`descuentoPorDefectoPorcentaje?: number`** (default `0`). Se usa en un solo
lugar: al empujar una línea nueva desde `onArticuloSeleccionado`, que en vez de
`descuento_tipo: null, descuento_valor: null` escribe `'porcentaje'` y el valor de la prop **cuando
es mayor a 0**.

- Con la prop en `0` —factura y orden de compra, que no la pasan— el componente se comporta
  **exactamente como hoy**: `null`/`null`.
- La prop no reacciona a cambios sobre las líneas ya capturadas: reemplazarlas al cambiar de cliente
  es responsabilidad del formulario de cotización, que es quien sabe que eso debe pasar.
- El descuento precargado queda en la columna de descuento, **editable como cualquier otro**. El
  precio unitario que se precarga sigue siendo el de venta del artículo, sin tocar.

### `/cotizaciones/crear` y `/cotizaciones/:id/editar`

- Mantiene un `descuentoClienteActual` que alimenta la prop del componente de líneas:
  - **Al crear**: arranca en `0` y se actualiza con el evento `seleccion` del combobox.
  - **Al editar**: se inicializa con el `descuento_cliente_porcentaje` de la cotización cargada —no
    con el valor vigente del cliente—, que es justo el sentido de haberlo congelado. Si el usuario
    cambia de cliente en la pantalla, pasa a mandar el valor del cliente nuevo.
- **Al cambiar de cliente con líneas ya capturadas**, el descuento de **todas** las líneas se
  reemplaza por el del cliente nuevo, incluso si eso significa dejarlas en `null`/`null`. No se
  respetan las ediciones manuales previas: son ajustes que el usuario hizo pensando en otro cliente.
- **Aviso sobre la tabla de líneas** (adición técnica 4), visible solo cuando
  `descuentoClienteActual > 0`, con el componente `Alert` de 003 en su variante informativa:

  > ⓘ **Ferretería López** tiene un descuento permanente de **15%**, ya aplicado en cada línea.
  > Podés modificarlo línea por línea si esta cotización es una excepción.

  El aviso vive en la vista de cotización, **no dentro de `DocumentoLineas`**: ese componente lo
  comparten factura y orden de compra, donde el mensaje no tiene sentido.

### `/cotizaciones/:id` (detalle)

Renglón informativo en el encabezado del documento, junto al cliente, visible solo cuando
`descuento_cliente_porcentaje > 0`:

> Descuento de cliente al cotizar: **10%**

La redacción dice **"al cotizar"** a propósito: el valor es el que tenía el cliente ese día, y puede
no coincidir ni con el vigente ni con el de cada línea si el usuario las editó.

### `/facturas/crear?cotizacion_id=...`

- La precarga de líneas usa `precio_unitario_facturacion` y deja los descuentos en `null`, como
  describe la tabla de arriba.
- Aviso informativo cuando la cotización de origen traía descuento de cliente
  (`descuento_cliente_porcentaje > 0`):

  > ⓘ Los precios unitarios ya incluyen el descuento de **15%** de este cliente. La factura no
  > mostrará el descuento por separado.

  Es la misma razón por la que existe el aviso de la cotización: sin él, el usuario ve precios que
  no coinciden con su catálogo y no puede saber por qué. Ambos son pantallas de captura internas.

### El PDF de la cotización no cambia

No se agrega el renglón de "descuento de cliente" al PDF que se le manda al cliente. El PDF **ya
muestra el descuento por línea**, que es lo que al cliente le interesa ver; el porcentaje congelado
es contexto interno del usuario, mismo criterio con el que se decidió que el aviso de la adición 4
vive solo en la pantalla de captura.

## Fuera de alcance

- **Descuento en monto fijo** (pesos) a nivel cliente: es siempre porcentaje, porque tiene que
  aplicar a artículos de precios distintos.
- **Descuentos por artículo, familia, categoría o marca**: el porcentaje del cliente aplica a todas
  las líneas por igual, sin excepciones.
- **Descuentos por volumen o escalonados** (más piezas, más descuento).
- **Vigencia**: no hay fecha de inicio, fecha de fin ni caducidad. No hay descuentos de temporada.
- **Flujo de autorización**: nadie aprueba un descuento; el usuario lo captura y aplica.
- **Historial de cambios** del porcentaje de un cliente, y reversión de un cambio.
- **Recálculo de documentos ya guardados** al cambiar el descuento de un cliente: cotizaciones y
  facturas existentes conservan lo que se calculó en su momento.
- **Aplicación automática en facturas creadas desde cero**, órdenes de compra
  ([012](012-ordenes-compra.md)), tesorería ([010](010-tesoreria.md)) y complementos de pago.
- **Compensación del residuo de centavos** entre líneas al plegar el descuento en el precio.
- **Validación del descuento efectivo total** (línea + global) contra el tope de 50%.
- **Reportes** de descuento otorgado por cliente o por periodo.
- **Un descuento distinto por moneda**: el sistema es MXN, sin cambios en 011/014.
- **Importación/exportación masiva** del descuento de clientes (004 no tiene CSV de clientes y esta
  historia no lo abre).

## Estado de implementación

Implementada el 2026-08-08.

- **`precio_unitario_facturacion` se implementó como accessor del modelo `CotizacionLinea`**, no
  como una cuenta dentro del Resource: así el valor está disponible para cualquier consumidor
  (tests, futuros servicios) y no solo para la serialización JSON. El Resource únicamente lo expone.
- **La validación usa `sometimes` en vez de `nullable`**: con `nullable`, un `descuento_permanente`
  ausente en un `PUT` habría llegado como `null` a `validated()` y de ahí a una columna NOT NULL.
  Con `sometimes`, el campo ausente sencillamente no se toca, y el `prepareForValidation` traduce el
  campo enviado en blanco a `0` antes de validar.
- **Los valores enteros en las aserciones JSON van sin decimal** (`150`, no `150.0`; el dataset de
  la prueba de rango se tipa `int|float` y no `float`): PHP serializa un float redondo como entero y
  `assertJsonPath` compara con identidad. Es la misma convención ya documentada en 011 y 014, y las
  tres primeras corridas de la suite fallaron exactamente por ahí.
- **El aviso de la cotización quedó en `CotizacionFormView.vue`, no en `DocumentoLineas.vue`**, como
  anticipaba la spec: ese componente lo comparten factura y orden de compra. Lo único que ganó el
  componente compartido es la prop `descuentoPorDefectoPorcentaje`, con default `0`, que solo se lee
  al agregar una línea nueva.
- **`ClienteCombobox` pasó a emitir `seleccion` con el cliente completo** además del `v-model` del
  id. Los otros consumidores (`FacturaFormView`) ignoran el evento y no cambiaron de comportamiento.
  El cliente se busca en los resultados **con el id que llega en el manejador**, no leyendo de vuelta
  el ref de `defineModel`: con `v-model` en el padre, esa lectura devuelve el valor anterior y el
  combobox terminaba emitiendo siempre el cliente previo (o `null` en la primera selección), con lo
  que ninguna línea recibía el descuento. Detectado en navegador el 2026-08-08 y corregido el mismo
  día; la restricción quedó escrita como regla general del design system en
  [003](003-design-system-tailwind.md). Ni `vue-tsc` ni ESLint ni Vitest —que solo cubre `src/lib/`—
  podían atraparlo: era exactamente lo que la verificación visual pendiente tenía que encontrar.
- **No se agregó ningún caso al fixture compartido** `shared/fixtures/totales-documento.json`: el
  precio de facturación se calcula solo en el backend, así que no hay dos implementaciones que atar.
  Las 39 pruebas de Vitest siguen pasando sin cambios.
- **Verificado**: **279 tests de Pest en verde** (22 nuevos en `DescuentoClienteTest.php`), 39 de
  Vitest, `vue-tsc --noEmit` sin errores, `npm run build` exitoso, Pint y ESLint limpios. Prettier
  reformateó `CotizacionDetalleView.vue` y `FacturaFormView.vue` al terminar.
- **Pendiente**: la verificación visual en un navegador real (misma limitación de entorno que el
  resto de las historias). Falta confirmar a ojo: la tabla de `/clientes` con su sexta columna en
  **375 px** —el riesgo anotado arriba, por el desborde corregido en 006—, el campo de descuento en
  la ficha del cliente, el aviso de la cotización con sus líneas precargadas, el reemplazo del
  descuento al cambiar de cliente con líneas ya capturadas, y el formulario de factura precargado
  desde una cotización con descuento.

## Criterios de aceptación

1. Un usuario autenticado puede capturar en la ficha de un cliente un descuento permanente entre 0%
   y 50%, con hasta 2 decimales; el campo es opcional y su valor por defecto es 0%.
2. Capturar 50.01% o más muestra un error de validación y no permite guardar; el rechazo ocurre en
   el servidor, no solo en la pantalla.
3. Los clientes que ya existían quedan en 0% y se comportan exactamente como antes de esta historia.
4. El listado `/clientes` muestra el descuento de cada cliente, con un guion cuando es 0%.
5. Al elegir en una cotización un cliente con descuento, cada línea de artículo que se agregue trae
   ese porcentaje precargado en su columna de descuento, con el precio unitario intacto en el precio
   de lista del artículo.
6. Ese descuento precargado es editable línea por línea, y modificarlo en una cotización **no**
   cambia el descuento guardado en la ficha del cliente.
7. Cambiar de cliente en una cotización con líneas ya capturadas reemplaza el descuento de todas las
   líneas por el del cliente nuevo, incluso si el nuevo es 0%.
8. La pantalla de captura de cotización muestra un aviso con el nombre del cliente y su porcentaje
   cuando el descuento es mayor a 0%, y no lo muestra cuando es 0%.
9. Una cotización guarda el porcentaje que tenía el cliente al capturarla; subir o bajar después el
   descuento del cliente **no** modifica esa cotización ni sus totales.
10. Editar una cotización sin cambiar de cliente conserva el porcentaje guardado; editarla cambiando
    de cliente lo reemplaza por el del cliente nuevo.
11. Duplicar una cotización copia el porcentaje guardado del original, no el vigente del cliente.
12. Al facturar una cotización con descuento de cliente, cada línea llega al formulario de factura
    con el precio unitario ya rebajado y sin descuento; con $333.33 × 3 al 15%, la factura se
    precarga a $283.33 por pieza, sin descuento.
13. El **total** de esa factura es idéntico al de la cotización ($985.99 en el caso anterior), y su
    renglón de descuento queda en $0.00.
14. Si la cotización además traía descuento global, ese sí viaja visible a la factura y el total
    sigue coincidiendo.
15. El precio unitario precargado en la factura sigue siendo editable, como cualquier otra línea.
16. Una factura creada desde cero para un cliente con descuento permanente **no** aplica ningún
    descuento automático.
17. Una cotización de un cliente sin descuento produce exactamente los mismos valores que producía
    antes de esta historia: el mecanismo es transparente cuando no se usa.
18. El PDF de la cotización sigue mostrando el descuento por línea y no incorpora ningún renglón
    nuevo.
19. Órdenes de compra, tesorería y complementos de pago se comportan igual que antes.
20. Pint, ESLint/Prettier y las suites de Pest y Vitest corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. El descuento permanente es un dato de la **ficha del cliente**: se captura al crear o editar un
   cliente y queda guardado ahí.
2. Se expresa **solo en porcentaje**, no en monto fijo, porque debe aplicar a artículos de precios
   distintos.
3. El rango válido es **0% a 50%**, con hasta 2 decimales. Más de 50% se rechaza con error de
   validación.
4. Es **opcional**: un cliente sin descuento (vacío o 0%) se comporta exactamente como antes de esta
   historia.
5. Todos los clientes existentes quedan **sin descuento** al activarse la funcionalidad.
6. El descuento es **permanente y sin vigencia**: sin fecha de inicio ni de fin, no caduca, y no
   requiere aprobación de nadie.
7. Aplica a **todos los artículos por igual**: no hay artículos, categorías ni marcas excluidas.
8. El listado de clientes muestra una columna con el descuento, para identificar de un vistazo qué
   clientes están clasificados.
9. Al elegir un cliente con descuento en una cotización, cada línea que se agregue trae
   **precargado** ese porcentaje en su columna de descuento.
10. El **precio unitario no se toca** en la cotización: sigue siendo el de lista y el descuento
    aparece por separado en su columna, de modo que el cliente ve el beneficio que se le da.
11. El descuento precargado es **editable línea por línea**, sin que eso modifique el descuento
    guardado en el cliente.
12. **Cambiar de cliente** en una cotización con líneas capturadas **reemplaza** el descuento de
    todas las líneas por el del cliente nuevo, incluso si eso las deja en 0%.
13. El descuento del cliente **convive con el descuento global** de la cotización: primero el de
    línea, luego el global sobre lo que queda, tal como ya funciona el motor de cálculo.
14. El **tope de 50% aplica al dato del cliente, no al resultado final**: con un descuento global
    encima, el efectivo puede superar el 50%, y eso queda bajo responsabilidad del usuario.
15. Cambiar el descuento de un cliente **no modifica cotizaciones ya guardadas**: solo afecta a las
    nuevas y a las líneas que se agreguen después.
16. "Transparente a la factura" significa que **el monto que paga el cliente es el mismo** en la
    cotización y en la factura: el descuento no se pierde, solo deja de mostrarse.
17. Al convertir la cotización en factura, cada línea llega con el **precio unitario ya rebajado** y
    con la columna de descuento en **cero**. La factura no muestra en ninguna parte que hubo un
    descuento.
18. **(Redefinido al redactar la spec)** El **total a pagar es idéntico** en ambos documentos, y el
    renglón de descuento de la factura queda en $0.00 (o solo con el descuento global, si lo hubo).
    El **subtotal no cambia**: se verificó contra `FacturaTotalesCalculator` que el `subtotal` del
    sistema ya viene neto de los descuentos de línea, así que plegarlos en el precio unitario lo deja
    en el mismo número. La versión anterior de este supuesto decía que el subtotal de la factura
    sería menor, y eso era incorrecto.
19. Si al rebajar el precio unitario resulta un número con más de 2 decimales, se redondea a 2, lo
    que puede producir una **diferencia de centavos** entre el total de la cotización y el de la
    factura. Se acepta y no se compensa.
20. El precio unitario ya rebajado sigue siendo **editable** en el formulario de factura.
21. Una factura creada **desde cero** no aplica ningún descuento automático, aunque el cliente tenga
    descuento permanente. El descuento nace en la cotización.
22. El descuento del cliente **no afecta ningún otro documento**: órdenes de compra, tesorería y
    complementos de pago siguen igual.
23. **(Adición técnica)** El porcentaje vive en una columna nueva de `clientes`,
    `decimal(5,2)` not null default `0.00`. **No es nullable**: `NULL` y `0.00` significarían lo
    mismo, a diferencia de `tamano_goma` en 014, donde `NULL` sí era un estado distinto.
24. **(Adición técnica)** Cada cotización guarda una **copia congelada** del porcentaje que tenía el
    cliente al capturarla, para que el documento explique de dónde salieron sus descuentos aunque la
    ficha del cliente cambie después. Se reescribe solo si se cambia de cliente al editar, y se
    copia tal cual al duplicar. Es contexto informativo: la fuente de verdad del cálculo sigue siendo
    el descuento de cada línea.
25. **(Adición técnica)** El precio unitario ya rebajado lo calcula **el servidor** y se expone como
    atributo derivado (`precio_unitario_facturacion = redondeo2(importe / cantidad)`); el frontend
    solo lo consume. Es la única forma de que el redondeo no se implemente dos veces y termine
    produciendo descuadres de centavos entre lo que se le mostró al cliente y lo que se timbró ante
    el SAT — riesgo ya documentado en 008 para el algoritmo de totales, que esta historia no repite.
    Al derivarse del `importe` persistido, la fórmula vale igual para descuentos de tipo porcentaje y
    de tipo monto.
26. **(Adición técnica)** A la factura **no viaja descuento de línea de ninguna clase**, venga del
    cliente o capturado a mano: la regla es una sola y no depende del origen del porcentaje. El
    descuento **global** de la cotización sí viaja visible, porque el usuario lo capturó
    explícitamente para ese documento y su plegado no haría falta para que los totales cuadren.
27. **(Adición técnica)** Un **aviso visual** explica el descuento precargado en la pantalla de
    captura de la cotización, y otro explica en el formulario de factura por qué los precios no
    coinciden con el catálogo. Ambos viven en pantallas de captura internas y **no** en el PDF que
    recibe el cliente. El aviso de la cotización se implementa en su vista y no dentro de
    `DocumentoLineas`, que es un componente compartido con factura y orden de compra.
28. **(Adición técnica)** `DocumentoLineas` recibe el porcentaje por defecto como prop opcional que
    solo se aplica al agregar una línea nueva. Con el default en `0` —factura y orden de compra— el
    componente se comporta exactamente como hoy.
