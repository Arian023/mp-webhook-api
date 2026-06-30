# mp-webhook-api

Procesador de webhooks de Mercado Pago Point construido con Slim 4. Recibe, valida, registra y almacena notificaciones de pago desde terminales presenciales.

## Arquitectura

```mermaid
%%{init: {'theme': 'base', 'themeVariables': { 'fontSize': '14px' }}}%%
graph TB
    subgraph "Mercado Pago"
        MP[Terminal Point]
        MPAPI[MP API<br/>api.mercadopago.com]
    end

    subgraph "mp-webhook-api (Slim 4)"
        direction TB
        WH[POST /webhook<br/>WebhookController]
        WP[WebhookProcessor]
        IA{isAuthorized?<br/>HMAC-SHA256}
        IV{isValid?<br/>JSON schema}
        IW[IntentWebhookHandler]
        PAYH[PaymentWebhookHandler]
        ORDH[OrderWebhookHandler]
        LOG[webhook_logs]
    end

    subgraph "Infraestructura"
        DB[(MySQL<br/>Medoo)]
        MPClient[Guzzle Client<br/>+ Middleware de Reintentos]
    end

    MP -->|"POST /webhook<br/>X-Signature"| WH
    WH --> WP
    WP --> IA
    IA -->|inválida| WP
    IA -->|válida| IV
    IV -->|inválida| WP
    IV -->|válida| IW
    IV -->|válida| PAYH
    IV -->|válida| ORDH

    IW --> MPClient
    PAYH --> MPClient
    ORDH --> MPClient
    MPClient --> MPAPI

    IW --> DB
    PAYH --> DB
    ORDH --> DB
    WP -->|registrar evento| LOG
    LOG --> DB
```

### Capas

| Capa | Ruta | Descripción |
|---|---|---|
| HTTP | `app/Http/Controllers/` | Manejadores de rutas Slim |
| Middleware | `app/Http/Middleware/` | Auth, logging, manejo de errores |
| Aplicación | `app/Application/Services/` | Orquestación de lógica de negocio |
| Infraestructura | `app/Infrastructure/` | Base de datos, cliente MP API, repositorios |

## Stack Tecnológico

- **PHP** >=8.3
- **Slim 4** — Micro framework PSR-7/15
- **PHP-DI** — Contenedor de inyección de dependencias
- **Medoo** — Abstracción de base de datos ligera
- **Guzzle 7** — Cliente HTTP con middleware de reintentos
- **Monolog 3** — Logging
- **Phinx** — Migraciones de base de datos
- **Carbon** — Manejo de fechas

## Instalación

### Prerrequisitos

- PHP 8.3+ con extensiones `pdo_mysql`, `json`, `mbstring`, `openssl`
- MariaDB 10.6+ o MySQL 8.0+
- Composer 2.x

### Pasos

```bash
git clone https://git.teseonet.duckdns.org/arian/mp-webhook-api.git
cd mp-webhook-api
composer install
cp .env.example .env
```

Editar `.env` con tus credenciales:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=mp_webhook
DB_USER=root
DB_PASS=

MP_ACCESS_TOKEN=APP_USR-...
MP_WEBHOOK_SECRET=tu-webhook-secret
```

### Base de datos

```bash
# Crear la base de datos
mysql -u root -p -e "CREATE DATABASE mp_webhook CHARACTER SET utf8mb4;"

# Ejecutar migraciones
vendor/bin/phinx migrate
```

### Con Lerd (desarrollo local)

Este proyecto incluye configuración para Lerd. Si usas [Lerd](https://lerd.dev):

```bash
lerd link          # Registrar sitio en mp-webhook-api.test
lerd start         # Iniciar PHP-FPM, MariaDB, Nginx
```

MariaDB está disponible en `lerd-mariadb-11-8:3306` desde los contenedores (usuario: `root`, contraseña: `lerd`).

## Endpoints de la API

### Webhook (sin auth — firma validada)

```
POST /webhook?data.id={uuid}&type=point_integration_wh
```

Headers: `X-Request-Id`, `X-Signature: ts=...,v1=...`

### Gestión de Intents (Bearer auth)

| Método | Endpoint | Descripción |
|---|---|---|
| `POST` | `/api/intents?device_id={id}` | Crear intent de pago |
| `GET` | `/api/intents/{id}` | Obtener estado del intent |
| `DELETE` | `/api/intents/{id}/cancel?device_id={id}` | Cancelar intent |
| `POST` | `/api/intents/{id}/refund` | Reembolsar pago del intent |

### Gestión de Pagos (Bearer auth)

| Método | Endpoint | Descripción |
|---|---|---|
| `GET` | `/api/payments/{id}` | Obtener detalles del pago |
| `POST` | `/api/payments/{id}/refund` | Reembolsar pago |

### Orders API (Bearer auth)

| Método | Endpoint | Descripción |
|---|---|---|
| `POST` | `/api/orders` | Crear orden ( Orders API) |
| `GET` | `/api/orders/{id}` | Obtener detalles de la orden |
| `POST` | `/api/orders/{id}/cancel` | Cancelar orden |
| `POST` | `/api/orders/{id}/refund` | Reembolsar orden |

### Health (sin auth)

```
GET /health
```

Retorna `{"status":"ok","database":"connected"}`.

## Flujo del Webhook

### `point_integration_wh` (notificación de intent)

Se recibe cuando una terminal Point crea, completa o cancela un intent de pago.

```mermaid
sequenceDiagram
    autonumber
    participant T as Terminal Point
    participant MP as Mercado Pago
    participant WH as POST /webhook
    participant WL as RequestLogger
    participant WP as WebhookProcessor
    participant IA as isAuthorized()
    participant IV as isValid()
    participant IW as IntentWebhookHandler
    participant MPAPI as MP API (Guzzle)
    participant DB as MySQL (Medoo)

    T->>MP: Pago completado
    MP->>WH: POST /webhook?data.id={uuid}&type=point_integration_wh
    Note right of WH: Headers: X-Request-Id, X-Signature

    WH->>WL: Registrar request (method, IP, path)
    WL->>WP: process(request)

    WP->>WP: Extraer query params (data.id, type)
    WP->>WP: Parsear body JSON

    WP->>IA: isAuthorized(request, dataId)
    Note right of IA: Parsear X-Signature: ts=...,v1=...<br/>HMAC-SHA256(secret, manifest)
    IA-->>WP: true / false

    WP->>IV: isValid(body, type)
    Note right of IV: Verificar campos requeridos:<br/>id, amount, state
    IV-->>WP: true / false

    alt Firma válida Y body válido
        WP->>IW: handle(body, dataId)

        IW->>DB: SELECT intents WHERE intent_id = dataId
        alt Intent no encontrado
            IW->>DB: INSERT INTO intents (intent_id, amount, status, ...)
        end

        alt body.payment.id existe
            IW->>MPAPI: GET /v1/payments/{paymentId}
            MPAPI-->>IW: Detalles del pago (200+ campos)

            IW->>DB: SELECT payments WHERE payment_id = ?
            alt Pago no encontrado
                IW->>DB: INSERT INTO payments (payment_id, status, card, ...)
                IW->>DB: INSERT INTO charges (charge_id, type, amount, ...)
            else Pago existente
                IW->>DB: UPDATE payments SET status = ?, ...
            end

            IW->>DB: UPDATE intents SET payment_id = ?, payment_method = ?, ...
        else Sin payment.id
            IW->>DB: UPDATE intents SET intent_status = state
        end
    end

    WP->>DB: INSERT INTO webhook_logs (event_type, payload, signature_valid, ...)
    WP-->>WH: { message: "Webhook processed", type: "..." }
    WH-->>MP: 200 OK
```

```json
// Query: ?data.id=61ec4adf-...&type=point_integration_wh
// Body (state=FINISHED, tiene pago):
{
  "additional_info": {"external_reference": "000910854661-000910029286"},
  "amount": 3980000,
  "created_at": "2026-06-28 20:39:45",
  "id": "61ec4adf-82b3-414e-beb7-0308f131c63b",
  "payment": {"id": 165404232933, "state": "approved", "type": "credit_card"},
  "state": "FINISHED"
}
```

**Clave**: `amount` está en **centavos** (dividir por 100 para ARS).

Pasos de procesamiento:
1. Validar firma HMAC-SHA256
2. Buscar o crear intent en tabla `intents`
3. Si existe `payment.id` → obtener pago completo de `GET /v1/payments/{id}`
4. Insertar pago + cargos en la DB
5. Vincular intent al pago
6. Registrar evento en `webhook_logs`

### `payment` (legacy, uso infrecuente)

Notificación directa de pago. Obtiene los detalles del pago y los almacena.

### `order` (Orders API)

Notificación de estado de orden. Recibe eventos de orden como `order.created`, `order.updated`, `order.canceled`. Obtiene la orden completa de MP API, almacena/actualiza en la DB local y sincroniza los pagos asociados.

## Esquema de Base de Datos

Seis tablas gestionadas por migraciones Phinx:

```mermaid
erDiagram
    terminals {
        INT id PK
        VARCHAR external_id UK "Device ID de MP"
        VARCHAR pos_id "POS ID en MP"
        VARCHAR serial_number "Número de serie"
    }

    intents {
        INT id PK
        VARCHAR intent_id UK "UUID del intent en MP"
        VARCHAR device_id "device_id de la terminal"
        DECIMAL amount "Monto en ARS"
        VARCHAR intent_status "OPEN / FINISHED / CANCELED"
        BIGINT payment_id "ID de pago MP"
    }

    payments {
        INT id PK
        BIGINT payment_id UK "ID de pago MP"
        VARCHAR intent_id "UUID del intent en MP"
        INT terminal_id FK "FK a terminals"
        VARCHAR status "approved / rejected / refunded"
        DECIMAL total_amount "Monto en ARS"
        VARCHAR payment_method "visa, debvisa"
        VARCHAR card_last_four "Últimos 4 dígitos"
        DATETIME created_at_mp "date_created (conv. tz)"
    }

    charges {
        INT id PK
        INT payment_id FK "FK a payments"
        VARCHAR charge_id UK "ID del cargo en MP"
        VARCHAR type "fee / tax"
        DECIMAL amount "Monto original"
        DECIMAL rate "Porcentaje de comisión"
    }

    webhook_logs {
        BIGINT id PK
        VARCHAR request_id "X-Request-Id"
        VARCHAR event_type "order / payment / point_integration_wh"
        JSON payload "Body completo del webhook"
        TINYINT signature_valid "0 o 1"
    }

    terminals ||--o{ intents : "device_id"
    terminals ||--o{ payments : "terminal_id"
    intents ||--o| payments : "payment_id"
    payments ||--o{ charges : "payment_id"
```

| Tabla | Descripción |
|---|---|
| `terminals` | Terminales Point registradas (external_id, pos_id, sucursal) |
| `intents` | Intents de pago (intent_id UUID, monto, estado, pago vinculado) |
| `payments` | Registros de pago (payment_id, datos de tarjeta, montos, timestamps) |
| `charges` | Desglose de comisiones e impuestos por pago |
| `orders` | Registros de Orders API (order_id, estado, device_id, detalles de pago) |
| `webhook_logs` | Log completo de eventos webhook (payload, firma, código de respuesta) |

## Desarrollo

```bash
# Estilo de código (Pint)
composer lint

# Análisis estático (PHPStan nivel 5)
composer analyse

# Ambos
composer test
```

### Ejemplos de API

Ver `docs/examples/` para respuestas JSON anonimizadas de la API de Mercado Pago. Útiles como referencia para las estructuras de webhook y pago.

## Estructura del Proyecto

```
mp-webhook-api/
├── app/
│   ├── Application/Services/        # WebhookProcessor, handlers, IntentService, PaymentService
│   ├── Http/
│   │   ├── Controllers/             # Webhook, Intent, Payment, Health
│   │   └── Middleware/              # BearerAuth, RequestLogger, ErrorHandler
│   └── Infrastructure/
│       ├── Database.php             # Factory Medoo
│       ├── Helpers/DateHelper.php   # Conversión de zona horaria
│       ├── MercadoPago/             # Cliente Guzzle + excepción
│       └── Repositories/            # Terminal, Intent, Payment, WebhookLog
├── bootstrap/                       # Container, middleware, rutas
├── database/migrations/             # Migraciones Phinx
├── docs/examples/                   # Ejemplos de respuestas API
├── public/index.php                 # Front controller
├── phinx.php                        # Config Phinx
├── pint.json                        # Config Laravel Pint
├── rector.php                       # Config Rector
└── phpstan.neon.dist                # Config PHPStan
```

## Licencia

Este proyecto está bajo la [licencia MIT](https://opensource.org/license/MIT).
