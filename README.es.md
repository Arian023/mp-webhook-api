# mp-webhook-api

Procesador de webhooks de Mercado Pago Point construido con Slim 4. Recibe, valida, registra y almacena notificaciones de pago desde terminales presenciales.

## Arquitectura

```
┌─────────────────────────────────────────────────────────────────┐
│                  Terminal Mercado Pago Point                    │
└──────────────────────────────────┬──────────────────────────────┘
                                   │ POST /webhook
                                   ▼
┌─────────────────────────────────────────────────────────────────┐
│  RequestLoggerMiddleware → WebhookController → WebhookProcessor │
│                                                                 │
│  1. isAuthorized()   — Validación de firma HMAC-SHA256          │
│  2. isValid()        — Estructura JSON por tipo                 │
│  3. Dispatch         — IntentWebhookHandler / PaymentHandler    │
│  4. webhook_logs     — Almacenar registro completo del evento   │
│  5. Respuesta 200    — MP reintenta si no recibe 2xx            │
└──────────────────────────────────┬──────────────────────────────┘
                                   │
                    ┌──────────────┴──────────────┐
                    ▼                              ▼
         IntentWebhookHandler            PaymentWebhookHandler
         (point_integration_wh)          (payment — legacy)
                    │                              │
                    ├─ Crear/actualizar intent      ├─ Obtener pago de MP API
                    ├─ Obtener pago de MP API       ├─ Insertar/actualizar pago
                    ├─ Insertar pago + cargos       ├─ Insertar cargos
                    └─ Vincular intent ↔ pago       └─ Actualizar intent relacionado
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

### Health (sin auth)

```
GET /health
```

Retorna `{"status":"ok","database":"connected"}`.

## Flujo del Webhook

### `point_integration_wh` (notificación de intent)

Se recibe cuando una terminal Point crea, completa o cancela un intent de pago.

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

## Esquema de Base de Datos

Cinco tablas gestionadas por migraciones Phinx:

| Tabla | Descripción |
|---|---|
| `terminals` | Terminales Point registradas (external_id, pos_id, sucursal) |
| `intents` | Intents de pago (intent_id UUID, monto, estado, pago vinculado) |
| `payments` | Registros de pago (payment_id, datos de tarjeta, montos, timestamps) |
| `charges` | Desglose de comisiones e impuestos por pago |
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

### Ejemplos de API Censurados

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
├── docs/examples/                   # Ejemplos censurados de respuestas API
├── public/index.php                 # Front controller
├── phinx.php                        # Config Phinx
├── pint.json                        # Config Laravel Pint
├── rector.php                       # Config Rector
└── phpstan.neon.dist                # Config PHPStan
```

## Licencia

Privado — no es código abierto.
