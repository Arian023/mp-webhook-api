# mp-webhook-api — Rewrite Plan

Portfolio rewrite of Mercado Pago Point integration webservice.
Demonstrates a clean webhook processing flow: **receive → validate → respond → log → store**.

---

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Framework | Slim 4 + PHP-DI | PSR-7/15 compliant, DI-native, lightweight |
| PHP version | 8.4 | Typed class constants, property hooks; not 8.5 for library compat |
| DB abstraction | Medoo | Same as original; lightweight, familiar |
| Migrations | Phinx | Schema versioning with expressive column names |
| HTTP client | Guzzle 7 + retry middleware | Same as original |
| Logging | Monolog 3 | Same as original |
| MP API version | **Legacy Point Integration API** (`point/integration-api`) | See note below |

### API Version Note

The rewrite uses the **legacy Point Integration API** (same as original):
- `POST /point/integration-api/devices/{id}/payment-intents` (create intent)
- `GET /point/integration-api/payment-intents/{id}` (get intent)
- `DELETE /point/integration-api/devices/{id}/payment-intents/{intentId}` (cancel)
- `GET /v1/payments/{id}` (get payment — this endpoint is shared)
- `POST /v1/payments/{id}/refunds` (refund)
- Webhook types: `point_integration_wh`, `payment`

Mercado Pago is migrating to a new **Orders API** (`v1/orders`, webhook type `order` with actions like `order.processed`, `order.canceled`, etc.). The architecture is designed so that adding a new webhook handler (e.g., `OrderWebhookHandler`) requires minimal changes — just a new handler class registered in the dispatcher.

### Column naming convention

Original columns used 3-char prefixes (`mpppag`, `mpiein`, etc.) due to legacy DB limits.
New columns use full English names: `payment_id`, `intent_status`, `created_at`, etc.

---

## Reference: Actual API Payloads

Source: production JSON examples in `docs/examples/API MP/` + debug log.

### Webhook `point_integration_wh` (intent notification)

Received as `POST /?data.id={uuid}&type=point_integration_wh` with headers `X-Request-Id`, `X-Signature`.

Body (state=FINISHED, has payment):
```json
{
  "additional_info": {"external_reference": "000910854661-000910029286"},
  "amount": 3980000,
  "caller_id": 839851763,
  "client_id": 7669109918673685,
  "created_at": "2026-06-28 20:39:45",
  "id": "61ec4adf-82b3-414e-beb7-0308f131c63b",
  "intent_type": "payment",
  "payment": {"id": 165404232933, "state": "approved", "type": "credit_card"},
  "state": "FINISHED"
}
```

Body (state=OPEN, no payment):
```json
{
  "additional_info": {"external_reference": "TestCancelar-15.26-2024.11.12", "ticket_number": "TestCancelar-15.26"},
  "amount": 1500,
  "device_id": "PAX_A910__SMARTPOS1495319707",
  "id": "39afd045-c7e7-4466-b15c-7de1a06986e2",
  "payment_mode": "card",
  "state": "OPEN"
}
```

**Key**: `amount` is in **centavos** (divide by 100 for ARS).

### `GET /point/integration-api/payment-intents/{id}`

```json
{
  "additional_info": {"external_reference": "...", "print_on_terminal": true, "ticket_number": "..."},
  "amount": 1500,
  "device_id": "PAX_A910__SMARTPOS1495319707",
  "id": "0c2b41c8-b71d-4233-abe0-187c7b26b18d",
  "payment": {"id": "93258976204", "installments": 1, "type": "credit_card"},
  "payment_mode": "card",
  "state": "FINISHED"
}
```

Error (400):
```json
{"message": "Intent ID is not valid...", "error": "116", "status": 400, "cause": []}
```

### `POST /point/integration-api/devices/{id}/payment-intents`

Response (201):
```json
{
  "id": "7f25f9aa-eea6-4f9c-bf16-a341f7123456",
  "device_id": "INGENICO_MOVE2500__12345678",
  "amount": 6000,
  "additional_info": {"external_reference": "...", "print_on_terminal": true, "ticket_number": "ABC123"}
}
```

### `GET /v1/payments/{id}`

Large response (~200 lines). Key fields used by the app:

| JSON path | Type | DB column (new) | Notes |
|---|---|---|---|
| `id` | int (11 digits) | `payment_id` | Primary business key |
| `status` | string | `status` | approved, rejected, refunded, cancelled |
| `status_detail` | string | `status_detail` | accredited, cc_rejected_insufficient_amount... |
| `payment_type_id` | string | `payment_type` | credit_card, debit_card, account_money |
| `payment_method_id` | string | `payment_method` | visa, debvisa, account_money... |
| `installments` | int | `installments` | |
| `currency_id` | string | `currency` | ARS |
| `authorization_code` | string | `authorization_code` | 6 chars |
| `transaction_amount` | decimal | `total_amount` | In ARS (not cents) |
| `transaction_details.net_received_amount` | decimal | `net_received` | |
| `transaction_details.overpaid_amount` | decimal | `overpaid_amount` | |
| `transaction_details.installment_amount` | decimal | `installment_amount` | |
| `taxes_amount` | decimal | `taxes_amount` | |
| `coupon_amount` | decimal | `coupon_amount` | |
| `date_created` | datetime (-04:00) | `created_at_mp` | Needs timezone conversion |
| `date_approved` | datetime or null | `approved_at` | Needs timezone conversion |
| `money_release_status` | string | `release_status` | pending, released |
| `money_release_date` | datetime or null | `released_at` | Needs timezone conversion |
| `live_mode` | bool | `is_live` | Store as S/N |
| `point_of_interaction.device.serial_number` | string | `device_serial` | Maps to terminal via `PAX_A910__{serial}` |
| `point_of_interaction.type` | string | `interaction_type` | POINT, INSTORE |
| `issuer_id` | string | `issuer_id` | |
| `payer.id` | string | `payer_id` | |
| `store_id` | string | `store_id` | |
| `pos_id` | string | `pos_id` | Maps to `mptpos` in terminals |
| `metadata.payment_intent_id` | string | `intent_id` | Links back to intent UUID |
| `external_reference` | string | `external_ref` | format: `000910854661-000910029286` |
| `card.bin` | string (8 digits) | `card_bin` | |
| `card.first_six_digits` | string | `card_first_six` | |
| `card.last_four_digits` | string | `card_last_four` | |
| `card.country` | string or null | `card_country` | |
| `card.cardholder.name` | string | `holder_name` | May have trailing spaces |
| `card.cardholder.identification.type` | string or null | `holder_doc_type` | |
| `card.cardholder.identification.number` | int or null | `holder_doc_number` | |
| `charges_details[]` | array | `charges` table | See below |

**Note on QR payments**: `point_of_interaction.type` = `INSTORE`, `point_of_interaction.device` may be absent.
Terminal lookup falls back to `pos_id` matching `mptpos` in terminals table.

### `charges_details[]` (nested in payment response)

Each charge:
```json
{
  "id": "165404232933-001",
  "type": "fee",
  "name": "mercadopago_fee",
  "amounts": {"original": 866.84, "refunded": 0},
  "rate": 2.18,
  "accounts": {"from": "collector", "to": ""},
  "date_created": "2026-06-28T17:40:01.000-04:00"
}
```

Types seen: `fee` (mercadopago_fee), `tax` (tax_withholding_collector-debitos_creditos, tax_withholding_sirtac_noinsc-neuquen).

### `POST /v1/payments/{id}/refunds`

Response (201):
```json
{
  "id": 1932573904,
  "payment_id": 93258976204,
  "amount": 15,
  "status": "approved",
  "date_created": "2024-11-13T11:09:37.895-04:00",
  "source": {"id": "2091250161", "name": "Arian Alberto Acevedo", "type": "collector"},
  "refund_mode": "standard",
  "amount_refunded_to_payer": 15
}
```

### `GET /v1/payments/{id}/refunds`

Returns array of refund objects (same structure as above).

### `GET /point/integration-api/devices`

Returns array of device objects. (Example file is empty — not critical for MVP.)

---

## Phase 0 — Project Skeleton

- [x] `git init` in `/home/arian/dev/mp-webhook-api`
- [x] `composer init` with:
  - name: `arian/mp-webhook-api`
  - description: "Mercado Pago Point webhook processor — Slim 4"
  - require PHP ^8.4
  - autoload PSR-4: `App\\` → `app/`
- [x] Install dependencies:
  - `slim/slim ^4`
  - `slim/psr7 ^1.7`
  - `php-di/php-di ^7.0`
  - `php-di/slim-bridge ^3.5`
  - `catfan/medoo ^2.1`
  - `guzzlehttp/guzzle ^7.9`
  - `caseyamcl/guzzle_retry_middleware ^2.12`
  - `monolog/monolog ^3.8`
  - `symfony/dotenv ^7.1`
  - `nesbot/carbon ^3.8`
- [x] Dev dependencies:
  - `robmorgan/phinx` (migrations)
  - `friendsofphp/php-cs-fixer` or `laravel/pint` (code style)
  - `phpstan/phpstan` (static analysis)
- [x] Create `.gitignore`:
  ```
  /vendor/
  /logs/
  /node_modules/
  .env
  .env.local
  .env.*.local
  composer.lock
  .php-cs-fixer.cache
  .phpunit.result.cache
  ```
- [x] Create `.env.example`:
  ```env
  APP_ENV=development
  APP_DEBUG=true
  LOG_LEVEL=debug

  DB_HOST=localhost
  DB_NAME=mp_webhook
  DB_USER=root
  DB_PASS=
  DB_CHARSET=utf8mb4

  MP_ACCESS_TOKEN=
  MP_WEBHOOK_SECRET=
  ```
- [x] Create `public/index.php` (front controller)
- [x] Create `bootstrap/container.php` (PHP-DI definitions)
- [x] Create `bootstrap/middleware.php` (middleware pipeline)
- [x] Create `bootstrap/routes.php` (route definitions)
- [x] Initial commit

---

## Phase 1 — Database Schema (Phinx)

- [x] Install and configure Phinx (`phinx init`)
- [x] Create migration: `terminals` table (was `mpt`)
  ```sql
  CREATE TABLE terminals (
      id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      external_id     VARCHAR(50)  NOT NULL UNIQUE COMMENT 'Device ID from MP (model + serial)',
      pos_id          VARCHAR(50)  NULL     COMMENT 'POS ID in MP',
      branch_code     SMALLINT UNSIGNED NULL COMMENT 'Branch number',
      register_number SMALLINT UNSIGNED NULL COMMENT 'Cash register number',
      operating_mode  VARCHAR(50)  NULL     COMMENT 'Operating mode',
      description     VARCHAR(50)  NULL     COMMENT 'Model / description',
      serial_number   VARCHAR(50)  NULL     COMMENT 'Device serial number',
      updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      updated_by      VARCHAR(40)  NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
- [x] Create migration: `intents` table (was `mpi`)
  ```sql
  CREATE TABLE intents (
      id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      intent_id         VARCHAR(50)  NOT NULL UNIQUE COMMENT 'MP intent UUID',
      device_id         VARCHAR(50)  NOT NULL     COMMENT 'Terminal device_id (from webhook or API)',
      external_ref      VARCHAR(50)  NOT NULL     COMMENT 'External reference (register-invoice)',
      amount            DECIMAL(12,2) NOT NULL    COMMENT 'Total amount in ARS (webhook sends cents, divide by 100)',
      intent_status     VARCHAR(50)  NOT NULL     COMMENT 'Intent state: OPEN, FINISHED, CANCELED, ABANDONED, ERROR',
      reject_reason     CHAR(1)      NULL        COMMENT 'Reject reason: C=installments, M=amount, F=payment method, X=other',
      payment_method    VARCHAR(50)  NULL        COMMENT 'Payment method name (e.g. visa)',
      installments      TINYINT UNSIGNED NULL     COMMENT 'Number of installments',
      payment_status    VARCHAR(50)  NULL        COMMENT 'Payment status from MP (e.g. approved)',
      payment_detail    VARCHAR(50)  NULL        COMMENT 'Payment status detail (e.g. accredited)',
      payment_id        BIGINT UNSIGNED NULL      COMMENT 'MP payment ID (11 digits)',
      ticket_number     VARCHAR(20)  NULL        COMMENT 'Invoice number (from additional_info.ticket_number)',
      register_number   SMALLINT UNSIGNED NULL    COMMENT 'Cash register number (from terminals table)',
      created_at        DATETIME     NOT NULL     COMMENT 'Intent creation timestamp (from webhook created_at or CURRENT_TIMESTAMP)',
      updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      updated_by        VARCHAR(10)  NOT NULL DEFAULT 'api'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
  **Notes**:
  - `amount`: webhook sends in centavos (e.g. 3980000 = $39,800.00). Divide by 100 before INSERT.
  - `device_id`: may be absent in webhook when state != OPEN. Available in intent GET response.
  - `ticket_number`: nullable — may be absent in webhook body.
  - `register_number`: populated from `terminals` table lookup, not from webhook directly.
- [x] Create migration: `payments` table (was `mpp`)
  ```sql
  CREATE TABLE payments (
      id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      payment_id          BIGINT UNSIGNED NOT NULL UNIQUE COMMENT 'MP payment/operation ID (11 digits)',
      intent_id           VARCHAR(50)   NULL     COMMENT 'MP intent UUID (from metadata.payment_intent_id)',
      terminal_id         INT UNSIGNED  NULL     COMMENT 'FK to terminals.id',
      external_ref        VARCHAR(50)   NOT NULL COMMENT 'External reference (e.g. 000910854661-000910029286)',
      status              VARCHAR(50)   NOT NULL COMMENT 'Payment status: approved, rejected, refunded, cancelled',
      status_detail       VARCHAR(50)   NOT NULL COMMENT 'e.g. accredited, cc_rejected_insufficient_amount',
      payment_type        VARCHAR(50)   NULL     COMMENT 'Payment type: credit_card, debit_card, account_money',
      payment_method      VARCHAR(50)   NULL     COMMENT 'Payment method: visa, debvisa, account_money',
      register_number     SMALLINT UNSIGNED NULL COMMENT 'Cash register number',
      card_number         SMALLINT UNSIGNED NULL COMMENT 'Card number (tac)',
      total_amount        DECIMAL(12,2) NOT NULL COMMENT 'Total amount in ARS (NOT cents)',
      net_received        DECIMAL(12,2) NULL     COMMENT 'Net received amount',
      overpaid_amount     DECIMAL(12,2) NULL     COMMENT 'Overpaid amount',
      taxes_amount        DECIMAL(12,2) NULL     COMMENT 'Taxes amount',
      coupon_amount       DECIMAL(12,2) NULL     COMMENT 'Coupon discount amount',
      installment_amount  DECIMAL(12,2) NULL     COMMENT 'Per-installment amount',
      installments        TINYINT UNSIGNED NULL  COMMENT 'Number of installments',
      currency            VARCHAR(5)    NULL     DEFAULT 'ARS',
      authorization_code  VARCHAR(6)    NULL     COMMENT 'Authorization code (e.g. 025854)',
      card_bin            VARCHAR(8)    NULL     COMMENT 'Card BIN (first 6-8 digits, e.g. 45464000)',
      card_first_six      VARCHAR(6)    NULL     COMMENT 'First 6 digits (e.g. 454640)',
      card_last_four      VARCHAR(4)    NULL     COMMENT 'Last 4 digits (e.g. 1023)',
      card_country        VARCHAR(50)   NULL     DEFAULT 'ARG',
      holder_doc_type     VARCHAR(50)   NULL     COMMENT 'Cardholder ID type',
      holder_doc_number   VARCHAR(20)   NULL     COMMENT 'Cardholder ID number',
      holder_name         VARCHAR(60)   NULL     COMMENT 'Cardholder name (may have trailing spaces)',
      created_at_mp       DATETIME      NOT NULL COMMENT 'date_created from MP (timezone-converted)',
      approved_at         DATETIME      NULL     COMMENT 'date_approved from MP (timezone-converted)',
      release_status      VARCHAR(50)   NULL     COMMENT 'Money release status: pending, released',
      released_at         DATETIME      NULL     COMMENT 'Money release date (timezone-converted)',
      is_live             CHAR(1)       NULL     COMMENT 'S=production, N=sandbox (from live_mode)',
      device_serial       VARCHAR(50)   NULL     COMMENT 'point_of_interaction.device.serial_number',
      interaction_type    VARCHAR(50)   NULL     DEFAULT 'POINT' COMMENT 'POINT or INSTORE (QR)',
      issuer_id           BIGINT UNSIGNED NULL   COMMENT 'Card issuer ID',
      payer_id            BIGINT UNSIGNED NULL   COMMENT 'Payer ID',
      store_id            BIGINT UNSIGNED NULL   COMMENT 'Store ID',
      pos_id              BIGINT UNSIGNED NULL   COMMENT 'POS ID',
      updated_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      updated_by          VARCHAR(10)   NOT NULL DEFAULT 'api'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
  **Notes**:
  - `card_bin`, `card_first_six`, `card_last_four` are VARCHAR, not INT — MP returns them as strings.
  - `holder_doc_number` is VARCHAR — some IDs have leading zeros or letters.
  - `total_amount` is in ARS (not cents). Webhook `amount` field (cents) must be divided by 100 before storing.
  - `created_at_mp`, `approved_at`, `released_at` need timezone conversion from MP's -04:00 to Argentina/Buenos_Aires.
- [x] Create migration: `charges` table (was `mpc`)
  ```sql
  CREATE TABLE charges (
      id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      payment_id      INT UNSIGNED  NOT NULL COMMENT 'FK to payments.id',
      charge_id       VARCHAR(30)   NOT NULL UNIQUE COMMENT 'MP charge ID (e.g. 165404232933-001)',
      payment_ref     BIGINT UNSIGNED NOT NULL COMMENT 'MP payment ID',
      type            VARCHAR(30)   NULL     COMMENT 'Charge type: fee, tax',
      description     VARCHAR(80)   NULL     COMMENT 'Charge name (e.g. mercadopago_fee, tax_withholding_collector-debitos_creditos)',
      amount          DECIMAL(12,2) NULL     COMMENT 'Original amount',
      refunded        DECIMAL(12,2) NULL     COMMENT 'Refunded amount (0 if not refunded)',
      rate            DECIMAL(6,2)  NULL     COMMENT 'Rate percentage (e.g. 2.18, 0.60, 3.00)',
      from_account    VARCHAR(50)   NULL     COMMENT 'Debited from (usually "collector")',
      to_account      VARCHAR(50)   NULL     COMMENT 'Credited to (usually "mp" or empty)',
      created_at_mp   DATETIME      NULL     COMMENT 'date_created from MP (timezone-converted)',
      updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      FOREIGN KEY (payment_id) REFERENCES payments(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
  **Notes**:
  - `amounts.refunded` is 0 (integer) when not refunded, decimal when refunded. Store as DECIMAL.
  - `rate` comes as decimal string (e.g. "2.18"). Store as DECIMAL(6,2).
  - `description` can be long (e.g. `tax_withholding_sirtac_noinsc-neuquen`), use VARCHAR(80).
- [x] Create migration: `webhook_logs` table (new — replaces flat file logging for webhook events)
  ```sql
  CREATE TABLE webhook_logs (
      id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      request_id      VARCHAR(36)   NOT NULL COMMENT 'X-Request-Id from MP',
      event_type      VARCHAR(50)   NOT NULL COMMENT 'order, payment, etc.',
      action          VARCHAR(50)   NULL     COMMENT 'order.processed, order.canceled...',
      data_id         VARCHAR(50)   NOT NULL COMMENT 'data.id from query params',
      payload         JSON          NOT NULL COMMENT 'Full webhook body',
      signature_valid TINYINT(1)    NOT NULL DEFAULT 0,
      response_code   SMALLINT      NOT NULL DEFAULT 200,
      processed_at    DATETIME      NOT NULL,
      created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_data_id (data_id),
      INDEX idx_event_type (event_type),
      INDEX idx_processed_at (processed_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
- [x] Seed migration with sample `terminals` data (optional, for /health test)
- [x] Copy `api_mp schema.csv` to project root as reference

---

## Phase 2 — Infrastructure Layer

### 2.1 Database

- [x] `app/Infrastructure/Database.php`
  - Factory class that reads DB config from env vars
  - Returns configured `Medoo\Medoo` instance
  - Registered in PHP-DI container as singleton

### 2.2 Repositories

Each repository wraps Medoo queries for one table. Methods return arrays (decoded JSON) or throw.

- [x] `app/Infrastructure/Repositories/TerminalRepository.php`
  - `findByExternalId(string $externalId): ?array`
  - `findByPosId(string $posId): ?array`
  - `findById(int $id): ?array`

- [x] `app/Infrastructure/Repositories/IntentRepository.php`
  - `findByIntentId(string $intentId): ?array`
  - `create(array $data): int` (returns insert ID)
  - `updateStatus(string $intentId, string $status): bool`
  - `updateWithPayment(string $intentId, array $paymentData): bool`

- [x] `app/Infrastructure/Repositories/PaymentRepository.php`
  - `findByPaymentId(int $paymentId): ?array`
  - `create(array $data): int`
  - `update(int $paymentId, array $data): bool`
  - `insertCharges(int $paymentInternalId, int $paymentRef, array $charges): void`

- [x] `app/Infrastructure/Repositories/WebhookLogRepository.php`
  - `log(array $data): int` — insert webhook event record

### 2.3 Mercado Pago HTTP Client

- [x] `app/Infrastructure/MercadoPago/MercadoPagoClient.php`
  - Constructor: base_uri `https://api.mercadopago.com`, retry middleware (5 attempts, 25s timeout)
  - `createIntent(string $deviceId, array $body): array` — POST `/point/integration-api/devices/{deviceId}/payment-intents`
  - `getIntent(string $intentId): array` — GET `/point/integration-api/payment-intents/{intentId}`
  - `cancelIntent(string $deviceId, string $intentId): array` — DELETE `/point/integration-api/devices/{deviceId}/payment-intents/{intentId}`
  - `getPayment(int $paymentId): array` — GET `/v1/payments/{paymentId}` (large response, ~200 lines)
  - `refundPayment(int $paymentId): array` — POST `/v1/payments/{paymentId}/refunds`
  - All methods: throw `MercadoPagoException` on non-2xx, log retries
  - Registered in container with `MP_ACCESS_TOKEN` from env
  - **Retry logic**: retry on timeout or status not in [200, 201, 400, 404, 409] (matches original)

### 2.4 Helpers

- [x] `app/Infrastructure/Helpers/DateHelper.php`
  - `toLocal(string $mpDate): string` — convert MP timezone (-04:00) to Argentina/Buenos_Aires
  - `formatForDb(string $date): string` — Y-m-d H:i:s
  - `calculateDelay(string $createdAt): string` — "2.1 seg" / "1.5 min" / "0.3 hs"

---

## Phase 3 — Application Layer (Services)

### 3.1 Webhook Processing (Core Flow)

- [x] `app/Application/Services/WebhookProcessor.php`
  - **This is the showcase class.** Orchestrates the entire webhook flow.
  - `process(ServerRequestInterface $request): array` — main entry point
    1. Extract query params: `data.id` (intent UUID), `type` (e.g. `point_integration_wh`)
    2. Parse body from `php://input` — webhook body has `amount` in centavos, `state`, `payment.id`, etc.
    3. Log incoming notification (full body at DEBUG level)
    4. Validate signature (`isAuthorized`) — `X-Signature: ts=...,v1=...` HMAC-SHA256
    5. Validate JSON structure per type (`isValid`)
    6. Dispatch by type:
       - `point_integration_wh` → `IntentWebhookHandler::handle()`
       - `payment` → `PaymentWebhookHandler::handle()` (legacy, rarely used)
    7. Log to `webhook_logs` table
    8. Return response payload (always 200 — MP retries on non-2xx)
  - `isAuthorized(ServerRequestInterface $request): bool`
    - Extract `X-Request-Id`, `X-Signature` headers
    - Parse `ts=...,v1=...` format
    - HMAC-SHA256: `hash_hmac('sha256', "id:{dataId};request-id:{requestId};ts:{ts};", secret)`
  - `isValid(array $body, string $type): bool`
    - `point_integration_wh`: requires `id` (min:1), `amount` (number), `created_at` (min:1), `state` (text); optional `payment.id` (number)
    - `payment`: requires `id` (min:1), `date_created` (min:1), `type` (text)

- [x] `app/Application/Services/IntentWebhookHandler.php`
  - Handles `point_integration_wh` type webhooks (intent status from Point terminal)
  - `handle(array $body, string $dataId): void`
    1. Find intent in `intents` table by `intent_id = dataId`
    2. If not found:
       - If `state != FINISHED`: insert intent directly with state, return early
       - If `state == FINISHED` and no `device_id` in body: fetch intent from MP API to get `device_id`
       - Insert intent into `intents` table
    3. If `payment.id` exists in body:
       - Fetch full payment details from `GET /v1/payments/{id}`
       - Find or create payment in `payments` table
       - Insert charges into `charges` table
       - Update intent with payment data (payment_id, payment_method, payment_status, etc.)
       - Log: "Order processed: PAY... (2.3 seg)"
    4. If no `payment.id`: update intent with state only (CANCELED, ABANDONED, ERROR)

- [x] `app/Application/Services/PaymentWebhookHandler.php`
  - Handles `payment` type webhooks (rare, legacy)
  - `handle(array $body, string $dataId): void`
    1. Fetch payment details from `GET /v1/payments/{dataId}`
    2. Find or create in `payments` table
    3. Insert charges
    4. Update related intent (if exists) via `metadata.payment_intent_id`

### 3.2 Intent Management

- [x] `app/Application/Services/IntentService.php`
  - `create(string $deviceId, array $body): array`
    1. Validate token (done by middleware)
    2. Validate request body: `amount` (number), `additional_info.external_reference` (min:1), `additional_info.ticket_number` (min:1)
    3. Call MP API `POST /point/integration-api/devices/{deviceId}/payment-intents`
    4. Insert into `intents` table (amount in ARS — body from PV is already in ARS, not centavos)
    5. Return MP response
  - `get(string $intentId): array`
    1. Check intent exists in DB
    2. Fetch from MP API `GET /point/integration-api/payment-intents/{intentId}`
    3. If has `payment.id`: fetch payment details, update DB
    4. Update intent status in DB
    5. Return data
  - `cancel(string $deviceId, string $intentId): array`
    1. Call MP API `DELETE /point/integration-api/devices/{deviceId}/payment-intents/{intentId}`
    2. Update intent status to CANCELED in DB
    3. Return response
  - `refund(string $intentId, ?string $reason): array`
    1. Look up `payment_id` from intent in DB
    2. Call MP API `POST /v1/payments/{paymentId}/refunds`
    3. Fetch updated payment details, update DB
    4. Return response

### 3.3 Payment Management

- [x] `app/Application/Services/PaymentService.php`
  - `get(int $paymentId): array`
  - `refund(int $paymentId): array`

### 3.4 Token Validation

- [ ] `app/Application/Services/TokenValidator.php`
  - `validate(string $token): bool` — compare with `MP_ACCESS_TOKEN`
  - Or better: use middleware (see Phase 4)

---

## Phase 4 — HTTP Layer

### 4.1 Middleware

- [x] `app/Http/Middleware/BearerAuthMiddleware.php`
  - PSR-15 middleware
  - Extract `Authorization: Bearer <token>` header
  - Compare with `MP_ACCESS_TOKEN` env var
  - Skip auth for `POST /webhook` (uses signature validation instead)
  - Return 401 JSON on failure

- [x] `app/Http/Middleware/RequestLoggerMiddleware.php`
  - Log method, path, IP, query params for every request
  - Add timing (request duration)

- [x] `app/Http/Middleware/ErrorHandlerMiddleware.php`
  - Catch exceptions, return JSON error responses
  - Log errors with context
  - Respect `APP_DEBUG` for stack traces

### 4.2 Controllers

- [x] `app/Http/Controllers/WebhookController.php`
  - `POST /webhook` — delegates to `WebhookProcessor::process()`
  - Always returns 200 (MP expects 200 to stop retrying)
  - Logs the entire flow

- [x] `app/Http/Controllers/IntentController.php`
  - `POST /api/intents` — create intent
  - `GET /api/intents/{id}` — get intent status
  - `DELETE /api/intents/{id}/cancel` — cancel intent
  - `POST /api/intents/{id}/refund` — refund intent

- [x] `app/Http/Controllers/PaymentController.php`
  - `GET /api/payments/{id}` — get payment details
  - `POST /api/payments/{id}/refund` — refund payment

- [x] `app/Http/Controllers/HealthController.php`
  - `GET /health` — test DB connection, return status JSON

### 4.3 Route Definitions

- [x] `bootstrap/routes.php`
  ```php
  // Webhook (no auth — uses signature validation)
  $app->post('/webhook', [WebhookController::class, 'handle']);

  // API endpoints (bearer auth)
  $app->group('/api', function (Group $group) {
      $group->post('/intents', [IntentController::class, 'create']);
      $group->get('/intents/{id}', [IntentController::class, 'get']);
      $group->delete('/intents/{id}/cancel', [IntentController::class, 'cancel']);
      $group->post('/intents/{id}/refund', [IntentController::class, 'refund']);
      $group->get('/payments/{id}', [PaymentController::class, 'get']);
      $group->post('/payments/{id}/refund', [PaymentController::class, 'refund']);
  })->add(BearerAuthMiddleware::class);

  // Health (no auth)
  $app->get('/health', [HealthController::class, 'check']);
  ```

### 4.4 Container Definitions

- [x] `bootstrap/container.php`
  - Bind `Medoo\Medoo` → `Database` factory
  - Bind `MercadoPagoClient` with env vars
  - Bind `LoggerInterface` → Monolog instance
  - Autowire controllers and services

---

## Phase 5 — Webhook Flow (The Showcase)

This is the data flow to highlight in the portfolio. Every step is logged.

```
Mercado Pago Point Terminal
       │
       ▼
  POST /webhook?data.id=61ec4adf-...&type=point_integration_wh
  Headers: X-Request-Id: <uuid>, X-Signature: ts=...,v1=...
  Body: {
    "amount": 3980000,          ← centavos!
    "state": "FINISHED",
    "payment": {"id": 165404232933, "state": "approved", "type": "credit_card"},
    "additional_info": {"external_reference": "000910854661-000910029286"},
    "created_at": "2026-06-28 20:39:45",
    ...
  }
       │
       ▼
  ┌─ RequestLoggerMiddleware ─────────────────────────┐
  │  [2026-06-28 17:40:03] INFO  POST /webhook        │
  │  IP: 54.88.218.97  DataID: 61ec4adf-...           │
  │  Type: point_integration_wh                        │
  └───────────────────────────────────────────────────┘
       │
       ▼
  ┌─ WebhookController::handle() ─────────────────────┐
  │                                                    │
  │  1. WebhookProcessor::isAuthorized()               │
  │     - Parse X-Signature: ts=1719...,v1=abc123...   │
  │     - HMAC-SHA256("id:{dataId};request-id:{rid};   │
  │       ts:{ts};", WEBHOOK_SECRET)                   │
  │     - Log: "Signature valid" / "Invalid"           │
  │                                                    │
  │  2. WebhookProcessor::isValid()                    │
  │     - point_integration_wh:                        │
  │       id (min:1), amount (number),                 │
  │       created_at (min:1), state (text),            │
  │       payment.id (optional, number)                │
  │     - Log if invalid                               │
  │                                                    │
  │  3. WebhookLogRepository::log()                    │
  │     - INSERT into webhook_logs (request_id,        │
  │       event_type, data_id, payload,                │
  │       signature_valid, response_code, processed_at)│
  │                                                    │
  │  4. IntentWebhookHandler::handle(body, dataId)     │
  │     a. IntentRepository::findByIntentId(dataId)    │
  │     b. Not found + FINISHED + no device_id:        │
  │        - MercadoPagoClient::getIntent(dataId)      │
  │        - Extract device_id from response           │
  │     c. IntentRepository::create()                  │
  │        - amount: body.amount / 100  ← centavos!    │
  │        - external_ref, intent_status, created_at   │
  │     d. body.payment.id exists:                     │
  │        - MercadoPagoClient::getPayment(165404...)  │
  │        - Response (200 lines): status, card,       │
  │          charges_details[], transaction_details... │
  │        - PaymentRepository::create()               │
  │          - Map: status, payment_type, card_bin,    │
  │            net_received, created_at_mp (tz conv)   │
  │        - PaymentRepository::insertCharges()        │
  │          - Loop charges_details[]: fee + tax       │
  │        - IntentRepository::updateWithPayment()     │
  │          - payment_id, payment_method, status...   │
  │     e. Log: "Intent updated: PAY... (18.3 seg)"    │
  │                                                    │
  │  5. Return 200 JSON                                │
  └───────────────────────────────────────────────────┘
       │
       ▼
  Response: { "message": "Webhook processed", "type": "point_integration_wh" }
```

---

## Phase 6 — Documentation

- [ ] `README.md` (English)
  - Project description, architecture diagram, setup instructions
  - API endpoint reference with example requests/responses
  - Webhook flow explanation
  - Link to `README.es.md`
- [ ] `README.es.md` (Spanish)
  - Same content in Spanish
- [ ] Inline code comments (PHPDoc) on all public methods
- [ ] `api_mp_schema.csv` — original schema for reference

---

## Phase 7 — Quality

- [ ] Run `pint` (or php-cs-fixer) for code style
- [ ] Run `phpstan` level 6+ for static analysis
- [ ] Manual test: simulate webhook with Postman/curl
- [ ] Manual test: create intent, get intent, cancel, refund
- [ ] Verify all webhook events are logged in `webhook_logs` table
- [ ] Verify Monolog logs are written to `logs/` directory
- [ ] Final commit with clean code

---

## Notes for Future (Post-MVP)

### Cron Jobs (maintenance tasks)

Original code has two jobs that run via crontab (no bash wrapper):

1. **CheckIntentions** — Finds intents with `FINISHED` status but missing payment details (status, installments, payment method). Queries MP API to fill gaps. Useful when webhook fails or standalone terminal mode is used.

2. **UpdatePending** — Finds approved payments with `pending` money release status older than 7 days. Queries MP API to check if release status changed. Batch processes with transaction support.

These can be added as Slim console commands (using `symfony/console` or a simple PHP script) in a future phase. The `MercadoPagoClient` service is already reusable for this.

### New Orders API migration notes

The new Orders API (`v1/orders`) replaces the legacy Point Integration API:
- `POST /v1/orders` — body now requires `type: "point"`, `transactions.payments[].amount`, `config.point.terminal_id`
- `GET /v1/orders/{id}` — returns order with nested transactions
- Webhook `type: "order"` with `action` field replaces `type: "point_integration_wh"`
- Order IDs are alphanumeric (`ORD...`) instead of UUIDs

---

## File Map

```
mp-webhook-api/
├── PLAN.md                              ← this file
├── composer.json
├── phinx.php                            ← Phinx config
├── .env.example
├── .gitignore
├── README.md
├── README.es.md
├── api_mp_schema.csv                    ← original DB schema reference
├── examples/                            ← ⚠️ REMOVE or CENSOR before first commit
│   └── API MP/                          ← Real JSON responses for dev reference
│       ├── api_2026-06-28.log           ← Production webhook log (debug level)
│       ├── Devices/
│       ├── Intentions/
│       └── Payments/
├── public/
│   └── index.php                        ← front controller
├── bootstrap/
│   ├── container.php                    ← PHP-DI definitions
│   ├── middleware.php                   ← middleware pipeline
│   └── routes.php                       ← route definitions
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── WebhookController.php
│   │   │   ├── IntentController.php
│   │   │   ├── PaymentController.php
│   │   │   └── HealthController.php
│   │   └── Middleware/
│   │       ├── BearerAuthMiddleware.php
│   │       ├── RequestLoggerMiddleware.php
│   │       └── ErrorHandlerMiddleware.php
│   ├── Application/
│   │   └── Services/
│   │       ├── WebhookProcessor.php         ← CORE: webhook flow
│   │       ├── IntentWebhookHandler.php     ← point_integration_wh handler
│   │       ├── PaymentWebhookHandler.php    ← payment type handler
│   │       ├── IntentService.php            ← intent CRUD
│   │       ├── PaymentService.php           ← payment CRUD
│   │       └── TokenValidator.php           ← bearer token check
│   └── Infrastructure/
│       ├── Database.php                 ← Medoo factory
│       ├── MercadoPago/
│       │   └── MercadoPagoClient.php    ← Guzzle + retry
│       ├── Repositories/
│       │   ├── TerminalRepository.php
│       │   ├── IntentRepository.php
│       │   ├── PaymentRepository.php
│       │   └── WebhookLogRepository.php
│       └── Helpers/
│           └── DateHelper.php           ← timezone conversion
├── database/
│   └── migrations/                      ← Phinx migrations
│       ├── 20250628000001_create_terminals_table.php
│       ├── 20250628000002_create_intents_table.php
│       ├── 20250628000003_create_payments_table.php
│       ├── 20250628000004_create_charges_table.php
│       └── 20250628000005_create_webhook_logs_table.php
└── logs/                                ← Monolog output (gitignored)
```
