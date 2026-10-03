# Spec 024: Importación masiva de clientes por CSV

## Historia de usuario

Como usuario que viene de otro sistema, quiero subir de una sola vez el archivo donde guardaba mis
clientes, para no capturarlos uno por uno.

## Compatibilidad con el archivo del sistema anterior

| Columna del archivo                                                         | Campo del cliente           | Tratamiento                                                                 |
| --------------------------------------------------------------------------- | --------------------------- | --------------------------------------------------------------------------- |
| `RazonSocial`                                                               | `razon_social`              | Igual. Obligatoria.                                                         |
| `RFC`                                                                       | `rfc`                       | Sin espacios ni guiones, en mayúsculas. Obligatoria.                        |
| `RegimenFiscal`                                                             | `regimen_fiscal`            | Se toman los 3 primeros dígitos (`601`, `601 - General...`). Obligatoria.   |
| `CP`                                                                        | `codigo_postal_fiscal`      | Se rellena con ceros a la izquierda (Excel guarda `01000` como `1000`). Obligatoria. |
| `Email`                                                                     | `correo`                    | Si trae varios separados por `;` o `,`, se queda el primero.                |
| `Calle`, `NumExterior`, `NumInterior`, `Colonia`, `Ciudad`, `Municipio`, `Estado`, `Pais` | `direccion_comercial` | Se juntan en una línea: `Rio Guayalejo 1308 Int. 1-E, Col. Longoria, Reynosa, TAMAULIPAS`. El municipio se omite si repite la ciudad y el país si es México. |

Este sistema no guarda el domicilio desglosado (el CFDI 4.0 del receptor solo pide el CP), por eso las
columnas de domicilio se fusionan en la dirección comercial. También se aceptan `NombreComercial`,
`Contacto` y `Telefono`, y los nombres de columna propios de este sistema; los encabezados se comparan
sin mayúsculas, acentos, espacios ni guiones bajos. Las columnas desconocidas se ignoran.

## Comportamiento

- Pantalla `/clientes/importar`, con botón "Importar CSV" en `/clientes`.
- Cada fila se valida con las mismas reglas del formulario (`ClienteRequest::reglas`); las válidas se
  dan de alta y las inválidas se reportan con fila, RFC, razón social y motivo, sin detener el archivo.
- Un RFC que ya existe entre los clientes del usuario (o que se repite dentro del archivo) se rechaza
  como duplicado. Por eso volver a subir el archivo corregido no crea clientes dos veces.
- Los clientes importados quedan con descuento permanente 0.
- Acepta UTF-8 (con o sin BOM) y Windows-1252, separado por `,` o `;`, hasta 2 MB.

## Fuera de alcance

- Actualizar clientes existentes desde el CSV: solo da de alta.
- Validar el RFC o la razón social contra el SAT.

## Estado de implementación

Implementada el 2026-10-02.

- `app/Services/Clientes/ImportadorClientesCsv.php`, `app/Http/Controllers/ImportacionClientesController.php`,
  `app/Http/Requests/ImportarClientesRequest.php`, `resources/views/clientes/importar.blade.php`.
- `ClienteRequest` expone `reglas()`, `mensajes()` y `atributos()` estáticos para reutilizarlos fila por fila.
- La lectura del CSV (BOM y Windows-1252) se movió al trait `App\Services\Concerns\LeeArchivoCsv`, que
  ahora comparten los importadores de artículos y de clientes.
- Pruebas en `tests/Feature/ImportacionClientesTest.php`; Pint y la suite completa pasan.
