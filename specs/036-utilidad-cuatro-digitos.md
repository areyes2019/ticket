# Spec: Utilidad de cuatro dígitos

> **Estado: implementada** el 2026-10-12, a petición directa del usuario.

**Modifica:** [009-precio-proveedor-utilidad.md](009-precio-proveedor-utilidad.md) (topes de la
utilidad y del precio del proveedor) y [028](028-precio-distribuidor.md) (utilidad distribuidor).

## Problema

En el catálogo de sellos de madera, la moldura cuesta $10.00 y ese costo debe seguir siendo el de las
órdenes de compra, pero el sello se vende en unos $200.00 (≈ 1,624% de utilidad). El tope de 999.99%
lo impedía. No era una regla de negocio: venía de las columnas `decimal(5,2)`.

## Reglas

1. `utilidad_porcentaje` y `utilidad_distribuidor_porcentaje`, en `catalogos` y en `articulos`, pasan
   a `decimal(7,2)`. La migración solo agranda las columnas y no toca los datos.
2. La validación (formulario, edición de catálogo e importación CSV) acepta de **0 a 9,999.99%**, con
   el mensaje "La utilidad … debe estar entre 0 y 9,999.99%.". Los `<input>` llevan `max="9999.99"`.
3. El aviso no bloqueante de utilidad alta sigue a partir de 400% (`Articulo::UMBRAL_UTILIDAD_ALTA`).
4. `precio_proveedor` baja su tope de 9,000,000 a **900,000**, para que el precio de venta más alto
   posible (×100.9999, más el ajuste al peso entero) siga cabiendo en `decimal(10,2)`.

## Pruebas

- Una moldura de $10 con 1,624.14% se guarda con costo $10.00.
- 10000 se rechaza y 9999.99 se acepta (artículo, catálogo, distribuidor, importación).
- Tope: $900,000 con 9,999.99% da `90899911.21` sin IVA; 900,000.01 se rechaza.
