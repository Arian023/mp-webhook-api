# Guía de migración a Orders API: Point Smart y QR

Orders API unifica las operaciones de cobro presencial de Point y QR. Reemplaza
los contratos de Payment Intents e Instore, pero no cambia el flujo de negocio:
el cliente continúa pagando en la terminal o escaneando el código QR.

La migración requiere adaptar tanto el POS que crea los cobros como el receptor
de notificaciones. Cambiar únicamente la URL del webhook no migra la integración.
La disponibilidad y los requisitos deben consultarse para el país y la aplicación
del comercio; las referencias siguientes corresponden principalmente a Argentina.

## 1. Adaptar los endpoints

Las rutas de esta sección pertenecen a `https://api.mercadopago.com`, no a esta API local.

| Operación | Orders API |
|---|---|
| Crear una orden Point o QR | `POST /v1/orders` |
| Consultar una orden | `GET /v1/orders/{order_id}` |
| Cancelar una orden | `POST /v1/orders/{order_id}/cancel` |
| Reembolsar una orden | `POST /v1/orders/{order_id}/refund` |

Estos endpoints sustituyen las operaciones de cobro sobre
`/point/integration-api/.../payment-intents` y `/mpmobile/instore/qr/...`.
La cancelación pasa a operar sobre una orden específica, no sobre la caja QR.

Para Point, el listado de terminales pasa a `GET /terminals/v1/list` y su
configuración a `PATCH /terminals/v1/setup`. La terminal debe estar en modo `PDV`.

## 2. Adaptar la creación de cobros

| Concepto | Point | QR |
|---|---|---|
| Tipo de orden | `type: "point"` | `type: "qr"` |
| Destino del cobro | `config.point.terminal_id` | `config.qr.external_pos_id` |
| Configuración específica | Impresión mediante `config.point.print_on_terminal` | Modo mediante `config.qr.mode`: `static`, `dynamic` o `hybrid` |

- Los importes se expresan como strings en unidades monetarias: `"15.00"`, no
  los `1500` centavos de Payment Intents. Respetar los decimales admitidos por país.
- El pago se declara en `transactions.payments[].amount`. QR utiliza además
  `total_amount` e `items` según su contrato de creación.
- `external_reference` pasa a ser obligatorio: hasta 64 caracteres alfanuméricos,
  guiones y guiones bajos, sin datos personales.
- En Point, la impresión deja de ser un booleano: se utiliza `seller_ticket` o
  `no_ticket` dentro de `config.point`.
- QR Orders no acepta `notification_url` en el cuerpo. La URL se configura en
  los Webhooks de la aplicación.
- La vigencia se expresa mediante `expiration_time`, una duración ISO 8601 como
  `PT5M`. No trasladar sin más los headers o límites de expiración de legacy:
  consultar los valores permitidos para Point o QR.

## 3. Autenticación e idempotencia

Enviar el Access Token mediante `Authorization: Bearer <ACCESS_TOKEN>`.
Las operaciones de creación, cancelación y reembolso requieren además
`X-Idempotency-Key`; las consultas GET no lo requieren.

Persistir una clave por operación de negocio y reutilizarla en sus reintentos,
incluidos los posteriores a un timeout. Una operación distinta necesita otra
clave. Reutilizar una clave con un cuerpo diferente puede producir
`idempotency_key_already_used`.

El header legacy `x-test-scope: sandbox` no se utiliza en Orders. Las pruebas
se realizan con los usuarios y credenciales indicados para cada producto.

## 4. Migrar las notificaciones

En **Tus integraciones > Webhooks**, configurar una URL HTTPS y suscribir
**Order (Mercado Pago)**. Los eventos `payment`, `merchant_orders` y
`point_integration_wh` de legacy no sustituyen esta suscripción.

Los ejemplos de entrega utilizan `type=order`; algunas guías denominan el tópico
`orders`. Las acciones incluyen `order.processed`, `order.canceled`,
`order.refunded` y `order.expired`. Point también contempla
`order.action_required` y `order.failed`.

La notificación identifica la orden mediante `data.id`. Consultar
`GET /v1/orders/{order_id}` cuando se necesite el recurso completo y actualizar
tanto el estado general como los datos de sus transacciones.

### Firma y recepción

Validar `X-Signature` con la clave secreta de Webhooks y HMAC-SHA256. El
manifiesto documentado es:

```text
id:[data.id_url];request-id:[x-request-id_header];ts:[ts_header];
```

Para la firma, convertir el ID alfanumérico a minúsculas sin alterar el ID usado
para consultar la orden. Omitir del manifiesto los componentes opcionales ausentes
según la documentación y contemplar timestamps en milisegundos.

Mercado Pago espera HTTP 200 o 201 en hasta 22 segundos y reintenta entregas sin
confirmación. Diseñar la persistencia para soportar duplicados. Si el trabajo puede
exceder ese plazo, guardar la notificación de forma duradera antes de confirmar
y procesarla mediante un worker.

## 5. Adaptar estados e identificadores

- En Orders, el éxito se representa con `status: "processed"`; la transacción
  acreditada utiliza `status_detail: "accredited"`. No esperar el `approved`
  propio de Payments.
- `action_required` no implica aprobación: puede requerir comprobar el resultado
  en la terminal. Una solicitud de cancelación tampoco implica que la cancelación
  ya haya finalizado; consultar el estado o esperar su notificación.
- Distinguir el identificador de orden (`ORD...`), el de transacción (`PAY...`)
  y la referencia numérica del pago. No son intercambiables.
- Las consultas muestran la referencia del pago como `reference_id`; los ejemplos
  de notificaciones también incluyen `reference.id`. La referencia puede no existir
  antes del procesamiento: no fabricar un pago a partir del identificador `PAY...`.
- Mantener la moneda del recurso. Una orden QR no implica una terminal Point y
  no debe depender de un identificador de dispositivo para poder registrarse.
- No asumir que Orders expone todos los campos de tarjeta, comisiones e impuestos
  disponibles en Payments. Revisar las necesidades de conciliación contra su contrato.

## 6. Cancelaciones y reembolsos

Realizar estas operaciones mediante Orders API, no mediante Payments API.
Para un reembolso total, utilizar `POST /v1/orders/{order_id}/refund` sin cuerpo.
Para reembolsos parciales, consultar el contrato del producto e indicar la
transacción y el importe cuando corresponda.

Un reembolso parcial puede conservar `status: "processed"` con
`status_detail: "partially_refunded"`; no interpretar todo reembolso como devolución
total. Sincronizar el estado final a partir del recurso y sus notificaciones.

## Fuentes oficiales

- [Point legacy: aviso de sustitución](https://www.mercadopago.com/developers/es/docs/mp-point-legacy/overview)
- [Migración de Payment Intent a Orders](https://www.mercadopago.com/developers/es/docs/mp-point/resources/migrate-payment-intent-to-orders)
- [Notificaciones Point y validación HMAC](https://www.mercadopago.com/developers/es/docs/mp-point/notifications)
- [Estados de órdenes y transacciones Point](https://www.mercadopago.com/developers/es/docs/mp-point/resources/status-order-transaction)
- [Migración de QR Instore a Orders](https://www.mercadopago.com/developers/es/docs/qr-code/resources/migrate-instore-orders-to-orders)
- [Notificaciones QR](https://www.mercadopago.com/developers/es/docs/qr-code-ca/notifications)
