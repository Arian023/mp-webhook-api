# mp-webhook-api

Mercado Pago Point webhook processor built with Slim 4. Receives, validates, logs, and stores payment notifications from in-person terminals.

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     Mercado Pago Point Terminal                 │
└──────────────────────────────────┬──────────────────────────────┘
                                   │ POST /webhook
                                   ▼
┌─────────────────────────────────────────────────────────────────┐
│  RequestLoggerMiddleware → WebhookController → WebhookProcessor │
│                                                                 │
│  1. isAuthorized()   — HMAC-SHA256 signature validation         │
│  2. isValid()        — JSON structure per type                  │
│  3. Dispatch         — IntentWebhookHandler / PaymentHandler    │
│  4. webhook_logs     — Store full event record                  │
│  5. 200 response     — MP retries on non-2xx                    │
└──────────────────────────────────┬──────────────────────────────┘
                                   │
                    ┌──────────────┴──────────────┐
                    ▼                              ▼
         IntentWebhookHandler            PaymentWebhookHandler
         (point_integration_wh)          (payment — legacy)
                    │                              │
                    ├─ Create/update intent         ├─ Fetch payment from MP API
                    ├─ Fetch payment from MP API    ├─ Insert/update payment
                    ├─ Insert payment + charges     ├─ Insert charges
                    └─ Link intent ↔ payment        └─ Update related intent
```

### Layers

| Layer | Path | Description |
|---|---|---|
| HTTP | `app/Http/Controllers/` | Slim route handlers |
| Middleware | `app/Http/Middleware/` | Auth, logging, error handling |
| Application | `app/Application/Services/` | Business logic orchestration |
| Infrastructure | `app/Infrastructure/` | Database, MP API client, repositories |

## Tech Stack

- **PHP** >=8.3
- **Slim 4** — PSR-7/15 micro framework
- **PHP-DI** — Dependency injection container
- **Medoo** — Lightweight database abstraction
- **Guzzle 7** — HTTP client with retry middleware
- **Monolog 3** — Logging
- **Phinx** — Database migrations
- **Carbon** — Date/time handling

## Setup

### Prerequisites

- PHP 8.3+ with `pdo_mysql`, `json`, `mbstring`, `openssl` extensions
- MariaDB 10.6+ or MySQL 8.0+
- Composer 2.x

### Installation

```bash
git clone https://git.teseonet.duckdns.org/arian/mp-webhook-api.git
cd mp-webhook-api
composer install
cp .env.example .env
```

Edit `.env` with your credentials:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=mp_webhook
DB_USER=root
DB_PASS=

MP_ACCESS_TOKEN=APP_USR-...
MP_WEBHOOK_SECRET=your-webhook-secret
```

### Database

```bash
# Create the database
mysql -u root -p -e "CREATE DATABASE mp_webhook CHARACTER SET utf8mb4;"

# Run migrations
vendor/bin/phinx migrate
```

### Using Lerd (local dev)

This project includes Lerd configuration. If you use [Lerd](https://lerd.dev):

```bash
lerd link          # Register site at mp-webhook-api.test
lerd start         # Start PHP-FPM, MariaDB, Nginx
```

MariaDB is available at `lerd-mariadb-11-8:3306` from containers (user: `root`, pass: `lerd`).

## API Endpoints

### Webhook (no auth — signature validated)

```
POST /webhook?data.id={uuid}&type=point_integration_wh
```

Headers: `X-Request-Id`, `X-Signature: ts=...,v1=...`

### Intent Management (Bearer auth)

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/intents?device_id={id}` | Create payment intent |
| `GET` | `/api/intents/{id}` | Get intent status |
| `DELETE` | `/api/intents/{id}/cancel?device_id={id}` | Cancel intent |
| `POST` | `/api/intents/{id}/refund` | Refund intent's payment |

### Payment Management (Bearer auth)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/payments/{id}` | Get payment details |
| `POST` | `/api/payments/{id}/refund` | Refund payment |

### Health (no auth)

```
GET /health
```

Returns `{"status":"ok","database":"connected"}`.

## Webhook Flow

### `point_integration_wh` (intent notification)

Received when a Point terminal creates, completes, or cancels a payment intent.

```json
// Query: ?data.id=61ec4adf-...&type=point_integration_wh
// Body (state=FINISHED, has payment):
{
  "additional_info": {"external_reference": "000910854661-000910029286"},
  "amount": 3980000,
  "created_at": "2026-06-28 20:39:45",
  "id": "61ec4adf-82b3-414e-beb7-0308f131c63b",
  "payment": {"id": 165404232933, "state": "approved", "type": "credit_card"},
  "state": "FINISHED"
}
```

**Key**: `amount` is in **cents** (divide by 100 for ARS).

Processing steps:
1. Validate HMAC-SHA256 signature
2. Find or create intent in `intents` table
3. If `payment.id` exists → fetch full payment from `GET /v1/payments/{id}`
4. Insert payment + charges into DB
5. Link intent to payment
6. Log event to `webhook_logs`

### `payment` (legacy, rarely used)

Direct payment notification. Fetches payment details and stores them.

## Database Schema

Five tables managed by Phinx migrations:

| Table | Description |
|---|---|
| `terminals` | Registered Point devices (external_id, pos_id, branch) |
| `intents` | Payment intents (intent_id UUID, amount, status, linked payment) |
| `payments` | Payment records (payment_id, card details, amounts, timestamps) |
| `charges` | Fee and tax breakdown per payment |
| `webhook_logs` | Full webhook event log (payload, signature, response code) |

## Development

```bash
# Code style (Pint)
composer lint

# Static analysis (PHPStan level 5)
composer analyse

# Both
composer test
```

### API Examples

See `docs/examples/` for anonymized JSON responses from the Mercado Pago API. Useful as reference for webhook and payment structures.

## Project Structure

```
mp-webhook-api/
├── app/
│   ├── Application/Services/        # WebhookProcessor, handlers, IntentService, PaymentService
│   ├── Http/
│   │   ├── Controllers/             # Webhook, Intent, Payment, Health
│   │   └── Middleware/              # BearerAuth, RequestLogger, ErrorHandler
│   └── Infrastructure/
│       ├── Database.php             # Medoo factory
│       ├── Helpers/DateHelper.php   # Timezone conversion
│       ├── MercadoPago/             # Guzzle client + exception
│       └── Repositories/            # Terminal, Intent, Payment, WebhookLog
├── bootstrap/                       # Container, middleware, routes
├── database/migrations/             # Phinx migrations
├── docs/examples/                   # API response examples
├── public/index.php                 # Front controller
├── phinx.php                        # Phinx config
├── pint.json                        # Laravel Pint config
├── rector.php                       # Rector config
└── phpstan.neon.dist                # PHPStan config
```

## License

This project is released under the [MIT License](https://opensource.org/license/MIT).
