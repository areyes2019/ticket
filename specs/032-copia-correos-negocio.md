# Spec: Copia de los correos al buzón del negocio

> **Estado: implementada** el 2026-10-07. Ver "Estado de implementación".

**Modifica:** [011-cotizaciones.md](011-cotizaciones.md), [012-facturacion.md](012-facturacion.md),
[017-ordenes-compra.md](017-ordenes-compra.md) y [019-pedidos-mostrador.md](019-pedidos-mostrador.md)
(autofactura). Cada correo de documento que sale del sistema lleva además una **copia oculta** al
correo del negocio.

**No modifica:** los destinatarios que captura el usuario, el asunto, el cuerpo, los adjuntos, el
envío síncrono, los cambios de estado ni el manejo de errores de cada correo.

## Historia de usuario

Como dueño del negocio, quiero recibir en `ventas@sellopronto.com.mx` una copia de todos los correos
de órdenes de compra, cotizaciones y facturas que se envían, para tener en mi buzón el registro de
todo lo que salió, con sus adjuntos.

## Comportamiento

- Los tres correos de documento llevan copia: `CotizacionMail` (011), `OrdenCompraMail` (017) y
  `FacturaMail` (012), incluida la factura que manda la **autofactura** del pedido (019), porque usa
  el mismo `FacturaMail` vía `EnviadorCorreoFactura`.
- La copia es **oculta (CCO / Bcc)**: el cliente o el proveedor no ve el buzón del negocio entre los
  destinatarios y, si responde a todos, la respuesta no le llega al negocio por esa vía.
- La copia es idéntica a lo que recibe el destinatario: mismo asunto, cuerpo y adjuntos (PDF; XML y
  PDF en la factura). Sale en el mismo envío, así que no hay un segundo correo que pueda fallar por
  separado.
- Si el buzón del negocio ya va entre los destinatarios (sin importar mayúsculas), no se agrega la
  copia oculta, para no recibirlo dos veces.
- Si el correo no sale (falla el SMTP o, en la factura, no llega el XML de facturapi.io), tampoco
  sale la copia: es el mismo envío.
- El mensaje de éxito ("Cotización enviada a …") sigue nombrando solo a los destinatarios capturados.

## Configuración

- `config('negocio.copia_correos')`, leído de `NEGOCIO_COPIA_CORREOS` en `.env`. Valor de fábrica:
  `ventas@sellopronto.com.mx` (si la variable no existe, se usa ese). Queda documentado en
  `.env.example`.
- Con la variable **vacía** (`NEGOCIO_COPIA_CORREOS=`) no se manda copia.
- Es una sola dirección. Para cambiarla en producción basta editar `.env` y limpiar la caché de
  configuración (`php artisan config:clear` o `config:cache`).

## Backend (Laravel)

- **Trait `App\Mail\Concerns\CopiaAlNegocio`**, junto a `AdjuntaPdf`: un método
  `copiaAlNegocio(): list<Address>` que devuelve la dirección configurada, o `[]` si está vacía o ya
  va en `$this->to`.
  - Revisa la propiedad `$this->to` y **no** `hasTo()`: `hasTo()` vuelve a llamar a `envelope()` y
    se llamaría sin fin.
  - `$this->to` ya está lleno cuando se arma el sobre: `Mail::to(...)->send()` asigna los
    destinatarios antes de construir el mensaje.
- `CotizacionMail`, `OrdenCompraMail` y `FacturaMail` usan el trait y pasan
  `bcc: $this->copiaAlNegocio()` en su `Envelope`.
- Los controladores (`EnvioCotizacionController`, `EnvioOrdenCompraController`) y
  `EnviadorCorreoFactura` no cambian.
- `config/negocio.php` gana la clave `copia_correos`.

## Pruebas

`tests/Feature/CopiaCorreosNegocioTest.php`:

- Cotización, orden de compra y factura enviadas desde su pantalla llevan `hasBcc` del buzón del
  negocio, y la cotización no lo lleva en `cc`.
- Si el buzón ya va entre los destinatarios (escrito con otras mayúsculas), no se agrega la copia.
- Con `negocio.copia_correos` vacío no hay copia.
- Con el mailer `array` (un `MailManager` real, no el falso), el mensaje que se entrega al transporte
  tiene `Bcc` = el buzón del negocio, `To` = solo el cliente y `Cc` vacío. Es la prueba de que el
  encabezado sale de verdad y no solo en `Mail::fake()`.

Las pruebas existentes de envío (`EnvioCotizacionTest`, `EnvioOrdenCompraTest`,
`FacturaDocumentosTest`, `AutofacturaTest`) no cambian y siguen pasando.

## Fuera de alcance

- Copia de otros correos que no son documentos (recuperación de contraseña, avisos del sistema).
- Copia de lo que se comparte por WhatsApp o se descarga: no pasa por el servidor de correo.
- Varias direcciones de copia o configurarla desde la pantalla de Configuración.
- Copia visible (CC) en lugar de oculta.
- Registrar en la base de datos cada envío.

## Criterios de aceptación

1. Al enviar una cotización por correo, `ventas@sellopronto.com.mx` recibe el mismo correo con el PDF.
2. Al enviar una orden de compra por correo, `ventas@sellopronto.com.mx` recibe el mismo correo con
   el PDF.
3. Al enviar una factura por correo, desde Facturación o desde la autofactura del pedido,
   `ventas@sellopronto.com.mx` recibe el mismo correo con el XML y el PDF.
4. El cliente o proveedor no ve el buzón del negocio entre los destinatarios.
5. Si el usuario escribe el buzón del negocio como destinatario, llega una sola vez.
6. Cambiar `NEGOCIO_COPIA_CORREOS` cambia el buzón; dejarla vacía apaga la copia.

## Supuestos asumidos (registro completo)

1. "Copia" se entiende como copia oculta (CCO), para no exponer el buzón interno al cliente ni al
   proveedor.
2. Aplica también a la factura de la autofactura, que es un correo de factura.
3. La dirección vive en `.env` con `ventas@sellopronto.com.mx` de fábrica, no en la pantalla de
   Configuración.
4. Si el buzón ya es destinatario, no se duplica.

## Estado de implementación

Implementada el 2026-10-07: trait `CopiaAlNegocio`, `bcc` en los tres `Mailable`, clave
`negocio.copia_correos` y `NEGOCIO_COPIA_CORREOS` en `.env.example`, y
`CopiaCorreosNegocioTest` (6 pruebas). Suite completa en verde.

En producción (Hostinger) no hace falta tocar `.env`: sin la variable se usa el valor de fábrica.
